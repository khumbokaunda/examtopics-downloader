# Exam Library (web application)

A private, single-user web app for the examtopics-downloader Go tool. It discovers providers
and their exams, runs collections in the background, keeps a versioned library of the results,
and turns them into Markdown and PDF study documents or an in-browser reader with a
practice mode.

- Install on a VPS: [../docs/INSTALL.md](../docs/INSTALL.md)
- Operate, back up, recover, upgrade: [../docs/OPERATIONS.md](../docs/OPERATIONS.md)
- How it works and why: [../docs/ARCHITECTURE.md](../docs/ARCHITECTURE.md)
- File formats shared with the Go tool: [../docs/DATA_MODEL.md](../docs/DATA_MODEL.md)

## Layout

| Path | Contents |
|---|---|
| `public/` | The only web-served directory: `index.php` and static assets |
| `app/Core` | Kernel, router, sessions, config, database, paths, logging |
| `app/Controllers` | One controller per area (collect, library, jobs, …) |
| `app/Services` | Jobs and worker, cache publication and validation, documents |
| `app/Repositories` | All SQL (prepared statements only) |
| `app/Views` | PHP templates |
| `bin/` | `migrate`, `create-user`, `worker`, `reconcile`, `rebuild-index`, `cleanup` |
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
