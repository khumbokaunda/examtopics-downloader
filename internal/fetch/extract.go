package fetch

import (
	"encoding/json"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"examtopics-downloader/internal/dataset"
	"examtopics-downloader/internal/models"
	"examtopics-downloader/internal/utils"

	"github.com/PuerkitoBio/goquery"
)

// Parsing rules below are based on evidence recorded in docs/AUDIT.md: the
// question-discussion header lines, "Suggested Answer: BE 🗳️" inside .card-text, and
// "A. text" choices, all observed in examples/.

var (
	suggestedAnswerRE = regexp.MustCompile(`Suggested Answer:\s*([A-Z]+)`)
	topicRE           = regexp.MustCompile(`Topic #:\s*(\d+)`)
	questionNumRE     = regexp.MustCompile(`Question #:\s*(\d+)`)
	allQuestionsRE    = regexp.MustCompile(`^\[All (.+) Questions\]$`)
	choicePrefixRE    = regexp.MustCompile(`^([A-Z])\.\s*`)
	lettersOnlyRE     = regexp.MustCompile(`^[A-Z]+$`)
	horizontalSpaceRE = regexp.MustCompile(`[ \t\x{00A0}\x{200B}]+`)
	examTitleRE       = regexp.MustCompile(`(?i)^exam\s+(.+?)\s+topic\s+\d+\s+question\s+\d+(?:\s+discussion)?$`)
)

// CleanMultiline normalises whitespace while keeping line structure: runs of spaces collapse,
// lines are trimmed, and consecutive blank lines collapse to one. utils.CleanText, by
// contrast, turns every newline into a space, which is why comments came out as one
// run-on paragraph.
func CleanMultiline(s string) string {
	s = strings.ReplaceAll(s, "\r\n", "\n")
	lines := strings.Split(s, "\n")
	out := make([]string, 0, len(lines))
	blank := false
	for _, line := range lines {
		line = strings.TrimSpace(horizontalSpaceRE.ReplaceAllString(line, " "))
		if line == "" {
			if !blank && len(out) > 0 {
				out = append(out, "")
			}
			blank = true
			continue
		}
		blank = false
		out = append(out, line)
	}
	return strings.TrimSpace(strings.Join(out, "\n"))
}

// HTMLToText converts an untrusted HTML fragment (as found in the cached dataset's
// question_text) to plain text with line breaks, returning any <img> sources separately.
// Fragments without markup are returned unchanged apart from whitespace normalisation.
func HTMLToText(fragment string) (string, []string) {
	if !strings.ContainsAny(fragment, "<&") {
		return CleanMultiline(fragment), nil
	}
	doc, err := goquery.NewDocumentFromReader(strings.NewReader("<div id=\"etx-root\">" + fragment + "</div>"))
	if err != nil {
		return CleanMultiline(fragment), nil
	}
	root := doc.Find("#etx-root")
	root.Find("script, style, noscript, template").Remove()
	var images []string
	root.Find("img").Each(func(_ int, s *goquery.Selection) {
		if src, ok := s.Attr("src"); ok && strings.TrimSpace(src) != "" {
			images = append(images, absoluteURL(src))
		}
		s.Remove()
	})
	root.Find("br").Each(func(_ int, s *goquery.Selection) { s.ReplaceWithHtml("\n") })
	// Block elements start and end a line; CleanMultiline collapses the extra blank lines.
	root.Find("p, div, li, tr, h1, h2, h3, h4, h5, h6, pre, ul, ol, table").Each(func(_ int, s *goquery.Selection) {
		s.PrependHtml("\n")
		s.AppendHtml("\n")
	})
	return CleanMultiline(root.Text()), images
}

func absoluteURL(src string) string {
	src = strings.TrimSpace(src)
	if strings.HasPrefix(src, "//") {
		return "https:" + src
	}
	return utils.AddToBaseUrl(src)
}

func appendUnique(dst []string, items ...string) []string {
	seen := make(map[string]struct{}, len(dst))
	for _, d := range dst {
		seen[d] = struct{}{}
	}
	for _, it := range items {
		if it == "" {
			continue
		}
		if _, ok := seen[it]; !ok {
			seen[it] = struct{}{}
			dst = append(dst, it)
		}
	}
	return dst
}

// parseHeader reads topic, question number and exam display name from the
// .question-discussion-header lines, e.g.
//
//	Actual exam question from / Google's / Professional Cloud DevOps Engineer /
//	Question #: 1 / Topic #: 1 / [All Professional Cloud DevOps Engineer Questions]
func parseHeader(header string) (topic, question int, examName string) {
	if m := topicRE.FindStringSubmatch(header); m != nil {
		topic, _ = strconv.Atoi(m[1])
	}
	if m := questionNumRE.FindStringSubmatch(header); m != nil {
		question, _ = strconv.Atoi(m[1])
	}
	var lines []string
	for _, l := range strings.Split(header, "\n") {
		if l = strings.TrimSpace(l); l != "" {
			lines = append(lines, l)
		}
	}
	for i, l := range lines {
		if m := allQuestionsRE.FindStringSubmatch(l); m != nil {
			return topic, question, m[1]
		}
		if strings.HasSuffix(l, "'s") && i+1 < len(lines) && !strings.HasPrefix(lines[i+1], "Question #") {
			examName = lines[i+1]
		}
	}
	return topic, question, examName
}

// ExamNameFromTitle extracts "H12-811" from a discussion title such as
// "Exam H12-811 topic 1 question 5 discussion". It returns "" if the title does not have
// that shape.
func ExamNameFromTitle(title string) string {
	if m := examTitleRE.FindStringSubmatch(strings.TrimSpace(title)); m != nil {
		return m[1]
	}
	return ""
}

type tallyVote struct {
	VotedAnswers string `json:"voted_answers"`
	VoteCount    int    `json:"vote_count"`
	IsMostVoted  bool   `json:"is_most_voted"`
}

func parseVoteTally(doc *goquery.Document) []tallyVote {
	jsonText := strings.TrimSpace(doc.Find(".voted-answers-tally script").First().Text())
	if jsonText == "" {
		return nil
	}
	var votes []tallyVote
	if err := json.Unmarshal([]byte(jsonText), &votes); err != nil {
		return nil
	}
	return votes
}

// parseQuestionPage extracts both the legacy record (unchanged CLI output) and the
// normalised dataset record from one discussion page, plus the exam display name.
func parseQuestionPage(doc *goquery.Document, link string) (models.QuestionData, dataset.Question, string) {
	var choiceLines []string
	var choices []dataset.Choice
	doc.Find("li.multi-choice-item").Each(func(_ int, s *goquery.Selection) {
		raw := utils.CleanText(s.Text())
		choiceLines = append(choiceLines, raw)

		text := strings.TrimSpace(strings.TrimSuffix(raw, "Most Voted"))
		letter, _ := s.Attr("data-choice-letter")
		if m := choicePrefixRE.FindStringSubmatch(text); m != nil {
			if letter == "" {
				letter = m[1]
			}
			text = text[len(m[0]):]
		}
		choices = append(choices, dataset.Choice{Letter: strings.ToUpper(strings.TrimSpace(letter)), Text: text})
	})

	votes := parseVoteTally(doc)

	// Legacy answer: most-voted community answer, else the legacy .correct-answer
	// selector. The fallback previously kept only the first character, truncating
	// multi-answer questions ("BE" became "B").
	legacyAnswer := ""
	for _, v := range votes {
		if v.IsMostVoted {
			legacyAnswer = v.VotedAnswers
			break
		}
	}
	if legacyAnswer == "" && len(votes) > 0 {
		legacyAnswer = votes[0].VotedAnswers
	}
	correctText := strings.Join(strings.Fields(doc.Find(".correct-answer").Text()), "")
	if legacyAnswer == "" && correctText != "" {
		legacyAnswer = correctText
	}

	headerText := strings.TrimSpace(doc.Find(".question-discussion-header").Text())
	legacy := models.QuestionData{
		Title:        utils.CleanText(doc.Find("h1").Text()),
		Header:       strings.ReplaceAll(headerText, "\t", ""),
		Content:      utils.CleanText(doc.Find(".card-text").Text()),
		Questions:    choiceLines,
		Answer:       legacyAnswer,
		Timestamp:    utils.CleanText(doc.Find(".discussion-meta-data > i").Text()),
		QuestionLink: link,
		Comments:     utils.CleanText(doc.Find(".discussion-container").Text()),
	}

	// Normalised record.
	topic, qnum, examName := parseHeader(headerText)
	card := doc.Find(".card-text")
	cardText := card.Text()
	suggested := ""
	if m := suggestedAnswerRE.FindStringSubmatch(cardText); m != nil {
		suggested = m[1]
	}
	if i := strings.Index(cardText, "Suggested Answer:"); i >= 0 {
		cardText = cardText[:i]
	}
	if suggested == "" && lettersOnlyRE.MatchString(correctText) {
		suggested = correctText
	}
	var images []string
	card.Find("img").Each(func(_ int, s *goquery.Selection) {
		if src, ok := s.Attr("src"); ok && strings.TrimSpace(src) != "" {
			images = appendUnique(images, absoluteURL(src))
		}
	})

	q := dataset.Question{
		Topic:           topic,
		QuestionNumber:  qnum,
		Text:            CleanMultiline(cardText),
		Choices:         choices,
		SuggestedAnswer: suggested,
		QuestionImages:  images,
		// Comment markup is unverified (docs/AUDIT.md, uncertainty 1): keep the text with its
		// line structure instead of guessing per-comment selectors.
		CommentsText: CleanMultiline(doc.Find(".discussion-container").Text()),
		SourceURL:    link,
		Timestamp:    legacy.Timestamp,
	}
	for _, v := range votes {
		q.CommunityVotes = append(q.CommunityVotes, dataset.Vote{Answer: v.VotedAnswers, Count: v.VoteCount, MostVoted: v.IsMostVoted})
	}
	if ref, ok := parseDiscussionPath(link); ok {
		q.ID = ref.ID
		if q.Topic == 0 {
			q.Topic = ref.Topic
		}
		if q.QuestionNumber == 0 {
			q.QuestionNumber = ref.Question
		}
	}
	if q.ID == "" {
		q.ID = link
	}
	return legacy, q, examName
}

// cachedQuestion is the shape of one question in the third-party cached dataset.
type cachedQuestion = struct {
	Choices           map[string]string `json:"choices"`
	ID                string            `json:"id"`
	ExamID            int               `json:"exam_id"`
	QuestionText      string            `json:"question_text"`
	Answer            string            `json:"answer"`
	AnswerET          string            `json:"answer_ET"`
	Topic             string            `json:"topic"`
	IsMC              bool              `json:"isMC"`
	AnswerDescription string            `json:"answer_description"`
	Discussion        []struct {
		Content     string `json:"content"`
		UpvoteCount string `json:"upvote_count"`
		Poster      string `json:"poster"`
		Timestamp   string `json:"timestamp"`
	} `json:"discussion"`
	AnswerImages   []string `json:"answer_images"`
	QuestionImages []string `json:"question_images"`
	URL            string   `json:"url"`
	Timestamp      string   `json:"timestamp"`
}

// normalizeCached maps a cached-dataset question to the canonical record. Everything the
// source provides is kept, including the structured comments the legacy path flattened.
func normalizeCached(src cachedQuestion) dataset.Question {
	text, textImages := HTMLToText(src.QuestionText)

	keys := make([]string, 0, len(src.Choices))
	for k := range src.Choices {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var choices []dataset.Choice
	var choiceImages []string
	for _, k := range keys {
		t, imgs := HTMLToText(src.Choices[k])
		choices = append(choices, dataset.Choice{Letter: strings.ToUpper(k), Text: t})
		choiceImages = append(choiceImages, imgs...)
	}

	explanation, explanationImages := HTMLToText(src.AnswerDescription)
	answer, answerImages := HTMLToText(src.Answer)

	q := dataset.Question{
		ID:              src.ID,
		Text:            text,
		Choices:         choices,
		SuggestedAnswer: answer,
		AnswerET:        strings.TrimSpace(src.AnswerET),
		Explanation:     explanation,
		SourceURL:       absoluteURL(src.URL),
		Timestamp:       src.Timestamp,
	}
	q.Topic, _ = strconv.Atoi(strings.TrimSpace(src.Topic))
	for _, img := range src.QuestionImages {
		q.QuestionImages = appendUnique(q.QuestionImages, absoluteURL(img))
	}
	q.QuestionImages = appendUnique(q.QuestionImages, textImages...)
	q.QuestionImages = appendUnique(q.QuestionImages, choiceImages...)
	for _, img := range src.AnswerImages {
		q.AnswerImages = appendUnique(q.AnswerImages, absoluteURL(img))
	}
	q.AnswerImages = appendUnique(q.AnswerImages, answerImages...)
	q.AnswerImages = appendUnique(q.AnswerImages, explanationImages...)

	for _, d := range src.Discussion {
		content, imgs := HTMLToText(d.Content)
		for _, img := range imgs {
			content += "\n[image: " + img + "]"
		}
		upvotes, _ := strconv.Atoi(strings.TrimSpace(d.UpvoteCount))
		q.Comments = append(q.Comments, dataset.Comment{
			Author:    strings.TrimSpace(d.Poster),
			Timestamp: strings.TrimSpace(d.Timestamp),
			Upvotes:   upvotes,
			Content:   strings.TrimSpace(content),
		})
	}
	if ref, ok := parseDiscussionPath(src.URL); ok {
		q.QuestionNumber = ref.Question
		if q.Topic == 0 {
			q.Topic = ref.Topic
		}
		if q.ID == "" {
			q.ID = ref.ID
		}
	}
	return q
}
