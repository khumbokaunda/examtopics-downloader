<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pages and saves in place (js/swap.js), outlines of the coming page (js/skeleton.js), and the
 * motion around them. These are browser scripts, so what is held here is the contract between
 * them and the rest of the front end: what loads where, what makes a page load in full, and
 * what the set-up scripts must do so that a page put in place works like one loaded in full.
 */
final class InPlacePagesTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    public function testTheLayoutLoadsTheSwapOnlyWhenSignedIn(): void
    {
        $layout = self::read('app/Views/layout.php');
        self::assertMatchesRegularExpression("#<\\?php if \\(\\\$user\\): \\?><script src=\"<\\?= e\\(asset\\('/assets/js/skeleton\\.js'\\)\\) \\?>\"></script>\\s*<script src=\"<\\?= e\\(asset\\('/assets/js/swap\\.js'\\)\\) \\?>\"></script><\\?php endif; \\?>#", $layout);
        // Before motion.js, which leaves the progress bar to swap.js when it runs.
        self::assertLessThan(strpos($layout, "/assets/js/motion.js"), strpos($layout, "/assets/js/swap.js"));
        // The transition into the sign-in pages is for signed-out pages only.
        self::assertStringContainsString("<?php if (!\$user): ?><link rel=\"stylesheet\" href=\"<?= e(asset('/assets/css/page-transition.css')) ?>\"><?php endif; ?>", $layout);
    }

    /** A widget that runs for the whole visit must never be put in place, or left in place. */
    public function testPagesThatRunForTheWholeVisitLoadInFull(): void
    {
        $swap = self::read('public/assets/js/swap.js');
        preg_match("#var WHOLE = '([^']+)';#", $swap, $m);
        self::assertNotEmpty($m);
        foreach (['form[data-mock]', '[data-practice]', 'form[data-chunked-upload]', '[data-wizard]', '[data-live]'] as $widget) {
            self::assertStringContainsString($widget, $m[1], "$widget would keep running behind the next page");
        }
        // Content with a script of its own is loaded in full: its script was written to run once.
        self::assertStringContainsString("next.querySelector('script') || next.querySelector(WHOLE)", $swap);
        // Leaving such a page is a full load too.
        self::assertGreaterThanOrEqual(3, substr_count($swap, 'holdsWhole()'));
    }

    public function testFilesPrintsAndSignInAreOrdinaryLoads(): void
    {
        $swap = self::read('public/assets/js/swap.js');
        preg_match('#var SKIP = (/.+/i);#', $swap, $m);
        self::assertNotEmpty($m);
        $skip = substr($m[1], 0, -1) . 'i';
        foreach (['/login', '/logout', '/join', '/branding/logo.png', '/assets/css/base.css', '/api/palette', '/certificates/12/pdf', '/library/a/b/download',
            '/institutions/x/export', '/institutions/x/sittings/3', '/jobs/4/file.zip', '/x?format=csv'] as $path) {
            self::assertMatchesRegularExpression($skip, $path, "$path should be an ordinary load");
        }
        foreach (['/', '/settings/branding', '/library', '/jobs/4', '/platform/plans', '/activity?from=2026-01-01'] as $path) {
            self::assertDoesNotMatchRegularExpression($skip, $path, "$path should open in place");
        }
    }

    /**
     * Set-up that ran once on page load must also run on content put in place, and anything that
     * polls must stop once its page is gone (a poll that reloads would otherwise reload whatever
     * page the person has moved on to).
     */
    public function testSetUpRunsAgainOnContentPutInPlace(): void
    {
        $app = self::read('public/assets/js/app.js');
        self::assertStringContainsString("document.addEventListener('examlib:load'", $app);
        foreach (['data-job-progress', 'data-job-chip', 'data-live-log', 'data-doc-status', 'data-integrity-wait', 'data-toggle-panel', 'data-question-form', 'data-qr'] as $attr) {
            self::assertMatchesRegularExpression('#onEach\(\'[a-z]*\[' . preg_quote($attr, '#') . '\]#', $app, "$attr is set up only on the first page");
        }
        // Delegated, so a form put in place is covered without being set up.
        self::assertStringNotContainsString("querySelectorAll('form[data-confirm]')", $app);
        self::assertStringNotContainsString("querySelectorAll('form[data-once]')", $app);
        self::assertStringNotContainsString("querySelectorAll('select[data-autosubmit]')", $app);
        // A reload goes through reloadPage() (in place where it can be), never straight to the browser.
        self::assertStringNotContainsString('window.location.reload()', $app);
        foreach (['node', 'box', 'el'] as $who) {
            self::assertStringContainsString("if (!$who.isConnected) ", $app);
        }
        // The token is read when used: a page put in place can carry a new one.
        self::assertStringNotContainsString('var csrf =', $app);
        // A filter that sends itself goes through requestSubmit, which swap.js sees; submit() skips it.
        self::assertStringNotContainsString('sel.form.submit()', $app);

        foreach (['public/assets/js/data-table.js', 'public/assets/js/motion.js', 'public/assets/js/skeleton.js'] as $script) {
            self::assertStringContainsString("'examlib:load'", self::read($script), "$script does not set up content put in place");
        }
    }

    public function testEverySaveButtonSaysWhatItIsDoing(): void
    {
        $app = self::read('public/assets/js/app.js');
        self::assertStringContainsString('ExamLib.buttonBusy = function (btn, on)', $app);
        self::assertStringContainsString("save: 'Saving'", $app);
        self::assertStringContainsString("delete: 'Deleting'", $app);
        // swap.js uses the same spinner, and gives a refused form back usable.
        $swap = self::read('public/assets/js/swap.js');
        self::assertStringContainsString('ExamLib.buttonBusy(btn, on)', $swap);
        self::assertStringContainsString('ExamLib.release(form)', $swap);
    }

    /** An outline keeps no text, value, link or data: nothing a reader saw is written down. */
    public function testAnOutlineKeepsOnlyLayout(): void
    {
        $sk = self::read('public/assets/js/skeleton.js');
        self::assertStringContainsString("var KEEP_ATTR = ['class', 'colspan', 'rowspan'];", $sk);
        self::assertStringContainsString('t.replaceWith(grey(', $sk);
        // Outlines are filed under the page on screen, not the address Back has already moved to.
        self::assertStringContainsString('keyFor(main.dataset.page) !== keyFor(location.href)', $sk);
    }

    /** The CSP allows no inline style: nothing here may write one. */
    public function testNoInlineStyles(): void
    {
        foreach (['public/assets/js/swap.js', 'public/assets/js/skeleton.js'] as $script) {
            $text = self::read($script);
            self::assertStringNotContainsString('style=', $text, $script);
            self::assertStringNotContainsString('.style.', $text, $script);
        }
    }

    public function testMotionIsShortAndFollowsReduceMotion(): void
    {
        $css = self::read('public/assets/css/motion.css');
        // Content arrives readable and settles within 40 ms of stagger, never from nothing.
        self::assertStringContainsString('@keyframes settle-in { from { opacity: .5;', $css);
        preg_match_all('#\#page-body > \*[^{]*\{[^}]*animation-delay:\s*(\d+)ms#', $css, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $delay) {
            self::assertLessThanOrEqual(40, (int) $delay);
        }
        // Entrances never keep a transform (it would trap fixed dialogs inside the page).
        self::assertDoesNotMatchRegularExpression('#animation:[^;]*\b(both|forwards)\b#', preg_replace('#::view-transition[^{]*\{[^}]*\}#', '', $css) ?? '');
        // A figure is hidden only until motion.js has seen it, and only when motion is on.
        self::assertStringContainsString('html.count-wait .kpi-value:not([data-counted])', $css);
        self::assertStringContainsString("root.classList.add('count-wait')", self::read('public/assets/js/theme-init.js'));
        self::assertStringContainsString("root.classList.remove('count-wait')", self::read('public/assets/js/motion.js'));
        // Full loads cross-fade; not for a person who asked for less motion.
        self::assertStringContainsString('@view-transition { navigation: auto; }', $css);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce) { @view-transition { navigation: none; } }', $css);
        $pt = self::read('public/assets/css/page-transition.css');
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce) { @view-transition { navigation: none; } }', $pt);
    }

    /** A view transition name may appear once on a page, or the browser skips the transition. */
    public function testTransitionNamesAreUniqueOnEachPage(): void
    {
        $pt = self::read('public/assets/css/page-transition.css');
        // The footer repeats the brand, so the names are scoped to the top bar.
        self::assertStringContainsString('.site-bar .site-brand .brand-logo', $pt);
        self::assertStringNotContainsString("\n.site-brand .brand-logo", $pt);
        self::assertStringContainsString('class="site-brand-name"', self::read('app/Views/layout.php'));
    }
}
