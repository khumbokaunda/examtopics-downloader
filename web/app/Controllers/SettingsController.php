<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Services\Appearance;

final class SettingsController extends Controller
{
    public function index(): Response
    {
        $binary = (string) $this->app->config()->get('tool.binary');
        $lock = $this->s->supervisor()->lock();
        return $this->render('settings/index', [
            'title' => 'Settings',
            'nav' => 'settings',
            'toolInstalled' => is_file($binary) && is_executable($binary),
            'toolMissing' => \App\Services\ToolCheck::missingOptions($binary),
            'toolPath' => $binary,
            'tokenConfigured' => (string) $this->app->config()->get('github_token', '') !== '',
            'workerRunning' => $lock->isHeld(),
            'workerPid' => $lock->holderPid(),
            'autostart' => (bool) $this->app->config()->get('worker.autostart', true),
            'rps' => (float) $this->app->config()->get('tool.default_rps', 1.0),
            'storage' => $this->app->paths()->storage(),
            'imagesDownload' => (bool) $this->app->config()->get('media.download', true),
            'imageTotals' => $this->s->media()->totals(),
            'recentLogins' => $this->s->loginAttempts()->recent(200),
            'system' => $this->system()->effective(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    /** Timezones and how long a sign-in lasts. */
    public function system(): \App\Services\SystemSettings
    {
        return $this->app->service(\App\Services\SystemSettings::class, fn() => new \App\Services\SystemSettings($this->app));
    }

    public function time(): Response
    {
        try {
            $before = $this->system()->effective();
            $this->system()->save([
                'timezone' => (string) $this->request->input('timezone', ''),
                'idle_timeout' => (string) $this->request->input('idle_timeout', ''),
                'absolute_timeout' => (string) $this->request->input('absolute_timeout', ''),
            ]);
            $after = $this->system()->effective();
            $changes = [];
            foreach ($after as $k => $v) {
                if (!is_array($v) && (string) ($before[$k] ?? '') !== (string) $v) {
                    $changes[$k] = [$before[$k] ?? null, $v];
                }
            }
            if ($changes !== []) {
                $this->s->audit()->record('settings.time_changed', ['changes' => $changes]);
            }
            $this->flash('success', 'Time and sign-in settings saved. New timeouts apply from the next page.');
        } catch (\PDOException $e) {
            $this->flash('danger', $this->schemaMessage($e));
        }
        return $this->redirect('/settings#time');
    }

    /** The appearance form: every preference at once. */
    public function appearance(): Response
    {
        try {
            $this->saveAppearance();
            $this->flash('success', 'Appearance saved.');
        } catch (\PDOException $e) {
            $this->flash('danger', $this->schemaMessage($e));
        }
        return $this->redirect('/account#appearance');
    }

    /** The top bar's theme toggle (and any other single preference) from JavaScript. */
    public function appearanceJson(): Response
    {
        try {
            return Response::json(['appearance' => $this->saveAppearance()]);
        } catch (\PDOException $e) {
            return Response::json(['error' => $this->schemaMessage($e)], 503);
        }
    }

    /** Saving fails like this when the code was updated but the database was not migrated. */
    private function schemaMessage(\PDOException $e): string
    {
        $this->app->logger()->error('app', 'Saving appearance failed', ['error' => $e->getMessage()]);
        return $this->s->users()->hasColumn('preferences')
            ? 'Your appearance could not be saved. See storage/logs/app.log on the server.'
            : 'Your appearance could not be saved because the database needs an update. On the server, run php bin/migrate.php in the web folder.';
    }

    /** @return array<string,string> */
    private function saveAppearance(): array
    {
        $user = (array) $this->auth->user();
        $input = [];
        foreach (array_keys(Appearance::OPTIONS) as $key) {
            $value = $this->request->input($key);
            if ($value !== null) {
                $input[$key] = $value;
            }
        }
        $prefs = Appearance::merge(Appearance::forUser($user, $this->s->branding()->get()), $input);
        $this->s->users()->setPreferences((int) $user['id'], $prefs);
        return $prefs;
    }

    public function reconcile(): Response
    {
        $state = $this->s->supervisor()->ensure();
        $this->s->audit()->record('settings.reconcile_run', ['result' => $state]);
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
