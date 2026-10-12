# Repository Audit: examtopics-downloader

Audit of the Go tool as it stood at commit `d58f129`, before the Exam Library work.
It describes what the code actually does, verified from source, the bundled
`examples/` output and live runs reported by the owner. It is not based on the README.

## 1. Entry point and package structure

| Path | Role |
|---|---|
| `cmd/main.go` | Only entry point. Flag parsing, mode selection, exit codes. |
| `internal/fetch/fetch.go` | `Fetcher` (HTTP client + shared throttle + retry), cache lookup (`FetchCachedLinks`, `GetCachedPages`), provider exam list (`GetProviderExams`). |
| `internal/fetch/scraper.go` | Live scraping: listing pages → question pages; cached-file parsing (`getJSONFromLink`). |
| `internal/models` | `QuestionData` (output record), `JSONResponse` (shape of cached files), HTTP transports. |
| `internal/utils` | Text cleanup, link sorting, output writers (`WriteData`), HTTP client constructors. |
| `internal/constants` | Timeouts, pacing, retry limits. |
| `tests/` | External test package; live-network tests skipped under `-short`. |

The README's `go run .` does not work: there are no Go files at the repository root. The real command is `go run ./cmd/main.go`.

## 2. Providers

There is no provider list in code. `-p` is a free-form slug interpolated into URLs
(`/exams/<p>/`, `/discussions/<p>/<n>/`, and `Capitalized` in the GitHub cache path).
The README documents 15 providers. `huawei` is not among them, but it works (verified by owner runs).
So "supported" means "ExamTopics serves pages for this slug".

**Decision:** providers are discovered at runtime from ExamTopics (new `-providers`
mode) or added manually by slug. The web app validates a manually added slug by running an inventory refresh for it.
No provider list is hard-coded in the web app.

## 3. Exam identifiers and question counts

- `-exams` scrapes `.popular-exam-link` hrefs from `/exams/<p>/`. That gives exam slugs but **no counts**,
  and those slugs are not guaranteed to match discussion-link codes. For example, `h19-101-v6-0` is listed
  there but has zero discussions (verified by owner).
- Discussion links carry the exam code. Two formats are confirmed by `examples/` and owner output:
  - `/discussions/google/view/54119-exam-professional-cloud-devops-engineer-topic-1-question-1/`
  - `/discussions/lpi/view/64493-exam-010-150-topic-1-question-1-discussion/`
  - Pattern: `/discussions/<p>/view/<id>-exam-<code>-topic-<t>-question-<q>[-discussion]/`
  - Hrefs may be relative or absolute (absolute hrefs were observed on `/exams/` pages).
- No count is available anywhere except by walking the provider's listing pages and counting unique
  discussion links per code. That's what the owner's manual inventory did, and it doesn't require fetching question pages.
- **Selection is substring-based (`-s`)**: `-s h12-811` also matches `h12-811-v2-0`. Exact matching does not exist.

## 4. Question and discussion collection

**Live path** (two phases, both throttled at 1 req/s through `scrapeFetcher`):
1. Listing pages `/discussions/<p>/<n>/` (trailing slash required; without it ExamTopics returns 404).
   Page count comes from `.discussion-list-page-indicator strong` (2nd element).
2. One page per matching question link, parsed by `getDataFromLink`:

| Field | Selector | Evidence of content |
|---|---|---|
| Title | `h1` | "Exam Professional Cloud DevOps Engineer topic 1 question 1 discussion" |
| Header | `.question-discussion-header` | Lines: "Actual exam question from", "Google's", exam display name, "Question #: 1", "Topic #: 1" |
| Content | `.card-text` | Question text **plus** "Suggested Answer: BE 🗳️" |
| Choices | `li.multi-choice-item` | Always "A. text" form (276/278 example questions) |
| Answer | `.voted-answers-tally script` JSON (most voted), else `.correct-answer` **first character only** | |
| Timestamp | `.discussion-meta-data > i` | "June 1, 2021, 8:11 p.m." |
| Comments | `.discussion-container` text | No sample available |

**Cached path:** the public GitHub repo `thatonecodes/examtopics-data` (`/<Provider>/<code>_<n>.json`)
holds ExamTopics page data (`pageProps.questions`) with **structured** fields: `choices`, `answer`, `answer_ET`,
`answer_description`, `discussion[]{poster, content, upvote_count, timestamp}`, `question_images`,
`answer_images`, `topic`, `url`, `isMC`. Two API calls per file (metadata → `download_url`).

## 5. Output formats

`-type md|json|pdf|html|text` (README says `txt`; the code accepts `text`).
**All of them write the Markdown file first**, then convert it.
- `json`: a flat `[]QuestionData` array with no envelope, no completion status and no provider/exam metadata.
  Choices are free text, answer is a single string, comments are one flattened string.
- `pdf`: `mdtopdf` (gofpdf core fonts, so no Unicode beyond Latin-1, and no images).
- `html`: goldmark conversion of the Markdown.
- `-save-links` exists. `-output-save-links` (in the README) does not.

## 6. Cache behaviour

The only cache is the third-party GitHub dataset, read-only and partial (no Huawei).
There is no local cache: every run re-collects. Output files are overwritten in place.

## 7. Images, answers, explanations, comments

- **Images:** live path: `.Text()` drops `<img>`, so images are lost (0 image references in
  `examples/`). Cached path: `question_images` URLs are joined into `Content`, `answer_images` are ignored.
- **Answers:** a live-path answer is the community most-voted answer, or the first character of `.correct-answer`.
  **37 of 278 questions in `examples/` store an answer that differs from the "Suggested Answer" printed on
  the same page**, mostly multi-answer truncation (`BE` → `B`). The suggested answer is present in `.card-text`
  but never parsed.
- **Explanations:** cached `answer_description` is in the struct but never output. The live path has no selector for it.
- **Comments:** both paths run comments through `CleanText`, which **replaces every newline with a space**.
  That's the cause of the run-on comment paragraph. The cached path has fully structured comments that are flattened
  by code (`"[poster] content\n"` → `CleanText`). The live path's HTML structure is **unknown**: no sample exists.

## 8. Rate limiting and HTTP errors

`Fetcher.Get`: throttle consulted before every attempt (1 req/s default), retries 429/502/503/504
up to 5 times with exponential backoff and jitter, honours `Retry-After` (capped at 30s), fails fast on an exhausted
GitHub quota. Non-retryable statuses return `*StatusError`. Partial runs exit 1 with a warning.
`getMaxNumPages` still `log.Panicf`s on failure, and `GetProviderExams` calls `log.Fatalf`.

## 9. Interactive prompts and unattended execution

- `deleteMarkdownFile` reads stdin ("Delete Markdown file after conversion? (y/n)") for every
  non-md `-type`. That blocks unattended runs.
- `pb` progress bars write terminal escape sequences, and progress messages go to **stdout** mixed with output.
- There's no machine-readable progress, no way to emit structured output to a chosen path, and no exit-code
  distinction between "partial", "nothing matched" and "failed".

## 10. Tests, build and deployment

- `go build ./...`, `go vet ./...` and `go test -short ./...` pass (CI: `.github/workflows/go-tests.yml`).
- Test coverage: fetch retry/throttle/status handling, cached-file ordering, question-page failure
  counting, output writers. Live-network tests skip under `-short`.
- Docker image (`Dockerfile`, GHCR workflow). No configuration files. The token comes via `-t` or
  `$EXAMTOPICS_GITHUB_TOKEN`.

## Required changes (implemented in Phase 2)

| Need | Change |
|---|---|
| Exact exam selection | `-exam <code>`: parse discussion links strictly and compare codes exactly; cached files matched on the `<code>_<n>.json` stem. `-s` is kept unchanged for existing users. |
| Inventory with counts | `-inventory`: walk listing pages and count unique discussion IDs per exact code. No question pages fetched. |
| Provider discovery | `-providers`: `/exams/<slug>/` hrefs from ExamTopics' `/exams/` page. |
| Canonical JSON | `-out-json <path>`: versioned envelope with status, counts, errors and normalised questions (choices split, suggested + community answers, explanations, images, structured comments). Atomic write. |
| Progress | `-events <path>`: JSON Lines events. Human output moves to stderr in machine modes. |
| Unattended runs | `-batch`: never prompt, no progress bars. Prompts also skipped when stdin is not a terminal. |
| Exit codes | 0 complete · 1 failed · 2 partial (data written, flagged) · 3 nothing matched · 64 usage error. |
| Testability | `EXAMTOPICS_BASE_URL` / `EXAMTOPICS_GITHUB_API` overrides so tests run against local fixtures. |

The legacy CLI path (`-s`, `-type`, Markdown output) is preserved.

## Uncertainties and missing evidence

1. **Live-path comment structure.** Without a saved discussion page, per-comment author/date/upvotes cannot
   be extracted reliably. Implemented fix: stop destroying line breaks and keep the comment text as
   `comments_text`. Structured comments come only from the cached path. **Needed: one saved discussion page
   (`curl ... > sample.html`).**
2. **Live-path explanations and answer images.** No selector is known. Not collected.
3. **`/exams/` provider page markup.** Parsed by href pattern only. If it yields nothing, providers can be added manually.
4. **Listing-page anchor text** as an exam display name is parsed only if it matches the observed
   `Exam <name> topic N question M discussion` title pattern. Otherwise the name is left empty.
5. **Cached `question_text` may contain HTML.** It's treated as an untrusted fragment and converted to text, with `<img>` sources extracted.
6. **`answer_ET`** semantics are unknown. It's preserved verbatim as `answer_et`.
7. **Real-site compatibility of the new parsing** cannot be tested from the development sandbox
   (no access to examtopics.com). Tests use fixtures built from the evidence above.
