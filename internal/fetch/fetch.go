package fetch

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"examtopics-downloader/internal/constants"
	"examtopics-downloader/internal/dataset"
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
	text := http.StatusText(e.StatusCode)
	if text == "" {
		return fmt.Sprintf("%s returned HTTP %d", e.URL, e.StatusCode)
	}
	return fmt.Sprintf("%s returned HTTP %d (%s)", e.URL, e.StatusCode, text)
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
	return f.get(url, 0)
}

// TooLargeError reports a response body over the caller's size limit. It is not
// transient: the same file will be just as large on the next attempt.
type TooLargeError struct {
	URL   string
	Limit int64
}

func (e *TooLargeError) Error() string {
	return fmt.Sprintf("%s is larger than %d bytes", e.URL, e.Limit)
}

// get is Get with an optional body size limit (0 means none).
func (f *Fetcher) get(url string, limit int64) ([]byte, error) {
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
			var reader io.Reader = resp.Body
			if limit > 0 {
				reader = io.LimitReader(resp.Body, limit+1)
			}
			body, err := io.ReadAll(reader)
			resp.Body.Close()
			if err != nil {
				return nil, fmt.Errorf("reading response body from %q: %w", url, err)
			}
			if limit > 0 && int64(len(body)) > limit {
				return nil, &TooLargeError{URL: url, Limit: limit}
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

	return nil, fmt.Errorf("gave up after %d attempts: %w", constants.MaxRetries+1, lastErr)
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

// getMaxNumPages reads the listing's page count. It returns an error rather than panicking
// so machine modes can report the failure.
func getMaxNumPages(url string) (int, error) {
	doc, err := scrapeFetcher.Document(url)
	if err != nil {
		return 0, fmt.Errorf("reading the listing page count: %w", err)
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

	return pageCount, nil
}

func GetProviderExams(providerName string) []string {
	baseURL := fmt.Sprintf("%s/exams/%s/", utils.BaseURL, providerName)
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

// listingLink is an anchor found on a listing page.
type listingLink struct {
	Href string
	Text string
}

// getLinksFromPage returns the anchors on one listing page that keep accepts.
func getLinksFromPage(url string, keep func(href string) bool) ([]listingLink, error) {
	doc, err := scrapeFetcher.Document(url)
	if err != nil {
		return nil, err
	}

	var links []listingLink
	doc.Find("a").Each(func(i int, s *goquery.Selection) {
		href, exists := s.Attr("href")
		if exists && keep(href) {
			links = append(links, listingLink{Href: href, Text: utils.CleanText(s.Text())})
		}
	})

	return links, nil
}

// githubAPIBase is the GitHub API origin; EXAMTOPICS_GITHUB_API overrides it for tests.
var githubAPIBase = func() string {
	if v := os.Getenv("EXAMTOPICS_GITHUB_API"); v != "" {
		return strings.TrimRight(v, "/")
	}
	return "https://api.github.com"
}()

func FetchCachedLinks(providerName string, sel Selector, cacheFetcher *Fetcher) []string {
	parsedProviderName := utils.CapitalizeFirstLetter(strings.ToLower(providerName))
	baseURL := fmt.Sprintf("%s/repos/thatonecodes/examtopics-data/contents/%s", githubAPIBase, parsedProviderName)
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
		if sel.MatchCachedFile(item.Name, item.URL) {
			linksWithNumbers = append(linksWithNumbers, models.FileInfo{
				URL:    item.URL,
				Name:   item.Name,
				Number: utils.ExtractNumberFromPath(item.Name),
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
	Normalized  []dataset.Question
	Files       int
	Failed      int
	RateLimited bool
	Errors      []string
}

func GetCachedPages(providerName string, sel Selector, token string) CacheResult {
	apiClient := utils.NewHTTPClient()
	if token != "" {
		apiClient = utils.NewGitHubClient(token)
	}
	apiFetcher := NewFetcher(apiClient, constants.CacheRequestsPerSecond)
	defer apiFetcher.Stop()

	// Raw file downloads get their own unauthenticated fetcher. They used to go
	// through scrapeFetcher, which shares the 1/s throttle meant for
	// examtopics.com, so ~150 files took minutes instead of seconds.
	downloadFetcher := NewFetcher(utils.NewHTTPClient(), constants.CacheRequestsPerSecond)
	defer downloadFetcher.Stop()

	reporter.Phase("cache_lookup", "Looking for the exam in the public cached dataset")
	links := FetchCachedLinks(providerName, sel, apiFetcher)
	if len(links) == 0 {
		return CacheResult{}
	}
	reporter.Phase("cache_files", fmt.Sprintf("Downloading %d cached files", len(links)))
	return collectCachedQuestions(links, apiFetcher, downloadFetcher)
}

// collectCachedQuestions downloads every cached file and returns their
// questions in file order, numbered sequentially. links must already be in file
// order, as FetchCachedLinks returns them. Each download writes only its own
// slot in perFile, so the output order no longer depends on which download
// finishes first. Questions repeated across files (same source ID) are kept once.
func collectCachedQuestions(links []string, apiFetcher, downloadFetcher *Fetcher) CacheResult {
	var (
		wg      sync.WaitGroup
		mu      sync.Mutex
		failed  int
		done    int32
		limited bool
		errs    []string
		perFile = make([][]cachedItem, len(links))
	)

	// Previously this launched one unbounded goroutine per file, firing hundreds
	// of simultaneous GitHub API requests and tripping the quota immediately.
	sem := make(chan struct{}, constants.CacheMaxConcurrentRequests)

	for i, link := range links {
		wg.Add(1)
		go func(i int, link string) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()

			items, err := getJSONFromLink(link, apiFetcher, downloadFetcher)
			if err != nil {
				var rateErr *RateLimitError
				mu.Lock()
				failed++
				if errors.As(err, &rateErr) {
					limited = true
				}
				errs = append(errs, err.Error())
				mu.Unlock()
				log.Printf("skipping %s: %v", link, err)
				reporter.Warning("cache_files", err.Error())
			} else {
				perFile[i] = items
			}
			reporter.Progress("cache_files", int(atomic.AddInt32(&done, 1)), len(links))
		}(i, link)
	}
	wg.Wait()

	var (
		all  []models.QuestionData
		norm []dataset.Question
		seen = make(map[string]struct{})
	)
	for i, link := range links {
		name := strings.ReplaceAll(utils.GetNameFromLink(link), ".json?ref=main", "")
		for _, it := range perFile[i] {
			if it.norm.ID != "" {
				if _, dup := seen[it.norm.ID]; dup {
					continue
				}
				seen[it.norm.ID] = struct{}{}
			}
			q := it.legacy
			q.Title = fmt.Sprintf("Examtopics %s question #%d", name, len(all)+1)
			all = append(all, q)
			norm = append(norm, it.norm)
		}
	}

	return CacheResult{
		Questions:   all,
		Normalized:  norm,
		Files:       len(links),
		Failed:      failed,
		RateLimited: limited,
		Errors:      errs,
	}
}

// providerLinkRE matches provider pages such as "/exams/huawei/".
var providerLinkRE = regexp.MustCompile(`^/exams/([a-z0-9-]+)/?$`)

// GetProviders lists providers from the ExamTopics exams index. Only the href pattern is
// relied on, not the page layout (docs/AUDIT.md, uncertainty 3).
// providerCountRE splits "Certinia (1 exams)" from the exams index into name and count.
var providerCountRE = regexp.MustCompile(`^(.*?)\s*\((\d+)\s+exams?\)$`)

// SplitProviderName separates a provider link's text into its name and the exam count the
// index shows after it, if any.
func SplitProviderName(text string) (string, int) {
	text = strings.TrimSpace(text)
	if m := providerCountRE.FindStringSubmatch(text); m != nil {
		n, _ := strconv.Atoi(m[2])
		return strings.TrimSpace(m[1]), n
	}
	return text, 0
}

func GetProviders() (dataset.Providers, error) {
	reporter.Phase("providers", "Reading the provider list")
	doc, err := scrapeFetcher.Document(utils.BaseURL + "/exams/")
	if err != nil {
		return dataset.Providers{}, fmt.Errorf("reading the provider list: %w", err)
	}
	seen := make(map[string]int)
	var providers []dataset.Provider
	doc.Find("a").Each(func(_ int, s *goquery.Selection) {
		href, _ := s.Attr("href")
		path, ok := relativePath(href)
		if !ok {
			return
		}
		m := providerLinkRE.FindStringSubmatch(strings.ToLower(path))
		if m == nil || !ValidProviderSlug(m[1]) {
			return
		}
		name, count := SplitProviderName(utils.CleanText(s.Text()))
		if i, dup := seen[m[1]]; dup {
			if providers[i].Name == m[1] && name != "" {
				providers[i].Name = name
			}
			if providers[i].ExamCount == 0 {
				providers[i].ExamCount = count
			}
			return
		}
		if name == "" {
			name = m[1]
		}
		seen[m[1]] = len(providers)
		providers = append(providers, dataset.Provider{Slug: m[1], Name: name, ExamCount: count})
	})
	sort.Slice(providers, func(i, j int) bool { return providers[i].Slug < providers[j].Slug })
	if providers == nil {
		providers = []dataset.Provider{}
	}
	return dataset.Providers{
		SchemaVersion: dataset.SchemaVersion,
		Kind:          "providers",
		GeneratedAt:   time.Now().UTC().Format(time.RFC3339),
		Status:        dataset.StatusComplete,
		Providers:     providers,
	}, nil
}

// GetInventory lists the exams a provider has discussions for, with the number of unique
// discussions per exact exam code. Only listing pages are fetched.
func GetInventory(provider string) (dataset.Inventory, error) {
	numPages, err := getMaxNumPages(fmt.Sprintf("%s/discussions/%s/", utils.BaseURL, provider))
	if err != nil {
		return dataset.Inventory{}, err
	}
	reporter.Phase("listing_pages", fmt.Sprintf("Scanning %d listing pages", numPages))
	fmt.Fprintf(out, "Scanning %d listing pages for provider '%s'\n", numPages, provider)

	links, failedPages, errs := collectListingLinks(provider, numPages, func(href string) bool {
		_, ok := ParseDiscussionLink(href, provider)
		return ok
	})

	type agg struct {
		ids   map[string]struct{}
		name  string
		links []listingLink
	}
	byCode := make(map[string]*agg)
	newest := 0
	for _, l := range links {
		ref, _ := ParseDiscussionLink(l.Href, provider)
		a := byCode[ref.Code]
		if a == nil {
			a = &agg{ids: make(map[string]struct{})}
			byCode[ref.Code] = a
		}
		a.ids[ref.ID] = struct{}{}
		a.links = append(a.links, l)
		if a.name == "" {
			a.name = ExamNameFromTitle(l.Text)
		}
		if id, err := strconv.Atoi(ref.ID); err == nil && id > newest {
			newest = id
		}
	}

	// Discussion titles only carry the exam code ("Exam H12-811 topic 1 ..."). The provider's
	// exams page has the full titles ("H12-811: HCIA-Datacom V1.0"), so it is read once too.
	// It is optional: if it cannot be read, the exams keep their codes as names.
	titles := map[string]string{}
	if doc, err := scrapeFetcher.Document(fmt.Sprintf("%s/exams/%s/", utils.BaseURL, provider)); err != nil {
		log.Printf("provider exams page: %v", err)
		reporter.Warning("exam_titles", "Exam titles were not read from the provider's exams page: "+err.Error())
	} else {
		titles = ExamTitles(doc, provider)
	}

	exams := make([]dataset.InventoryExam, 0, len(byCode))
	for code, a := range byCode {
		name := a.name
		if t := titleFor(titles, code); t != "" {
			name = t
		}
		exams = append(exams, dataset.InventoryExam{Code: code, Name: name, QuestionCount: len(a.ids),
			Links: uniqueDiscussionLinks(a.links, provider)})
	}
	sort.Slice(exams, func(i, j int) bool { return exams[i].Code < exams[j].Code })

	status := dataset.StatusComplete
	if failedPages > 0 {
		status = dataset.StatusPartial
	}
	return dataset.Inventory{
		SchemaVersion:      dataset.SchemaVersion,
		Kind:               "inventory",
		Provider:           provider,
		GeneratedAt:        time.Now().UTC().Format(time.RFC3339),
		Status:             status,
		ListingPages:       numPages,
		FailedListingPages: failedPages,
		NewestID:           newest,
		Errors:             dataset.CapErrors(errs),
		Exams:              exams,
	}, nil
}
