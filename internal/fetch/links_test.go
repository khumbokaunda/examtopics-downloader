package fetch

import "testing"

func TestParseDiscussionLink(t *testing.T) {
	cases := []struct {
		href, provider, code, id string
		topic, question          int
		ok                       bool
	}{
		// Both formats observed in examples/.
		{"/discussions/google/view/54119-exam-professional-cloud-devops-engineer-topic-1-question-1/", "google", "professional-cloud-devops-engineer", "54119", 1, 1, true},
		{"/discussions/lpi/view/64493-exam-010-150-topic-1-question-1-discussion/", "lpi", "010-150", "64493", 1, 1, true},
		// Versioned codes stay whole, so exact matching can tell them apart.
		{"/discussions/huawei/view/1-exam-h12-811-v2-0-topic-1-question-7-discussion/", "huawei", "h12-811-v2-0", "1", 1, 7, true},
		{"https://www.examtopics.com/discussions/huawei/view/2-exam-h12-811-topic-3-question-12/", "huawei", "h12-811", "2", 3, 12, true},
		// Some codes have an underscore (H19-101_V6.0).
		{"https://www.examtopics.com/discussions/huawei/view/391032-exam-h19-101_v60-topic-1-question-16-discussion/", "huawei", "h19-101_v60", "391032", 1, 16, true},
		{"/discussions/huawei/view/3-exam-h12-811-question-4/?foo=bar#x", "huawei", "h12-811", "3", 0, 4, true},
		// Rejected: wrong provider, other host, listing pages, malformed.
		{"/discussions/cisco/view/1-exam-200-301-topic-1-question-1/", "huawei", "", "", 0, 0, false},
		{"https://evil.example/discussions/huawei/view/1-exam-h12-811-topic-1-question-1/", "huawei", "", "", 0, 0, false},
		{"//evil.example/discussions/huawei/view/1-exam-h12-811-topic-1-question-1/", "huawei", "", "", 0, 0, false},
		{"/discussions/huawei/2/", "huawei", "", "", 0, 0, false},
		{"/discussions/huawei/view/abc-exam-h12-811-topic-1-question-1/", "huawei", "", "", 0, 0, false},
		{"/exams/huawei/h12-811/", "huawei", "", "", 0, 0, false},
	}
	for _, c := range cases {
		ref, ok := ParseDiscussionLink(c.href, c.provider)
		if ok != c.ok {
			t.Errorf("%s: ok=%v want %v", c.href, ok, c.ok)
			continue
		}
		if ok && (ref.Code != c.code || ref.ID != c.id || ref.Topic != c.topic || ref.Question != c.question) {
			t.Errorf("%s: got %+v", c.href, ref)
		}
	}
}

func TestSelectorExactVersusSubstring(t *testing.T) {
	v2 := "/discussions/huawei/view/1-exam-h12-811-v2-0-topic-1-question-7-discussion/"
	plain := "/discussions/huawei/view/2-exam-h12-811-topic-1-question-7/"
	exact := Selector{Provider: "huawei", Exam: "h12-811"}
	if !exact.MatchLink(plain) || exact.MatchLink(v2) {
		t.Error("exact selector must match h12-811 and only h12-811")
	}
	substr := Selector{Provider: "huawei", Search: "h12-811"}
	if !substr.MatchLink(plain) || !substr.MatchLink(v2) {
		t.Error("substring selector keeps legacy behaviour")
	}
	if !exact.MatchCachedFile("H12--811_3.json", "") || exact.MatchCachedFile("h12-811-v2-0_1.json", "") {
		t.Error("cached file matching must be exact after normalisation")
	}
}

func TestTextHelpers(t *testing.T) {
	if got := CleanMultiline("  a  \t b\n\n\n  c \r\n"); got != "a b\n\nc" {
		t.Errorf("CleanMultiline: %q", got)
	}
	text, imgs := HTMLToText(`Pick one:<br>  <b>bold</b> &amp; more<p>para</p><img src="/x.png"><script>alert(1)</script>`)
	if text != "Pick one:\nbold & more\npara" || len(imgs) != 1 || imgs[0] != "https://www.examtopics.com/x.png" {
		t.Errorf("HTMLToText: %q %v", text, imgs)
	}
	if text, _ := HTMLToText("<p>one</p><p>two</p>"); text != "one\n\ntwo" {
		t.Errorf("paragraphs: %q", text)
	}
	if got := ExamNameFromTitle("Exam H12-811 topic 1 question 5 discussion"); got != "H12-811" {
		t.Errorf("ExamNameFromTitle: %q", got)
	}
	if got := ExamNameFromTitle("Next page"); got != "" {
		t.Errorf("ExamNameFromTitle on non-title: %q", got)
	}
	topic, q, name := parseHeader("Actual exam question from\n\nGoogle's\nProfessional Cloud DevOps Engineer\n\nQuestion #: 12\nTopic #: 3\n")
	if topic != 3 || q != 12 || name != "Professional Cloud DevOps Engineer" {
		t.Errorf("parseHeader: %d %d %q", topic, q, name)
	}
}

func TestSplitProviderName(t *testing.T) {
	for _, c := range []struct {
		in   string
		name string
		n    int
	}{
		{"Certinia (1 exams)", "Certinia", 1},
		{"Checkpoint (22 exams)", "Checkpoint", 22},
		{"Cisco (1 exam)", "Cisco", 1},
		{"Cisco", "Cisco", 0},
		{"Palo Alto Networks  (31 exams)", "Palo Alto Networks", 31},
		{"(ISC)2 (9 exams)", "(ISC)2", 9},
		{"Odd (name)", "Odd (name)", 0},
	} {
		name, n := SplitProviderName(c.in)
		if name != c.name || n != c.n {
			t.Errorf("SplitProviderName(%q) = %q, %d; want %q, %d", c.in, name, n, c.name, c.n)
		}
	}
}
