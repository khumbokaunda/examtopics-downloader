<?php

declare(strict_types=1);

namespace Tests\Support;

/** A minimal "browser": cookies, CSRF token scraping, no redirect following. */
final class HttpClient
{
    /** @var array<string,string> */
    private array $cookies = [];

    public function __construct(private string $base)
    {
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    public function request(string $method, string $path, array $form = []): array
    {
        $ch = curl_init($this->base . $path);
        $headers = [];
        if ($this->cookies) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_NOPROXY => '*',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException('HTTP error: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $out = [];
        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = array_map('trim', explode(':', $line, 2));
                $out[strtolower($k)] = $v;
                if (strtolower($k) === 'set-cookie') {
                    [$pair] = explode(';', $v, 2);
                    [$ck, $cv] = array_pad(explode('=', $pair, 2), 2, '');
                    if ($cv === '' || str_contains(strtolower($v), 'expires=thu, 01 jan 1970')) {
                        unset($this->cookies[$ck]);
                    } else {
                        $this->cookies[$ck] = $cv;
                    }
                }
            }
        }
        return ['status' => $status, 'headers' => $out, 'body' => substr((string) $raw, $headerSize)];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** POST with the CSRF token scraped from $tokenPage (or the last page's meta tag). */
    public function post(string $path, array $form, string $tokenPage = '/'): array
    {
        $page = $this->get($tokenPage)['body'];
        if (preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $page, $m) !== 1
            && preg_match('/name="csrf-token" content="([0-9a-f]{64})"/', $page, $m) !== 1) {
            throw new \RuntimeException('No CSRF token on ' . $tokenPage);
        }
        return $this->request('POST', $path, $form + ['_csrf' => $m[1]]);
    }

    public function json(string $path): array
    {
        $r = $this->get($path);
        return (array) json_decode($r['body'], true);
    }

    public function forgetCookies(): void
    {
        $this->cookies = [];
    }
}
