<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;

final class SettingsController extends Controller
{
    public function index(): Response
    {
        $binary = (string) $this->app->config()->get('tool.binary');
        $lock = $this->s->supervisor()->lock();
        return $this->render('settings', [
            'title' => 'Settings',
            'nav' => 'settings',
            'user' => $this->auth->user(),
            'toolInstalled' => is_file($binary) && is_executable($binary),
            'toolPath' => $binary,
            'tokenConfigured' => (string) $this->app->config()->get('github_token', '') !== '',
            'workerRunning' => $lock->isHeld(),
            'workerPid' => $lock->holderPid(),
            'autostart' => (bool) $this->app->config()->get('worker.autostart', true),
            'rps' => (float) $this->app->config()->get('tool.default_rps', 1.0),
            'storage' => $this->app->paths()->storage(),
            'pdfImages' => (bool) $this->app->config()->get('documents.pdf_images', false),
            'recentLogins' => $this->s->loginAttempts()->recent(10),
        ]);
    }

    public function password(): Response
    {
        $user = (array) $this->auth->user();
        $current = (string) $this->request->input('current_password', '');
        $new = (string) $this->request->input('new_password', '');
        $repeat = (string) $this->request->input('repeat_password', '');
        if (!password_verify($current, (string) $user['password_hash'])) {
            $this->app->logger()->warning('security', 'Password change rejected: wrong current password', ['username' => $user['username']]);
            $this->flash('danger', 'The current password is incorrect.');
        } elseif (strlen($new) < 12) {
            $this->flash('danger', 'The new password must be at least 12 characters.');
        } elseif (!hash_equals($new, $repeat)) {
            $this->flash('danger', 'The new passwords do not match.');
        } else {
            $this->s->users()->setPassword((int) $user['id'], $new);
            $this->session->regenerate();
            $this->app->logger()->info('security', 'Password changed', ['username' => $user['username']]);
            $this->flash('success', 'Password changed.');
        }
        return $this->redirect('/settings');
    }

    public function reconcile(): Response
    {
        $state = $this->s->supervisor()->ensure();
        $this->flash('info', match ($state) {
            'running' => 'A worker is running; nothing needed reconciling.',
            'started' => 'Stale jobs reconciled; a worker was started for queued jobs.',
            'queued' => 'Stale jobs reconciled; queued jobs are waiting for the daemon worker.',
            'idle' => 'Stale jobs reconciled; no queued jobs.',
            default => 'Reconciled, but the worker could not be started. See storage/logs/app.log.',
        });
        return $this->redirect('/settings');
    }
}
