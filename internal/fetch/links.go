package fetch

import (
	"fmt"
	"os"
	"regexp"
	"strconv"
	"strings"

	"examtopics-downloader/internal/utils"
)

// DiscussionRef is a discussion link parsed into its parts. Both observed formats are
// accepted (see docs/AUDIT.md section 3):
//
//	/discussions/google/view/54119-exam-professional-cloud-devops-engineer-topic-1-question-1/
//	/discussions/lpi/view/64493-exam-010-150-topic-1-question-1-discussion/
type DiscussionRef struct {
	Provider string
	ID       string
	Code     string
	Topic    int
	Question int
	Path     string // normalised relative path, e.g. "/discussions/lpi/view/…/"
}

// The exam code is matched lazily so it stops at the first "-topic-N-question-M" (or
// "-question-M") suffix. Codes are lower-case alphanumerics and hyphens.
var discussionPathRE = regexp.MustCompile(
	`^/discussions/([a-z0-9-]+)/view/(\d+)-exam-([a-z0-9-]+?)(?:-topic-(\d+))?-question-(\d+)(?:-discussion)?/?$`)

var examCodeRE = regexp.MustCompile(`^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`)

// ValidExamCode reports whether code is acceptable for -exam.
func ValidExamCode(code string) bool {
	return len(code) <= 128 && examCodeRE.MatchString(code) && !strings.Contains(code, "--")
}

// ValidProviderSlug reports whether slug is acceptable for -p in machine modes.
func ValidProviderSlug(slug string) bool {
	return len(slug) <= 64 && examCodeRE.MatchString(slug) && !strings.Contains(slug, "--")
}

// relativePath strips the scheme and host from an ExamTopics href. Hrefs on other hosts
// are rejected.
func relativePath(href string) (string, bool) {
	href = strings.TrimSpace(href)
	if i := strings.IndexAny(href, "?#"); i >= 0 {
		href = href[:i]
	}
	if strings.HasPrefix(href, "/") && !strings.HasPrefix(href, "//") {
		return href, true
	}
	base := strings.TrimRight(utils.BaseURL, "/")
	for _, prefix := range []string{base, "https://www.examtopics.com", "http://www.examtopics.com", "https://examtopics.com"} {
		if strings.HasPrefix(href, prefix+"/") {
			return href[len(prefix):], true
		}
	}
	return "", false
}

// ParseDiscussionLink parses an href into a DiscussionRef. Links for other providers,
// other hosts, listing pages and anything not matching the discussion format are rejected.
func ParseDiscussionLink(href, provider string) (DiscussionRef, bool) {
	ref, ok := parseDiscussionPath(href)
	if !ok || ref.Provider != provider {
		return DiscussionRef{}, false
	}
	return ref, true
}

// parseDiscussionPath parses a discussion href for any provider.
func parseDiscussionPath(href string) (DiscussionRef, bool) {
	path, ok := relativePath(href)
	if !ok {
		return DiscussionRef{}, false
	}
	m := discussionPathRE.FindStringSubmatch(strings.ToLower(path))
	if m == nil {
		return DiscussionRef{}, false
	}
	topic, _ := strconv.Atoi(m[4])
	question, _ := strconv.Atoi(m[5])
	if !strings.HasSuffix(path, "/") {
		path += "/"
	}
	return DiscussionRef{
		Provider: m[1],
		ID:       m[2],
		Code:     m[3],
		Topic:    topic,
		Question: question,
		Path:     path,
	}, true
}

// Selector decides which discussion links (live path) or cached files (GitHub path)
// belong to the requested exam.
//
// Exam mode compares the parsed exam code exactly: "h12-811" never matches
// "h12-811-v2-0". Search mode keeps the original substring behaviour of -s.
type Selector struct {
	Provider string
	Exam     string // exact code; takes precedence when set
	Search   string // legacy substring search
}

func (s Selector) Exact() bool { return s.Exam != "" }

// MatchLink reports whether a discussion href belongs to the selection.
func (s Selector) MatchLink(href string) bool {
	if s.Exact() {
		ref, ok := ParseDiscussionLink(href, s.Provider)
		return ok && ref.Code == s.Exam
	}
	return utils.GrepString(href, "/discussions") && utils.GrepString(href, s.Search)
}

// cachedFileRE splits a cached dataset file name such as "200-301_128.json".
var cachedFileRE = regexp.MustCompile(`^(.+)_(\d+)\.json$`)

// CachedFileCode returns the exam code a cached file belongs to, normalised the same way
// the original cache matching did (lower-case, repeated hyphens collapsed).
func CachedFileCode(name string) (string, bool) {
	m := cachedFileRE.FindStringSubmatch(name)
	if m == nil {
		return "", false
	}
	code := strings.ToLower(m[1])
	for strings.Contains(code, "--") {
		code = strings.ReplaceAll(code, "--", "-")
	}
	return code, true
}

// MatchCachedFile reports whether a cached file (by name, with its API URL for the legacy
// search) belongs to the selection.
func (s Selector) MatchCachedFile(name, apiURL string) bool {
	if s.Exact() {
		code, ok := CachedFileCode(name)
		return ok && code == s.Exam
	}
	return utils.GrepStringFromCache(apiURL, s.Search)
}

// ReadLinkList reads discussion links for a provider, one per line (blank lines and lines
// starting with # are skipped). Lines that are not discussion links are an error.
func ReadLinkList(path, provider string) ([]string, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var links []string
	for n, line := range strings.Split(string(data), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		ref, ok := ParseDiscussionLink(line, provider)
		if !ok {
			return nil, fmt.Errorf("%s line %d is not a %s discussion link", path, n+1, provider)
		}
		links = append(links, ref.Path)
	}
	return links, nil
}
