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

## Starting several jobs

Collecting an exam and refreshing a provider's inventory keep you on the page. The button is
replaced by the job's status ("In the queue", "Running, 54%", then "Done" or "Failed"), which
opens the job when clicked. On the collect page every exam waiting or running is marked in the
list, so you can pick the next one and queue it straight away; jobs run one at a time in the
order they were queued.

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

## Two-step sign-in

Anyone can turn it on from their account page: scan the QR code with an authenticator app
(Google Authenticator, Microsoft Authenticator, Authy, a password manager), type the code it
shows, and save the ten recovery codes, which are shown once. Signing in then asks for the
code after the password. Recommended for every administrator.

- **Lost phone, codes at hand**: sign in with a recovery code (each works once), then turn it
  off and on again with the new phone, or make new codes.
- **Lost phone and codes**: an administrator opens the person's page on Users and uses **Turn
  off two-step sign-in**. The person is told by email.
- **The only administrator is locked out**: `php bin/unlock.php two-step <username>`.

Wrong codes: five per sign-in, then the password again; ten per account in fifteen minutes,
then none for a while. Reaching ten means someone typed the right password, so the owner is
told to change it and the reviewers are told too.

**Confirm it is you.** Making, downloading, uploading, restoring and deleting backups, deleting
an account and turning off someone else's two-step sign-in first ask for the code from the
app (or the password, when two-step sign-in is off). One confirmation lasts 10 minutes
(`security.confirm_minutes`).

## Sign-in protection

An account locks for 15 minutes after 5 wrong passwords in a row; its owner gets an email, and
everyone with `security.manage` an inbox notice. A network that fails 20 times in 15 minutes is
refused for 30 minutes. The limits are in `config/config.php` under `security` (override them
in `config.local.php`).

The **Sign-in attempts** page (System menu) shows, for the last day, week or month:

- **Did any attempt get in?** Sign-ins from a network that had just typed 3 or more wrong
  passwords for that account, or tried 5 or more names that do not exist, or straight after
  the account was locked. "It was them" clears one (it stays listed as cleared, and can be
  flagged again); "Clear all" clears every one.
- **Accounts tried**, with how close each is to locking, and **Unlock**.
- **Names that do not exist** (a name that may be a password is shown as its length).
- **Networks**, with **Unblock**. Unblocking also forgives that address's earlier failures.

Locked accounts also show on the Users page, with **Unlock now** on the account. Everyone sees
their own recent sign-ins and failed attempts on their account page.

From the command line, for when the only administrator is locked out:

```bash
php bin/unlock.php list                  # locked accounts and blocked networks
php bin/unlock.php account <username>
php bin/unlock.php network <address>
php bin/unlock.php two-step <username>   # turn off two-step sign-in
```

Sign-in attempts older than 90 days are removed by `bin/cleanup.php`; the activity history keeps
its own record.

## Activity history

The **Activity** page (administrators, or anyone given `activity.view`) lists who did what
and when, newest first, with filters for dates, person and kind, and **Download as CSV** for
every matching entry. The dashboard shows the newest entries and counts failed sign-ins of
the last day on one line.

**Check the history** walks every entry and says whether any was changed or removed after
it was written. The same from the command line, with an exit code for scripts:

```bash
php bin/verify-audit.php          # "Intact: N entries checked" and the last hash, or where it breaks
php bin/verify-audit.php --json
```

Keeping the last hash somewhere off the server lets you prove later that nothing before it
was rewritten. A backup carries the history; restoring one brings back the backup's history
and adds the restore to it. The rows are small and are kept indefinitely.

## Backups

The **Backups** page (administrators, `backup.manage`) makes one zip file holding everything:
every table (accounts, settings, providers, exams, versions, requests, notifications,
corrections), `storage/cache` (the exam data, **the one thing you cannot regenerate without
scraping again**), the known question links, the logo, the pictures added by hand in the
reader (`storage/uploads`, always included) and, if ticked, the saved images
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

## The library

The library opens on shelves: a card per exam with its editions together, Read and Practice
on the card, and a warning only when something needs attention (a partial version, a refresh
running). The Table button switches to one row per exam with every column; the choice is
remembered per person. In the table, an exam collected more than `library.stale_days` (90 by
default, in `config/config.php`) ago is marked "older than 90 days" for those who collect.

Editions are grouped by name. When two different exams end up on one card, or two editions of
one exam stay apart, open the exam (its Documents link) and use the Editions card, which needs
`library.manage`:

- **Show separately** gives the exam a card of its own.
- **Group with another exam** puts it on one card with an exam you pick.
- **Group by name again** undoes either of those.
- **Show this edition first** chooses which edition the card leads with; by default it is the
  one whose code sorts highest (SAA-C03 before SAA-C02).

## Checking and correcting

The **Integrity** page (in the Collect section of the menu, for anyone who may correct answers)
lists every exam that needs checking, with the problems counted at the top:

- **Answers to check** opens the reader on just the doubtful answers of that exam.
- **Questions missing** means ExamTopics lists more questions than were collected; Collect
  again fetches them.
- **Images not saved** can be downloaded from the page; the job shows in place of the button.
- **Documents to rebuild** were made before the latest corrections or images; the exam page
  rebuilds them.
- **Partly collected** and **Data unreadable** (the data file is missing or damaged) appear as
  filters only while some exam has them.

The figures are kept per exam and worked out again when the exam is collected again or
corrected. On a large library the first visit may list some exams as not checked yet; open the
page again to check more (raise `integrity.budget_seconds` in `config/config.local.php` to do
more at once).

In the reader (study mode), **Edit this question** fixes the wording and each choice, removes
pictures and adds new ones (PNG, JPEG, GIF or WebP, up to `media.max_bytes`, 5 MB by default;
PHP's `upload_max_filesize` must allow it too). A line that says `[picture]` marks where a
picture sits. Edits show in every version of the exam, survive collecting it again, appear in
documents made after them, and are listed on the exam's Corrections page, where each can be
put back as collected.

## Study progress

Every answer someone checks in practice mode (picked, then revealed) is graded on the server
and counted: **My progress** in the sidebar lists each exam they read or practised, how many
questions they answered, how many were right, and how many to go over again. **My mistakes**
in practice mode shows only the questions whose latest answer was wrong; answering one right
takes it off. The library shows **Continue studying** (the last page opened in each exam) and
**Saved** (the bookmark on a card) above the provider shelves.

The **dashboard** is each person's own study page: where to continue, their streak (days in
a row on which they read a page or checked an answer, counted in their own timezone), this
week's answers and how many were right, a 14-day chart, mistakes to go over, saved exams,
what is new in the library grouped by provider and day, and their exam requests. Those who
collect, see the activity history or see everyone's progress get a "Running the library"
section below it: totals, jobs, documents, who studied this week and recent activity, with
sign-ins counted on one line instead of listed.

Administrators see everyone's progress under My progress > **Everyone**, and can give a member
the same view with the `progress.view_all` permission (Users > the account > extra
permissions). Members are told on their progress page and on each exam page that
administrators can see it. Only the person can clear their own progress in an exam (the exam
page's Your progress card); that is recorded in the activity history. Deleting an account
deletes its progress. Backups include it.

## Retention

- **Versions** are kept until you delete them (Library > exam > Version history). The latest
  complete version cannot be deleted, and nothing is deleted while a collection for that exam
  is active or a document is being generated from the version.
- **Generated documents** can be deleted per document; the cached data is not affected.
- **Job working files**: `php bin/cleanup.php --days=30` (scheduled by `deploy/examtopics.cron`).
  Job history rows are kept.
- **Saved images** stay when a version is deleted, because other versions usually share them.
  `php bin/cleanup.php --media` deletes the ones no cached version or question edit refers to
  any more (add `--dry-run` to see what it would remove). Pictures added by hand are never
  deleted by it.

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
