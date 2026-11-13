<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CertificateArt;
use PHPUnit\Framework\TestCase;

/** The artwork behind certificates: drawn in the brand colour, once per colour. */
final class CertificateArtTest extends TestCase
{
    public function testTheArtworkIsDrawnOncePerColourAndKept(): void
    {
        $dir = sys_get_temp_dir() . '/cert-art-' . bin2hex(random_bytes(4));
        $art = new CertificateArt($dir);
        $navy = $art->forColour('#1F3A6E');
        foreach (['page', 'seal'] as $kind) {
            self::assertStringStartsWith('data:image/png;base64,', $navy[$kind]);
            $size = getimagesizefromstring((string) base64_decode(substr($navy[$kind], 22)));
            self::assertIsArray($size);
        }
        $size = getimagesizefromstring((string) base64_decode(substr($navy['page'], 22)));
        self::assertSame([CertificateArt::W, CertificateArt::H], [$size[0], $size[1]], 'A4 portrait');
        self::assertCount(2, glob($dir . '/*.png') ?: []);
        self::assertSame($navy, $art->forColour('#1f3a6e'), 'kept, whatever the case of the colour');
        $art->forColour('#BE185D');
        self::assertCount(4, glob($dir . '/*.png') ?: [], 'a new colour gets its own');
        self::assertSame($art->forColour('not a colour'), $art->forColour('#1C7293'), 'a bad colour falls back to the default');
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    public function testQrCodeAndLogo(): void
    {
        self::assertStringStartsWith('data:image/png;base64,', CertificateArt::qr('https://example.org/verify/abc'));
        self::assertNull(CertificateArt::logo(null));
        self::assertNull(CertificateArt::logo('/no/such/file.png'));
    }
}
