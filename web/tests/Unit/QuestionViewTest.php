<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Documents\QuestionView;
use PHPUnit\Framework\TestCase;

final class QuestionViewTest extends TestCase
{
    /** Old collections kept the choices in the text, with the sign-in box at the end. */
    public function testChoicesStoredInTheTextAreSplitOut(): void
    {
        $q = QuestionView::normalize([
            'text' => "What is the difference between a RIB and a FIB?\n\n A.\n The FIB is populated based on RIB content.\n B.\n"
                . " The RIB maintains a mirror image of the FIB.\n C.\n The RIB is used to make IP source prefix-based switching decisions.\n"
                . " D.\n The FIB is where all IP routing information is stored.\n\nForgot my password",
            'choices' => [],
        ]);
        self::assertSame('What is the difference between a RIB and a FIB?', $q['text']);
        self::assertSame(['A', 'B', 'C', 'D'], array_column($q['choices'], 'letter'));
        self::assertSame('The FIB is populated based on RIB content.', $q['choices'][0]['text']);
        self::assertSame('The FIB is where all IP routing information is stored.', $q['choices'][3]['text'], 'the sign-in text is dropped');

        // Proper data is left alone.
        $ok = ['text' => "Stem\nline two", 'choices' => [['letter' => 'A', 'text' => 'x'], ['letter' => 'B', 'text' => 'y']]];
        self::assertSame($ok, QuestionView::normalize($ok));
        self::assertSame(["Para one<br>\nline", 'Para two'], QuestionView::paragraphs("Para one\nline\n\nPara two"));
    }
}
