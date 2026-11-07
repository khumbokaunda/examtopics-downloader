<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Services\InstitutionAccess;

/**
 * The base of every page inside an institution. Routes to these pages are open to any signed-in
 * account; tenant() is the gate. Someone without an active membership of the institution gets
 * "not found" (whether or not it exists, and whatever their global role), and a member without
 * the institution permission a page needs gets 403.
 */
abstract class TenantController extends Controller
{
    /**
     * The institution and the signed-in person's membership of it, or the refusal.
     *
     * @param bool $verifiedOnly whether the page needs a verified institution (inviting, the
     *        member list); the overview, profile and leaving work while it waits.
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    protected function tenant(string $ref, ?string $permission, bool $verifiedOnly = true): array
    {
        $repo = $this->s->institutions()->repo();
        $i = $repo->findByUuid($ref);
        $m = $i !== null ? $repo->membership((int) $i['id'], (int) $this->auth->user()['id']) : null;
        if ($i === null || $m === null || $m['status'] !== 'active') {
            throw HttpException::notFound('Institution');
        }
        $owner = $m['role'] === InstitutionAccess::OWNER;
        if ($i['status'] === 'suspended' && !($owner && !$verifiedOnly)) {
            throw new HttpException(403, $i['name'] . ' is suspended, so its pages are closed. Its owners can see why.');
        }
        if ($verifiedOnly && $i['status'] !== 'verified') {
            throw new HttpException(403, 'This opens once ' . $i['name'] . ' is verified.');
        }
        if ($permission !== null && !InstitutionAccess::can($m, $permission)) {
            throw new HttpException(403, 'Your role in ' . $i['name'] . ' does not include this. It needs the "' . $permission . '" permission, which an owner can give you.');
        }
        return [$i, $m];
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $m @return array<string,mixed> */
    protected function pageVars(array $i, array $m, string $tab): array
    {
        return ['title' => $i['short_name'] ?: $i['name'], 'nav' => 'institutions', 'institution' => $i, 'membership' => $m, 'tab' => $tab];
    }

    /** @param array<string,mixed> $p */
    protected function name(array $p): string
    {
        return \App\Services\Access::displayName($p);
    }
}
