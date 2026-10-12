<?php

declare(strict_types=1);

namespace App\Core;

final class NativeSession extends Session
{
    public function start(Config $config, bool $https): void
    {
        $secure = (bool) $config->get('session.secure', true);
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

        $idle = (int) $config->get('session.idle_timeout', 14400);
        $last = (int) ($this->data['_last_activity'] ?? 0);
        if ($last > 0 && time() - $last > $idle) {
            $this->destroy();
            session_start();
            $this->data = &$_SESSION;
        }
        $this->data['_last_activity'] = time();
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
