<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Institutions;
use App\Services\Onboarding;
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
            'documents' => $this->s->onboarding()->documents((int) $i['id']), 'acceptances' => $this->s->policies()->acceptances('institution', (int) $i['id']),
            'missing' => $this->s->onboarding()->missing($i),
            'memberCount' => array_sum($repo->roleCounts((int) $i['id']))]);
    }

    public function document(string $ref, string $document): Response
    {
        $i = $this->s->institutions()->repo()->findByUuid($ref) ?? throw HttpException::notFound('Institution');
        return InstitutionApplicationController::serve($this->s->onboarding(), $i, $document);
    }

    /** Record, or clear, that the institution is an authorised awarding body (only then may it issue formal qualifications). */
    public function awarding(string $ref): Response
    {
        $i = $this->s->institutions()->repo()->findByUuid($ref) ?? throw HttpException::notFound('Institution');
        $body = mb_substr(trim((string) $this->request->input('awarding_body', '')), 0, 160);
        $this->app->db()->run('UPDATE institutions SET awarding_body = ? WHERE id = ?', [$body !== '' ? $body : null, (int) $i['id']]);
        $this->s->audit()->record('institution.awarding_body_set', ['institution' => $i['name'], 'body' => $body], 'institution', (string) $i['uuid']);
        $this->flash('success', $body !== '' ? $i['name'] . ' may issue formal qualifications of ' . $body . '.' : $i['name'] . ' may not issue formal qualifications.');
        return $this->redirect('/platform/institutions/' . $i['uuid']);
    }

    public function decide(string $ref): Response
    {
        $i = $this->s->institutions()->repo()->findByUuid($ref) ?? throw HttpException::notFound('Institution');
        try {
            $decision = (string) $this->request->input('decision', '');
            $note = (string) $this->request->input('note', '');
            $evidence = (string) $this->request->input('evidence', '');
            $changed = 0;
            foreach (array_keys(Onboarding::DIMENSIONS) as $dim) {
                $state = (string) $this->request->input($dim, '');
                if ($state !== '') {
                    $changed += $this->s->onboarding()->setDimension($i, $dim, $state, $decision === 'dimensions' ? $note : '', $evidence, $this->auth->user()) ? 1 : 0;
                    $i = (array) $this->s->institutions()->repo()->findByUuid($ref);
                }
            }
            if ($decision === 'dimensions') {
                $this->flash('success', $changed === 0 ? 'Nothing changed.' : 'Verification saved.');
            } else {
                $this->s->institutions()->decide($i, $decision, $note, $evidence, $this->auth->user());
                $this->flash('success', $i['name'] . ': ' . strtolower(Institutions::STATUSES[Institutions::DECISIONS[$decision]['to']]) . '. Its owners are told.');
            }
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/platform/institutions/' . $i['uuid']);
    }
}
