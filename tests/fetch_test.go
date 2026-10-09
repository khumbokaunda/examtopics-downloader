package tests

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"examtopics-downloader/internal/fetch"
)

func TestFetchURLReturnsBody(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("hello"))
	}))
	defer srv.Close()

	body, err := fetch.FetchURL(srv.URL, *srv.Client())
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

	body, err := fetch.FetchURL(srv.URL, *srv.Client())
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
