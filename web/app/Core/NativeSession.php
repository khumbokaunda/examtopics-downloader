<?php

declare(strict_types=1);

namespace App\Core;

final class NativeSession extends Session
{
    /**
     * @param array{idle_timeout:int,absolute_timeout:int} $timeouts
     * @param string $savePath a private directory for session files (see below), or '' for PHP's
     */
    public function start(Config $config, bool $https, array $timeouts = ['idle_timeout' => 14400, 'absolute_timeout' => 0], string $savePath = ''): void
    {
        $secure = (bool) $config->get('session.secure', true);
        $idle = max(300, $timeouts['idle_timeout']);
        $absolute = max(0, $timeouts['absolute_timeout']);
        // Session files live in the app's own directory, cleaned on this app's schedule: in PHP's
        // shared directory, Debian's cleanup job deletes them after 24 idle minutes, whatever the
        // timeout here says.
        if ($savePath !== '' && (is_dir($savePath) || @mkdir($savePath, 0700, true))) {
            ini_set('session.save_path', $savePath);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }
        ini_set('session.gc_maxlifetime', (string) max($idle, $absolute));
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        session_name((string) $config->get('session.name', 'examtopics_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure || $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        $this->data = &$_SESSION;

        $last = (int) ($this->data['_last_activity'] ?? 0);
        $started = (int) ($this->data['_started_at'] ?? 0);
        if (($last > 0 && time() - $last > $idle) || ($absolute > 0 && $started > 0 && time() - $started > $absolute)) {
            $this->destroy();
            session_start();
            $this->data = &$_SESSION;
        }
        $this->data['_last_activity'] = time();
        $this->data['_started_at'] ??= time();
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
                'httponly' => true, 'samesite' => 'Strict',
            ]);
            session_destroy();
        }
    }
}
