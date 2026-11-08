<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\InstitutionRepository;
use App\Repositories\UserRepository;
use RuntimeException;

/**
 * The rules for institutions: registering one, the platform's verification decisions, and who
 * belongs to it. Controllers check that the person may act in the institution at all
 * (InstitutionController::tenant); this class checks that the change itself is allowed, records
 * it in the activity history and tells the people it concerns. A refused change throws a
 * RuntimeException whose message is shown to the person.
 *
 * Verification by this platform means a reviewer looked at the registration. It is not
 * accreditation, and the pages never say it is.
 */
final class Institutions
{
    public const TYPES = [
        'university' => 'University',
        'college' => 'College',
        'tevet' => 'Technical and vocational training institution',
        'professional' => 'Professional training institution',
        'private_centre' => 'Private training centre',
        'corporate' => 'Corporate training department',
        'exam_provider' => 'Independent examination provider',
        'other' => 'Other',
    ];

    public const REGIONS = ['Northern' => 'Northern Region', 'Central' => 'Central Region', 'Southern' => 'Southern Region', 'Other' => 'Outside Malawi'];

    /** Suggested in the district box; any other place can be typed. */
    public const DISTRICTS = ['Balaka', 'Blantyre', 'Chikwawa', 'Chiradzulu', 'Chitipa', 'Dedza', 'Dowa', 'Karonga', 'Kasungu', 'Likoma',
        'Lilongwe', 'Machinga', 'Mangochi', 'Mchinji', 'Mulanje', 'Mwanza', 'Mzimba', 'Neno', 'Nkhata Bay', 'Nkhotakota', 'Nsanje',
        'Ntcheu', 'Ntchisi', 'Phalombe', 'Rumphi', 'Salima', 'Thyolo', 'Zomba'];

    /**
     * The application's lifecycle; 'pending' is a submitted application and 'verified' an
     * approved one (the stored names predate the lifecycle and every check reads them).
     */
    public const STATUSES = [
        'draft' => 'Draft, not sent',
        'pending' => 'Submitted',
        'under_review' => 'Under review',
        'info_requested' => 'More information requested',
        'verified' => 'Approved',
        'rejected' => 'Not approved',
        'suspended' => 'Suspended',
    ];

    public const TONES = ['draft' => 'neutral', 'pending' => 'warning', 'under_review' => 'info', 'info_requested' => 'warning', 'verified' => 'success',
        'rejected' => 'danger', 'suspended' => 'danger'];

    /** The decisions a platform reviewer can make, from the statuses each applies to. */
    public const DECISIONS = [
        'review' => ['to' => 'under_review', 'from' => ['pending'], 'label' => 'Start the review', 'note' => false],
        'request_info' => ['to' => 'info_requested', 'from' => ['pending', 'under_review'], 'label' => 'Ask for more information', 'note' => true],
        'verify' => ['to' => 'verified', 'from' => ['pending', 'under_review', 'suspended'], 'label' => 'Approve', 'note' => false],
        'reject' => ['to' => 'rejected', 'from' => ['pending', 'under_review', 'info_requested'], 'label' => 'Do not approve', 'note' => true],
        'suspend' => ['to' => 'suspended', 'from' => ['verified'], 'label' => 'Suspend', 'note' => true],
    ];

    /** Registrations one account may have waiting at once. */
    public const MAX_PENDING = 3;
    public const INVITE_DAYS = 14;

    public function __construct(
        private InstitutionRepository $repo,
        private UserRepository $users,
        private AuditLog $audit,
        private Notifier $notifier,
        private MailQueue $mail,
        private Mailer $mailer,
        private InstitutionStructure $structure,
    ) {
    }

    public function repo(): InstitutionRepository
    {
        return $this->repo;
    }

    /**
     * The posted profile, cleaned, with what is wrong with it.
     *
     * @param array<string,?string> $in
     * @return array{0:array<string,?string>,1:array<string,string>}
     */
    public static function validate(array $in): array
    {
        $v = [];
        foreach (InstitutionRepository::PROFILE as $f) {
            $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in[$f] ?? '')));
            $v[$f] = $f === 'intended_use' ? $text : preg_replace('/\s+/u', ' ', $text);
        }
        $errors = [];
        $len = static fn(string $f): int => mb_strlen((string) $v[$f]);
        $need = ['name' => [3, 160, 'The official name'], 'address' => [3, 255, 'The address'], 'district' => [2, 80, 'The district or town'],
            'contact_name' => [2, 120, 'The contact person'], 'contact_position' => [2, 120, 'Their position'], 'intended_use' => [10, 1000, 'How you plan to use it']];
        foreach ($need as $f => [$min, $max, $label]) {
            if ($len($f) < $min || $len($f) > $max) {
                $errors[$f] = $label . ' needs ' . $min . ' to ' . $max . ' characters.';
            }
        }
        foreach (['short_name' => 80, 'website' => 255, 'registration_info' => 500, 'services' => 1000, 'registration_authority' => 160, 'registration_number' => 80,
            'accreditation_authority' => 160, 'accreditation_reference' => 80, 'year_established' => 4, 'accreditation_expires' => 10, 'contact_phone' => 40] as $f => $max) {
            if ($len($f) > $max) {
                $errors[$f] = 'At most ' . $max . ' characters.';
            }
            $v[$f] = $v[$f] === '' ? null : $v[$f];
        }
        if ($v['year_established'] !== null && (preg_match('/^\d{4}$/', (string) $v['year_established']) !== 1 || (int) $v['year_established'] < 1850
            || (int) $v['year_established'] > (int) gmdate('Y'))) {
            $errors['year_established'] = 'Give the year it was established, for example 2014, or leave it empty.';
        }
        if ($v['accreditation_expires'] !== null) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v['accreditation_expires']);
            if ($d === false || $d->format('Y-m-d') !== $v['accreditation_expires']) {
                $errors['accreditation_expires'] = 'Give the date the accreditation expires, or leave it empty.';
            }
        }
        if ($v['accreditation_authority'] === null && ($v['accreditation_reference'] !== null || $v['accreditation_expires'] !== null)) {
            $errors['accreditation_authority'] = 'Name the accrediting body for the accreditation you describe.';
        }
        if ($v['registration_number'] !== null && $v['registration_authority'] === null) {
            $errors['registration_authority'] = 'Name the authority that issued the registration number.';
        }
        if (preg_match('/^\+?[0-9 ()\-]{7,40}$/', (string) $v['contact_phone']) !== 1) {
            $errors['contact_phone'] = 'Give the representative\'s telephone number: digits, spaces and an optional + at the start.';
        }
        if (!isset(self::TYPES[(string) $v['type']])) {
            $errors['type'] = 'Choose the kind of institution.';
        }
        if (!isset(self::REGIONS[(string) $v['region']])) {
            $errors['region'] = 'Choose the region.';
        }
        $v['email'] = UserRepository::normalizeEmail((string) $v['email']);
        if (!UserRepository::validEmail($v['email'])) {
            $errors['email'] = 'Give an email address the institution reads.';
        }
        if (preg_match('/^\+?[0-9 ()\-]{7,40}$/', (string) $v['phone']) !== 1) {
            $errors['phone'] = 'Give a telephone number: digits, spaces and an optional + at the start.';
        }
        if ($v['website'] !== null && preg_match('#^https?://[^\s/]+\.[^\s]+$#i', (string) $v['website']) !== 1) {
            $errors['website'] = 'Give the full address, starting with https://, or leave it empty.';
        }
        return [$v, $errors];
    }

    /**
     * Start an institution's application, as a draft. The person registering becomes its owner
     * (their authority to represent it is one of the things a reviewer verifies). Reviewers are
     * told once the application is sent (Onboarding::submit()), not before.
     *
     * @param array<string,?string> $profile already validated
     * @param array<string,mixed> $user
     * @return array<string,mixed> the institution
     */
    public function register(array $profile, array $user): array
    {
        if ($this->repo->pendingCreatedBy((int) $user['id']) >= self::MAX_PENDING) {
            throw new RuntimeException('You already have ' . self::MAX_PENDING . ' applications open. Finish or wait for a decision on those first.');
        }
        $id = $this->repo->create($profile, (int) $user['id']);
        $this->repo->join($id, (int) $user['id'], InstitutionAccess::OWNER, [], null);
        $institution = (array) $this->repo->find($id);
        $this->record('institution.registered', $institution, ['type' => $institution['type']]);
        return $institution;
    }

    /** @param array<string,mixed> $institution @param array<string,?string> $profile already validated */
    public function updateProfile(array $institution, array $profile): void
    {
        $changed = [];
        foreach (InstitutionRepository::PROFILE as $f) {
            if (($institution[$f] ?? null) !== ($profile[$f] ?? null)) {
                $changed[] = $f;
            }
        }
        if ($changed === []) {
            return;
        }
        $this->repo->updateProfile((int) $institution['id'], $profile);
        $this->record('institution.updated', $institution, ['fields' => $changed]);
    }

    /**
     * A platform reviewer's decision. Asking for information, declining and suspending need a
     * reason, which the owners see. Approving needs the organisation's identity and the
     * representative's authority verified (Onboarding::setDimension()); registration and
     * accreditation may stay unverified or not applicable.
     *
     * @param array<string,mixed> $institution @param array<string,mixed> $reviewer
     */
    public function decide(array $institution, string $decision, string $note, string $evidence, array $reviewer): void
    {
        $d = self::DECISIONS[$decision] ?? throw new RuntimeException('Choose a decision.');
        $from = (string) $institution['status'];
        if (!in_array($from, $d['from'], true)) {
            throw new RuntimeException('An application that is "' . (self::STATUSES[$from] ?? $from) . '" cannot be given that decision.');
        }
        $note = mb_substr(trim($note), 0, 1000);
        $evidence = mb_substr(trim($evidence), 0, 1000);
        if ($d['note'] && $note === '') {
            throw new RuntimeException('Give the reason. The institution\'s owners will see it.');
        }
        if ($decision === 'verify' && $from !== 'suspended'
            && ($institution['identity_status'] !== 'verified' || $institution['representative_status'] !== 'verified')) {
            throw new RuntimeException('Verify the organisation\'s identity and the representative\'s authority before approving.');
        }
        $this->repo->setStatus((int) $institution['id'], $from, $d['to'], $note !== '' ? $note : null, $evidence !== '' ? $evidence : null, (int) $reviewer['id']);
        $this->record('institution.status_changed', $institution, ['from' => $from, 'to' => $d['to']]);
        if (in_array($d['to'], ['verified', 'rejected', 'info_requested', 'suspended'], true)) {
            $this->notifier->resolve('institution-review:' . $institution['uuid']);
        }
        if ($d['to'] === 'under_review') {
            return;
        }
        $title = match ($d['to']) {
            'verified' => $from === 'suspended' ? $institution['name'] . ' is reinstated' : $institution['name'] . ' is approved',
            'rejected' => $institution['name'] . ' was not approved',
            'info_requested' => $institution['name'] . ': more information is needed',
            default => $institution['name'] . ' is suspended',
        };
        $body = match ($d['to']) {
            'verified' => 'Your institution\'s workspace is ready. Start with the setup steps on its overview.',
            'rejected' => 'Reason: ' . $note . ' You can correct the application and send it again.',
            'info_requested' => 'The reviewer asks: ' . $note . ' Answer it on the application page and send it again.',
            default => 'Reason: ' . $note . ' Its members cannot use it until it is reinstated. Its records and certificates are kept.',
        };
        foreach ($this->repo->owners((int) $institution['id']) as $owner) {
            $this->notifier->notify($owner, 'institution', $title, $body, '/institutions/' . $institution['uuid'] . ($d['to'] === 'verified' ? '' : '/application'), null,
                'institution-decision:' . $institution['uuid'] . ':' . $d['to'] . ':' . time());
        }
    }

    /**
     * Invite someone by email address, with a role. Returns the link to accept, which is also
     * emailed when email is set up, and an inbox notice when the address belongs to an account.
     *
     * @param array<string,mixed> $institution @param array<string,mixed> $actor its membership, of the person inviting
     * @param array<string,mixed> $user the person inviting
     * @param array<string,mixed>|null $details applied on acceptance (a student import's number, programme and classes)
     * @return array{path:string,link:string,emailed:bool}
     */
    public function invite(array $institution, array $actor, array $user, string $email, string $role, ?array $details = null): array
    {
        $email = UserRepository::normalizeEmail($email);
        if (!UserRepository::validEmail($email)) {
            throw new RuntimeException('Give the email address of the person to invite.');
        }
        $this->assertRoleGrantable($actor, $role);
        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            $m = $this->repo->membership((int) $institution['id'], (int) $existing['id']);
            if ($m !== null && $m['status'] === 'active') {
                throw new RuntimeException($email . ' already belongs to the institution.');
            }
        }
        $token = bin2hex(random_bytes(24));
        $expires = gmdate('Y-m-d H:i:s', time() + self::INVITE_DAYS * 86400);
        $id = $this->repo->invite((int) $institution['id'], $email, $role, hash('sha256', $token), (int) $user['id'], $expires, $details);
        $path = '/invitations/' . $token;
        $this->record('institution.member_invited', $institution, ['email' => $email, 'role' => $role]);
        $title = Access::displayName($user) . ' invited you to ' . $institution['name'];
        $line = 'You are invited to join ' . $institution['name'] . ' as ' . InstitutionAccess::roleName($role) . '. The invitation lasts ' . self::INVITE_DAYS . ' days.';
        if ($existing !== null && $existing['status'] === 'active') {
            // The inbox notice links to the invitation; the email below carries the same link.
            $this->notifier->notify(['email' => null] + $existing, 'institution', $title, $line, $path, 'invitation:' . $id, 'invitation:' . $id);
        }
        $emailed = false;
        $url = $this->mailer->link($path);
        if ($this->mailer->configured() && $url !== null) {
            $emailed = $this->mail->enqueue('invitation:' . $id, $email, null, $title,
                '<p>' . e($line) . '</p><p><a href="' . e($url) . '">See the invitation</a></p>'
                . '<p style="color:#6b7280;font-size:13px">Accept it while signed in to an account with this email address. If you did not expect it, ignore this email.</p>');
        }
        return ['path' => $path, 'link' => $url ?? $path, 'emailed' => $emailed];
    }

    /**
     * Send an open invitation again, with a fresh link (the old one stops working) lasting
     * another INVITE_DAYS. Works on an invitation that expired without being answered.
     *
     * @param array<string,mixed> $institution @param array<string,mixed> $user
     * @return array{link:string,emailed:bool}
     */
    public function resend(array $institution, array $user, int $invitationId): array
    {
        $inv = $this->repo->pendingInvitation((int) $institution['id'], $invitationId) ?? throw new RuntimeException('That invitation is no longer open.');
        $token = bin2hex(random_bytes(24));
        $this->repo->renewInvitation((int) $institution['id'], $invitationId, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + self::INVITE_DAYS * 86400));
        $path = '/invitations/' . $token;
        $this->record('institution.invitation_resent', $institution, ['email' => $inv['email']]);
        $title = 'Reminder: ' . Access::displayName($user) . ' invited you to ' . $institution['name'];
        $line = 'You are invited to join ' . $institution['name'] . ' as ' . InstitutionAccess::roleName((string) $inv['role']) . '. This link lasts ' . self::INVITE_DAYS . ' days.';
        $existing = $this->users->findByEmail((string) $inv['email']);
        if ($existing !== null && $existing['status'] === 'active') {
            $this->notifier->notify(['email' => null] + $existing, 'institution', $title, $line, $path, 'invitation:' . $invitationId, 'invitation:' . $invitationId . ':' . time());
        }
        $url = $this->mailer->link($path);
        $emailed = $this->mailer->configured() && $url !== null && $this->mail->enqueue('invitation:' . $invitationId . ':' . time(), (string) $inv['email'], null, $title,
            '<p>' . e($line) . '</p><p><a href="' . e($url) . '">See the invitation</a></p>'
            . '<p style="color:#6b7280;font-size:13px">Accept it while signed in to an account with this email address. If you did not expect it, ignore this email.</p>');
        return ['link' => $url ?? $path, 'emailed' => $emailed];
    }

    /** @param array<string,mixed> $institution */
    public function revoke(array $institution, int $invitationId): void
    {
        $inv = $this->repo->openInvitation((int) $institution['id'], $invitationId) ?? throw new RuntimeException('That invitation is no longer open.');
        $this->repo->revokeInvitation((int) $institution['id'], $invitationId);
        $this->notifier->resolve('invitation:' . $invitationId);
        $this->record('institution.invitation_revoked', $institution, ['email' => $inv['email']]);
    }

    /**
     * Why this person cannot accept the invitation, or null when they can.
     *
     * @param array<string,mixed> $inv from invitationByToken() @param array<string,mixed>|null $user
     */
    public function refusal(array $inv, ?array $user): ?string
    {
        if ($inv['revoked_at'] !== null) {
            return 'This invitation was withdrawn.';
        }
        if ($inv['accepted_at'] !== null) {
            return 'This invitation has been accepted.';
        }
        if ($inv['declined_at'] !== null) {
            return 'This invitation was declined.';
        }
        if ((string) $inv['expires_at'] <= Database::now()) {
            return 'This invitation has expired. Ask the institution to invite you again.';
        }
        if ($inv['institution_status'] !== 'verified') {
            return 'The institution cannot take new members at the moment.';
        }
        if ($user !== null && UserRepository::normalizeEmail((string) ($user['email'] ?? '')) !== (string) $inv['email']) {
            return 'This invitation is for ' . $inv['email'] . '. Sign in with the account that has that email address.';
        }
        return null;
    }

    /** @param array<string,mixed> $inv @param array<string,mixed> $user */
    public function accept(array $inv, array $user, ?Policies $policies = null, ?string $ip = null): void
    {
        if (($why = $this->refusal($inv, $user)) !== null) {
            throw new RuntimeException($why);
        }
        $institution = (array) $this->repo->find((int) $inv['institution_id']);
        $existing = $this->repo->membership((int) $institution['id'], (int) $user['id']);
        if ($existing !== null && $existing['status'] === 'active') {
            $this->repo->closeInvitation((int) $inv['id'], 'accepted', (int) $user['id']);
            throw new RuntimeException('You already belong to ' . $institution['name'] . '.');
        }
        $this->repo->join((int) $institution['id'], (int) $user['id'], (string) $inv['role'], [], $inv['invited_by'] !== null ? (int) $inv['invited_by'] : null);
        $this->repo->closeInvitation((int) $inv['id'], 'accepted', (int) $user['id']);
        $details = json_decode((string) ($inv['details'] ?? ''), true);
        if (is_array($details) && $inv['role'] === InstitutionAccess::STUDENT) {
            $m = $this->repo->membership((int) $institution['id'], (int) $user['id']);
            $this->structure->applyInvitationDetails((int) $institution['id'], (int) $m['id'], $details, $inv['invited_by'] !== null ? (int) $inv['invited_by'] : null);
        }
        $this->notifier->resolve('invitation:' . $inv['id']);
        if ($policies !== null) {
            $joined = $this->repo->membership((int) $institution['id'], (int) $user['id']);
            $policies->accept((int) $user['id'], ['membership_notice'], 'membership', (int) $joined['id'], $ip);
        }
        $this->record('institution.invitation_accepted', $institution, ['role' => $inv['role']]);
    }

    /** @param array<string,mixed> $inv @param array<string,mixed> $user */
    public function decline(array $inv, array $user): void
    {
        $why = $this->refusal($inv, $user);
        if ($why !== null && !str_starts_with($why, 'The institution')) {
            throw new RuntimeException($why);
        }
        $this->repo->closeInvitation((int) $inv['id'], 'declined', (int) $user['id']);
        $this->notifier->resolve('invitation:' . $inv['id']);
        $this->record('institution.invitation_declined', (array) $this->repo->find((int) $inv['institution_id']));
    }

    /**
     * Change a member's role and extra permissions.
     *
     * @param array<string,mixed> $institution @param array<string,mixed> $actor membership of the person changing it
     * @param array<string,mixed> $member from member() @param array<mixed> $posted extra permissions as posted
     */
    public function changeRole(array $institution, array $actor, array $member, string $role, array $posted): void
    {
        $this->assertCanChange($actor, $member);
        $this->assertRoleGrantable($actor, $role);
        if ($member['role'] === InstitutionAccess::OWNER && $role !== InstitutionAccess::OWNER && $this->repo->activeOwners((int) $institution['id']) <= 1) {
            throw new RuntimeException('An institution needs at least one owner. Make someone else an owner first.');
        }
        $extras = InstitutionAccess::cleanExtras($role, $posted, InstitutionAccess::permissions($actor));
        // A permission the person granting cannot give stays as it was.
        foreach (InstitutionAccess::extras($member) as $kept) {
            if (!InstitutionAccess::can($actor, $kept) && $role !== InstitutionAccess::OWNER && !in_array($kept, $extras, true)) {
                $extras[] = $kept;
            }
        }
        sort($extras);
        $before = InstitutionAccess::extras($member);
        sort($before);
        if ($role === $member['role'] && $extras === $before) {
            return;
        }
        $this->repo->setRole((int) $institution['id'], (int) $member['id'], $role, $extras);
        $this->record('institution.member_role_changed', $institution, ['username' => $member['username'], 'from' => $member['role'], 'to' => $role,
            'permissions' => $extras]);
    }

    /** @param array<string,mixed> $institution @param array<string,mixed> $actor @param array<string,mixed> $member */
    public function removeMember(array $institution, array $actor, array $member): void
    {
        $this->assertCanChange($actor, $member);
        if ($member['role'] === InstitutionAccess::OWNER && $this->repo->activeOwners((int) $institution['id']) <= 1) {
            throw new RuntimeException('An institution needs at least one owner.');
        }
        $this->repo->remove((int) $institution['id'], (int) $member['id'], (int) $actor['user_id']);
        $this->structure->repo()->endAllEnrolments((int) $institution['id'], (int) $member['id'], (int) $actor['user_id']);
        $this->record('institution.member_removed', $institution, ['username' => $member['username'], 'role' => $member['role']]);
    }

    /** @param array<string,mixed> $institution @param array<string,mixed> $membership the person's own */
    public function leave(array $institution, array $membership): void
    {
        if ($membership['role'] === InstitutionAccess::OWNER && $this->repo->activeOwners((int) $institution['id']) <= 1) {
            throw new RuntimeException('You are the only owner. Make someone else an owner before you leave.');
        }
        $this->repo->remove((int) $institution['id'], (int) $membership['id'], (int) $membership['user_id']);
        $this->structure->repo()->endAllEnrolments((int) $institution['id'], (int) $membership['id'], (int) $membership['user_id']);
        $this->record('institution.member_left', $institution, ['role' => $membership['role']]);
    }

    /** @param array<string,mixed> $actor */
    private function assertRoleGrantable(array $actor, string $role): void
    {
        if (!isset(InstitutionAccess::ROLES[$role])) {
            throw new RuntimeException('Choose a role.');
        }
        if (!InstitutionAccess::can($actor, 'members.manage')) {
            throw new RuntimeException('You cannot change who belongs to the institution.');
        }
        if ($role === InstitutionAccess::OWNER && $actor['role'] !== InstitutionAccess::OWNER) {
            throw new RuntimeException('Only an owner can make someone an owner.');
        }
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $member */
    private function assertCanChange(array $actor, array $member): void
    {
        if ((int) $member['id'] === (int) $actor['id']) {
            throw new RuntimeException('You cannot change your own membership here. Another manager can, or you can leave the institution.');
        }
        if ($member['status'] !== 'active') {
            throw new RuntimeException('That person no longer belongs to the institution.');
        }
        if (!InstitutionAccess::can($actor, 'members.manage')) {
            throw new RuntimeException('You cannot change who belongs to the institution.');
        }
        if ($member['role'] === InstitutionAccess::OWNER && $actor['role'] !== InstitutionAccess::OWNER) {
            throw new RuntimeException('Only an owner can change another owner.');
        }
    }

    /** @param array<string,mixed> $institution @param array<string,mixed> $details */
    private function record(string $action, array $institution, array $details = []): void
    {
        $this->audit->record($action, ['institution' => (string) ($institution['name'] ?? '?')] + $details, 'institution', (string) ($institution['uuid'] ?? ''));
    }
}
