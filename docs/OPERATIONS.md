# Operating Examtopics

Day-to-day running, recovery, backups and upgrades. Installation is in [INSTALL.md](INSTALL.md),
design in [ARCHITECTURE.md](ARCHITECTURE.md).

All commands run from `web/` as the web server user, for example
`sudo -u www-data php bin/reconcile.php`.

## What runs where

- **Web requests** render pages and queue jobs. They never run a collection themselves.
- **One background worker** runs queued jobs one at a time: provider discovery, inventory
  refreshes and collections. More can be queued while one runs; they start in turn, oldest
  first. The same exam cannot be queued twice (its collection and image download share a
  slot), nor two refreshes of one provider. The database enforces this (`jobs.active_slot`).
- The worker holds an exclusive `flock()` on `storage/jobs/worker.lock`. The kernel releases it
  when the process exits for any reason, so the lock cannot go stale.
- **Document generation** happens inside the web request and never contacts ExamTopics.

## Where to look

| What | Where |
|---|---|
| Running and past jobs | Jobs & History page; each job shows its phase, progress, sanitised error summary and the last 40 lines of tool output |
| Application errors (with the reference ID shown to the user) | `storage/logs/app.log` |
| Job lifecycle, publication, reconciliation | `storage/logs/jobs.log` |
| Inventory refreshes | `storage/logs/inventory.log` |
| Document generation (timings, failures) | `storage/logs/documents.log` |
| Logins, throttling, CSRF rejections, refused pages, password, account and branding changes | `storage/logs/security.log` |
| Worker process output | `storage/logs/worker.log` |
| Emails sent, skipped and failed (with the SMTP error) | `storage/logs/mail.log`; Settings > Email lists recent notification emails |
| One job's raw files | `storage/jobs/<id>/` (`events.jsonl`, `tool.log`, and `output.json` if it was rejected) |

Logs are JSON Lines and are redacted: tokens, authorization headers, cookies and the
database password never appear in them. Rotate them with `deploy/examtopics.logrotate`.

## Recovery procedures

### A job looks stuck

1. Open the job. If *Last update* keeps moving, it is working; large providers take time at
   the default pacing of one request per second.
2. If you want it to stop, use **Cancel job**. The tool gets SIGTERM, then SIGKILL after 10
   seconds. Cached data is unchanged.

### A job shows "running" but nothing is happening (stale job)

This happens when the worker died: a crash, `kill -9`, or a reboot. Any status poll repairs
it automatically. To force it:

- **Settings > Reconcile stale jobs**, or
- `php bin/reconcile.php`

Reconciliation runs only when no worker holds the lock. Jobs still marked running are then
marked **failed (Interrupted)**, any orphaned tool process is stopped (after checking its
`/proc/<pid>/cmdline` really is the tool), and a worker is started for queued jobs.
Interrupted jobs are never retried automatically; start them again yourself.

### Checking the worker lock

`storage/jobs/worker.lock` contains the PID of the last holder. To see whether a worker is
alive: `fuser storage/jobs/worker.lock` or the Settings page. Never delete the lock file
while a worker runs. It is harmless when no worker runs, but there is never a reason to
remove it.

### Jobs queue but never start

Look at `storage/logs/app.log` and `worker.log`. Usual causes:

- `php_cli` points to a missing binary (php-fpm cannot discover the CLI path).
- `proc_open` is in `disable_functions`, or `open_basedir` excludes `/usr/bin` or `/proc`.
- `storage/` is not writable by the web server user.
- `worker.autostart` is `false` but the systemd worker is not running.

### A collection fails

The job page shows the tool's own failure message and its exit code:

| Exit | Meaning | What to do |
|---|---|---|
| 1 | Failed (e.g. the listing could not be read) | Check the tool output; often a network or HTTP 5xx problem |
| 2 | Partial: some pages were refused | Saved as a *partial* version; the complete version is untouched. Retry later with slower pacing |
| 3 | Nothing matched | The exam has no public discussions (check the provider inventory) |
| 64 | Usage error | A bug; report it with the job's tool output |
| 137/143 | Killed or terminated | Cancelled, timed out (`tool.max_runtime`) or worker shutdown |

"Output failed validation" means the tool produced data the library refused to publish. The
rejected file stays in `storage/jobs/<id>/output.json` for inspection. Nothing is published
and the previous version stays current.

Repeated HTTP 429 means ExamTopics is rate-limiting you. Each page is tried six times with
increasing waits; pages that still fail with a temporary error (429, 502, 503, 504 or a
dropped connection) get one more try after a 60-second pause, after every other page is done.
Only pages that fail that second pass are reported. If you still see them, use the 0.5 or
0.25 requests/second pacing on the Collect page, or refresh again later. Hover or open
**Details** under an error in the providers table to see every failed page.

### PDF generation fails or times out

Generation failures never affect the cached data or other documents. PDFs are built by a
detached process (`bin/build-document.php`, output in `storage/logs/documents-build.log`), so
no web request waits on them and a proxy such as Cloudflare cannot time them out; the exam
page shows how many questions are laid out. A build whose process died (a restart, or a
time or memory limit) shows as failed and can be started again. For very large exams, raise
`documents.pdf_time_limit` (seconds, default 1800) or `documents.pdf_memory_limit` in
`config.local.php`. Markdown is always fast and is made in the request.

Large PDFs need real memory, not just a high PHP limit: in one file, the 1,395-question CCNA
(200-301) study guide with about 870 pictures peaks at roughly 1.2 GB of server memory (about
0.85 MB per question). So exams with more than `documents.pdf_part_questions` questions (500 by
default) get their PDFs in near-equal parts, made one after another in the same build: the
CCNA becomes three parts of 465 questions, and the build peaks at about 410 MB (and takes
about 40% less time). Each part is a
complete document with its own contents and appendices, downloaded on its own. When the server
runs short, the operating system stops the build without a word, and the exam page then says
how far it got and how much memory it was using. `free -m` shows the memory available, and
`sudo dmesg -T | grep -i "killed process"` shows whether the kernel stopped a build. Adding
swap, or building when the server is otherwise quiet, helps.

### A document download says "missing or failed its integrity check"

The file changed or disappeared on disk. Use **Generate** again; it is rebuilt from the
cached data.

## Accounts

- **Approving sign-ups**: with Users > Sign-up set to "Needs approval", new accounts wait
  after verifying their email; administrators are notified and use **Approve** on the Users
  page. The person gets an email when it is done.
- **Someone did not get their code**: check Settings > Email (send a test message) and
  `storage/logs/mail.log`. An administrator can also create the account, or set a new
  password, on the Users page; an address typed there counts as verified.
- **Locked out**: `php bin/create-user.php <name> --admin` re-enables the account, resets its
  password and makes it an administrator.

## Backups

The **Backups** page (administrators, `backup.manage`) makes one zip file holding everything:
every table (accounts, settings, providers, exams, versions, requests, notifications,
corrections), `storage/cache` (the exam data, **the one thing you cannot regenerate without
scraping again**), the known question links, the logo and, if ticked, the saved images
(`storage/media`) and built documents (`storage/documents`). Backups are kept in
`storage/backups`, outside the web root; they can be downloaded, uploaded, restored and
deleted there. They hold password hashes and the email password: keep copies private.

Not in a backup: `config/config.local.php` (copy it yourself: it has the database password),
sign-in throttling records, pending codes and emails, job working files and logs.

**Uploading** sends the file in pieces of up to 8 MB (halved whenever the web server refuses a
piece as too large), so no single request is large or long and PHP's upload limit does not
apply. The page shows the progress, speed and time left. The pieces collect in
`storage/backups/uploads`; if the connection drops or the page closes, choosing the same file
again carries on where it stopped. Unfinished uploads are listed on the page with a Discard
button, and `bin/cleanup.php` deletes those nobody has added to for a week. The finished file
is checked like any backup before it is listed.

**Restoring** from the page runs in the background (`bin/backup.php restore --track`), like
making a backup: the page shows its progress, and when it finishes it goes to the sign-in form.
It cannot be cancelled half way.

From the command line (for large archives and for cron):

```bash
php bin/backup.php create                     # everything
php bin/backup.php create --no-media --no-documents
php bin/backup.php list
php bin/backup.php restore /path/to/examtopics-backup-....zip   # asks you to type RESTORE
```

`deploy/examtopics.cron` has a commented nightly line (`create --scheduled --keep=7`). Copy
backups off the server as well.

**Restoring** checks the archive first (entry names, format, an active administrator in it,
and that it was not made by a newer version), then makes a database-only safety backup named
`...-before-restore.zip` and stops if that fails. The tables are replaced in one transaction,
so a failure changes nothing; files are then written over what is there (nothing else is
deleted). Jobs that were running in the backup are marked failed, and everyone is signed out,
since the accounts may differ: sign-ins from before the database was replaced stop working at
that moment, even if a request that was running at the time wrote its session back. The Backups page shows the result of the
last restore.

### Moving to a new server

1. On the old server: Backups > Make a backup (with images and documents), then download it,
   or run `php bin/backup.php create` and copy the file from `storage/backups`.
2. On the new server, install the app as in `docs/INSTALL.md`: the code, the Go tool,
   `composer install --no-dev`, the database and user, `config/config.local.php` (copy the
   old one and change what differs), the web server and the cron file.
3. Restore it, before anyone else signs in. Either create your account
   (`php bin/create-user.php`), sign in, upload the backup on the Backups page and restore it
   there, or copy it over and restore it from the command line:

   ```bash
   cd /srv/examtopics-downloader/web
   php bin/backup.php restore /tmp/examtopics-backup-....zip
   ```

   This creates the tables if needed, so `bin/migrate.php` is not required first.
4. Sign in with your usual account, check Settings > Email (send a test message) and point
   the domain at the new server.

### Restoring after losing the database without a backup

```bash
php bin/migrate.php                    # recreate the schema
php bin/create-user.php yourname       # recreate your account (an administrator)
php bin/rebuild-index.php              # re-index every cached version from its manifest
```

`rebuild-index` verifies each dataset against the checksum in its manifest and skips (and
reports) anything corrupted. Job history and inventories are not recoverable from files.
Refresh the inventories from the Providers page.

## Retention

- **Versions** are kept until you delete them (Library > exam > Version history). The latest
  complete version cannot be deleted, and nothing is deleted while a collection for that exam
  is active or a document is being generated from the version.
- **Generated documents** can be deleted per document; the cached data is not affected.
- **Job working files**: `php bin/cleanup.php --days=30` (scheduled by `deploy/examtopics.cron`).
  Job history rows are kept.
- **Saved images** stay when a version is deleted, because other versions usually share them.
  `php bin/cleanup.php --media` deletes the ones no cached version refers to any more (add
  `--dry-run` to see what it would remove).

## Upgrades

```bash
cd /srv/examtopics-downloader
git pull
go build -o bin/examtopics-downloader ./cmd
cd web
composer install --no-dev --optimize-autoloader
sudo -u www-data php bin/migrate.php
sudo systemctl restart examtopics-worker    # only if you use the systemd worker
```

On-demand workers pick up new code with the next job. Upgrade between jobs: a worker that is
mid-job keeps running the old code until it finishes.

## Running the test suites

```bash
# Go tool (no network needed; uses a local fixture site)
go test -short ./...

# Web application: needs a disposable MySQL/MariaDB database
mysql -e "CREATE DATABASE examtopics_test; CREATE USER 'examtopics'@'localhost' IDENTIFIED BY 'examtopics-test-pw';
          GRANT ALL ON examtopics_test.* TO 'examtopics'@'localhost';"
cd web && composer install && vendor/bin/phpunit
```

The PHP suite wipes `examtopics_test` freely. Point it elsewhere with `EXAMTOPICS_TEST_DSN`,
`EXAMTOPICS_TEST_USER` and `EXAMTOPICS_TEST_PASSWORD`. It never contacts ExamTopics: all collection
tests run the real tool against `tests/fixtureserver`.
