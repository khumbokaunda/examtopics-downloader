package tests

// End-to-end tests of the compiled tool against the fixture site: exit codes, exact exam
// selection, inventory, JSON output, progress events and unattended execution.

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"

	"examtopics-downloader/internal/dataset"
	"examtopics-downloader/internal/progress"
	"examtopics-downloader/tests/fixturesite"
)

var toolBinary string

func TestMain(m *testing.M) {
	dir, err := os.MkdirTemp("", "etdl-bin-")
	if err != nil {
		panic(err)
	}
	toolBinary = filepath.Join(dir, "examtopics-downloader")
	build := exec.Command("go", "build", "-o", toolBinary, "../cmd/main.go")
	build.Stdout, build.Stderr = os.Stdout, os.Stderr
	if err := build.Run(); err != nil {
		panic("building tool: " + err.Error())
	}
	code := m.Run()
	os.RemoveAll(dir)
	os.Exit(code)
}

type toolRun struct {
	exit   int
	stdout string
	stderr string
	dir    string
}

func (r toolRun) file(name string) string { return filepath.Join(r.dir, name) }

func runTool(t *testing.T, site *fixturesite.Site, stdin *os.File, args ...string) toolRun {
	t.Helper()
	srv := httptest.NewServer(site)
	t.Cleanup(srv.Close)
	dir := t.TempDir()

	ctx, cancel := context.WithTimeout(context.Background(), 90*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, toolBinary, append([]string{"-rps", "1000"}, args...)...)
	cmd.Dir = dir
	cmd.Env = append(os.Environ(),
		"EXAMTOPICS_BASE_URL="+srv.URL,
		"EXAMTOPICS_GITHUB_API="+srv.URL+"/gh",
		"EXAMTOPICS_GITHUB_TOKEN=",
	)
	if stdin != nil {
		cmd.Stdin = stdin
	}
	var stdout, stderr bytes.Buffer
	cmd.Stdout, cmd.Stderr = &stdout, &stderr
	err := cmd.Run()
	if ctx.Err() != nil {
		t.Fatalf("tool timed out (did it wait for input?)\nstderr:\n%s", stderr.String())
	}
	exit := 0
	var ee *exec.ExitError
	if errors.As(err, &ee) {
		exit = ee.ExitCode()
	} else if err != nil {
		t.Fatalf("running tool: %v", err)
	}
	return toolRun{exit: exit, stdout: stdout.String(), stderr: stderr.String(), dir: dir}
}

func readDataset(t *testing.T, path string) dataset.Dataset {
	t.Helper()
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading dataset: %v", err)
	}
	var ds dataset.Dataset
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&ds); err != nil {
		t.Fatalf("dataset is not valid JSON of the documented shape: %v", err)
	}
	return ds
}

// readEvents validates the JSON Lines stream and returns its events.
func readEvents(t *testing.T, path string) []progress.Event {
	t.Helper()
	f, err := os.Open(path)
	if err != nil {
		t.Fatalf("opening events: %v", err)
	}
	defer f.Close()
	var events []progress.Event
	terminal := 0
	sc := bufio.NewScanner(f)
	for line := 1; sc.Scan(); line++ {
		var e progress.Event
		dec := json.NewDecoder(strings.NewReader(sc.Text()))
		dec.DisallowUnknownFields()
		if err := dec.Decode(&e); err != nil {
			t.Fatalf("events line %d is not a valid event: %v\n%s", line, err, sc.Text())
		}
		if e.TS == "" || e.Event == "" {
			t.Fatalf("events line %d lacks ts/event: %s", line, sc.Text())
		}
		if _, err := time.Parse("2006-01-02T15:04:05.000Z", e.TS); err != nil {
			t.Fatalf("events line %d has bad timestamp %q", line, e.TS)
		}
		switch e.Event {
		case "completed", "failed":
			terminal++
		case "progress":
			if e.Done == nil || (e.Total != nil && *e.Done > *e.Total) {
				t.Fatalf("events line %d has inconsistent progress: %s", line, sc.Text())
			}
		case "phase", "warning":
		default:
			t.Fatalf("events line %d has unknown event %q", line, e.Event)
		}
		events = append(events, e)
	}
	if terminal != 1 {
		t.Fatalf("expected exactly one terminal event, got %d", terminal)
	}
	return events
}

func last(events []progress.Event) progress.Event { return events[len(events)-1] }

func TestExactExamCollectionIsCompleteAndExcludesVariants(t *testing.T) {
	site := fixturesite.Default(fixturesite.Options{})
	r := runTool(t, site, nil, "-batch", "-no-cache", "-p", "huawei", "-exam", "h12-811",
		"-out-json", "ds.json", "-events", "ev.jsonl")
	if r.exit != 0 {
		t.Fatalf("exit %d, want 0\nstderr:\n%s", r.exit, r.stderr)
	}
	if r.stdout != "" {
		t.Errorf("batch mode must keep stdout clean, got:\n%s", r.stdout)
	}
	ds := readDataset(t, r.file("ds.json"))
	if ds.Status != dataset.StatusComplete || ds.Exam != "h12-811" || ds.Provider != "huawei" || ds.Source != dataset.SourceLive {
		t.Fatalf("unexpected header: status=%s exam=%s provider=%s source=%s", ds.Status, ds.Exam, ds.Provider, ds.Source)
	}
	if len(ds.Questions) != 5 || ds.Counts.Collected != 5 || ds.Counts.QuestionLinks != 5 {
		t.Fatalf("want exactly the 5 h12-811 questions (not h12-811-v2-0), got %d (counts %+v)", len(ds.Questions), ds.Counts)
	}
	for i, q := range ds.Questions {
		if q.Number != i+1 || q.QuestionNumber != i+1 || q.Topic != 1 {
			t.Errorf("question %d: number=%d question_number=%d topic=%d", i, q.Number, q.QuestionNumber, q.Topic)
		}
		if !strings.HasPrefix(q.Text, "H12-811 question") || strings.Contains(q.Text, "Suggested Answer") {
			t.Errorf("question %d text not cleanly extracted: %q", i, q.Text)
		}
		if len(q.Choices) != 5 || q.Choices[0].Letter != "A" || q.Choices[0].Text != "OSPF" {
			t.Errorf("question %d choices: %+v", i, q.Choices)
		}
		if !strings.Contains(q.CommentsText, "I think it is") || !strings.Contains(q.CommentsText, "\n") {
			t.Errorf("question %d comments lost their line structure: %q", i, q.CommentsText)
		}
	}
	// Question 3 has a multi-letter suggested answer that the legacy code truncated.
	if q := ds.Questions[2]; q.Answer != "BE" || q.AnswerSource != "suggested" {
		t.Errorf("multi-answer: got %q from %q, want BE from suggested", q.Answer, q.AnswerSource)
	}
	// Question 2 has no suggested answer: falls back to the community vote.
	if q := ds.Questions[1]; q.Answer != "C" || q.AnswerSource != "community" || len(q.QuestionImages) != 1 {
		t.Errorf("community fallback/images: answer=%q source=%q images=%v", q.Answer, q.AnswerSource, q.QuestionImages)
	}
	if ds.ExamName != "H12-811" {
		t.Errorf("exam name %q", ds.ExamName)
	}
	if _, err := os.Stat(r.file("examtopics_output.md")); err == nil {
		t.Error("legacy Markdown must not be written when only -out-json was requested")
	}

	events := readEvents(t, r.file("ev.jsonl"))
	if e := last(events); e.Event != "completed" || e.Status != "complete" || *e.Questions != 5 {
		t.Errorf("terminal event %+v", e)
	}
	phases := map[string]bool{}
	for _, e := range events {
		phases[e.Phase] = true
	}
	for _, p := range []string{"listing_pages", "question_pages", "writing"} {
		if !phases[p] {
			t.Errorf("missing phase %q in events", p)
		}
	}
}

func TestLegacySubstringSearchStillIncludesVariants(t *testing.T) {
	site := fixturesite.Default(fixturesite.Options{})
	r := runTool(t, site, nil, "-batch", "-no-cache", "-p", "huawei", "-s", "h12-811", "-out-json", "ds.json")
	if r.exit != 0 {
		t.Fatalf("exit %d\n%s", r.exit, r.stderr)
	}
	ds := readDataset(t, r.file("ds.json"))
	if len(ds.Questions) != 7 || ds.Exam != "" || ds.Search != "h12-811" {
		t.Fatalf("substring -s should keep its old behaviour (7 questions incl. v2-0), got %d exam=%q search=%q",
			len(ds.Questions), ds.Exam, ds.Search)
	}
}

func TestInventoryCountsExactCodes(t *testing.T) {
	site := fixturesite.Default(fixturesite.Options{})
	r := runTool(t, site, nil, "-inventory", "-p", "huawei", "-out-json", "inv.json", "-events", "ev.jsonl")
	if r.exit != 0 {
		t.Fatalf("exit %d\n%s", r.exit, r.stderr)
	}
	raw, _ := os.ReadFile(r.file("inv.json"))
	var inv dataset.Inventory
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&inv); err != nil {
		t.Fatalf("inventory JSON: %v", err)
	}
	got := map[string]int{}
	names := map[string]string{}
	for _, e := range inv.Exams {
		got[e.Code] = e.QuestionCount
		names[e.Code] = e.Name
	}
	want := map[string]int{"h12-811": 5, "h12-811-v2-0": 2, "h19-301": 3}
	if len(got) != len(want) {
		t.Fatalf("inventory exams %v, want %v", got, want)
	}
	for k, v := range want {
		if got[k] != v {
			t.Errorf("%s: count %d, want %d", k, got[k], v)
		}
	}
	if names["h12-811-v2-0"] != "H12-811-V2.0" {
		t.Errorf("display name from listing title: %q", names["h12-811-v2-0"])
	}
	if inv.Kind != "inventory" || inv.SchemaVersion != 1 || inv.Status != "complete" || inv.ListingPages != 4 {
		t.Errorf("inventory header %+v", inv)
	}
	// Inventory must not fetch question pages.
	for _, q := range site.Questions() {
		if site.Hits(q.Path()) != 0 {
			t.Fatalf("inventory fetched question page %s", q.Path())
		}
	}
	readEvents(t, r.file("ev.jsonl"))
}

func TestPartialCollectionExitsTwoAndIsLabelled(t *testing.T) {
	site := fixturesite.Default(fixturesite.Options{})
	failing := site.Questions()[3].Path() // h12-811 question 4
	site = fixturesite.Default(fixturesite.Options{FailPaths: map[string]int{failing: 500}})
	r := runTool(t, site, nil, "-batch", "-no-cache", "-p", "huawei", "-exam", "h12-811",
		"-out-json", "ds.json", "-events", "ev.jsonl")
	if r.exit != 2 {
		t.Fatalf("exit %d, want 2 (partial)\n%s", r.exit, r.stderr)
	}
	ds := readDataset(t, r.file("ds.json"))
	if ds.Status != dataset.StatusPartial || len(ds.Questions) != 4 || ds.Counts.FailedQuestions != 1 || len(ds.Errors) != 1 {
		t.Fatalf("partial dataset: status=%s questions=%d counts=%+v errors=%v", ds.Status, len(ds.Questions), ds.Counts, ds.Errors)
	}
	if e := last(readEvents(t, r.file("ev.jsonl"))); e.Event != "completed" || e.Status != "partial" {
		t.Errorf("terminal event %+v", e)
	}
	if !strings.Contains(r.stderr, "INCOMPLETE") {
		t.Errorf("stderr should flag the incomplete output:\n%s", r.stderr)
	}
}

func TestRateLimitedPageIsRetriedThenSucceeds(t *testing.T) {
	flaky := "/discussions/huawei/2/"
	site := fixturesite.Default(fixturesite.Options{FlakyPaths: map[string]bool{flaky: true}})
	r := runTool(t, site, nil, "-batch", "-no-cache", "-p", "huawei", "-exam", "h12-811", "-out-json", "ds.json")
	if r.exit != 0 {
		t.Fatalf("exit %d\n%s", r.exit, r.stderr)
	}
	if site.Hits(flaky) != 2 {
		t.Errorf("429 page requested %d times, want 2 (one retry)", site.Hits(flaky))
	}
	if ds := readDataset(t, r.file("ds.json")); ds.Status != "complete" || len(ds.Questions) != 5 {
		t.Errorf("after a retried 429 the run should be complete, got %s with %d", ds.Status, len(ds.Questions))
	}
}

func TestPersistentRateLimitEndsPartialWithBoundedRetries(t *testing.T) {
	failing := "/discussions/huawei/2/"
	site := fixturesite.Default(fixturesite.Options{FailPaths: map[string]int{failing: 429}})
	r := runTool(t, site, nil, "-batch", "-no-cache", "-p", "huawei", "-exam", "h12-811", "-out-json", "ds.json")
	if r.exit != 2 {
		t.Fatalf("exit %d, want 2\n%s", r.exit, r.stderr)
	}
	if hits := site.Hits(failing); hits != 6 {
		t.Errorf("rate-limited page requested %d times, want 6 (1 + 5 bounded retries)", hits)
	}
	ds := readDataset(t, r.file("ds.json"))
	if ds.Counts.FailedListingPages != 1 || ds.Status != "partial" {
		t.Errorf("counts %+v status %s", ds.Counts, ds.Status)
	}
}

func TestNoMatchExitsThreeWithoutOutput(t *testing.T) {
	site := fixturesite.Default(fixturesite.Options{})
	r := runTool(t, site, nil, "-batch", "-no-cache", "-p", "huawei", "-exam", "h19-101",
		"-out-json", "ds.json", "-events", "ev.jsonl")
	if r.exit != 3 {
		t.Fatalf("exit %d, want 3\n%s", r.exit, r.stderr)
	}
	if _, err := os.Stat(r.file("ds.json")); err == nil {
		t.Error("no dataset may be written when nothing matched")
	}
	if e := last(readEvents(t, r.file("ev.jsonl"))); e.Event != "failed" {
		t.Errorf("terminal event %+v", e)
	}
}

func TestUnreadableListingExitsOne(t *testing.T) {
	site := fixturesite.Default(fixturesite.Options{FailPaths: map[string]int{"/discussions/huawei/": 500}})
	r := runTool(t, site, nil, "-batch", "-no-cache", "-p", "huawei", "-exam", "h12-811", "-out-json", "ds.json", "-events", "ev.jsonl")
	if r.exit != 1 {
		t.Fatalf("exit %d, want 1\n%s", r.exit, r.stderr)
	}
	if e := last(readEvents(t, r.file("ev.jsonl"))); e.Event != "failed" || !strings.Contains(e.Message, "500") {
		t.Errorf("failure should be reported with its cause: %+v", e)
	}
}

func TestUsageErrorsExit64(t *testing.T) {
	site := fixturesite.Default(fixturesite.Options{})
	for _, args := range [][]string{
		{"-exam", "../etc/passwd"},
		{"-exam", "H12-811"},
		{"-exam", "h12-811", "-s", "h12"},
		{"-p", "huawei;rm", "-exam", "h12-811"},
		{"-inventory", "-exam", "h12-811"},
		{"-out-json", "-", "-events", "-"},
		{"stray-argument"},
	} {
		if r := runTool(t, site, nil, args...); r.exit != 64 {
			t.Errorf("%v: exit %d, want 64\n%s", args, r.exit, r.stderr)
		}
	}
}

// Without -batch, a conversion that used to prompt "Delete Markdown file?" must not block
// when stdin is not a terminal. The pipe below is never written to or closed.
func TestConversionDoesNotWaitForInputWithoutTerminal(t *testing.T) {
	pr, pw, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	defer pr.Close()
	defer pw.Close()
	site := fixturesite.Default(fixturesite.Options{})
	r := runTool(t, site, pr, "-no-cache", "-p", "huawei", "-exam", "h12-811", "-type", "html", "-o", "out.md")
	if r.exit != 0 {
		t.Fatalf("exit %d\n%s", r.exit, r.stderr)
	}
	if _, err := os.Stat(r.file("out.html")); err != nil {
		t.Errorf("html output missing: %v", err)
	}
	if _, err := os.Stat(r.file("out.md")); err != nil {
		t.Errorf("markdown should be kept when no one can answer the prompt: %v", err)
	}
}

func TestCachedDatasetPathKeepsStructuredComments(t *testing.T) {
	site := fixturesite.Default(fixturesite.Options{})
	r := runTool(t, site, nil, "-batch", "-p", "cisco", "-exam", "200-301", "-out-json", "ds.json", "-events", "ev.jsonl")
	if r.exit != 0 {
		t.Fatalf("exit %d\n%s", r.exit, r.stderr)
	}
	ds := readDataset(t, r.file("ds.json"))
	if ds.Source != dataset.SourceGitHubCache || ds.Counts.CacheFiles != 2 {
		t.Fatalf("source=%s cache_files=%d (200-301-v2 must not match exactly)", ds.Source, ds.Counts.CacheFiles)
	}
	if len(ds.Questions) != 3 || ds.Questions[0].ID != "501" || ds.Questions[2].ID != "502" {
		t.Fatalf("file order: %+v", ds.Questions)
	}
	q := ds.Questions[0]
	if len(q.Comments) != 2 || q.Comments[0].Author != "carol" || q.Comments[0].Upvotes != 5 ||
		q.Comments[0].Content != "First line.\nSecond line." || q.Comments[1].Content != "Disagree\nit is C" {
		t.Errorf("structured comments: %+v", q.Comments)
	}
	if q.Text != "First file Q1 with\nbreak and" || len(q.QuestionImages) != 2 {
		t.Errorf("HTML fragment → text/images: %q %v", q.Text, q.QuestionImages)
	}
	if q.Choices[0].Text != "Option A" || q.Explanation != "Because B is right.\nSee docs." {
		t.Errorf("choices/explanation: %+v / %q", q.Choices, q.Explanation)
	}
	if ds.Questions[1].Answer != "AC" || ds.Questions[1].QuestionNumber != 2 {
		t.Errorf("multi-answer from cache: %+v", ds.Questions[1])
	}
}

func TestProvidersDiscovery(t *testing.T) {
	site := fixturesite.Default(fixturesite.Options{})
	r := runTool(t, site, nil, "-providers", "-out-json", "p.json")
	if r.exit != 0 {
		t.Fatalf("exit %d\n%s", r.exit, r.stderr)
	}
	raw, _ := os.ReadFile(r.file("p.json"))
	var doc dataset.Providers
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatal(err)
	}
	if len(doc.Providers) != 2 || doc.Providers[0].Slug != "cisco" || doc.Providers[1].Name != "Huawei" {
		t.Errorf("providers %+v (exam links, the index itself and other hosts must be ignored)", doc.Providers)
	}
}

func TestToolVersion(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip()
	}
	r := runTool(t, fixturesite.Default(fixturesite.Options{}), nil, "-version")
	if r.exit != 0 || !strings.HasPrefix(r.stdout, "examtopics-downloader ") {
		t.Errorf("version output %q exit %d", r.stdout, r.exit)
	}
}
