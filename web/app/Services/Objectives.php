<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use App\Services\Documents\QuestionView;
use RuntimeException;

/**
 * An exam's objectives and which one each question covers, set by the people who correct
 * questions (answers.edit). Library questions are files, so this is an overlay keyed by
 * (exam_id, question_key), like corrections and notes.
 *
 * Also here, because they read the same data: the trust badge each reader sees on a question,
 * the per-objective breakdown of a mock exam, and the next step suggested on an exam's page.
 */
final class Objectives
{
    /** A mock is broken down by objective once at least this share of its questions has one. */
    public const BREAKDOWN_COVERAGE = 80.0;
    /** An objective is the weakest only after this many answers in it. */
    public const MIN_ANSWERS = 5;

    public const TRUST = [
        'corrected' => ['Corrected by an editor', 'success', 'pencil-square'],
        'checked' => ['Checked by an editor', 'success', 'patch-check'],
        'reported' => ['Reported, being looked at', 'warning', 'flag'],
        'doubtful' => ['Answer in doubt', 'warning', 'exclamation-triangle'],
        'unreviewed' => ['Not reviewed', 'neutral', null],
    ];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    // Objectives -----------------------------------------------------------------------------

    /** @return list<array<string,mixed>> with how many questions each covers */
    public function all(int $examId): array
    {
        return $this->db->all('SELECT o.*, (SELECT COUNT(*) FROM question_objectives q WHERE q.objective_id = o.id) AS question_count
                               FROM exam_objectives o WHERE o.exam_id = ? ORDER BY o.position, o.id', [$examId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $examId, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM exam_objectives WHERE exam_id = ? AND uuid = ?', [$examId, $uuid]) : null;
    }

    /** @param array<string,mixed> $exam @param array<string,mixed>|null $o */
    public function save(array $exam, ?array $o, string $code, string $name): void
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $code = trim((string) preg_replace('/\s+/u', ' ', $code));
        if ($name === '' || mb_strlen($name) > 160 || mb_strlen($code) > 32) {
            throw new RuntimeException('An objective has a name of up to 160 characters, and a code of up to 32.');
        }
        if ($o === null) {
            $pos = (int) $this->db->value('SELECT COALESCE(MAX(position), 0) + 1 FROM exam_objectives WHERE exam_id = ?', [(int) $exam['id']]);
            $this->db->run('INSERT INTO exam_objectives (uuid, exam_id, code, name, position, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [Ids::uuid(), (int) $exam['id'], $code !== '' ? $code : null, $name, $pos, Database::now()]);
        } else {
            $this->db->run('UPDATE exam_objectives SET code = ?, name = ? WHERE id = ?', [$code !== '' ? $code : null, $name, (int) $o['id']]);
        }
        $this->audit->record('exam.objective_saved', ['exam' => $exam['code'], 'objective' => $name], 'exam', (string) $exam['code']);
    }

    /** Remove an objective; its questions are left without one. @param array<string,mixed> $exam @param array<string,mixed> $o */
    public function remove(array $exam, array $o): void
    {
        $this->db->run('DELETE FROM exam_objectives WHERE id = ?', [(int) $o['id']]);
        $this->audit->record('exam.objective_removed', ['exam' => $exam['code'], 'objective' => $o['name']], 'exam', (string) $exam['code']);
    }

    /** @return array<string,int> question key => objective id */
    public function map(int $examId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT question_key, objective_id FROM question_objectives WHERE exam_id = ?', [$examId]) as $r) {
            $out[(string) $r['question_key']] = (int) $r['objective_id'];
        }
        return $out;
    }

    /**
     * Put questions under an objective, or take them out of any (null).
     *
     * @param array<string,mixed> $exam @param list<string> $keys @param array<string,mixed> $user
     */
    public function assign(array $exam, array $keys, ?array $o, array $user): int
    {
        $now = Database::now();
        $n = 0;
        foreach (array_unique($keys) as $key) {
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key) !== 1) {
                continue;
            }
            if ($o === null) {
                $n += $this->db->run('DELETE FROM question_objectives WHERE exam_id = ? AND question_key = ?', [(int) $exam['id'], $key])->rowCount();
            } else {
                $this->db->run('INSERT INTO question_objectives (exam_id, question_key, objective_id, set_by, set_at) VALUES (?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE objective_id = VALUES(objective_id), set_by = VALUES(set_by), set_at = VALUES(set_at)',
                    [(int) $exam['id'], $key, (int) $o['id'], (int) $user['id'], $now]);
                $n++;
            }
        }
        if ($n > 0) {
            $this->audit->record('exam.objectives_assigned', ['exam' => $exam['code'], 'objective' => $o['name'] ?? null, 'questions' => $n], 'exam', (string) $exam['code']);
        }
        return $n;
    }

    /**
     * A start: one objective per ExamTopics topic number, each with its questions. Only for an
     * exam with no objectives yet.
     *
     * @param array<string,mixed> $exam @param list<array<string,mixed>> $questions @param array<string,mixed> $user
     */
    public function fromTopics(array $exam, array $questions, array $user): int
    {
        if ($this->all((int) $exam['id']) !== []) {
            throw new RuntimeException('This exam has objectives already.');
        }
        $byTopic = [];
        foreach ($questions as $q) {
            $byTopic[max(1, (int) ($q['topic'] ?? 1))][] = QuestionView::key($q);
        }
        ksort($byTopic);
        foreach ($byTopic as $topic => $keys) {
            $this->save($exam, null, (string) $topic, 'Topic ' . $topic);
            $o = $this->db->one('SELECT * FROM exam_objectives WHERE exam_id = ? ORDER BY id DESC LIMIT 1', [(int) $exam['id']]);
            $this->assign($exam, $keys, $o, $user);
        }
        return count($byTopic);
    }

    // What readers see -------------------------------------------------------------------------

    /**
     * One status for a question, from what is already known about it.
     *
     * @param array<string,mixed> $q a question with its correction applied
     */
    public static function trust(array $q, bool $reported): string
    {
        $kind = (string) ($q['edit']['kind'] ?? '');
        return match (true) {
            $kind === 'corrected' => 'corrected',
            $kind === 'checked' => 'checked',
            $reported => 'reported',
            QuestionView::doubts($q) !== [] => 'doubtful',
            default => 'unreviewed',
        };
    }

    /** @return array<string,true> keys of questions with an open report */
    public function reported(int $examId): array
    {
        $out = [];
        foreach ($this->db->all("SELECT DISTINCT question_key FROM question_reports WHERE exam_id = ? AND status = 'open'", [$examId]) as $r) {
            $out[(string) $r['question_key']] = true;
        }
        return $out;
    }

    /**
     * A finished mock, right and asked by objective, when enough of its questions have one.
     *
     * @param array<string,mixed> $mock decoded (keys, results)
     * Grouped by the objectives as they are now, so renaming one renames it here too.
     *
     * @return array{coverage:float,rows:list<array{uuid:string,name:string,code:?string,right:int,total:int,percent:float}>}|null
     */
    public function breakdown(array $mock): ?array
    {
        $map = $this->map((int) $mock['exam_id']);
        $keys = (array) $mock['keys'];
        if ($keys === [] || $map === []) {
            return null;
        }
        $mapped = array_filter($keys, static fn(string $k): bool => isset($map[$k]));
        $coverage = round(count($mapped) / count($keys) * 100, 1);
        if ($coverage < self::BREAKDOWN_COVERAGE) {
            return null;
        }
        $names = [];
        foreach ($this->all((int) $mock['exam_id']) as $o) {
            $names[(int) $o['id']] = $o;
        }
        $rows = [];
        foreach ($mapped as $k) {
            $id = $map[$k];
            $rows[$id] ??= ['uuid' => (string) ($names[$id]['uuid'] ?? ''), 'name' => (string) ($names[$id]['name'] ?? ''), 'code' => $names[$id]['code'] ?? null, 'right' => 0, 'total' => 0, 'position' => (int) ($names[$id]['position'] ?? 0)];
            $rows[$id]['total']++;
            $rows[$id]['right'] += !empty($mock['results'][$k]['right']) ? 1 : 0;
        }
        usort($rows, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
        return ['coverage' => $coverage, 'rows' => array_map(static fn(array $r): array => ['uuid' => $r['uuid'], 'name' => $r['name'], 'code' => $r['code'], 'right' => $r['right'], 'total' => $r['total'],
            'percent' => round($r['right'] / $r['total'] * 100, 1)], $rows)];
    }

    /**
     * The one thing to do next on an exam, from what this person has done: questions due for
     * review, then mistakes, then the weakest objective, then a timed mock once most of the exam
     * has been answered, else carrying on.
     *
     * @return array{text:string,why:string,query:array<string,string>,mock:bool}
     */
    public function nextStep(int $userId, int $examId, int $questionCount, string $today): array
    {
        $due = (int) $this->db->value('SELECT COUNT(*) FROM study_answers WHERE user_id = ? AND exam_id = ? AND due_on IS NOT NULL AND due_on <= ?', [$userId, $examId, $today]);
        if ($due > 0) {
            return ['text' => 'Review the ' . $due . ' question' . ($due === 1 ? '' : 's') . ' due today', 'why' => 'Spaced review: answering them now keeps them learnt.',
                'query' => ['mode' => 'practice', 'due' => '1'], 'mock' => false];
        }
        $mistakes = (int) $this->db->value('SELECT COUNT(*) FROM study_answers WHERE user_id = ? AND exam_id = ? AND correct = 0', [$userId, $examId]);
        if ($mistakes > 0) {
            return ['text' => 'Go over your ' . $mistakes . ' mistake' . ($mistakes === 1 ? '' : 's'), 'why' => 'The questions you last got wrong.',
                'query' => ['mode' => 'practice', 'mistakes' => '1'], 'mock' => false];
        }
        $weak = $this->db->one('SELECT o.uuid, o.name, COUNT(*) AS answered, SUM(s.correct) AS right_count FROM study_answers s
                                JOIN question_objectives q ON q.exam_id = s.exam_id AND q.question_key = s.question_key JOIN exam_objectives o ON o.id = q.objective_id
                                WHERE s.user_id = ? AND s.exam_id = ? GROUP BY o.id HAVING COUNT(*) >= ? ORDER BY SUM(s.correct) / COUNT(*), o.position LIMIT 1',
            [$userId, $examId, self::MIN_ANSWERS]);
        if ($weak !== null && (int) $weak['right_count'] / (int) $weak['answered'] < 0.7) {
            return ['text' => 'Practise ' . $weak['name'], 'why' => 'Your weakest objective: ' . (int) round((int) $weak['right_count'] / (int) $weak['answered'] * 100) . '% right so far.',
                'query' => ['mode' => 'practice', 'objective' => (string) $weak['uuid']], 'mock' => false];
        }
        $answered = (int) $this->db->value('SELECT COUNT(*) FROM study_answers WHERE user_id = ? AND exam_id = ?', [$userId, $examId]);
        if ($questionCount > 0 && $answered >= $questionCount * 0.6) {
            return ['text' => 'Take a timed mock exam', 'why' => 'You have answered most of the questions. A mock shows how ready you are.', 'query' => [], 'mock' => true];
        }
        return ['text' => $answered === 0 ? 'Start practising' : 'Carry on practising', 'why' => $answered === 0 ? 'Answer questions one at a time and check each answer.'
            : ($questionCount - $answered) . ' questions you have not answered yet.', 'query' => ['mode' => 'practice'], 'mock' => false];
    }
}
