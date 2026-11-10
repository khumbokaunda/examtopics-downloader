<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use App\Repositories\InstitutionRepository;
use App\Repositories\StructureRepository;
use App\Repositories\UserRepository;
use RuntimeException;

/**
 * How students come to belong to an institution, beyond invitations: enrolment codes and the
 * requests they create, and the lifecycle of a student's relationship with the institution.
 *
 * One person, one ExamLib account. A student's membership of an institution, and the record the
 * institution keeps about them (student number, programme, classes, standing), belong to that
 * relationship and never to the account: leaving, being withdrawn or completing a programme
 * changes the relationship, and the account, its own study and its other institutions are
 * untouched. Accounts are never merged, and never matched by name.
 *
 * An enrolment code proves only that its holder may ask to join. It is not proof of identity, so
 * codes that do not need approval should be used where the institution can see who joins (in a
 * classroom, say), and the code's page says so.
 */
final class Enrolment
{
    /** The lifecycle as the institution sees it, from an invitation to the end of the relationship. */
    public const STATES = [
        'invited' => 'Invited', 'pending_registration' => 'Pending registration', 'pending_acceptance' => 'Pending acceptance',
        'active' => 'Active', 'suspended' => 'Suspended', 'withdrawn' => 'Withdrawn', 'completed' => 'Completed',
    ];
    public const TONES = ['invited' => 'neutral', 'pending_registration' => 'warning', 'pending_acceptance' => 'warning', 'active' => 'success',
        'suspended' => 'warning', 'withdrawn' => 'neutral', 'completed' => 'info'];

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    /** Wrong codes one account may type in an hour before it must wait. */
    public const MAX_TRIES = 10;

    public function __construct(private Database $db, private InstitutionRepository $institutions, private StructureRepository $structure,
        private InstitutionStructure $structureService, private UserRepository $users, private Policies $policies, private AuditLog $audit,
        private Notifier $notifier, private Throttle $throttle, private ?Plans $plans = null)
    {
    }

    // The lifecycle --------------------------------------------------------------------------

    /**
     * Where an open invitation stands: invited (not yet opened, no account), pending
     * registration (the person needs to create or confirm an account) or pending acceptance (an
     * account with the address exists and needs to accept).
     *
     * @param array<string,mixed> $inv
     */
    public function invitationState(array $inv): string
    {
        $account = $this->users->findByEmail((string) $inv['email']);
        if ($account !== null && $account['status'] === 'active') {
            return 'pending_acceptance';
        }
        return $account !== null || $inv['viewed_at'] !== null ? 'pending_registration' : 'invited';
    }

    /**
     * Student invitations still open, each with its state.
     *
     * @return list<array<string,mixed>>
     */
    public function waiting(int $iid): array
    {
        $rows = array_values(array_filter($this->institutions->openInvitations($iid), static fn(array $v): bool => $v['role'] === InstitutionAccess::STUDENT));
        foreach ($rows as $k => $v) {
            $rows[$k]['state'] = $this->invitationState($v);
            $details = json_decode((string) ($v['details'] ?? ''), true);
            $rows[$k]['student_number'] = is_array($details) ? (string) ($details['student_number'] ?? '') : '';
        }
        return $rows;
    }

    /** How many students are in each state. @param list<array<string,mixed>> $students @param list<array<string,mixed>> $waiting @return array<string,int> */
    public static function counts(array $students, array $waiting): array
    {
        $out = array_fill_keys(array_keys(self::STATES), 0);
        foreach ($waiting as $w) {
            $out[$w['state']]++;
        }
        foreach ($students as $s) {
            $st = (string) ($s['student_status'] ?? 'active');
            $out[$st] = ($out[$st] ?? 0) + 1;
        }
        return $out;
    }

    // Codes ----------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> the institution's codes, newest first, with programme, class and pending requests */
    public function codes(int $iid): array
    {
        return $this->db->all("SELECT c.*, p.name AS programme_name, k.name AS class_name, k.intake AS class_intake,
                                      (SELECT COUNT(*) FROM enrolment_requests r WHERE r.code_id = c.id AND r.status = 'pending') AS pending
                               FROM enrolment_codes c LEFT JOIN institution_programmes p ON p.id = c.programme_id LEFT JOIN institution_classes k ON k.id = c.class_id
                               WHERE c.institution_id = ? ORDER BY c.revoked_at IS NULL DESC, c.id DESC", [$iid]);
    }

    /**
     * Create a code. @param array<string,mixed> $i @param array<string,mixed> $user
     * @param array{label?:string,programme?:string,class?:string,requires_approval?:bool,max_uses?:string,expires_on?:string} $in
     */
    public function createCode(array $i, array $user, array $in): string
    {
        $iid = (int) $i['id'];
        $programme = ($in['programme'] ?? '') !== '' ? ($this->structure->programme($iid, (string) $in['programme']) ?? throw new RuntimeException('Choose a programme of this institution.')) : null;
        $class = ($in['class'] ?? '') !== '' ? ($this->structure->class($iid, (string) $in['class']) ?? throw new RuntimeException('Choose a class of this institution.')) : null;
        if ($class !== null && $class['status'] !== 'active') {
            throw new RuntimeException('That class is closed.');
        }
        $max = trim((string) ($in['max_uses'] ?? ''));
        if ($max !== '' && (preg_match('/^\d{1,5}$/', $max) !== 1 || (int) $max < 1)) {
            throw new RuntimeException('The most uses is a whole number from 1, or empty for no limit.');
        }
        $expires = null;
        $on = trim((string) ($in['expires_on'] ?? ''));
        if ($on !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $on, new \DateTimeZone('UTC'));
            if ($d === false || $d->format('Y-m-d') !== $on || $on < gmdate('Y-m-d')) {
                throw new RuntimeException('Give an expiry date from today on, or leave it empty.');
            }
            $expires = $on . ' 23:59:59';
        }
        $label = mb_substr(trim((string) ($in['label'] ?? '')), 0, 120);
        $prefix = strtoupper(substr((string) preg_replace('/[^A-Za-z]/', '', (string) ($i['short_name'] ?: $i['name'])), 0, 4)) ?: 'EDU';
        for ($try = 0; ; $try++) {
            $code = $prefix . '-' . self::random(4) . '-' . self::random(4);
            if ($this->db->value('SELECT 1 FROM enrolment_codes WHERE code = ?', [$code]) === null) {
                break;
            }
            if ($try > 5) {
                throw new RuntimeException('Could not make a new code. Try again.');
            }
        }
        $this->db->run('INSERT INTO enrolment_codes (uuid, institution_id, code, label, programme_id, class_id, requires_approval, max_uses, expires_at, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [Ids::uuid(), $iid, $code, $label !== '' ? $label : null, $programme !== null ? (int) $programme['id'] : null,
            $class !== null ? (int) $class['id'] : null, !empty($in['requires_approval']) ? 1 : 0, $max !== '' ? (int) $max : null, $expires, (int) $user['id'], Database::now()]);
        $this->audit->record('institution.enrolment_code_created', ['institution' => $i['name'], 'code' => $code, 'approval' => !empty($in['requires_approval'])],
            'institution', (string) $i['uuid']);
        return $code;
    }

    /** @param array<string,mixed> $i */
    public function revokeCode(array $i, string $uuid): void
    {
        $c = Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM enrolment_codes WHERE institution_id = ? AND uuid = ? AND revoked_at IS NULL', [(int) $i['id'], $uuid]) : null;
        if ($c === null) {
            throw new RuntimeException('That code is not in use.');
        }
        $this->db->run('UPDATE enrolment_codes SET revoked_at = ? WHERE id = ?', [Database::now(), (int) $c['id']]);
        $this->audit->record('institution.enrolment_code_revoked', ['institution' => $i['name'], 'code' => $c['code']], 'institution', (string) $i['uuid']);
    }

    public static function normalise(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', trim($code)));
    }

    /**
     * The code typed, if it can be used now, with its institution. Wrong codes count against the
     * account, so codes cannot be found by trying them.
     *
     * @return array<string,mixed>
     */
    public function lookup(string $code, array $user): array
    {
        if ($this->throttle->count('enrolment-code', (string) $user['id'], 3600) >= self::MAX_TRIES) {
            throw new RuntimeException('Too many codes that did not work. Try again in an hour, or ask your institution for an invitation.');
        }
        $c = $this->db->one('SELECT c.*, i.uuid AS institution_uuid, i.name AS institution_name, i.status AS institution_status, p.name AS programme_name,
                                    k.name AS class_name, k.intake AS class_intake, k.status AS class_status
                             FROM enrolment_codes c JOIN institutions i ON i.id = c.institution_id
                             LEFT JOIN institution_programmes p ON p.id = c.programme_id LEFT JOIN institution_classes k ON k.id = c.class_id
                             WHERE c.code = ?', [self::normalise($code)]);
        $why = match (true) {
            $c === null => 'No institution uses that code. Check it with your institution.',
            $c['revoked_at'] !== null => 'That code is no longer in use.',
            $c['expires_at'] !== null && (string) $c['expires_at'] <= Database::now() => 'That code has expired.',
            $c['max_uses'] !== null && (int) $c['uses'] >= (int) $c['max_uses'] => 'That code has been used as many times as it allows.',
            $c['institution_status'] !== 'verified' => 'That institution cannot take new members at the moment.',
            $c['class_id'] !== null && $c['class_status'] !== 'active' => 'The class that code is for is closed.',
            default => null,
        };
        if ($why !== null) {
            $this->throttle->hit('enrolment-code', (string) $user['id'], PHP_INT_MAX, 3600);
            throw new RuntimeException($why);
        }
        return $c;
    }

    /**
     * Use a code: join at once, or ask to join when the code needs approval. The person accepts
     * the membership notice either way.
     *
     * @param array<string,mixed> $code from lookup() @param array<string,mixed> $user
     * @return string "joined" or "requested"
     */
    public function useCode(array $code, array $user, bool $noticeAccepted, ?string $ip): string
    {
        if (!$noticeAccepted) {
            throw new RuntimeException('Read what joining means and tick the box to go on.');
        }
        $iid = (int) $code['institution_id'];
        $m = $this->institutions->membership($iid, (int) $user['id']);
        if ($m !== null && $m['status'] === 'active') {
            throw new RuntimeException('You already belong to ' . $code['institution_name'] . '.');
        }
        $institution = (array) $this->institutions->find($iid);
        if ((int) $code['requires_approval'] === 1) {
            if ($this->db->value("SELECT 1 FROM enrolment_requests WHERE institution_id = ? AND user_id = ? AND status = 'pending'", [$iid, (int) $user['id']]) !== null) {
                throw new RuntimeException('Your request to join ' . $code['institution_name'] . ' is already waiting for approval.');
            }
            $this->db->run('INSERT INTO enrolment_requests (uuid, institution_id, code_id, user_id, created_at) VALUES (?, ?, ?, ?, ?)',
                [Ids::uuid(), $iid, (int) $code['id'], (int) $user['id'], Database::now()]);
            $this->policies->accept((int) $user['id'], ['membership_notice'], 'enrolment_request', (int) $this->db->lastId(), $ip);
            $this->audit->record('institution.enrolment_requested', ['institution' => $code['institution_name'], 'code' => $code['code']], 'institution', (string) $code['institution_uuid']);
            foreach ($this->managers($iid) as $manager) {
                $this->notifier->notify($manager, 'institution', 'Request to join ' . $code['institution_name'], Access::displayName($user) . ' asked to join with the code '
                    . $code['code'] . '.', '/institutions/' . $code['institution_uuid'] . '/students', 'enrolment-requests:' . $iid, 'enrolment-request:' . $iid . ':' . $user['id'] . ':' . time());
            }
            return 'requested';
        }
        $this->join($institution, $code, (int) $user['id'], null, $ip);
        return 'joined';
    }

    /** @return list<array<string,mixed>> requests waiting for a decision, with who asked and the code */
    public function requests(int $iid): array
    {
        return $this->db->all("SELECT r.*, u.username, u.display_name, u.email, c.code, c.label FROM enrolment_requests r JOIN users u ON u.id = r.user_id
                               JOIN enrolment_codes c ON c.id = r.code_id WHERE r.institution_id = ? AND r.status = 'pending' ORDER BY r.id", [$iid]);
    }

    /** Approve or decline a request. @param array<string,mixed> $i @param array<string,mixed> $actor */
    public function decide(array $i, array $actor, string $uuid, bool $approve): void
    {
        $iid = (int) $i['id'];
        $r = Ids::isUuid($uuid) ? $this->db->one("SELECT r.*, c.code, c.programme_id, c.class_id, c.id AS code_id, u.display_name, u.username FROM enrolment_requests r
                                                  JOIN enrolment_codes c ON c.id = r.code_id JOIN users u ON u.id = r.user_id
                                                  WHERE r.institution_id = ? AND r.uuid = ? AND r.status = 'pending'", [$iid, $uuid]) : null;
        if ($r === null) {
            throw new RuntimeException('That request has been decided already.');
        }
        $this->db->run('UPDATE enrolment_requests SET status = ?, decided_by = ?, decided_at = ? WHERE id = ?', [$approve ? 'approved' : 'declined', (int) $actor['id'], Database::now(), (int) $r['id']]);
        $user = (array) $this->users->find((int) $r['user_id']);
        if ($approve) {
            $code = (array) $this->db->one('SELECT * FROM enrolment_codes WHERE id = ?', [(int) $r['code_id']]);
            $m = $this->institutions->membership($iid, (int) $r['user_id']);
            if ($m === null || $m['status'] !== 'active') {
                $this->join($i, $code, (int) $r['user_id'], (int) $actor['id'], null, false);
            }
        }
        $this->notifier->resolve('enrolment-requests:' . $iid);
        $this->audit->record($approve ? 'institution.enrolment_approved' : 'institution.enrolment_declined', ['institution' => $i['name'], 'student' => $r['username']],
            'institution', (string) $i['uuid']);
        $this->notifier->notify($user, 'institution', $approve ? 'You joined ' . $i['name'] : 'Your request to join ' . $i['name'] . ' was not approved',
            $approve ? 'Your request was approved. You are now one of its students.' : 'Ask the institution if you think this is a mistake.',
            $approve ? '/institutions/' . $i['uuid'] : '/', null, 'enrolment-decision:' . $r['uuid']);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $code */
    private function join(array $i, array $code, int $userId, ?int $by, ?string $ip, bool $acceptNotice = true): void
    {
        $iid = (int) $i['id'];
        $this->plans?->assertRoom($i, InstitutionAccess::STUDENT);
        $this->db->transaction(function (Database $db) use ($i, $code, $userId, $by, $iid): void {
            $this->institutions->join($iid, $userId, InstitutionAccess::STUDENT, [], $by);
            $db->run('UPDATE enrolment_codes SET uses = uses + 1 WHERE id = ?', [(int) $code['id']]);
            $m = (array) $this->institutions->membership($iid, $userId);
            $details = ['classes' => []];
            if ($code['programme_id'] !== null) {
                $details['programme'] = (string) $db->value('SELECT uuid FROM institution_programmes WHERE id = ?', [(int) $code['programme_id']]);
            }
            if ($code['class_id'] !== null) {
                $details['classes'][] = (string) $db->value('SELECT uuid FROM institution_classes WHERE id = ?', [(int) $code['class_id']]);
            }
            $this->structureService->applyInvitationDetails($iid, (int) $m['id'], $details, $by);
        });
        $m = (array) $this->institutions->membership($iid, $userId);
        if ($acceptNotice) {
            $this->policies->accept($userId, ['membership_notice'], 'membership', (int) $m['id'], $ip);
        }
        $this->audit->record('institution.joined_with_code', ['institution' => $i['name'], 'code' => $code['code']], 'institution', (string) $i['uuid']);
    }

    /** @return list<array<string,mixed>> the accounts of active members who manage students */
    private function managers(int $iid): array
    {
        $out = [];
        foreach ($this->institutions->members($iid) as $m) {
            if ($m['status'] === 'active' && InstitutionAccess::can($m, 'students.manage') && ($u = $this->users->find((int) $m['user_id'])) !== null) {
                $out[] = $u;
            }
        }
        return $out;
    }

    private static function random(int $n): string
    {
        $out = '';
        for ($k = 0; $k < $n; $k++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $out;
    }
}
