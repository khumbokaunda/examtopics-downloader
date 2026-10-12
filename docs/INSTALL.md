# Installing the Exam Library on a VPS

This guide installs the private web application (`web/`) next to the Go collection tool on
an existing Linux server. It does not replace your web server configuration; it adds one
site. Examples assume the checkout lives in `/srv/examtopics-downloader` and the web server
runs as `www-data` (Debian/Ubuntu). Adjust both to your system.

## 1. Requirements

| Component | Version | Notes |
|---|---|---|
| PHP (web and CLI) | 8.1 or newer | Extensions: `pdo_mysql`, `mbstring`, `dom`, `xml`. Recommended: `pcntl` and `posix` (CLI) for clean worker shutdown and orphan cleanup; `gd` if you enable images in PDFs. |
| MySQL or MariaDB | MySQL 5.7+ / MariaDB 10.3+ | One database for metadata. Question content stays in JSON files. |
| Go | 1.24+ | Only to build the collection tool. |
| Composer | 2.x | Installs dompdf. |
| Web server | nginx + php-fpm, or Apache | With HTTPS. |
| `setsid`, `bash` | util-linux, any | Present on all mainstream distributions. Used to start the background worker detached from the web server. |

PHP must be allowed to run `proc_open`. If your `php.ini` (or the php-fpm pool) lists it in
`disable_functions`, or sets an `open_basedir` that excludes `/usr/bin`, `/proc` and the
checkout, background jobs cannot start.

## 2. Get the code and build the tool

```bash
cd /srv
git clone https://github.com/<you>/examtopics-downloader.git
cd examtopics-downloader
go build -o bin/examtopics-downloader ./cmd
bin/examtopics-downloader -version
```

Build the package (`./cmd`), not the file (`./cmd/main.go`): only a package build records the
Git revision, which the library shows on every collected version.

## 3. Install the PHP dependencies

```bash
cd /srv/examtopics-downloader/web
composer install --no-dev --optimize-autoloader
```

## 4. Create the database

```sql
CREATE DATABASE examlib CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'examlib'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON examlib.* TO 'examlib'@'localhost';
```

## 5. Configure

```bash
cd /srv/examtopics-downloader/web
cp config/config.local.php.example config/config.local.php
```

Edit `config/config.local.php`:

- `db`: the DSN, user and password from step 4.
- `github_token` (optional): a GitHub personal access token **with no scopes**. It only
  raises the rate limit for the public cached dataset. It is passed to the tool through its
  environment, never on the command line.
- `php_cli`: the PHP CLI binary if it is not `/usr/bin/php` (for example `/usr/bin/php8.3`).
  Under php-fpm, PHP cannot discover this itself.

Every other setting and its default is documented in `config/config.php`. Do not edit
that file; override values in `config.local.php`.

Secrets can also come from the environment instead (`EXAMLIB_DB_DSN`, `EXAMLIB_DB_USER`,
`EXAMLIB_DB_PASSWORD`, `EXAMLIB_GITHUB_TOKEN`), for example via `env[...]` in the php-fpm pool.

## 6. Permissions

The web server user runs both the site and the background worker, so it must own the private
storage directory and be able to read the configuration:

```bash
cd /srv/examtopics-downloader/web
sudo chown -R www-data:www-data storage
sudo chmod -R u=rwX,g=rX,o= storage
sudo chown root:www-data config/config.local.php
sudo chmod 640 config/config.local.php
```

Run every maintenance command as that user (`sudo -u www-data php bin/...`). Running them as
root would create root-owned files the web server cannot write.

## 7. Create the schema and your account

```bash
cd /srv/examtopics-downloader/web
sudo -u www-data php bin/migrate.php
sudo -u www-data php bin/create-user.php yourname     # prompts for a password (12+ characters)
```

`bin/create-user.php` also resets the password of an existing user.

## 8. Web server

Point the site's document root at **`web/public`**. Nothing else in the repository may be
web-accessible: configuration, cached data, logs and documents all live outside it.

- nginx: `web/deploy/nginx.conf.example`
- Apache: `web/deploy/apache.conf.example`

Both examples redirect HTTP to HTTPS, execute only `index.php`, and raise the FastCGI timeout to
300 seconds because building a PDF of a large exam can take a while. Get a certificate with
your usual tool (for example `certbot --nginx -d exams.example.com`).

Session cookies are marked `Secure`, so the site must be reached over HTTPS. The only
exception is the SSH-tunnel setup below.

## 9. Restrict who can reach it

The login is mandatory and every page, API call and download requires it. Because the server
is public, add a network-level layer as well. Any one of these works:

- **IP allowlist** in the web server (`allow`/`deny` in nginx, `Require ip` in Apache).
- **VPN** (WireGuard, Tailscale): bind the site to the VPN interface only.
- **SSH tunnel only**: serve the site over plain HTTP bound to the loopback address
  (`listen 127.0.0.1:8080;` in nginx, no public server block), set
  `'session' => ['secure' => false]` in `config.local.php`, then connect with
  `ssh -L 8080:127.0.0.1:8080 you@vps` and open `http://localhost:8080`. Traffic is
  encrypted by SSH and the site is unreachable from the internet. Use this only when the site
  is bound to loopback.

An obscure URL is not protection. If you put the app behind a reverse proxy you control, set
`'security' => ['trust_forwarded_for' => true]` so login throttling sees real client IPs.
Never enable it otherwise.

## 10. Scheduled maintenance

```bash
sudo crontab -u www-data /srv/examtopics-downloader/web/deploy/examlib.cron
sudo cp /srv/examtopics-downloader/web/deploy/examlib.logrotate /etc/logrotate.d/examlib
```

The cron file reconciles stale jobs every five minutes and removes working files of jobs
older than 30 days. Edit the paths inside both files first.

## 11. Optional: a permanent worker under systemd

By default the web app starts a worker on demand and it exits when the queue is empty. If
you prefer a supervised, always-on worker:

1. In `config.local.php`: `'worker' => ['autostart' => false]`.
2. Install `web/deploy/examlib-worker.service` into `/etc/systemd/system/`, adjust paths,
   then `systemctl enable --now examlib-worker`.

## 12. First use

1. Sign in.
2. **Providers & Inventory**: either *Discover providers*, or add one by its identifier
   (for example `huawei`). Adding queues an inventory refresh, which lists every exam that
   has discussions, with question counts.
3. **Collect Exam**: pick the provider and exam, then *Start collection*. You can close the
   browser; the job continues on the server.
4. **Exam Library**: read the exam, practice with hidden answers, and generate Markdown or PDF
   documents.

See [OPERATIONS.md](OPERATIONS.md) for backups, upgrades, logs and recovery.
