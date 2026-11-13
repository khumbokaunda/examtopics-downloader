<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\View;
use App\Services\Access;
use App\Services\Editions;
use App\Services\Readiness;
use RuntimeException;

/**
 * Readiness certificates: earning one on a finished mock exam, the holder's own certificates,
 * printing and downloading them, the public verification page, and the platform's list where a
 * certificate can be revoked (never deleted).
 */
final class CertificateController extends Controller
{
    public function issue(string $uuid): Response
    {
        $mock = $this->s->mocks()->find($uuid);
        $user = $this->auth->user();
        if ($mock === null || (int) $mock['user_id'] !== (int) $user['id'] || $mock['finished_at'] === null) {
            throw HttpException::notFound('Mock exam');
        }
        $exam = $this->app->db()->one('SELECT e.*, p.name AS provider_name, p.slug AS provider_slug FROM exams e JOIN providers p ON p.id = e.provider_id WHERE e.id = ?',
            [(int) $mock['exam_id']]) ?? throw HttpException::notFound('Exam');
        try {
            $cert = $this->s->readiness()->issue($mock, $this->s->mocks()->history((int) $user['id'], (int) $mock['exam_id'], 50), $user, $exam,
                provider_name($exam, 'provider_name', 'provider_slug') . ' ' . Editions::title($exam));
            $this->flash('success', 'Your certificate is ready. Anyone can check it at the link printed on it.');
            return $this->redirect('/certificates/' . $cert['uuid']);
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/mock/' . $uuid);
        }
    }

    public function index(): Response
    {
        return $this->render('certificates/index', ['title' => 'My certificates', 'nav' => 'certificates',
            'rows' => $this->s->readiness()->mine((int) $this->auth->user()['id']), 'credentials' => $this->s->credentials()->heldBy((int) $this->auth->user()['id'])]);
    }

    public function show(string $uuid): Response
    {
        $cert = $this->find($uuid);
        // Certificates earned since attempts record where their questions came from were also
        // measured against the random-draw conditions; earlier ones were not.
        $drawn = $cert['mock_id'] !== null
            && $this->app->db()->value('SELECT source FROM mock_exams WHERE id = ?', [(int) $cert['mock_id']]) !== null;
        return $this->render('certificates/show', ['title' => 'Certificate ' . $cert['number'], 'nav' => 'certificates', 'cert' => $cert,
            'verifyUrl' => $this->verifyUrl((string) $cert['token']), 'drawn' => $drawn]);
    }

    public function pdf(string $uuid): Response
    {
        $cert = $this->find($uuid);
        $view = new View($this->app, $this->session, $this->auth->user());
        $url = $this->verifyUrl((string) $cert['token']);
        $cover = $this->s->branding()->pdfCover('');
        $html = $view->render('certificates/pdf', ['cert' => $cert, 'verifyUrl' => $url, 'brand' => $cover['name'],
            'logo' => \App\Services\CertificateArt::logo($cover['logo']), 'qr' => \App\Services\CertificateArt::qr($url)]
            + InstitutionCredentialController::art($this), null);
        return new Response(\App\Services\Results::pdf($html, $this->app->paths()->storage() . '/tmp/pdf'), 200,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="certificate-' . $cert['number'] . '.pdf"']);
    }

    /** Public: anyone with the link sees whether the certificate is genuine and current, and nothing private. */
    public function verify(string $token): Response
    {
        $cert = $this->s->readiness()->byToken($token);
        $credential = $cert === null ? $this->s->credentials()->byToken($token) : null;
        if ($credential !== null) {
            return $this->render('credentials/verify', ['title' => 'Verify a credential', 'c' => $credential,
                'authentic' => $this->s->credentials()->authentic($credential), 'state' => \App\Services\Credentials::state($credential)]);
        }
        return $this->render('certificates/verify', ['title' => 'Verify a certificate', 'cert' => $cert, 'brand' => $this->s->branding()->get()['name']],
            $cert === null ? 404 : 200);
    }

    /** Public: the keys that sign credentials, so a copy can be checked offline. */
    public function keys(): Response
    {
        return Response::json(['algorithm' => 'Ed25519', 'signs' => 'the SHA-256 fingerprint (hex) of the credential fields', 'keys' => $this->s->credentials()->signer()->publicKeys()]);
    }

    /** The holder's own copy of an institutional credential. */
    public function credentialPdf(string $uuid): Response
    {
        $c = \App\Core\Ids::isUuid($uuid) ? $this->app->db()->one("SELECT c.* FROM credentials c JOIN institution_memberships m ON m.id = c.membership_id
                                                                     WHERE c.uuid = ? AND m.user_id = ? AND c.status IN ('issued', 'revoked', 'replaced')", [$uuid, (int) $this->auth->user()['id']]) : null;
        if ($c === null) {
            throw HttpException::notFound('Credential');
        }
        return InstitutionCredentialController::document($this, (array) $this->s->institutions()->repo()->find((int) $c['institution_id']), $c);
    }

    public function platform(): Response
    {
        $q = mb_substr(trim((string) $this->request->query('q', '')), 0, 100);
        return $this->render('platform/certificates', ['title' => 'Certificates', 'nav' => 'platform-certificates', 'rows' => $this->s->readiness()->all($q), 'q' => $q]);
    }

    public function revoke(string $uuid): Response
    {
        $cert = $this->s->readiness()->byUuid($uuid) ?? throw HttpException::notFound('Certificate');
        try {
            $this->s->readiness()->revoke($cert, (string) $this->request->input('reason', ''), $this->auth->user());
            $this->flash('success', 'Certificate ' . $cert['number'] . ' is revoked. Its verification page now says so.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/platform/certificates');
    }

    /** @return array<string,mixed> the person's own certificate (or any, for certificates.manage) */
    private function find(string $uuid): array
    {
        $cert = $this->s->readiness()->byUuid($uuid);
        $user = $this->auth->user();
        if ($cert === null || ((int) $cert['user_id'] !== (int) $user['id'] && !Access::can($user, 'certificates.manage'))) {
            throw HttpException::notFound('Certificate');
        }
        return $cert;
    }

    /** @param array<string,mixed> $cert */
}
