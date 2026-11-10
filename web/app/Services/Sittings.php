<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\SittingRepository;
use RuntimeException;

/**
 * Candidates sitting institution assessments: when one can be started, saving answers, handing
 * in (by the candidate, or by the server once time is up), and grading.
 *
 * The server's clock is the only clock. A sitting ends at its ends_at; an answer that arrives
 * after it is refused, whatever the browser says, and the sitting is handed in with what had
 * arrived. Grading happens here, from the stored form; the right answers never go to the browser.
 *
 * Marking:
 *  - single choice and true or false: the marks for the right choice, nothing for no answer;
 *  - multiple select: the marks for exactly the right choices; with part marks, each right pick
 *    earns its share (marks / number of right choices) and each wrong pick takes one share off,
 *    never below zero;
 *  - a wrong answer (one that earns nothing) loses negative_marking times the question's marks;
 *    an unanswered question loses nothing; the total never goes below zero.
 */
final class Sittings
{
    /** The longest written answer kept, in characters. */
    public const MAX_WRITTEN = 20000;

    public const STATUSES = ['in_progress' => 'In progress', 'submitted' => 'Handed in', 'auto_submitted' => 'Handed in when time ran out', 'terminated' => 'Ended by the institution'];

    public function __construct(private SittingRepository $repo, private Assessments $assessments, private AuditLog $audit, private Integrity $integrity)
    {
    }

    public function repo(): SittingRepository
    {
        return $this->repo;
    }

    /** upcoming, open or closed, by the server's clock. @param array<string,mixed> $a */
    public static function window(array $a): string
    {
        $now = Database::now();
        if ($a['status'] === 'closed' || ($a['closes_at'] !== null && (string) $a['closes_at'] <= $now)) {
            return 'closed';
        }
        if ($a['opens_at'] !== null && (string) $a['opens_at'] > $now) {
            return 'upcoming';
        }
        return 'open';
    }

    /**
     * Start a sitting of an assessment from assignedTo(), or return the one already in progress.
     *
     * @param array<string,mixed> $row from SittingRepository::assignedTo()
     * @return string the sitting's uuid
     */
    public function start(array $row, bool $termsAccepted): string
    {
        if ($row['open_session'] !== null) {
            return (string) $row['open_session'];
        }
        if (!$termsAccepted) {
            throw new RuntimeException('Read the rules and tick the box to say you accept them.');
        }
        if (self::window($row) !== 'open') {
            throw new RuntimeException(self::window($row) === 'upcoming' ? 'This assessment has not opened yet.' : 'This assessment has closed.');
        }
        if ((int) $row['attempts_used'] >= (int) $row['max_attempts']) {
            throw new RuntimeException('You have used every attempt this assessment allows.');
        }
        $iid = (int) $row['institution_id'];
        $institution = ['id' => $iid];
        $seed = random_int(1, 2147483647);
        $form = $this->assessments->draw($institution, $row, $seed);
        if ($form === []) {
            throw new RuntimeException('This assessment has no questions to show. Tell the institution.');
        }
        $ends = time() + (int) $row['duration_minutes'] * 60;
        if ($row['closes_at'] !== null) {
            $ends = min($ends, (int) strtotime($row['closes_at'] . ' UTC'));
        }
        $uuid = $this->repo->create($iid, (int) $row['id'], (int) $row['membership_id'], $this->repo->assignedClass($iid, (int) $row['id'], (int) $row['membership_id']),
            (int) $row['attempts_used'] + 1, $seed, gmdate('Y-m-d H:i:s', $ends), $form, (string) ($row['monitoring'] ?? 'basic'));
        if ($uuid === null) {
            throw new RuntimeException('It was started already, perhaps in another tab. Open it from your assessments.');
        }
        $this->audit->record('sitting.started', ['institution' => $row['institution_name'], 'assessment' => $row['title'], 'attempt' => (int) $row['attempts_used'] + 1],
            'institution', (string) $row['institution_uuid']);
        return $uuid;
    }

    /** Seconds left by the server's clock; while paused, the time left when it was paused. */
    public static function secondsLeft(array $session): int
    {
        $from = ($session['paused_at'] ?? null) !== null ? (int) strtotime($session['paused_at'] . ' UTC') : time();
        return max(0, (int) strtotime($session['ends_at'] . ' UTC') - $from);
    }

    /** End a sitting at the institution's decision (invigilation), marked with the answers it had. @param array<string,mixed> $session */
    public function terminate(array $session): void
    {
        if ($session['status'] === 'in_progress') {
            $this->finish($session, 'terminated');
        }
    }

    /**
     * Save one answer. Refused once time is up (the sitting is then handed in).
     *
     * @param array<string,mixed> $session @param array<int,array<string,mixed>> $questions by position
     */
    public function save(array $session, array $questions, int $position, string $answer, bool $flagged, int $seq): bool
    {
        if ($session['status'] !== 'in_progress') {
            throw new RuntimeException('This sitting has been handed in.');
        }
        if (($session['paused_at'] ?? null) !== null) {
            throw new RuntimeException('The invigilator has paused this sitting. Your answers are kept; carry on when it resumes.');
        }
        if (self::secondsLeft($session) <= 0) {
            $this->finish($session, 'auto_submitted');
            throw new RuntimeException('Time is up. Your answers were handed in.');
        }
        $q = $questions[$position] ?? throw new RuntimeException('There is no such question in this sitting.');
        return $this->repo->saveResponse((int) $session['institution_id'], (int) $session['id'], $position, self::cleanAnswer($q, $answer), $flagged, $seq);
    }

    /**
     * Hand a sitting in and grade it. Answers sent with the form are saved first if time allows.
     * Handing in twice does nothing the second time.
     *
     * @param array<string,mixed> $session @param array<int,string> $posted answers by position
     */
    public function submit(array $session, array $posted = []): string
    {
        if ($session['status'] !== 'in_progress') {
            return (string) $session['status'];
        }
        if (self::secondsLeft($session) <= 0) {
            $this->finish($session, 'auto_submitted');
            return 'auto_submitted';
        }
        if ($posted !== []) {
            $questions = $this->byPosition((int) $session['id']);
            $saved = $this->repo->responses((int) $session['id']);
            $seq = $this->repo->maxSeq((int) $session['id']) + 1;
            foreach ($posted as $pos => $answer) {
                if (isset($questions[$pos])) {
                    $clean = self::cleanAnswer($questions[$pos], $answer);
                    if ($clean !== (string) ($saved[$pos]['answer'] ?? '')) {
                        $this->repo->saveResponse((int) $session['institution_id'], (int) $session['id'], $pos, $clean, (bool) ($saved[$pos]['flagged'] ?? false), $seq++);
                    }
                }
            }
        }
        $this->finish($session, 'submitted');
        return 'submitted';
    }

    /** Hand in every sitting whose time is up (run by bin/sittings.php, and when results are opened). @return int how many */
    public function expireOverdue(): int
    {
        $n = 0;
        foreach ($this->repo->overdue() as $uuid) {
            $s = $this->repo->session($uuid);
            if ($s !== null && $s['status'] === 'in_progress') {
                $n += $this->finish($s, 'auto_submitted') ? 1 : 0;
            }
        }
        return $n;
    }

    /** @param array<string,mixed> $session */
    private function finish(array $session, string $status): bool
    {
        $questions = $this->repo->questions((int) $session['id']);
        $responses = $this->repo->responses((int) $session['id']);
        $grades = [];
        $score = 0.0;
        $max = 0.0;
        $pending = false;
        foreach ($questions as $q) {
            $pos = (int) $q['position'];
            $g = self::grade($q, (string) ($responses[$pos]['answer'] ?? ''), (float) $session['negative_marking']);
            $grades[$pos] = $g;
            $pending = $pending || !empty($g['pending']);
            $score += (float) $g['awarded'];
            $max += (float) $q['marks'];
        }
        $score = max(0.0, round($score, 2));
        // With written answers to mark, the percent and the pass wait for the marker.
        $percent = !$pending && $max > 0 ? round($score / $max * 100, 2) : null;
        $passed = $session['pass_percent'] !== null && $percent !== null ? $percent >= (float) $session['pass_percent'] : null;
        $done = $this->repo->finish((int) $session['id'], $status, $score, $percent, $passed, $grades, $pending ? 'awaiting' : null);
        if ($done) {
            $this->integrity->record($session, $status);
            $this->audit->record($status === 'submitted' ? 'sitting.submitted' : ($status === 'terminated' ? 'sitting.terminated' : 'sitting.auto_submitted'),
                ['institution' => $session['institution_name'], 'assessment' => $session['title'], 'student' => $session['username']],
                'institution', (string) $session['institution_uuid']);
        }
        return $done;
    }

    /**
     * The marks one answer earns. A negative number is a deduction for a wrong answer.
     *
     * @param array<string,mixed> $q a sitting question with its version's type, choices, marks and partial_credit
     * @return array{awarded:float,correct:bool}
     */
    public static function grade(array $q, string $answer, float $negative): array
    {
        $marks = (float) $q['marks'];
        if (($q['type'] ?? '') === 'essay') {
            // A person marks it. Nothing written earns nothing, and needs no marking.
            return trim($answer) === '' ? ['awarded' => 0.0, 'correct' => false] : ['awarded' => null, 'correct' => null, 'pending' => true];
        }
        $right = [];
        foreach ((array) json_decode((string) $q['choices'], true) as $c) {
            if (!empty($c['correct'])) {
                $right[] = (string) $c['key'];
            }
        }
        sort($right);
        $picked = $answer === '' ? [] : str_split($answer);
        if ($picked === []) {
            return ['awarded' => 0.0, 'correct' => false];
        }
        if ($picked === $right) {
            return ['awarded' => $marks, 'correct' => true];
        }
        $awarded = 0.0;
        if ($q['type'] === 'multiple' && (int) $q['partial_credit'] === 1 && $right !== []) {
            $hits = count(array_intersect($picked, $right));
            $misses = count(array_diff($picked, $right));
            $awarded = max(0.0, round(($hits - $misses) / count($right) * $marks, 2));
        }
        if ($awarded <= 0.0 && $negative > 0) {
            $awarded = -round($negative * $marks, 2);
        }
        return ['awarded' => $awarded, 'correct' => false];
    }

    /**
     * The picked choice keys of a question, sorted, keeping only keys it has; one at most for a
     * single choice or true or false question.
     *
     * @param array<string,mixed> $q
     */
    public static function cleanAnswer(array $q, string $answer): string
    {
        if (($q['type'] ?? '') === 'essay') {
            return mb_substr(rtrim(str_replace(["\r\n", "\r"], "\n", $answer)), 0, self::MAX_WRITTEN);
        }
        $keys = array_map(static fn(array $c): string => (string) $c['key'], (array) json_decode((string) $q['choices'], true));
        $picked = array_values(array_unique(array_intersect(str_split(strtoupper(preg_replace('/[^A-Za-z]/', '', $answer) ?? '')), $keys)));
        sort($picked);
        if ($q['type'] !== 'multiple' && count($picked) > 1) {
            $picked = [$picked[0]];
        }
        return implode('', $picked);
    }

    /**
     * Mark a sitting's written answers. Once every one is marked the sitting's score, percent and
     * pass are worked out. Marks can be changed until the results are published.
     *
     * @param array<string,mixed> $session from SittingRepository::session() @param array<string,mixed> $marker a membership
     * @param array<int,array{marks:string,feedback:string}> $marks by position
     */
    public function markWritten(array $session, array $marker, array $marks): void
    {
        if ($session['status'] === 'in_progress') {
            throw new RuntimeException('This sitting has not been handed in yet.');
        }
        if (($session['results_published_at'] ?? null) !== null) {
            throw new RuntimeException('The results are published, so the marks stay as they are. Unpublish them first.');
        }
        $questions = $this->repo->questions((int) $session['id']);
        $responses = $this->repo->responses((int) $session['id']);
        $changes = [];
        foreach ($questions as $q) {
            $pos = (int) $q['position'];
            if ($q['type'] !== 'essay' || trim((string) ($responses[$pos]['answer'] ?? '')) === '' || !isset($marks[$pos])) {
                continue;
            }
            $raw = trim((string) $marks[$pos]['marks']);
            if ($raw === '') {
                continue;
            }
            if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > (float) $q['marks']) {
                throw new RuntimeException('Question ' . $pos . ': give a mark from 0 to ' . rtrim(rtrim(number_format((float) $q['marks'], 2, '.', ''), '0'), '.') . '.');
            }
            $feedback = trim(str_replace(["\r\n", "\r"], "\n", (string) $marks[$pos]['feedback']));
            if (mb_strlen($feedback) > 5000) {
                throw new RuntimeException('Question ' . $pos . ': feedback has at most 5,000 characters.');
            }
            $changes[$pos] = [round((float) $raw, 2), $feedback !== '' ? $feedback : null, (float) $q['marks']];
        }
        if ($changes === []) {
            throw new RuntimeException('Give a mark for at least one answer.');
        }
        $this->repo->markWritten((int) $session['id'], (int) $marker['id'], $changes);
        // Every written answer marked: the sitting is complete.
        $questions = $this->repo->questions((int) $session['id']);
        $open = array_filter($questions, static fn(array $q): bool => $q['type'] === 'essay' && $q['awarded'] === null);
        if ($open === []) {
            $score = max(0.0, round(array_sum(array_map(static fn(array $q): float => (float) $q['awarded'], $questions)), 2));
            $max = array_sum(array_map(static fn(array $q): float => (float) $q['marks'], $questions));
            $percent = $max > 0 ? round($score / $max * 100, 2) : null;
            $passed = $session['pass_percent'] !== null && $percent !== null ? $percent >= (float) $session['pass_percent'] : null;
            $this->repo->setScore((int) $session['id'], $score, $percent, $passed, 'marked');
        }
        $this->audit->record('sitting.marked', ['institution' => $session['institution_name'], 'assessment' => $session['title'], 'student' => $session['username'],
            'answers' => count($changes), 'complete' => $open === []], 'institution', (string) $session['institution_uuid']);
    }

    /** @return array<int,array<string,mixed>> */
    public function byPosition(int $sessionId): array
    {
        $out = [];
        foreach ($this->repo->questions($sessionId) as $q) {
            $out[(int) $q['position']] = $q;
        }
        return $out;
    }
}
