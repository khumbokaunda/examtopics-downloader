<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use RuntimeException;

/**
 * An invitation to join an institution, opened from its emailed link. The page itself is public,
 * so someone without an account can see what they are invited to and how to get one; accepting
 * needs a signed-in account with the invited email address.
 */
final class InvitationController extends Controller
{
    public function show(string $token): Response
    {
        $inv = $this->find($token);
        $this->s->institutions()->repo()->markInvitationViewed((int) $inv['id']);
        $user = $this->auth->check() ? $this->auth->user() : null;
        return $this->render('invitations/show', ['title' => 'Invitation', 'nav' => '', 'invitation' => $inv, 'token' => $token,
            'refusal' => $this->s->institutions()->refusal($inv, $user), 'signedIn' => $user !== null,
            'canRegister' => $this->s->registration()->mode() !== 'closed']);
    }

    public function accept(string $token): Response
    {
        $inv = $this->find($token);
        try {
            if ($inv['role'] === \App\Services\InstitutionAccess::STUDENT && $this->request->input('notice') !== '1') {
                throw new RuntimeException('Read what joining means and tick the box to go on.');
            }
            $this->s->institutions()->accept($inv, $this->auth->user(), $this->s->policies(), $this->request->ip());
            $this->flash('success', 'Welcome to ' . $inv['institution_name'] . '.');
            return $this->redirect('/institutions/' . $inv['institution_uuid']);
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/invitations/' . $token);
        }
    }

    public function decline(string $token): Response
    {
        $inv = $this->find($token);
        try {
            $this->s->institutions()->decline($inv, $this->auth->user());
            $this->flash('info', 'Invitation declined.');
            return $this->redirect('/');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/invitations/' . $token);
        }
    }

    /** @return array<string,mixed> */
    private function find(string $token): array
    {
        if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
            throw HttpException::notFound('Invitation');
        }
        return $this->s->institutions()->repo()->invitationByToken(hash('sha256', $token)) ?? throw HttpException::notFound('Invitation');
    }
}
