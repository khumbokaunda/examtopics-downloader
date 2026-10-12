package fetch

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"os"
	"sort"
	"sync"
	"sync/atomic"

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
// number of pages that could not be fetched, and their error summaries.
func collectListingLinks(providerName string, numPages int, keep func(href string) bool) ([]listingLink, int, []string) {
	var (
		failedPages int32
		done        int32
		wg          sync.WaitGroup
		mu          sync.Mutex
		errs        []string
	)
	sem := make(chan struct{}, constants.MaxConcurrentRequests)
	results := make(chan []listingLink, numPages)
	bar := newBar(numPages)
	startTime := utils.StartTime()

	for i := 1; i <= numPages; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()

			// The trailing slash is required; without it ExamTopics returns 404.
			url := fmt.Sprintf("%s/discussions/%s/%d/", utils.BaseURL, providerName, i)
			links, err := getLinksFromPage(url, keep)
			if err != nil {
				atomic.AddInt32(&failedPages, 1)
				msg := fmt.Sprintf("listing page %d: %v", i, err)
				log.Print(msg)
				reporter.Warning("listing_pages", msg)
				mu.Lock()
				errs = append(errs, msg)
				mu.Unlock()
			}
			results <- links
			bar.Increment()
			reporter.Progress("listing_pages", int(atomic.AddInt32(&done, 1)), numPages)
		}(i)
	}

	go func() {
		wg.Wait()
		close(results)
	}()

	// about 10 questions per examtopics page, we can preallocate
	all := make([]listingLink, 0, numPages*10)
	for res := range results {
		all = append(all, res...)
	}

	bar.Finish()
	fmt.Fprintf(out, "Scraping completed in %s.\n", utils.TimeSince(startTime))
	return all, int(atomic.LoadInt32(&failedPages)), errs
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
		wg              sync.WaitGroup
		mu              sync.Mutex
		failedQuestions int32
		done            int32
		errs            []string
		results         = make([]*scrapedItem, len(links))
		sem             = make(chan struct{}, constants.MaxConcurrentRequests)
		bar             = newBar(len(links))
		startTime       = utils.StartTime()
	)

	for i, link := range links {
		wg.Add(1)
		go func(i int, url string) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()

			item, err := getDataFromLink(url)
			if err != nil {
				atomic.AddInt32(&failedQuestions, 1)
				msg := fmt.Sprintf("question page %s: %v", url, err)
				log.Print(msg)
				reporter.Warning("question_pages", msg)
				mu.Lock()
				errs = append(errs, msg)
				mu.Unlock()
			} else {
				results[i] = &item
			}
			bar.Increment()
			reporter.Progress("question_pages", int(atomic.AddInt32(&done, 1)), len(links))
		}(i, utils.AddToBaseUrl(link))
	}

	wg.Wait()
	bar.Finish()
	fmt.Fprintf(out, "Scraping completed in %s.\n", utils.TimeSince(startTime))

	items := make([]scrapedItem, 0, len(links))
	for _, r := range results {
		if r != nil {
			items = append(items, *r)
		}
	}
	return items, int(atomic.LoadInt32(&failedQuestions)), errs
}
