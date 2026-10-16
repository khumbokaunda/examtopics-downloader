package fetch

import (
	"encoding/json"
	"fmt"
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
//
// ExamTopics changed the discussion page by 2026 (a page saved in October 2026):
//   - the header is .discussion-header-container (its first div) instead of
//     .question-discussion-header;
//   - the choices are lines inside p.card-text ("<br>A. text") instead of li.multi-choice-item;
//   - there is no suggested answer, only a "Show Suggested Answer" link to the paywalled exam
//     page, and no vote tally;
//   - comments are .comment-container blocks with a username, a dated span, an optional
//     "Selected Answer: X" badge, the text, an upvote count and nested replies.
// Both layouts are parsed; for the new one the community votes are counted from the comments.

var (
	suggestedAnswerRE = regexp.MustCompile(`Suggested Answer:\s*([A-Z]+)`)
	topicRE           = regexp.MustCompile(`Topic #:\s*(\d+)`)
	questionNumRE     = regexp.MustCompile(`Question #:\s*(\d+)`)
	allQuestionsRE    = regexp.MustCompile(`^\[All (.+) Questions\]$`)
	choicePrefixRE    = regexp.MustCompile(`^([A-Z])\.\s*`)
	lettersOnlyRE     = regexp.MustCompile(`^[A-Z]+$`)
	horizontalSpaceRE = regexp.MustCompile(`[ \t\x{00A0}\x{200B}]+`)
	examTitleRE       = regexp.MustCompile(`(?i)^exam\s+(.+?)\s+topic\s+\d+\s+question\s+\d+(?:\s+discussion)?$`)
	choiceLineRE      = regexp.MustCompile(`^([A-Z])\.\s*(.*)$`)
	selectedAnswerRE  = regexp.MustCompile(`Selected Answer:\s*([A-Z]+)`)
	upvotesRE         = regexp.MustCompile(`(\d+)`)
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
	return htmlToText(fragment, false)
}

// imageMarkerRE matches the placeholder htmlToText leaves where an image was, when asked to.
var imageMarkerRE = regexp.MustCompile(`⟦img:(\d+)⟧`)

// htmlToText is HTMLToText; with mark set, each image also leaves a line "⟦img:N⟧" (N is
// its index in the returned list), so callers can tell which text an image belongs to.
func htmlToText(fragment string, mark bool) (string, []string) {
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
			if mark {
				s.ReplaceWithHtml(fmt.Sprintf("<br>⟦img:%d⟧<br>", len(images)-1))
				return
			}
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

// takeImageMarkers removes the image placeholder lines from text and returns the images
// they stood for, in order.
func takeImageMarkers(text string, images []string) (string, []string) {
	var found []string
	for _, m := range imageMarkerRE.FindAllStringSubmatch(text, -1) {
		if i, err := strconv.Atoi(m[1]); err == nil && i < len(images) {
			found = appendUnique(found, images[i])
		}
	}
	return CleanMultiline(imageMarkerRE.ReplaceAllString(text, "")), found
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
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

// questionHeader returns the header text in either page layout.
func questionHeader(doc *goquery.Document) string {
	if h := doc.Find(".question-discussion-header"); h.Length() > 0 {
		return strings.TrimSpace(h.First().Text())
	}
	return strings.TrimSpace(doc.Find(".discussion-header-container").ChildrenFiltered("div").First().Text())
}

// posterTimestamp reads "by <user> at <i>April 18, 2026 06:42 pm</i>" under the question in
// the 2026 layout.
func posterTimestamp(doc *goquery.Document) string {
	ts := ""
	doc.Find(".discussion-header-container").ChildrenFiltered("div").Each(func(_ int, d *goquery.Selection) {
		if ts != "" || !strings.HasPrefix(strings.TrimSpace(d.Text()), "by") {
			return
		}
		d.Find("i").Each(func(_ int, i *goquery.Selection) {
			if t := strings.TrimSpace(i.Text()); t != "" {
				ts = t
			}
		})
	})
	return ts
}

// splitInlineChoices separates "stem / A. one / B. two" text (the 2026 layout puts the choices
// inside the question text) into the stem and the choices. Letters must run A, B, C... from
// the first "A." line, and there must be at least two, or the text is left whole: a stem that
// merely contains "A." stays intact.
func splitInlineChoices(text string) (string, []dataset.Choice) {
	lines := strings.Split(text, "\n")
	start := -1
	for i, l := range lines {
		if m := choiceLineRE.FindStringSubmatch(strings.TrimSpace(l)); m != nil && m[1] == "A" {
			start = i
			break
		}
	}
	if start == -1 {
		return text, nil
	}
	var choices []dataset.Choice
	next := 'A'
	for _, l := range lines[start:] {
		l = strings.TrimSpace(l)
		if m := choiceLineRE.FindStringSubmatch(l); m != nil && rune(m[1][0]) == next {
			choices = append(choices, dataset.Choice{Letter: m[1], Text: m[2]})
			next++
			continue
		}
		if l == "" || len(choices) == 0 {
			continue
		}
		c := &choices[len(choices)-1]
		if c.Text == "" {
			c.Text = l
		} else {
			c.Text += "\n" + l
		}
	}
	if len(choices) < 2 {
		return text, nil
	}
	return strings.TrimSpace(strings.Join(lines[:start], "\n")), choices
}

// parseComments reads the 2026 .comment-container blocks, replies included, in page order.
// It returns nil when the page has none (older layouts keep their comments as text).
func parseComments(doc *goquery.Document) []dataset.Comment {
	var out []dataset.Comment
	doc.Find(".discussion-container .comment-container").Each(func(_ int, c *goquery.Selection) {
		body := c.ChildrenFiltered(".media-body").First()
		head := body.ChildrenFiltered("div").First()
		main := body.ChildrenFiltered(".comment-body").First()

		ts := ""
		if d := head.Find(".comment-date").First(); d.Length() > 0 {
			ts = strings.TrimSpace(d.AttrOr("title", ""))
			if ts == "" {
				ts = strings.TrimSpace(d.Text())
			}
		}
		selected := ""
		main.ChildrenFiltered(".badge").Each(func(_ int, b *goquery.Selection) {
			if m := selectedAnswerRE.FindStringSubmatch(strings.Join(strings.Fields(b.Text()), " ")); m != nil {
				selected = m[1]
			}
		})
		upvotes := 0
		if m := upvotesRE.FindStringSubmatch(main.ChildrenFiltered(".comment-control").Find(".upvote-count").First().Text()); m != nil {
			upvotes, _ = strconv.Atoi(m[1])
		}
		contentNode := main.ChildrenFiltered(".comment-content").First()
		html, _ := contentNode.Html()
		content, imgs := HTMLToText(html)
		for _, img := range imgs {
			content += "\n[image: " + img + "]"
		}
		parent := ""
		if p := c.Parent().Closest(".comment-container"); p.Length() > 0 {
			parent = p.AttrOr("data-comment-id", "")
		}
		out = append(out, dataset.Comment{
			ID:             strings.TrimSpace(c.AttrOr("data-comment-id", "")),
			ParentID:       strings.TrimSpace(parent),
			Author:         strings.TrimSpace(head.Find(".comment-username").First().Text()),
			Timestamp:      ts,
			Upvotes:        upvotes,
			SelectedAnswer: selected,
			Content:        strings.TrimSpace(content),
		})
	})
	return out
}

// votesFromComments counts the community vote the way ExamTopics describes it: a voting
// comment counts once for its selected answer, and every upvote of it counts once more.
func votesFromComments(comments []dataset.Comment) []dataset.Vote {
	counts := map[string]int{}
	for _, c := range comments {
		if c.SelectedAnswer != "" {
			counts[c.SelectedAnswer] += 1 + c.Upvotes
		}
	}
	votes := make([]dataset.Vote, 0, len(counts))
	for a, n := range counts {
		votes = append(votes, dataset.Vote{Answer: a, Count: n})
	}
	sort.Slice(votes, func(i, j int) bool {
		if votes[i].Count != votes[j].Count {
			return votes[i].Count > votes[j].Count
		}
		return votes[i].Answer < votes[j].Answer
	})
	if len(votes) > 0 {
		votes[0].MostVoted = true
	}
	return votes
}

// legacyComments renders structured comments for the legacy record, one per paragraph.
func legacyComments(comments []dataset.Comment) string {
	var b strings.Builder
	for i, c := range comments {
		if i > 0 {
			b.WriteString("\n\n")
		}
		meta := []string{}
		if c.Timestamp != "" {
			meta = append(meta, c.Timestamp)
		}
		if c.SelectedAnswer != "" {
			meta = append(meta, "selected "+c.SelectedAnswer)
		}
		if c.Upvotes > 0 {
			meta = append(meta, fmt.Sprintf("%d upvotes", c.Upvotes))
		}
		who := c.Author
		if c.ParentID != "" {
			who = "↳ " + who
		}
		b.WriteString(who)
		if len(meta) > 0 {
			b.WriteString(" (" + strings.Join(meta, ", ") + ")")
		}
		b.WriteString(": " + c.Content)
	}
	return b.String()
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
	comments := parseComments(doc)

	headerText := questionHeader(doc)
	topic, qnum, examName := parseHeader(headerText)
	// Only the question's own blocks: the page has other .card-text elements, such as the
	// sign-in box ("Forgot my password"), which used to end up in the question text.
	card := doc.Find(".question-body .card-text")
	if card.Length() == 0 {
		card = doc.Find(".card-text").FilterFunction(func(_ int, s *goquery.Selection) bool {
			return s.Closest(".modal").Length() == 0
		})
	}
	cardText := card.Text()
	suggested := ""
	if m := suggestedAnswerRE.FindStringSubmatch(cardText); m != nil {
		suggested = m[1]
	}
	if i := strings.Index(cardText, "Suggested Answer:"); i >= 0 {
		cardText = cardText[:i]
	}
	text := CleanMultiline(cardText)
	var choiceImages []string
	if len(choices) == 0 {
		// 2026 layout: the choices are lines of the question text, after <br> breaks. Images
		// between one letter and the next belong to that choice (options that are pictures).
		html, _ := card.First().Html()
		full, marked := htmlToText(html, true)
		if stem, inline := splitInlineChoices(full); len(inline) > 0 {
			for i := range inline {
				inline[i].Text, inline[i].Images = takeImageMarkers(inline[i].Text, marked)
				choiceImages = append(choiceImages, inline[i].Images...)
			}
			stem, _ = takeImageMarkers(stem, marked)
			text, choices = stem, inline
			for _, c := range inline {
				choiceLines = append(choiceLines, c.Letter+". "+strings.ReplaceAll(c.Text, "\n", " "))
			}
		}
	}

	var dsVotes []dataset.Vote
	for _, v := range votes {
		dsVotes = append(dsVotes, dataset.Vote{Answer: v.VotedAnswers, Count: v.VoteCount, MostVoted: v.IsMostVoted})
	}
	fromComments := false
	if len(dsVotes) == 0 && len(comments) > 0 {
		dsVotes = votesFromComments(comments)
		fromComments = len(dsVotes) > 0
	}

	// Legacy answer: most-voted community answer, else the legacy .correct-answer
	// selector. The fallback previously kept only the first character, truncating
	// multi-answer questions ("BE" became "B").
	legacyAnswer := ""
	for _, v := range dsVotes {
		if v.MostVoted {
			legacyAnswer = v.Answer
			break
		}
	}
	if legacyAnswer == "" && len(dsVotes) > 0 {
		legacyAnswer = dsVotes[0].Answer
	}
	correctText := strings.Join(strings.Fields(doc.Find(".correct-answer").Text()), "")
	if legacyAnswer == "" && correctText != "" {
		legacyAnswer = correctText
	}
	if fromComments && legacyAnswer != "" {
		legacyAnswer += " (most voted in the comments; ExamTopics shows no suggested answer on this page)"
	}

	timestamp := utils.CleanText(doc.Find(".discussion-meta-data > i").Text())
	if timestamp == "" {
		timestamp = posterTimestamp(doc)
	}
	legacyContent := utils.CleanText(doc.Find(".card-text").Text())
	commentsText := CleanMultiline(doc.Find(".discussion-container").Text())
	legacyCommentText := utils.CleanText(doc.Find(".discussion-container").Text())
	if len(comments) > 0 {
		legacyContent = utils.CleanText(text)
		commentsText = ""
		legacyCommentText = legacyComments(comments)
	}
	legacy := models.QuestionData{
		Title:        utils.CleanText(doc.Find("h1").Text()),
		Header:       strings.ReplaceAll(headerText, "\t", ""),
		Content:      legacyContent,
		Questions:    choiceLines,
		Answer:       legacyAnswer,
		Timestamp:    timestamp,
		QuestionLink: link,
		Comments:     legacyCommentText,
	}

	if suggested == "" && lettersOnlyRE.MatchString(correctText) {
		suggested = correctText
	}
	var images []string
	card.Find("img").Each(func(_ int, s *goquery.Selection) {
		if src, ok := s.Attr("src"); ok && strings.TrimSpace(src) != "" && !contains(choiceImages, absoluteURL(src)) {
			images = appendUnique(images, absoluteURL(src))
		}
	})

	q := dataset.Question{
		Topic:             topic,
		QuestionNumber:    qnum,
		Text:              text,
		Choices:           choices,
		SuggestedAnswer:   suggested,
		QuestionImages:    images,
		Comments:          comments,
		CommentsText:      commentsText,
		CommunityVotes:    dsVotes,
		VotesFromComments: fromComments,
		SourceURL:         link,
		Timestamp:         timestamp,
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

// examPageLinkRE matches a provider's exam pages, such as "/exams/huawei/h12-811/".
var examPageLinkRE = regexp.MustCompile(`^(?:https?://(?:www\.)?examtopics\.com)?/exams/([a-z0-9-]+)/([a-z0-9._-]+)/?$`)

// ExamTitles reads a provider's exams page and returns each exam's full title by the slug in
// its address: "H12-811: HCIA-Datacom V1.0" at /exams/huawei/h12-811/ gives
// "h12-811" => "HCIA-Datacom V1.0". Links whose text is only the code give no title.
func ExamTitles(doc *goquery.Document, provider string) map[string]string {
	out := map[string]string{}
	doc.Find("a[href]").Each(func(_ int, s *goquery.Selection) {
		href, _ := s.Attr("href")
		m := examPageLinkRE.FindStringSubmatch(strings.TrimSpace(href))
		if m == nil || m[1] != provider || m[2] == "view" {
			return
		}
		if _, seen := out[m[2]]; seen {
			return
		}
		if t := examTitle(utils.CleanText(s.Text()), m[2]); t != "" {
			out[m[2]] = t
		}
	})
	return out
}

// examTitle takes the title from a link text: what follows "CODE:", or the whole text when it
// is not just the code.
func examTitle(text, slug string) string {
	text = strings.TrimSpace(text)
	if i := strings.Index(text, ":"); i > 0 && codeKey(text[:i]) == codeKey(slug) {
		return strings.TrimSpace(text[i+1:])
	}
	if text == "" || codeKey(text) == codeKey(slug) {
		return ""
	}
	return text
}

// titleFor finds an exam's title by its code, tolerating different punctuation between the
// discussion code and the exams page address ("h19-101-v6-0" and "h19-101_v60").
func titleFor(titles map[string]string, code string) string {
	if t, ok := titles[code]; ok {
		return t
	}
	for slug, t := range titles {
		if codeKey(slug) == codeKey(code) {
			return t
		}
	}
	return ""
}

// codeKey reduces an exam code to lower-case letters and digits, for comparing spellings.
func codeKey(s string) string {
	var b strings.Builder
	for _, r := range strings.ToLower(s) {
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') {
			b.WriteRune(r)
		}
	}
	return b.String()
}
