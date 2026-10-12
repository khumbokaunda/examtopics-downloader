# Operating Examtopics

Day-to-day running, recovery, backups and upgrades. Installation is in [INSTALL.md](INSTALL.md),
design in [ARCHITECTURE.md](ARCHITECTURE.md).

All commands run from `web/` as the web server user, for example
`sudo -u www-data php bin/reconcile.php`.

## What runs where

- **Web requests** render pages and queue jobs. They never run a collection themselves.
- **One background worker** runs queued jobs one at a time: provider discovery, inventory
  refreshes and collections. Only one collection may be queued or running at once, and only one
  refresh per provider. The database enforces this (`jobs.active_slot`).
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
| Logins, throttling, CSRF rejections, password changes | `storage/logs/security.log` |
| Worker process output | `storage/logs/worker.log` |
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

Generation failures never affect the cached data or other documents. For very large exams,
raise `documents.pdf_time_limit` / `documents.pdf_memory_limit` in `config.local.php` and
the FastCGI timeout in the web server (`fastcgi_read_timeout` / `ProxyTimeout`). Markdown is
always fast.

### A document download says "missing or failed its integrity check"

The file changed or disappeared on disk. Use **Generate** again; it is rebuilt from the
cached data.

## Backups

| Back up | Why |
|---|---|
| `web/storage/cache/` | The canonical exam data (datasets and manifests). **The one thing you cannot regenerate without scraping again.** |
| `web/config/config.local.php` | Your configuration and secrets |
| The MySQL database (`mysqldump examtopics`) | Users, job history, inventories, document records |

`storage/documents/` can be skipped: documents are regenerated on demand. `storage/media/`
(saved question images) is worth keeping but can be downloaded again with "Download missing
images" on each exam page. `storage/jobs/` and `storage/logs/` are diagnostic only.

### Restoring after losing the database

```bash
php bin/migrate.php                    # recreate the schema
php bin/create-user.php yourname       # recreate your account
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
