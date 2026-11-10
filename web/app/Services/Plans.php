<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use RuntimeException;

/**
 * Plans: what an institution gets. A plan limits how many students and staff an institution
 * has at once, and carries a price written by the operator (shown on the pricing page and on
 * invoices). An institution with no plan has no limits.
 *
 * The limits are checked where people join (an invitation accepted, an enrolment code used,
 * a join request approved) and where they are invited, counting open invitations too, so a
 * class list of 200 is refused at the start rather than after 30 have joined.
 */
final class Plans
{
    public const KINDS = ['students' => 'students', 'staff' => 'staff'];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** @return list<array<string,mixed>> with how many institutions are on each */
    public function all(bool $withArchived = true): array
    {
        return $this->db->all('SELECT p.*, (SELECT COUNT(*) FROM institutions i WHERE i.plan_id = p.id AND i.deleted_at IS NULL) AS institution_count
                               FROM plans p' . ($withArchived ? '' : ' WHERE p.archived_at IS NULL') . ' ORDER BY p.archived_at IS NOT NULL, p.position, p.id');
    }

    /** @return list<array<string,mixed>> the plans shown on the pricing page */
    public function listed(): array
    {
        return $this->db->all('SELECT * FROM plans WHERE public = 1 AND archived_at IS NULL ORDER BY position, id');
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM plans WHERE uuid = ?', [$uuid]) : null;
    }

    /** @return array<string,mixed>|null */
    public function byId(?int $id): ?array
    {
        return $id === null ? null : $this->db->one('SELECT * FROM plans WHERE id = ?', [$id]);
    }

    /**
     * Add or change a plan. Limits are whole numbers or empty for none.
     *
     * @param array<string,mixed>|null $plan @param array<string,mixed> $in
     */
    public function save(?array $plan, array $in): array
    {
        $text = static fn(string $k, int $max): ?string => ($v = trim((string) preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? '')))) === '' ? null : mb_substr($v, 0, $max);
        $limit = static function (string $k) use ($in): ?int {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') {
                return null;
            }
            if (!ctype_digit($v) || (int) $v > 1000000) {
                throw new RuntimeException('A limit is a whole number, or empty for no limit.');
            }
            return (int) $v;
        };
        $name = $text('name', 80);
        if ($name === null) {
            throw new RuntimeException('Give the plan a name.');
        }
        $values = [$name, $text('price', 80), $text('summary', 255), $limit('max_students'), $limit('max_staff'), !empty($in['public']) ? 1 : 0];
        $now = Database::now();
        if ($plan === null) {
            $uuid = Ids::uuid();
            $pos = (int) $this->db->value('SELECT COALESCE(MAX(position), 0) + 1 FROM plans');
            $this->db->run('INSERT INTO plans (uuid, name, price, summary, max_students, max_staff, public, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$uuid, ...$values, ...[$pos, $now, $now]]);
            $plan = (array) $this->find($uuid);
        } else {
            $this->db->run('UPDATE plans SET name = ?, price = ?, summary = ?, max_students = ?, max_staff = ?, public = ?, updated_at = ? WHERE id = ?',
                [...$values, $now, (int) $plan['id']]);
            $plan = (array) $this->byId((int) $plan['id']);
        }
        $this->audit->record('plan.saved', ['plan' => $name, 'price' => $values[1], 'students' => $values[3], 'staff' => $values[4]], 'plan', (string) $plan['uuid']);
        return $plan;
    }

    /** Stop offering a plan, or offer it again. Institutions on it keep it. @param array<string,mixed> $plan */
    public function archive(array $plan, bool $archived): void
    {
        $this->db->run('UPDATE plans SET archived_at = ?, updated_at = ? WHERE id = ?', [$archived ? Database::now() : null, Database::now(), (int) $plan['id']]);
        $this->audit->record($archived ? 'plan.archived' : 'plan.restored', ['plan' => $plan['name']], 'plan', (string) $plan['uuid']);
    }

    /**
     * Put an institution on a plan, or on none. Refused when the institution has more people
     * than the plan allows, so a limit is never broken by the change itself.
     *
     * @param array<string,mixed> $institution @param array<string,mixed>|null $plan @param array<string,mixed> $actor
     */
    public function assign(array $institution, ?array $plan, array $actor, string $note = ''): void
    {
        $current = $institution['plan_id'] !== null ? (int) $institution['plan_id'] : null;
        $to = $plan !== null ? (int) $plan['id'] : null;
        if ($current === $to) {
            return;
        }
        if ($plan !== null) {
            if ($plan['archived_at'] !== null) {
                throw new RuntimeException('That plan is not offered any more.');
            }
            $usage = $this->usage($institution);
            foreach (self::KINDS as $kind => $word) {
                $max = $plan['max_' . $kind];
                if ($max !== null && $usage[$kind] > (int) $max) {
                    throw new RuntimeException($institution['name'] . ' has ' . $usage[$kind] . ' ' . $word . ', more than the ' . (int) $max . ' this plan allows.');
                }
            }
        }
        $this->db->transaction(function () use ($institution, $to, $actor, $note): void {
            $this->db->run('UPDATE institutions SET plan_id = ? WHERE id = ?', [$to, (int) $institution['id']]);
            $this->db->run('INSERT INTO institution_plan_changes (institution_id, plan_id, note, changed_by, changed_at) VALUES (?, ?, ?, ?, ?)',
                [(int) $institution['id'], $to, trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null, (int) ($actor['id'] ?? 0) ?: null, Database::now()]);
        });
        $from = $this->byId($current);
        $this->audit->record('institution.plan_changed', ['institution' => $institution['name'], 'from' => $from['name'] ?? null, 'to' => $plan['name'] ?? null],
            'institution', (string) $institution['uuid']);
    }

    /** @return list<array<string,mixed>> newest first */
    public function history(int $institutionId): array
    {
        return $this->db->all('SELECT c.*, p.name AS plan_name, u.username, u.display_name FROM institution_plan_changes c
                               LEFT JOIN plans p ON p.id = c.plan_id LEFT JOIN users u ON u.id = c.changed_by
                               WHERE c.institution_id = ? ORDER BY c.changed_at DESC, c.id DESC', [$institutionId]);
    }

    /**
     * People in the institution now, and invitations still open.
     *
     * @param array<string,mixed> $institution
     * @return array{students:int,staff:int,invited_students:int,invited_staff:int}
     */
    public function usage(array $institution): array
    {
        $id = (int) $institution['id'];
        $members = $this->db->one("SELECT SUM(role = 'student') AS students, SUM(role <> 'student') AS staff FROM institution_memberships
                                   WHERE institution_id = ? AND status = 'active'", [$id]);
        $invites = $this->db->one("SELECT SUM(role = 'student') AS students, SUM(role <> 'student') AS staff FROM institution_invitations
                                   WHERE institution_id = ? AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL AND expires_at > ?",
            [$id, Database::now()]);
        return ['students' => (int) ($members['students'] ?? 0), 'staff' => (int) ($members['staff'] ?? 0),
            'invited_students' => (int) ($invites['students'] ?? 0), 'invited_staff' => (int) ($invites['staff'] ?? 0)];
    }

    /** students or staff, from an institution role */
    public static function kindOf(string $role): string
    {
        return $role === InstitutionAccess::STUDENT ? 'students' : 'staff';
    }

    /**
     * Refuse adding $count people with this role when the plan would be over its limit.
     * $inviting counts open invitations too (they may all be accepted); joining does not,
     * because the one joining is usually one of them. Inviting $email again replaces their open
     * invitation, so it is not counted twice.
     *
     * @param array<string,mixed> $institution
     */
    public function assertRoom(array $institution, string $role, int $count = 1, bool $inviting = false, ?string $email = null): void
    {
        $plan = $this->byId(isset($institution['plan_id']) && $institution['plan_id'] !== null ? (int) $institution['plan_id'] : null);
        if ($plan === null) {
            return;
        }
        $kind = self::kindOf($role);
        $max = $plan['max_' . $kind];
        if ($max === null) {
            return;
        }
        $usage = $this->usage($institution);
        $have = $usage[$kind] + ($inviting ? $usage['invited_' . $kind] : 0);
        if ($inviting && $email !== null) {
            $have -= (int) $this->db->value("SELECT COUNT(*) FROM institution_invitations WHERE institution_id = ? AND email = ? AND (role = 'student') = ?
                AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL AND expires_at > ?",
                [(int) $institution['id'], $email, $kind === 'students' ? 1 : 0, Database::now()]);
        }
        if ($have + $count > (int) $max) {
            throw new RuntimeException($institution['name'] . "'s plan allows " . (int) $max . ' ' . $kind . ($inviting && $usage['invited_' . $kind] > 0
                ? ', and it has ' . $usage[$kind] . ' with ' . $usage['invited_' . $kind] . ' more invited.'
                : ', and it has ' . $usage[$kind] . '.') . ' Its administrator can ask for a larger plan.');
        }
    }
}
