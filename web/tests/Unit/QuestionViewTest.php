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

    public function testScrapedTextIsCleaned(): void
    {
        self::assertSame('Here’s how each mode works: “On demand” – café', QuestionView::cleanText("Hereâ€™s how each mode works: â€œOn demandâ€\u{009D} â€“ cafÃ©"));
        self::assertSame("A & B 'x' <tag> a b", QuestionView::cleanText('A &amp; B &#39;x&#39; &amp;lt;tag&amp;gt; a&nbsp;b'));
        self::assertSame('中文 costs €5™ and é', QuestionView::cleanText('中文 costs €5™ and é'), 'correct text is left alone');
        self::assertSame("One\n\nTwo", QuestionView::cleanText("One  \n\n\n\nTwo\n"));
        // Punctuation read as Hebrew in ExamTopics' own text.
        self::assertSame("APs are \"lightweight,\" so it\u{2019}s managed \u{2022} centrally",
            QuestionView::cleanText("APs are \u{05D2}\u{20AC}lightweight,\u{05D2}\u{20AC} so it\u{05D2}\u{20AC}\u{2122}s managed \u{05D2}\u{20AC}\u{00A2} centrally"));
        $c = QuestionView::comments(['comments' => [['author' => 'x', 'content' => "Dead Peer Detection checks the peer.\n\n\nHereâ€™s how:", 'upvotes' => 1]]]);
        self::assertSame("Dead Peer Detection checks the peer.\n\nHere’s how:", $c[0]['content']);
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

    /**
     * A real discussion: two voters, both limited to one letter, and comments that argue for
     * ABC and ABCD. The vote says A; the hints say why it needs checking.
     */
    public function testDoubtfulAnswersAreFlaggedWithWhatTheCommentsSay(): void
    {
        $comments = [
            ["As respostas corretas são A, B e C.\nD. LSA não é um tipo de pacote", 2, ''],
            ['A,B,C. D is not a packet, LSA is routing information.', 1, ''],
            ["All are correct.\nList of OSPF packets:\nHello", 1, 'A'],
            ['Aaaaaa', 1, ''],
            ['All the given options are correct.', 1, ''],
            ['The answer is ABCD', 2, ''],
            ['All the above', 2, ''],
            ['A,B,C,D', 3, ''],
            ["A B and C are correct answers.\nHELLO: Used to discover neighbors.", 2, 'A'],
        ];
        $q = QuestionView::normalize([
            'text' => 'Which of the following packets are OSPF packets?', 'topic' => 1, 'question_number' => 12,
            'choices' => [['letter' => 'A', 'text' => 'HELLO'], ['letter' => 'B', 'text' => 'LSR'], ['letter' => 'C', 'text' => 'LSU'], ['letter' => 'D', 'text' => 'LSA']],
            'answer' => '', 'community_votes' => [],
            'comments' => array_map(static fn(array $c) => ['author' => 'u', 'content' => $c[0], 'upvotes' => $c[1], 'selected_answer' => $c[2]], $comments),
        ]);
        self::assertSame('A: 5 (100%) – most voted (counted from the comments)', QuestionView::votesLine($q));
        self::assertSame([
            'The question asks for more than one answer, but the answer has one letter.',
            'Only 2 people voted.',
            'Most comments that name an answer say ABCD (5 of 8).',
        ], QuestionView::doubts($q));
        self::assertSame('ABCD (5), ABC (3)', QuestionView::claimsLine($q));

        // Corrected in the reader: the hints go, and the collected answer is still named.
        self::assertSame('1-12', QuestionView::key($q));
        [$fixed] = QuestionView::applyEdits([$q], ['1-12' => ['answer' => 'ABC', 'note' => 'LSA is not a packet type.']]);
        self::assertSame('ABC (corrected; the collected answer was A)', QuestionView::answerLine($fixed));
        self::assertSame('LSA is not a packet type.', $fixed['edit']['note']);
        self::assertSame([], QuestionView::doubts($fixed));
        // Saving the collected letters unchanged marks the answer as checked.
        [$checked] = QuestionView::applyEdits([$q], ['1-12' => ['answer' => 'A', 'note' => null]]);
        self::assertStringEndsWith('; checked)', (string) QuestionView::answerLine($checked));
        self::assertSame([], QuestionView::doubts($checked));
    }

    public function testCommentsNameAnswersInManyWays(): void
    {
        $l = ['A', 'B', 'C', 'D', 'E'];
        foreach ([
            'I think it is B.' => 'B', 'Correct answer: C' => 'C', 'go with D because it is newer' => 'D', 'E' => 'E',
            'AD for sure.' => 'AD', 'C is correct' => 'C', 'Answer is B, D' => 'BD',
            'Agreed.' => null, 'A switch forwards frames; AD of OSPF is 110' => null, 'C and D are wrong' => null,
        ] as $text => $want) {
            self::assertSame($want, QuestionView::claimIn($text, $l), $text);
        }
        // Wording that asks for a number of answers.
        $q = ['text' => 'Which two statements are true? (Choose two.)', 'answer' => 'A', 'answer_source' => 'suggested', 'suggested_answer' => 'A',
            'choices' => [['letter' => 'A', 'text' => 'x'], ['letter' => 'B', 'text' => 'y'], ['letter' => 'C', 'text' => 'z']]];
        self::assertSame(['The question asks for two answers, but the answer has 1.'], QuestionView::doubts($q));
        self::assertSame([], QuestionView::doubts(['text' => 'Which of the following is true when links are up?'] + $q));
    }

    /** The cached dataset marks each question picture's place with "//IMG//", in order. */
    public function testImageMarksPlacePicturesInTheText(): void
    {
        $choices = [['letter' => 'A', 'text' => 'x'], ['letter' => 'B', 'text' => 'y']];
        $q = QuestionView::normalize(['text' => "//IMG//\n\nRefer to the exhibit. Which configuration works?",
            'question_images' => ['exhibit.png'], 'choices' => $choices]);
        self::assertSame('Refer to the exhibit. Which configuration works?', $q['text'], 'the mark is not shown');
        self::assertSame([['image' => 0], ['text' => 'Refer to the exhibit. Which configuration works?']], QuestionView::questionSegments($q));

        $lab = QuestionView::normalize(['text' => "SIMULATION\nTopology:\n//IMG//\nTasks:\n1. Configure OSPF\n//IMG//",
            'question_images' => ['topology.png', 'tasks.png'], 'choices' => []]);
        self::assertSame([['text' => "SIMULATION\nTopology:"], ['image' => 0], ['text' => "Tasks:\n1. Configure OSPF"], ['image' => 1]],
            QuestionView::questionSegments($lab));

        // Without marks, pictures follow the text as before.
        self::assertSame([['text' => 'Q?'], ['image' => 0]], QuestionView::questionSegments(['text' => 'Q?', 'question_images' => ['a.png']]));
    }
}
