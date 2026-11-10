<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use RuntimeException;

/**
 * Readiness for a certification exam, judged from mock exams of the library, and the
 * certificates that record it.
 *
 * The rules are fixed and shown with every judgement, so a candidate (and anyone reading their
 * certificate) can see exactly what was measured. The library's questions carry no named topics,
 * so instead of a single score readiness asks for a strong, complete and repeated performance on
 * questions the candidate did not choose:
 *
 *  - Demonstrated readiness: at least 80% on a timed mock of at least 30 questions, with at most
 *    10% left unanswered, and another attempt at the same exam in the 60 days before it at 70% or
 *    more. Both attempts must be drawn at random from all the exam's questions or from the ones
 *    not answered before (never from the candidate's mistakes, due or bookmarked questions),
 *    from at least twice as many questions as were asked, and at most half of the qualifying
 *    attempt's questions may have been in the earlier one. Only this level earns a certificate.
 *  - Nearly ready: 70% or more, or 80% without meeting the other conditions.
 *  - Developing: 55% to 69%.
 *  - Not yet ready: under 55%.
 *
 * Why the last three conditions: a candidate could otherwise bookmark thirty questions they know,
 * sit them twice and hold a numbered certificate. A random draw from a pool twice the size of the
 * attempt cannot be predicted, and limiting what two attempts share means the second is not a
 * repeat of the first. Certificates issued before these conditions stay valid.
 *
 * It measures performance on this platform's questions. It is not a prediction of the real exam,
 * a professional certification, or anything issued or endorsed by the certification's provider.
 */
final class Readiness
{
    public const LEVELS = [
        'not_yet' => 'Not yet ready',
        'developing' => 'Developing',
        'nearly' => 'Nearly ready',
        'ready' => 'Demonstrated readiness',
    ];
    public const READY = 80.0;
    public const NEARLY = 70.0;
    public const DEVELOPING = 55.0;
    public const MIN_QUESTIONS = 30;
    public const MAX_UNANSWERED = 10.0;
    public const CONSISTENT = 70.0;
    public const WINDOW_DAYS = 60;
    /** Where a mock's questions may be chosen from for it to count (MockController::SOURCES). */
    public const COUNTED_SOURCES = ['all', 'new'];
    /** The questions must be drawn from at least this many times the number asked. */
    public const POOL_FACTOR = 2;
    /** At most this share of the attempt's questions may have been in the earlier attempt. */
    public const MAX_OVERLAP = 50.0;

    public const DISCLAIMER = 'This certificate confirms performance against the stated ExamLib readiness assessment criteria. It is not a professional '
        . 'certification, a qualification issued by the certification provider, or a guarantee of success in an external examination.';

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /**
     * The judgement of one finished mock exam, with each condition and whether it was met.
     *
     * @param array<string,mixed> $mock a finished mock (decoded) @param list<array<string,mixed>> $earlier the person's other finished mocks of the same exam
     * @return array{level:string,label:string,percent:float,conditions:list<array{ok:bool,text:string}>,eligible:bool,stats:array<string,mixed>,previous:?array<string,mixed>}
     */
    public static function assess(array $mock, array $earlier): array
    {
        $total = max(0, (int) $mock['total']);
        $right = (int) $mock['right_count'];
        $percent = $total > 0 ? round($right / $total * 100, 2) : 0.0;
        $unanswered = count(array_filter((array) $mock['results'], static fn($r) => is_array($r) && ($r['picked'] ?? '') === ''));
        $unansweredPct = $total > 0 ? $unanswered / $total * 100 : 100.0;
        $finished = (int) strtotime($mock['finished_at'] . ' UTC');
        $seconds = max(0, $finished - (int) strtotime($mock['started_at'] . ' UTC'));
        // The earlier attempt: in the window, long enough, drawn the same way, and not mostly the
        // same questions. The best of those is the one shown.
        $keys = array_flip(array_map('strval', (array) ($mock['keys'] ?? [])));
        $previous = null;
        foreach ($earlier as $m) {
            $at = (int) strtotime($m['finished_at'] . ' UTC');
            if ((int) $m['id'] === (int) $mock['id'] || $at > $finished || $at < $finished - self::WINDOW_DAYS * 86400
                || (int) $m['total'] < self::MIN_QUESTIONS || !self::randomDraw($m) || !self::bigPool($m)) {
                continue;
            }
            $shared = count(array_intersect_key($keys, array_flip(array_map('strval', (array) ($m['keys'] ?? [])))));
            if ($total === 0 || $shared / $total * 100 > self::MAX_OVERLAP) {
                continue;
            }
            $p = round((int) $m['right_count'] / (int) $m['total'] * 100, 2);
            if ($previous === null || $p > $previous['percent']) {
                $previous = ['percent' => $p, 'finished_at' => $m['finished_at'], 'total' => (int) $m['total'], 'shared' => $shared];
            }
        }
        $source = (string) ($mock['source'] ?? '');
        $pool = (int) ($mock['pool_size'] ?? 0);
        $conditions = [
            ['ok' => $percent >= self::READY, 'text' => 'At least ' . (int) self::READY . '% right (' . self::fmt($percent) . '%).'],
            ['ok' => (int) $mock['minutes'] > 0, 'text' => 'Timed, under exam conditions.'],
            ['ok' => $total >= self::MIN_QUESTIONS, 'text' => 'At least ' . self::MIN_QUESTIONS . ' questions (' . $total . ').'],
            ['ok' => $unansweredPct <= self::MAX_UNANSWERED, 'text' => 'At most ' . (int) self::MAX_UNANSWERED . '% unanswered (' . $unanswered . ' of ' . $total . ').'],
            ['ok' => self::randomDraw($mock), 'text' => 'Questions drawn at random from all questions, or from ones you had not answered ('
                . match (true) { $source === '' => 'not recorded for this attempt', in_array($source, self::COUNTED_SOURCES, true) => 'yes', default => 'you chose the list' } . ').'],
            ['ok' => self::bigPool($mock), 'text' => 'Drawn from at least ' . self::POOL_FACTOR * $total . ' questions, twice the number asked ('
                . ($pool > 0 ? $pool : 'not recorded') . ').'],
            ['ok' => $previous !== null && $previous['percent'] >= self::CONSISTENT, 'text' => 'Another attempt like this in the ' . self::WINDOW_DAYS . ' days before, at '
                . (int) self::CONSISTENT . '% or more, sharing at most half of these questions'
                . ($previous !== null ? ' (best: ' . self::fmt($previous['percent']) . '%, ' . $previous['shared'] . ' shared).' : ' (none yet).')],
        ];
        $all = array_filter($conditions, static fn(array $c): bool => !$c['ok']) === [];
        $level = $all ? 'ready' : ($percent >= self::NEARLY ? 'nearly' : ($percent >= self::DEVELOPING ? 'developing' : 'not_yet'));
        return ['level' => $level, 'label' => self::LEVELS[$level], 'percent' => $percent, 'conditions' => $conditions, 'eligible' => $all, 'previous' => $previous,
            'stats' => ['answered' => $total - $unanswered, 'unanswered' => $unanswered, 'flagged' => count((array) $mock['flags']), 'seconds' => $seconds,
                'per_question' => $total > 0 ? (int) round($seconds / $total) : 0]];
    }

    /** Whether the attempt's questions were drawn at random, not from a list the candidate made. @param array<string,mixed> $m */
    public static function randomDraw(array $m): bool
    {
        return in_array((string) ($m['source'] ?? ''), self::COUNTED_SOURCES, true);
    }

    /** Whether the attempt was drawn from enough questions that it could not be predicted. @param array<string,mixed> $m */
    public static function bigPool(array $m): bool
    {
        return (int) ($m['total'] ?? 0) > 0 && (int) ($m['pool_size'] ?? 0) >= self::POOL_FACTOR * (int) $m['total'];
    }

    /**
     * Issue the certificate a finished mock earned, once: asking again returns the same one.
     *
     * @param array<string,mixed> $mock @param list<array<string,mixed>> $earlier @param array<string,mixed> $user @param array<string,mixed> $exam
     * @return array<string,mixed> the certificate
     */
    public function issue(array $mock, array $earlier, array $user, array $exam, string $examTitle): array
    {
        $existing = $this->db->one('SELECT * FROM certificates WHERE mock_id = ?', [(int) $mock['id']]);
        if ($existing !== null) {
            return $existing;
        }
        $a = self::assess($mock, $earlier);
        if (!$a['eligible']) {
            throw new RuntimeException('This attempt does not meet the conditions for a certificate.');
        }
        $now = Database::now();
        $year = gmdate('Y');
        return $this->db->transaction(function (Database $db) use ($mock, $user, $exam, $examTitle, $a, $now, $year): array {
            $next = (int) $db->value('SELECT COUNT(*) FROM certificates WHERE number LIKE ? FOR UPDATE', ['EL-' . $year . '-%']) + 1;
            $uuid = Ids::uuid();
            $db->run('INSERT INTO certificates (uuid, number, token, kind, user_id, mock_id, exam_id, holder_name, exam_title, percent, questions, level, issued_at)
                      VALUES (?, ?, ?, \'readiness\', ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$uuid, sprintf('EL-%s-%06d', $year, $next), bin2hex(random_bytes(16)), (int) $user['id'], (int) $mock['id'], (int) $exam['id'],
                    mb_substr(Access::displayName($user), 0, 190), mb_substr($examTitle, 0, 255), number_format($a['percent'], 2, '.', ''), (int) $mock['total'], 'ready', $now]);
            $cert = (array) $db->one('SELECT * FROM certificates WHERE uuid = ?', [$uuid]);
            $this->audit->record('certificate.issued', ['number' => $cert['number'], 'exam' => $examTitle], 'certificate', (string) $cert['number']);
            return $cert;
        });
    }

    /** @param array<string,mixed> $cert @param array<string,mixed> $by */
    public function revoke(array $cert, string $reason, array $by): void
    {
        $reason = mb_substr(trim($reason), 0, 1000);
        if ($reason === '') {
            throw new RuntimeException('Give the reason. It is shown on the verification page.');
        }
        if ($cert['status'] !== 'issued') {
            throw new RuntimeException('That certificate is already revoked.');
        }
        $this->db->run("UPDATE certificates SET status = 'revoked', revoked_at = ?, revoked_by = ?, revoke_reason = ? WHERE id = ?",
            [Database::now(), (int) $by['id'], $reason, (int) $cert['id']]);
        $this->audit->record('certificate.revoked', ['number' => $cert['number'], 'holder' => $cert['holder_name']], 'certificate', (string) $cert['number']);
    }

    /** @return array<string,mixed>|null */
    public function byToken(string $token): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? $this->db->one('SELECT * FROM certificates WHERE token = ?', [$token]) : null;
    }

    /** @return array<string,mixed>|null */
    public function byUuid(string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM certificates WHERE uuid = ?', [$uuid]) : null;
    }

    /** @return array<string,mixed>|null */
    public function forMock(int $mockId): ?array
    {
        return $this->db->one('SELECT * FROM certificates WHERE mock_id = ?', [$mockId]);
    }

    /** @return list<array<string,mixed>> */
    public function mine(int $userId): array
    {
        return $this->db->all('SELECT * FROM certificates WHERE user_id = ? ORDER BY issued_at DESC', [$userId]);
    }

    /** @return list<array<string,mixed>> every certificate, newest first, for the platform */
    public function all(string $q = ''): array
    {
        $sql = 'SELECT * FROM certificates';
        $args = [];
        if ($q !== '') {
            $sql .= ' WHERE number LIKE ? OR holder_name LIKE ? OR exam_title LIKE ?';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $args = [$like, $like, $like];
        }
        return $this->db->all($sql . ' ORDER BY issued_at DESC LIMIT 1000', $args);
    }

    public static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
