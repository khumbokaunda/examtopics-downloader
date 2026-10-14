<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Counts recent events per bucket and subject (an IP address or an account), to limit how
 * often codes are sent and accounts are created. Subjects are stored hashed.
 */
final class Throttle
{
    public function __construct(private Database $db)
    {
    }

    /** Record an event unless the limit is reached; false when it is. */
    public function hit(string $bucket, string $subject, int $max, int $windowSeconds): bool
    {
        $key = hash('sha256', $subject);
        $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        if ((int) $this->db->value('SELECT COUNT(*) FROM throttle_events WHERE bucket = ? AND subject = ? AND created_at > ?', [$bucket, $key, $since]) >= $max) {
            return false;
        }
        $this->db->run('INSERT INTO throttle_events (bucket, subject, created_at) VALUES (?, ?, ?)', [$bucket, $key, Database::now()]);
        return true;
    }

    /** Seconds since the last event, or null if there was none in the last day. */
    public function since(string $bucket, string $subject): ?int
    {
        $last = $this->db->value('SELECT MAX(created_at) FROM throttle_events WHERE bucket = ? AND subject = ? AND created_at > ?',
            [$bucket, hash('sha256', $subject), gmdate('Y-m-d H:i:s', time() - 86400)]);
        return $last === null ? null : time() - (int) strtotime($last . ' UTC');
    }

    /** Remove events older than a day (bin/cleanup.php). */
    public function prune(): int
    {
        return $this->db->run('DELETE FROM throttle_events WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)])->rowCount();
    }
}
