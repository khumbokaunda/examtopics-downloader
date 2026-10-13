package fetch

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"os"
	"sort"
	"strconv"
	"sync"
	"sync/atomic"
	"time"

	"examtopics-downloader/internal/constants"
	"examtopics-downloader/internal/dataset"
	"examtopics-downloader/internal/models"
	"examtopics-downloader/internal/progress"
	"examtopics-downloader/internal/utils"

	"github.com/cheggaaa/pb/v3"
)

// Output configuration. The defaults are the original interactive behaviour: status lines
// on stdout and terminal progress bars. Machine modes (cmd/main.go) send status lines to
// stderr, turn bars off and attach a JSON Lines reporter.
var (
	reporter progress.Reporter = progress.Nop{}
	out      io.Writer         = os.Stdout
	showBars                   = true
)

// ConfigureOutput sets where progress goes. r receives structured events; human is the
// writer for status lines; bars enables terminal progress bars.
func ConfigureOutput(r progress.Reporter, human io.Writer, bars bool) {
	if r == nil {
		r = progress.Nop{}
	}
	reporter, out, showBars = r, human, bars
}

func newBar(total int) *pb.ProgressBar {
	bar := pb.New(total)
	if !showBars {
		bar.SetWriter(io.Discard)
	}
	return bar.Start()
}

// cachedItem pairs the legacy record with the normalised one for a cached question.
type cachedItem struct {
	legacy models.QuestionData
	norm   dataset.Question
}

// scrapedItem is one successfully parsed question page.
type scrapedItem struct {
	legacy   models.QuestionData
	norm     dataset.Question
	examName string
}

func getDataFromLink(link string) (scrapedItem, error) {
	doc, err := scrapeFetcher.Document(link)
	if err != nil {
		return scrapedItem{}, err
	}
	legacy, norm, name := parseQuestionPage(doc, link)
	return scrapedItem{legacy: legacy, norm: norm, examName: name}, nil
}

// getJSONFromLink resolves a cached file's GitHub API entry and downloads it, returning
// its questions in file order. Titles are left empty: question numbers depend on the
// file's position among all the files, so the caller assigns them once every file is in
// order.
//
// apiFetcher may carry a GitHub token and is used for the API call only.
// downloadFetcher fetches the raw.githubusercontent.com file, which needs no
// credentials; it must not be scrapeFetcher, whose throttle is paced for
// examtopics.com and would slow the cached path to a crawl.
func getJSONFromLink(link string, apiFetcher, downloadFetcher *Fetcher) ([]cachedItem, error) {
	initialResp, err := apiFetcher.Get(link)
	if err != nil {
		return nil, fmt.Errorf("fetching cached question metadata: %w", err)
	}

	var githubResp map[string]any
	if err := json.Unmarshal(initialResp, &githubResp); err != nil {
		return nil, fmt.Errorf("unmarshalling GitHub API response: %w", err)
	}

	downloadURL, ok := githubResp["download_url"].(string)
	if !ok {
		return nil, fmt.Errorf("no download_url in GitHub API response for %s", link)
	}

	jsonResp, err := downloadFetcher.Get(downloadURL)
	if err != nil {
		return nil, fmt.Errorf("fetching cached questions from %s: %w", downloadURL, err)
	}

	var content struct {
		PageProps struct {
			Questions []cachedQuestion `json:"questions"`
		} `json:"pageProps"`
	}
	if err := json.Unmarshal(jsonResp, &content); err != nil {
		return nil, fmt.Errorf("unmarshalling questions data from %s: %w", downloadURL, err)
	}

	fmt.Fprintln(out, "Processing content from:", downloadURL)

	if content.PageProps.Questions == nil {
		return nil, fmt.Errorf("no questions found in JSON content from %s", downloadURL)
	}

	var items []cachedItem
	for _, q := range content.PageProps.Questions {
		var comments string
		for _, discussion := range q.Discussion {
			comments += fmt.Sprintf("[%s] %s\n", discussion.Poster, discussion.Content)
		}

		var choicesHeader string
		var keys []string
		for key := range q.Choices {
			keys = append(keys, key)
		}
		sort.Strings(keys)
		for _, key := range keys {
			choicesHeader += fmt.Sprintf("**%s:** %s\n\n", key, q.Choices[key])
		}

		items = append(items, cachedItem{
			legacy: models.QuestionData{
				Header:       q.QuestionText,
				Content:      joinLines(q.QuestionImages),
				Questions:    []string{choicesHeader},
				Answer:       q.Answer,
				Timestamp:    q.Timestamp,
				QuestionLink: q.URL,
				Comments:     utils.CleanText(comments),
			},
			norm: normalizeCached(q),
		})
	}

	return items, nil
}

func joinLines(lines []string) string {
	s := ""
	for i, l := range lines {
		if i > 0 {
			s += "\n"
		}
		s += l
	}
	return s
}

// collectListingLinks walks every listing page and returns the anchors keep accepts, the
// number of pages that could not be fetched, and their error summaries. Pages that fail with
// a transient error get one more chance after a cooldown (see retryFailed).
func collectListingLinks(providerName string, numPages int, keep func(href string) bool) ([]listingLink, int, []string) {
	var (
		done   int32
		wg     sync.WaitGroup
		mu     sync.Mutex
		failed = map[int]error{}
		all    = make([]listingLink, 0, numPages*10) // about 10 questions per listing page
	)
	sem := make(chan struct{}, constants.MaxConcurrentRequests)
	bar := newBar(numPages)
	startTime := utils.StartTime()
	pageURL := func(i int) string {
		// The trailing slash is required; without it ExamTopics returns 404.
		return fmt.Sprintf("%s/discussions/%s/%d/", utils.BaseURL, providerName, i)
	}

	for i := 1; i <= numPages; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()

			links, err := getLinksFromPage(pageURL(i), keep)
			mu.Lock()
			if err != nil {
				failed[i] = err
				log.Printf("listing page %d: %v", i, err)
			} else {
				all = append(all, links...)
			}
			mu.Unlock()
			bar.Increment()
			reporter.Progress("listing_pages", int(atomic.AddInt32(&done, 1)), numPages)
		}(i)
	}
	wg.Wait()
	bar.Finish()

	pages := make([]int, 0, len(failed))
	for i := range failed {
		pages = append(pages, i)
	}
	sort.Ints(pages)
	retryFailed("listing_pages", "listing page", pages, failed, func(i int) error {
		links, err := getLinksFromPage(pageURL(i), keep)
		if err == nil {
			all = append(all, links...)
		}
		return err
	})

	var errs []string
	for _, i := range pages {
		if err, ok := failed[i]; ok {
			msg := fmt.Sprintf("listing page %d: %v", i, err)
			reporter.Warning("listing_pages", msg)
			errs = append(errs, msg)
		}
	}
	fmt.Fprintf(out, "Scraping completed in %s.\n", utils.TimeSince(startTime))
	return all, len(errs), errs
}

// RetryCooldown is how long to wait before giving pages that failed with a transient error
// (rate limiting, an overloaded server, a dropped connection) one more chance. It runs
// after the first pass, so the site has had a pause from this client.
// EXAMTOPICS_RETRY_COOLDOWN (seconds) overrides it, for tests.
var RetryCooldown = retryCooldownFromEnv(60 * time.Second)

func retryCooldownFromEnv(def time.Duration) time.Duration {
	if v := os.Getenv("EXAMTOPICS_RETRY_COOLDOWN"); v != "" {
		if secs, err := strconv.Atoi(v); err == nil && secs >= 0 {
			return time.Duration(secs) * time.Second
		}
	}
	return def
}

// retryFailed retries, one at a time, the failed items whose error was transient, after
// RetryCooldown. A success removes the item from failed; a second failure replaces its error.
func retryFailed[K comparable](phase, what string, keys []K, failed map[K]error, fetch func(K) error) {
	var retry []K
	for _, k := range keys {
		if transient(failed[k]) {
			retry = append(retry, k)
		}
	}
	if len(retry) == 0 {
		return
	}
	reporter.Phase(phase, fmt.Sprintf("Retrying %d %ss that failed, after a %s pause", len(retry), what, RetryCooldown))
	fmt.Fprintf(out, "%d %ss failed; retrying them after a %s pause.\n", len(retry), what, RetryCooldown)
	utils.Sleep(RetryCooldown)
	for n, k := range retry {
		if err := fetch(k); err != nil {
			failed[k] = err
		} else {
			delete(failed, k)
		}
		reporter.Progress(phase, n+1, len(retry))
	}
	recovered := 0
	for _, k := range retry {
		if _, still := failed[k]; !still {
			recovered++
		}
	}
	fmt.Fprintf(out, "Second pass recovered %d of %d %ss.\n", recovered, len(retry), what)
}

// transient reports whether an error might go away on a later attempt: retries ran out on a
// retryable status or a transport error. A definite refusal (404, 403) or an exhausted API
// quota will not.
func transient(err error) bool {
	if err == nil {
		return false
	}
	var rl *RateLimitError
	if errors.As(err, &rl) {
		return false
	}
	var tl *TooLargeError
	if errors.As(err, &tl) {
		return false
	}
	var ni *notImageError
	if errors.As(err, &ni) {
		return false
	}
	var se *StatusError
	if errors.As(err, &se) {
		return retryable(se.StatusCode)
	}
	return true
}

// ScrapeResult reports what the live scrape retrieved. The scrape has two
// phases, listing pages and then one page per question, and either can be
// refused, so both failure counts are carried; otherwise a rate-limited run is
// indistinguishable from an exam that simply has fewer questions.
type ScrapeResult struct {
	Questions       []models.QuestionData
	Normalized      []dataset.Question
	ExamName        string
	Pages           int
	FailedPages     int
	Links           int
	FailedQuestions int
	Errors          []string
}

// GetAllPages scrapes every question selected by sel. An error means the scrape could
// not start (the listing page count was unreadable).
func GetAllPages(providerName string, sel Selector) (ScrapeResult, error) {
	baseURL := fmt.Sprintf("%s/discussions/%s/", utils.BaseURL, providerName)
	numPages, err := getMaxNumPages(baseURL)
	if err != nil {
		return ScrapeResult{}, err
	}
	fmt.Fprintf(out, "Fetching %d pages for provider '%s'\n", numPages, providerName)
	reporter.Phase("listing_pages", fmt.Sprintf("Scanning %d listing pages", numPages))

	allLinks, failedPages, errs := collectListingLinks(providerName, numPages, sel.MatchLink)

	var sortedLinks []string
	if sel.Exact() {
		sortedLinks = uniqueDiscussionLinks(allLinks, providerName)
	} else {
		// Absolute ExamTopics hrefs become relative so they dedupe against relative copies
		// of the same link and are fetched through the configured base URL.
		hrefs := make([]string, len(allLinks))
		for i, l := range allLinks {
			hrefs[i] = l.Href
			if p, ok := relativePath(l.Href); ok {
				hrefs[i] = p
			}
		}
		sortedLinks = utils.SortLinksByQuestionNumber(utils.DeduplicateLinks(hrefs))
	}

	fmt.Fprintf(out, "Found %d unique matching links:\n", len(sortedLinks))
	reporter.Phase("question_pages", fmt.Sprintf("Fetching %d question pages", len(sortedLinks)))

	items, failedQuestions, qErrs := scrapeQuestionPages(sortedLinks)

	result := ScrapeResult{
		Pages:           numPages,
		FailedPages:     failedPages,
		Links:           len(sortedLinks),
		FailedQuestions: failedQuestions,
		Errors:          append(errs, qErrs...),
	}
	for _, it := range items {
		result.Questions = append(result.Questions, it.legacy)
		result.Normalized = append(result.Normalized, it.norm)
		if result.ExamName == "" {
			result.ExamName = it.examName
		}
	}
	return result, nil
}

// uniqueDiscussionLinks keeps one link per discussion ID (the same discussion can appear on
// two listing pages when new posts shift the pagination) and orders them by topic,
// question number, then ID.
// GetPagesFromKnownLinks collects an exam from known discussion links: the ones an
// inventory refresh remembered and any the user added. ExamTopics lists a provider's
// discussions newest first, so instead of every listing page it reads them from the first
// until two pages in a row hold nothing newer than the inventory's newest discussion
// (newerThan). A negative newerThan reads no listing pages at all: for exams whose
// discussions ExamTopics does not list, only the given links can be collected.
func GetPagesFromKnownLinks(providerName string, sel Selector, known []string, newerThan int) (ScrapeResult, error) {
	baseURL := fmt.Sprintf("%s/discussions/%s/", utils.BaseURL, providerName)
	numPages := 0
	if newerThan < 0 {
		fmt.Fprintf(out, "Using %d question links you provided; no listing pages are read.\n", len(known))
	} else {
		var err error
		if numPages, err = getMaxNumPages(baseURL); err != nil {
			return ScrapeResult{}, err
		}
		fmt.Fprintf(out, "Using %d known question links; checking the newest listing pages for questions added since.\n", len(known))
		reporter.Phase("listing_pages", fmt.Sprintf("Checking the newest listing pages (%d questions already known)", len(known)))
	}

	links := make([]listingLink, 0, len(known)+20)
	for _, k := range known {
		links = append(links, listingLink{Href: k})
	}
	var errs []string
	failed, read, quiet := 0, 0, 0
	for page := 1; page <= numPages && quiet < 2; page++ {
		pageLinks, err := getLinksFromPage(fmt.Sprintf("%s%d/", baseURL, page), func(href string) bool {
			_, ok := ParseDiscussionLink(href, providerName)
			return ok
		})
		read++
		reporter.Progress("listing_pages", read, -1)
		if err != nil {
			failed++
			quiet = 0
			msg := fmt.Sprintf("listing page %d: %v", page, err)
			reporter.Warning("listing_pages", msg)
			errs = append(errs, msg)
			continue
		}
		newer := false
		for _, l := range pageLinks {
			ref, _ := ParseDiscussionLink(l.Href, providerName)
			if id, err := strconv.Atoi(ref.ID); err == nil && id > newerThan {
				newer = true
			}
			if sel.MatchLink(l.Href) {
				links = append(links, l)
			}
		}
		if newer {
			quiet = 0
		} else {
			quiet++
		}
	}
	sorted := uniqueDiscussionLinks(links, providerName)
	if newerThan >= 0 {
		fmt.Fprintf(out, "Read %d of %d listing pages; %d questions to fetch (%d new since the inventory).\n", read, numPages, len(sorted), len(sorted)-len(uniqueDiscussionLinks(links[:len(known)], providerName)))
	}
	reporter.Phase("question_pages", fmt.Sprintf("Fetching %d question pages", len(sorted)))

	items, failedQuestions, qErrs := scrapeQuestionPages(sorted)
	result := ScrapeResult{
		Pages:           read,
		FailedPages:     failed,
		Links:           len(sorted),
		FailedQuestions: failedQuestions,
		Errors:          append(errs, qErrs...),
	}
	for _, it := range items {
		result.Questions = append(result.Questions, it.legacy)
		result.Normalized = append(result.Normalized, it.norm)
		if result.ExamName == "" {
			result.ExamName = it.examName
		}
	}
	return result, nil
}

func uniqueDiscussionLinks(links []listingLink, provider string) []string {
	seen := make(map[string]struct{})
	var refs []DiscussionRef
	for _, l := range links {
		ref, ok := ParseDiscussionLink(l.Href, provider)
		if !ok {
			continue
		}
		if _, dup := seen[ref.ID]; dup {
			continue
		}
		seen[ref.ID] = struct{}{}
		refs = append(refs, ref)
	}
	sort.SliceStable(refs, func(i, j int) bool {
		a, b := refs[i], refs[j]
		if a.Topic != b.Topic {
			return a.Topic < b.Topic
		}
		if a.Question != b.Question {
			return a.Question < b.Question
		}
		return a.ID < b.ID
	})
	out := make([]string, len(refs))
	for i, r := range refs {
		out[i] = r.Path
	}
	return out
}

// scrapeQuestionPages fetches one page per question link, preserving the order
// of links, and returns the parsed questions, how many pages could not be fetched,
// and their error summaries. Those failures used to be logged and dropped, so a run
// that lost questions to rate limiting still reported success.
func scrapeQuestionPages(links []string) ([]scrapedItem, int, []string) {
	var (
		wg        sync.WaitGroup
		mu        sync.Mutex
		done      int32
		failed    = map[int]error{}
		results   = make([]*scrapedItem, len(links))
		sem       = make(chan struct{}, constants.MaxConcurrentRequests)
		bar       = newBar(len(links))
		startTime = utils.StartTime()
	)

	for i, link := range links {
		wg.Add(1)
		go func(i int, url string) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()

			item, err := getDataFromLink(url)
			mu.Lock()
			if err != nil {
				failed[i] = err
				log.Printf("question page %s: %v", url, err)
			} else {
				results[i] = &item
			}
			mu.Unlock()
			bar.Increment()
			reporter.Progress("question_pages", int(atomic.AddInt32(&done, 1)), len(links))
		}(i, utils.AddToBaseUrl(link))
	}

	wg.Wait()
	bar.Finish()

	order := make([]int, 0, len(failed))
	for i := range failed {
		order = append(order, i)
	}
	sort.Ints(order)
	retryFailed("question_pages", "question page", order, failed, func(i int) error {
		item, err := getDataFromLink(utils.AddToBaseUrl(links[i]))
		if err == nil {
			results[i] = &item
		}
		return err
	})

	var errs []string
	for _, i := range order {
		if err, ok := failed[i]; ok {
			msg := fmt.Sprintf("question page %s: %v", utils.AddToBaseUrl(links[i]), err)
			reporter.Warning("question_pages", msg)
			errs = append(errs, msg)
		}
	}
	fmt.Fprintf(out, "Scraping completed in %s.\n", utils.TimeSince(startTime))

	items := make([]scrapedItem, 0, len(links))
	for _, r := range results {
		if r != nil {
			items = append(items, *r)
		}
	}
	return items, len(errs), errs
}
