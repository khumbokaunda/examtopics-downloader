<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The network address of the person making a request.
 *
 * Normally that is REMOTE_ADDR. Behind a reverse proxy (Cloudflare, a load balancer, another
 * nginx in front of this one) REMOTE_ADDR is the proxy, the same for everyone, and the
 * person's address is in a header the proxy adds. Anybody can send those headers, so they are
 * only believed from a proxy this server was told about: one listed in security.trusted_proxies
 * (addresses or CIDR ranges), or Cloudflare, whose ranges are published and which sets
 * CF-Connecting-IP itself (security.trust_cloudflare, on by default). X-Forwarded-For is read
 * from the right: each proxy appends the address it received the request from, so the first
 * entry that is not a trusted proxy is the person; anything left of it could be invented.
 *
 * security.trust_forwarded_for (the older switch) trusts the one proxy directly in front.
 */
final class ClientIp
{
    /** Cloudflare's published ranges (cloudflare.com/ips). */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** @param array<string,mixed> $server $_SERVER, or a test's */
    public static function of(array $server, Config $config): string
    {
        $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }
        $trusted = array_values(array_filter(array_map('trim', (array) $config->get('security.trusted_proxies', [])),
            static fn($v) => $v !== ''));
        if ((bool) $config->get('security.trust_cloudflare', true)) {
            $trusted = [...$trusted, ...self::CLOUDFLARE];
        }
        $direct = (bool) $config->get('security.trust_forwarded_for', false);
        if (!$direct && !self::inList($remote, $trusted)) {
            return $remote;
        }
        // Cloudflare's own header, when the request came through it or through a listed proxy
        // that passes it on (a tunnel on this server).
        $cf = trim((string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ((bool) $config->get('security.trust_cloudflare', true) && self::inList($remote, $trusted)
            && $cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
            return $cf;
        }
        $hops = array_reverse(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($hops as $hop) {
            if ($hop === '' || filter_var($hop, FILTER_VALIDATE_IP) === false) {
                return $remote;
            }
            if (!self::inList($hop, $trusted)) {
                return $hop;
            }
        }
        return $remote;
    }

    /** An address of Cloudflare's: a proxy, never a person. */
    public static function isCloudflare(string $ip): bool
    {
        return self::inList($ip, self::CLOUDFLARE);
    }

    /** A public internet address. A private or loopback one is usually a proxy everyone shares. */
    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @param list<string> $list addresses or CIDR ranges */
    public static function inList(string $ip, array $list): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($list as $entry) {
            [$net, $bits] = array_pad(explode('/', (string) $entry, 2), 2, null);
            $netBin = @inet_pton((string) $net);
            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $max = strlen($bin) * 8;
            $bits = $bits === null ? $max : (int) $bits;
            if ($bits < 0 || $bits > $max) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }
}
