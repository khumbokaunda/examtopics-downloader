<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Who may do what. A person holds one role; a role holds permissions (small named rights such
 * as "library.view"); a person may also be given extra permissions on top of their role. The
 * administrator holds every permission, including any added later.
 *
 * Routes are gated by a permission (routes/web.php), the Kernel answers 403 when it is
 * missing, and templates hide what the server would refuse by asking can().
 */
final class Access
{
    public const ADMIN = 'admin';
    public const MEMBER = 'member';

    /** Account states. Only active accounts can sign in. */
    public const STATUSES = [
        'active' => 'Active',
        'unverified' => 'Email not verified',
        'pending' => 'Waiting for approval',
        'disabled' => 'Disabled',
    ];

    public const ROLES = [
        self::ADMIN => ['name' => 'Administrator', 'description' => 'Everything: collecting, users, settings and backups.'],
        self::MEMBER => ['name' => 'Member', 'description' => 'Reads and practises the collected exams and requests new ones.'],
    ];

    /** The catalogue: every permission the code checks, with the sentence shown beside its box. */
    public const PERMISSIONS = [
        'library.view' => 'Browse providers and collected exams, read and practise them, and download built documents.',
        'exams.request' => 'Request exams that have not been collected yet.',
        'inventory.first' => 'Load the exam list of a provider that has never been fetched.',
        'documents.build' => 'Build and delete PDF and Markdown documents.',
        'answers.edit' => 'Correct answers and mark them as checked.',
        'exams.collect' => 'Collect exams, refresh any provider, add providers, download images and see jobs.',
        'library.manage' => 'Delete cached versions of an exam, and choose which exams share a card as editions.',
        'requests.manage' => 'See and answer every exam request, and be notified of new ones.',
        'users.manage' => 'Create, edit, approve and disable accounts, and be notified of sign-ups.',
        'settings.manage' => 'Change system, email and branding settings.',
        'backup.manage' => 'Create, download and restore backups.',
        'progress.view_all' => 'See every member\'s study progress: the exams they read and practised, and how many answers they got right.',
        'activity.view' => 'See the activity history: sign-ins, and changes to accounts, settings, the library and backups.',
        'security.manage' => 'Review sign-in attempts, unlock accounts and unblock networks, and be told when either is locked.',
    ];

    /** What each role gives. The administrator is not listed: it holds everything. */
    public const ROLE_PERMISSIONS = [
        self::MEMBER => ['library.view', 'exams.request', 'inventory.first'],
    ];

    /** A gate meaning "any signed-in person": the account pages everyone has. */
    public const SIGNED_IN = 'signed-in';

    public static function isAdmin(?array $user): bool
    {
        return ($user['role'] ?? '') === self::ADMIN;
    }

    /**
     * Every permission the person holds.
     *
     * @param array<string,mixed>|null $user
     * @return list<string>
     */
    public static function permissions(?array $user): array
    {
        if ($user === null) {
            return [];
        }
        if (self::isAdmin($user)) {
            return array_keys(self::PERMISSIONS);
        }
        $held = array_merge(self::ROLE_PERMISSIONS[(string) ($user['role'] ?? '')] ?? [], self::extras($user));
        return array_values(array_unique(array_intersect($held, array_keys(self::PERMISSIONS))));
    }

    /** @param array<string,mixed>|null $user */
    public static function can(?array $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }
        if ($permission === self::SIGNED_IN || self::isAdmin($user)) {
            return true;
        }
        return in_array($permission, self::permissions($user), true);
    }

    /**
     * The permissions given to this person on top of their role.
     *
     * @param array<string,mixed> $user
     * @return list<string>
     */
    public static function extras(array $user): array
    {
        $list = json_decode((string) ($user['permissions'] ?? ''), true);
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_filter($list, static fn($p): bool => is_string($p) && isset(self::PERMISSIONS[$p])));
    }

    /**
     * Extra permissions to store from a form: only catalogue entries the role does not already
     * give. Administrators need none.
     *
     * @param array<mixed> $posted
     * @return list<string>
     */
    public static function cleanExtras(string $role, array $posted): array
    {
        if ($role === self::ADMIN) {
            return [];
        }
        $given = self::ROLE_PERMISSIONS[$role] ?? [];
        $out = [];
        foreach ($posted as $p) {
            if (is_string($p) && isset(self::PERMISSIONS[$p]) && !in_array($p, $given, true) && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** The one list of roles a form may offer and the server accepts. @return array<string,string> */
    public static function assignableRoles(): array
    {
        return array_map(static fn(array $r): string => $r['name'], self::ROLES);
    }

    public static function roleName(string $role): string
    {
        return self::ROLES[$role]['name'] ?? $role;
    }

    /** The name shown for a person: their display name, else their username. @param array<string,mixed> $user */
    public static function displayName(array $user): string
    {
        $name = trim((string) ($user['display_name'] ?? ''));
        return $name !== '' ? $name : (string) ($user['username'] ?? '');
    }
}
