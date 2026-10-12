<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session data. The base class keeps data in memory (used directly by tests); NativeSession
 * binds it to PHP's session with hardened cookie settings.
 */
class Session
{
    /** @var array<string,mixed> */
    protected array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /** New session ID, same data. Called on login to prevent session fixation. */
    public function regenerate(): void
    {
    }

    public function destroy(): void
    {
        $this->data = [];
    }

    public function flash(string $type, string $message): void
    {
        $flashes = $this->get('_flash', []);
        $flashes[] = ['type' => $type, 'message' => $message];
        $this->set('_flash', $flashes);
    }

    /** @return list<array{type:string,message:string}> */
    public function takeFlashes(): array
    {
        $f = $this->get('_flash', []);
        $this->remove('_flash');
        return is_array($f) ? $f : [];
    }

    public function csrfToken(): string
    {
        $t = $this->get('_csrf');
        if (!is_string($t) || strlen($t) !== 64) {
            $t = bin2hex(random_bytes(32));
            $this->set('_csrf', $t);
        }
        return $t;
    }

    public function validCsrf(?string $token): bool
    {
        $t = $this->get('_csrf');
        return is_string($t) && is_string($token) && $token !== '' && hash_equals($t, $token);
    }
}
