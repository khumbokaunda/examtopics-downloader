package main

import (
	"flag"
	"fmt"
	"log"
	"os"

	"examtopics-downloader/internal/constants"
	"examtopics-downloader/internal/fetch"
	"examtopics-downloader/internal/utils"
)

// tokenEnvVar supplies the GitHub token when -t is not given. It is read after
// flag parsing rather than used as the flag's default, because the flag package
// prints non-empty defaults in -h and usage-error output, which would display
// the token on screen.
const tokenEnvVar = "EXAMTOPICS_GITHUB_TOKEN"

func main() {
	provider := flag.String("p", "google", "Name of the exam provider (default -> google)")
	grepStr := flag.String("s", "", "String to grep for in discussion links")
	outputPath := flag.String("o", "examtopics_output.md", "Optional path of the file where the data will be outputted")
	fileType := flag.String("type", "md", "Optionally include file type (default -> .md)")
	commentBool := flag.Bool("c", false, "Optionally include all the comment/discussion text")
	examsFlag := flag.Bool("exams", false, "Optionally show all the possible exams for your selected provider and exit")
	saveUrls := flag.Bool("save-links", false, "Optional argument to save unique links to questions")
	noCache := flag.Bool("no-cache", false, "Optional argument, set to disable looking through cached data on github")
	token := flag.String("t", "", "GitHub PAT to raise the API rate limit; if omitted, read from $"+tokenEnvVar)
	rps := flag.Float64("rps", constants.RequestsPerSecond, "Requests per second when scraping; lower this if ExamTopics returns 429")
	flag.Parse()

	if *token == "" {
		*token = os.Getenv(tokenEnvVar)
	}

	scrapeRate := constants.RequestsPerSecond
	if *rps > 0 {
		scrapeRate = *rps
		fetch.SetScrapeRate(scrapeRate)
	}

	if *examsFlag {
		exams := fetch.GetProviderExams(*provider)
		fmt.Printf("Exams for provider '%s'\n\n", *provider)
		for _, exam := range exams {
			fmt.Println(utils.AddToBaseUrl(exam))
		}
		os.Exit(0)
	}

	if *grepStr == "" {
		log.Printf("running without a valid string to search for with -s, (no_grep_str)!")
	}

	if !*noCache {
		result := fetch.GetCachedPages(*provider, *grepStr, *token)
		switch {
		case len(result.Questions) > 0:
			utils.WriteData(result.Questions, *outputPath, *commentBool, *fileType)
			if result.Failed > 0 {
				warnCacheFailures(result, *token)
				fmt.Fprintf(os.Stderr, "Output is INCOMPLETE: wrote %d questions to %s anyway.\n",
					len(result.Questions), *outputPath)
				os.Exit(1)
			}
			fmt.Printf("Successfully saved cached output to %s (filetype: %s).\n", *outputPath, *fileType)
			os.Exit(0)

		case result.Failed > 0:
			// Cached data exists for this exam but none of it could be
			// downloaded. Saying "no cached data" here would hide the cause,
			// which is usually an exhausted GitHub quota that a token fixes.
			warnCacheFailures(result, *token)
			fmt.Fprintln(os.Stderr, "None of the cached data could be downloaded, falling back to manual scraping.")

		default:
			fmt.Println("No cached data available, falling back to manual scraping.")
		}
	}

	result := fetch.GetAllPages(*provider, *grepStr)
	incomplete := result.FailedPages > 0 || result.FailedQuestions > 0

	if result.FailedPages > 0 {
		fmt.Fprintf(os.Stderr,
			"\nWARNING: %d of %d listing pages could not be fetched, so matching questions may be missing.\n",
			result.FailedPages, result.Pages)
	}
	if result.FailedQuestions > 0 {
		fmt.Fprintf(os.Stderr,
			"\nWARNING: %d of %d question pages could not be fetched; those questions are missing from the output.\n",
			result.FailedQuestions, result.Links)
	}
	if incomplete {
		fmt.Fprintf(os.Stderr,
			"ExamTopics rate-limits aggressively. Retry with slower pacing, e.g. -rps %.2f\n",
			scrapeRate/2)
	}

	if len(result.Questions) == 0 {
		if result.Links > 0 {
			// Links matched, but every question page was refused.
			log.Fatalf("found %d matching question links but none of their pages could be fetched; nothing was written to %s",
				result.Links, *outputPath)
		}
		// Nothing matched on the pages that did load. With most pages loaded,
		// that points at the -s string rather than at rate limiting.
		log.Fatalf("no discussion links containing %q were found on the %d of %d listing pages that loaded; "+
			"check that -s matches how this exam appears in its discussion URLs. Nothing was written to %s",
			*grepStr, result.Pages-result.FailedPages, result.Pages, *outputPath)
	}

	if *saveUrls {
		utils.SaveLinks("saved-links.txt", result.Questions)
	}
	utils.WriteData(result.Questions, *outputPath, *commentBool, *fileType)

	if incomplete {
		fmt.Fprintf(os.Stderr, "Output is INCOMPLETE: wrote %d questions to %s anyway.\n",
			len(result.Questions), *outputPath)
		os.Exit(1)
	}
	fmt.Printf("Successfully saved output to %s (filetype: %s).\n", *outputPath, *fileType)
}

// warnCacheFailures explains why cached files could not be fetched and, when
// the GitHub quota ran out, what to do about it.
func warnCacheFailures(result fetch.CacheResult, token string) {
	fmt.Fprintf(os.Stderr, "\nWARNING: %d of %d cached files could not be fetched.\n", result.Failed, result.Files)
	if !result.RateLimited {
		return
	}
	if token == "" {
		fmt.Fprintf(os.Stderr,
			"The GitHub API quota was exhausted. Unauthenticated requests are capped at 60/hour;\n"+
				"set $%s (or pass -t) to raise this to 5000/hour.\n", tokenEnvVar)
	} else {
		fmt.Fprintln(os.Stderr, "The GitHub API quota for your token was exhausted. Wait for it to reset and retry.")
	}
}
