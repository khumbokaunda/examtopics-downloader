// Package progress emits machine-readable progress as JSON Lines, one event per line,
// for callers such as the Examtopics web application. Human-readable output never goes
// to this stream, so it is always valid JSON Lines.
package progress

import (
	"encoding/json"
	"io"
	"sync"
	"time"
)

// Event is one line of the stream. See docs/DATA_MODEL.md section 4.
type Event struct {
	TS        string `json:"ts"`
	Event     string `json:"event"`
	Phase     string `json:"phase,omitempty"`
	Message   string `json:"message,omitempty"`
	Done      *int   `json:"done,omitempty"`
	Total     *int   `json:"total,omitempty"`
	Status    string `json:"status,omitempty"`
	Questions *int   `json:"questions,omitempty"`
}

// Reporter receives progress from the collection code.
type Reporter interface {
	Phase(phase, message string)
	// Progress reports done items of total; a negative total means the total is unknown.
	Progress(phase string, done, total int)
	Warning(phase, message string)
	Completed(status string, questions int, message string)
	Failed(message string)
}

// Nop discards everything. It is the default so library code can always report.
type Nop struct{}

func (Nop) Phase(string, string)          {}
func (Nop) Progress(string, int, int)     {}
func (Nop) Warning(string, string)        {}
func (Nop) Completed(string, int, string) {}
func (Nop) Failed(string)                 {}

// JSONLines writes events to w. It is safe for concurrent use, and it writes at most
// one terminal event (completed or failed).
type JSONLines struct {
	mu       sync.Mutex
	enc      *json.Encoder
	finished bool
	now      func() time.Time
}

func NewJSONLines(w io.Writer) *JSONLines {
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(false)
	return &JSONLines{enc: enc, now: time.Now}
}

func (j *JSONLines) emit(e Event, terminal bool) {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.finished {
		return
	}
	if terminal {
		j.finished = true
	}
	e.TS = j.now().UTC().Format("2006-01-02T15:04:05.000Z")
	// Encoder.Encode writes the object plus "\n" in one Write call.
	_ = j.enc.Encode(e)
}

func (j *JSONLines) Phase(phase, message string) {
	j.emit(Event{Event: "phase", Phase: phase, Message: message}, false)
}

func (j *JSONLines) Progress(phase string, done, total int) {
	e := Event{Event: "progress", Phase: phase, Done: &done}
	if total >= 0 {
		e.Total = &total
	}
	j.emit(e, false)
}

func (j *JSONLines) Warning(phase, message string) {
	j.emit(Event{Event: "warning", Phase: phase, Message: message}, false)
}

func (j *JSONLines) Completed(status string, questions int, message string) {
	j.emit(Event{Event: "completed", Status: status, Questions: &questions, Message: message}, true)
}

func (j *JSONLines) Failed(message string) {
	j.emit(Event{Event: "failed", Message: message}, true)
}
