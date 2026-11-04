<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EditionMatcher;
use PHPUnit\Framework\TestCase;

final class EditionMatcherTest extends TestCase
{
    /** @param array<string,string> $choices letter => text */
    private static function q(int $n, string $text, array $choices): array
    {
        $out = ['number' => $n, 'topic' => 1, 'question_number' => $n, 'text' => $text, 'choices' => []];
        foreach ($choices as $l => $t) {
            $out['choices'][] = ['letter' => $l, 'text' => $t];
        }
        return $out;
    }

    public function testQuestionsAreFoundExactlyOrNearly(): void
    {
        $abc = ['A' => 'Amazon S3', 'B' => 'Amazon EBS', 'C' => 'Amazon EFS'];
        $there = new EditionMatcher([
            self::q(7, 'A company needs durable object storage for backups. Which service should it use?', $abc),
            self::q(8, 'A company needs a shared file system for many instances. Which service should it use?', $abc),
            self::q(9, 'Which service gives block storage to one instance?', $abc),
        ]);
        $exact = $there->match(self::q(1, 'A company needs  durable object storage for backups.   which SERVICE should it use', $abc));
        self::assertSame(['1-7', true], [$exact['key'], $exact['exact']], 'case, spacing and punctuation do not matter');

        $near = $there->match(self::q(2, 'A company needs a shared file system for many EC2 instances. Which service should it use?', $abc));
        self::assertSame(['1-8', false], [$near['key'], $near['exact']], 'a word added is still the same question');
        self::assertGreaterThanOrEqual(EditionMatcher::NEAR, $near['score']);

        self::assertNull($there->match(self::q(3, 'Which service runs containers without servers?', ['A' => 'AWS Fargate', 'B' => 'Amazon EC2'])));
    }

    public function testAnswersAreCarriedByTheWordingOfTheirChoices(): void
    {
        $here = self::q(1, 'Which two?', ['A' => 'OSPF', 'B' => 'BGP', 'C' => 'RIP']);
        $reordered = self::q(5, 'Which two?', ['A' => 'RIP', 'B' => 'OSPF', 'C' => 'bgp.']);
        self::assertSame('BC', EditionMatcher::mapLetters('AB', $here, $reordered), 'OSPF is B there and BGP is C');
        self::assertSame('B', EditionMatcher::mapLetters('A', $here, $reordered));
        self::assertNull(EditionMatcher::mapLetters('C', $here, self::q(5, 'Which two?', ['A' => 'OSPF', 'B' => 'BGP', 'C' => 'IS-IS'])),
            'a choice that is not there is not guessed');
        self::assertFalse(EditionMatcher::same($here, $reordered), 'a question edit is carried only to one that reads the same');
    }
}
