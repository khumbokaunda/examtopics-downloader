package tests

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"examtopics-downloader/internal/fetch"
	"examtopics-downloader/internal/utils"
)

func TestFetchURLReturnsBody(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("hello"))
	}))
	defer srv.Close()

	body, err := fetch.NewFetcher(srv.Client(), 1000).Get(srv.URL)
	if err != nil {
		t.Fatalf("expected no error, got %v", err)
	}
	if string(body) != "hello" {
		t.Errorf("expected body %q, got %q", "hello", string(body))
	}
}

// A 404 must surface as a *StatusError carrying the code, not as a nil body
// that callers can only describe as an "empty response".
func TestFetchURLReportsStatusCode(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.NotFound(w, r)
	}))
	defer srv.Close()

	body, err := fetch.NewFetcher(srv.Client(), 1000).Get(srv.URL)
	if err == nil {
		t.Fatal("expected an error for a 404 response, got nil")
	}
	if body != nil {
		t.Errorf("expected nil body, got %q", string(body))
	}

	var statusErr *fetch.StatusError
	if !errors.As(err, &statusErr) {
		t.Fatalf("expected a *fetch.StatusError, got %T: %v", err, err)
	}
	if statusErr.StatusCode != http.StatusNotFound {
		t.Errorf("expected status 404, got %d", statusErr.StatusCode)
	}
	if !strings.Contains(err.Error(), "404") {
		t.Errorf("expected error text to mention 404, got %q", err.Error())
	}
}

// A 429 was previously not retried at all: FetchURL gave up on the first one.
// ExamTopics returns 429 as soon as the scrape outpaces it, so this mattered.
func TestFetchURLRetriesTooManyRequests(t *testing.T) {
	var attempts int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if atomic.AddInt32(&attempts, 1) == 1 {
			w.Header().Set("Retry-After", "1")
			w.WriteHeader(http.StatusTooManyRequests)
			return
		}
		w.Write([]byte("recovered"))
	}))
	defer srv.Close()

	body, err := fetch.NewFetcher(srv.Client(), 1000).Get(srv.URL)
	if err != nil {
		t.Fatalf("expected the retry to succeed, got %v", err)
	}
	if string(body) != "recovered" {
		t.Errorf("expected %q, got %q", "recovered", string(body))
	}
	if got := atomic.LoadInt32(&attempts); got != 2 {
		t.Errorf("expected exactly 2 attempts, got %d", got)
	}
}

// An exhausted GitHub quota must fail fast with an actionable error rather than
// burning retries on something that will not clear for an hour.
func TestFetchURLDetectsExhaustedQuota(t *testing.T) {
	var attempts int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&attempts, 1)
		w.Header().Set("X-RateLimit-Remaining", "0")
		w.Header().Set("X-RateLimit-Reset", strconv.FormatInt(time.Now().Add(time.Hour).Unix(), 10))
		w.WriteHeader(http.StatusForbidden)
	}))
	defer srv.Close()

	_, err := fetch.NewFetcher(srv.Client(), 1000).Get(srv.URL)
	if err == nil {
		t.Fatal("expected an error for an exhausted quota")
	}

	var rateErr *fetch.RateLimitError
	if !errors.As(err, &rateErr) {
		t.Fatalf("expected a *fetch.RateLimitError, got %T: %v", err, err)
	}
	if rateErr.ResetsIn <= 0 {
		t.Errorf("expected a positive reset duration, got %v", rateErr.ResetsIn)
	}
	if got := atomic.LoadInt32(&attempts); got != 1 {
		t.Errorf("expected to fail fast in 1 attempt, got %d", got)
	}
}

// The throttle must gate retries, not just first attempts. Previously the
// rate limiter lived in the callers, so once a host began returning 429 the
// retries went out unmetered and amplified the overload that caused them.
func TestFetcherThrottlesRetries(t *testing.T) {
	var attempts int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if atomic.AddInt32(&attempts, 1) <= 3 {
			w.WriteHeader(http.StatusTooManyRequests)
			return
		}
		w.Write([]byte("ok"))
	}))
	defer srv.Close()

	// 5 requests/second => each attempt waits ~200ms on the throttle.
	f := fetch.NewFetcher(srv.Client(), 5)
	defer f.Stop()

	start := time.Now()
	if _, err := f.Get(srv.URL); err != nil {
		t.Fatalf("expected success after retries, got %v", err)
	}
	elapsed := time.Since(start)

	got := atomic.LoadInt32(&attempts)
	if got != 4 {
		t.Fatalf("expected 4 attempts, got %d", got)
	}
	// 4 attempts * 200ms of throttle = ~800ms, on top of backoff sleeps. If the
	// throttle were bypassed on retries this would come in far quicker.
	if elapsed < 800*time.Millisecond {
		t.Errorf("expected the throttle to gate all %d attempts (>=800ms), took %v", got, elapsed)
	}
	t.Logf("%d attempts in %v", got, elapsed)
}

func TestAddToBaseUrlDoesNotDoublePrefix(t *testing.T) {
	cases := map[string]string{
		"/exams/huawei/h19-101-v6-0/": "https://www.examtopics.com/exams/huawei/h19-101-v6-0/",
		// ExamTopics now serves some hrefs already absolute.
		"https://www.examtopics.com/exams/huawei/h19-101-v6-0/": "https://www.examtopics.com/exams/huawei/h19-101-v6-0/",
		"http://www.examtopics.com/discussions/huawei/1/":       "http://www.examtopics.com/discussions/huawei/1/",
	}
	for in, want := range cases {
		if got := utils.AddToBaseUrl(in); got != want {
			t.Errorf("AddToBaseUrl(%q) = %q, want %q", in, got, want)
		}
	}
}
