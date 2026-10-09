package fetch

import (
	"encoding/json"
	"fmt"
	"log"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"

	"examtopics-downloader/internal/constants"
	"examtopics-downloader/internal/models"
	"examtopics-downloader/internal/utils"

	"github.com/PuerkitoBio/goquery"
	"github.com/cheggaaa/pb/v3"
)

func getDataFromLink(link string) *models.QuestionData {
	doc, err := scrapeFetcher.Document(link)
	if err != nil {
		log.Printf("Failed parsing HTML data from link: %v", err)
		return nil
	}

	var allQuestions []string
	doc.Find("li.multi-choice-item").Each(func(i int, s *goquery.Selection) {
		allQuestions = append(allQuestions, utils.CleanText(s.Text()))
	})

	// ExamTopics now embeds the community-voted answer in a hidden JSON
	// <script> inside .voted-answers-tally instead of the old .correct-answer.
	answer := ""
	jsonText := strings.TrimSpace(doc.Find(".voted-answers-tally script").First().Text())
	if jsonText != "" {
		var votes []struct {
			VotedAnswers string `json:"voted_answers"`
			VoteCount    int    `json:"vote_count"`
			IsMostVoted  bool   `json:"is_most_voted"`
		}
		if err := json.Unmarshal([]byte(jsonText), &votes); err != nil {
			log.Printf("failed to parse voted-answers JSON for %s: %v", link, err)
		} else {
			for _, v := range votes {
				if v.IsMostVoted {
					answer = v.VotedAnswers
					break
				}
			}
			if answer == "" && len(votes) > 0 {
				answer = votes[0].VotedAnswers
			}
		}
	}

	// Fallback to the legacy selector if the JSON tally is absent.
	if answer == "" {
		answerText := strings.TrimSpace(doc.Find(".correct-answer").Text())
		if len(answerText) > 0 {
			answer = string(strings.ReplaceAll(strings.ReplaceAll(answerText, " ", ""), "\n", "")[0])
		}
	}

	return &models.QuestionData{
		Title:        utils.CleanText(doc.Find("h1").Text()),
		Header:       strings.ReplaceAll(strings.TrimSpace(doc.Find(".question-discussion-header").Text()), "\t", ""),
		Content:      utils.CleanText(doc.Find(".card-text").Text()),
		Questions:    allQuestions,
		Answer:       answer,
		Timestamp:    utils.CleanText(doc.Find(".discussion-meta-data > i").Text()),
		QuestionLink: link,
		Comments:     utils.CleanText(doc.Find(".discussion-container").Text()),
	}
}

var (
	counterMu sync.Mutex
	counter   int
)

// nextQuestionNumber hands out question numbers. getJSONFromLink runs in
// parallel, so the bare counter++ it used before was a data race and produced
// nondeterministic numbering.
func nextQuestionNumber() int {
	counterMu.Lock()
	defer counterMu.Unlock()
	counter++
	return counter
}

// getJSONFromLink resolves a cached file's GitHub API entry and downloads it.
// cacheClient is used for the API call only; the raw.githubusercontent.com
// download needs no credentials, so it goes out over the unauthenticated
// client.
func getJSONFromLink(link string, cacheFetcher *Fetcher) ([]*models.QuestionData, error) {
	initialResp, err := cacheFetcher.Get(link)
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

	jsonResp, err := scrapeFetcher.Get(downloadURL)
	if err != nil {
		return nil, fmt.Errorf("fetching cached questions from %s: %w", downloadURL, err)
	}

	var content models.JSONResponse
	if err := json.Unmarshal(jsonResp, &content); err != nil {
		return nil, fmt.Errorf("unmarshalling questions data from %s: %w", downloadURL, err)
	}

	fmt.Println("Processing content from:", downloadURL)

	var questions []*models.QuestionData

	if content.PageProps.Questions == nil {
		return nil, fmt.Errorf("no questions found in JSON content from %s", downloadURL)
	}

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

		name := utils.GetNameFromLink(link)

		questions = append(questions, &models.QuestionData{
			Title:        "Examtopics " + strings.ReplaceAll(name, ".json?ref=main", "") + " question #" + strconv.Itoa(nextQuestionNumber()),
			Header:       q.QuestionText,
			Content:      strings.Join(q.QuestionImages, "\n"),
			Questions:    []string{choicesHeader},
			Answer:       q.Answer,
			Timestamp:    q.Timestamp,
			QuestionLink: q.URL,
			Comments:     utils.CleanText(comments),
		})
	}

	return questions, nil
}

// fetchAllPageLinksConcurrently returns the matching links plus the number of
// pages that could not be fetched, so the caller can tell "this exam has no
// questions" apart from "most of the site refused us".
func fetchAllPageLinksConcurrently(providerName, grepStr string, numPages, concurrency int) ([]string, int) {
	var failedPages int32
	var wg sync.WaitGroup
	sem := make(chan struct{}, concurrency)
	results := make(chan []string, numPages)
	bar := pb.StartNew(numPages)
	startTime := utils.StartTime()

	for i := 1; i <= numPages; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()

			// The trailing slash is required; without it ExamTopics returns 404.
			url := fmt.Sprintf("https://www.examtopics.com/discussions/%s/%d/", providerName, i)
			links, err := getLinksFromPage(url, grepStr)
			if err != nil {
				atomic.AddInt32(&failedPages, 1)
				log.Printf("page %d: %v", i, err)
			}
			results <- links
			bar.Increment()
		}(i)
	}

	go func() {
		wg.Wait()
		close(results)
	}()

	// about 10 questions per examtopics page, we can preallocate
	all := make([]string, 0, numPages*10)
	for res := range results {
		all = append(all, res...)
	}

	bar.Finish()
	fmt.Printf("Scraping completed in %s.\n", utils.TimeSince(startTime))
	return all, int(atomic.LoadInt32(&failedPages))
}

// ScrapeResult reports what the live scrape retrieved, including how many
// listing pages were refused, so a caller can distinguish an exam with no
// matching questions from a run the site rate-limited into uselessness.
type ScrapeResult struct {
	Questions   []models.QuestionData
	Pages       int
	FailedPages int
}

// Main concurrent page scraping logic
func GetAllPages(providerName string, grepStr string) ScrapeResult {
	baseURL := fmt.Sprintf("https://www.examtopics.com/discussions/%s/", providerName)
	numPages := getMaxNumPages(baseURL)
	fmt.Printf("Fetching %d pages for provider '%s'\n", numPages, providerName)

	allLinks, failedPages := fetchAllPageLinksConcurrently(providerName, grepStr, numPages, constants.MaxConcurrentRequests)

	unique := utils.DeduplicateLinks(allLinks)
	sortedLinks := utils.SortLinksByQuestionNumber(unique)

	fmt.Printf("Found %d unique matching links:\n", len(sortedLinks))

	var wg sync.WaitGroup
	sem := make(chan struct{}, constants.MaxConcurrentRequests)
	results := make([]*models.QuestionData, len(sortedLinks))
	startTime := utils.StartTime()
	bar := pb.StartNew(len(sortedLinks))

	for i, link := range sortedLinks {
		wg.Add(1)
		url := utils.AddToBaseUrl(link)

		go func(i int, url string) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()

			data := getDataFromLink(url)
			if data != nil {
				results[i] = data
			}
			bar.Increment()
		}(i, url)
	}

	wg.Wait()
	bar.Finish()
	// Filter out nil entries
	var finalData []models.QuestionData
	for _, entry := range results {
		if entry != nil {
			finalData = append(finalData, *entry)
		}
	}

	fmt.Printf("Scraping completed in %s.\n", utils.TimeSince(startTime))

	return ScrapeResult{
		Questions:   finalData,
		Pages:       numPages,
		FailedPages: failedPages,
	}
}
