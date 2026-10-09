package fetch

import (
	"encoding/json"
	"fmt"
	"log"
	"sort"
	"strings"
	"sync"
	"sync/atomic"

	"examtopics-downloader/internal/constants"
	"examtopics-downloader/internal/models"
	"examtopics-downloader/internal/utils"

	"github.com/PuerkitoBio/goquery"
	"github.com/cheggaaa/pb/v3"
)

func getDataFromLink(link string) (*models.QuestionData, error) {
	doc, err := scrapeFetcher.Document(link)
	if err != nil {
		return nil, err
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
	}, nil
}

// getJSONFromLink resolves a cached file's GitHub API entry and downloads it,
// returning its questions in the order they appear in the file. Titles are left
// empty: question numbers depend on the file's position among all the files, so
// the caller assigns them once every file is in order.
//
// apiFetcher may carry a GitHub token and is used for the API call only.
// downloadFetcher fetches the raw.githubusercontent.com file, which needs no
// credentials; it must not be scrapeFetcher, whose throttle is paced for
// examtopics.com and would slow the cached path to a crawl.
func getJSONFromLink(link string, apiFetcher, downloadFetcher *Fetcher) ([]models.QuestionData, error) {
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

	var content models.JSONResponse
	if err := json.Unmarshal(jsonResp, &content); err != nil {
		return nil, fmt.Errorf("unmarshalling questions data from %s: %w", downloadURL, err)
	}

	fmt.Println("Processing content from:", downloadURL)

	var questions []models.QuestionData

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

		questions = append(questions, models.QuestionData{
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

// ScrapeResult reports what the live scrape retrieved. The scrape has two
// phases, listing pages and then one page per question, and either can be
// refused, so both failure counts are carried; otherwise a rate-limited run is
// indistinguishable from an exam that simply has fewer questions.
type ScrapeResult struct {
	Questions       []models.QuestionData
	Pages           int
	FailedPages     int
	Links           int
	FailedQuestions int
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

	questions, failedQuestions := scrapeQuestionPages(sortedLinks)

	return ScrapeResult{
		Questions:       questions,
		Pages:           numPages,
		FailedPages:     failedPages,
		Links:           len(sortedLinks),
		FailedQuestions: failedQuestions,
	}
}

// scrapeQuestionPages fetches one page per question link, preserving the order
// of links, and returns the questions plus how many pages could not be fetched.
// Those failures used to be logged and dropped, so a run that lost questions to
// rate limiting still reported success.
func scrapeQuestionPages(links []string) ([]models.QuestionData, int) {
	var (
		wg              sync.WaitGroup
		failedQuestions int32
		results         = make([]*models.QuestionData, len(links))
		sem             = make(chan struct{}, constants.MaxConcurrentRequests)
		bar             = pb.StartNew(len(links))
		startTime       = utils.StartTime()
	)

	for i, link := range links {
		wg.Add(1)
		go func(i int, url string) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()

			data, err := getDataFromLink(url)
			if err != nil {
				atomic.AddInt32(&failedQuestions, 1)
				log.Printf("question page %s: %v", url, err)
			} else {
				results[i] = data
			}
			bar.Increment()
		}(i, utils.AddToBaseUrl(link))
	}

	wg.Wait()
	bar.Finish()
	fmt.Printf("Scraping completed in %s.\n", utils.TimeSince(startTime))

	return utils.FilterOutNilData(results), int(atomic.LoadInt32(&failedQuestions))
}
