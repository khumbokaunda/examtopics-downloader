<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Institutions;
use RuntimeException;

/**
 * The platform's review of institutions (institutions.manage): every registration with its
 * profile and status, and the decision to verify, not verify or suspend it. Reviewers see how
 * many people belong to an institution and who its owners are (they are the contact), never its
 * members or anything they do there: that needs a membership of the institution itself.
 */
final class PlatformInstitutionController extends Controller
{
    public function index(): Response
    {
        $status = (string) $this->request->query('status', '');
        $status = isset(Institutions::STATUSES[$status]) ? $status : '';
        $repo = $this->s->institutions()->repo();
        return $this->render('platform/institutions', ['title' => 'Institutions', 'nav' => 'platform-institutions',
            'rows' => $repo->all($status), 'status' => $status, 'counts' => $repo->statusCounts()]);
    }

    public function show(string $ref): Response
    {
        $repo = $this->s->institutions()->repo();
        $i = $repo->findByUuid($ref) ?? throw HttpException::notFound('Institution');
        return $this->render('platform/institution', ['title' => $i['name'], 'nav' => 'platform-institutions', 'institution' => $i,
            'reviews' => $repo->reviews((int) $i['id']), 'owners' => $repo->owners((int) $i['id']),
            'memberCount' => array_sum($repo->roleCounts((int) $i['id']))]);
    }

    public function decide(string $ref): Response
    {
        $i = $this->s->institutions()->repo()->findByUuid($ref) ?? throw HttpException::notFound('Institution');
        try {
            $decision = (string) $this->request->input('decision', '');
            $this->s->institutions()->decide($i, $decision, (string) $this->request->input('note', ''), (string) $this->request->input('evidence', ''), $this->auth->user());
            $this->flash('success', $i['name'] . ': ' . strtolower(Institutions::STATUSES[Institutions::DECISIONS[$decision]['to']]) . '. Its owners are told.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/platform/institutions/' . $i['uuid']);
    }
}
