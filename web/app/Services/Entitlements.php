<?php

declare(strict_types=1);

namespace App\Services;

/**
 * What an account has been given of the products on this installation. There is one today: the
 * library (reading, practising and requesting the collected exams). It is separate from the
 * institution side: a student a college enrols does not get the library with their account.
 *
 * This is the one place that answers "does this account have it", so that plans and billing can
 * decide it later without touching the pages that ask. Today the answer is a column on the
 * account (users.library_access), set when the account is made and changed by an administrator.
 */
final class Entitlements
{
    public const LIBRARY = 'library';

    /** The permissions that are the library: a role gives them only to an account that has it. */
    public const LIBRARY_PERMISSIONS = ['library.view', 'exams.request', 'inventory.first'];

    /** @param array<string,mixed>|null $user */
    public static function has(?array $user, string $what): bool
    {
        if ($user === null || $what !== self::LIBRARY) {
            return false;
        }
        // An account read before the column existed (or built by hand in a test) has it.
        return Access::isAdmin($user) || (int) ($user['library_access'] ?? 1) === 1;
    }

    /**
     * The permissions a role gives this account: the role's, less the library's when the
     * account does not have the library.
     *
     * @param array<string,mixed> $user @param list<string> $rolePermissions @return list<string>
     */
    public static function filter(array $user, array $rolePermissions): array
    {
        return self::has($user, self::LIBRARY) ? $rolePermissions : array_values(array_diff($rolePermissions, self::LIBRARY_PERMISSIONS));
    }

    /**
     * Whether a new account starts with the library: yes when someone signs up for themselves,
     * no when they arrive through an institution (an invitation, a student import, an enrolment
     * code, or registering an institution).
     */
    public static function libraryForNewAccount(bool $throughInstitution): bool
    {
        return !$throughInstitution;
    }
}
