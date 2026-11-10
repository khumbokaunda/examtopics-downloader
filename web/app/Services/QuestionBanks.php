<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\QuestionBankRepository;
use App\Repositories\StructureRepository;
use RuntimeException;

/**
 * The rules for question banks: who may see and write in a bank, what a valid question is, and
 * how a question moves from draft to published.
 *
 *  draft --submit--> review --approve--> approved --publish--> published
 *    ^                  |                    |
 *    +---- return ------+--------------------+        (any edit makes the latest version a draft again)
 *
 * Publishing points the question's published version at its latest version; assessments use the
 * published version, so an edit under review never changes what candidates see. Archiving takes
 * a question out of new assessments without losing it.
 */
final class QuestionBanks
{
    public const TYPES = ['single' => 'Single choice', 'multiple' => 'Multiple select', 'true_false' => 'True or false'];
    public const DIFFICULTIES = ['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard'];
    public const STATUSES = ['draft' => 'Draft', 'review' => 'In review', 'approved' => 'Approved', 'published' => 'Published', 'archived' => 'Archived'];
    public const MAX_CHOICES = 10;
    public const MAX_TEXT = 20000;
    public const MAX_IMAGES = 10;

    public function __construct(private QuestionBankRepository $repo, private StructureRepository $structure, private AuditLog $audit)
    {
    }

    public function repo(): QuestionBankRepository
    {
        return $this->repo;
    }

    // Access ---------------------------------------------------------------------------------

    /** @param array<string,mixed> $m membership @param array<string,mixed> $bank */
    public function canSee(array $m, array $bank): bool
    {
        return InstitutionAccess::can($m, 'question_banks.view') || InstitutionAccess::can($m, 'question_banks.edit')
            || InstitutionAccess::can($m, 'question_banks.publish') || $this->isAuthor($m, $bank);
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $bank */
    public function canWrite(array $m, array $bank): bool
    {
        return InstitutionAccess::can($m, 'question_banks.edit') || (InstitutionAccess::can($m, 'question_banks.create') && $this->isAuthor($m, $bank));
    }

    /** Rename, archive, topics and editors: the bank's creator, or question_banks.edit. @param array<string,mixed> $m @param array<string,mixed> $bank */
    public function canManage(array $m, array $bank): bool
    {
        return InstitutionAccess::can($m, 'question_banks.edit')
            || (InstitutionAccess::can($m, 'question_banks.create') && (int) ($bank['created_by'] ?? 0) === (int) $m['id']);
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $bank */
    private function isAuthor(array $m, array $bank): bool
    {
        return (int) ($bank['created_by'] ?? 0) === (int) $m['id'] || $this->repo->isEditor((int) $m['institution_id'], (int) $bank['id'], (int) $m['id']);
    }

    // Banks and topics -----------------------------------------------------------------------

    /** @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed>|null $bank @return string the bank's uuid */
    public function saveBank(array $i, array $m, ?array $bank, string $name, string $subject, string $description, string $programmeUuid): string
    {
        $iid = (int) $i['id'];
        $name = InstitutionStructure::cleanName($name, 160);
        $subject = trim((string) preg_replace('/\s+/u', ' ', $subject));
        $description = trim($description);
        if (mb_strlen($subject) > 120 || mb_strlen($description) > 1000) {
            throw new RuntimeException('The subject has at most 120 characters and the description at most 1,000.');
        }
        $programme = null;
        if ($programmeUuid !== '') {
            $programme = $this->structure->programme($iid, $programmeUuid) ?? throw new RuntimeException('Choose one of the institution\'s programmes.');
        }
        if ($this->repo->bankNameTaken($iid, $name, (int) ($bank['id'] ?? 0))) {
            throw new RuntimeException('There is already a question bank called ' . $name . '.');
        }
        $uuid = $this->repo->saveBank($iid, $bank, $name, $subject !== '' ? $subject : null, $description !== '' ? $description : null,
            $programme !== null ? (int) $programme['id'] : null, $bank === null ? (int) $m['id'] : null);
        $this->record($bank === null ? 'institution.bank_created' : 'institution.bank_changed', $i, ['bank' => $name]);
        return $uuid;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $bank */
    public function setArchived(array $i, array $bank, bool $archived): void
    {
        $this->repo->setBankStatus((int) $i['id'], (int) $bank['id'], $archived ? 'archived' : 'active');
        $this->record($archived ? 'institution.bank_archived' : 'institution.bank_restored', $i, ['bank' => $bank['name']]);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $bank @param list<array<string,mixed>> $people active memberships of this institution */
    public function setEditors(array $i, array $bank, array $people): void
    {
        foreach ($people as $p) {
            if ($p['status'] !== 'active' || !InstitutionAccess::can($p, 'question_banks.create') && !InstitutionAccess::can($p, 'question_banks.edit')) {
                throw new RuntimeException(\App\Services\Access::displayName($p) . '\'s role does not include writing questions.');
            }
        }
        $this->repo->setEditors((int) $i['id'], (int) $bank['id'], array_map(static fn(array $p): int => (int) $p['id'], $people));
        $this->record('institution.bank_editors_set', $i, ['bank' => $bank['name'], 'editors' => array_map(static fn(array $p): string => (string) $p['username'], $people)]);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $bank */
    public function addTopic(array $i, array $bank, string $name, string $parentUuid): void
    {
        $iid = (int) $i['id'];
        $name = InstitutionStructure::cleanName($name);
        $parent = null;
        if ($parentUuid !== '') {
            $parent = $this->repo->topic($iid, (int) $bank['id'], $parentUuid);
            if ($parent === null || $parent['parent_id'] !== null) {
                throw new RuntimeException('A subtopic goes under one of the bank\'s topics.');
            }
        }
        if ($this->repo->topicNameTaken($iid, (int) $bank['id'], $parent !== null ? (int) $parent['id'] : null, $name)) {
            throw new RuntimeException('There is already a topic called ' . $name . ' there.');
        }
        $this->repo->addTopic($iid, (int) $bank['id'], $parent !== null ? (int) $parent['id'] : null, $name);
        $this->record('institution.topic_added', $i, ['bank' => $bank['name'], 'name' => $name]);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $bank @param array<string,mixed> $topic */
    public function renameTopic(array $i, array $bank, array $topic, string $name): void
    {
        $name = InstitutionStructure::cleanName($name);
        if ($this->repo->topicNameTaken((int) $i['id'], (int) $bank['id'], $topic['parent_id'] !== null ? (int) $topic['parent_id'] : null, $name, (int) $topic['id'])) {
            throw new RuntimeException('There is already a topic called ' . $name . ' there.');
        }
        $this->repo->renameTopic((int) $i['id'], (int) $topic['id'], $name);
        $this->record('institution.topic_renamed', $i, ['bank' => $bank['name'], 'from' => $topic['name'], 'name' => $name]);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $bank @param array<string,mixed> $topic */
    public function deleteTopic(array $i, array $bank, array $topic): void
    {
        if ($this->repo->topicUsed((int) $i['id'], (int) $topic['id'])) {
            throw new RuntimeException($topic['name'] . ' is used by questions (or has subtopics), so it stays. Questions keep the topic they were written with.');
        }
        $this->repo->deleteTopic((int) $i['id'], (int) $topic['id']);
        $this->record('institution.topic_removed', $i, ['bank' => $bank['name'], 'name' => $topic['name']]);
    }

    // Questions ------------------------------------------------------------------------------

    /**
     * The posted question, cleaned, or why it cannot be saved.
     *
     * @param array<string,mixed> $in posted fields: type, text, choice_text (list), correct (list of indexes), tf_answer, marks,
     *        partial_credit, difficulty, topic, explanation, reference
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    public function validate(int $iid, int $bankId, array $in): array
    {
        $errors = [];
        $type = (string) ($in['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            $errors[] = 'Choose the kind of question.';
            $type = 'single';
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['text'] ?? '')));
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            $errors[] = 'Write the question (up to ' . number_format(self::MAX_TEXT) . ' characters).';
        }
        $choices = [];
        if ($type === 'true_false') {
            $answer = (string) ($in['tf_answer'] ?? '');
            if (!in_array($answer, ['T', 'F'], true)) {
                $errors[] = 'Say whether the statement is true or false.';
            }
            $choices = [['key' => 'T', 'text' => 'True', 'correct' => $answer === 'T'], ['key' => 'F', 'text' => 'False', 'correct' => $answer === 'F']];
        } else {
            $correct = array_map('strval', (array) ($in['correct'] ?? []));
            $letters = range('A', 'J');
            foreach (array_slice((array) ($in['choice_text'] ?? []), 0, self::MAX_CHOICES, true) as $idx => $t) {
                $t = trim(str_replace(["\r\n", "\r"], "\n", (string) $t));
                if ($t === '') {
                    continue;
                }
                if (mb_strlen($t) > 4000) {
                    $errors[] = 'A choice has at most 4,000 characters.';
                }
                $choices[] = ['key' => $letters[count($choices)], 'text' => $t, 'correct' => in_array((string) $idx, $correct, true)];
            }
            $right = count(array_filter($choices, static fn(array $c): bool => $c['correct']));
            if (count($choices) < 2) {
                $errors[] = 'Give at least two choices.';
            } elseif ($type === 'single' && $right !== 1) {
                $errors[] = 'Mark exactly one choice as the right answer.';
            } elseif ($type === 'multiple' && $right < 1) {
                $errors[] = 'Mark at least one choice as right.';
            }
            if (count(array_unique(array_map(static fn(array $c): string => mb_strtolower($c['text']), $choices))) !== count($choices)) {
                $errors[] = 'Two choices read the same.';
            }
        }
        $marksRaw = trim((string) ($in['marks'] ?? ''));
        $marks = is_numeric($marksRaw) ? round((float) $marksRaw, 2) : 0.0;
        if ($marks <= 0 || $marks > 1000) {
            $errors[] = 'Give the marks: more than 0 and at most 1,000.';
        }
        $difficulty = (string) ($in['difficulty'] ?? '');
        if (!isset(self::DIFFICULTIES[$difficulty])) {
            $errors[] = 'Choose how hard the question is.';
        }
        $topicId = null;
        if ((string) ($in['topic'] ?? '') !== '') {
            $t = $this->repo->topic($iid, $bankId, (string) $in['topic']);
            if ($t === null) {
                $errors[] = 'Choose one of the bank\'s topics.';
            } else {
                $topicId = (int) $t['id'];
            }
        }
        $explanation = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['explanation'] ?? '')));
        $reference = trim((string) ($in['reference'] ?? ''));
        if (mb_strlen($explanation) > 5000 || mb_strlen($reference) > 1000) {
            $errors[] = 'The explanation has at most 5,000 characters and the reference at most 1,000.';
        }
        return [[
            'type' => $type, 'text' => $text, 'choices' => $choices, 'marks' => number_format($marks, 2, '.', ''),
            'partial_credit' => $type === 'multiple' && ($in['partial_credit'] ?? '') === '1', 'difficulty' => $difficulty, 'topic_id' => $topicId,
            'explanation' => $explanation !== '' ? $explanation : null, 'reference' => $reference !== '' ? $reference : null, 'images' => [],
        ], array_values(array_unique($errors))];
    }

    /**
     * Save a question as its next version (or create it). A version the same as the latest one
     * is not written again.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $bank @param array<string,mixed>|null $question
     * @param array<string,mixed> $v from validate(), with images filled in
     * @return array{uuid:string,version:int,changed:bool}
     */
    public function saveQuestion(array $i, array $m, array $bank, ?array $question, array $v, bool $record = true): array
    {
        if ($bank['status'] !== 'active') {
            throw new RuntimeException('An archived bank takes no new questions or edits. Restore it first.');
        }
        if ($question !== null && $question['status'] === 'archived') {
            throw new RuntimeException('Restore the question before editing it.');
        }
        if ($question !== null && self::sameContent($question, $v)) {
            return ['uuid' => (string) $question['uuid'], 'version' => (int) $question['latest_version'], 'changed' => false];
        }
        $done = $this->repo->writeVersion((int) $i['id'], (int) $bank['id'], $question, $v, (int) $m['id']);
        if ($record) { // an import records itself once, not once per question
            $this->record($question === null ? 'institution.question_created' : 'institution.question_edited', $i,
                ['bank' => $bank['name'], 'version' => $done['version'], 'question' => self::excerpt($v['text'])]);
        }
        return $done + ['changed' => true];
    }

    /**
     * Move a question along its review: submit, approve, publish, return, archive or restore.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $bank @param array<string,mixed> $q from question()
     */
    public function transition(array $i, array $m, array $bank, array $q, string $action, string $note = ''): string
    {
        $status = (string) $q['status'];
        $publisher = InstitutionAccess::can($m, 'question_banks.publish');
        $writer = $this->canWrite($m, $bank);
        $iid = (int) $i['id'];
        $note = mb_substr(trim($note), 0, 1000);
        [$to, $allowed, $published] = match ($action) {
            'submit' => ['review', $writer && $status === 'draft', null],
            'approve' => ['approved', $publisher && $status === 'review', null],
            'publish' => ['published', $publisher && in_array($status, ['review', 'approved'], true), (int) $q['latest_version']],
            'return' => ['draft', $publisher && in_array($status, ['review', 'approved'], true), null],
            'archive' => ['archived', ($writer || $publisher) && $status !== 'archived', null],
            'restore' => [(int) $q['published_version'] === (int) $q['latest_version'] ? 'published' : 'draft', ($writer || $publisher) && $status === 'archived', null],
            default => ['', false, null],
        };
        if (!$allowed) {
            throw new RuntimeException('That cannot be done to a question that is "' . (self::STATUSES[$status] ?? $status) . '", or not by you.');
        }
        if ($action === 'return' && $note === '') {
            throw new RuntimeException('Say what needs changing. The author sees it on the question.');
        }
        if ($bank['status'] !== 'active' && !in_array($action, ['archive', 'restore'], true)) {
            throw new RuntimeException('The bank is archived. Restore it first.');
        }
        $this->repo->setStatus($iid, (int) $q['id'], $to, $published, $action === 'return' ? $note : null);
        $done = ['submit' => 'institution.question_submitted', 'approve' => 'institution.question_approved', 'publish' => 'institution.question_published',
            'return' => 'institution.question_returned', 'archive' => 'institution.question_archived', 'restore' => 'institution.question_restored'];
        $this->record($done[$action], $i, ['bank' => $bank['name'], 'version' => (int) $q['latest_version'],
            'question' => self::excerpt((string) $q['text'])] + ($note !== '' && $action === 'return' ? ['note' => $note] : []));
        return $to;
    }

    /** Whether a question's latest version already says what $v says. @param array<string,mixed> $q @param array<string,mixed> $v */
    public static function sameContent(array $q, array $v): bool
    {
        $images = (array) (json_decode((string) ($q['images'] ?? ''), true) ?: []);
        return $q['type'] === $v['type'] && $q['text'] === $v['text'] && (array) json_decode((string) $q['choices'], true) === $v['choices']
            && $images === $v['images'] && number_format((float) $q['marks'], 2, '.', '') === $v['marks'] && (bool) $q['partial_credit'] === $v['partial_credit']
            && $q['difficulty'] === $v['difficulty'] && ($q['topic_id'] !== null ? (int) $q['topic_id'] : null) === $v['topic_id']
            && $q['explanation'] === $v['explanation'] && $q['reference'] === $v['reference'];
    }

    public static function excerpt(string $text, int $length = 80): string
    {
        $one = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($one) > $length ? mb_substr($one, 0, $length - 1) . '…' : $one;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $details */
    public function record(string $action, array $i, array $details): void
    {
        $this->audit->record($action, ['institution' => (string) $i['name']] + $details, 'institution', (string) $i['uuid']);
    }
}
