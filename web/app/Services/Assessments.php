<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AssessmentRepository;
use App\Repositories\QuestionBankRepository;
use App\Repositories\StructureRepository;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * The rules for building assessments: who may see, edit, assign and publish one; what makes one
 * ready to publish; and how a candidate's form is drawn from it.
 *
 * Lifecycle: draft (edited freely) -> published (fixed: chosen questions are pinned to the
 * versions published at that moment) -> closed -> archived. A published assessment can go back to
 * draft only while nobody has sat it, and an archived one that was sat is restored as closed.
 */
final class Assessments
{
    public const TYPES = [
        'practice' => 'Practice assessment',
        'diagnostic' => 'Diagnostic assessment',
        'readiness' => 'Readiness assessment',
        'formative' => 'Formative assessment',
        'summative' => 'Summative assessment',
        'formal' => 'Formal examination',
    ];
    public const REVIEW_POLICIES = [
        'none' => 'Nothing: the result is kept from the candidate',
        'score' => 'Their score only',
        'answers' => 'Their score, answers, the right answers and explanations',
    ];
    public const STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'closed' => 'Closed', 'archived' => 'Archived'];
    public const MAX_QUESTIONS = 300;

    public function __construct(
        private AssessmentRepository $repo,
        private QuestionBankRepository $banks,
        private QuestionBanks $bankRules,
        private StructureRepository $structure,
        private AuditLog $audit,
    ) {
    }

    public function repo(): AssessmentRepository
    {
        return $this->repo;
    }

    // Access ---------------------------------------------------------------------------------

    /** @param array<string,mixed> $m @param array<string,mixed> $a */
    public function canSee(array $m, array $a): bool
    {
        return InstitutionAccess::can($m, 'assessments.view') || InstitutionAccess::can($m, 'assessments.schedule')
            || ((int) ($a['created_by'] ?? 0) === (int) $m['id'] && InstitutionAccess::can($m, 'assessments.create'));
    }

    /** Settings, questions and blueprints, while a draft. @param array<string,mixed> $m @param array<string,mixed> $a */
    public function canEdit(array $m, array $a): bool
    {
        return $a['status'] === 'draft' && InstitutionAccess::can($m, 'assessments.create')
            && ((int) ($a['created_by'] ?? 0) === (int) $m['id'] || InstitutionAccess::can($m, 'assessments.view'));
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $a */
    public function canAssign(array $m, array $a): bool
    {
        return in_array($a['status'], ['draft', 'published'], true) && InstitutionAccess::can($m, 'assessments.assign') && $this->canSee($m, $a);
    }

    // Building -------------------------------------------------------------------------------

    /** @param array<string,mixed> $i @param array<string,mixed> $m @return string the new assessment's uuid */
    public function create(array $i, array $m, string $title, string $type): string
    {
        $title = InstitutionStructure::cleanName($title, 200);
        if (!isset(self::TYPES[$type])) {
            throw new RuntimeException('Choose the kind of assessment.');
        }
        $uuid = $this->repo->create((int) $i['id'], $title, $type, (int) $m['id']);
        $this->record('institution.assessment_created', $i, ['assessment' => $title]);
        return $uuid;
    }

    /**
     * Check and save the settings of a draft. Times come in the person's timezone and are kept in
     * UTC.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $a @param array<string,mixed> $in
     */
    public function saveSettings(array $i, array $a, array $in, string $timezone): void
    {
        $title = InstitutionStructure::cleanName((string) ($in['title'] ?? ''), 200);
        $errors = [];
        $type = (string) ($in['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            $errors[] = 'Choose the kind of assessment.';
        }
        $description = trim((string) ($in['description'] ?? ''));
        $instructions = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['instructions'] ?? '')));
        if (mb_strlen($description) > 2000 || mb_strlen($instructions) > 10000) {
            $errors[] = 'The description has at most 2,000 characters and the instructions at most 10,000.';
        }
        $duration = self::int($in['duration_minutes'] ?? '');
        if ($duration === null || $duration < 1 || $duration > 600) {
            $errors[] = 'The time allowed is 1 to 600 minutes.';
        }
        $pass = trim((string) ($in['pass_percent'] ?? ''));
        if ($pass !== '' && (!is_numeric($pass) || (float) $pass < 0 || (float) $pass > 100)) {
            $errors[] = 'The pass mark is a percentage from 0 to 100, or empty for no pass mark.';
        }
        $attempts = self::int($in['max_attempts'] ?? '');
        if ($attempts === null || $attempts < 1 || $attempts > 20) {
            $errors[] = 'Attempts allowed: 1 to 20.';
        }
        $negative = trim((string) ($in['negative_marking'] ?? '0'));
        if (!is_numeric($negative) || (float) $negative < 0 || (float) $negative > 1) {
            $errors[] = 'A wrong answer can take off from 0 to 1 times the question\'s marks.';
        }
        $policy = (string) ($in['review_policy'] ?? '');
        if (!isset(self::REVIEW_POLICIES[$policy])) {
            $errors[] = 'Choose what candidates see afterwards.';
        }
        $monitoring = (string) ($in['monitoring'] ?? '') ?: 'basic';
        if (!isset(Integrity::POLICIES[$monitoring])) {
            $errors[] = 'Choose what is monitored.';
        }
        [$opens, $closes, $windowErrors] = self::window((string) ($in['opens_at'] ?? ''), (string) ($in['closes_at'] ?? ''), $timezone);
        $errors = array_merge($errors, $windowErrors);
        if ($errors !== []) {
            throw new RuntimeException('Nothing was saved. ' . implode(' ', $errors));
        }
        $this->repo->saveSettings((int) $i['id'], (int) $a['id'], [
            'title' => $title, 'description' => $description !== '' ? $description : null, 'type' => $type,
            'instructions' => $instructions !== '' ? $instructions : null, 'duration_minutes' => $duration,
            'pass_percent' => $pass !== '' ? number_format((float) $pass, 2, '.', '') : null, 'max_attempts' => $attempts,
            'opens_at' => $opens, 'closes_at' => $closes, 'shuffle_questions' => ($in['shuffle_questions'] ?? '') === '1' ? 1 : 0,
            'shuffle_choices' => ($in['shuffle_choices'] ?? '') === '1' ? 1 : 0, 'negative_marking' => number_format((float) $negative, 2, '.', ''),
            'review_policy' => $policy, 'monitoring' => $monitoring,
        ]);
        $this->record('institution.assessment_changed', $i, ['assessment' => $title]);
    }

    /** Change when a published assessment opens and closes. @param array<string,mixed> $i @param array<string,mixed> $a */
    public function reschedule(array $i, array $a, string $opensAt, string $closesAt, string $timezone): void
    {
        [$opens, $closes, $errors] = self::window($opensAt, $closesAt, $timezone);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
        $this->repo->saveSettings((int) $i['id'], (int) $a['id'], ['opens_at' => $opens, 'closes_at' => $closes]);
        $this->record('institution.assessment_rescheduled', $i, ['assessment' => $a['title'], 'opens' => $opens, 'closes' => $closes]);
    }

    /**
     * Add questions chosen from a bank the person may see. Only published, not archived
     * questions can be chosen.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $a @param array<string,mixed> $bank
     * @param list<string> $questionUuids
     */
    public function addQuestions(array $i, array $m, array $a, array $bank, array $questionUuids): int
    {
        if (!$this->bankRules->canSee($m, $bank)) {
            throw new RuntimeException('You cannot see that question bank.');
        }
        $ids = [];
        foreach ($questionUuids as $uuid) {
            $q = $this->banks->question((int) $i['id'], (int) $bank['id'], (string) $uuid) ?? throw new RuntimeException('That question is not in the bank.');
            if ($q['published_version'] === null || $q['status'] === 'archived') {
                throw new RuntimeException('Only published questions can go in an assessment.');
            }
            $ids[] = (int) $q['id'];
        }
        if (count($this->repo->items((int) $i['id'], (int) $a['id'])) + count($ids) > self::MAX_QUESTIONS) {
            throw new RuntimeException('An assessment has at most ' . self::MAX_QUESTIONS . ' questions chosen one by one.');
        }
        $n = $this->repo->addItems((int) $i['id'], (int) $a['id'], $ids);
        if ($n > 0) {
            $this->repo->touch((int) $i['id'], (int) $a['id']);
            $this->record('institution.assessment_questions_added', $i, ['assessment' => $a['title'], 'count' => $n, 'bank' => $bank['name']]);
        }
        return $n;
    }

    /**
     * A blueprint row: so many published questions from a bank, optionally of one topic (and its
     * subtopics) and one difficulty, drawn for each candidate.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $a @param array<string,mixed> $bank
     */
    public function addBlueprint(array $i, array $m, array $a, array $bank, string $topicUuid, string $difficulty, string $count): void
    {
        if (!$this->bankRules->canSee($m, $bank)) {
            throw new RuntimeException('You cannot see that question bank.');
        }
        $topic = null;
        if ($topicUuid !== '') {
            $topic = $this->banks->topic((int) $i['id'], (int) $bank['id'], $topicUuid) ?? throw new RuntimeException('Choose one of the bank\'s topics.');
        }
        if ($difficulty !== '' && !isset(QuestionBanks::DIFFICULTIES[$difficulty])) {
            throw new RuntimeException('Choose a difficulty, or any.');
        }
        $n = self::int($count);
        if ($n === null || $n < 1 || $n > self::MAX_QUESTIONS) {
            throw new RuntimeException('Draw from 1 to ' . self::MAX_QUESTIONS . ' questions.');
        }
        $this->repo->addBlueprint((int) $i['id'], (int) $a['id'], (int) $bank['id'], $topic !== null ? (int) $topic['id'] : null, $difficulty, $n);
        $this->repo->touch((int) $i['id'], (int) $a['id']);
        $this->record('institution.assessment_blueprint_added', $i, ['assessment' => $a['title'], 'count' => $n, 'bank' => $bank['name']]);
    }

    /**
     * Assign to a class or a student. Someone without classes.view assigns only the classes they
     * teach.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $a
     */
    public function assign(array $i, array $m, array $a, string $classUuid, string $memberUuid): bool
    {
        $iid = (int) $i['id'];
        $all = InstitutionAccess::can($m, 'classes.view');
        if ($classUuid !== '') {
            $c = $this->structure->class($iid, $classUuid);
            if ($c === null || $c['status'] !== 'active') {
                throw new RuntimeException('Choose an open class.');
            }
            if (!$all && !$this->structure->teaches($iid, (int) $c['id'], (int) $m['id'])) {
                throw new RuntimeException('You can assign only the classes you teach.');
            }
            $done = $this->repo->assign($iid, (int) $a['id'], (int) $c['id'], null, (int) $m['id']);
            $what = ['class' => InstitutionStructure::label((string) $c['name'], (string) $c['intake'])];
        } else {
            if (!$all) {
                throw new RuntimeException('You can assign only whole classes you teach.');
            }
            $st = $this->structure->student($iid, $memberUuid);
            if ($st === null || $st['status'] !== 'active') {
                throw new RuntimeException('Choose one of the institution\'s students.');
            }
            $done = $this->repo->assign($iid, (int) $a['id'], null, (int) $st['id'], (int) $m['id']);
            $what = ['student' => (string) $st['username']];
        }
        if ($done) {
            $this->record('institution.assessment_assigned', $i, ['assessment' => $a['title']] + $what);
        }
        return $done;
    }

    // Readiness, drawing and publishing ------------------------------------------------------

    /**
     * What has to be true before publishing, each with whether it is.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $a
     * @return list<array{ok:bool,text:string}>
     */
    public function checks(array $i, array $a): array
    {
        $iid = (int) $i['id'];
        $items = $this->repo->items($iid, (int) $a['id']);
        $rows = $this->repo->blueprints($iid, (int) $a['id']);
        $out = [];
        $out[] = ['ok' => trim((string) ($a['instructions'] ?? '')) !== '', 'text' => 'Instructions for candidates are written.'];
        $count = count($items) + array_sum(array_map(static fn(array $r): int => (int) $r['question_count'], $rows));
        $out[] = ['ok' => $count > 0, 'text' => $count > 0 ? 'It has ' . $count . ' ' . ($count === 1 ? 'question' : 'questions') . '.' : 'It has questions.'];
        $unusable = array_filter($items, static fn(array $it): bool => $it['shown_version_id'] === null || $it['question_status'] === 'archived');
        if ($items !== []) {
            $out[] = ['ok' => $unusable === [], 'text' => $unusable === [] ? 'Every chosen question is published.'
                : count($unusable) . ' chosen ' . (count($unusable) === 1 ? 'question is' : 'questions are') . ' archived or no longer published. Take them out.'];
        }
        if ($rows !== []) {
            $short = $this->shortages($i, $a, $rows);
            $out[] = ['ok' => $short === [], 'text' => $short === [] ? 'The question banks have enough published questions for the blueprint.'
                : 'Not enough published questions: ' . implode('; ', $short) . '.'];
        }
        $out[] = ['ok' => $a['closes_at'] === null || (string) $a['closes_at'] > gmdate('Y-m-d H:i:s'), 'text' => 'It closes in the future, or stays open until closed.'];
        $out[] = ['ok' => $this->repo->assignments($iid, (int) $a['id']) !== [], 'text' => 'It is assigned to at least one class or student.'];
        return $out;
    }

    /**
     * A candidate's form: the chosen questions, then each blueprint row's draw, shuffled when the
     * assessment says so, each with the order its choices are shown in. The same seed draws the
     * same form, so a sitting can be stored as its seed and the version ids it used.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $a
     * @return list<array{version:array<string,mixed>,choice_order:list<string>}>
     */
    public function draw(array $i, array $a, int $seed): array
    {
        $iid = (int) $i['id'];
        $random = new Randomizer(new Mt19937($seed));
        $versions = [];
        $used = [];
        foreach ($this->repo->items($iid, (int) $a['id']) as $it) {
            if ($it['shown_version_id'] !== null) {
                $versions[] = (int) $it['shown_version_id'];
            }
        }
        $byId = [];
        foreach ($this->repo->blueprints($iid, (int) $a['id']) as $r) {
            $pool = array_values(array_filter($this->repo->pool($iid, (int) $a['id'], (int) $r['bank_id'], $r['topic_id'] !== null ? (int) $r['topic_id'] : null,
                (string) $r['difficulty']), static fn(array $v): bool => !isset($used[(int) $v['question_id']])));
            $take = min((int) $r['question_count'], count($pool));
            if ($take === 0) {
                continue;
            }
            $keys = $take === 1 ? [$random->pickArrayKeys($pool, 1)[0]] : $random->pickArrayKeys($pool, $take);
            foreach ($keys as $k) {
                $used[(int) $pool[$k]['question_id']] = true;
                $byId[(int) $pool[$k]['id']] = $pool[$k];
                $versions[] = (int) $pool[$k]['id'];
            }
        }
        $rows = $this->repo->versionsById($iid, array_values(array_unique($versions)));
        if ((int) $a['shuffle_questions'] === 1) {
            $rows = $random->shuffleArray($rows);
        }
        return array_map(static function (array $v) use ($a, $random): array {
            $keys = array_map(static fn(array $c): string => (string) $c['key'], (array) json_decode((string) $v['choices'], true));
            if ((int) $a['shuffle_choices'] === 1 && $v['type'] !== 'true_false') {
                $keys = $random->shuffleArray($keys);
            }
            return ['version' => $v, 'choice_order' => $keys];
        }, $rows);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $a @param array<string,mixed> $m */
    public function publish(array $i, array $m, array $a): void
    {
        if ($a['status'] !== 'draft') {
            throw new RuntimeException('Only a draft can be published.');
        }
        $failed = array_filter($this->checks($i, $a), static fn(array $c): bool => !$c['ok']);
        if ($failed !== []) {
            throw new RuntimeException('Not ready to publish: ' . implode(' ', array_map(static fn(array $c): string => $c['text'], $failed)));
        }
        $this->repo->pinItems((int) $i['id'], (int) $a['id']);
        $this->repo->setStatus((int) $i['id'], (int) $a['id'], 'published', (int) $m['id']);
        $this->record('institution.assessment_published', $i, ['assessment' => $a['title']]);
    }

    /** published -> draft, closed; closed -> published; draft or closed -> archived; archived -> draft. @param array<string,mixed> $i @param array<string,mixed> $a */
    public function move(array $i, array $a, string $action): string
    {
        [$to, $from] = match ($action) {
            'unpublish' => ['draft', ['published']],
            'close' => ['closed', ['published']],
            'reopen' => ['published', ['closed']],
            'archive' => ['archived', ['draft', 'closed']],
            'restore' => ['draft', ['archived']],
            default => ['', []],
        };
        if (!in_array($a['status'], $from, true)) {
            throw new RuntimeException('That cannot be done to an assessment that is "' . (self::STATUSES[$a['status']] ?? $a['status']) . '".');
        }
        if ($action === 'reopen' && $a['closes_at'] !== null && (string) $a['closes_at'] <= gmdate('Y-m-d H:i:s')) {
            throw new RuntimeException('Its closing time has passed. Set a later one first.');
        }
        $sat = $this->repo->sittingCount((int) $i['id'], (int) $a['id']) > 0;
        if ($action === 'unpublish' && $sat) {
            throw new RuntimeException('Candidates have sat it, so it stays published. Close it instead, and make a new assessment for any changes.');
        }
        if ($action === 'restore' && $sat) {
            $to = 'closed';
        }
        if ($action === 'unpublish' || ($action === 'restore' && !$sat)) {
            $this->repo->unpinItems((int) $i['id'], (int) $a['id']);
        }
        $this->repo->setStatus((int) $i['id'], (int) $a['id'], $to);
        $done = ['unpublish' => 'institution.assessment_unpublished', 'close' => 'institution.assessment_closed', 'reopen' => 'institution.assessment_reopened',
            'archive' => 'institution.assessment_archived', 'restore' => 'institution.assessment_restored'];
        $this->record($done[$action], $i, ['assessment' => $a['title']]);
        return $to;
    }

    /**
     * Blueprint rows that cannot be filled, drawing them in order as a candidate's form would.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $a @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function shortages(array $i, array $a, array $rows): array
    {
        $used = [];
        $short = [];
        foreach ($rows as $r) {
            $pool = array_values(array_filter($this->repo->pool((int) $i['id'], (int) $a['id'], (int) $r['bank_id'], $r['topic_id'] !== null ? (int) $r['topic_id'] : null,
                (string) $r['difficulty']), static fn(array $v): bool => !isset($used[(int) $v['question_id']])));
            foreach (array_slice($pool, 0, (int) $r['question_count']) as $v) {
                $used[(int) $v['question_id']] = true;
            }
            if (count($pool) < (int) $r['question_count']) {
                $short[] = self::rowLabel($r) . ' needs ' . (int) $r['question_count'] . ', has ' . count($pool);
            }
        }
        return $short;
    }

    /** @param array<string,mixed> $r a blueprints() row */
    public static function rowLabel(array $r): string
    {
        return $r['bank_name'] . ($r['topic_name'] !== null ? ', ' . $r['topic_name'] : '') . ((string) $r['difficulty'] !== '' ? ', ' . strtolower(QuestionBanks::DIFFICULTIES[$r['difficulty']] ?? $r['difficulty']) : '');
    }

    /** @return array{0:?string,1:?string,2:list<string>} opens and closes in UTC, and what is wrong */
    private static function window(string $opens, string $closes, string $timezone): array
    {
        $errors = [];
        $parse = static function (string $v) use ($timezone, &$errors): ?string {
            $v = trim($v);
            if ($v === '') {
                return null;
            }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $v, new \DateTimeZone($timezone));
            if ($d === false) {
                $errors[] = 'Give the times as a date and a time.';
                return null;
            }
            return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        };
        $o = $parse($opens);
        $c = $parse($closes);
        if ($o !== null && $c !== null && $c <= $o) {
            $errors[] = 'It has to close after it opens.';
        }
        return [$o, $c, $errors];
    }

    private static function int(mixed $v): ?int
    {
        $v = trim((string) $v);
        return preg_match('/^\d{1,6}$/', $v) === 1 ? (int) $v : null;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $details */
    private function record(string $action, array $i, array $details): void
    {
        $this->audit->record($action, ['institution' => (string) $i['name']] + $details, 'institution', (string) $i['uuid']);
    }
}
