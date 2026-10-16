<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Paths;
use App\Repositories\SettingsRepository;

/**
 * The installation's identity, entered once on Settings > Branding and read everywhere: the
 * system name (browser tab, sidebar, sign-in page, email), a tagline, a logo, the colour and
 * the default theme. config.php's app_name is only the fallback while no name is saved.
 *
 * Logo files live in storage/branding (outside the web root) and are served by
 * BrandingController with ?v=<modified time>, so a replaced logo shows at once.
 */
final class Branding
{
    public const ACCENTS = ['teal', 'indigo', 'violet', 'amber', 'rose', 'emerald', 'custom'];
    public const THEMES = ['system', 'light', 'dark'];
    public const LOGO_MAX_BYTES = 5 * 1024 * 1024;
    /** Longest side a raster logo is scaled down to. */
    public const LOGO_MAX_SIDE = 512;
    private const FILE_RE = '/^logo_[0-9]{8,14}(?:_[0-9a-f]{4})?\.(?:png|svg)$/';

    /** @var array{name:string,tagline:string,logo:?string,accent:string,color:string,theme:string}|null */
    private ?array $cache = null;

    public function __construct(private App $app)
    {
    }

    /** @return array{name:string,tagline:string,logo:?string,accent:string,color:string,theme:string} */
    public function get(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $values = [
            'name' => (string) $this->app->config()->get('app_name', 'Examtopics'),
            'tagline' => 'Your private exam study library',
            'logo' => null,
            'accent' => 'teal',
            'color' => '',
            'theme' => 'system',
        ];
        try {
            $s = $this->settings();
            $name = trim((string) $s->get('brand.name', ''));
            if ($name !== '') {
                $values['name'] = $name;
            }
            $tagline = $s->get('brand.tagline');
            if ($tagline !== null) {
                $values['tagline'] = $tagline;
            }
            $logo = (string) $s->get('brand.logo', '');
            if (self::validFile($logo) && is_file($this->path($logo))) {
                $values['logo'] = $logo;
            }
            $color = (string) $s->get('brand.color', '');
            $accent = (string) $s->get('brand.accent', 'teal');
            if (in_array($accent, self::ACCENTS, true) && ($accent !== 'custom' || self::validColor($color))) {
                $values['accent'] = $accent;
                $values['color'] = $accent === 'custom' ? strtoupper($color) : '';
            }
            $theme = (string) $s->get('brand.theme', 'system');
            if (in_array($theme, self::THEMES, true)) {
                $values['theme'] = $theme;
            }
        } catch (\Throwable) {
            // No database yet: the defaults stand.
        }
        return $this->cache = $values;
    }

    /**
     * Save the text and colour settings.
     *
     * @param array<string,mixed> $input
     * @return string|null an error to show, or null when saved
     */
    public function save(array $input): ?string
    {
        $name = trim((string) ($input['name'] ?? ''));
        $tagline = trim((string) ($input['tagline'] ?? ''));
        $accent = (string) ($input['accent'] ?? 'teal');
        $color = trim((string) ($input['color'] ?? ''));
        $theme = (string) ($input['theme'] ?? 'system');
        if (mb_strlen($name) > 60) {
            return 'Keep the system name to 60 characters.';
        }
        if (mb_strlen($tagline) > 120) {
            return 'Keep the tagline to 120 characters.';
        }
        if (!in_array($accent, self::ACCENTS, true)) {
            return 'Choose one of the colours.';
        }
        if ($accent === 'custom' && !self::validColor($color)) {
            return 'A custom colour is a hex value such as #1C7293.';
        }
        $s = $this->settings();
        $s->set('brand.name', $name);
        $s->set('brand.tagline', $tagline);
        $s->set('brand.accent', $accent);
        $s->set('brand.color', $accent === 'custom' ? strtoupper($color) : '');
        $s->set('brand.theme', in_array($theme, self::THEMES, true) ? $theme : 'system');
        $this->cache = null;
        return null;
    }

    /**
     * Store an uploaded logo. Raster images are scaled down and saved as PNG (transparency
     * kept); SVG is kept as uploaded.
     *
     * @param array{name:string,tmp_name:string,size:int,error:int} $upload
     * @return string|null an error to show, or null when stored
     */
    public function storeLogo(array $upload): ?string
    {
        if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'That file is larger than the server accepts (upload_max_filesize in php.ini). Use a smaller image.';
        }
        if ($upload['error'] !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'])) {
            return 'The upload did not arrive. Try again.';
        }
        if ($upload['size'] > self::LOGO_MAX_BYTES) {
            return 'Use an image under 5 MB.';
        }
        $bytes = (string) file_get_contents($upload['tmp_name']);
        $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['heic', 'heif'], true) || str_contains(substr($bytes, 4, 8), 'ftyphei')) {
            return 'iPhone HEIC photos cannot be read. Save it as JPEG or PNG and upload that instead.';
        }
        Paths::ensureDir($this->dir());
        $file = 'logo_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(2));
        if (self::looksLikeSvg($bytes)) {
            $file .= '.svg';
            file_put_contents($this->path($file), $bytes);
        } else {
            $png = self::rasterToPng($bytes);
            if ($png === null) {
                return 'That is not an image this server can read. Use PNG, JPEG, GIF, WebP or SVG.';
            }
            $file .= '.png';
            file_put_contents($this->path($file), $png);
        }
        $this->replaceLogo($file);
        return null;
    }

    public function removeLogo(): void
    {
        $this->replaceLogo('');
    }

    /** Point the setting at a new file and delete the old one. */
    private function replaceLogo(string $file): void
    {
        $old = (string) $this->settings()->get('brand.logo', '');
        $this->settings()->set('brand.logo', $file);
        if ($old !== '' && $old !== $file && self::validFile($old)) {
            @unlink($this->path($old));
        }
        $this->cache = null;
    }

    /** The logo's address with ?v=<modified time>, or null. */
    public function logoUrl(): ?string
    {
        $logo = $this->get()['logo'];
        if ($logo === null) {
            return null;
        }
        return \App\Core\View::buildUrl((string) $this->app->config()->get('base_path', ''), '/branding/' . $logo,
            ['v' => (string) (filemtime($this->path($logo)) ?: 0)]);
    }

    /** The logo as a file, for email (PNG only: mail clients do not show SVG). */
    public function logoFile(): ?string
    {
        $logo = $this->get()['logo'];
        return $logo !== null ? $this->path($logo) : null;
    }

    public function dir(): string
    {
        return $this->app->paths()->brandingRoot();
    }

    public function path(string $file): string
    {
        return $this->dir() . '/' . $file;
    }

    /** Only names this class creates are ever served or deleted. */
    public static function validFile(string $file): bool
    {
        return preg_match(self::FILE_RE, $file) === 1;
    }

    public static function validColor(string $color): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1;
    }

    /** The stylesheet that applies a custom colour (empty for a preset). */
    public function css(): string
    {
        $b = $this->get();
        return $b['accent'] === 'custom' ? self::customCss($b['color']) : '';
    }

    /**
     * The accent variables for a custom colour in both themes. The colour is darkened for the
     * light theme, or lightened for the dark one, only as far as it takes to read as text and
     * to carry white (light) or near-black (dark) button text. A colour that already passes is
     * used exactly as entered.
     */
    public static function customCss(string $hex): string
    {
        if (!self::validColor($hex)) {
            return '';
        }
        $rgb = self::rgb($hex);
        $light = self::untilContrast($rgb, [255, 255, 255], 4.5, [0, 0, 0]);
        $darkBg = [12, 12, 14]; // --color-bg in the dark theme
        $dark = self::untilContrast($rgb, $darkBg, 5.0, [255, 255, 255]);
        $v = static fn(array $c): string => sprintf('#%02X%02X%02X', $c[0], $c[1], $c[2]);
        return ":root[data-accent=\"custom\"] {\n"
            . '  --color-primary: ' . $v($light) . ";\n"
            . '  --color-primary-hover: ' . $v(self::mix($light, [0, 0, 0], 0.15)) . ";\n"
            . '  --color-accent: ' . $v(self::untilContrast($rgb, [255, 255, 255], 3.0, [0, 0, 0])) . ";\n"
            . '  --color-primary-soft: ' . $v(self::mix($light, [255, 255, 255], 0.88)) . ";\n"
            . '  --color-focus-ring: ' . $v($light) . "55;\n"
            . "}\n"
            . "[data-theme=\"dark\"][data-accent=\"custom\"] {\n"
            . '  --color-primary: ' . $v($dark) . ";\n"
            . '  --color-primary-hover: ' . $v(self::mix($dark, [255, 255, 255], 0.15)) . ";\n"
            . '  --color-accent: ' . $v(self::mix($dark, [255, 255, 255], 0.1)) . ";\n"
            . '  --color-primary-soft: ' . $v(self::mix($dark, $darkBg, 0.88)) . ";\n"
            . '  --color-focus-ring: ' . $v($dark) . "55;\n"
            . "}\n"
            . '.swatch-dot[data-swatch="custom"] { background: ' . $v($light) . "; }\n";
    }

    /** @return array{int,int,int} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    /**
     * Mix towards $toward in small steps until the contrast with $against reaches $ratio.
     *
     * @param array{int,int,int} $c @param array{int,int,int} $against @param array{int,int,int} $toward
     * @return array{int,int,int}
     */
    private static function untilContrast(array $c, array $against, float $ratio, array $toward): array
    {
        for ($i = 0; $i <= 20; $i++) {
            $mixed = self::mix($c, $toward, $i / 20);
            if (self::contrast($mixed, $against) >= $ratio) {
                return $mixed;
            }
        }
        return $toward;
    }

    /** @param array{int,int,int} $a @param array{int,int,int} $b @return array{int,int,int} */
    private static function mix(array $a, array $b, float $t): array
    {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    /** WCAG contrast ratio. @param array{int,int,int} $a @param array{int,int,int} $b */
    public static function contrast(array $a, array $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** @param array{int,int,int} $c */
    private static function luminance(array $c): float
    {
        $f = static function (int $v): float {
            $s = $v / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
    }

    private static function looksLikeSvg(string $bytes): bool
    {
        $head = ltrim(substr($bytes, 0, 1024));
        return (str_starts_with($head, '<?xml') || str_starts_with($head, '<svg') || str_starts_with($head, '<!--'))
            && stripos($head . substr($bytes, 1024, 4096), '<svg') !== false;
    }

    /** Decode, scale down and re-encode as PNG; null when GD cannot read it. */
    private static function rasterToPng(string $bytes): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, self::LOGO_MAX_SIDE / max($w, $h));
        if ($scale < 1.0 || !imageistruecolor($img)) {
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $out = imagecreatetruecolor($nw, $nh);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
            imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $img = $out;
        } else {
            imagesavealpha($img, true);
        }
        ob_start();
        imagepng($img, null, 9);
        return (string) ob_get_clean();
    }

    private function settings(): SettingsRepository
    {
        return new SettingsRepository($this->app->db());
    }
}
