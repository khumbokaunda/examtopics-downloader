// Package dataset defines the canonical JSON documents the tool writes for downstream
// consumers (docs/DATA_MODEL.md): collected exam datasets, provider inventories and the
// provider list. Every document carries a schema version and is written atomically.
package dataset

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"runtime/debug"
	"time"
)

const SchemaVersion = 1

const (
	StatusComplete = "complete"
	StatusPartial  = "partial"

	SourceLive        = "live"
	SourceGitHubCache = "github-cache"

	// MaxErrors bounds the error summaries carried in a document.
	MaxErrors = 50
)

type Tool struct {
	Name     string `json:"name"`
	Revision string `json:"revision"`
	Modified bool   `json:"modified"`
}

// ToolInfo identifies the build from Go's embedded VCS metadata.
func ToolInfo() Tool {
	t := Tool{Name: "examtopics-downloader", Revision: "unknown"}
	if info, ok := debug.ReadBuildInfo(); ok {
		for _, s := range info.Settings {
			switch s.Key {
			case "vcs.revision":
				if len(s.Value) > 12 {
					t.Revision = s.Value[:12]
				} else {
					t.Revision = s.Value
				}
			case "vcs.modified":
				t.Modified = s.Value == "true"
			}
		}
	}
	return t
}

type Counts struct {
	ListingPages       int `json:"listing_pages"`
	FailedListingPages int `json:"failed_listing_pages"`
	QuestionLinks      int `json:"question_links"`
	FailedQuestions    int `json:"failed_questions"`
	CacheFiles         int `json:"cache_files"`
	FailedCacheFiles   int `json:"failed_cache_files"`
	Collected          int `json:"collected"`
}

// Failures reports whether any expected item could not be collected.
func (c Counts) Failures() int {
	return c.FailedListingPages + c.FailedQuestions + c.FailedCacheFiles
}

type Choice struct {
	Letter string `json:"letter"`
	Text   string `json:"text"`
}

type Vote struct {
	Answer    string `json:"answer"`
	Count     int    `json:"count"`
	MostVoted bool   `json:"most_voted"`
}

type Comment struct {
	Author    string `json:"author"`
	Timestamp string `json:"timestamp"`
	Upvotes   int    `json:"upvotes"`
	Content   string `json:"content"`
}

type Question struct {
	Number          int       `json:"number"`
	ID              string    `json:"id"`
	Topic           int       `json:"topic,omitempty"`
	QuestionNumber  int       `json:"question_number,omitempty"`
	Text            string    `json:"text"`
	Choices         []Choice  `json:"choices"`
	Answer          string    `json:"answer"`
	AnswerSource    string    `json:"answer_source"`
	SuggestedAnswer string    `json:"suggested_answer"`
	AnswerET        string    `json:"answer_et,omitempty"`
	CommunityVotes  []Vote    `json:"community_votes"`
	Explanation     string    `json:"explanation"`
	QuestionImages  []string  `json:"question_images"`
	AnswerImages    []string  `json:"answer_images"`
	Comments        []Comment `json:"comments"`
	CommentsText    string    `json:"comments_text"`
	SourceURL       string    `json:"source_url"`
	Timestamp       string    `json:"timestamp"`
}

// ResolveAnswer fills Answer and AnswerSource: ExamTopics' suggested answer when present,
// otherwise the community's most-voted answer.
func (q *Question) ResolveAnswer() {
	switch {
	case q.SuggestedAnswer != "":
		q.Answer, q.AnswerSource = q.SuggestedAnswer, "suggested"
	default:
		for _, v := range q.CommunityVotes {
			if v.MostVoted {
				q.Answer, q.AnswerSource = v.Answer, "community"
				return
			}
		}
		if len(q.CommunityVotes) > 0 {
			q.Answer, q.AnswerSource = q.CommunityVotes[0].Answer, "community"
			return
		}
		q.Answer, q.AnswerSource = "", ""
	}
}

// Normalize replaces nil slices with empty ones so the JSON shape never varies.
func (q *Question) Normalize() {
	if q.Choices == nil {
		q.Choices = []Choice{}
	}
	if q.CommunityVotes == nil {
		q.CommunityVotes = []Vote{}
	}
	if q.QuestionImages == nil {
		q.QuestionImages = []string{}
	}
	if q.AnswerImages == nil {
		q.AnswerImages = []string{}
	}
	if q.Comments == nil {
		q.Comments = []Comment{}
	}
}

type Dataset struct {
	SchemaVersion int        `json:"schema_version"`
	Kind          string     `json:"kind"`
	Provider      string     `json:"provider"`
	Exam          string     `json:"exam"`
	ExamName      string     `json:"exam_name"`
	Search        string     `json:"search"`
	Source        string     `json:"source"`
	Status        string     `json:"status"`
	CollectedAt   string     `json:"collected_at"`
	Tool          Tool       `json:"tool"`
	Counts        Counts     `json:"counts"`
	Errors        []string   `json:"errors"`
	Questions     []Question `json:"questions"`
}

// NewDataset numbers the questions, resolves answers and derives status from counts.
func NewDataset(provider, exam, search, source string, counts Counts, errs []string, questions []Question) Dataset {
	for i := range questions {
		questions[i].Number = i + 1
		questions[i].ResolveAnswer()
		questions[i].Normalize()
	}
	if questions == nil {
		questions = []Question{}
	}
	counts.Collected = len(questions)
	status := StatusComplete
	if counts.Failures() > 0 {
		status = StatusPartial
	}
	return Dataset{
		SchemaVersion: SchemaVersion,
		Kind:          "dataset",
		Provider:      provider,
		Exam:          exam,
		Search:        search,
		Source:        source,
		Status:        status,
		CollectedAt:   time.Now().UTC().Format(time.RFC3339),
		Tool:          ToolInfo(),
		Counts:        counts,
		Errors:        CapErrors(errs),
		Questions:     questions,
	}
}

type InventoryExam struct {
	Code          string `json:"code"`
	Name          string `json:"name"`
	QuestionCount int    `json:"question_count"`
}

type Inventory struct {
	SchemaVersion      int             `json:"schema_version"`
	Kind               string          `json:"kind"`
	Provider           string          `json:"provider"`
	GeneratedAt        string          `json:"generated_at"`
	Status             string          `json:"status"`
	ListingPages       int             `json:"listing_pages"`
	FailedListingPages int             `json:"failed_listing_pages"`
	Errors             []string        `json:"errors"`
	Exams              []InventoryExam `json:"exams"`
}

type Provider struct {
	Slug string `json:"slug"`
	Name string `json:"name"`
}

type Providers struct {
	SchemaVersion int        `json:"schema_version"`
	Kind          string     `json:"kind"`
	GeneratedAt   string     `json:"generated_at"`
	Status        string     `json:"status"`
	Providers     []Provider `json:"providers"`
}

func CapErrors(errs []string) []string {
	if errs == nil {
		return []string{}
	}
	if len(errs) > MaxErrors {
		extra := len(errs) - MaxErrors
		errs = append(errs[:MaxErrors:MaxErrors], fmt.Sprintf("… and %d more", extra))
	}
	return errs
}

// WriteJSON writes v to path atomically: a temp file in the same directory, fsync, rename.
// Readers therefore never see a half-written document. path "-" writes to stdout.
func WriteJSON(path string, v any) error {
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return fmt.Errorf("encoding JSON: %w", err)
	}
	data = append(data, '\n')
	if path == "-" {
		_, err := os.Stdout.Write(data)
		return err
	}
	dir := filepath.Dir(path)
	tmp, err := os.CreateTemp(dir, "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return fmt.Errorf("creating temp file: %w", err)
	}
	tmpName := tmp.Name()
	cleanup := func() { tmp.Close(); os.Remove(tmpName) }
	if _, err := tmp.Write(data); err != nil {
		cleanup()
		return fmt.Errorf("writing %s: %w", path, err)
	}
	if err := tmp.Sync(); err != nil {
		cleanup()
		return fmt.Errorf("syncing %s: %w", path, err)
	}
	if err := tmp.Close(); err != nil {
		os.Remove(tmpName)
		return fmt.Errorf("closing %s: %w", path, err)
	}
	if err := os.Chmod(tmpName, 0o640); err != nil {
		os.Remove(tmpName)
		return err
	}
	if err := os.Rename(tmpName, path); err != nil {
		os.Remove(tmpName)
		return fmt.Errorf("publishing %s: %w", path, err)
	}
	return nil
}
