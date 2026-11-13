<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The artwork behind a certificate, drawn in the site's brand colour: the page (diagonal ribbons
 * in two corners, flowing lines, a field of dots, a fine frame) and a seal with ribbons.
 *
 * Drawn here, as pictures, because the PDF renderer draws CSS gradients and shapes poorly.
 * Each picture is made once per colour and kept in storage/tmp/pdf, so a second certificate
 * costs nothing.
 */
final class CertificateArt
{
    /** A4 portrait at 150 dots per inch. */
    public const W = 1240;
    public const H = 1754;

    public function __construct(private string $dir)
    {
    }

    /** @return array{page:string,seal:string} data: URIs for an <img> */
    public function forColour(string $hex): array
    {
        $hex = preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1 ? strtoupper($hex) : '#1C7293';
        @mkdir($this->dir, 0750, true);
        $out = [];
        foreach (['page' => fn() => $this->page($hex), 'seal' => fn() => $this->seal($hex)] as $kind => $draw) {
            $file = $this->dir . '/certificate-' . $kind . '-v2-' . substr($hex, 1) . '.png';
            if (!is_file($file)) {
                $png = $draw();
                @file_put_contents($file . '.part', $png);
                @rename($file . '.part', $file);
                $out[$kind] = 'data:image/png;base64,' . base64_encode($png);
                continue;
            }
            $out[$kind] = 'data:image/png;base64,' . base64_encode((string) file_get_contents($file));
        }
        return $out;
    }

    /** A QR code for the address that checks a certificate, as a data: URI. */
    public static function qr(string $url): string
    {
        return (new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,
            'scale' => 5, 'outputBase64' => true, 'quietzoneSize' => 1])))->render($url);
    }

    /** A logo file as a data: URI, or null when there is none to show. */
    public static function logo(?string $path): ?string
    {
        if ($path === null || !is_file($path)) {
            return null;
        }
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', default => null,
        };
        return $mime === null ? null : 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }

    private function page(string $hex): string
    {
        $im = imagecreatetruecolor(self::W, self::H);
        imagealphablending($im, true);
        imageantialias($im, true);
        $primary = self::rgb($hex);
        $white = [255, 255, 255];
        imagefill($im, 0, 0, self::col($im, self::mix($primary, $white, 0.975)));

        // A soft glow behind the middle of the page.
        for ($r = 760; $r > 0; $r -= 8) {
            $c = self::col($im, self::mix($primary, $white, 0.975 + 0.025 * (1 - $r / 760)), 0);
            imagefilledellipse($im, (int) (self::W / 2), (int) (self::H * 0.42), $r * 2, (int) ($r * 1.7), $c);
        }

        // Fields of dots fading out from two corners.
        for ($y = 30; $y < 520; $y += 22) {
            for ($x = self::W - 520; $x < self::W - 20; $x += 22) {
                $d = hypot(self::W - $x, $y) / 560;
                if ($d < 1) {
                    imagefilledellipse($im, $x, $y, 5, 5, self::col($im, $primary, (int) (60 + 67 * $d)));
                }
            }
        }
        for ($y = self::H - 520; $y < self::H - 20; $y += 22) {
            for ($x = 30; $x < 520; $x += 22) {
                $d = hypot($x, self::H - $y) / 560;
                if ($d < 1) {
                    imagefilledellipse($im, $x, $y, 5, 5, self::col($im, $primary, (int) (60 + 67 * $d)));
                }
            }
        }

        // Flowing lines, kept to the top right and the bottom left corners.
        $bg = self::mix($primary, $white, 0.975);
        for ($k = 0; $k < 22; $k++) {
            $strength = 0.45 - $k * 0.012;
            $this->wave($im, $primary, $bg, $strength, 150 + $k * 5, 0.006, 70 + $k * 2.5, $k * 0.07, 0.5, true);
            $this->wave($im, $primary, $bg, $strength, self::H - 170 - $k * 5, 0.006, 70 + $k * 2.5, 2.2 + $k * 0.07, 0.5, false);
        }

        // Diagonal ribbons in the top left and bottom right corners, the brand colour fading into a
        // lighter tint. Solid colours (each already mixed with the page), so strips do not show.
        $page = self::mix($primary, $white, 0.975);
        $tint = self::mix($primary, $white, 0.62);
        $deep = self::mix($primary, [0, 0, 0], 0.12);
        $soft = self::mix($primary, $page, 0.35);
        $this->ribbon($im, [-260, -20], 150, 640, 0.6, $deep, $tint);
        $this->ribbon($im, [-110, 130], 95, 430, 0.6, $soft, self::mix($tint, $page, 0.4));
        $this->ribbon($im, [-330, 330], 70, 360, 0.6, $tint, $soft);
        $this->ribbon($im, [self::W - 120, self::H - 620], 150, 640, 0.6, $tint, $deep);
        $this->ribbon($im, [self::W - 210, self::H - 560], 95, 430, 0.6, self::mix($tint, $page, 0.4), $soft);
        $this->ribbon($im, [self::W + 40, self::H - 760], 70, 360, 0.6, $soft, $tint);

        // A fine double frame.
        imagesetthickness($im, 3);
        imagerectangle($im, 46, 46, self::W - 47, self::H - 47, self::col($im, $primary, 70));
        imagesetthickness($im, 1);
        imagerectangle($im, 60, 60, self::W - 61, self::H - 61, self::col($im, $primary, 95));

        return self::png($im);
    }

    /**
     * A band leaning right, from $at (its bottom left), $width across and $length long, its colour
     * going from $from at the top to $to at the bottom.
     *
     * @param array{0:int,1:int} $at @param array{0:int,1:int,2:int} $from @param array{0:int,1:int,2:int} $to
     */
    private function ribbon(\GdImage $im, array $at, int $width, int $length, float $lean, array $from, array $to): void
    {
        $steps = 120;
        for ($i = 0; $i < $steps; $i++) {
            $t0 = $i / $steps;
            $t1 = ($i + 1) / $steps;
            $y0 = $at[1] + $length * $t0;
            $y1 = $at[1] + $length * $t1 + 1;
            // Leaning: x moves left as y goes down.
            $x0 = $at[0] + $length * $lean * (1 - $t0);
            $x1 = $at[0] + $length * $lean * (1 - $t1);
            $c = self::col($im, self::mix($from, $to, $t0));
            imagefilledpolygon($im, [(int) $x0, (int) $y0, (int) ($x0 + $width), (int) $y0, (int) ($x1 + $width), (int) $y1, (int) $x1, (int) $y1], $c);
        }
    }

    /**
     * One flowing line across part of the page, in solid colours mixed toward the page so it fades
     * out at its inner end (transparent segments overlap and look dashed).
     *
     * @param array{0:int,1:int,2:int} $rgb @param array{0:int,1:int,2:int} $page
     */
    private function wave(\GdImage $im, array $rgb, array $page, float $strength, float $y, float $freq, float $amp, float $phase, float $span, bool $right): void
    {
        $start = $right ? (int) (self::W * (1 - $span)) : 0;
        $end = $right ? self::W : (int) (self::W * $span);
        $px = null;
        $py = null;
        for ($x = $start; $x <= $end; $x += 3) {
            $fade = $right ? ($x - $start) / max(1, $end - $start) : 1 - ($x - $start) / max(1, $end - $start);
            $yy = $y + sin($x * $freq + $phase) * $amp * (0.4 + 0.6 * $fade);
            if ($px !== null) {
                $c = self::col($im, self::mix($page, $rgb, $strength * min(1.0, $fade * 1.6)));
                imageline($im, $px, $py, $x, (int) $yy, $c);
            }
            $px = $x;
            $py = (int) $yy;
        }
    }

    private function seal(string $hex): string
    {
        $s = 420;
        $im = imagecreatetruecolor($s, (int) ($s * 1.35));
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        imageantialias($im, true);
        $primary = self::rgb($hex);
        $deep = self::mix($primary, [0, 0, 0], 0.3);
        $cx = (int) ($s / 2);
        $cy = (int) ($s * 0.45);

        // Two ribbons hanging below, with notched ends.
        foreach ([[-1, $primary], [1, $deep]] as [$side, $rgb]) {
            $top = $cy + 40;
            $x0 = $cx + $side * 18;
            $x1 = $cx + $side * 120;
            $bottom = (int) ($s * 1.3);
            $pts = [$x0 - $side * 48, $top, $x0 + $side * 48, $top, $x1 + $side * 40, $bottom, $x1, $bottom - 46, $x1 - $side * 52, $bottom];
            imagefilledpolygon($im, $pts, self::col($im, $rgb));
        }

        // The rosette: a gold starburst, a ring and a centre.
        $gold = [227, 178, 60];
        $goldDark = [190, 139, 32];
        $pts = [];
        $n = 32;
        for ($i = 0; $i < $n * 2; $i++) {
            $r = $i % 2 === 0 ? 188 : 168;
            $a = M_PI * $i / $n;
            $pts[] = (int) ($cx + cos($a) * $r);
            $pts[] = (int) ($cy + sin($a) * $r);
        }
        imagefilledpolygon($im, $pts, self::col($im, $goldDark));
        imagefilledellipse($im, $cx, $cy, 320, 320, self::col($im, $gold));
        imagesetthickness($im, 4);
        imageellipse($im, $cx, $cy, 270, 270, self::col($im, [255, 236, 179]));
        imagesetthickness($im, 1);
        imagefilledellipse($im, $cx, $cy, 230, 230, self::col($im, [240, 196, 82]));
        // A star in the centre.
        $star = [];
        for ($i = 0; $i < 10; $i++) {
            $r = $i % 2 === 0 ? 78 : 32;
            $a = -M_PI / 2 + M_PI * $i / 5;
            $star[] = (int) ($cx + cos($a) * $r);
            $star[] = (int) ($cy + sin($a) * $r);
        }
        imagefilledpolygon($im, $star, self::col($im, [255, 250, 235]));

        return self::png($im);
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function col(\GdImage $im, array $rgb, int $alpha = 0): int
    {
        return (int) imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], max(0, min(127, $alpha)));
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /** @param array{0:int,1:int,2:int} $a @param array{0:int,1:int,2:int} $b @return array{0:int,1:int,2:int} */
    private static function mix(array $a, array $b, float $t): array
    {
        return [(int) round($a[0] + ($b[0] - $a[0]) * $t), (int) round($a[1] + ($b[1] - $a[1]) * $t), (int) round($a[2] + ($b[2] - $a[2]) * $t)];
    }

    private static function png(\GdImage $im): string
    {
        ob_start();
        imagepng($im, null, 6);
        return (string) ob_get_clean();
    }
}
