<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Results and analytics of institution assessments, worked out from stored sittings (their
 * forms, answers and grades), never recomputed from the question bank as it is today.
 *
 * - Per sitting: score, percentage, pass, and a breakdown by topic.
 * - Per assessment: score distribution, topics across candidates, and question statistics (how
 *   often it was right, the wrong choice picked most), flagged for a look when unusually hard or
 *   easy. Flags are prompts for the author, not proof that a question is faulty.
 * - Per class: each assessment assigned to it, with completion, average and pass rate.
 * - Publishing results to candidates, who then see what the assessment's review policy allows.
 */
final class Results
{
    /** Below this share right (with enough sittings) a question is flagged as hard. */
    public const HARD = 30.0;
    public const EASY = 95.0;
    public const MIN_SITTINGS = 5;

    public function __construct(private Database $db, private Notifier $notifier, private AuditLog $audit)
    {
    }

    /**
     * The breakdown of one sitting by topic.
     *
     * @param list<array<string,mixed>> $questions a sitting's questions (with awarded, marks, topic_id, correct)
     * @return list<array{topic:string,questions:int,correct:int,marks:float,awarded:float,percent:float}>
     */
    public function topics(array $questions): array
    {
        $names = $this->topicNames(array_values(array_unique(array_filter(array_map(static fn(array $q) => $q['topic_id'] !== null ? (int) $q['topic_id'] : null, $questions))))) ;
        $by = [];
        foreach ($questions as $q) {
            $name = $q['topic_id'] !== null ? ($names[(int) $q['topic_id']] ?? 'Other') : 'No topic';
            $by[$name] ??= ['topic' => $name, 'questions' => 0, 'correct' => 0, 'marks' => 0.0, 'awarded' => 0.0];
            $by[$name]['questions']++;
            $by[$name]['correct'] += (int) $q['correct'];
            $by[$name]['marks'] += (float) $q['marks'];
            $by[$name]['awarded'] += max(0.0, (float) $q['awarded']);
        }
        $out = array_map(static fn(array $t): array => $t + ['percent' => $t['marks'] > 0 ? round($t['awarded'] / $t['marks'] * 100, 1) : 0.0], array_values($by));
        usort($out, static fn(array $a, array $b): int => $a['percent'] <=> $b['percent']);
        return $out;
    }

    /**
     * Statistics of an assessment over its handed-in sittings.
     *
     * @return array{count:int,distribution:list<int>,topics:list<array<string,mixed>>,questions:list<array<string,mixed>>}
     */
    public function assessment(int $iid, int $assessmentId): array
    {
        $done = "s.institution_id = ? AND s.assessment_id = ? AND s.status <> 'in_progress'";
        $percents = array_map('floatval', array_column($this->db->all("SELECT s.percent FROM assessment_sessions s WHERE $done", [$iid, $assessmentId]), 'percent'));
        $distribution = array_fill(0, 10, 0);
        foreach ($percents as $p) {
            $distribution[min(9, (int) floor($p / 10))]++;
        }
        $rows = $this->db->all("SELECT q.position, q.awarded, q.marks, q.correct, q.choice_order, v.question_id, v.topic_id, v.text, v.choices, v.type, r.answer
                                FROM assessment_session_questions q JOIN assessment_sessions s ON s.id = q.session_id
                                JOIN question_versions v ON v.id = q.question_version_id
                                LEFT JOIN assessment_responses r ON r.session_id = q.session_id AND r.position = q.position
                                WHERE $done", [$iid, $assessmentId]);
        $questions = [];
        foreach ($rows as $r) {
            $id = (int) $r['question_id'];
            $questions[$id] ??= ['question_id' => $id, 'text' => (string) $r['text'], 'type' => (string) $r['type'], 'shown' => 0, 'correct' => 0, 'unanswered' => 0,
                'awarded' => 0.0, 'marks' => 0.0, 'wrong' => [], 'choices' => (array) json_decode((string) $r['choices'], true)];
            $q = &$questions[$id];
            $q['shown']++;
            $q['correct'] += (int) $r['correct'];
            $q['awarded'] += (float) $r['awarded'];
            $q['marks'] += (float) $r['marks'];
            $answer = (string) ($r['answer'] ?? '');
            if ($answer === '') {
                $q['unanswered']++;
            } elseif (!(int) $r['correct']) {
                foreach (str_split($answer) as $k) {
                    $q['wrong'][$k] = ($q['wrong'][$k] ?? 0) + 1;
                }
            }
            unset($q);
        }
        $out = [];
        foreach ($questions as $q) {
            $right = array_map(static fn(array $c): string => (string) $c['key'], array_filter($q['choices'], static fn(array $c): bool => !empty($c['correct'])));
            $wrongPicks = array_diff_key($q['wrong'], array_flip($right));
            arsort($wrongPicks);
            $top = array_key_first($wrongPicks);
            $text = null;
            foreach ($q['choices'] as $c) {
                if ((string) $c['key'] === (string) $top) {
                    $text = (string) $c['text'];
                }
            }
            $percent = $q['shown'] > 0 ? round($q['correct'] / $q['shown'] * 100, 1) : 0.0;
            $out[] = ['question_id' => $q['question_id'], 'text' => $q['text'], 'type' => $q['type'], 'shown' => $q['shown'], 'percent' => $percent,
                'unanswered' => $q['unanswered'], 'average' => $q['shown'] > 0 ? round($q['awarded'] / $q['shown'], 2) : 0.0, 'marks' => $q['shown'] > 0 ? round($q['marks'] / $q['shown'], 2) : 0.0,
                'distractor' => $top !== null ? ['text' => $text, 'count' => $wrongPicks[$top]] : null,
                'flag' => $q['shown'] >= self::MIN_SITTINGS ? ($percent < self::HARD ? 'hard' : ($percent >= self::EASY ? 'easy' : null)) : null];
        }
        usort($out, static fn(array $a, array $b): int => $a['percent'] <=> $b['percent']);
        $topicRows = array_map(static fn(array $r): array => ['topic_id' => $r['topic_id'], 'awarded' => $r['awarded'], 'marks' => $r['marks'], 'correct' => $r['correct']], $rows);
        return ['count' => count($percents), 'distribution' => $distribution, 'topics' => $this->topics($topicRows), 'questions' => $out];
    }

    /**
     * Each assessment assigned to a class, with how its students did: completion counts the
     * students in the class now who have handed in at least once.
     *
     * @return list<array<string,mixed>>
     */
    public function classReport(int $iid, int $classId): array
    {
        $members = (int) $this->db->value("SELECT COUNT(*) FROM class_enrolments WHERE institution_id = ? AND class_id = ? AND status = 'active'", [$iid, $classId]);
        $rows = $this->db->all("SELECT a.uuid, a.title, a.status, a.pass_percent, a.closes_at,
                                       (SELECT COUNT(DISTINCT s.membership_id) FROM assessment_sessions s WHERE s.assessment_id = a.id AND s.status <> 'in_progress'
                                          AND s.membership_id IN (SELECT membership_id FROM class_enrolments e WHERE e.class_id = ? AND e.status = 'active')) AS sat,
                                       (SELECT AVG(s.percent) FROM assessment_sessions s WHERE s.assessment_id = a.id AND s.status <> 'in_progress' AND s.class_id = ?) AS average,
                                       (SELECT SUM(s.passed) FROM assessment_sessions s WHERE s.assessment_id = a.id AND s.status <> 'in_progress' AND s.class_id = ?) AS passed,
                                       (SELECT COUNT(*) FROM assessment_sessions s WHERE s.assessment_id = a.id AND s.status <> 'in_progress' AND s.class_id = ?) AS sittings
                                FROM assessments a JOIN assessment_assignments x ON x.assessment_id = a.id AND x.class_id = ?
                                WHERE a.institution_id = ? AND a.status IN ('published', 'closed') ORDER BY a.published_at DESC",
            [$classId, $classId, $classId, $classId, $classId, $iid]);
        return array_map(static fn(array $r): array => $r + ['members' => $members], $rows);
    }

    /** @return list<array<string,mixed>> a student's handed-in sittings in an institution, newest first */
    public function student(int $iid, int $membershipId): array
    {
        return $this->db->all("SELECT s.*, a.uuid AS assessment_uuid, a.title FROM assessment_sessions s JOIN assessments a ON a.id = s.assessment_id
                               WHERE s.institution_id = ? AND s.membership_id = ? AND s.status <> 'in_progress' ORDER BY s.started_at DESC", [$iid, $membershipId]);
    }

    /** @return array<string,mixed> the institution's results at a glance */
    public function overview(int $iid): array
    {
        $r = $this->db->one("SELECT COUNT(*) AS sittings, AVG(percent) AS average, SUM(passed) AS passed, SUM(passed IS NOT NULL) AS graded
                             FROM assessment_sessions WHERE institution_id = ? AND status <> 'in_progress'", [$iid]);
        return ['sittings' => (int) $r['sittings'], 'average' => $r['average'] !== null ? round((float) $r['average'], 1) : null,
            'pass_rate' => (int) $r['graded'] > 0 ? round((int) $r['passed'] / (int) $r['graded'] * 100, 1) : null,
            'assessments' => (int) $this->db->value("SELECT COUNT(*) FROM assessments WHERE institution_id = ? AND status IN ('published', 'closed')", [$iid])];
    }

    /**
     * Publish an assessment's results: its candidates are told in their inbox (and by email if
     * they chose it) and can open them. @param array<string,mixed> $i @param array<string,mixed> $a @param array<string,mixed> $m
     */
    public function publish(array $i, array $m, array $a): int
    {
        if (!in_array($a['status'], ['published', 'closed'], true)) {
            throw new RuntimeException('Publish the assessment first.');
        }
        if ($a['results_published_at'] !== null) {
            throw new RuntimeException('Its results are already published.');
        }
        $this->db->run('UPDATE assessments SET results_published_at = ?, results_published_by = ? WHERE institution_id = ? AND id = ?',
            [Database::now(), (int) $m['id'], (int) $i['id'], (int) $a['id']]);
        $latest = $this->db->all("SELECT u.*, s.uuid AS sitting_uuid FROM assessment_sessions s JOIN institution_memberships m ON m.id = s.membership_id JOIN users u ON u.id = m.user_id
                                  WHERE s.institution_id = ? AND s.assessment_id = ? AND s.status <> 'in_progress'
                                    AND s.attempt = (SELECT MAX(t.attempt) FROM assessment_sessions t WHERE t.assessment_id = s.assessment_id AND t.membership_id = s.membership_id AND t.status <> 'in_progress')",
            [(int) $i['id'], (int) $a['id']]);
        foreach ($latest as $row) {
            $uuid = (string) $row['sitting_uuid'];
            unset($row['sitting_uuid']);
            $this->notifier->notify($row, 'institution', 'Your result: ' . $a['title'], $i['name'] . ' has published the results of ' . $a['title'] . '.',
                '/sit/' . $uuid . '/result', null, 'results:' . $a['uuid'] . ':' . $row['id']);
        }
        $this->audit->record('institution.results_published', ['institution' => $i['name'], 'assessment' => $a['title'], 'candidates' => count($latest)], 'institution', (string) $i['uuid']);
        return count($latest);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $a */
    public function unpublish(array $i, array $a): void
    {
        $this->db->run('UPDATE assessments SET results_published_at = NULL, results_published_by = NULL WHERE institution_id = ? AND id = ?', [(int) $i['id'], (int) $a['id']]);
        $this->audit->record('institution.results_unpublished', ['institution' => $i['name'], 'assessment' => $a['title']], 'institution', (string) $i['uuid']);
    }

    /** Every sitting of an assessment as CSV. @param list<array<string,mixed>> $rows from SittingRepository::forAssessment() */
    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['name', 'username', 'student_number', 'class', 'attempt', 'status', 'started_utc', 'handed_in_utc', 'score', 'max_score', 'percent', 'passed'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, array_map(static function (mixed $v): string {
                $v = (string) $v;
                return preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v;
            }, [Access::displayName($r), $r['username'], $r['student_number'] ?? '', $r['class_name'] ?? '', $r['attempt'], $r['status'], $r['started_at'],
                $r['submitted_at'] ?? '', $r['score'] ?? '', $r['max_score'], $r['percent'] ?? '', $r['passed'] === null ? '' : ((int) $r['passed'] ? 'yes' : 'no')]), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /** A small HTML document as PDF bytes (reports), with no remote loading. */
    public static function pdf(string $html, string $tempDir, string $orientation = 'portrait'): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        if ($tempDir !== '') {
            @mkdir($tempDir, 0750, true);
            $options->setTempDir($tempDir);
            $options->setFontCache($tempDir);
        }
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();
        return (string) $dompdf->output();
    }

    /** @param list<int> $ids @return array<int,string> */
    private function topicNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all('SELECT id, name FROM bank_topics WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }
}
