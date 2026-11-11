<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use RuntimeException;

/**
 * The pages anyone can read without signing in: a home page, pricing and contact. They are off
 * until an administrator turns them on (an installation used only as a private library shows
 * the sign-in page, as before). Messages sent from the contact page go to the people who look
 * after institutions.
 */
final class PublicSite
{
    public const FIELDS = ['eyebrow' => 60, 'headline' => 160, 'highlight' => 80, 'intro' => 600, 'contact_email' => 190, 'contact_phone' => 40, 'contact_hours' => 120];
    /** Contact messages one address may send in an hour. */
    public const MESSAGES_PER_HOUR = 5;

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public function __construct(private Database $db, private AuditLog $audit, private Notifier $notifier, private Throttle $throttle)
    {
    }

    /** @return array{on:bool,eyebrow:string,headline:string,highlight:string,intro:string,contact_email:string,contact_phone:string,contact_hours:string} */
    public function settings(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $values = ['on' => false] + array_fill_keys(array_keys(self::FIELDS), '');
        try {
            $repo = new SettingsRepository($this->db);
            $values['on'] = $repo->get('site.public') === '1';
            foreach (array_keys(self::FIELDS) as $k) {
                $values[$k] = (string) $repo->get('site.' . $k, '');
            }
        } catch (\Throwable) {
            // No database yet: the public pages stay off.
        }
        return $this->cache = $values;
    }

    public function on(): bool
    {
        return $this->settings()['on'];
    }

    /** @param array<string,mixed> $in */
    public function save(array $in): void
    {
        $repo = new SettingsRepository($this->db);
        $email = trim((string) ($in['contact_email'] ?? ''));
        if ($email !== '' && !UserRepository::validEmail($email)) {
            throw new RuntimeException('The contact email address is not valid.');
        }
        $repo->set('site.public', !empty($in['on']) ? '1' : '0');
        foreach (self::FIELDS as $k => $max) {
            $v = trim(str_replace("\r\n", "\n", (string) ($in[$k] ?? '')));
            $repo->set('site.' . $k, mb_substr($k === 'intro' ? $v : (string) preg_replace('/\s+/u', ' ', $v), 0, $max));
        }
        $this->cache = null;
        $this->audit->record('site.public_saved', ['on' => !empty($in['on'])], 'settings', 'site');
    }

    /**
     * Keep a message from the contact page and tell the people who look after institutions.
     *
     * @param array<string,mixed> $in
     */
    public function contact(array $in, string $ip): void
    {
        $line = static fn(string $k, int $max): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? ''))), 0, $max);
        $name = $line('name', 120);
        $email = UserRepository::normalizeEmail((string) ($in['email'] ?? ''));
        $body = trim(str_replace("\r\n", "\n", (string) ($in['message'] ?? '')));
        if ($name === '' || !UserRepository::validEmail($email) || $body === '') {
            throw new RuntimeException('Give your name, a valid email address and a message.');
        }
        if (mb_strlen($body) > 5000) {
            throw new RuntimeException('Keep the message under 5,000 characters.');
        }
        if (!$this->throttle->hit('contact', $ip, self::MESSAGES_PER_HOUR, 3600)) {
            throw new RuntimeException('Too many messages from this address. Try again in an hour.');
        }
        $uuid = Ids::uuid();
        $org = $line('organisation', 160);
        $phone = $line('phone', 40);
        $planUuid = (string) ($in['plan'] ?? '');
        $plan = Ids::isUuid($planUuid) ? $this->db->one('SELECT id, name FROM plans WHERE uuid = ? AND public = 1 AND archived_at IS NULL', [$planUuid]) : null;
        $this->db->run('INSERT INTO contact_messages (uuid, name, email, organisation, phone, plan_id, body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$uuid, $name, $email, $org !== '' ? $org : null, $phone !== '' ? $phone : null, $plan !== null ? (int) $plan['id'] : null, $body, Database::now()]);
        $this->notifier->notifyPermission('institutions.manage', 'institution', 'Message from ' . $name . ($org !== '' ? ', ' . $org : '') . ($plan !== null ? ' about ' . $plan['name'] : ''),
            mb_substr(preg_replace('/\s+/u', ' ', $body) ?? '', 0, 200), '/platform/messages', 'contact:' . $uuid, 'contact:' . $uuid);
    }

    /** @return list<array<string,mixed>> newest first */
    public function messages(?string $status = null): array
    {
        return $this->db->all('SELECT m.*, u.username AS handler_username, u.display_name AS handler_name, p.name AS plan_name FROM contact_messages m
                               LEFT JOIN users u ON u.id = m.handled_by LEFT JOIN plans p ON p.id = m.plan_id' . ($status !== null ? ' WHERE m.status = ?' : '') . ' ORDER BY m.created_at DESC, m.id DESC LIMIT 1000',
            $status !== null ? [$status] : []);
    }

    /** @param array<string,mixed> $actor */
    public function markAnswered(string $uuid, array $actor): void
    {
        $m = Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM contact_messages WHERE uuid = ?', [$uuid]) : null;
        if ($m === null) {
            throw new RuntimeException('That message is not here.');
        }
        if ($m['status'] === 'answered') {
            return;
        }
        $this->db->run("UPDATE contact_messages SET status = 'answered', handled_by = ?, handled_at = ? WHERE id = ?", [(int) $actor['id'], Database::now(), (int) $m['id']]);
        $this->notifier->resolve('contact:' . $uuid);
        $this->audit->record('site.message_answered', ['from' => $m['name']], 'contact_message', $uuid);
    }
}
