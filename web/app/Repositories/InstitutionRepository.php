<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Ids;

/**
 * Institutions, their verification history, memberships and invitations.
 *
 * Tenant isolation lives here as much as in the controllers: every read of a membership or an
 * invitation takes the institution's id and puts it in the WHERE clause, so an id from another
 * institution finds nothing, whatever the page asked for.
 */
final class InstitutionRepository
{
    /** Profile columns a form may set. */
    public const PROFILE = ['name', 'short_name', 'type', 'address', 'region', 'district', 'email', 'phone', 'website',
        'registration_info', 'contact_name', 'contact_position', 'intended_use'];

    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM institutions WHERE uuid = ?', [$uuid]) : null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM institutions WHERE id = ?', [$id]);
    }

    /** @param array<string,?string> $profile @return int the new id */
    public function create(array $profile, int $userId): int
    {
        $now = Database::now();
        $cols = array_intersect_key($profile, array_flip(self::PROFILE));
        $names = array_keys($cols);
        $this->db->run('INSERT INTO institutions (uuid, ' . implode(', ', $names) . ', status, created_by, created_at, updated_at) VALUES (?, '
            . implode(', ', array_fill(0, count($names), '?')) . ", 'pending', ?, ?, ?)",
            [Ids::uuid(), ...array_values($cols), $userId, $now, $now]);
        return $this->db->lastId();
    }

    /** @param array<string,?string> $profile */
    public function updateProfile(int $id, array $profile): void
    {
        $cols = array_intersect_key($profile, array_flip(self::PROFILE));
        if ($cols === []) {
            return;
        }
        $this->db->run('UPDATE institutions SET ' . implode(', ', array_map(static fn(string $c): string => $c . ' = ?', array_keys($cols)))
            . ', updated_at = ? WHERE id = ?', [...array_values($cols), Database::now(), $id]);
    }

    public function setStatus(int $id, string $from, string $to, ?string $note, ?string $evidence, ?int $userId): void
    {
        $now = Database::now();
        $this->db->transaction(function (Database $db) use ($id, $from, $to, $note, $evidence, $userId, $now): void {
            $db->run('UPDATE institutions SET status = ?, status_note = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ?',
                [$to, $note, $userId, $now, $now, $id]);
            $db->run('INSERT INTO institution_reviews (institution_id, from_status, to_status, note, evidence, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $from, $to, $note, $evidence, $userId, $now]);
        });
    }

    /** @return list<array<string,mixed>> the institution's decisions, newest first, with who made them */
    public function reviews(int $institutionId): array
    {
        return $this->db->all('SELECT r.*, u.username, u.display_name FROM institution_reviews r LEFT JOIN users u ON u.id = r.user_id
                               WHERE r.institution_id = ? ORDER BY r.id DESC', [$institutionId]);
    }

    /**
     * Every institution, for the platform reviewers: profile, status and how many people
     * belong, never who they are.
     *
     * @return list<array<string,mixed>>
     */
    public function all(string $status = ''): array
    {
        $sql = "SELECT i.*, (SELECT COUNT(*) FROM institution_memberships m WHERE m.institution_id = i.id AND m.status = 'active') AS member_count
                FROM institutions i";
        $args = [];
        if ($status !== '') {
            $sql .= ' WHERE i.status = ?';
            $args[] = $status;
        }
        return $this->db->all($sql . " ORDER BY i.status = 'pending' DESC, i.created_at DESC", $args);
    }

    /** @return array<string,int> institutions per status */
    public function statusCounts(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM institutions GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    public function pendingCreatedBy(int $userId): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM institutions WHERE created_by = ? AND status = 'pending'", [$userId]);
    }

    // Memberships ---------------------------------------------------------------------------

    /** @return array<string,mixed>|null the person's membership of the institution, active or not */
    public function membership(int $institutionId, int $userId): ?array
    {
        return $this->db->one('SELECT * FROM institution_memberships WHERE institution_id = ? AND user_id = ?', [$institutionId, $userId]);
    }

    /** @return array<string,mixed>|null a membership of this institution by its public id, with the person's account */
    public function member(int $institutionId, string $uuid): ?array
    {
        if (!Ids::isUuid($uuid)) {
            return null;
        }
        return $this->db->one('SELECT m.*, u.username, u.display_name, u.email FROM institution_memberships m JOIN users u ON u.id = m.user_id
                               WHERE m.institution_id = ? AND m.uuid = ?', [$institutionId, $uuid]);
    }

    /** @return list<array<string,mixed>> the institution's memberships with their accounts; active first */
    public function members(int $institutionId, bool $withRemoved = false): array
    {
        return $this->db->all('SELECT m.*, u.username, u.display_name, u.email FROM institution_memberships m JOIN users u ON u.id = m.user_id
                               WHERE m.institution_id = ?' . ($withRemoved ? '' : " AND m.status = 'active'")
            . " ORDER BY m.status = 'active' DESC, FIELD(m.role, 'owner', 'admin', 'instructor', 'exam_officer', 'invigilator', 'student'), u.display_name, u.username",
            [$institutionId]);
    }

    /** @return array<string,int> active members per role */
    public function roleCounts(int $institutionId): array
    {
        $out = [];
        foreach ($this->db->all("SELECT role, COUNT(*) AS n FROM institution_memberships WHERE institution_id = ? AND status = 'active' GROUP BY role", [$institutionId]) as $r) {
            $out[(string) $r['role']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> the institutions a person belongs to (active memberships), with their role */
    public function forUser(int $userId): array
    {
        return $this->db->all("SELECT i.*, m.role AS member_role, m.uuid AS membership_uuid FROM institution_memberships m
                               JOIN institutions i ON i.id = m.institution_id
                               WHERE m.user_id = ? AND m.status = 'active' ORDER BY i.name", [$userId]);
    }

    /**
     * Add the person, or bring back a membership that ended, with this role.
     *
     * @param list<string> $extras
     */
    public function join(int $institutionId, int $userId, string $role, array $extras, ?int $invitedBy): void
    {
        $now = Database::now();
        $json = $extras === [] ? null : json_encode(array_values($extras));
        $this->db->run(
            "INSERT INTO institution_memberships (uuid, institution_id, user_id, role, permissions, status, invited_by, joined_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), permissions = VALUES(permissions), status = 'active', invited_by = VALUES(invited_by),
                 joined_at = VALUES(joined_at), removed_at = NULL, removed_by = NULL, updated_at = VALUES(updated_at)",
            [Ids::uuid(), $institutionId, $userId, $role, $json, $invitedBy, $now, $now]
        );
    }

    /** @param list<string> $extras */
    public function setRole(int $institutionId, int $membershipId, string $role, array $extras): void
    {
        $this->db->run("UPDATE institution_memberships SET role = ?, permissions = ?, updated_at = ? WHERE institution_id = ? AND id = ? AND status = 'active'",
            [$role, $extras === [] ? null : json_encode(array_values($extras)), Database::now(), $institutionId, $membershipId]);
    }

    /** End a membership. The row stays, marked removed, so its history keeps its context. */
    public function remove(int $institutionId, int $membershipId, ?int $byUserId): void
    {
        $now = Database::now();
        $this->db->run("UPDATE institution_memberships SET status = 'removed', removed_at = ?, removed_by = ?, updated_at = ? WHERE institution_id = ? AND id = ?",
            [$now, $byUserId, $now, $institutionId, $membershipId]);
    }

    public function activeOwners(int $institutionId): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM institution_memberships WHERE institution_id = ? AND role = 'owner' AND status = 'active'", [$institutionId]);
    }

    /** @return list<array<string,mixed>> the active owners' accounts */
    public function owners(int $institutionId): array
    {
        return $this->db->all("SELECT u.* FROM institution_memberships m JOIN users u ON u.id = m.user_id
                               WHERE m.institution_id = ? AND m.role = 'owner' AND m.status = 'active' ORDER BY u.id", [$institutionId]);
    }

    /**
     * Institutions this account is the only active owner of, so deleting the account would
     * leave them with nobody responsible.
     *
     * @return list<string> their names
     */
    public function soleOwnerOf(int $userId): array
    {
        return array_map(static fn(array $r): string => (string) $r['name'], $this->db->all(
            "SELECT i.name FROM institution_memberships m JOIN institutions i ON i.id = m.institution_id
             WHERE m.user_id = ? AND m.role = 'owner' AND m.status = 'active'
               AND (SELECT COUNT(*) FROM institution_memberships o WHERE o.institution_id = m.institution_id AND o.role = 'owner' AND o.status = 'active') = 1
             ORDER BY i.name", [$userId]));
    }

    // Invitations ---------------------------------------------------------------------------

    /** @param array<string,mixed>|null $details */
    public function invite(int $institutionId, string $email, string $role, string $tokenSha256, ?int $by, string $expiresAt, ?array $details = null): int
    {
        $now = Database::now();
        // A newer invitation to the same address replaces any still open.
        $this->db->run('UPDATE institution_invitations SET revoked_at = ? WHERE institution_id = ? AND email = ?
                        AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL', [$now, $institutionId, $email]);
        $this->db->run('INSERT INTO institution_invitations (institution_id, email, role, details, token_sha256, invited_by, created_at, expires_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$institutionId, $email, $role, $details === null ? null : json_encode($details, JSON_UNESCAPED_SLASHES),
                        $tokenSha256, $by, $now, $expiresAt]);
        return $this->db->lastId();
    }

    /** @return array<string,mixed>|null an invitation by its token's hash, with its institution */
    public function invitationByToken(string $tokenSha256): ?array
    {
        return $this->db->one('SELECT v.*, i.uuid AS institution_uuid, i.name AS institution_name, i.status AS institution_status
                               FROM institution_invitations v JOIN institutions i ON i.id = v.institution_id WHERE v.token_sha256 = ?', [$tokenSha256]);
    }

    /** @return list<array<string,mixed>> invitations still open (not accepted, declined, revoked or expired) */
    public function openInvitations(int $institutionId): array
    {
        return $this->db->all('SELECT v.*, u.username AS invited_by_username, u.display_name AS invited_by_name FROM institution_invitations v
                               LEFT JOIN users u ON u.id = v.invited_by
                               WHERE v.institution_id = ? AND v.accepted_at IS NULL AND v.declined_at IS NULL AND v.revoked_at IS NULL AND v.expires_at > ?
                               ORDER BY v.id DESC', [$institutionId, Database::now()]);
    }

    /** @return array<string,mixed>|null an open invitation of this institution */
    public function openInvitation(int $institutionId, int $id): ?array
    {
        return $this->db->one('SELECT * FROM institution_invitations WHERE institution_id = ? AND id = ?
                               AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL', [$institutionId, $id]);
    }

    public function revokeInvitation(int $institutionId, int $id): void
    {
        $this->db->run('UPDATE institution_invitations SET revoked_at = ? WHERE institution_id = ? AND id = ? AND accepted_at IS NULL', [Database::now(), $institutionId, $id]);
    }

    public function closeInvitation(int $id, string $how, ?int $userId): void
    {
        $now = Database::now();
        if ($how === 'accepted') {
            $this->db->run('UPDATE institution_invitations SET accepted_at = ?, accepted_by = ? WHERE id = ?', [$now, $userId, $id]);
        } else {
            $this->db->run('UPDATE institution_invitations SET declined_at = ? WHERE id = ?', [$now, $id]);
        }
    }
}
