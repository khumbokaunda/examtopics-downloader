<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * Emails about events (a new exam request, a request answered, a new sign-up) are queued
 * here rather than sent while the page waits. Each has an idempotency key, so the same event
 * is never mailed twice. bin/send-mail.php sends what is due: it is started in the background
 * when something is queued, and by cron every few minutes for retries. A failed send is
 * retried after 1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours, then given up.
 */
final class MailQueue
{
    public const BACKOFF = [60, 300, 1800, 7200, 43200];

    /** @param \Closure():bool|null $kick starts bin/send-mail.php in the background */
    public function __construct(private Database $db, private Mailer $mailer, private Logger $log, private ?\Closure $kick = null)
    {
    }

    /** Queue one message; false when this key was queued before. */
    public function enqueue(string $key, string $to, ?string $toName, string $subject, string $fragment): bool
    {
        $added = $this->db->run(
            "INSERT IGNORE INTO mail_queue (idempotency_key, to_email, to_name, subject, html, status, attempts, next_attempt_at, created_at)
             VALUES (?, ?, ?, ?, ?, 'queued', 0, ?, ?)",
            [substr($key, 0, 191), $to, $toName, mb_substr($subject, 0, 255), $fragment, Database::now(), Database::now()]
        )->rowCount() === 1;
        if ($added && $this->kick !== null) {
            ($this->kick)();
        }
        return $added;
    }

    /**
     * Send what is due, oldest first. Each row is claimed before sending, so two runs at once
     * never send the same message.
     *
     * @return array{sent:int,failed:int}
     */
    public function drain(int $limit = 50): array
    {
        // A run that died mid-send leaves rows "sending"; after ten minutes they are due again.
        $this->db->run(
            "UPDATE mail_queue SET status = 'queued' WHERE status = 'sending' AND next_attempt_at < ?",
            [gmdate('Y-m-d H:i:s', time() - 600)]
        );
        $sent = $failed = 0;
        $due = $this->db->all(
            "SELECT * FROM mail_queue WHERE status = 'queued' AND next_attempt_at <= ? ORDER BY id LIMIT " . max(1, $limit),
            [Database::now()]
        );
        foreach ($due as $row) {
            $claimed = $this->db->run("UPDATE mail_queue SET status = 'sending', next_attempt_at = ? WHERE id = ? AND status = 'queued'",
                [Database::now(), (int) $row['id']])->rowCount();
            if ($claimed !== 1) {
                continue;
            }
            if ($this->mailer->send((string) $row['to_email'], (string) $row['subject'], (string) $row['html'], null, $row['to_name'])) {
                $this->db->run("UPDATE mail_queue SET status = 'sent', sent_at = ?, attempts = attempts + 1, last_error = NULL WHERE id = ?",
                    [Database::now(), (int) $row['id']]);
                $sent++;
                continue;
            }
            $attempts = (int) $row['attempts'] + 1;
            $giveUp = $attempts > count(self::BACKOFF) || !$this->mailer->configured();
            $this->db->run(
                'UPDATE mail_queue SET status = ?, attempts = ?, next_attempt_at = ?, last_error = ? WHERE id = ?',
                [
                    $giveUp ? 'failed' : 'queued', $attempts,
                    gmdate('Y-m-d H:i:s', time() + (self::BACKOFF[$attempts - 1] ?? 0)),
                    $this->mailer->configured() ? 'Send failed; see storage/logs/mail.log' : 'Email is not set up',
                    (int) $row['id'],
                ]
            );
            $failed++;
        }
        if ($sent + $failed > 0) {
            $this->log->info('mail', 'Mail queue run', ['sent' => $sent, 'failed' => $failed]);
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** @return array<string,int> message counts by status */
    public function counts(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM mail_queue GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> the latest messages, for the email settings page */
    public function recent(int $limit = 20): array
    {
        return $this->db->all('SELECT id, to_email, subject, status, attempts, last_error, created_at, sent_at FROM mail_queue ORDER BY id DESC LIMIT ' . max(1, $limit));
    }
}
