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

	"examtopics-downloader/internal/constants"
	"examtopics-downloader/internal/models"
	"examtopics-downloader/internal/utils"

	"github.com/PuerkitoBio/goquery"
)

var client = utils.NewHTTPClient()

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

// FetchURL retrieves url, retrying on transport errors and 503 responses. Any
// other non-200 status is returned immediately as a *StatusError so callers can
// see what actually went wrong.
func FetchURL(url string, client http.Client) ([]byte, error) {
	backoff := constants.InitalBackoff
	var lastErr error

	for attempt := 0; attempt <= constants.MaxRetries; attempt++ {
		if attempt > 0 {
			delay := utils.DelayTime(backoff)
			log.Printf("Retry attempt %d for URL: %s after waiting %v", attempt, url, delay)
			utils.Sleep(delay)
			backoff = utils.BackoffTime(backoff, constants.BackoffFactor)
		}

		resp, err := client.Get(url)
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
		resp.Body.Close()

		statusErr := &StatusError{URL: url, StatusCode: resp.StatusCode}
		if resp.StatusCode != http.StatusServiceUnavailable {
			return nil, statusErr
		}
		lastErr = statusErr
	}

	return nil, fmt.Errorf("exhausted retries for %q: %w", url, lastErr)
}

func ParseHTML(url string, client http.Client) (*goquery.Document, error) {
	body, err := FetchURL(url, client)
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
	doc, err := ParseHTML(url, *client)
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
	doc, err := ParseHTML(baseURL, *client)
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
func getLinksFromPage(url string, grepStr string) []string {
	doc, err := ParseHTML(url, *client)
	if err != nil {
		log.Printf("Failed to parse HTML for %s: %v", url, err)
		return nil
	}

	var matchingLinks []string
	doc.Find("a").Each(func(i int, s *goquery.Selection) {
		href, exists := s.Attr("href")
		if exists && utils.GrepString(href, "/discussions") && utils.GrepString(href, grepStr) {
			matchingLinks = append(matchingLinks, href)
		}
	})

	return matchingLinks
}

func FetchCachedLinks(providerName string, grepStr string, token string) []string {
	parsedProviderName := utils.CapitalizeFirstLetter(strings.ToLower(providerName))
	baseURL := fmt.Sprintf("https://api.github.com/repos/thatonecodes/examtopics-data/contents/%s", parsedProviderName)
	if token != "" {
		client = utils.NewGitHubClient(token)
	}
	resp, err := FetchURL(baseURL, *client)
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

func GetCachedPages(providerName string, grepStr string, token string) []models.QuestionData {
	links := FetchCachedLinks(providerName, grepStr, token)
	var allData []models.QuestionData

	var wg sync.WaitGroup
	dataChan := make(chan models.QuestionData)

	for _, link := range links {
		wg.Add(1)
		go func(link string) {
			defer wg.Done()
			dataList := getJSONFromLink(link)
			if dataList == nil {
				return
			}
			for _, data := range dataList {
				dataChan <- *data // send each QuestionData into the channel
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

	return utils.SortQuestionDataByPageNumber(allData)
}
