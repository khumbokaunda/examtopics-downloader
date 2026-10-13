# Examtopics Architecture

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
   and `inventory_refreshes` (status, errors). A complete refresh also remembers each exam's
   discussion links (`storage/inventory/<provider>/`), so a later collection of that exam reads
   only the newest listing pages (`-links-from`, `-newer-than`) instead of all of them.
3. **Exam library**: canonical JSON datasets in `storage/cache/<provider>/<exam>/`, one
   immutable file per version plus a manifest. MySQL indexes them (`cache_versions`) and can be
   rebuilt from the manifests.
4. **Document generation**: Markdown and PDF built from one validated version, recorded in
   `documents` with options, size and checksum, and reused when unchanged. PDFs are built by
   a detached process that records its progress, so the page shows it and no request waits.
5. **Saved images**: after a collection, the tool downloads the questions' images (only from
   ExamTopics, paced like page requests). The worker checks each file again and keeps it once
   in `storage/media`, named by its content hash (`media` table). The reader serves them from
   `/media/<hash>` and PDFs embed them, so neither depends on ExamTopics serving images to a
   browser, which it often refuses.
6. **Web interface**: server-rendered PHP templates in an application shell (sidebar, top bar,
   command palette), styled by a token-based theme over Bootstrap 5's CSS. See
   [The interface](#the-interface).
7. **Answer corrections**: the reader flags answers that look doubtful (the wording asks for
   several answers but one letter was collected, few votes, ExamTopics and the community
   disagree, or the comments mostly name another answer) and lets you correct or confirm them,
   with a note. Corrections are stored per exam question in `answer_edits` (keyed by topic and
   question number), so they apply to every version and survive refreshes. They are shown over
   the collected data, which is never changed; documents record them in their options, so one
   made before a correction is offered for rebuilding.

## The interface

- **Tokens first.** Every colour, size, radius, shadow and timing is a CSS custom property in
  `public/assets/css/tokens.css`. A theme, accent, density or motion change is an attribute on
  `<html>` (`data-theme`, `data-accent`, `data-density`, `data-surface`, `data-motion`), never a
  second stylesheet. Bootstrap's own variables are pointed at the tokens in `base.css`, so its
  components follow both themes; Bootstrap's dark mode and its JavaScript are not used.
- **Load order**: `fonts.css`, `tokens.css`, Bootstrap, Bootstrap Icons, `base.css`
  (Bootstrap mapping and base components), `layout.css` (the shell and breakpoints),
  `components.css` (chips, KPI tiles, empty states, palette, dialogs, the question reader).
- **Preferences** (theme, accent, density, surface, motion) are stored per user in
  `users.preferences` and rendered into the `<html>` attributes by the server.
  `js/theme-init.js` runs in `<head>` before first paint to resolve a "match my device" theme
  and keeps a local copy so the sign-in page matches. It is an external file because the CSP
  allows no inline script. The sidebar's collapsed state is kept in the browser only.
- **Self-hosted**: Poppins and JetBrains Mono (SIL Open Font License, latin and latin-ext
  subsets), Bootstrap Icons (MIT) and Bootstrap's CSS are served from `public/assets/vendor`.
  A unit test fails if a template or stylesheet names another host or a referenced asset is
  missing. Asset URLs carry the file's modification time, so a deploy is never served stale.
- **Data tables**: every list (providers, a provider's exams, the collect picker, the
  library, jobs, sign-in attempts) is a `table[data-table]` enhanced by `js/data-table.js`:
  search, sorting (Shift+click adds a second column), page sizes, a pager, a status line,
  column visibility, CSV export and copy. Each table's state is saved in the browser per page
  and table, and restored on the next visit. Without JavaScript the table is a complete list.
  Server-side filters (provider, status, job type) stay as links and selects; the jobs page
  loads the latest 1,000 jobs and says so when there are more.
- **Behaviour** lives in `js/app.js`: sidebar collapse and the phone drawer, the user menu,
  the Ctrl K command palette (pages, plus cached exams and providers from `/api/palette`), the
  confirmation dialog that replaces `window.confirm`, live job progress and practice-mode
  reveals. Every dynamic value is inserted with DOM APIs, never `innerHTML`.
- **PDFs** are laid out as study guides in the app's own type and colours: Poppins and
  JetBrains Mono (TrueType copies in `web/resources/fonts`, embedded as subsets), the teal
  accent with a deep teal-navy for titles and answers, and amber only for warnings.
  - Page 1 has the title, an "About this guide" box and a contents list whose rows link to
    their sections. Its page numbers and dotted leaders are drawn after layout, from where
    each section actually landed.
  - Each section (a topic, or 50 questions when there is one topic) starts on a new page and
    is a PDF bookmark (`Pdf/OutlineCpdf` adds the outline entries dompdf's writer lacks).
  - Question cards are kept on one page and flag a missing figure or an answer the community
    vote disagrees with. Appendices list the answer key (four per row) and every question's
    source.
  - dompdf uses one font per run of text, so characters Poppins cannot draw are wrapped for
    DejaVu Sans (`Pdf/FontCoverage` reads Poppins' character map).

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
- **Sessions** end after a period without activity (4 hours by default) and, if set, a fixed
  time after signing in; both are chosen under Settings > Time and sign-in. Session files are
  kept in `storage/sessions`, so the system's own session cleanup (on Debian, every 24 idle
  minutes) cannot sign anyone out early.
- **Identifiers in URLs**: jobs and documents are addressed by a random UUID, not their row
  number; exams, providers and versions by their validated codes.
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
  is fully escaped; dompdf runs with remote access and PHP disabled. Images in the browser come
  from the saved copies, else from `https:` addresses. Saved images are PNG, JPEG, GIF or WebP
  only (never SVG), checked with `getimagesize`, and served with `nosniff` and a sandboxing CSP.
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
| dompdf instead of the tool's `-type pdf` | The existing path (mdtopdf with gofpdf core fonts) cannot render text outside Latin-1, has no images, and only converts its own legacy Markdown. dompdf embeds TrueType fonts (the app's Poppins, with DejaVu Sans for wider coverage) and supports print CSS, so every variant has one pipeline. |
| A detached worker plus `flock`, not a queue server | One collection at a time is a requirement, not a limitation. `flock` gives crash-safe mutual exclusion with no extra infrastructure. |
| A UNIQUE `active_slot` column | Duplicate-submission prevention is atomic in the database, not a racy check-then-insert. |
| Exact exam matching in the Go tool | Matching must happen where links are parsed. Filtering a substring search in the UI could not stop `h12-811` collecting `h12-811-v2-0`. |
| Comments always collected | Including or excluding discussions is a document option, so it never needs a new collection. |
| Images downloaded by the tool, not by PHP | The tool already paces requests, retries rate limiting and keeps to ExamTopics hosts, so images get the same care as pages. An image step shares the collection slot, so it never runs alongside a collection. |

## Known limitations

- ExamTopics' discussion pages (as of October 2026) carry no suggested answer: only a "Show
  Suggested Answer" link to the paid exam page. Live collections therefore use the most-voted
  community answer, counted from the comments' selected answers and upvotes, and label it so.
  The public cached dataset still has ExamTopics' answers for the exams it covers.
- Explanations and answer images are only available from the cached dataset path; discussion
  pages have neither.
- Parsing was verified against a fixture site and a page saved by the user in October 2026
  (`internal/fetch/testdata/discussion-2026.html` keeps its markup with invented text), not
  against the live site, which the development environment cannot reach. Pages in the older
  layout (choices as list items, a vote tally, comments as text) are still parsed.
- The collection tool reads ExamTopics' suggested answer and the votes; it does not understand
  what the comments argue. On a multi-answer question ExamTopics may let each voter pick only
  one letter, so the vote can be incomplete. The reader's "Check this answer" hints point at
  such cases (they are heuristics, not verdicts), and corrections are made by hand.
- One collection runs at a time by design, so a large provider inventory or exam can occupy
  the worker for a long time.
