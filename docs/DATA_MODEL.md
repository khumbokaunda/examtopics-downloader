# Data Model

The Go tool and the web application exchange data only through the files described here.
JSON is the source of truth for question content. MySQL holds metadata that can be
rebuilt from the manifests (see `web/bin/rebuild-index.php`).

All documents carry `schema_version`. Readers must reject versions they do not know.

## 1. Collection dataset (`-out-json`, schema_version 1)

Written atomically by `examtopics-downloader -exam <code> -out-json <path>`.

```json
{
  "schema_version": 1,
  "kind": "dataset",
  "provider": "huawei",
  "exam": "h12-811",
  "exam_name": "H12-811",
  "search": "",
  "source": "live",
  "status": "complete",
  "collected_at": "2026-10-12T08:30:00Z",
  "tool": { "name": "examtopics-downloader", "revision": "d58f129", "modified": false },
  "counts": {
    "listing_pages": 47, "failed_listing_pages": 0,
    "question_links": 192, "failed_questions": 0,
    "cache_files": 0, "failed_cache_files": 0,
    "collected": 192
  },
  "errors": [],
  "questions": [ { "…": "see below" } ]
}
```

| Field | Meaning |
|---|---|
| `exam` | Exact exam code requested with `-exam`. Empty when the legacy substring `-search` was used (the web app never does). |
| `source` | `live` (scraped from ExamTopics) or `github-cache` (third-party dataset). |
| `status` | `complete`: every expected item was collected. `partial`: some pages or files failed; `errors` explains why. |
| `counts.collected` | Always equals `len(questions)`. |
| `errors` | Sanitised failure summaries, at most 50. They never contain credentials. |

### Question record

```json
{
  "number": 1,
  "id": "54119",
  "topic": 1,
  "question_number": 1,
  "text": "You support a Node.js application…",
  "choices": [ { "letter": "A", "text": "Instrument all applications…" } ],
  "answer": "B",
  "answer_source": "suggested",
  "suggested_answer": "B",
  "answer_et": "",
  "community_votes": [ { "answer": "B", "count": 31, "most_voted": true } ],
  "explanation": "",
  "question_images": ["https://www.examtopics.com/assets/media/exam-media/…png"],
  "answer_images": [],
  "comments": [ { "author": "user1", "timestamp": "…", "upvotes": 12, "content": "Line one\nLine two",
                  "selected_answer": "AD", "id": "1802619", "parent_id": "" } ],
  "comments_text": "",
  "source_url": "https://www.examtopics.com/discussions/google/view/54119-exam-…/",
  "timestamp": "June 1, 2021, 8:11 p.m."
}
```

| Field | Notes |
|---|---|
| `number` | 1…n in dataset order (topic, then question number; for cached files, file then in-file order). |
| `id` | Discussion ID (live) or source question ID (cache). Unique within a dataset. |
| `answer` / `answer_source` | Best available answer: `suggested` (ExamTopics' suggested answer) if present, otherwise `community` (most-voted), otherwise empty. Multi-letter answers (`BE`) are never truncated. |
| `comments` | Structured comments, from the cached dataset and from live pages in the 2026 layout. `selected_answer` is the answer a voting comment chose; `id` and `parent_id` link a reply to its comment (both optional). |
| `comments_text` | Unstructured comment text with line breaks preserved, for pages in the older layout whose comments are not structured. Empty when `comments` is filled from the page. |
| `votes_from_comments` | Optional, `true` when `community_votes` was counted from the comments because the page showed no tally: each voting comment counts once for its selected answer, plus once per upvote, as ExamTopics describes its vote count. |
| `answer_et` | Cached-source field `answer_ET`, kept verbatim; meaning unverified. |
| Text fields | Plain text with `\n` line breaks. HTML fragments from the source are converted to text and their `<img>` sources moved into the image arrays. Consumers must still escape text when rendering. |

## 2. Inventory (`-inventory -out-json`)

```json
{
  "schema_version": 1, "kind": "inventory",
  "provider": "huawei", "generated_at": "…", "status": "complete",
  "listing_pages": 47, "failed_listing_pages": 0, "errors": [],
  "exams": [ { "code": "h12-811", "name": "", "question_count": 192 } ]
}
```

`question_count` is the number of **unique discussion IDs** whose link parses to exactly that code.
Built from listing pages only; no question pages are fetched.

## 3. Providers (`-providers -out-json`)

```json
{ "schema_version": 1, "kind": "providers", "generated_at": "…", "status": "complete",
  "providers": [ { "slug": "huawei", "name": "Huawei", "exam_count": 52 } ] }
```

`exam_count` is the number of exams ExamTopics lists for the provider on its exams index
("Huawei (52 exams)"); the name is stored without it. It counts exams, not discussions, and is
left out when the index shows no count.

## 3a. Images (`-images-from <file> -images-dir <dir>`)

Downloads the images a dataset refers to. `-images-from` takes a dataset document (its
`question_images` and `answer_images`) or a text file with one address per line (`#` starts a
comment; a path such as `/assets/media/x.png` is taken as an ExamTopics address). Each image
is saved in the `-images-dir` folder as `<sha256 of its content>.<png|jpg|gif|webp>`, and the
document below goes to `-out-json`, or to `<dir>/images.json` without it.

```json
{ "schema_version": 1, "kind": "images", "generated_at": "…", "status": "partial",
  "saved": 1, "failed": 1, "skipped": 1,
  "images": [
    { "url": "https://www.examtopics.com/assets/media/exam-media/04312/0001.png", "status": "saved",
      "file": "3f2a…9c.png", "sha256": "3f2a…9c", "content_type": "image/png", "bytes": 48213 },
    { "url": "https://www.examtopics.com/assets/media/exam-media/04312/0002.png", "status": "failed",
      "error": "…returned HTTP 404 (Not Found)" },
    { "url": "https://elsewhere.example/x.png", "status": "skipped", "error": "not an ExamTopics address" }
  ] }
```

- Only `https` addresses on `examtopics.com` and its subdomains are fetched, and redirects
  may not leave them. Requests are paced by `-rps` and retried like page requests (with the
  second pass after a pause).
- `failed` may succeed on a later run (rate limiting, a 404 that comes back); `skipped` will
  not (another host, not a PNG, JPEG, GIF or WebP image, or over 5 MB). SVG is never kept.
- `status` is `partial` when any image failed, and the exit code is then 2.

## 4. Progress events (`-events <path>`, JSON Lines)

One JSON object per line, flushed as it happens.

```json
{"ts":"2026-10-12T08:30:00.123Z","event":"phase","phase":"listing_pages","message":"Scanning 47 listing pages"}
{"ts":"…","event":"progress","phase":"listing_pages","done":12,"total":47}
{"ts":"…","event":"progress","phase":"question_pages","done":80,"total":192}
{"ts":"…","event":"warning","phase":"question_pages","message":"…"}
{"ts":"…","event":"completed","status":"partial","questions":190,"message":"…"}
{"ts":"…","event":"failed","message":"no questions matched exam \"h19-101\""}
```

- `total` is omitted when unknown. Consumers must show an indeterminate indicator.
- Phases emitted by the real implementation: `providers`, `cache_lookup`, `cache_files`,
  `listing_pages`, `question_pages`, `images`, `writing`.
- Exactly one terminal event (`completed` or `failed`) is written when the process exits normally.
  If the process is killed, there is none, and the web app relies on the exit status.

## 5. Exit codes

| Code | Meaning |
|---|---|
| 0 | Complete. Requested output written. |
| 1 | Failed. No usable output. |
| 2 | Partial. Output written with `"status": "partial"`. |
| 3 | Nothing matched (no questions for the exam/search). |
| 64 | Usage error (bad flags). |

## 6. Cache layout and versions (web application)

```
web/storage/cache/<provider>/<exam>/
    20261012T083000Z-3f9a1c.json            canonical dataset (never modified after publication)
    20261012T083000Z-3f9a1c.manifest.json   metadata, written after the dataset
```

- Version ID: `YYYYMMDDTHHMMSSZ-<6 hex>`. Sortable by time, collision-safe.
- Publication: the dataset is copied to `<version>.json.tmp`, fsynced, renamed; then the manifest
  the same way. A version exists only when its manifest exists.
- Provider and exam directory names are validated identifiers (`^[a-z0-9][a-z0-9-]{0,127}$`).
- `partial` datasets are published as versions with `status: partial`. They never replace the
  exam's latest **complete** version.

### Manifest

```json
{
  "schema_version": 1, "kind": "manifest",
  "provider": "huawei", "exam": "h12-811", "exam_name": "",
  "version": "20261012T083000Z-3f9a1c",
  "file": "20261012T083000Z-3f9a1c.json",
  "status": "complete", "source": "live",
  "collected_at": "…", "published_at": "…",
  "reported_count": 192, "collected_count": 192,
  "counts": { "…": "copied from dataset" },
  "options": { "source_mode": "auto", "rps": 1.0 },
  "tool": { "name": "examtopics-downloader", "revision": "…" },
  "checksum": { "algorithm": "sha256", "value": "…" },
  "size": 812345,
  "dataset_schema_version": 1,
  "job_id": 12,
  "warnings": ["Inventory reported 192 questions, collected 190"]
}
```

## 7. Generated documents

```
web/storage/documents/<provider>/<exam>/<version>/<variant>.<md|pdf>
```

They're tied to the source version forever. A refresh creates a new version and never relabels old documents.

PDFs embed the images saved on the server (section 8) and record how many in their options,
so a PDF built before more images were saved is offered for a rebuild. Markdown files keep
the original image addresses, since a standalone file cannot reach the server's copies.

## 8. Saved images (web application)

```
web/storage/media/<first two hex digits of the sha256>/<sha256>.<png|jpg|gif|webp>
```

After a collection publishes a version, the worker runs the tool's image mode for the
version's images that are not saved yet, checks each file again (name, size, checksum, image
type) and moves it here. The `media` table has one row per image address: `saved` (with the
content hash), `failed` (tried again by the next collection or "Download missing images"), or
`skipped` (never tried again). Identical images share one file. The reader loads saved copies
from `/media/<sha256>`, which requires signing in.
