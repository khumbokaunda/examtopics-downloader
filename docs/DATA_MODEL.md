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
  "comments": [ { "author": "user1", "timestamp": "…", "upvotes": 12, "content": "Line one\nLine two" } ],
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
| `comments` | Structured comments. Populated from the cached dataset, which provides them structured. |
| `comments_text` | Unstructured comment text with line breaks preserved. Used by the live scraper until the ExamTopics comment markup is confirmed (see AUDIT.md, uncertainty 1). |
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
  "providers": [ { "slug": "huawei", "name": "Huawei" } ] }
```

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
  `listing_pages`, `question_pages`, `writing`.
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
