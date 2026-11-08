<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use RuntimeException;

/**
 * Joining an institution with an enrolment code: type the code, see which institution it is for
 * and what joining means, then join (or ask to, when the code needs approval). The code is
 * carried between the two steps in the session, never in the address.
 */
final class EnrolController extends Controller
{
    private const KEY = '_enrol_code';

    public function show(): Response
    {
        return $this->render('enrol/show', ['title' => 'Join with a code', 'nav' => 'institutions', 'code' => null, 'error' => null]);
    }

    public function lookup(): Response
    {
        try {
            $code = $this->s->enrolment()->lookup((string) $this->request->input('code', ''), $this->auth->user());
        } catch (RuntimeException $e) {
            return $this->render('enrol/show', ['title' => 'Join with a code', 'nav' => 'institutions', 'code' => null, 'error' => $e->getMessage()], 422);
        }
        $this->session->set(self::KEY, ['code' => $code['code'], 'at' => time()]);
        return $this->render('enrol/show', ['title' => 'Join ' . $code['institution_name'], 'nav' => 'institutions', 'code' => $code, 'error' => null]);
    }

    public function join(): Response
    {
        $held = $this->session->get(self::KEY);
        $this->session->remove(self::KEY);
        if (!is_array($held) || (int) ($held['at'] ?? 0) < time() - 1800) {
            $this->flash('warning', 'Enter the code again.');
            return $this->redirect('/enrol');
        }
        try {
            $code = $this->s->enrolment()->lookup((string) $held['code'], $this->auth->user());
            $how = $this->s->enrolment()->useCode($code, $this->auth->user(), $this->request->input('notice') === '1', $this->request->ip());
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/enrol');
        }
        if ($how === 'joined') {
            $this->flash('success', 'Welcome to ' . $code['institution_name'] . '.');
            return $this->redirect('/institutions/' . $code['institution_uuid']);
        }
        $this->flash('success', 'Your request to join ' . $code['institution_name'] . ' is sent. You are told in your inbox when it is decided.');
        return $this->redirect('/institutions');
    }
}
