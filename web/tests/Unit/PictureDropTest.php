<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pictures dropped or pasted into the question editor (app.js). The browser does the work, so
 * what is held here is the contract: every picture field takes a drop, a paste reaches them, and
 * the preview stays inside the Content Security Policy.
 */
final class PictureDropTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    public function testEveryPictureFieldInTheEditorTakesADropOrAPaste(): void
    {
        $view = self::read('app/Views/viewer/question_edit.php');
        // The rows of "Pictures in place of words", and both "Add a picture" fields.
        self::assertStringContainsString('<div class="place-row<?= $spare ? \' is-spare\' : \'\' ?>" data-picture-zone', $view);
        self::assertStringContainsString('<div class="picture-zone" data-picture-zone>', $view);
        self::assertSame(1, substr_count($view, 'class="picture-zone" data-picture-zone'), 'inside the loop over question and answer');
        self::assertStringContainsString('choose, drop or paste one', $view);

        $app = self::read('public/assets/js/app.js');
        self::assertStringContainsString("onEach('[data-picture-zone]'", $app, 'set up again on a page put in place');
        self::assertStringContainsString("document.addEventListener('paste'", $app);
        self::assertStringContainsString("zone.addEventListener('drop'", $app);
        // A dropped file is put into the field itself, so the save is the ordinary form post.
        self::assertStringContainsString('input.files = dt.files;', $app);
        // Only the kinds the server accepts.
        self::assertStringContainsString("var PICTURE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];", $app);
        // Text pasted into a text box stays text.
        self::assertStringContainsString("indexOf.call(cd.types, 'text/plain') !== -1) return;", $app);
    }

    /** The policy allows data: pictures but not blob: ones, so the preview reads the file. */
    public function testThePreviewStaysInsideThePolicy(): void
    {
        $app = self::read('public/assets/js/app.js');
        self::assertStringContainsString('reader.readAsDataURL(file);', $app);
        self::assertStringNotContainsString('createObjectURL', $app);
        $kernel = self::read('app/Core/Kernel.php');
        self::assertStringContainsString("img-src 'self' data:", $kernel);
        self::assertStringNotContainsString('blob:', $kernel);
    }
}
