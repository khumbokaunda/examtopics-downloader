<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Everything the pages load is served by the app itself: no template, stylesheet or script
 * names another host, and every referenced asset exists. The CSP ('self' only) would block a
 * CDN link anyway; this catches it before it fails quietly in a browser.
 */
final class AssetsTest extends TestCase
{
    private static function web(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private static function files(string $dir, string $pattern): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (preg_match($pattern, $f->getFilename()) === 1 && !str_contains($f->getPathname(), '/vendor/')) {
                $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    public function testNoTemplateOrAssetLoadsFromAnotherHost(): void
    {
        $files = array_merge(
            self::files(self::web() . '/app/Views', '/\.php$/'),
            self::files(self::web() . '/public/assets', '/\.(css|js)$/'),
        );
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('#(src|href)\s*=\s*["\']?(https?:)?//#i', $text, "$file links to another host");
            self::assertDoesNotMatchRegularExpression('#url\(\s*["\']?(https?:)?//#i', $text, "$file loads a URL from another host");
            self::assertDoesNotMatchRegularExpression('#@import#i', $text, "$file imports a stylesheet");
        }
    }

    public function testEveryAssetTheLayoutReferencesExists(): void
    {
        $layout = (string) file_get_contents(self::web() . '/app/Views/layout.php');
        preg_match_all("#asset\\('(/assets/[^']+)'\\)#", $layout, $m);
        self::assertGreaterThan(5, count($m[1]));
        foreach ($m[1] as $path) {
            self::assertFileExists(self::web() . '/public' . $path);
        }
    }

    public function testEveryFileAStylesheetReferencesExists(): void
    {
        $sheets = array_merge(
            self::files(self::web() . '/public/assets/css', '/\.css$/'),
            [self::web() . '/public/assets/vendor/bootstrap-icons/bootstrap-icons.min.css'],
        );
        foreach ($sheets as $css) {
            preg_match_all('#url\(\s*["\']?(?!data:)([^"\')?\#]+)#', (string) file_get_contents($css), $m);
            foreach ($m[1] as $ref) {
                self::assertFileExists(dirname($css) . '/' . $ref, "$css references a missing file");
            }
        }
    }

    public function testThemeScriptsKnowTheSameOptionsAsTheServer(): void
    {
        $init = (string) file_get_contents(self::web() . '/public/assets/js/theme-init.js');
        foreach (\App\Services\Appearance::OPTIONS as $key => $values) {
            self::assertStringContainsString($key . ": ['" . implode("', '", $values) . "']", $init, "theme-init.js options for $key");
        }
        $tokens = (string) file_get_contents(self::web() . '/public/assets/css/tokens.css');
        foreach (\App\Services\Appearance::OPTIONS['accent'] as $accent) {
            self::assertStringContainsString('[data-theme="dark"][data-accent="' . $accent . '"]', $tokens);
            self::assertStringContainsString(':root[data-accent="' . $accent . '"]', $tokens);
        }
    }
}
