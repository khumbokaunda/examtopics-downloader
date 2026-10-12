<?php

declare(strict_types=1);

namespace App\Services\Documents;

/**
 * Document variants. All are generated from the same canonical dataset; none modifies it.
 * In answer-hidden variants, everything that reveals the answer is withheld from the
 * question pages: the answer, its explanation, the community votes and the discussion
 * (comments routinely state the answer). The "_key" variant puts answers in a key at the end.
 */
final class Variant
{
    public const ALL = [
        'study' => [
            'label' => 'Questions with answers & explanations',
            'file' => 'Questions_Answers',
            'answers' => true, 'comments' => false, 'answer_key' => false,
        ],
        'discussions' => [
            'label' => 'Questions with answers & discussions',
            'file' => 'Discussions',
            'answers' => true, 'comments' => true, 'answer_key' => false,
        ],
        'practice' => [
            'label' => 'Practice mode (answers hidden)',
            'file' => 'Practice_Mode',
            'answers' => false, 'comments' => false, 'answer_key' => false,
        ],
        'practice_key' => [
            'label' => 'Practice mode with answer key at the end',
            'file' => 'Practice_Answer_Key',
            'answers' => false, 'comments' => false, 'answer_key' => true,
        ],
    ];

    /** @param array{label:string,file:string,answers:bool,comments:bool,answer_key:bool} $def */
    private function __construct(public readonly string $key, private array $def)
    {
    }

    public static function get(string $key): self
    {
        if (!isset(self::ALL[$key])) {
            throw new \InvalidArgumentException('Unknown document variant.');
        }
        return new self($key, self::ALL[$key]);
    }

    public function label(): string
    {
        return $this->def['label'];
    }

    public function fileLabel(): string
    {
        return $this->def['file'];
    }

    public function showAnswers(): bool
    {
        return $this->def['answers'];
    }

    public function showComments(): bool
    {
        return $this->def['comments'];
    }

    public function answerKey(): bool
    {
        return $this->def['answer_key'];
    }

    /** @return array<string,mixed> */
    public function options(): array
    {
        return ['variant' => $this->key, 'answers' => $this->showAnswers(), 'comments' => $this->showComments(),
                'answer_key' => $this->answerKey()];
    }
}
