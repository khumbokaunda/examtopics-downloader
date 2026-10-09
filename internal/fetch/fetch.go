package fetch

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"

	"examtopics-downloader/internal/constants"
	"examtopics-downloader/internal/models"
	"examtopics-downloader/internal/utils"

	"github.com/PuerkitoBio/goquery"
)

// Fetcher pairs an HTTP client with a throttle shared by every request it
// makes, retries included. Rate limiting used to live in the callers, which
// gated only each URL's first attempt; once a host started refusing requests,
// the retries went out unmetered and made the overload worse.
type Fetcher struct {
	client   *http.Client
	throttle *time.Ticker
}

func NewFetcher(client *http.Client, rps float64) *Fetcher {
	return &Fetcher{client: client, throttle: utils.CreateRateLimiter(rps)}
}

// Stop releases the throttle's ticker.
func (f *Fetcher) Stop() { f.throttle.Stop() }

// wait blocks until the shared throttle allows another request.
func (f *Fetcher) wait() { <-f.throttle.C }

// scrapeFetcher talks to examtopics.com and must never carry a GitHub token.
// The client behind it was previously a single mutable global that
// FetchCachedLinks reassigned to the authenticated one, which leaked the token
// to examtopics.com on every cache miss that fell through to scraping.
var scrapeFetcher = NewFetcher(utils.NewHTTPClient(), constants.RequestsPerSecond)

// SetScrapeRate replaces the scrape throttle, letting the caller tune pacing
// without a recompile. ExamTopics tolerates less than you would expect.
func SetScrapeRate(rps float64) {
	scrapeFetcher.Stop()
	scrapeFetcher = NewFetcher(utils.NewHTTPClient(), rps)
}

// StatusError reports a response whose status code was not 200 OK. Callers can
// use errors.As to react to a specific code, e.g. treating 404 as "not found"
// rather than as a hard failure.
type StatusError struct {
	URL        string
	StatusCode int
}

func (e *StatusError) Error() string {
	return fmt.Sprintf("request for %q failed with status code %d", e.URL, e.StatusCode)
}

// RateLimitError reports that a host refused the request because an API quota
// is exhausted, rather than because of a transient overload. Retrying will not
// help until the quota resets.
type RateLimitError struct {
	URL        string
	StatusCode int
	ResetsIn   time.Duration
}

func (e *RateLimitError) Error() string {
	if e.ResetsIn > 0 {
		return fmt.Sprintf("rate limit exhausted for %q (status %d), resets in %s",
			e.URL, e.StatusCode, e.ResetsIn.Round(time.Second))
	}
	return fmt.Sprintf("rate limit exhausted for %q (status %d)", e.URL, e.StatusCode)
}

// retryable reports whether a status code is worth another attempt. 429 is
// included: ExamTopics returns it once the scrape outpaces what it tolerates,
// and the previous code gave up on it immediately.
func retryable(code int) bool {
	switch code {
	case http.StatusTooManyRequests,
		http.StatusBadGateway,
		http.StatusServiceUnavailable,
		http.StatusGatewayTimeout:
		return true
	}
	return false
}

// quotaExhausted distinguishes "you have used up your hourly quota" from a
// transient 429. GitHub signals the former with x-ratelimit-remaining: 0, on
// either a 403 or a 429.
func quotaExhausted(resp *http.Response) (time.Duration, bool) {
	if resp.StatusCode != http.StatusForbidden && resp.StatusCode != http.StatusTooManyRequests {
		return 0, false
	}
	if resp.Header.Get("X-RateLimit-Remaining") != "0" {
		return 0, false
	}
	var resetsIn time.Duration
	if reset, err := strconv.ParseInt(resp.Header.Get("X-RateLimit-Reset"), 10, 64); err == nil {
		if d := time.Until(time.Unix(reset, 0)); d > 0 {
			resetsIn = d
		}
	}
	return resetsIn, true
}

// retryAfter reads the Retry-After header, honouring it only up to
// constants.MaxRetryAfter so a hostile value cannot stall the run.
func retryAfter(resp *http.Response) (time.Duration, bool) {
	v := resp.Header.Get("Retry-After")
	if v == "" {
		return 0, false
	}
	secs, err := strconv.Atoi(strings.TrimSpace(v))
	if err != nil || secs < 0 {
		return 0, false
	}
	d := time.Duration(secs) * time.Second
	if d > constants.MaxRetryAfter {
		return constants.MaxRetryAfter, true
	}
	return d, true
}

// Get retrieves url, retrying on transport errors and on statuses that
// retryable reports as transient (429, 502, 503, 504), honouring Retry-After
// when the server sends it. A non-retryable status is returned immediately as a
// *StatusError, and an exhausted API quota as a *RateLimitError, so callers can
// see what actually went wrong instead of receiving a bare nil body.
func (f *Fetcher) Get(url string) ([]byte, error) {
	backoff := constants.InitalBackoff
	var lastErr error

	for attempt := 0; attempt <= constants.MaxRetries; attempt++ {
		if attempt > 0 {
			delay := utils.DelayTime(backoff)
			if hinted, ok := lastErr.(*retryHint); ok && hinted.after > 0 {
				delay = hinted.after
			}
			log.Printf("Retry attempt %d for URL: %s after waiting %v", attempt, url, delay)
			utils.Sleep(delay)
			backoff = utils.BackoffTime(backoff, constants.BackoffFactor)
		}

		// Every attempt, not just the first, goes through the throttle.
		f.wait()

		resp, err := f.client.Get(url)
		if err != nil {
			lastErr = fmt.Errorf("fetching %q: %w", url, err)
			continue
		}

		if resp.StatusCode == http.StatusOK {
			body, err := io.ReadAll(resp.Body)
			resp.Body.Close()
			if err != nil {
				return nil, fmt.Errorf("reading response body from %q: %w", url, err)
			}
			return body, nil
		}

		// An exhausted quota will not clear within our backoff window, so fail
		// fast with an error the caller can explain to the user.
		if resetsIn, exhausted := quotaExhausted(resp); exhausted {
			resp.Body.Close()
			return nil, &RateLimitError{URL: url, StatusCode: resp.StatusCode, ResetsIn: resetsIn}
		}

		wait, hasWait := retryAfter(resp)
		code := resp.StatusCode
		resp.Body.Close()

		if !retryable(code) {
			return nil, &StatusError{URL: url, StatusCode: code}
		}
		lastErr = &retryHint{err: &StatusError{URL: url, StatusCode: code}}
		if hasWait {
			lastErr.(*retryHint).after = wait
		}
	}

	return nil, fmt.Errorf("exhausted retries for %q: %w", url, lastErr)
}

// retryHint carries an optional server-supplied wait alongside the underlying
// error, so the retry loop can honour Retry-After without widening Get's
// signature.
type retryHint struct {
	err   error
	after time.Duration
}

func (h *retryHint) Error() string { return h.err.Error() }
func (h *retryHint) Unwrap() error { return h.err }

func (f *Fetcher) Document(url string) (*goquery.Document, error) {
	body, err := f.Get(url)
	if err != nil {
		return nil, err
	}

	doc, err := goquery.NewDocumentFromReader(bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("failed to parse HTML from URL %q: %w", url, err)
	}

	return doc, nil
}

// Fetches total number of pages
func getMaxNumPages(url string) int {
	doc, err := scrapeFetcher.Document(url)
	if err != nil {
		log.Panicf("Failed parsing HTML for number of pages: %v", err)
	}

	var pageCount int
	doc.Find(".discussion-list-page-indicator strong").Each(func(i int, s *goquery.Selection) {
		if i == 1 {
			pageCount, _ = strconv.Atoi(strings.TrimSpace(s.Text()))
		}
	})

	// Handle the null case
	if pageCount == 0 {
		pageCount = 1
	}

	return pageCount
}

func GetProviderExams(providerName string) []string {
	baseURL := fmt.Sprintf("https://www.examtopics.com/exams/%s/", providerName)
	doc, err := scrapeFetcher.Document(baseURL)
	if err != nil {
		log.Fatalf("Failed to parse HTML for provider exams: %v", err)
	}

	var allExams []string
	doc.Find(".popular-exam-link").Each(func(i int, s *goquery.Selection) {
		href, exists := s.Attr("href")
		if exists {
			allExams = append(allExams, utils.CleanText(href))
		}
	})

	return allExams
}

// Extracts matching links from a single page
func getLinksFromPage(url string, grepStr string) ([]string, error) {
	doc, err := scrapeFetcher.Document(url)
	if err != nil {
		return nil, err
	}

	var matchingLinks []string
	doc.Find("a").Each(func(i int, s *goquery.Selection) {
		href, exists := s.Attr("href")
		if exists && utils.GrepString(href, "/discussions") && utils.GrepString(href, grepStr) {
			matchingLinks = append(matchingLinks, href)
		}
	})

	return matchingLinks, nil
}

func FetchCachedLinks(providerName string, grepStr string, cacheFetcher *Fetcher) []string {
	parsedProviderName := utils.CapitalizeFirstLetter(strings.ToLower(providerName))
	baseURL := fmt.Sprintf("https://api.github.com/repos/thatonecodes/examtopics-data/contents/%s", parsedProviderName)
	resp, err := cacheFetcher.Get(baseURL)
	if err != nil {
		// A cache miss is expected for providers the upstream dataset does not
		// cover; it is not a failure, so say so and let the caller scrape.
		var statusErr *StatusError
		if errors.As(err, &statusErr) && statusErr.StatusCode == http.StatusNotFound {
			log.Printf("no cached data for provider %q, falling back to scraping", providerName)
		} else {
			log.Printf("could not fetch cached links for provider %q: %v", providerName, err)
		}
		return nil
	}

	var content []models.FileInfo
	if err := json.Unmarshal(resp, &content); err != nil {
		log.Printf("error unmarshaling cached link listing: %v", err)
		return nil
	}

	var linksWithNumbers []models.FileInfo
	for _, item := range content {
		link := item.URL
		number := utils.ExtractNumberFromPath(item.Name)
		if utils.GrepStringFromCache(link, grepStr) {
			linksWithNumbers = append(linksWithNumbers, models.FileInfo{
				URL:    link,
				Name:   item.Name,
				Number: number,
			})
		}
	}

	return utils.SortCachedLinks(linksWithNumbers)
}

// CacheResult reports what the cached-data path managed to retrieve. Failed
// counts files that could not be fetched, so callers can tell a complete run
// from a partial one instead of silently writing a fraction of the exam.
type CacheResult struct {
	Questions   []models.QuestionData
	Files       int
	Failed      int
	RateLimited bool
}

func GetCachedPages(providerName string, grepStr string, token string) CacheResult {
	cacheClient := utils.NewHTTPClient()
	if token != "" {
		cacheClient = utils.NewGitHubClient(token)
	}
	cacheFetcher := NewFetcher(cacheClient, constants.CacheRequestsPerSecond)
	defer cacheFetcher.Stop()

	links := FetchCachedLinks(providerName, grepStr, cacheFetcher)
	if len(links) == 0 {
		return CacheResult{}
	}

	var (
		wg       sync.WaitGroup
		mu       sync.Mutex
		allData  []models.QuestionData
		failed   int
		limited  bool
		dataChan = make(chan models.QuestionData, len(links))
	)

	// Previously this launched one unbounded goroutine per file, firing hundreds
	// of simultaneous GitHub API requests and tripping the quota immediately.
	sem := make(chan struct{}, constants.CacheMaxConcurrentRequests)

	for _, link := range links {
		wg.Add(1)
		go func(link string) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()

			dataList, err := getJSONFromLink(link, cacheFetcher)
			if err != nil {
				var rateErr *RateLimitError
				mu.Lock()
				failed++
				if errors.As(err, &rateErr) {
					limited = true
				}
				mu.Unlock()
				log.Printf("skipping %s: %v", link, err)
				return
			}
			for _, data := range dataList {
				dataChan <- *data
			}
		}(link)
	}

	go func() {
		wg.Wait()
		close(dataChan)
	}()

	for data := range dataChan {
		allData = append(allData, data)
	}

	return CacheResult{
		Questions:   utils.SortQuestionDataByPageNumber(allData),
		Files:       len(links),
		Failed:      failed,
		RateLimited: limited,
	}
}
