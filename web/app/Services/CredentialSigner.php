<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Signs credential fingerprints with an Ed25519 key, so a copy of a credential can be checked
 * offline against the published public key (/verify/keys). The verification page stays the
 * authority: a signature proves the fields were issued by this platform, not that the credential
 * still stands.
 *
 * Keys live in storage/keys (outside the web root, included in backups), one file per key named
 * by its id (the first 16 hex characters of the public key's SHA-256). storage/keys/current names
 * the key in use. A replaced key is kept, so credentials it signed still verify.
 */
final class CredentialSigner
{
    public function __construct(private string $dir)
    {
    }

    /** @return array{0:string,1:string} the signature (base64) and the id of the key that made it */
    public function sign(string $message): array
    {
        [$kid, $secret] = $this->current();
        return [base64_encode(sodium_crypto_sign_detached($message, $secret)), $kid];
    }

    public function verify(string $message, string $signature, string $kid): bool
    {
        $public = $this->publicKey($kid);
        $sig = base64_decode($signature, true);
        return $public !== null && $sig !== false && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($sig, $message, $public);
    }

    /** @return array<string,string> every key's public half, base64, by id */
    public function publicKeys(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.key') ?: [] as $f) {
            $kid = basename($f, '.key');
            if (($p = $this->publicKey($kid)) !== null) {
                $out[$kid] = base64_encode($p);
            }
        }
        return $out;
    }

    private function publicKey(string $kid): ?string
    {
        if (preg_match('/^[a-f0-9]{16}$/', $kid) !== 1 || !is_file($this->dir . '/' . $kid . '.key')) {
            return null;
        }
        $secret = (string) hex2bin(trim((string) file_get_contents($this->dir . '/' . $kid . '.key')));
        return strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ? sodium_crypto_sign_publickey_from_secretkey($secret) : null;
    }

    /** @return array{0:string,1:string} the current key's id and secret, made on first use */
    private function current(): array
    {
        $kid = is_file($this->dir . '/current') ? trim((string) file_get_contents($this->dir . '/current')) : '';
        if ($kid !== '' && is_file($this->dir . '/' . $kid . '.key')) {
            return [$kid, (string) hex2bin(trim((string) file_get_contents($this->dir . '/' . $kid . '.key')))];
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException('The signing key could not be stored. Check that storage/keys can be written.');
        }
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $kid = substr(hash('sha256', sodium_crypto_sign_publickey($pair)), 0, 16);
        if (file_put_contents($this->dir . '/' . $kid . '.key', bin2hex($secret)) === false || file_put_contents($this->dir . '/current', $kid) === false) {
            throw new RuntimeException('The signing key could not be stored. Check that storage/keys can be written.');
        }
        @chmod($this->dir . '/' . $kid . '.key', 0600);
        return [$kid, $secret];
    }
}
