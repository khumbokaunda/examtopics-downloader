# ExamLib (web application)

ExamLib is two products in one installation, kept apart by what each account has:

- **Institutions.** A training provider, college or exam centre registers, is reviewed, and then
  runs its own workspace: staff and students, classes and programmes, versioned question banks
  with review, assessments (timed sittings, live invigilation, integrity records), results,
  academic records and transcripts, and signed credentials anyone can verify. Each institution's
  records are kept apart from every other's by the database.
- **The library.** Exams collected with the `examtopics-downloader` Go tool, kept in versions,
  read and practised in the browser (practice mode, spaced review, mock exams, readiness
  certificates) and turned into Markdown and PDF study documents. An account has the library or
  it does not; accounts that arrive through an institution start without it.

Everyone signs in to the same home page, which shows what their account has. It began as a
private, single-user front end for the Go tool; the `examtopics` name survives in the
configuration, the environment variables and the deploy files, which are left as they are so
existing installs and backups keep working.

- What it does, page by page: [../docs/WEB_SPEC.md](../docs/WEB_SPEC.md)
- How the institution side was built, and what is planned: [../docs/PLATFORM_PLAN.md](../docs/PLATFORM_PLAN.md)
- Install on a VPS: [../docs/INSTALL.md](../docs/INSTALL.md)
- Operate, back up, recover, upgrade: [../docs/OPERATIONS.md](../docs/OPERATIONS.md)
- How it works and why: [../docs/ARCHITECTURE.md](../docs/ARCHITECTURE.md)
- File formats shared with the Go tool: [../docs/DATA_MODEL.md](../docs/DATA_MODEL.md)

## Layout

| Path | Contents |
|---|---|
| `public/` | The only web-served directory: `index.php` and static assets |
| `public/assets/css` | `tokens.css` (the theme), `base.css`, `layout.css`, `components.css`, `fonts.css` |
| `public/assets/js` | `theme-init.js` (in `<head>`, before first paint) and `app.js` |
| `public/assets/vendor` | Bootstrap CSS, Bootstrap Icons, Poppins and JetBrains Mono, each with its licence |
| `app/Core` | Kernel, router, sessions, config, database, paths, logging |
| `app/Controllers` | One controller per area (library, collect, institutions, assessments, sittings, …) |
| `app/Services` | The rules: access and entitlements, institutions, question banks, assessments, results, credentials, jobs, documents |
| `app/Repositories` | All SQL (prepared statements only) |
| `app/Views` | PHP templates |
| `bin/` | `migrate`, `create-user`, `worker`, `reconcile`, `rebuild-index`, `cleanup`, `send-mail`, `backup` |
| `config/` | `config.php` (defaults, committed) and `config.local.php` (yours, ignored) |
| `database/migrations` | Ordered SQL migrations |
| `deploy/` | nginx, Apache, systemd, logrotate and cron examples |
| `storage/` | Private data: cache, documents, job files, logs (ignored by Git) |
| `tests/` | PHPUnit: unit, integration (MySQL) and end-to-end (real HTTP + worker + tool) |

## Local development

```bash
go build -o ../bin/examtopics-downloader ../cmd
composer install
cp config/config.local.php.example config/config.local.php   # set db, 'session' => ['secure' => false]
php bin/migrate.php && php bin/create-user.php dev
php -S 127.0.0.1:8080 -t public public/index.php
```
