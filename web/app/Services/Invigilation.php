<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Live invigilation: who watches an assessment, the live state of every candidate, observations,
 * and interventions on a sitting.
 *
 * - Watching (the live page and observations) is for assessments.monitor, or for an invigilator
 *   assigned to the assessment.
 * - Intervening (pause, resume, extra time, ending a sitting) needs assessments.intervene. A
 *   paused sitting's clock stops: resuming moves its end by the time it was paused.
 *
 * Live state comes from polling (every 15 seconds on the page), not a socket: what a sitting
 * last saved or reported, and whether that was recent enough to call it connected.
 */
final class Invigilation
{
    public const CATEGORIES = [
        'note' => 'Note', 'incident' => 'Incident', 'technical' => 'Technical difficulty', 'interruption' => 'Interruption', 'identity_checked' => 'Identity checked',
    ];
    /** Seconds since the last save or report within which a sitting counts as connected. */
    public const CONNECTED = 120;

    public function __construct(private Database $db, private Integrity $integrity, private AuditLog $audit)
    {
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $a */
    public function canWatch(array $m, array $a): bool
    {
        return InstitutionAccess::can($m, 'assessments.monitor')
            || $this->db->value('SELECT 1 FROM invigilator_assignments WHERE assessment_id = ? AND membership_id = ?', [(int) $a['id'], (int) $m['id']]) !== null;
    }

    /** @return list<array<string,mixed>> the assessment's invigilators */
    public function invigilators(int $iid, int $assessmentId): array
    {
        return $this->db->all("SELECT m.id, m.uuid, m.role, u.username, u.display_name FROM invigilator_assignments x
                               JOIN institution_memberships m ON m.id = x.membership_id JOIN users u ON u.id = m.user_id
                               WHERE x.institution_id = ? AND x.assessment_id = ? AND m.status = 'active' ORDER BY u.display_name, u.username", [$iid, $assessmentId]);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $a @param list<array<string,mixed>> $people active staff memberships of this institution */
    public function setInvigilators(array $i, array $a, array $people): void
    {
        foreach ($people as $p) {
            if ($p['status'] !== 'active' || $p['role'] === InstitutionAccess::STUDENT) {
                throw new RuntimeException('Only the institution\'s staff can invigilate.');
            }
        }
        $this->db->transaction(function (Database $db) use ($i, $a, $people): void {
            $db->run('DELETE FROM invigilator_assignments WHERE institution_id = ? AND assessment_id = ?', [(int) $i['id'], (int) $a['id']]);
            foreach ($people as $p) {
                $db->run('INSERT INTO invigilator_assignments (assessment_id, membership_id, institution_id, assigned_at) VALUES (?, ?, ?, ?)',
                    [(int) $a['id'], (int) $p['id'], (int) $i['id'], Database::now()]);
            }
        });
        $this->audit->record('institution.invigilators_set', ['institution' => $i['name'], 'assessment' => $a['title'],
            'invigilators' => array_map(static fn(array $p): string => (string) $p['username'], $people)], 'institution', (string) $i['uuid']);
    }

    /**
     * Every candidate the assessment reaches, with their latest sitting's live state.
     *
     * @return list<array<string,mixed>>
     */
    public function live(int $iid, int $assessmentId): array
    {
        $rows = $this->db->all("SELECT m.id AS membership_id, m.uuid AS membership_uuid, u.username, u.display_name, st.student_number,
                                       s.id, s.uuid, s.status, s.started_at, s.ends_at, s.paused_at, s.extra_minutes, s.monitoring,
                                       (SELECT COUNT(*) FROM assessment_session_questions q WHERE q.session_id = s.id) AS question_count,
                                       (SELECT COUNT(*) FROM assessment_responses r WHERE r.session_id = s.id AND r.answer <> '') AS answered,
                                       GREATEST(COALESCE((SELECT MAX(r.saved_at) FROM assessment_responses r WHERE r.session_id = s.id), '1970-01-01'),
                                                COALESCE((SELECT MAX(e.received_at) FROM assessment_events e WHERE e.session_id = s.id), '1970-01-01'),
                                                COALESCE(s.started_at, '1970-01-01')) AS last_seen,
                                       (SELECT COUNT(*) FROM invigilator_messages g WHERE g.session_id = s.id) AS messages_sent,
                                       (SELECT COUNT(*) FROM invigilator_messages g WHERE g.session_id = s.id AND g.seen_at IS NULL) AS messages_unseen
                                FROM institution_memberships m JOIN users u ON u.id = m.user_id
                                LEFT JOIN institution_students st ON st.membership_id = m.id
                                LEFT JOIN assessment_sessions s ON s.id = (SELECT MAX(t.id) FROM assessment_sessions t WHERE t.assessment_id = ? AND t.membership_id = m.id)
                                WHERE m.institution_id = ? AND m.status = 'active' AND m.role = 'student' AND (
                                      m.id IN (SELECT membership_id FROM assessment_assignments WHERE assessment_id = ? AND membership_id IS NOT NULL)
                                   OR m.id IN (SELECT e.membership_id FROM class_enrolments e JOIN assessment_assignments x ON x.class_id = e.class_id
                                               WHERE x.assessment_id = ? AND e.status = 'active'))
                                ORDER BY u.display_name, u.username", [$assessmentId, $iid, $assessmentId, $assessmentId]);
        $levels = $this->integrity->levels(array_values(array_filter(array_map(static fn(array $r) => $r['id'] !== null ? (int) $r['id'] : null, $rows))));
        $now = time();
        return array_map(static function (array $r) use ($levels, $now): array {
            $live = $r['status'] === 'in_progress';
            $left = $live ? Sittings::secondsLeft($r) : null;
            return $r + ['state' => $r['status'] === null ? 'not_started' : ($live ? ($r['paused_at'] !== null ? 'paused' : 'writing') : 'handed_in'),
                'seconds_left' => $left, 'connected' => $live && $r['paused_at'] === null && $now - (int) strtotime($r['last_seen'] . ' UTC') <= self::CONNECTED,
                'level' => $r['id'] !== null ? ($levels[(int) $r['id']] ?? 'none') : 'none'];
        }, $rows);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $session */
    public function observe(array $i, array $m, array $session, string $category, string $note): void
    {
        $note = mb_substr(trim($note), 0, 2000);
        if (!isset(self::CATEGORIES[$category])) {
            throw new RuntimeException('Choose what kind of observation it is.');
        }
        if ($note === '' && $category !== 'identity_checked') {
            throw new RuntimeException('Write what you observed.');
        }
        $this->db->run('INSERT INTO invigilator_observations (session_id, institution_id, category, note, membership_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [(int) $session['id'], (int) $i['id'], $category, $note !== '' ? $note : 'Identity checked.', (int) $m['id'], Database::now()]);
        $this->audit->record('institution.observation_recorded', ['institution' => $i['name'], 'student' => $session['username'], 'category' => $category], 'institution', (string) $i['uuid']);
    }

    /**
     * Send a message to one candidate's sitting in progress.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $session
     */
    public function message(array $i, array $m, array $session, string $body): void
    {
        if ($session['status'] !== 'in_progress') {
            throw new RuntimeException('That sitting has been handed in.');
        }
        $this->insertMessage($i, $m, (int) $session['id'], self::cleanMessage($body));
        $this->audit->record('institution.message_sent', ['institution' => $i['name'], 'student' => $session['username'], 'assessment' => $session['title']],
            'institution', (string) $i['uuid']);
    }

    /**
     * Send a message to everyone writing the assessment now. @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $a
     * @return int how many sittings it reached
     */
    public function broadcast(array $i, array $m, array $a, string $body): int
    {
        $body = self::cleanMessage($body);
        $ids = array_map('intval', array_column($this->db->all("SELECT id FROM assessment_sessions WHERE institution_id = ? AND assessment_id = ? AND status = 'in_progress'",
            [(int) $i['id'], (int) $a['id']]), 'id'));
        if ($ids === []) {
            throw new RuntimeException('Nobody is writing it now.');
        }
        foreach ($ids as $id) {
            $this->insertMessage($i, $m, $id, $body);
        }
        $this->audit->record('institution.message_sent', ['institution' => $i['name'], 'assessment' => $a['title'], 'count' => count($ids)], 'institution', (string) $i['uuid']);
        return count($ids);
    }

    /**
     * A sitting's messages, oldest first. For the candidate's page, unseen ones are marked seen.
     *
     * @return list<array{id:int,body:string,created_at:string,seen_at:?string}>
     */
    public function messages(int $sessionId, bool $markSeen = false): array
    {
        if ($markSeen) {
            $this->db->run('UPDATE invigilator_messages SET seen_at = ? WHERE session_id = ? AND seen_at IS NULL', [Database::now(), $sessionId]);
        }
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'body' => (string) $r['body'], 'created_at' => (string) $r['created_at'],
            'seen_at' => $r['seen_at']], $this->db->all('SELECT id, body, created_at, seen_at FROM invigilator_messages WHERE session_id = ? ORDER BY id', [$sessionId]));
    }

    private static function cleanMessage(string $body): string
    {
        $body = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $body)), 0, 500);
        if ($body === '') {
            throw new RuntimeException('Write the message.');
        }
        return $body;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $m */
    private function insertMessage(array $i, array $m, int $sessionId, string $body): void
    {
        $this->db->run('INSERT INTO invigilator_messages (session_id, institution_id, body, membership_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [$sessionId, (int) $i['id'], $body, (int) $m['id'], Database::now()]);
    }

    /** @return list<array<string,mixed>> with who wrote each */
    public function observations(int $sessionId): array
    {
        return $this->db->all("SELECT o.*, COALESCE(NULLIF(u.display_name, ''), u.username) AS author FROM invigilator_observations o
                               LEFT JOIN institution_memberships m ON m.id = o.membership_id LEFT JOIN users u ON u.id = m.user_id
                               WHERE o.session_id = ? ORDER BY o.id DESC", [$sessionId]);
    }

    /**
     * Pause, resume, give extra minutes or end a sitting in progress.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $session from SittingRepository::session()
     */
    public function intervene(array $i, array $session, string $action, int $minutes, string $reason, Sittings $sittings): string
    {
        if ($session['status'] !== 'in_progress') {
            throw new RuntimeException('That sitting has been handed in.');
        }
        $now = Database::now();
        $reason = mb_substr(trim($reason), 0, 1000);
        switch ($action) {
            case 'pause':
                if ($session['paused_at'] !== null) {
                    throw new RuntimeException('It is already paused.');
                }
                $this->db->run('UPDATE assessment_sessions SET paused_at = ? WHERE id = ? AND status = \'in_progress\'', [$now, (int) $session['id']]);
                $this->integrity->record($session, 'paused');
                $done = 'Paused. The candidate\'s clock has stopped.';
                break;
            case 'resume':
                if ($session['paused_at'] === null) {
                    throw new RuntimeException('It is not paused.');
                }
                $pausedMs = max(0, time() - (int) strtotime($session['paused_at'] . ' UTC')) * 1000;
                $this->db->run('UPDATE assessment_sessions SET ends_at = DATE_ADD(ends_at, INTERVAL TIMESTAMPDIFF(SECOND, paused_at, ?) SECOND), paused_at = NULL WHERE id = ?',
                    [$now, (int) $session['id']]);
                $this->integrity->record($session, 'resumed', $pausedMs);
                $done = 'Resumed. The time it was paused was added back.';
                break;
            case 'extend':
                if ($minutes < 1 || $minutes > 240) {
                    throw new RuntimeException('Give from 1 to 240 extra minutes.');
                }
                if ($reason === '') {
                    throw new RuntimeException('Say why the extra time is given.');
                }
                $this->db->run('UPDATE assessment_sessions SET ends_at = DATE_ADD(ends_at, INTERVAL ? MINUTE), extra_minutes = extra_minutes + ? WHERE id = ?',
                    [$minutes, $minutes, (int) $session['id']]);
                $this->integrity->record($session, 'extended', $minutes * 60000);
                $done = $minutes . ' extra minutes given.';
                break;
            case 'terminate':
                if ($reason === '') {
                    throw new RuntimeException('Say why the sitting is ended. It is kept with the sitting.');
                }
                $this->integrity->record($session, 'terminated');
                $sittings->terminate($session);
                $done = 'The sitting is ended and marked with the answers it had.';
                break;
            default:
                throw new RuntimeException('Choose what to do.');
        }
        $names = ['pause' => 'institution.sitting_paused', 'resume' => 'institution.sitting_resumed', 'extend' => 'institution.sitting_extended', 'terminate' => 'institution.sitting_ended'];
        $this->audit->record($names[$action], ['institution' => $i['name'], 'student' => $session['username'],
            'assessment' => $session['title'], 'minutes' => $minutes, 'reason' => $reason], 'institution', (string) $i['uuid']);
        return $done;
    }
}
