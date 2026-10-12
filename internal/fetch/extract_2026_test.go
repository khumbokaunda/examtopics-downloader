package fetch

import (
	"os"
	"reflect"
	"strings"
	"testing"

	"examtopics-downloader/internal/dataset"

	"github.com/PuerkitoBio/goquery"
)

// The 2026 layout: inline choices, no suggested answer, structured comments with selected
// answers and replies. testdata/discussion-2026.html keeps the markup of a page saved in
// October 2026 with invented text.
func TestParseQuestionPage2026Layout(t *testing.T) {
	f, err := os.Open("testdata/discussion-2026.html")
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	doc, err := goquery.NewDocumentFromReader(f)
	if err != nil {
		t.Fatal(err)
	}
	link := "https://www.examtopics.com/discussions/huawei/view/405798-exam-h13-624-v5-5-topic-1-question-160-discussion/"
	legacy, q, name := parseQuestionPage(doc, link)

	if name != "H13-624_V5.5" {
		t.Errorf("exam name %q", name)
	}
	if q.Topic != 1 || q.QuestionNumber != 160 || q.ID != "405798" {
		t.Errorf("topic %d, number %d, id %q", q.Topic, q.QuestionNumber, q.ID)
	}
	wantText := "An engineer explains how a storage cluster balances load.\n\nWhich of the following statements are false? (Choose all that apply.)"
	if q.Text != wantText {
		t.Errorf("stem %q", q.Text)
	}
	wantChoices := []dataset.Choice{
		{Letter: "A", Text: "The first statement, which wraps\nacross two source lines."},
		{Letter: "B", Text: "The second statement."},
		{Letter: "C", Text: "The third statement."},
		{Letter: "D", Text: "The fourth statement."},
	}
	if !reflect.DeepEqual(q.Choices, wantChoices) {
		t.Errorf("choices %+v", q.Choices)
	}
	if len(q.QuestionImages) != 1 || !strings.HasSuffix(q.QuestionImages[0], "/assets/media/exam-media/04312/0001.png") {
		t.Errorf("images %v", q.QuestionImages)
	}
	if q.SuggestedAnswer != "" {
		t.Errorf("the 2026 page has no suggested answer, got %q", q.SuggestedAnswer)
	}

	if len(q.Comments) != 4 {
		t.Fatalf("comments %+v", q.Comments)
	}
	alice, bob := q.Comments[0], q.Comments[1]
	if alice.Author != "alice" || alice.Timestamp != "October 01, 2026 09:15 am" || alice.Upvotes != 3 ||
		alice.SelectedAnswer != "AD" || alice.Content != "A is false because of the first rule.\nD is false too." || alice.ID != "1" || alice.ParentID != "" {
		t.Errorf("alice %+v", alice)
	}
	if bob.Author != "bob" || bob.ParentID != "1" || bob.Upvotes != 1 || bob.SelectedAnswer != "" || bob.Content != "Agreed with alice." {
		t.Errorf("reply %+v", bob)
	}
	if q.CommentsText != "" {
		t.Errorf("structured comments replace the text dump, got %q", q.CommentsText)
	}

	// AD: alice 1+3 and dave 1+0 = 5; B: carol 1+1 = 2.
	wantVotes := []dataset.Vote{{Answer: "AD", Count: 5, MostVoted: true}, {Answer: "B", Count: 2}}
	if !reflect.DeepEqual(q.CommunityVotes, wantVotes) || !q.VotesFromComments {
		t.Errorf("votes %+v from comments %v", q.CommunityVotes, q.VotesFromComments)
	}
	q.ResolveAnswer()
	if q.Answer != "AD" || q.AnswerSource != "community" {
		t.Errorf("answer %q from %q", q.Answer, q.AnswerSource)
	}
	if q.Timestamp != "April 18, 2026 06:42 pm" {
		t.Errorf("timestamp %q", q.Timestamp)
	}

	// The legacy Markdown record gets the choices, the stem without them, and readable comments.
	if len(legacy.Questions) != 4 || legacy.Questions[0] != "A. The first statement, which wraps across two source lines." {
		t.Errorf("legacy choices %q", legacy.Questions)
	}
	if strings.Contains(legacy.Content, "The second statement") {
		t.Errorf("legacy content still holds the choices: %q", legacy.Content)
	}
	if !strings.HasPrefix(legacy.Answer, "AD (most voted in the comments") {
		t.Errorf("legacy answer %q", legacy.Answer)
	}
	if !strings.Contains(legacy.Comments, "alice (October 01, 2026 09:15 am, selected AD, 3 upvotes): A is false") ||
		!strings.Contains(legacy.Comments, "↳ bob") || strings.Contains(legacy.Comments, "Submit") {
		t.Errorf("legacy comments %q", legacy.Comments)
	}
	if legacy.Timestamp != "April 18, 2026 06:42 pm" {
		t.Errorf("legacy timestamp %q", legacy.Timestamp)
	}
}

func TestSplitInlineChoicesLeavesOrdinaryTextAlone(t *testing.T) {
	text := "Which statement is true?\nA. only one line looks like a choice"
	if stem, choices := splitInlineChoices(text); stem != text || choices != nil {
		t.Errorf("got %q %+v", stem, choices)
	}
	if _, choices := splitInlineChoices("Q\nA. one\nC. skipped a letter\nB. two"); len(choices) != 2 || choices[1].Letter != "B" ||
		choices[0].Text != "one\nC. skipped a letter" {
		t.Errorf("letters must run in order: %+v", choices)
	}
}
