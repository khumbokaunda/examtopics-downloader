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

    /** Options that are pictures: older collections kept them with the question's images. */
    public function testPictureChoicesGetTheirImages(): void
    {
        $q = QuestionView::normalize([
            'text' => "Which JSON script is properly formatted?\n\nA.\n\nB.\n\nC.\n\nD.",
            'choices' => [], 'question_images' => ['exhibit.png', 'a.png', 'b.png', 'c.png', 'd.png'],
        ]);
        self::assertSame('Which JSON script is properly formatted?', $q['text']);
        self::assertSame(['exhibit.png'], $q['question_images']);
        self::assertSame([['a.png'], ['b.png'], ['c.png'], ['d.png']], array_column($q['choices'], 'images'));
        // Choices with text keep the images where they are.
        $text = QuestionView::normalize(['text' => "Q\nA. one\nB. two", 'choices' => [], 'question_images' => ['x.png', 'y.png']]);
        self::assertSame(['x.png', 'y.png'], $text['question_images']);
    }

    public function testDragAndDropQuestionsAreRecognised(): void
    {
        $dd = ['text' => "DRAG DROP -\nDrag and drop the descriptions from the left onto the protocols on the right.\nSelect and Place:", 'answer' => ''];
        self::assertSame('drag-drop', QuestionView::kind($dd));
        self::assertStringStartsWith('Drag and drop question: the answer is a picture', (string) QuestionView::pictureAnswerNote($dd));
        self::assertSame('hotspot', QuestionView::kind(['text' => "HOTSPOT -\nRefer to the exhibit.\nHot Area:"]));
        self::assertNull(QuestionView::kind(['text' => 'Which protocol is described?']));
        self::assertNull(QuestionView::pictureAnswerNote($dd + ['answer_images' => ['answer.png']]), 'an answer picture is shown instead');
    }

    /** Old collections kept the discussion as one text block: it is read back into comments. */
    public function testOldCommentTextBecomesCommentsAndAVote(): void
    {
        $text = "bmoha017\nMost Recent 1 year, 1 months, 20 days ago\n\nSelected Answer: C A passive interface stops OSPF.\n\nupvoted 1 time\n\n"
            . "Submit Cancel\n\n...\n\nMegon\n2 years ago\n\nD is the answer.\n\nupvoted 2 times\n\nSubmit Cancel\n\n"
            . "kaz\nHighly Voted 3 years ago\n\nSelected Answer: D\nApplying a filter is right.\n\nupvoted 9 times\n\nSubmit Cancel\n";
        $q = QuestionView::normalize(['text' => 'Q?', 'answer' => '', 'community_votes' => [], 'comments' => [], 'comments_text' => $text,
            'choices' => [['letter' => 'A', 'text' => 'x'], ['letter' => 'B', 'text' => 'y']]]);
        $c = QuestionView::comments($q);
        self::assertSame(['bmoha017', 'Megon', 'kaz'], array_column($c, 'author'));
        self::assertSame(['C', '', 'D'], array_column($c, 'selected'));
        self::assertSame([1, 2, 9], array_column($c, 'upvotes'));
        self::assertSame('A passive interface stops OSPF.', $c[0]['content']);
        self::assertSame('Highly Voted', $c[2]['badge']);
        // Votes as ExamTopics counts them: D = 1 + 9, C = 1 + 1.
        self::assertSame('D', $q['answer']);
        self::assertSame('community', $q['answer_source']);
        self::assertSame([['answer' => 'D', 'count' => 10, 'most_voted' => true], ['answer' => 'C', 'count' => 2, 'most_voted' => false]], $q['community_votes']);
        // Text in another shape is kept as one block.
        self::assertCount(1, QuestionView::comments(['comments_text' => "alice\n1 upvotes\nI think it is A.\nBecause of the RFC."]));
    }
}
