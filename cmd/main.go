package main

import (
	"flag"
	"fmt"
	"log"
	"os"

	"examtopics-downloader/internal/fetch"
	"examtopics-downloader/internal/utils"
)

func main() {
	provider := flag.String("p", "google", "Name of the exam provider (default -> google)")
	grepStr := flag.String("s", "", "String to grep for in discussion links")
	outputPath := flag.String("o", "examtopics_output.md", "Optional path of the file where the data will be outputted")
	fileType := flag.String("type", "md", "Optionally include file type (default -> .md)")
	commentBool := flag.Bool("c", false, "Optionally include all the comment/discussion text")
	examsFlag := flag.Bool("exams", false, "Optionally show all the possible exams for your selected provider and exit")
	saveUrls := flag.Bool("save-links", false, "Optional argument to save unique links to questions")
	noCache := flag.Bool("no-cache", false, "Optional argument, set to disable looking through cached data on github")
	token := flag.String("t", "", "Optional argument to make cached requests faster to gh api")
	flag.Parse()

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
		if len(result.Questions) > 0 {
			utils.WriteData(result.Questions, *outputPath, *commentBool, *fileType)

			if result.Failed > 0 {
				fmt.Fprintf(os.Stderr,
					"\nWARNING: output is INCOMPLETE - %d of %d cached files could not be fetched.\n",
					result.Failed, result.Files)
				if result.RateLimited {
					if *token == "" {
						fmt.Fprintf(os.Stderr,
							"The GitHub API quota was exhausted. Unauthenticated requests are capped at\n"+
								"60/hour; pass a token with -t to raise this to 5000/hour:\n"+
								"  go run ./cmd/main.go -p %s -s %s -t $GITHUB_TOKEN\n",
							*provider, *grepStr)
					} else {
						fmt.Fprintf(os.Stderr,
							"The GitHub API quota for your token was exhausted. Wait for it to reset and retry.\n")
					}
				}
				fmt.Fprintf(os.Stderr, "Wrote %d questions to %s anyway.\n", len(result.Questions), *outputPath)
				os.Exit(1)
			}

			fmt.Printf("Successfully saved cached output to %s (filetype: %s).\n", *outputPath, *fileType)
			os.Exit(0)
		}
	}

	if !*noCache {
		fmt.Println("No cached data available, falling back to manual scraping.")
	}
	links := fetch.GetAllPages(*provider, *grepStr)

	if len(links) == 0 {
		log.Fatalf("no questions found for provider %q with search string %q; nothing was written to %s",
			*provider, *grepStr, *outputPath)
	}

	if *saveUrls {
		utils.SaveLinks("saved-links.txt", links)
	}
	utils.WriteData(links, *outputPath, *commentBool, *fileType)
	fmt.Printf("Successfully saved output to %s (filetype: %s).\n", *outputPath, *fileType)
}
