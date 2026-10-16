<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use App\Core\Logger;
use App\Repositories\UserRepository;

/**
 * Requests for exams that have not been collected yet. A person has at most one open request
 * per exam (a UNIQUE open_key). Each new request notifies the people who handle requests, in
 * the inbox and by email. Answering one (collected or declined) notifies the person who asked;
 * so does a collection of that exam finishing, which closes every open request for it.
 */
final class ExamRequests
{
    public const STATUSES = ['open' => 'Open', 'collected' => 'Collected', 'declined' => 'Declined', 'cancelled' => 'Cancelled'];

    public function __construct(private Database $db, private UserRepository $users, private Notifier $notifier, private Logger $log)
    {
    }

    /**
     * @param array<string,mixed> $user @param array<string,mixed> $provider
     * @return array{0:?string,1:?array<string,mixed>} an error, or the new request
     */
    public function create(array $user, array $provider, string $exam, string $note): array
    {
        $exam = strtolower(trim($exam));
        if (!Ids::isExam($exam)) {
            return ['Exam codes use letters, digits, hyphens and underscores, as in ExamTopics addresses (for example h12-811).', null];
        }
        $collected = (int) $this->db->value(
            'SELECT COUNT(*) FROM exams WHERE provider_id = ? AND code = ? AND latest_version_id IS NOT NULL', [(int) $provider['id'], $exam]);
        if ($collected > 0) {
            return ['That exam is already in the library.', null];
        }
        // Once a provider's exam list is loaded, requests are chosen from it; a code is only
        // typed for a provider whose list has never been fetched.
        $listed = (int) $this->db->value('SELECT COUNT(*) FROM exams WHERE provider_id = ? AND in_inventory = 1', [(int) $provider['id']]);
        if ($listed > 0 && (int) $this->db->value('SELECT COUNT(*) FROM exams WHERE provider_id = ? AND code = ? AND in_inventory = 1', [(int) $provider['id'], $exam]) === 0) {
            return [strtoupper($exam) . ' is not on ' . provider_name($provider) . '\'s exam list. Choose the exam from the list.', null];
        }
        $note = mb_substr(trim($note), 0, 500);
        $uuid = Ids::uuid();
        $now = Database::now();
        try {
            $this->db->run(
                "INSERT INTO exam_requests (uuid, user_id, provider_id, exam_code, note, status, open_key, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'open', ?, ?, ?)",
                [$uuid, (int) $user['id'], (int) $provider['id'], $exam, $note !== '' ? $note : null,
                    self::openKey((int) $user['id'], (int) $provider['id'], $exam), $now, $now]
            );
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return ['You have already requested this exam. You will be told when it is collected.', null];
            }
            throw $e;
        }
        $request = (array) $this->find($uuid);
        $others = (int) $this->db->value("SELECT COUNT(*) FROM exam_requests WHERE provider_id = ? AND exam_code = ? AND status = 'open'",
            [(int) $provider['id'], $exam]) - 1;
        $label = strtoupper($exam) . ' (' . provider_name($provider) . ')';
        $this->notifier->notifyPermission('requests.manage', 'exam_request', 'Exam request: ' . $label,
            Access::displayName($user) . ' asked for ' . $label . ($others > 0 ? ', also asked for by ' . $others . ' other' . ($others === 1 ? '' : 's') : '')
            . '.' . ($note !== '' ? ' Note: ' . $note : ''),
            '/requests?status=open&provider=' . rawurlencode((string) $provider['slug']) . '&exam=' . rawurlencode($exam), 'request:' . $provider['slug'] . ':' . $exam, 'exam-request:' . $uuid, (int) $user['id']);
        $this->log->info('app', 'Exam requested', ['username' => $user['username'], 'provider' => $provider['slug'], 'exam' => $exam]);
        return [null, $request];
    }

    /**
     * Answer an open request. "collected" closes every open request for that exam.
     *
     * @param array<string,mixed> $request @param array<string,mixed> $by
     */
    public function answer(array $request, string $status, string $response, array $by): void
    {
        if ($request['status'] !== 'open' || !in_array($status, ['collected', 'declined'], true)) {
            return;
        }
        $response = mb_substr(trim($response), 0, 500);
        if ($status === 'collected') {
            $this->closeExam((int) $request['provider_id'], (string) $request['exam_code'], $response, (int) $by['id']);
            return;
        }
        $this->close([$request], 'declined', $response, (int) $by['id']);
    }

    /** A collection of this exam finished: close its open requests and tell the people who asked. */
    public function collected(int $providerId, string $exam): void
    {
        try {
            $this->closeExam($providerId, $exam, '', null);
        } catch (\Throwable $e) {
            $this->log->error('app', 'Closing exam requests failed', ['exam' => $exam, 'error' => $e->getMessage()]);
        }
    }

    /** @param array<string,mixed> $request */
    public function cancel(array $request): void
    {
        if ($request['status'] === 'open') {
            $this->db->run("UPDATE exam_requests SET status = 'cancelled', open_key = NULL, updated_at = ?, resolved_at = ? WHERE id = ?",
                [Database::now(), Database::now(), (int) $request['id']]);
            $this->resolveIfNoneOpen((int) $request['provider_id'], (string) $request['exam_code']);
        }
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one(self::SELECT . ' WHERE r.uuid = ?', [$uuid]) : null;
    }

    /**
     * Requests to list: everyone's for a manager, else only this person's.
     *
     * @return list<array<string,mixed>>
     */
    public function list(?int $userId, string $status): array
    {
        $where = [];
        $args = [];
        if ($userId !== null) {
            $where[] = 'r.user_id = ?';
            $args[] = $userId;
        }
        if (isset(self::STATUSES[$status])) {
            $where[] = 'r.status = ?';
            $args[] = $status;
        }
        return $this->db->all(self::SELECT . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY r.status = 'open' DESC, r.created_at DESC LIMIT 500", $args);
    }

    /** @return array<string,true> "<exam code>" => true for this person's open requests at a provider */
    public function openFor(int $userId, int $providerId): array
    {
        $out = [];
        foreach ($this->db->all("SELECT exam_code FROM exam_requests WHERE user_id = ? AND provider_id = ? AND status = 'open'", [$userId, $providerId]) as $r) {
            $out[(string) $r['exam_code']] = true;
        }
        return $out;
    }

    public function openCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM exam_requests WHERE status = 'open'");
    }

    private function closeExam(int $providerId, string $exam, string $response, ?int $byId): void
    {
        $open = $this->db->all(self::SELECT . " WHERE r.provider_id = ? AND r.exam_code = ? AND r.status = 'open'", [$providerId, $exam]);
        $this->close($open, 'collected', $response, $byId);
    }

    /** @param list<array<string,mixed>> $requests */
    private function close(array $requests, string $status, string $response, ?int $byId): void
    {
        $now = Database::now();
        foreach ($requests as $r) {
            $this->db->run('UPDATE exam_requests SET status = ?, response = ?, open_key = NULL, updated_at = ?, resolved_at = ?, resolved_by = ? WHERE id = ? AND status = ?',
                [$status, $response !== '' ? $response : null, $now, $now, $byId, (int) $r['id'], 'open']);
            $user = $this->users->find((int) $r['user_id']);
            if ($user === null) {
                continue;
            }
            $label = strtoupper((string) $r['exam_code']) . ' (' . provider_name(['name' => $r['provider_name'], 'slug' => $r['provider_slug']]) . ')';
            $link = $status === 'collected'
                ? '/library/' . rawurlencode((string) $r['provider_slug']) . '/' . rawurlencode((string) $r['exam_code'])
                : '/requests';
            $this->notifier->notify($user, 'request_update',
                $status === 'collected' ? $label . ' is in the library' : 'Your request for ' . $label . ' was declined',
                ($status === 'collected' ? 'The exam you asked for, ' . $label . ', has been collected and is ready to read.' : 'Your request for ' . $label . ' was declined.')
                . ($response !== '' ? ' ' . ($status === 'collected' ? 'Note: ' : 'Reason: ') . $response : ''),
                $link, null, 'request-' . $status . ':' . $r['uuid']);
            $this->resolveIfNoneOpen((int) $r['provider_id'], (string) $r['exam_code']);
        }
    }

    /** The managers' "new request" notifications for an exam are done once nobody waits on it. */
    private function resolveIfNoneOpen(int $providerId, string $exam): void
    {
        $open = (int) $this->db->value("SELECT COUNT(*) FROM exam_requests WHERE provider_id = ? AND exam_code = ? AND status = 'open'", [$providerId, $exam]);
        if ($open === 0) {
            $slug = (string) $this->db->value('SELECT slug FROM providers WHERE id = ?', [$providerId]);
            $this->notifier->resolve('request:' . $slug . ':' . $exam);
        }
    }

    private static function openKey(int $userId, int $providerId, string $exam): string
    {
        return $userId . ':' . $providerId . ':' . $exam;
    }

    private const SELECT = 'SELECT r.*, p.slug AS provider_slug, p.name AS provider_name, u.username, u.display_name,
            (SELECT COUNT(*) FROM exam_requests o WHERE o.provider_id = r.provider_id AND o.exam_code = r.exam_code AND o.status = \'open\') AS open_for_exam
        FROM exam_requests r JOIN providers p ON p.id = r.provider_id JOIN users u ON u.id = r.user_id';
}
