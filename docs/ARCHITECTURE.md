# Exam Library Architecture

A private web application (`web/`) that drives the existing Go tool to build a personal,
versioned library of exam questions and to produce documents from it. The current state of
the Go tool before this work is recorded in [AUDIT.md](AUDIT.md); the file formats exchanged
between the components are specified in [DATA_MODEL.md](DATA_MODEL.md).

## Components

```
 Browser ──HTTPS──> web/public/index.php ── Kernel (auth, CSRF, headers) ── Controllers
                                                     │                          │
                         MySQL <── Repositories <────┘           Services ──────┘
                         (metadata only)                          │      │
                                                                  │      └─ DocumentStore ─> Markdown / dompdf
                                         JobService ── jobs table │                 (reads cached JSON only)
                                              │                   │
                                  setsid -f php bin/worker.php ───┘  (one at a time, flock)
                                              │
                                  bin/examtopics-downloader -batch -events … -out-json …
                                              │
                                     ExamTopics (or the public cached dataset)
```

1. **Collection engine**: the Go tool. It is the only component that talks to ExamTopics. The
   web app calls it with validated arguments and reads its JSON output and JSON Lines
   progress events. The CLI remains fully usable on its own.
2. **Provider and exam inventory**: `-inventory` counts unique discussions per exact exam
   code from listing pages only. Results are stored in `exams` (reported count, refresh time)
   and `inventory_refreshes` (status, errors).
3. **Exam library**: canonical JSON datasets in `storage/cache/<provider>/<exam>/`, one
   immutable file per version plus a manifest. MySQL indexes them (`cache_versions`) and can be
   rebuilt from the manifests.
4. **Document generation**: Markdown and PDF built from one validated version, recorded in
   `documents` with options, size and checksum, and reused when unchanged.
5. **Web interface**: server-rendered PHP templates with Bootstrap 5 and one small JavaScript
   file.

## Collection lifecycle

1. The browser POSTs to `/collect`. The controller validates the provider and exam against the
   inventory. If the exam is already cached, it refuses to start unless the user explicitly
   confirmed a refresh.
2. `JobService` inserts a `queued` job holding the `collect` slot. `jobs.active_slot` is a
   UNIQUE column, so a second collection fails atomically, even under concurrent requests.
3. `WorkerSupervisor::ensure()` starts a worker if none holds the lock:
   `setsid -f php bin/worker.php --drain`. The worker is detached from the web server: closing
   the browser, losing connectivity or restarting php-fpm does not affect it. Child processes
   never inherit the parent's descriptors (sockets, lock file).
4. The worker claims the job and runs the tool from an argument array (no shell). The GitHub
   token is passed in the child's environment, never on the command line. The tool's progress
   events are mirrored into the job row; the browser polls `/api/jobs/<id>` every two seconds.
5. On exit, the worker validates the dataset (`DatasetValidator`). A valid dataset is
   published as a new version: dataset, then manifest, then index row. A complete version
   becomes the exam's latest; a partial one is stored and flagged but never replaces a
   complete one. Invalid output is rejected and kept for inspection; nothing changes.

## Recovery model

- Holding the worker `flock()` proves that no other process is running a job, because only
  lock holders run jobs. The kernel releases the lock when a process dies. So whoever next
  takes the lock (a new worker, or any web request polling status) knows that jobs still
  marked `running` were interrupted. It marks them failed and stops their orphaned tool
  processes. There are no timeouts or guesses.
- A failed, cancelled or interrupted refresh never touches published versions.
- Cancellation and the runtime limit use SIGTERM, then SIGKILL after a grace period.

## Security model

- **Authentication** on every route except the login page, enforced centrally in the
  kernel; the API answers 401. Passwords use `password_hash`. Logins are throttled per IP,
  respond the same for unknown users, regenerate the session ID, and only redirect to
  same-site paths.
- **CSRF** tokens on every POST, including login.
- **Headers**: CSP `default-src 'self'` with no inline scripts or styles, frame denial,
  `no-referrer`, `Cache-Control: no-store`, HSTS on HTTPS.
- **Process execution**: fixed executable paths from configuration, argument arrays, values
  validated against identifier rules and the inventory; no request data reaches a command line
  unvalidated, and no shell interprets anything.
- **Filesystem**: only `web/public` is served. Paths are built from validated identifiers and
  checked to stay inside their root (`Paths::resolveInside` rejects `..`, absolute paths and
  symlink escapes). Downloads come from database records, are re-resolved inside the root and
  must match their checksum.
- **Output**: all scraped text is untrusted. Templates escape everything; the practice-mode
  reveal inserts text with `textContent`; Markdown escapes structural characters; the PDF HTML
  is fully escaped; dompdf runs with remote access and PHP disabled. Images in the browser are
  limited to `https:` URLs.
- **Practice mode**: answers, explanations, votes and comments are removed from the question
  data before the template sees it. Each is fetched on demand for one question from an
  authenticated endpoint.
- **Secrets** live in `config.local.php` or the environment and are redacted from every log
  line and error message.

## Decisions

| Decision | Reason |
|---|---|
| MySQL instead of SQLite | The VPS already runs MySQL; SQLite was not guaranteed to be installed. The schema avoids engine-specific features beyond InnoDB and works on MySQL 5.7+ and MariaDB 10.3+. |
| JSON files as the source of truth | Question content is large and immutable per version; files are easy to back up, checksum and rebuild the index from. The database never duplicates question content. |
| dompdf instead of the tool's `-type pdf` | The existing path (mdtopdf with gofpdf core fonts) cannot render text outside Latin-1, has no images, and only converts its own legacy Markdown. dompdf ships DejaVu Sans and supports print CSS, so every variant has one pipeline. |
| A detached worker plus `flock`, not a queue server | One collection at a time is a requirement, not a limitation. `flock` gives crash-safe mutual exclusion with no extra infrastructure. |
| A UNIQUE `active_slot` column | Duplicate-submission prevention is atomic in the database, not a racy check-then-insert. |
| Exact exam matching in the Go tool | Matching must happen where links are parsed. Filtering a substring search in the UI could not stop `h12-811` collecting `h12-811-v2-0`. |
| Comments always collected | Including or excluding discussions is a document option, so it never needs a new collection. |

## Known limitations

- The live scraper's per-comment structure (author, date, upvotes) is not parsed, because no
  sample of the comment markup was available; comments are kept as text with their line
  breaks. The public cached dataset provides structured comments. See AUDIT.md, uncertainty 1.
- Explanations and answer images are only available from the cached dataset path.
- New parsing was verified against a fixture site built from observed output, not against the
  live site (the development environment cannot reach it).
- One collection runs at a time by design, so a large provider inventory or exam can occupy
  the worker for a long time.
