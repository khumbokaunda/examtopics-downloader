<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Backup;

/**
 * Settings > Backups: make, download, upload, restore and delete backups. Making and restoring
 * one run in the background (bin/backup.php), since images and PDFs can take minutes to pack
 * or unpack, and the page shows their progress. Restoring needs the administrator's password
 * again. Uploads arrive in pieces (BackupUploads), so no single request is large or slow; the
 * whole-file upload is kept for browsers without JavaScript.
 */
final class BackupController extends Controller
{
    public function index(): Response
    {
        $backup = $this->s->backup();
        return $this->render('settings/backups', [
            'title' => 'Backups',
            'nav' => 'backups',
            'backups' => $backup->list(),
            'status' => $backup->status(),
            'sizes' => $this->areaSizes(),
            'uploadLimit' => self::uploadLimit(),
            'pending' => $this->s->backupUploads()->pending((int) $this->auth->user()['id']),
            'zip' => class_exists(\ZipArchive::class),
            'dir' => $backup->dir(),
            'last' => $backup->lastResult(),
        ]);
    }

    public function create(): Response
    {
        $args = ['create', '--by=' . (string) $this->auth->user()['username']];
        if ($this->request->input('media') !== '1') {
            $args[] = '--no-media';
        }
        if ($this->request->input('documents') !== '1') {
            $args[] = '--no-documents';
        }
        $backup = $this->s->backup();
        if (($backup->status()['status'] ?? '') === 'running') {
            $this->flash('warning', 'A backup is already being made. Its progress is shown below.');
            return $this->redirect('/backups');
        }
        if ((bool) $this->app->config()->get('backup.background', true)) {
            // The page shows the progress from here on, until the background process finishes.
            $backup->markStarting();
            if (!$this->s->supervisor()->launchScript('backup.php', $args, 'backup.log')) {
                $backup->writeStatus(['status' => 'failed', 'phase' => 'failed', 'started_at' => time(), 'finished_at' => time(),
                    'message' => 'The backup could not be started. See storage/logs/app.log, or run php bin/backup.php create on the server.']);
            }
            return $this->redirect('/backups');
        }
        try {
            $b = $backup->create(['media' => !in_array('--no-media', $args, true), 'documents' => !in_array('--no-documents', $args, true)],
                (string) $this->auth->user()['username'], '', true);
            $this->flash('success', 'Backup ' . $b['name'] . ' made (' . fmt_bytes($b['size']) . ').');
        } catch (\Throwable $e) {
            $this->flash('danger', 'The backup failed: ' . $e->getMessage());
        }
        return $this->redirect('/backups');
    }

    public function cancel(): Response
    {
        if ($this->s->backup()->cancel()) {
            $this->app->logger()->info('security', 'Backup cancelled', ['username' => $this->auth->user()['username'] ?? null]);
            $this->flash('success', 'The backup was cancelled and its unfinished file deleted.');
        }
        return $this->redirect('/backups');
    }

    /** The progress of the backup being made, polled by the Backups page. */
    public function statusJson(): Response
    {
        return Response::json($this->s->backup()->status() ?? ['status' => 'none', 'final' => true]);
    }

    public function download(string $name): Response
    {
        $path = $this->s->backup()->path($name) ?? throw HttpException::notFound('Backup');
        $this->app->logger()->info('security', 'Backup downloaded', ['file' => $name, 'username' => $this->auth->user()['username'] ?? null]);
        return Response::download($path, $name, 'application/zip');
    }

    /** The whole file in one request: the form's fallback when JavaScript is off. */
    public function upload(): Response
    {
        $file = $this->request->file('archive');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK || !is_file($file['tmp_name'])) {
            $this->flash('danger', $file !== null && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'That file is larger than this server accepts in one piece (' . fmt_bytes(self::uploadLimit()) . '). With JavaScript on, the page sends it in pieces instead; or copy it to ' . $this->s->backup()->dir() . ' on the server.'
                : 'Choose a backup file to upload.');
            return $this->redirect('/backups');
        }
        $this->uploaded($this->s->backupUploads()->adopt($file['tmp_name']));
        return $this->redirect('/backups');
    }

    /** Start sending a file in pieces, or carry on with an unfinished upload of the same file. */
    public function uploadStart(): Response
    {
        return $this->json($this->s->backupUploads()->start((int) $this->auth->user()['id'], (string) $this->request->input('name', ''),
            (int) $this->request->input('size', '0'), (int) $this->request->input('modified', '0')));
    }

    /**
     * One piece of the file. The offset is in the address, and the CSRF token in a header, so
     * that a piece larger than PHP accepts is reported as too large (413) rather than as a
     * form without its token.
     */
    public function uploadChunk(string $id): Response
    {
        $file = $this->request->file('chunk');
        if ($file === null || in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return Response::json(['error' => 'That piece is larger than the server accepts.'], 413);
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_file($file['tmp_name'])) {
            return Response::json(['error' => 'The piece did not arrive complete.'], 400);
        }
        $offset = (string) $this->request->query('offset', '');
        if (!ctype_digit($offset)) {
            return Response::json(['error' => 'The piece has no offset.'], 400);
        }
        return $this->json($this->s->backupUploads()->append($id, (int) $this->auth->user()['id'], (int) $offset, $file['tmp_name']));
    }

    /** The last piece arrived: check the file and list it with the other backups. */
    public function uploadFinish(string $id): Response
    {
        @set_time_limit(300);
        $result = $this->s->backupUploads()->finish($id, (int) $this->auth->user()['id']);
        if (isset($result['name'])) {
            $this->uploaded($result);
        }
        return $this->json($result);
    }

    public function uploadDiscard(string $id): Response
    {
        $gone = $this->s->backupUploads()->discard($id, (int) $this->auth->user()['id']);
        if ($this->request->wantsJson()) {
            return Response::json(['discarded' => $gone]);
        }
        if ($gone) {
            $this->flash('success', 'The unfinished upload was deleted.');
        }
        return $this->redirect('/backups');
    }

    /** @param array{name?:string,warnings?:list<string>,error?:string} $result */
    private function uploaded(array $result): void
    {
        if (isset($result['error'])) {
            $this->flash('danger', $result['error']);
            return;
        }
        $this->app->logger()->info('security', 'Backup uploaded', ['file' => $result['name'], 'username' => $this->auth->user()['username'] ?? null]);
        $this->flash('success', 'Uploaded as ' . $result['name'] . '. It is listed below, ready to restore.'
            . (($result['warnings'] ?? []) !== [] ? ' Note: ' . implode(' ', $result['warnings']) : ''));
    }

    /** @param array<string,mixed> $result a BackupUploads result: "http" is the status to send */
    private function json(array $result): Response
    {
        $status = (int) ($result['http'] ?? 200);
        unset($result['http']);
        return Response::json($result, $status);
    }

    public function restore(string $name): Response
    {
        $backup = $this->s->backup();
        $path = $backup->path($name) ?? throw HttpException::notFound('Backup');
        $user = (array) $this->auth->user();
        if (!password_verify((string) $this->request->input('password', ''), (string) $user['password_hash'])) {
            $this->app->logger()->warning('security', 'Restore refused: wrong password', ['username' => $user['username']]);
            $this->flash('danger', 'Your password was not right, so nothing was restored.');
            return $this->redirect('/backups');
        }
        if (($backup->status()['status'] ?? '') === 'running') {
            $this->flash('warning', 'A backup or restore is already running. Its progress is shown below; try again when it has finished.');
            return $this->redirect('/backups');
        }
        if ((bool) $this->app->config()->get('backup.background', true)) {
            // The page shows the progress; when it finishes everyone, this person included, is
            // signed out and the page goes to the sign-in form.
            $backup->markStarting('restore', $name);
            $this->app->logger()->info('security', 'Restore started', ['file' => $name, 'username' => $user['username']]);
            if (!$this->s->supervisor()->launchScript('backup.php', ['restore', $path, '--yes', '--track', '--by=' . (string) $user['username']], 'backup.log')) {
                $backup->writeStatus(['kind' => 'restore', 'status' => 'failed', 'phase' => 'failed', 'started_at' => time(), 'finished_at' => time(),
                    'message' => 'The restore could not be started, so nothing changed. See storage/logs/app.log, or run php bin/backup.php restore on the server.']);
            }
            return $this->redirect('/backups');
        }
        @set_time_limit(0);
        try {
            $result = $backup->restore($path, (string) $user['username']);
        } catch (\Throwable $e) {
            $this->flash('danger', $e->getMessage());
            return $this->redirect('/backups');
        }
        // Every session ended with the restore (the accounts may differ now), this one too. No
        // flash message survives that, so the backups page shows the result (last-restore.json).
        $this->session->destroy();
        return $this->redirect('/login', ['restored' => '1']);
    }

    public function delete(string $name): Response
    {
        if (!$this->s->backup()->delete($name)) {
            throw HttpException::notFound('Backup');
        }
        $this->app->logger()->info('security', 'Backup deleted', ['file' => $name, 'username' => $this->auth->user()['username'] ?? null]);
        $this->flash('success', 'Deleted ' . $name . '.');
        return $this->redirect('/backups');
    }

    /** @return array<string,int> bytes per storage area, so the options show what they cost */
    private function areaSizes(): array
    {
        $sizes = [];
        foreach (array_keys(Backup::AREAS) as $area) {
            $bytes = 0;
            $root = $this->app->paths()->storage() . '/' . $area;
            if (is_dir($root)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $f) {
                    $bytes += $f->isFile() ? (int) $f->getSize() : 0;
                }
            }
            $sizes[$area] = $bytes;
        }
        return $sizes;
    }

    /** The largest upload PHP accepts here. */
    private static function uploadLimit(): int
    {
        $parse = static function (string $v): int {
            $n = (int) $v;
            return match (strtolower(substr(trim($v), -1))) {
                'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
            };
        };
        $limits = array_filter([$parse((string) ini_get('upload_max_filesize')), $parse((string) ini_get('post_max_size'))]);
        return $limits === [] ? 0 : min($limits);
    }
}
