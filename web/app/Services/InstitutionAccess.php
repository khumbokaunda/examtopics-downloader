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
        self::OWNER => ['name' => 'Owner', 'description' => 'Responsible for the institution\'s account: its profile, its people and their roles.'],
        self::ADMIN => ['name' => 'Institution administrator', 'description' => 'Runs the day to day: people, departments and programmes, classes and student records (not owners).'],
        'instructor' => ['name' => 'Instructor', 'description' => 'Teaches and assesses. Sees who belongs to the institution and the classes they are assigned to.'],
        'exam_officer' => ['name' => 'Examination officer', 'description' => 'Administers examinations. Sees who belongs to the institution and every class.'],
        'invigilator' => ['name' => 'Invigilator', 'description' => 'Supervises examination sessions they are assigned to.'],
        self::STUDENT => ['name' => 'Student', 'description' => 'Belongs to classes and takes the assessments assigned to them.'],
    ];

    public const PERMISSIONS = [
        'institution.manage' => 'Edit the institution\'s profile.',
        'members.view' => 'See who belongs to the institution and their roles.',
        'members.manage' => 'Invite people, change their roles and remove them. Only owners can make or change owners.',
        'activity.view' => 'See the institution\'s activity history.',
        'departments.manage' => 'Add, rename and remove departments and programmes.',
        'classes.view' => 'See every class and who is in it. Instructors always see the classes they teach.',
        'classes.manage' => 'Create and archive classes, assign their instructors, and enrol, move and remove students.',
        'students.manage' => 'Keep student records (number, programme, standing) and import students from a spreadsheet.',
    ];

    /** What each role gives. The owner is not listed: it holds every institution permission. */
    public const ROLE_PERMISSIONS = [
        self::ADMIN => ['members.view', 'members.manage', 'activity.view', 'departments.manage', 'classes.view', 'classes.manage', 'students.manage'],
        'instructor' => ['members.view'],
        'exam_officer' => ['members.view', 'classes.view'],
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
