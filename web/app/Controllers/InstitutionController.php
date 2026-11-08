<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Repositories\InstitutionRepository;
use App\Services\AuditText;
use App\Services\InstitutionAccess;
use App\Services\Institutions;
use RuntimeException;

/**
 * An institution's own pages, for the people who belong to it. Every route is open to any
 * signed-in account at the router; tenant() is the gate: someone who does not belong to the
 * institution gets "not found" (whether or not it exists, and whatever their global role), and a
 * member without the institution permission a page needs gets 403.
 */
final class InstitutionController extends TenantController
{
    public function index(): Response
    {
        $mine = $this->s->institutions()->repo()->forUser((int) $this->auth->user()['id']);
        return $this->render('institutions/index', ['title' => 'Institutions', 'nav' => 'institutions', 'mine' => $mine]);
    }

    public function registerForm(): Response
    {
        $user = $this->auth->user();
        $blank = array_fill_keys(InstitutionRepository::PROFILE, '');
        $blank['email'] = (string) ($user['email'] ?? '');
        $blank['contact_name'] = (string) ($user['display_name'] ?? '');
        return $this->render('institutions/register', ['title' => 'Register an institution', 'nav' => 'institutions', 'form' => $blank, 'errors' => []]);
    }

    public function register(): Response
    {
        [$profile, $errors] = Institutions::validate($this->profileInput());
        if ($errors !== []) {
            return $this->render('institutions/register', ['title' => 'Register an institution', 'nav' => 'institutions', 'form' => $profile, 'errors' => $errors], 422);
        }
        try {
            $institution = $this->s->institutions()->register($profile, $this->auth->user());
        } catch (RuntimeException $e) {
            return $this->render('institutions/register', ['title' => 'Register an institution', 'nav' => 'institutions', 'form' => $profile,
                'errors' => ['_' => $e->getMessage()]], 422);
        }
        $this->flash('success', 'The application for ' . $institution['name'] . ' is started. Add its documents and send it for review.');
        return $this->redirect('/institutions/' . $institution['uuid'] . '/application');
    }

    public function show(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, null, false);
        $repo = $this->s->institutions()->repo();
        return $this->render('institutions/show', $this->pageVars($i, $m, 'overview') + [
            'roleCounts' => $repo->roleCounts((int) $i['id']),
            'invitations' => InstitutionAccess::can($m, 'members.view') ? count($repo->openInvitations((int) $i['id'])) : null,
            'activity' => InstitutionAccess::can($m, 'activity.view') ? $this->activityRows($i, 8) : null,
            'reviews' => [],
            'setup' => $i['status'] === 'verified' && InstitutionAccess::can($m, 'institution.manage')
                ? $this->s->onboarding()->setup($i, $this->auth->user(), $this->s->twoStep()->enabled((int) $this->auth->user()['id'])) : null,
            'counts' => $this->s->structure()->repo()->counts((int) $i['id']),
            'myClasses' => array_values(array_filter($this->s->structure()->repo()->enrolmentsOf((int) $i['id'], (int) $m['id']), static fn(array $e): bool => $e['status'] === 'active')),
            'teaching' => $this->s->structure()->repo()->classes((int) $i['id'], 'active', (int) $m['id']),
            'overview' => InstitutionAccess::can($m, 'assessments.view') ? $this->s->results()->overview((int) $i['id']) : null,
        ]);
    }

    public function profile(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'institution.manage', false);
        return $this->render('institutions/profile', $this->pageVars($i, $m, 'profile') + ['form' => $i, 'errors' => []]);
    }

    public function saveProfile(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'institution.manage', false);
        [$profile, $errors] = Institutions::validate($this->profileInput());
        if ($errors !== []) {
            return $this->render('institutions/profile', $this->pageVars($i, $m, 'profile') + ['form' => $profile, 'errors' => $errors], 422);
        }
        $this->s->institutions()->updateProfile($i, $profile);
        $this->flash('success', 'The profile is saved.' . (in_array($i['status'], \App\Services\Onboarding::EDITABLE, true) ? ' Send the application for review when it is ready.' : ''));
        return $this->redirect('/institutions/' . $i['uuid'] . '/profile');
    }

    public function resubmit(string $ref): Response
    {
        [$i] = $this->tenant($ref, 'institution.manage', false);
        try {
            $this->s->onboarding()->submit($i, $this->auth->user(), false, (string) $this->request->input('response', ''), $this->request->ip());
            $this->flash('success', 'Sent for review again.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid']);
    }

    public function members(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'members.view');
        $repo = $this->s->institutions()->repo();
        $manage = InstitutionAccess::can($m, 'members.manage');
        return $this->render('institutions/members', $this->pageVars($i, $m, 'members') + [
            'members' => $repo->members((int) $i['id'], $manage && $this->request->query('removed') === '1'),
            'showRemoved' => $manage && $this->request->query('removed') === '1',
            'invitations' => $manage ? $repo->openInvitations((int) $i['id']) : [],
            'manage' => $manage,
        ]);
    }

    public function invite(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'members.manage');
        try {
            $sent = $this->s->institutions()->invite($i, $m, $this->auth->user(), (string) $this->request->input('email', ''), (string) $this->request->input('role', ''));
            $email = (string) $this->request->input('email', '');
            if ($sent['emailed']) {
                $this->flash('success', 'Invitation emailed to ' . $email . '.');
            } else {
                $this->flash('info', 'Invitation made, but email is not set up, so it was not sent. Give ' . $email . ' this link yourself: '
                    . ($this->s->mailer()->link($sent['path']) ?? $sent['path']));
            }
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/members');
    }

    public function revoke(string $ref, string $id): Response
    {
        [$i] = $this->tenant($ref, 'members.manage');
        try {
            $this->s->institutions()->revoke($i, ctype_digit($id) ? (int) $id : 0);
            $this->flash('success', 'Invitation withdrawn. Its link no longer works.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/members');
    }

    public function memberRole(string $ref, string $member): Response
    {
        [$i, $m] = $this->tenant($ref, 'members.manage');
        $target = $this->s->institutions()->repo()->member((int) $i['id'], $member) ?? throw HttpException::notFound('Member');
        $posted = $this->request->post['permissions'] ?? [];
        try {
            $this->s->institutions()->changeRole($i, $m, $target, (string) $this->request->input('role', ''), is_array($posted) ? $posted : []);
            $this->flash('success', 'Saved for ' . $this->name($target) . '.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/members');
    }

    public function memberRemove(string $ref, string $member): Response
    {
        [$i, $m] = $this->tenant($ref, 'members.manage');
        $target = $this->s->institutions()->repo()->member((int) $i['id'], $member) ?? throw HttpException::notFound('Member');
        try {
            $this->s->institutions()->removeMember($i, $m, $target);
            $this->flash('success', $this->name($target) . ' no longer belongs to ' . $i['name'] . '. What they did here stays in its history.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/members');
    }

    public function leave(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, null, false);
        try {
            $this->s->institutions()->leave($i, $m);
            $this->flash('success', 'You left ' . $i['name'] . '.');
            return $this->redirect('/institutions');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/institutions/' . $i['uuid']);
        }
    }

    public function activity(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'activity.view');
        return $this->render('institutions/activity', $this->pageVars($i, $m, 'activity') + ['rows' => $this->activityRows($i, 500)]);
    }

    /** @param array<string,mixed> $i @return list<array<string,mixed>> */
    private function activityRows(array $i, int $limit): array
    {
        $rows = $this->s->audit()->forSubject('institution', (string) $i['uuid'], $limit);
        $names = [];
        foreach ($this->s->institutions()->repo()->members((int) $i['id'], true) as $p) {
            $names[(int) $p['user_id']] = $this->name($p);
        }
        return array_map(static fn(array $r): array => $r + AuditText::describe($r, $names) + ['who' => AuditText::who($r, $names)], $rows);
    }

    /** @return array<string,?string> */
    private function profileInput(): array
    {
        $in = [];
        foreach (InstitutionRepository::PROFILE as $f) {
            $in[$f] = $this->request->input($f);
        }
        return $in;
    }

}
