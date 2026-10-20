<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\UserRepository;

/**
 * The inbox, and the emails that go with it. A notification is a row for one person: a kind,
 * a title, a line of detail and a link into the app. A second event of the same kind with
 * the same link merges into the unread row (its count goes up) instead of piling up.
 * resolve() marks a whole family read once it is dealt with (a request answered, an account
 * approved), so nobody acts on it twice.
 *
 * Email goes through MailQueue with an idempotency key, only to verified addresses of people
 * who have not turned email off on their account page.
 *
 * Notifying is best effort: a failure is logged and never breaks the action that caused it.
 */
final class Notifier
{
    public const KINDS = [
        'exam_request' => 'Exam requests',
        'request_update' => 'Your requests',
        'account' => 'Accounts',
        'security' => 'Sign-in security',
    ];

    public function __construct(
        private Database $db,
        private UserRepository $users,
        private MailQueue $mail,
        private Mailer $mailer,
        private Logger $log,
    ) {
    }

    /**
     * @param array<string,mixed> $user a users row
     * @param string $eventKey identifies the event, so its email is sent once
     */
    public function notify(array $user, string $kind, string $title, string $body, string $link, ?string $resolveKey, string $eventKey): void
    {
        try {
            $now = Database::now();
            $existing = $this->db->one(
                'SELECT id FROM notifications WHERE user_id = ? AND kind = ? AND link = ? AND read_at IS NULL ORDER BY id DESC LIMIT 1',
                [(int) $user['id'], $kind, $link]
            );
            if ($existing !== null) {
                $this->db->run('UPDATE notifications SET title = ?, body = ?, count = count + 1, updated_at = ? WHERE id = ?',
                    [mb_substr($title, 0, 255), mb_substr($body, 0, 1000), $now, (int) $existing['id']]);
            } else {
                $this->db->run(
                    'INSERT INTO notifications (user_id, kind, title, body, link, resolve_key, count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
                    [(int) $user['id'], $kind, mb_substr($title, 0, 255), mb_substr($body, 0, 1000), $link, $resolveKey, $now, $now]
                );
            }
            if (self::wantsEmail($user)) {
                $url = $this->mailer->link($link);
                $fragment = '<p>' . e($body) . '</p>'
                    . ($url !== null ? '<p><a href="' . e($url) . '">Open it</a></p>' : '')
                    . '<p style="color:#6b7280;font-size:13px">You can turn these emails off on your account page.</p>';
                $this->mail->enqueue($eventKey . ':' . (int) $user['id'], (string) $user['email'], Access::displayName($user), $title, $fragment);
            }
        } catch (\Throwable $e) {
            $this->log->error('app', 'Notification failed', ['kind' => $kind, 'user' => $user['username'] ?? null, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Notify every active account holding a permission, except $exceptUserId.
     *
     * @return int how many were notified
     */
    public function notifyPermission(string $permission, string $kind, string $title, string $body, string $link, ?string $resolveKey, string $eventKey, int $exceptUserId = 0): int
    {
        $n = 0;
        try {
            foreach ($this->users->all() as $user) {
                if ($user['status'] === 'active' && (int) $user['id'] !== $exceptUserId && Access::can($user, $permission)) {
                    $this->notify($user, $kind, $title, $body, $link, $resolveKey, $eventKey);
                    $n++;
                }
            }
        } catch (\Throwable $e) {
            $this->log->error('app', 'Notification failed', ['kind' => $kind, 'error' => $e->getMessage()]);
        }
        return $n;
    }

    /** Mark every unread notification of a family read (it has been dealt with). */
    public function resolve(string $resolveKey): void
    {
        try {
            $this->db->run('UPDATE notifications SET read_at = ? WHERE resolve_key = ? AND read_at IS NULL', [Database::now(), $resolveKey]);
        } catch (\Throwable $e) {
            $this->log->error('app', 'Resolving notifications failed', ['key' => $resolveKey, 'error' => $e->getMessage()]);
        }
    }

    public function unreadCount(int $userId): int
    {
        try {
            return (int) $this->db->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
        } catch (\Throwable) {
            return 0; // not migrated yet
        }
    }

    /** @return list<array<string,mixed>> */
    public function page(int $userId, string $kind, int $page, int $perPage): array
    {
        [$where, $args] = $this->filter($userId, $kind);
        return $this->db->all('SELECT * FROM notifications WHERE ' . $where . ' ORDER BY read_at IS NULL DESC, updated_at DESC, id DESC LIMIT '
            . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage), $args);
    }

    public function total(int $userId, string $kind): int
    {
        [$where, $args] = $this->filter($userId, $kind);
        return (int) $this->db->value('SELECT COUNT(*) FROM notifications WHERE ' . $where, $args);
    }

    /** @return array<string,mixed>|null one of this person's notifications */
    public function find(int $userId, int $id): ?array
    {
        return $this->db->one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    public function markRead(int $userId, ?int $id = null): void
    {
        $this->db->run('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL' . ($id !== null ? ' AND id = ?' : ''),
            array_merge([Database::now(), $userId], $id !== null ? [$id] : []));
    }

    /** @param array<string,mixed> $user */
    public static function wantsEmail(array $user): bool
    {
        if (($user['email'] ?? null) === null || ($user['email_verified_at'] ?? null) === null) {
            return false;
        }
        $prefs = json_decode((string) ($user['preferences'] ?? ''), true);
        return !is_array($prefs) || ($prefs['email_notifications'] ?? '1') !== '0';
    }

    /** @return array{string,list<mixed>} */
    private function filter(int $userId, string $kind): array
    {
        return isset(self::KINDS[$kind]) ? ['user_id = ? AND kind = ?', [$userId, $kind]] : ['user_id = ?', [$userId]];
    }
}
