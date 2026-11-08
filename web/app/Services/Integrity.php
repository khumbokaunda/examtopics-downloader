<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Examination integrity monitoring: what a sitting's browser reports (by its monitoring
 * policy), what the server records itself, the rule-based indicators worked out from them, and
 * people's conclusions.
 *
 * An event is an observation, never a verdict. A hidden page or a lost focus has many innocent
 * causes (a notification, a call, an accessibility tool, a flaky connection), so reports use
 * neutral words ("the page was hidden") and indicators only suggest where a person might look.
 * Nothing here marks a candidate down or invalidates a result. No keystrokes and nothing copied
 * or pasted is ever recorded: only that a copy, cut or paste happened, under the enhanced policy.
 */
final class Integrity
{
    public const POLICIES = [
        'basic' => ['name' => 'Basic', 'text' => 'Timing and answers only. Nothing about the page or the device is recorded.'],
        'standard' => ['name' => 'Standard', 'text' => 'Also when the page is hidden or loses focus, page reloads, and when the connection drops.'],
        'enhanced' => ['name' => 'Enhanced', 'text' => 'Also leaving full screen, and each time something is copied, cut or pasted (never what it was).'],
    ];

    /** What a browser may report under each policy. */
    private const TYPES = [
        'basic' => [],
        'standard' => ['hidden', 'visible', 'blur', 'focus', 'offline', 'online'],
        'enhanced' => ['hidden', 'visible', 'blur', 'focus', 'offline', 'online', 'fullscreen_exit', 'fullscreen_enter', 'copy', 'cut', 'paste'],
    ];

    public const LABELS = [
        'opened' => 'The sitting page was opened', 'hidden' => 'The page was hidden', 'visible' => 'The page was shown again', 'blur' => 'The window lost focus',
        'focus' => 'The window had focus again', 'offline' => 'The connection was lost', 'online' => 'The connection came back', 'fullscreen_exit' => 'Full screen was left',
        'fullscreen_enter' => 'Full screen was entered', 'copy' => 'Something was copied', 'cut' => 'Something was cut', 'paste' => 'Something was pasted',
        'submitted' => 'Handed in by the candidate', 'auto_submitted' => 'Handed in by the server when time ran out', 'paused' => 'Paused by an invigilator',
        'resumed' => 'Resumed by an invigilator', 'extended' => 'Extra time given', 'terminated' => 'Ended by an examination officer',
    ];

    /** Most events kept for one sitting; more are ignored (a misbehaving page cannot fill the table). */
    public const MAX_EVENTS = 2000;

    public const OUTCOMES = ['no_concern' => 'No concern', 'concern_noted' => 'Concern noted', 'refer' => 'Referred for a decision'];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** @return list<string> the event types a browser reports under a policy */
    public static function types(string $policy): array
    {
        return self::TYPES[$policy] ?? [];
    }

    /**
     * Store events a sitting's browser sent. Unknown types, types its policy does not collect,
     * and repeats (the same seq and type) are dropped.
     *
     * @param array<string,mixed> $session @param list<mixed> $events
     * @return int how many were stored
     */
    public function ingest(array $session, array $events): int
    {
        $allowed = array_flip(self::types((string) $session['monitoring']));
        if ($allowed === [] || $events === []) {
            return 0;
        }
        $room = self::MAX_EVENTS - (int) $this->db->value('SELECT COUNT(*) FROM assessment_events WHERE session_id = ?', [(int) $session['id']]);
        $now = Database::now();
        $n = 0;
        foreach (array_slice($events, 0, 100) as $e) {
            if ($room <= 0 || !is_array($e)) {
                break;
            }
            $type = (string) ($e['type'] ?? '');
            $seq = $e['seq'] ?? null;
            if (!isset($allowed[$type]) || !is_int($seq) || $seq < 1) {
                continue;
            }
            $at = is_int($e['at'] ?? null) && $e['at'] > 0 ? gmdate('Y-m-d H:i:s', intdiv((int) $e['at'], 1000)) : null;
            $duration = is_int($e['duration_ms'] ?? null) && $e['duration_ms'] >= 0 ? min((int) $e['duration_ms'], 86400000) : null;
            $added = $this->db->run('INSERT IGNORE INTO assessment_events (session_id, institution_id, seq, type, source, client_at, received_at, duration_ms)
                                     VALUES (?, ?, ?, ?, \'browser\', ?, ?, ?)', [(int) $session['id'], (int) $session['institution_id'], $seq, $type, $at, $now, $duration])->rowCount();
            $n += $added;
            $room -= $added;
        }
        return $n;
    }

    /** Record something the server saw or did (a page opened, a hand-in, an invigilator's action). @param array<string,mixed> $session */
    public function record(array $session, string $type, ?int $durationMs = null): void
    {
        $this->db->run('INSERT INTO assessment_events (session_id, institution_id, seq, type, source, received_at, duration_ms) VALUES (?, ?, ?, ?, \'server\', ?, ?)',
            [(int) $session['id'], (int) $session['institution_id'], (int) (microtime(true) * 1000), $type, Database::now(), $durationMs]);
    }

    /** @return list<array<string,mixed>> a sitting's events in the order received */
    public function events(int $sessionId): array
    {
        return $this->db->all('SELECT * FROM assessment_events WHERE session_id = ? ORDER BY id', [$sessionId]);
    }

    /**
     * Figures and rule-based indicators for a sitting. Each indicator has a level: info (worth
     * knowing), review (worth a look) or technical (likely a device or connection problem).
     *
     * @param list<array<string,mixed>> $events
     * @return array{figures:array<string,int>,indicators:list<array{level:string,text:string}>,level:string}
     */
    public static function summary(array $events): array
    {
        $count = static fn(string $t): int => count(array_filter($events, static fn(array $e): bool => $e['type'] === $t));
        $hiddenMs = 0;
        $longest = 0;
        foreach ($events as $e) {
            if ($e['type'] === 'visible' && $e['duration_ms'] !== null) {
                $hiddenMs += (int) $e['duration_ms'];
                $longest = max($longest, (int) $e['duration_ms']);
            }
        }
        $f = [
            'hidden' => $count('hidden'), 'blur' => $count('blur'), 'hidden_seconds' => intdiv($hiddenMs, 1000), 'longest_hidden' => intdiv($longest, 1000),
            'fullscreen_exit' => $count('fullscreen_exit'), 'paste' => $count('paste'), 'copy' => $count('copy') + $count('cut'),
            'reloads' => max(0, $count('opened') - 1), 'offline' => $count('offline'),
        ];
        $i = [];
        $away = max($f['hidden'], $f['blur']);
        if ($away >= 5) {
            $i[] = ['level' => 'review', 'text' => 'The page was hidden or lost focus ' . $away . ' times.'];
        } elseif ($away > 0) {
            $i[] = ['level' => 'info', 'text' => 'The page was hidden or lost focus ' . $away . ' ' . ($away === 1 ? 'time' : 'times') . '.'];
        }
        if ($f['hidden_seconds'] >= 120 || $f['longest_hidden'] >= 60) {
            $i[] = ['level' => 'review', 'text' => 'The page was hidden for ' . $f['hidden_seconds'] . ' seconds in all, the longest spell ' . $f['longest_hidden'] . ' seconds.'];
        }
        if ($f['fullscreen_exit'] > 0) {
            $i[] = ['level' => $f['fullscreen_exit'] >= 3 ? 'review' : 'info', 'text' => 'Full screen was left ' . $f['fullscreen_exit'] . ' ' . ($f['fullscreen_exit'] === 1 ? 'time' : 'times') . '.'];
        }
        if ($f['paste'] > 0) {
            $i[] = ['level' => $f['paste'] >= 3 ? 'review' : 'info', 'text' => 'Something was pasted ' . $f['paste'] . ' ' . ($f['paste'] === 1 ? 'time' : 'times') . '.'];
        }
        if ($f['reloads'] >= 3) {
            $i[] = ['level' => 'technical', 'text' => 'The page was opened again ' . $f['reloads'] . ' times (reloads, or a lost connection).'];
        }
        if ($f['offline'] >= 3) {
            $i[] = ['level' => 'technical', 'text' => 'The connection dropped ' . $f['offline'] . ' times.'];
        }
        $levels = array_column($i, 'level');
        $level = in_array('review', $levels, true) ? 'review' : (in_array('technical', $levels, true) ? 'technical' : ($i !== [] ? 'info' : 'none'));
        return ['figures' => $f, 'indicators' => $i, 'level' => $level];
    }

    /** @return array<int,string> each listed sitting's indicator level, by session id */
    public function levels(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $by = [];
        foreach ($this->db->all('SELECT session_id, type, duration_ms FROM assessment_events WHERE session_id IN (' . implode(',', array_fill(0, count($sessionIds), '?')) . ')', $sessionIds) as $e) {
            $by[(int) $e['session_id']][] = $e;
        }
        $out = [];
        foreach ($sessionIds as $id) {
            $out[(int) $id] = self::summary($by[(int) $id] ?? [])['level'];
        }
        return $out;
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $i @param array<string,mixed> $m */
    public function review(array $i, array $m, array $session, string $outcome, string $note): void
    {
        $note = mb_substr(trim($note), 0, 2000);
        if (!isset(self::OUTCOMES[$outcome])) {
            throw new RuntimeException('Choose a conclusion.');
        }
        if ($note === '') {
            throw new RuntimeException('Write what the conclusion rests on.');
        }
        $this->db->run('INSERT INTO integrity_reviews (session_id, institution_id, outcome, note, membership_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [(int) $session['id'], (int) $i['id'], $outcome, $note, (int) $m['id'], Database::now()]);
        $this->audit->record('institution.integrity_reviewed', ['institution' => $i['name'], 'student' => $session['username'], 'outcome' => $outcome], 'institution', (string) $i['uuid']);
    }

    /** @return list<array<string,mixed>> with who wrote each */
    public function reviews(int $sessionId): array
    {
        return $this->db->all("SELECT r.*, COALESCE(NULLIF(u.display_name, ''), u.username) AS author FROM integrity_reviews r
                               LEFT JOIN institution_memberships m ON m.id = r.membership_id LEFT JOIN users u ON u.id = m.user_id
                               WHERE r.session_id = ? ORDER BY r.id DESC", [$sessionId]);
    }
}
