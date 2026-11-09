<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Onboarding;
use App\Services\Policies;
use RuntimeException;

/**
 * An institution's application, for its managers (institution.manage): what it still needs, its
 * documents, the agreement, sending it for review, and the review history so far.
 */
final class InstitutionApplicationController extends TenantController
{
    public function show(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'institution.manage', false);
        $ob = $this->s->onboarding();
        return $this->render('institutions/application', $this->pageVars($i, $m, 'application') + [
            'slots' => $ob->slots($i), 'missing' => $ob->missing($i),
            'accepted' => $this->s->policies()->acceptedAll(Policies::INSTITUTION, 'institution', (int) $i['id']),
            'reviews' => $this->s->institutions()->repo()->reviews((int) $i['id']),
        ]);
    }

    public function upload(string $ref): Response
    {
        [$i] = $this->tenant($ref, 'institution.manage', false);
        try {
            $file = $this->request->file('document') ?? throw new RuntimeException('Choose a file to upload.');
            $this->s->onboarding()->upload($i, (string) $this->request->input('slot', ''), $file, $this->auth->user());
            $this->flash('success', 'Document added.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/application');
    }

    public function remove(string $ref, string $document): Response
    {
        [$i] = $this->tenant($ref, 'institution.manage', false);
        try {
            $this->s->onboarding()->remove($i, $document);
            $this->flash('success', 'Document removed.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/application');
    }

    public function download(string $ref, string $document): Response
    {
        [$i] = $this->tenant($ref, 'institution.manage', false);
        return self::serve($this->s->onboarding(), $i, $document);
    }

    public function submit(string $ref): Response
    {
        [$i] = $this->tenant($ref, 'institution.manage', false);
        try {
            $this->s->onboarding()->submit($i, $this->auth->user(), $this->request->input('accept') === '1', (string) $this->request->input('response', ''), $this->request->ip());
            $this->flash('success', 'Sent for review. You are told in your inbox when it is decided.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/application');
    }

    /** @param array<string,mixed> $i */
    public static function serve(Onboarding $ob, array $i, string $uuid): Response
    {
        $doc = $ob->document((int) $i['id'], $uuid) ?? throw HttpException::notFound('Document');
        $path = $ob->path($doc, $i);
        if (!is_file($path)) {
            throw HttpException::notFound('Document');
        }
        return Response::download($path, (string) $doc['filename'], (string) $doc['mime']);
    }
}
