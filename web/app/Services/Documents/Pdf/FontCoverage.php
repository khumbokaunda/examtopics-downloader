<?php

declare(strict_types=1);

namespace App\Services\Documents\Pdf;

use FontLib\Font;

/**
 * Which characters a font can draw. dompdf uses one font per run of text and has no
 * per-character fallback, so runs the brand font cannot draw are wrapped for a fallback font
 * with wide coverage (DejaVu) instead of printing as blanks.
 */
final class FontCoverage
{
    /** @var array<string,string> regex character class bodies, per font file */
    private static array $classes = [];

    /** Wrap the runs of already escaped HTML that the font cannot draw in a span of $class. */
    public static function wrap(string $html, string $fontFile, string $class = 'fb'): string
    {
        $cc = self::$classes[$fontFile] ??= self::build($fontFile);
        if ($cc === '') {
            return $html;
        }
        return (string) preg_replace_callback('/[^' . $cc . '\s]+/u',
            static fn(array $m): string => '<span class="' . $class . '">' . $m[0] . '</span>', $html);
    }

    private static function build(string $fontFile): string
    {
        $font = is_file($fontFile) ? Font::load($fontFile) : null;
        if ($font === null) {
            return '';
        }
        $font->parse();
        $codes = [];
        foreach ((array) $font->getUnicodeCharMap() as $code => $glyph) {
            if ((int) $glyph > 0) {
                $codes[] = (int) $code;
            }
        }
        $font->close();
        sort($codes);
        $class = '';
        $start = $prev = null;
        foreach ($codes as $c) {
            if ($start !== null && $c === $prev + 1) {
                $prev = $c;
                continue;
            }
            if ($start !== null) {
                $class .= self::range($start, $prev);
            }
            $start = $prev = $c;
        }
        if ($start !== null) {
            $class .= self::range($start, $prev);
        }
        return $class;
    }

    private static function range(int $a, int $b): string
    {
        return sprintf('\x{%X}', $a) . ($b > $a ? sprintf('-\x{%X}', $b) : '');
    }
}
