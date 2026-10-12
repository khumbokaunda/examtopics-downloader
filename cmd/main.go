package main

import (
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"os"
	"strings"

	"examtopics-downloader/internal/constants"
	"examtopics-downloader/internal/dataset"
	"examtopics-downloader/internal/fetch"
	"examtopics-downloader/internal/models"
	"examtopics-downloader/internal/progress"
	"examtopics-downloader/internal/utils"
)

// tokenEnvVar supplies the GitHub token when -t is not given. It is read after
// flag parsing rather than used as the flag's default, because the flag package
// prints non-empty defaults in -h and usage-error output, which would display
// the token on screen.
const tokenEnvVar = "EXAMTOPICS_GITHUB_TOKEN"

// Exit codes (docs/DATA_MODEL.md section 5).
const (
	exitOK      = 0
	exitFailed  = 1
	exitPartial = 2
	exitNoMatch = 3
	exitUsage   = 64
)

func main() {
	os.Exit(run(os.Args[1:]))
}

type options struct {
	provider, grepStr, exam, outputPath, fileType, token, outJSON, events string
	commentBool, examsFlag, saveUrls, noCache, inventory, providers       bool
	batch, version                                                        bool
	rps                                                                   float64
	setFlags                                                              map[string]bool
}

func parseFlags(args []string) (*options, error) {
	fs := flag.NewFlagSet("examtopics-downloader", flag.ContinueOnError)
	o := &options{setFlags: map[string]bool{}}
	fs.StringVar(&o.provider, "p", "google", "Name of the exam provider (default -> google)")
	fs.StringVar(&o.grepStr, "s", "", "String to grep for in discussion links (substring match)")
	fs.StringVar(&o.exam, "exam", "", "Exact exam code to collect, e.g. h12-811 (never matches h12-811-v2-0)")
	fs.StringVar(&o.outputPath, "o", "examtopics_output.md", "Optional path of the file where the data will be outputted")
	fs.StringVar(&o.fileType, "type", "md", "Optionally include file type (default -> .md)")
	fs.BoolVar(&o.commentBool, "c", false, "Optionally include all the comment/discussion text")
	fs.BoolVar(&o.examsFlag, "exams", false, "Optionally show all the possible exams for your selected provider and exit")
	fs.BoolVar(&o.saveUrls, "save-links", false, "Optional argument to save unique links to questions")
	fs.BoolVar(&o.noCache, "no-cache", false, "Optional argument, set to disable looking through cached data on github")
	fs.StringVar(&o.token, "t", "", "GitHub PAT to raise the API rate limit; if omitted, read from $"+tokenEnvVar)
	fs.Float64Var(&o.rps, "rps", constants.RequestsPerSecond, "Requests per second when scraping; lower this if ExamTopics returns 429")
	fs.BoolVar(&o.inventory, "inventory", false, "List the provider's exams with discussion counts (JSON) and exit")
	fs.BoolVar(&o.providers, "providers", false, "List providers found on ExamTopics (JSON) and exit")
	fs.StringVar(&o.outJSON, "out-json", "", "Write the canonical JSON document to this path (\"-\" for stdout)")
	fs.StringVar(&o.events, "events", "", "Write JSON Lines progress events to this path (\"-\" for stdout)")
	fs.BoolVar(&o.batch, "batch", false, "Unattended mode: never prompt, no progress bars, status text on stderr")
	fs.BoolVar(&o.version, "version", false, "Print the tool version and exit")
	if err := fs.Parse(args); err != nil {
		return nil, err
	}
	if fs.NArg() > 0 {
		return nil, fmt.Errorf("unexpected arguments: %s", strings.Join(fs.Args(), " "))
	}
	fs.Visit(func(f *flag.Flag) { o.setFlags[f.Name] = true })
	if o.token == "" {
		o.token = os.Getenv(tokenEnvVar)
	}
	return o, o.validate()
}

func (o *options) validate() error {
	if !fetch.ValidProviderSlug(o.provider) {
		return fmt.Errorf("invalid provider %q: use lower-case letters, digits and hyphens", o.provider)
	}
	if o.exam != "" && !fetch.ValidExamCode(o.exam) {
		return fmt.Errorf("invalid exam code %q: use lower-case letters, digits and hyphens", o.exam)
	}
	if o.exam != "" && o.grepStr != "" {
		return errors.New("use either -exam (exact) or -s (substring), not both")
	}
	modes := 0
	for _, m := range []bool{o.inventory, o.providers, o.examsFlag} {
		if m {
			modes++
		}
	}
	if modes > 1 {
		return errors.New("-inventory, -providers and -exams cannot be combined")
	}
	if modes == 1 && (o.exam != "" || o.grepStr != "") {
		return errors.New("-exam/-s cannot be combined with -inventory, -providers or -exams")
	}
	if o.events == "-" && o.outJSON == "-" {
		return errors.New("-events and -out-json cannot both write to stdout")
	}
	return nil
}

// machine reports whether output must stay parseable: no prompts, no bars, status text
// on stderr.
func (o *options) machine() bool {
	return o.batch || o.events != "" || o.outJSON == "-" || o.inventory || o.providers
}

func run(args []string) int {
	o, err := parseFlags(args)
	if err != nil {
		if errors.Is(err, flag.ErrHelp) {
			return exitOK
		}
		fmt.Fprintln(os.Stderr, "error:", err)
		return exitUsage
	}

	if o.version {
		t := dataset.ToolInfo()
		fmt.Printf("%s %s (modified: %v)\n", t.Name, t.Revision, t.Modified)
		return exitOK
	}

	var reporter progress.Reporter = progress.Nop{}
	if o.events != "" {
		var w io.Writer = os.Stdout
		if o.events != "-" {
			f, err := os.OpenFile(o.events, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o640)
			if err != nil {
				fmt.Fprintln(os.Stderr, "error: cannot open events file:", err)
				return exitFailed
			}
			defer f.Close()
			w = f
		}
		reporter = progress.NewJSONLines(w)
	}
	if o.machine() {
		utils.Interactive = false
		log.SetOutput(os.Stderr)
		fetch.ConfigureOutput(reporter, os.Stderr, false)
	}

	if o.rps > 0 {
		fetch.SetScrapeRate(o.rps)
	}

	switch {
	case o.examsFlag:
		exams := fetch.GetProviderExams(o.provider)
		fmt.Printf("Exams for provider '%s'\n\n", o.provider)
		for _, exam := range exams {
			fmt.Println(utils.AddToBaseUrl(exam))
		}
		return exitOK
	case o.providers:
		return runProviders(o, reporter)
	case o.inventory:
		return runInventory(o, reporter)
	default:
		return runCollect(o, reporter)
	}
}

func writeDocument(o *options, reporter progress.Reporter, v any) bool {
	path := o.outJSON
	if path == "" {
		path = "-"
	}
	reporter.Phase("writing", "Writing JSON output")
	if err := dataset.WriteJSON(path, v); err != nil {
		reporter.Failed("writing output: " + err.Error())
		fmt.Fprintln(os.Stderr, "error:", err)
		return false
	}
	return true
}

func runProviders(o *options, reporter progress.Reporter) int {
	doc, err := fetch.GetProviders()
	if err != nil {
		reporter.Failed(err.Error())
		fmt.Fprintln(os.Stderr, "error:", err)
		return exitFailed
	}
	if !writeDocument(o, reporter, doc) {
		return exitFailed
	}
	if len(doc.Providers) == 0 {
		reporter.Failed("no providers found on the exams index page")
		return exitNoMatch
	}
	reporter.Completed(doc.Status, 0, fmt.Sprintf("%d providers", len(doc.Providers)))
	return exitOK
}

func runInventory(o *options, reporter progress.Reporter) int {
	inv, err := fetch.GetInventory(o.provider)
	if err != nil {
		reporter.Failed(err.Error())
		fmt.Fprintln(os.Stderr, "error:", err)
		return exitFailed
	}
	if len(inv.Exams) == 0 && inv.FailedListingPages == inv.ListingPages {
		reporter.Failed("every listing page failed")
		return exitFailed
	}
	if !writeDocument(o, reporter, inv) {
		return exitFailed
	}
	if len(inv.Exams) == 0 {
		reporter.Failed("no discussions found for provider " + o.provider)
		return exitNoMatch
	}
	msg := fmt.Sprintf("%d exams", len(inv.Exams))
	reporter.Completed(inv.Status, 0, msg)
	if inv.Status == dataset.StatusPartial {
		fmt.Fprintf(os.Stderr, "WARNING: %d of %d listing pages failed; counts may be low.\n",
			inv.FailedListingPages, inv.ListingPages)
		return exitPartial
	}
	return exitOK
}

// collection is the outcome of either source, in a common shape.
type collection struct {
	source     string
	legacy     []models.QuestionData
	normalized []dataset.Question
	examName   string
	counts     dataset.Counts
	errors     []string
}

func runCollect(o *options, reporter progress.Reporter) int {
	sel := fetch.Selector{Provider: o.provider, Exam: o.exam, Search: o.grepStr}
	if o.exam == "" && o.grepStr == "" {
		log.Printf("running without a valid string to search for with -s, (no_grep_str)!")
	}

	if !o.noCache {
		result := fetch.GetCachedPages(o.provider, sel, o.token)
		switch {
		case len(result.Questions) > 0:
			return finishCollect(o, reporter, collection{
				source:     dataset.SourceGitHubCache,
				legacy:     result.Questions,
				normalized: result.Normalized,
				counts:     dataset.Counts{CacheFiles: result.Files, FailedCacheFiles: result.Failed},
				errors:     result.Errors,
			}, result.RateLimited)
		case result.Failed > 0:
			// Cached data exists for this exam but none of it could be
			// downloaded. Saying "no cached data" here would hide the cause,
			// which is usually an exhausted GitHub quota that a token fixes.
			warnCacheFailures(result.Failed, result.Files, result.RateLimited, o.token)
			reporter.Warning("cache_files", "none of the cached data could be downloaded; falling back to live scraping")
			fmt.Fprintln(os.Stderr, "None of the cached data could be downloaded, falling back to manual scraping.")
		default:
			fmt.Fprintln(humanOut(o), "No cached data available, falling back to manual scraping.")
		}
	}

	result, err := fetch.GetAllPages(o.provider, sel)
	if err != nil {
		reporter.Failed(err.Error())
		fmt.Fprintln(os.Stderr, "error:", err)
		return exitFailed
	}
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
	if result.FailedPages > 0 || result.FailedQuestions > 0 {
		fmt.Fprintf(os.Stderr,
			"ExamTopics rate-limits aggressively. Retry with slower pacing, e.g. -rps %.2f\n", o.rps/2)
	}
	return finishCollect(o, reporter, collection{
		source:     dataset.SourceLive,
		legacy:     result.Questions,
		normalized: result.Normalized,
		examName:   result.ExamName,
		counts: dataset.Counts{
			ListingPages:       result.Pages,
			FailedListingPages: result.FailedPages,
			QuestionLinks:      result.Links,
			FailedQuestions:    result.FailedQuestions,
		},
		errors: result.Errors,
	}, false)
}

func finishCollect(o *options, reporter progress.Reporter, c collection, rateLimited bool) int {
	if len(c.normalized) == 0 {
		if c.counts.Failures() > 0 {
			msg := "no questions retrieved: requests were refused, so matching questions may exist that could not be fetched"
			reporter.Failed(msg)
			fmt.Fprintln(os.Stderr, "error:", msg)
			return exitFailed
		}
		target := o.exam
		if target == "" {
			target = o.grepStr
		}
		msg := fmt.Sprintf("no questions found for provider %q matching %q", o.provider, target)
		reporter.Failed(msg)
		fmt.Fprintln(os.Stderr, "error:", msg+"; nothing was written")
		return exitNoMatch
	}

	ds := dataset.NewDataset(o.provider, o.exam, o.grepStr, c.source, c.counts, c.errors, c.normalized)
	ds.ExamName = c.examName

	if o.outJSON != "" {
		if !writeDocument(o, reporter, ds) {
			return exitFailed
		}
	}
	// The legacy Markdown/-type output is written unless the caller asked only for JSON.
	if o.outJSON == "" || o.setFlags["o"] {
		if o.saveUrls {
			utils.SaveLinks("saved-links.txt", c.legacy)
		}
		utils.WriteData(c.legacy, o.outputPath, o.commentBool, o.fileType)
	}

	if ds.Status == dataset.StatusPartial {
		if c.source == dataset.SourceGitHubCache {
			warnCacheFailures(c.counts.FailedCacheFiles, c.counts.CacheFiles, rateLimited, o.token)
		}
		msg := fmt.Sprintf("%d questions collected; %d expected items failed", len(ds.Questions), c.counts.Failures())
		reporter.Completed(ds.Status, len(ds.Questions), msg)
		fmt.Fprintf(os.Stderr, "Output is INCOMPLETE: %s.\n", msg)
		return exitPartial
	}
	reporter.Completed(ds.Status, len(ds.Questions), fmt.Sprintf("%d questions collected", len(ds.Questions)))
	fmt.Fprintf(humanOut(o), "Successfully saved %d questions (source: %s).\n", len(ds.Questions), c.source)
	return exitOK
}

func humanOut(o *options) io.Writer {
	if o.machine() {
		return os.Stderr
	}
	return os.Stdout
}

// warnCacheFailures explains why cached files could not be fetched and, when
// the GitHub quota ran out, what to do about it.
func warnCacheFailures(failed, files int, rateLimited bool, token string) {
	fmt.Fprintf(os.Stderr, "\nWARNING: %d of %d cached files could not be fetched.\n", failed, files)
	if !rateLimited {
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
