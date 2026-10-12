// Package fixturesite serves a small, deterministic imitation of ExamTopics (and of the
// GitHub API used by the cached-data path) for tests. Page structure follows the evidence
// in docs/AUDIT.md: the header lines, "Suggested Answer: X 🗳️" inside .card-text,
// "A. text" choices, the vote-tally script, and both discussion-link formats.
//
// It is a test double: passing tests prove the pipeline behaves correctly against this
// structure, not that the real site still has it.
package fixturesite

import (
	"encoding/json"
	"fmt"
	"hash/fnv"
	"html"
	"image"
	"image/color"
	"image/png"
	"net/http"
	"strconv"
	"strings"
	"sync"
)

// Question is one fixture discussion.
type Question struct {
	ID        int
	Exam      string // exam code in the link
	ExamName  string
	Topic     int
	Number    int
	Text      string
	Choices   []string // "A. ..." form
	Suggested string
	Votes     []Vote
	Comments  []string // each rendered as its own block with line breaks
	Image     string   // optional image path inside .card-text
	Suffix    bool     // link ends with "-discussion"
	Absolute  bool     // href is absolute
	// Modern renders the 2026 page layout: inline choices, no suggested answer or vote
	// tally, and structured comments whose selected answers and upvotes add up to Votes.
	Modern bool
}

type Vote struct {
	Answers   string
	Count     int
	MostVoted bool
}

// Options control failure injection.
type Options struct {
	// Paths that always fail with the given status (e.g. 500, or 429 with Retry-After: 1).
	FailPaths map[string]int
	// Paths that return 429 (Retry-After: 1) the first time and succeed afterwards.
	FlakyPaths map[string]bool
	// Paths that return 503 (Retry-After: 1) for their first N requests, then succeed.
	FailTimes map[string]int
	// PerPage is the number of discussion links per listing page.
	PerPage int
}

type Site struct {
	opts      Options
	questions []Question
	mu        sync.Mutex
	hits      map[string]int
}

// Default returns the standard fixture: three Huawei exams whose codes share a prefix, so
// substring and exact selection give different results.
func Default(opts Options) *Site {
	if opts.PerPage == 0 {
		opts.PerPage = 3
	}
	s := &Site{opts: opts, hits: map[string]int{}}
	id := 1000
	add := func(exam, name string, topic, n int, suffix, abs bool) {
		id++
		q := Question{
			ID: id, Exam: exam, ExamName: name, Topic: topic, Number: n,
			Text:    fmt.Sprintf("%s question %d: which protocol is described?\nSecond line of the question.", strings.ToUpper(exam), n),
			Choices: []string{"A. OSPF", "B. BGP", "C. RIP", "D. IS-IS", "E. EIGRP"},
			Suffix:  suffix, Absolute: abs,
		}
		switch n % 3 {
		case 0:
			q.Suggested = "BE" // multi-answer: the legacy fallback truncated this
			q.Votes = []Vote{{"BE", 12, true}, {"B", 3, false}}
		case 1:
			q.Suggested = "A"
			q.Votes = []Vote{{"A", 7, true}}
		default:
			q.Suggested = ""
			q.Votes = []Vote{{"C", 9, true}, {"D", 2, false}}
		}
		q.Comments = []string{
			fmt.Sprintf("alice\n%d upvotes\nI think it is %s.\nBecause of the RFC.", n, q.Suggested),
			"bob\n1 upvote\nAgreed.",
		}
		if n == 2 {
			q.Image = "/assets/media/exam-media/fixture-" + exam + ".png"
		}
		s.questions = append(s.questions, q)
	}
	for n := 1; n <= 5; n++ {
		add("h12-811", "H12-811", 1, n, n%2 == 0, n == 3)
	}
	for n := 1; n <= 2; n++ {
		add("h12-811-v2-0", "H12-811-V2.0", 1, n, true, false)
	}
	for n := 1; n <= 3; n++ {
		add("h19-301", "H19-301", 2, n, false, false)
		s.questions[len(s.questions)-1].Modern = true
	}
	return s
}

func (s *Site) Questions() []Question { return s.questions }

// Hits returns how many times a path was requested.
func (s *Site) Hits(path string) int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.hits[path]
}

func (q Question) Path() string {
	p := fmt.Sprintf("/discussions/huawei/view/%d-exam-%s-topic-%d-question-%d", q.ID, q.Exam, q.Topic, q.Number)
	if q.Suffix {
		p += "-discussion"
	}
	return p + "/"
}

func (s *Site) pages() int {
	return (len(s.questions) + s.opts.PerPage - 1) / s.opts.PerPage
}

func (s *Site) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	s.mu.Lock()
	s.hits[path]++
	hit := s.hits[path]
	s.mu.Unlock()

	if code, ok := s.opts.FailPaths[path]; ok {
		if code == http.StatusTooManyRequests {
			w.Header().Set("Retry-After", "1")
		}
		http.Error(w, "injected failure", code)
		return
	}
	if n, ok := s.opts.FailTimes[path]; ok && hit <= n {
		w.Header().Set("Retry-After", "1")
		http.Error(w, "overloaded", http.StatusServiceUnavailable)
		return
	}
	if s.opts.FlakyPaths[path] && hit == 1 {
		w.Header().Set("Retry-After", "1")
		http.Error(w, "slow down", http.StatusTooManyRequests)
		return
	}

	switch {
	case path == "/exams/":
		s.examsIndex(w)
	case path == "/discussions/huawei/":
		s.listing(w, 1)
	case strings.HasPrefix(path, "/discussions/huawei/view/"):
		s.question(w, r, path)
	case strings.HasPrefix(path, "/discussions/huawei/"):
		rest := strings.Trim(strings.TrimPrefix(path, "/discussions/huawei/"), "/")
		n, err := strconv.Atoi(rest)
		// Like the real site: pagination without the trailing slash is a 404.
		if err != nil || !strings.HasSuffix(path, "/") || n < 1 || n > s.pages() {
			http.NotFound(w, r)
			return
		}
		s.listing(w, n)
	case strings.HasPrefix(path, "/assets/media/") || strings.HasPrefix(path, "/img/"):
		s.image(w, r, path)
	case strings.HasPrefix(path, "/gh/"):
		s.github(w, r, strings.TrimPrefix(path, "/gh"))
	case strings.HasPrefix(path, "/raw/"):
		s.raw(w, r, strings.TrimPrefix(path, "/raw/"))
	default:
		http.NotFound(w, r)
	}
}

// image serves a small PNG whose colour depends on the path, so different paths have
// different content. Paths containing "missing" are 404s; ".html" paths are not images.
func (s *Site) image(w http.ResponseWriter, r *http.Request, path string) {
	switch {
	case strings.Contains(path, "missing"):
		http.NotFound(w, r)
		return
	case strings.HasSuffix(path, ".html"):
		page(w, "not an image", "<p>This is a page.</p>")
		return
	}
	h := fnv.New32a()
	h.Write([]byte(path))
	sum := h.Sum32()
	img := image.NewRGBA(image.Rect(0, 0, 24, 12))
	fill := color.RGBA{R: uint8(sum), G: uint8(sum >> 8), B: uint8(sum >> 16), A: 255}
	for y := 0; y < 12; y++ {
		for x := 0; x < 24; x++ {
			img.Set(x, y, fill)
		}
	}
	w.Header().Set("Content-Type", "image/png")
	png.Encode(w, img)
}

func page(w http.ResponseWriter, title, body string) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	// Like the real site, every page carries a sign-in box with its own .card-text.
	fmt.Fprintf(w, "<!doctype html>\n<html><head><title>%s</title></head><body>\n%s\n%s</body></html>\n",
		html.EscapeString(title), body,
		"<div class=\"modal\" id=\"login-modal\"><div class=\"card-text\"><a href=\"/reset\">Forgot my password</a></div></div>\n")
}

func (s *Site) examsIndex(w http.ResponseWriter) {
	page(w, "Exams", `<ul>
  <li><a href="/exams/huawei/">Huawei (3 exams)</a></li>
  <li><a href="https://www.examtopics.com/exams/cisco/">Cisco</a></li>
  <li><a href="/exams/huawei/h12-811/">H12-811</a></li>
  <li><a href="/exams/">All</a></li>
  <li><a href="https://elsewhere.example/exams/evil/">Not ExamTopics</a></li>
</ul>`)
}

func (s *Site) listing(w http.ResponseWriter, n int) {
	var b strings.Builder
	fmt.Fprintf(&b, "<div class=\"discussion-list-page-indicator\">Page <strong>%d</strong> of <strong>%d</strong></div>\n", n, s.pages())
	start := (n - 1) * s.opts.PerPage
	for i := start; i < start+s.opts.PerPage && i < len(s.questions); i++ {
		q := s.questions[i]
		href := q.Path()
		if q.Absolute {
			href = "https://www.examtopics.com" + href
		}
		fmt.Fprintf(&b, "<div class=\"discussion-row\"><a href=\"%s\">Exam %s topic %d question %d discussion</a></div>\n",
			html.EscapeString(href), html.EscapeString(q.ExamName), q.Topic, q.Number)
	}
	// Pagination and unrelated links that a parser must ignore.
	fmt.Fprintf(&b, "<a href=\"/discussions/huawei/%d/\">Next</a> <a href=\"/discussions/cisco/view/1-exam-200-301-topic-1-question-1/\">other provider</a>\n", n+1)
	page(w, "Discussions", b.String())
}

func (s *Site) question(w http.ResponseWriter, r *http.Request, path string) {
	for _, q := range s.questions {
		if q.Path() == path || strings.TrimSuffix(q.Path(), "/") == path {
			if q.Modern {
				page(w, "Question", modernQuestion(q))
				return
			}
			var b strings.Builder
			fmt.Fprintf(&b, "<h1>Exam %s topic %d question %d discussion</h1>\n", html.EscapeString(q.ExamName), q.Topic, q.Number)
			fmt.Fprintf(&b, "<div class=\"question-discussion-header\">\n\tActual exam question from\n\n\tHuawei's\n\t%s\n\n\tQuestion #: %d\n\tTopic #: %d\n\n\t[All %s Questions]\n</div>\n",
				html.EscapeString(q.ExamName), q.Number, q.Topic, html.EscapeString(q.ExamName))
			b.WriteString("<div class=\"card-text\">\n" + strings.ReplaceAll(html.EscapeString(q.Text), "\n", "<br>\n"))
			if q.Image != "" {
				fmt.Fprintf(&b, "<br><img src=\"%s\">", html.EscapeString(q.Image))
			}
			b.WriteString("\n</div>\n<ul>\n")
			for _, c := range q.Choices {
				fmt.Fprintf(&b, "<li class=\"multi-choice-item\">%s</li>\n", html.EscapeString(c))
			}
			b.WriteString("</ul>\n")
			if q.Suggested != "" {
				fmt.Fprintf(&b, "<div class=\"card-text\">Suggested Answer: %s 🗳️</div>\n", q.Suggested)
			}
			votes := make([]map[string]any, 0, len(q.Votes))
			for _, v := range q.Votes {
				votes = append(votes, map[string]any{"voted_answers": v.Answers, "vote_count": v.Count, "is_most_voted": v.MostVoted})
			}
			tally, _ := json.Marshal(votes)
			fmt.Fprintf(&b, "<div class=\"voted-answers-tally\"><script type=\"application/json\">%s</script></div>\n", tally)
			b.WriteString("<div class=\"discussion-meta-data\"><i>Oct. 1, 2026, 10:00 a.m.</i></div>\n<div class=\"discussion-container\">\n")
			for _, c := range q.Comments {
				b.WriteString("<div class=\"comment\">\n" + strings.ReplaceAll(html.EscapeString(c), "\n", "\n<br>\n") + "\n</div>\n")
			}
			b.WriteString("</div>\n")
			page(w, "Question", b.String())
			return
		}
	}
	http.NotFound(w, r)
}

// modernQuestion renders the 2026 discussion layout (see internal/fetch/extract.go).
func modernQuestion(q Question) string {
	var b strings.Builder
	esc := html.EscapeString
	fmt.Fprintf(&b, "<h1><i class=\"fa fa-file-text-o\"></i>Exam %s topic %d question %d discussion</h1>\n", esc(q.ExamName), q.Topic, q.Number)
	b.WriteString("<div class=\"discussion-header-container\">\n<div>\n Actual exam question from Huawei's\n")
	fmt.Fprintf(&b, " <a class=\"discussion-link\" href=\"/exams/huawei/%s/view/\">%s</a>\n <div>\n Question #: %d<br>\n Topic #: %d\n </div>\n", esc(q.Exam), esc(q.ExamName), q.Number, q.Topic)
	fmt.Fprintf(&b, " <a class=\"all-questions-link\" href=\"/exams/huawei/%s/view/\">[All %s Questions]</a>\n</div>\n", esc(q.Exam), esc(q.ExamName))
	b.WriteString("<div class=\"question-body\">\n<p class=\"card-text\">\n" + strings.ReplaceAll(esc(q.Text), "\n", "<br>\n") + "<br>\n")
	if q.Image != "" {
		fmt.Fprintf(&b, "<img src=\"%s\"><br>\n", esc(q.Image))
	}
	for _, c := range q.Choices {
		letter, text, _ := strings.Cut(c, ". ")
		fmt.Fprintf(&b, "  <br>%s.\n      %s<br>\n", esc(letter), esc(text))
	}
	fmt.Fprintf(&b, "</p>\n<a class=\"btn btn-primary\" href=\"/exams/huawei/%s/view/\">Show Suggested Answer</a>\n</div>\n", esc(q.Exam))
	b.WriteString("<div class=\"mt-3 pt-1 border-top\">\n by <i class=\"fa fa-user-o\"></i> poster at <i> October 01, 2026 10:00 am</i>\n</div>\n</div>\n")
	b.WriteString("<div class=\"discussion-container\">\n")
	comment := func(id, user, answer, text string, upvotes int, replies string) string {
		badge := ""
		if answer != "" {
			badge = fmt.Sprintf("<div class=\"badge badge-warning\">Selected Answer: <span><strong>%s</strong></span></div>", esc(answer))
		}
		return fmt.Sprintf(`<div class="media comment-container" data-comment-id="%s"><div class="media-body">
<div><h5 class="comment-username">%s</h5> <span class="comment-date" title="October 0%s, 2026 09:00 am">some days ago</span></div>
<div class="comment-body">%s<div class="comment-content">%s</div>
<div class="comment-control"><span class="upvote-text">upvoted <span class="upvote-count">%d times</span></span></div>
<div class="comment-replies">%s</div></div></div></div>
`, esc(id), esc(user), esc(id[len(id)-1:]), badge, esc(text), upvotes, replies)
	}
	for i, v := range q.Votes {
		replies := ""
		if i == 0 {
			replies = comment(fmt.Sprintf("%d9", q.ID), "bob", "", "Agreed.", 0, "")
		}
		b.WriteString(comment(fmt.Sprintf("%d%d", q.ID, i+1), fmt.Sprintf("voter%d", i+1), v.Answers,
			fmt.Sprintf("I think it is %s.\nBecause of the RFC.", v.Answers), v.Count-1, replies))
	}
	b.WriteString("</div>\n")
	return b.String()
}

// --- GitHub cached-data imitation (EXAMTOPICS_GITHUB_API=<server>/gh) ---

type cachedFile struct {
	Name      string
	Questions []map[string]any
}

func (s *Site) cachedFiles() []cachedFile {
	mk := func(id, text, answer string, topic, qn int) map[string]any {
		return map[string]any{
			"id": id, "exam_id": 1, "topic": strconv.Itoa(topic), "isMC": true,
			"question_text": text,
			"choices":       map[string]string{"A": "Option <b>A</b>", "B": "Option B", "C": "Option C"},
			"answer":        answer, "answer_ET": answer,
			"answer_description": "Because <i>B</i> is right.<br>See docs.",
			"discussion": []map[string]string{
				{"poster": "carol", "content": "First line.\nSecond line.", "upvote_count": "5", "timestamp": "1 year ago"},
				{"poster": "dave", "content": "Disagree<br>it is C", "upvote_count": "x", "timestamp": "2 years ago"},
			},
			"question_images": []string{"/assets/media/exam-media/q" + id + ".png"},
			"answer_images":   []string{},
			"url":             fmt.Sprintf("/discussions/cisco/view/%s-exam-200-301-topic-%d-question-%d-discussion/", id, topic, qn),
			"timestamp":       "2024-01-01",
		}
	}
	return []cachedFile{
		{Name: "200-301_2.json", Questions: []map[string]any{mk("502", "Second file Q1", "B", 1, 3)}},
		{Name: "200-301_1.json", Questions: []map[string]any{
			mk("501", "First file Q1 with <br>break and <img src=\"/img/inline.png\">", "B", 1, 1),
			mk("503", "First file Q2", "AC", 1, 2),
		}},
		{Name: "200-301-v2_1.json", Questions: []map[string]any{mk("601", "Other exam", "A", 1, 1)}},
	}
}

func (s *Site) github(w http.ResponseWriter, r *http.Request, path string) {
	const dir = "/repos/thatonecodes/examtopics-data/contents/"
	if !strings.HasPrefix(path, dir) {
		http.NotFound(w, r)
		return
	}
	rest := strings.TrimPrefix(path, dir)
	base := "http://" + r.Host
	w.Header().Set("Content-Type", "application/json")
	if rest == "Cisco" {
		var list []map[string]string
		for _, f := range s.cachedFiles() {
			list = append(list, map[string]string{"name": f.Name, "url": base + "/gh" + dir + "Cisco/" + f.Name + "?ref=main"})
		}
		json.NewEncoder(w).Encode(list)
		return
	}
	if strings.HasPrefix(rest, "Cisco/") {
		name := strings.TrimPrefix(rest, "Cisco/")
		json.NewEncoder(w).Encode(map[string]string{"name": name, "download_url": base + "/raw/" + name})
		return
	}
	http.NotFound(w, r) // e.g. "Huawei": not in the cached dataset, like the real one
}

func (s *Site) raw(w http.ResponseWriter, r *http.Request, name string) {
	for _, f := range s.cachedFiles() {
		if f.Name == name {
			w.Header().Set("Content-Type", "application/json")
			json.NewEncoder(w).Encode(map[string]any{"pageProps": map[string]any{"questions": f.Questions}})
			return
		}
	}
	http.NotFound(w, r)
}
