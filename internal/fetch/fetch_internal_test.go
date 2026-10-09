package fetch

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"

	"examtopics-downloader/internal/constants"
)

// Cached questions must come out in file order, numbered sequentially, however
// the downloads happen to finish. Previously they were numbered in completion
// order and then "sorted" by a key that was -1 for every title.
func TestCollectCachedQuestionsKeepsFileOrder(t *testing.T) {
	const files = 5
	const failing = 3

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasPrefix(r.URL.Path, "/api/"):
			// "/api/Cisco/200-301_N.json"
			n, _ := strconv.Atoi(strings.TrimSuffix(strings.SplitN(r.URL.Path, "_", 2)[1], ".json"))
			if n == failing {
				http.NotFound(w, r)
				return
			}
			// Lower-numbered files answer last, so completion order is the
			// reverse of file order.
			time.Sleep(time.Duration(files-n) * 40 * time.Millisecond)
			fmt.Fprintf(w, `{"download_url": %q}`, fmt.Sprintf("http://%s/raw/%d", r.Host, n))
		case strings.HasPrefix(r.URL.Path, "/raw/"):
			n := strings.TrimPrefix(r.URL.Path, "/raw/")
			fmt.Fprintf(w, `{"pageProps":{"questions":[{"question_text":"file %s first"},{"question_text":"file %s second"}]}}`, n, n)
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()

	var links []string
	for n := 1; n <= files; n++ {
		links = append(links, fmt.Sprintf("%s/api/Cisco/200-301_%d.json?ref=main", srv.URL, n))
	}

	apiFetcher := NewFetcher(srv.Client(), 1000)
	defer apiFetcher.Stop()
	downloadFetcher := NewFetcher(srv.Client(), 1000)
	defer downloadFetcher.Stop()

	start := time.Now()
	result := collectCachedQuestions(links, apiFetcher, downloadFetcher)
	elapsed := time.Since(start)

	if result.Files != files || result.Failed != 1 {
		t.Fatalf("expected %d files with 1 failure, got Files=%d Failed=%d", files, result.Files, result.Failed)
	}

	var want []string
	for n := 1; n <= files; n++ {
		if n == failing {
			continue
		}
		want = append(want, fmt.Sprintf("file %d first", n), fmt.Sprintf("file %d second", n))
	}
	if len(result.Questions) != len(want) {
		t.Fatalf("expected %d questions, got %d", len(want), len(result.Questions))
	}
	for i, q := range result.Questions {
		if q.Header != want[i] {
			t.Errorf("question %d: got %q, want %q", i, q.Header, want[i])
		}
		if suffix := fmt.Sprintf(" question #%d", i+1); !strings.HasSuffix(q.Title, suffix) {
			t.Errorf("question %d: title %q should end with %q", i, q.Title, suffix)
		}
	}
	if got := result.Questions[0].Title; got != "Examtopics 200 301_1 question #1" {
		t.Errorf("unexpected title format %q", got)
	}

	// If raw downloads went through scrapeFetcher's 1/s throttle again, eight
	// downloads would take at least seven seconds.
	if elapsed > 4*time.Second {
		t.Errorf("cached downloads took %v; are they being throttled at the scrape rate?", elapsed)
	}
}

// Question-page failures in the second scrape phase must be counted, not
// dropped, and the surviving questions must keep their order.
func TestScrapeQuestionPagesCountsFailures(t *testing.T) {
	SetScrapeRate(1000)
	defer SetScrapeRate(constants.RequestsPerSecond)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/q/2/" {
			http.NotFound(w, r)
			return
		}
		fmt.Fprintf(w, "<html><body><h1>%s</h1></body></html>", r.URL.Path)
	}))
	defer srv.Close()

	links := []string{srv.URL + "/q/1/", srv.URL + "/q/2/", srv.URL + "/q/3/"}
	questions, failed := scrapeQuestionPages(links)

	if failed != 1 {
		t.Errorf("expected 1 failed question page, got %d", failed)
	}
	if len(questions) != 2 {
		t.Fatalf("expected 2 questions, got %d", len(questions))
	}
	if questions[0].Title != "/q/1/" || questions[1].Title != "/q/3/" {
		t.Errorf("expected order /q/1/, /q/3/; got %q, %q", questions[0].Title, questions[1].Title)
	}
}
