<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use RuntimeException;

/**
 * Credentials an institution issues in its own name: who issued it, to whom, what it certifies,
 * what it rests on, and whether it still stands.
 *
 * - Three categories with their own rules: assessment completion, institutional achievement, and
 *   formal qualification, which only an institution the platform recorded as an authorised
 *   awarding body may issue. ExamLib's own readiness certificates are separate (Readiness).
 * - A template says what is certified and who is eligible: passing an assessment (with results
 *   published, optionally at a minimum percentage), completing a programme, or chosen by staff.
 * - Issuing is approved by a signatory authorised for the category, unless the template allows a
 *   signatory to issue directly. The signatory's name and position are printed on it.
 * - One credential per holder and achievement at a time, unless a duplicate is explicitly
 *   authorised. An issued credential is never changed: it is revoked with a reason, or replaced
 *   by a corrected one that points back at it. Its expiry is shown, not enforced by deletion.
 * - Each issued credential carries a SHA-256 fingerprint of its fields, signed with the
 *   platform's Ed25519 key (CredentialSigner). The public verification page is authoritative;
 *   the signature only lets someone check a copy offline.
 */
final class Credentials
{
    public const CATEGORIES = [
        'completion' => 'Assessment completion certificate',
        'achievement' => 'Institutional achievement certificate',
        'qualification' => 'Formal qualification certificate',
    ];
    public const RULES = ['assessment' => 'Passing an assessment', 'programme' => 'Completing a programme', 'manual' => 'Chosen by staff'];
    public const STATUSES = ['pending' => 'Waiting for approval', 'issued' => 'Issued', 'declined' => 'Declined', 'revoked' => 'Revoked', 'replaced' => 'Replaced'];

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private Database $db, private AuditLog $audit, private Notifier $notifier, private CredentialSigner $signer)
    {
    }

    public function signer(): CredentialSigner
    {
        return $this->signer;
    }

    /** @param array<string,mixed> $i @return array<string,string> the categories this institution may issue */
    public static function categoriesFor(array $i): array
    {
        $c = self::CATEGORIES;
        if (trim((string) ($i['awarding_body'] ?? '')) === '') {
            unset($c['qualification']);
        }
        return $c;
    }

    // Signatories ----------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function signatories(int $iid): array
    {
        return $this->db->all("SELECT s.*, m.uuid AS membership_uuid, m.role, u.username, u.display_name FROM credential_signatories s
                               JOIN institution_memberships m ON m.id = s.membership_id JOIN users u ON u.id = m.user_id
                               WHERE s.institution_id = ? ORDER BY s.active DESC, u.display_name, u.username", [$iid]);
    }

    /** The active signatory record of this member for a category, or null. @return array<string,mixed>|null */
    public function signatoryFor(int $iid, int $membershipId, string $category): ?array
    {
        $s = $this->db->one("SELECT s.*, u.username, u.display_name FROM credential_signatories s JOIN institution_memberships m ON m.id = s.membership_id
                             JOIN users u ON u.id = m.user_id WHERE s.institution_id = ? AND s.membership_id = ? AND s.active = 1 AND m.status = 'active'", [$iid, $membershipId]);
        return $s !== null && in_array($category, explode(',', (string) $s['categories']), true) ? $s : null;
    }

    /**
     * Authorise a staff member to approve and sign credentials of the chosen categories (owners only).
     *
     * @param array<string,mixed> $i @param array<string,mixed> $member a membership with the account @param list<string> $categories
     */
    public function setSignatory(array $i, array $member, string $position, array $categories, bool $active): void
    {
        if ($member['status'] !== 'active' || $member['role'] === InstitutionAccess::STUDENT) {
            throw new RuntimeException('Only the institution\'s staff can sign credentials.');
        }
        $position = mb_substr(trim($position), 0, 120);
        if ($position === '') {
            throw new RuntimeException('Give their position, for example Principal. It is printed under their name.');
        }
        $categories = array_values(array_intersect($categories, array_keys(self::categoriesFor($i))));
        if ($active && $categories === []) {
            throw new RuntimeException('Choose at least one kind of credential they may sign.');
        }
        $now = Database::now();
        $this->db->run('INSERT INTO credential_signatories (uuid, institution_id, membership_id, position, categories, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE position = VALUES(position), categories = VALUES(categories), active = VALUES(active), updated_at = VALUES(updated_at)',
            [Ids::uuid(), (int) $i['id'], (int) $member['id'], $position, implode(',', $categories), $active ? 1 : 0, $now, $now]);
        $this->audit->record('institution.signatory_set', ['institution' => $i['name'], 'person' => $member['username'], 'position' => $position,
            'categories' => $categories, 'active' => $active], 'institution', (string) $i['uuid']);
    }

    // Templates ------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function templates(int $iid): array
    {
        return $this->db->all("SELECT t.*, a.title AS assessment_title, a.uuid AS assessment_uuid, p.name AS programme_name, p.uuid AS programme_uuid,
                                      (SELECT COUNT(*) FROM credentials c WHERE c.template_id = t.id AND c.status = 'issued') AS issued_count,
                                      (SELECT COUNT(*) FROM credentials c WHERE c.template_id = t.id AND c.status = 'pending') AS pending_count
                               FROM credential_templates t LEFT JOIN assessments a ON a.id = t.assessment_id LEFT JOIN institution_programmes p ON p.id = t.programme_id
                               WHERE t.institution_id = ? ORDER BY t.status = 'active' DESC, t.name", [$iid]);
    }

    /** @return array<string,mixed>|null */
    public function template(int $iid, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT t.*, a.title AS assessment_title, a.uuid AS assessment_uuid, a.results_published_at, p.name AS programme_name
                                                    FROM credential_templates t LEFT JOIN assessments a ON a.id = t.assessment_id LEFT JOIN institution_programmes p ON p.id = t.programme_id
                                                    WHERE t.institution_id = ? AND t.uuid = ?', [$iid, $uuid]) : null;
    }

    /**
     * Create a template, or change one that has issued nothing yet (later, only its wording for
     * future credentials, its approval and validity may change; issued ones keep what they said).
     *
     * @param array<string,mixed> $i @param array<string,mixed>|null $t @param array<string,string> $in @param array<string,mixed> $user
     */
    public function saveTemplate(array $i, ?array $t, array $in, array $user): void
    {
        $iid = (int) $i['id'];
        $category = (string) ($in['category'] ?? '');
        if (!isset(self::categoriesFor($i)[$category])) {
            throw new RuntimeException(isset(self::CATEGORIES[$category]) ? 'Formal qualifications can be issued only by an institution recorded by ExamLib as an authorised awarding body.'
                : 'Choose the kind of credential.');
        }
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160);
        $body = mb_substr(trim((string) ($in['body'] ?? '')), 0, 1000);
        $footer = mb_substr(trim((string) ($in['footer'] ?? '')), 0, 300);
        if ($name === '' || $title === '' || $body === '') {
            throw new RuntimeException('Give the template a name, the title printed on it, and what it certifies.');
        }
        $rule = (string) ($in['rule'] ?? '');
        if (!isset(self::RULES[$rule])) {
            throw new RuntimeException('Choose who is eligible.');
        }
        $assessment = null;
        $programme = null;
        if ($rule === 'assessment') {
            $assessment = $this->db->one('SELECT * FROM assessments WHERE institution_id = ? AND uuid = ?', [$iid, (string) ($in['assessment'] ?? '')])
                ?? throw new RuntimeException('Choose the assessment that must be passed.');
        } elseif ($rule === 'programme') {
            $programme = $this->db->one('SELECT * FROM institution_programmes WHERE institution_id = ? AND uuid = ?', [$iid, (string) ($in['programme'] ?? '')])
                ?? throw new RuntimeException('Choose the programme that must be completed.');
        }
        $min = trim((string) ($in['min_percent'] ?? ''));
        if ($min !== '' && (!is_numeric($min) || (float) $min < 0 || (float) $min > 100)) {
            throw new RuntimeException('The minimum mark is a percentage from 0 to 100, or empty.');
        }
        $validity = trim((string) ($in['validity_months'] ?? ''));
        if ($validity !== '' && (preg_match('/^\d{1,3}$/', $validity) !== 1 || (int) $validity < 1)) {
            throw new RuntimeException('Validity is a number of months, or empty when it does not expire.');
        }
        $vals = [$category, $name, $title, $body, $footer !== '' ? $footer : null, $rule, $assessment !== null ? (int) $assessment['id'] : null,
            $programme !== null ? (int) $programme['id'] : null, $min !== '' && $rule === 'assessment' ? round((float) $min, 2) : null, !empty($in['requires_approval']) ? 1 : 0,
            $validity !== '' ? (int) $validity : null];
        $now = Database::now();
        if ($t === null) {
            $this->db->run('INSERT INTO credential_templates (uuid, institution_id, category, name, title, body, footer, rule, assessment_id, programme_id, min_percent,
                            requires_approval, validity_months, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ids::uuid(), $iid, ...$vals, (int) $user['id'], $now, $now]);
        } else {
            if ((int) $this->db->value('SELECT COUNT(*) FROM credentials WHERE template_id = ?', [(int) $t['id']]) > 0
                && ($category !== $t['category'] || $rule !== $t['rule'] || ($vals[6] ?? null) !== ($t['assessment_id'] !== null ? (int) $t['assessment_id'] : null)
                    || ($vals[7] ?? null) !== ($t['programme_id'] !== null ? (int) $t['programme_id'] : null))) {
                throw new RuntimeException('Credentials were issued from this template, so its kind and eligibility stay as they are. Make a new template instead.');
            }
            $this->db->run('UPDATE credential_templates SET category = ?, name = ?, title = ?, body = ?, footer = ?, rule = ?, assessment_id = ?, programme_id = ?, min_percent = ?,
                            requires_approval = ?, validity_months = ?, status = ?, updated_at = ? WHERE id = ? AND institution_id = ?',
                [...$vals, ($in['status'] ?? 'active') === 'retired' ? 'retired' : 'active', $now, (int) $t['id'], $iid]);
        }
        $this->audit->record('institution.credential_template_saved', ['institution' => $i['name'], 'template' => $name, 'category' => $category], 'institution', (string) $i['uuid']);
    }

    // Eligibility ----------------------------------------------------------------------------

    /**
     * Students who meet the template's rule, with what they rest on and whether they already
     * hold or wait for this credential.
     *
     * @param array<string,mixed> $t @return list<array<string,mixed>>
     */
    public function eligible(int $iid, array $t): array
    {
        $base = "SELECT m.id AS membership_id, m.uuid AS membership_uuid, u.username, u.display_name, st.student_number";
        $from = " FROM institution_memberships m JOIN users u ON u.id = m.user_id LEFT JOIN institution_students st ON st.membership_id = m.id";
        if ($t['rule'] === 'assessment') {
            $rows = $this->db->all($base . ", s.uuid AS session_uuid, s.percent, DATE(s.submitted_at) AS achieved_on" . $from . "
                                   JOIN assessment_sessions s ON s.membership_id = m.id AND s.id = (SELECT x.id FROM assessment_sessions x WHERE x.assessment_id = ? AND x.membership_id = m.id
                                        AND x.passed = 1 AND x.status <> 'in_progress' ORDER BY x.percent DESC, x.id LIMIT 1)
                                   JOIN assessments a ON a.id = s.assessment_id AND a.results_published_at IS NOT NULL
                                   WHERE m.institution_id = ? AND m.role = 'student'" . ($t['min_percent'] !== null ? ' AND s.percent >= ?' : '') . ' ORDER BY u.display_name, u.username',
                $t['min_percent'] !== null ? [(int) $t['assessment_id'], $iid, (float) $t['min_percent']] : [(int) $t['assessment_id'], $iid]);
        } elseif ($t['rule'] === 'programme') {
            $rows = $this->db->all($base . ", NULL AS session_uuid, NULL AS percent, e.ended_on AS achieved_on" . $from . "
                                   JOIN programme_enrolments e ON e.membership_id = m.id AND e.programme_id = ? AND e.outcome = 'completed'
                                   WHERE m.institution_id = ? AND m.role = 'student' ORDER BY u.display_name, u.username", [(int) $t['programme_id'], $iid]);
        } else {
            $rows = $this->db->all($base . ", NULL AS session_uuid, NULL AS percent, NULL AS achieved_on" . $from . "
                                   WHERE m.institution_id = ? AND m.role = 'student' AND m.status = 'active' ORDER BY u.display_name, u.username", [$iid]);
        }
        $held = [];
        foreach ($this->db->all("SELECT membership_id, status, number FROM credentials WHERE template_id = ? AND status IN ('pending', 'issued')", [(int) $t['id']]) as $c) {
            $held[(int) $c['membership_id']] = $c;
        }
        foreach ($rows as $k => $r) {
            $rows[$k]['held'] = $held[(int) $r['membership_id']] ?? null;
        }
        return $rows;
    }

    // Issuing --------------------------------------------------------------------------------

    /**
     * Ask for, or issue, the template's credential to the chosen students. Those not eligible are
     * skipped; those who already hold it or wait for it are skipped unless a duplicate is
     * authorised.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $actor membership @param array<string,mixed> $user @param array<string,mixed> $t
     * @param list<string> $memberUuids @return array{issued:int,pending:int,skipped:list<string>}
     */
    public function issue(array $i, array $actor, array $user, array $t, array $memberUuids, string $achievementNote, bool $allowDuplicate): array
    {
        $iid = (int) $i['id'];
        if ($t['status'] !== 'active') {
            throw new RuntimeException('That template is retired.');
        }
        if (!isset(self::categoriesFor($i)[$t['category']])) {
            throw new RuntimeException('This institution is not authorised to issue that kind of credential.');
        }
        if ($i['status'] !== 'verified') {
            throw new RuntimeException('Credentials can be issued only while the institution is approved.');
        }
        $eligible = [];
        foreach ($this->eligible($iid, $t) as $r) {
            $eligible[(string) $r['membership_uuid']] = $r;
        }
        $signatory = $this->signatoryFor($iid, (int) $actor['id'], (string) $t['category']);
        $direct = $signatory !== null && (int) $t['requires_approval'] === 0;
        $out = ['issued' => 0, 'pending' => 0, 'skipped' => []];
        foreach (array_unique($memberUuids) as $uuid) {
            $r = $eligible[$uuid] ?? null;
            if ($r === null) {
                $out['skipped'][] = 'Someone chosen is not eligible.';
                continue;
            }
            $name = Access::displayName($r);
            if ($r['held'] !== null && !$allowDuplicate) {
                $out['skipped'][] = $name . ' already ' . ($r['held']['status'] === 'issued' ? 'holds ' . $r['held']['number'] : 'has one waiting for approval') . '.';
                continue;
            }
            $subject = 't' . $t['id'] . ':m' . $r['membership_id'] . ':' . ($t['rule'] === 'assessment' ? 'a' . $t['assessment_id'] : ($t['rule'] === 'programme' ? 'p' . $t['programme_id'] : 'manual'));
            $achieved = (string) ($r['achieved_on'] ?? '') !== '' ? (string) $r['achieved_on'] : gmdate('Y-m-d');
            $achievement = (string) $t['body'] . (trim($achievementNote) !== '' && $t['rule'] === 'manual' ? ' ' . mb_substr(trim($achievementNote), 0, 400) : '');
            $id = $this->db->transaction(function (Database $db) use ($i, $t, $r, $name, $subject, $achieved, $achievement, $allowDuplicate, $user): int {
                $db->run('INSERT INTO credentials (uuid, number, token, institution_id, membership_id, template_id, category, title, holder_name, achievement, programme_name,
                          assessment_title, assessment_ref, percent, achieved_on, status, subject_key, active_key, requested_by, requested_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [Ids::uuid(), $this->newNumber(), bin2hex(random_bytes(16)), (int) $i['id'], (int) $r['membership_id'], (int) $t['id'], $t['category'], $t['title'], $name, $achievement,
                        $t['programme_name'] ?? null, $t['assessment_title'] ?? null, $r['session_uuid'], $r['percent'], $achieved, 'pending', $subject, $allowDuplicate ? null : $subject,
                        (int) $user['id'], Database::now()]);
                $id = $db->lastId();
                $this->event($id, 'requested', $allowDuplicate ? 'Duplicate authorised.' : null, (int) $user['id']);
                return $id;
            });
            if ($direct) {
                $this->approve($i, $user, (array) $this->byId($id), $signatory);
                $out['issued']++;
            } else {
                $out['pending']++;
            }
        }
        if ($out['pending'] > 0) {
            foreach ($this->signatories($iid) as $s) {
                if ((int) $s['active'] === 1 && in_array($t['category'], explode(',', (string) $s['categories']), true)) {
                    $u = $this->db->one('SELECT u.* FROM users u JOIN institution_memberships m ON m.user_id = u.id WHERE m.id = ?', [(int) $s['membership_id']]);
                    if ($u !== null) {
                        $this->notifier->notify($u, 'institution', 'Credentials to approve at ' . $i['name'], $out['pending'] . ' ' . $t['title'] . ' waiting for your approval.',
                            '/institutions/' . $i['uuid'] . '/credentials', 'credentials-pending:' . $iid, 'credentials-pending:' . $iid . ':' . time());
                    }
                }
            }
        }
        $this->audit->record('institution.credentials_requested', ['institution' => $i['name'], 'template' => $t['name'], 'issued' => $out['issued'], 'pending' => $out['pending']],
            'institution', (string) $i['uuid']);
        return $out;
    }

    /**
     * Approve and issue a pending credential as a signatory of its category.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $user @param array<string,mixed> $c @param array<string,mixed> $signatory
     */
    public function approve(array $i, array $user, array $c, array $signatory): void
    {
        if ($c['status'] !== 'pending') {
            throw new RuntimeException('That credential is not waiting for approval.');
        }
        if (!in_array($c['category'], explode(',', (string) $signatory['categories']), true)) {
            throw new RuntimeException('You are not authorised to sign this kind of credential.');
        }
        $t = (array) $this->db->one('SELECT * FROM credential_templates WHERE id = ?', [(int) $c['template_id']]);
        $issued = Database::now();
        $expires = $t['validity_months'] !== null ? gmdate('Y-m-d', (int) strtotime('+' . (int) $t['validity_months'] . ' months')) : null;
        $fields = self::publicFields(array_merge($c, ['issued_at' => $issued, 'expires_on' => $expires, 'signatory_name' => Access::displayName($signatory),
            'signatory_position' => $signatory['position'], 'issuer_name' => $i['name']]));
        $fingerprint = hash('sha256', (string) json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        [$signature, $keyId] = $this->signer->sign($fingerprint);
        $this->db->run("UPDATE credentials SET status = 'issued', issued_at = ?, expires_on = ?, issuer_name = ?, signatory_id = ?, signatory_name = ?, signatory_position = ?,
                        approved_by = ?, fingerprint = ?, signature = ?, key_id = ? WHERE id = ? AND status = 'pending'",
            [$issued, $expires, $i['name'], (int) $signatory['id'], Access::displayName($signatory), $signatory['position'], (int) $user['id'], $fingerprint, $signature, $keyId,
                (int) $c['id']]);
        $this->event((int) $c['id'], 'issued', null, (int) $user['id']);
        $this->audit->record('institution.credential_issued', ['institution' => $i['name'], 'number' => $c['number'], 'holder' => $c['holder_name']], 'institution', (string) $i['uuid']);
        $holder = $this->db->one('SELECT u.* FROM users u JOIN institution_memberships m ON m.user_id = u.id WHERE m.id = ?', [(int) $c['membership_id']]);
        if ($holder !== null) {
            $this->notifier->notify($holder, 'institution', $i['name'] . ' issued you a credential', $c['title'] . ' (' . $c['number'] . ') is in your certificates.', '/certificates',
                null, 'credential-issued:' . $c['uuid']);
        }
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $user @param array<string,mixed> $c */
    public function decline(array $i, array $user, array $c, string $reason): void
    {
        if ($c['status'] !== 'pending') {
            throw new RuntimeException('That credential is not waiting for approval.');
        }
        $this->db->run("UPDATE credentials SET status = 'declined', active_key = NULL WHERE id = ?", [(int) $c['id']]);
        $this->event((int) $c['id'], 'declined', mb_substr(trim($reason), 0, 500) ?: null, (int) $user['id']);
        $this->audit->record('institution.credential_declined', ['institution' => $i['name'], 'number' => $c['number']], 'institution', (string) $i['uuid']);
    }

    /** Revoke an issued credential. It stays, verifiable as revoked. @param array<string,mixed> $i @param array<string,mixed> $user @param array<string,mixed> $c */
    public function revoke(array $i, array $user, array $c, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($c['status'] !== 'issued') {
            throw new RuntimeException('Only an issued credential can be revoked.');
        }
        if ($reason === '') {
            throw new RuntimeException('Give the reason. It is shown to anyone verifying the credential.');
        }
        $this->db->run("UPDATE credentials SET status = 'revoked', active_key = NULL, revoked_at = ?, revoked_by = ?, revoke_reason = ? WHERE id = ?",
            [Database::now(), (int) $user['id'], $reason, (int) $c['id']]);
        $this->event((int) $c['id'], 'revoked', $reason, (int) $user['id']);
        $this->audit->record('institution.credential_revoked', ['institution' => $i['name'], 'number' => $c['number'], 'reason' => $reason], 'institution', (string) $i['uuid']);
    }

    /**
     * Issue a corrected credential (for example a corrected name) that replaces an issued one.
     * The original stays, marked replaced, and its verification page names the replacement.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $user @param array<string,mixed> $c @param array<string,mixed> $signatory
     * @return string the new credential's number
     */
    public function correct(array $i, array $user, array $c, string $holderName, string $achievement, string $reason, array $signatory): string
    {
        if ($c['status'] !== 'issued') {
            throw new RuntimeException('Only an issued credential can be corrected.');
        }
        $holderName = mb_substr(trim($holderName), 0, 160);
        $achievement = mb_substr(trim($achievement), 0, 1000);
        $reason = mb_substr(trim($reason), 0, 500);
        if ($holderName === '' || $achievement === '' || $reason === '') {
            throw new RuntimeException('Give the corrected name and wording, and say what was corrected.');
        }
        if (!in_array($c['category'], explode(',', (string) $signatory['categories']), true)) {
            throw new RuntimeException('You are not authorised to sign this kind of credential.');
        }
        $id = $this->db->transaction(function (Database $db) use ($c, $holderName, $achievement, $user): int {
            $db->run("UPDATE credentials SET status = 'replaced', active_key = NULL WHERE id = ?", [(int) $c['id']]);
            $db->run('INSERT INTO credentials (uuid, number, token, institution_id, membership_id, template_id, category, title, holder_name, achievement, programme_name,
                      assessment_title, assessment_ref, percent, achieved_on, status, subject_key, active_key, requested_by, requested_at, replaces_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ids::uuid(), $this->newNumber(), bin2hex(random_bytes(16)), (int) $c['institution_id'], (int) $c['membership_id'], (int) $c['template_id'], $c['category'], $c['title'],
                    $holderName, $achievement, $c['programme_name'], $c['assessment_title'], $c['assessment_ref'], $c['percent'], $c['achieved_on'], 'pending', $c['subject_key'],
                    $c['subject_key'], (int) $user['id'], Database::now(), (int) $c['id']]);
            return $db->lastId();
        });
        $this->event((int) $c['id'], 'replaced', $reason, (int) $user['id']);
        $new = (array) $this->byId($id);
        $this->event($id, 'requested', 'Correction of ' . $c['number'] . ': ' . $reason, (int) $user['id']);
        $this->approve($i, $user, $new, $signatory);
        $this->audit->record('institution.credential_corrected', ['institution' => $i['name'], 'number' => $c['number'], 'replacement' => $new['number'], 'reason' => $reason],
            'institution', (string) $i['uuid']);
        return (string) $new['number'];
    }

    // Reading --------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> the institution's credentials, newest first, with a flag when the results behind one were taken back */
    public function all(int $iid, string $status = ''): array
    {
        return $this->db->all("SELECT c.*, t.name AS template_name, (c.assessment_ref IS NOT NULL AND EXISTS (SELECT 1 FROM assessment_sessions s JOIN assessments a ON a.id = s.assessment_id
                                      WHERE s.uuid = c.assessment_ref AND a.results_published_at IS NULL)) AS needs_review
                               FROM credentials c JOIN credential_templates t ON t.id = c.template_id WHERE c.institution_id = ?" . ($status !== '' ? ' AND c.status = ?' : '') . '
                               ORDER BY c.id DESC LIMIT 2000', $status !== '' ? [$iid, $status] : [$iid]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $iid, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM credentials WHERE institution_id = ? AND uuid = ?', [$iid, $uuid]) : null;
    }

    /** @return array<string,mixed>|null */
    public function byId(int $id): ?array
    {
        return $this->db->one('SELECT * FROM credentials WHERE id = ?', [$id]);
    }

    /** A credential by its public token, with its issuer, or null when unknown or not yet issued. @return array<string,mixed>|null */
    public function byToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }
        $c = $this->db->one("SELECT c.*, i.name AS institution_name, i.uuid AS institution_uuid, i.status AS institution_status, i.identity_status, i.registration_status,
                                    i.accreditation_status, i.representative_status, i.awarding_body, r.number AS replacement_number
                             FROM credentials c JOIN institutions i ON i.id = c.institution_id LEFT JOIN credentials r ON r.replaces_id = c.id
                             WHERE c.token = ? AND c.status IN ('issued', 'revoked', 'replaced')", [$token]);
        return $c;
    }

    /** @return list<array<string,mixed>> the issued or revoked credentials an account holds, across institutions */
    public function heldBy(int $userId): array
    {
        return $this->db->all("SELECT c.*, i.name AS institution_name FROM credentials c JOIN institution_memberships m ON m.id = c.membership_id
                               JOIN institutions i ON i.id = c.institution_id WHERE m.user_id = ? AND c.status IN ('issued', 'revoked', 'replaced') ORDER BY c.issued_at DESC", [$userId]);
    }

    /** @return list<array<string,mixed>> */
    public function events(int $credentialId): array
    {
        return $this->db->all('SELECT e.*, u.username, u.display_name FROM credential_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.credential_id = ? ORDER BY e.id', [$credentialId]);
    }

    /** issued, expired, revoked or replaced, by today's date. @param array<string,mixed> $c */
    public static function state(array $c): string
    {
        if ($c['status'] === 'issued' && $c['expires_on'] !== null && (string) $c['expires_on'] < gmdate('Y-m-d')) {
            return 'expired';
        }
        return (string) $c['status'];
    }

    /** Whether the stored fingerprint matches the fields and the signature checks out. @param array<string,mixed> $c */
    public function authentic(array $c): bool
    {
        if ($c['fingerprint'] === null || $c['signature'] === null) {
            return false;
        }
        $fields = self::publicFields($c);
        return hash_equals((string) $c['fingerprint'], hash('sha256', (string) json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            && $this->signer->verify((string) $c['fingerprint'], (string) $c['signature'], (string) $c['key_id']);
    }

    /** The fields a fingerprint covers, in a fixed order. @param array<string,mixed> $c @return array<string,mixed> */
    public static function publicFields(array $c): array
    {
        return ['number' => (string) $c['number'], 'issuer' => (string) $c['issuer_name'], 'category' => (string) $c['category'], 'title' => (string) $c['title'],
            'holder' => (string) $c['holder_name'], 'achievement' => (string) $c['achievement'], 'programme' => $c['programme_name'], 'assessment' => $c['assessment_title'],
            'achieved_on' => (string) $c['achieved_on'], 'issued_at' => (string) $c['issued_at'], 'expires_on' => $c['expires_on'],
            'signatory' => (string) $c['signatory_name'], 'position' => (string) $c['signatory_position']];
    }

    private function newNumber(): string
    {
        for ($try = 0; $try < 8; $try++) {
            $n = 'EXL-' . gmdate('Y') . '-';
            for ($k = 0; $k < 8; $k++) {
                $n .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            if ($this->db->value('SELECT 1 FROM credentials WHERE number = ?', [$n]) === null) {
                return $n;
            }
        }
        throw new RuntimeException('Could not make a credential number. Try again.');
    }

    private function event(int $credentialId, string $action, ?string $note, ?int $userId): void
    {
        $this->db->run('INSERT INTO credential_events (credential_id, action, note, user_id, created_at) VALUES (?, ?, ?, ?, ?)', [$credentialId, $action, $note, $userId, Database::now()]);
    }
}
