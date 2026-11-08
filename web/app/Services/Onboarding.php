<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use App\Repositories\InstitutionRepository;
use RuntimeException;

/**
 * An institution's onboarding: its application (supporting documents, what each kind of
 * institution must send, the agreement, sending it for review), the verification dimensions a
 * platform reviewer sets, and the setup checklist once it is approved.
 *
 * Verification is never one flag. Organisation identity, legal registration, accreditation and
 * the representative's authority each have their own state, and the platform approval is the
 * institution's status. Approving needs identity and representative authority verified; it does
 * not need registration or accreditation, which can stay unverified or not applicable, and every
 * page that describes the institution says which is which.
 */
final class Onboarding
{
    public const DIMENSIONS = [
        'identity' => ['label' => 'Organisation identity', 'column' => 'identity_status',
            'states' => ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected']],
        'registration' => ['label' => 'Legal registration', 'column' => 'registration_status',
            'states' => ['unverified' => 'Not verified', 'verified' => 'Verified', 'not_applicable' => 'Not applicable']],
        'accreditation' => ['label' => 'Accreditation', 'column' => 'accreditation_status',
            'states' => ['unverified' => 'Not verified', 'verified' => 'Verified', 'not_applicable' => 'Not applicable']],
        'representative' => ['label' => 'Representative authority', 'column' => 'representative_status',
            'states' => ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected']],
    ];

    public const DOCUMENTS = [
        'registration_certificate' => 'Registration certificate',
        'accreditation_certificate' => 'Accreditation certificate or letter',
        'authority_letter' => 'Letter authorising the representative',
        'identification' => 'Representative\'s identification',
        'other' => 'Other supporting document',
    ];

    /**
     * Documents each kind of institution must send. A university, college or technical college
     * is registered with a regulator; a private centre or corporate department may not be, so
     * it is not asked for what it cannot have. Accreditation evidence is needed only when an
     * accrediting body is named.
     */
    public const REQUIRED = [
        'university' => ['registration_certificate'], 'college' => ['registration_certificate'], 'tevet' => ['registration_certificate'],
        'exam_provider' => ['registration_certificate'],
    ];

    public const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_DOCUMENTS = 20;

    /** Statuses in which the institution's managers can still change its application. */
    public const EDITABLE = ['draft', 'info_requested', 'rejected'];

    public function __construct(private Database $db, private InstitutionRepository $repo, private Policies $policies, private AuditLog $audit,
        private Notifier $notifier, private string $storage)
    {
    }

    // Documents -------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> the documents not removed, newest first */
    public function documents(int $institutionId): array
    {
        return $this->db->all('SELECT d.*, u.username, u.display_name FROM institution_documents d LEFT JOIN users u ON u.id = d.uploaded_by
                               WHERE d.institution_id = ? AND d.removed_at IS NULL ORDER BY d.id DESC', [$institutionId]);
    }

    /** @return array<string,mixed>|null */
    public function document(int $institutionId, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM institution_documents WHERE institution_id = ? AND uuid = ? AND removed_at IS NULL', [$institutionId, $uuid]) : null;
    }

    public function path(array $doc, array $institution): string
    {
        return $this->storage . '/institution_documents/' . $institution['uuid'] . '/' . $doc['uuid'] . '.' . (self::MIMES[$doc['mime']] ?? 'bin');
    }

    /**
     * Keep an uploaded file. Only PDF, JPEG and PNG, by their content, up to 10 MB.
     *
     * @param array<string,mixed> $institution @param array{name:string,tmp_name:string,size:int,error:int} $file @param array<string,mixed> $user
     */
    public function upload(array $institution, string $kind, array $file, array $user): void
    {
        if (!in_array($institution['status'], [...self::EDITABLE, 'pending', 'under_review'], true)) {
            throw new RuntimeException('Documents can be added while the application is open.');
        }
        if (!isset(self::DOCUMENTS[$kind])) {
            throw new RuntimeException('Choose what the document is.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_file($file['tmp_name'])) {
            throw new RuntimeException(in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'That file is too large.' : 'Choose a file to upload.');
        }
        $size = (int) filesize($file['tmp_name']);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new RuntimeException('A document can be at most 10 MB.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::MIMES[$mime])) {
            throw new RuntimeException('Send a PDF, JPEG or PNG file.');
        }
        if (count($this->documents((int) $institution['id'])) >= self::MAX_DOCUMENTS) {
            throw new RuntimeException('An application can have at most ' . self::MAX_DOCUMENTS . ' documents. Remove one first.');
        }
        $uuid = Ids::uuid();
        $name = mb_substr(trim((string) preg_replace('/[^\pL\pN ._()-]+/u', '_', basename($file['name']))), 0, 180);
        $doc = ['uuid' => $uuid, 'mime' => $mime];
        $dest = $this->path($doc, $institution);
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0750, true) && !is_dir(dirname($dest))) {
            throw new RuntimeException('The document could not be stored. Tell an administrator.');
        }
        if (!copy($file['tmp_name'], $dest)) {
            throw new RuntimeException('The document could not be stored. Tell an administrator.');
        }
        $this->db->run('INSERT INTO institution_documents (uuid, institution_id, kind, filename, mime, size, sha256, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uuid, (int) $institution['id'], $kind, $name !== '' ? $name : 'document.' . self::MIMES[$mime], $mime, $size, (string) hash_file('sha256', $dest),
                (int) $user['id'], Database::now()]);
        $this->audit->record('institution.document_added', ['institution' => $institution['name'], 'kind' => $kind], 'institution', (string) $institution['uuid']);
    }

    /** Mark a document removed (the file stays: it may be evidence for a decision already made). @param array<string,mixed> $institution */
    public function remove(array $institution, string $uuid): void
    {
        if (!in_array($institution['status'], self::EDITABLE, true)) {
            throw new RuntimeException('Documents can be removed only while the application is with the institution.');
        }
        $doc = $this->document((int) $institution['id'], $uuid) ?? throw new RuntimeException('That document is not there.');
        $this->db->run('UPDATE institution_documents SET removed_at = ? WHERE id = ?', [Database::now(), (int) $doc['id']]);
        $this->audit->record('institution.document_removed', ['institution' => $institution['name'], 'kind' => $doc['kind']], 'institution', (string) $institution['uuid']);
    }

    // The application -------------------------------------------------------------------------

    /**
     * What the application still needs before it can be sent, as sentences (empty when ready).
     *
     * @param array<string,mixed> $institution @return list<string>
     */
    public function missing(array $institution): array
    {
        $out = [];
        [, $errors] = Institutions::validate($institution);
        if ($errors !== []) {
            $out[] = 'Complete the profile: ' . implode(' ', $errors);
        }
        $have = array_flip(array_column($this->documents((int) $institution['id']), 'kind'));
        foreach (self::required($institution) as $kind) {
            if (!isset($have[$kind])) {
                $out[] = 'Upload the ' . strtolower(self::DOCUMENTS[$kind]) . '.';
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $institution @return list<string> the document kinds this institution must send */
    public static function required(array $institution): array
    {
        $kinds = self::REQUIRED[(string) $institution['type']] ?? [];
        if (trim((string) ($institution['accreditation_authority'] ?? '')) !== '') {
            $kinds[] = 'accreditation_certificate';
        }
        return array_values(array_unique($kinds));
    }

    /**
     * Send the application for review: from a draft, after more information was asked for, or
     * after it was not approved. The representative accepts the agreement each time it is sent
     * unless the current versions were already accepted for this institution.
     *
     * @param array<string,mixed> $institution @param array<string,mixed> $user
     */
    public function submit(array $institution, array $user, bool $accepted, string $response, ?string $ip): void
    {
        $from = (string) $institution['status'];
        if (!in_array($from, self::EDITABLE, true)) {
            throw new RuntimeException('This application has been sent already.');
        }
        if (($missing = $this->missing($institution)) !== []) {
            throw new RuntimeException(implode(' ', $missing));
        }
        $iid = (int) $institution['id'];
        if (!$accepted && !$this->policies->acceptedAll(Policies::INSTITUTION, 'institution', $iid)) {
            throw new RuntimeException('Read the agreement and tick the box to accept it on behalf of the institution.');
        }
        if ($accepted) {
            $this->policies->accept((int) $user['id'], Policies::INSTITUTION, 'institution', $iid, $ip);
        }
        $response = mb_substr(trim($response), 0, 1000);
        if ($from === 'info_requested' && $response === '') {
            throw new RuntimeException('Say what you changed or answer the reviewer\'s question.');
        }
        $this->repo->setStatus($iid, $from, 'pending', $response !== '' ? $response : null, null, (int) $user['id']);
        $this->db->run('UPDATE institutions SET submitted_at = ? WHERE id = ?', [Database::now(), $iid]);
        $this->audit->record('institution.submitted', ['institution' => $institution['name'], 'from' => $from], 'institution', (string) $institution['uuid']);
        $this->notifier->notifyPermission('institutions.manage', 'institution', 'Institution to review: ' . $institution['name'],
            Access::displayName($user) . ($from === 'draft' ? ' sent the application of ' : ' sent the application again for ') . $institution['name'] . '.',
            '/platform/institutions/' . $institution['uuid'], 'institution-review:' . $institution['uuid'], 'institution-submitted:' . $institution['uuid'] . ':' . time(),
            (int) $user['id']);
    }

    /**
     * A reviewer's change to one verification dimension, kept in the review history.
     *
     * @param array<string,mixed> $institution @param array<string,mixed> $reviewer
     * @return bool whether it changed
     */
    public function setDimension(array $institution, string $dimension, string $state, string $note, string $evidence, array $reviewer): bool
    {
        $d = self::DIMENSIONS[$dimension] ?? throw new RuntimeException('There is no such verification.');
        if (!isset($d['states'][$state])) {
            throw new RuntimeException('Choose a state for ' . strtolower($d['label']) . '.');
        }
        $from = (string) $institution[$d['column']];
        if ($from === $state) {
            return false;
        }
        $now = Database::now();
        $iid = (int) $institution['id'];
        $this->db->transaction(function (Database $db) use ($d, $state, $iid, $dimension, $from, $note, $evidence, $reviewer, $now): void {
            $db->run('UPDATE institutions SET ' . $d['column'] . ' = ?, updated_at = ? WHERE id = ?', [$state, $now, $iid]);
            $db->run('INSERT INTO institution_reviews (institution_id, dimension, from_status, to_status, note, evidence, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$iid, $dimension, $from, $state, mb_substr(trim($note), 0, 1000) ?: null, mb_substr(trim($evidence), 0, 1000) ?: null, (int) $reviewer['id'], $now]);
        });
        $this->audit->record('institution.verification_changed', ['institution' => $institution['name'], 'dimension' => $dimension, 'from' => $from, 'to' => $state],
            'institution', (string) $institution['uuid']);
        return true;
    }

    /** One line per dimension and the platform approval, for display. @param array<string,mixed> $i @return list<array{label:string,state:string,text:string,tone:string}> */
    public static function verification(array $i): array
    {
        $tone = ['verified' => 'success', 'rejected' => 'danger', 'not_applicable' => 'neutral'];
        $out = [];
        foreach (self::DIMENSIONS as $d) {
            $s = (string) ($i[$d['column']] ?? '');
            $out[] = ['label' => $d['label'], 'state' => $s, 'text' => $d['states'][$s] ?? $s, 'tone' => $tone[$s] ?? 'warning'];
        }
        $s = (string) $i['status'];
        $out[] = ['label' => 'Platform approval', 'state' => $s, 'text' => Institutions::STATUSES[$s] ?? $s,
            'tone' => $s === 'verified' ? 'success' : (in_array($s, ['rejected', 'suspended'], true) ? 'danger' : 'warning')];
        return $out;
    }

    // Setup -----------------------------------------------------------------------------------

    /**
     * The first steps once an institution is approved, each done or not by what is on file.
     *
     * @param array<string,mixed> $institution @param array<string,mixed> $user
     * @return list<array{label:string,done:bool,href:string}>
     */
    public function setup(array $institution, array $user, bool $twoStep): array
    {
        $iid = (int) $institution['id'];
        $count = fn(string $sql): int => (int) $this->db->value($sql, [$iid]);
        $base = '/institutions/' . $institution['uuid'];
        return [
            ['label' => 'Turn on two-step sign-in for your account', 'done' => $twoStep, 'href' => '/account'],
            ['label' => 'Invite your staff', 'done' => $count("SELECT COUNT(*) FROM institution_memberships WHERE institution_id = ? AND role <> 'student' AND status = 'active'") > 1
                || $count('SELECT COUNT(*) FROM institution_invitations WHERE institution_id = ? AND role <> \'student\'') > 0, 'href' => $base . '/members'],
            ['label' => 'Set up departments and programmes', 'done' => $count('SELECT COUNT(*) FROM institution_programmes WHERE institution_id = ?') > 0, 'href' => $base . '/structure'],
            ['label' => 'Create a class', 'done' => $count('SELECT COUNT(*) FROM institution_classes WHERE institution_id = ?') > 0, 'href' => $base . '/classes'],
            ['label' => 'Enrol students', 'done' => $count("SELECT COUNT(*) FROM institution_memberships WHERE institution_id = ? AND role = 'student'") > 0
                || $count("SELECT COUNT(*) FROM institution_invitations WHERE institution_id = ? AND role = 'student'") > 0, 'href' => $base . '/students'],
            ['label' => 'Start a question bank', 'done' => $count('SELECT COUNT(*) FROM question_banks WHERE institution_id = ?') > 0, 'href' => $base . '/banks'],
            ['label' => 'Build your first assessment', 'done' => $count('SELECT COUNT(*) FROM assessments WHERE institution_id = ?') > 0, 'href' => $base . '/assessments'],
        ];
    }
}
