<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Who may do what inside an institution. It works like App\Services\Access, but per
 * membership: a person's role and extra permissions in one institution give them nothing in
 * another, and their global role (member, administrator) gives them nothing in any. A platform
 * administrator who is not a member of an institution cannot open its pages at all.
 *
 * The catalogue lists only permissions the code checks today. Question banks, assessments,
 * results and integrity add theirs as they are built, so a box is never offered that does
 * nothing.
 */
final class InstitutionAccess
{
    public const OWNER = 'owner';
    public const ADMIN = 'admin';
    public const STUDENT = 'student';

    public const ROLES = [
        self::OWNER => ['name' => 'Owner', 'description' => 'Full control.'],
        self::ADMIN => ['name' => 'Institution administrator', 'description' => 'Runs people, classes and records.'],
        'instructor' => ['name' => 'Instructor', 'description' => 'Writes questions and teaches classes.'],
        'exam_officer' => ['name' => 'Examination officer', 'description' => 'Runs assessments and results.'],
        'invigilator' => ['name' => 'Invigilator', 'description' => 'Watches assigned sittings.'],
        self::STUDENT => ['name' => 'Student', 'description' => 'Takes assessments.'],
    ];

    public const PERMISSIONS = [
        'institution.manage' => 'Edit the profile.',
        'members.view' => 'See people and roles.',
        'members.manage' => 'Invite, change and remove people.',
        'activity.view' => 'See activity.',
        'departments.manage' => 'Manage departments and programmes.',
        'classes.view' => 'See all classes.',
        'classes.manage' => 'Manage classes and enrolments.',
        'students.manage' => 'Manage student records and imports.',
        'question_banks.view' => 'See all question banks.',
        'question_banks.create' => 'Create banks and write questions.',
        'question_banks.edit' => 'Edit any question bank.',
        'question_banks.publish' => 'Approve and publish questions.',
        'assessments.view' => 'See all assessments.',
        'assessments.create' => 'Build assessments.',
        'assessments.assign' => 'Assign assessments.',
        'assessments.schedule' => 'Publish and schedule assessments.',
        'results.publish' => 'Publish results.',
        'results.export' => 'Download results and reports.',
        'integrity.view' => 'See integrity reports.',
        'integrity.manage' => 'Record integrity conclusions.',
        'assessments.monitor' => 'Watch sittings live.',
        'assessments.intervene' => 'Pause, extend or end sittings.',
        'transcripts.issue' => 'Issue transcripts.',
        'credentials.issue' => 'Request credentials.',
        'credentials.manage' => 'Manage credential templates.',
    ];

    /** Short names for the permissions, for summaries. */
    public const AREAS = [
        'institution.manage' => 'Profile', 'members.view' => 'People', 'members.manage' => 'People', 'activity.view' => 'Activity',
        'departments.manage' => 'Programmes', 'classes.view' => 'Classes', 'classes.manage' => 'Classes', 'students.manage' => 'Students',
        'question_banks.view' => 'Question banks', 'question_banks.create' => 'Question banks', 'question_banks.edit' => 'Question banks',
        'question_banks.publish' => 'Question banks', 'assessments.view' => 'Assessments', 'assessments.create' => 'Assessments',
        'assessments.assign' => 'Assessments', 'assessments.schedule' => 'Assessments', 'results.publish' => 'Results', 'results.export' => 'Results',
        'integrity.view' => 'Integrity', 'integrity.manage' => 'Integrity', 'assessments.monitor' => 'Invigilation', 'assessments.intervene' => 'Invigilation',
        'transcripts.issue' => 'Transcripts', 'credentials.issue' => 'Credentials', 'credentials.manage' => 'Credentials',
    ];

    /** What each role gives. The owner is not listed: it holds every institution permission. */
    public const ROLE_PERMISSIONS = [
        self::ADMIN => ['members.view', 'members.manage', 'activity.view', 'departments.manage', 'classes.view', 'classes.manage', 'students.manage',
            'question_banks.view', 'question_banks.create', 'question_banks.edit', 'question_banks.publish',
            'assessments.view', 'assessments.create', 'assessments.assign', 'assessments.schedule', 'results.publish', 'results.export', 'integrity.view', 'integrity.manage', 'assessments.monitor', 'assessments.intervene', 'transcripts.issue', 'credentials.issue', 'credentials.manage'],
        'instructor' => ['members.view', 'question_banks.create', 'assessments.create', 'assessments.assign'],
        'exam_officer' => ['members.view', 'classes.view', 'question_banks.view', 'question_banks.publish',
            'assessments.view', 'assessments.create', 'assessments.assign', 'assessments.schedule', 'results.publish', 'results.export', 'integrity.view', 'integrity.manage', 'assessments.monitor', 'assessments.intervene', 'transcripts.issue', 'credentials.issue'],
        'invigilator' => [],
        self::STUDENT => [],
    ];

    /**
     * @param array<string,mixed>|null $membership an active institution_memberships row
     * @return list<string>
     */
    public static function permissions(?array $membership): array
    {
        if ($membership === null || ($membership['status'] ?? '') !== 'active') {
            return [];
        }
        $role = (string) ($membership['role'] ?? '');
        if ($role === self::OWNER) {
            return array_keys(self::PERMISSIONS);
        }
        $held = array_merge(self::ROLE_PERMISSIONS[$role] ?? [], self::extras($membership));
        return array_values(array_unique(array_intersect($held, array_keys(self::PERMISSIONS))));
    }

    /** @param array<string,mixed>|null $membership */
    public static function can(?array $membership, string $permission): bool
    {
        return in_array($permission, self::permissions($membership), true);
    }

    /** @param array<string,mixed> $membership @return list<string> */
    public static function extras(array $membership): array
    {
        $list = json_decode((string) ($membership['permissions'] ?? ''), true);
        return is_array($list) ? array_values(array_filter($list, static fn($p): bool => is_string($p) && isset(self::PERMISSIONS[$p]))) : [];
    }

    /**
     * Extra permissions to store from a form: catalogue entries the role does not already give,
     * and only ones the person granting them holds (nobody hands out more than they have).
     *
     * @param array<mixed> $posted @param list<string> $grantable
     * @return list<string>
     */
    public static function cleanExtras(string $role, array $posted, array $grantable): array
    {
        if ($role === self::OWNER) {
            return [];
        }
        $given = self::ROLE_PERMISSIONS[$role] ?? [];
        $out = [];
        foreach ($posted as $p) {
            if (is_string($p) && isset(self::PERMISSIONS[$p]) && in_array($p, $grantable, true) && !in_array($p, $given, true) && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public static function roleName(string $role): string
    {
        return self::ROLES[$role]['name'] ?? $role;
    }
}
