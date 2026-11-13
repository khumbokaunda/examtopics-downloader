<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\View;
use App\Services\Credentials;
use App\Services\InstitutionAccess;
use RuntimeException;

/**
 * An institution's credentials: templates (credentials.manage), asking for credentials for
 * eligible students (credentials.issue), approving them (the category's signatories), the
 * register of what was issued, revoking and correcting, signatories (owners) and the logo.
 */
final class InstitutionCredentialController extends TenantController
{
    public function index(string $ref): Response
    {
        [$i, $m] = $this->access($ref);
        $svc = $this->s->credentials();
        $iid = (int) $i['id'];
        $status = (string) $this->request->query('status', '');
        $status = isset(Credentials::STATUSES[$status]) ? $status : '';
        return $this->render('credentials/institution', $this->pageVars($i, $m, 'credentials') + [
            'templates' => $svc->templates($iid), 'credentials' => $svc->all($iid, $status), 'status' => $status,
            'pending' => array_values(array_filter($svc->all($iid, 'pending'), fn(array $c): bool => $svc->signatoryFor($iid, (int) $m['id'], (string) $c['category']) !== null)),
            'signatories' => $svc->signatories($iid), 'staff' => array_values(array_filter($this->s->institutions()->repo()->members($iid),
                static fn(array $p): bool => $p['role'] !== InstitutionAccess::STUDENT)),
            'canManage' => InstitutionAccess::can($m, 'credentials.manage'), 'canIssue' => InstitutionAccess::can($m, 'credentials.issue'),
            'isOwner' => $m['role'] === InstitutionAccess::OWNER, 'categories' => Credentials::categoriesFor($i),
        ]);
    }

    public function templateForm(string $ref, ?string $template = null): Response
    {
        [$i, $m] = $this->tenant($ref, 'credentials.manage');
        $t = $template !== null ? ($this->s->credentials()->template((int) $i['id'], $template) ?? throw HttpException::notFound('Template')) : null;
        return $this->render('credentials/template_form', $this->pageVars($i, $m, 'credentials') + ['template' => $t, 'categories' => Credentials::categoriesFor($i),
            'assessments' => $this->app->db()->all("SELECT uuid, title FROM assessments WHERE institution_id = ? AND status <> 'draft' ORDER BY title", [(int) $i['id']]),
            'programmes' => $this->s->structure()->repo()->programmes((int) $i['id'])]);
    }

    public function saveTemplate(string $ref, ?string $template = null): Response
    {
        [$i] = $this->tenant($ref, 'credentials.manage');
        $t = $template !== null ? ($this->s->credentials()->template((int) $i['id'], $template) ?? throw HttpException::notFound('Template')) : null;
        $in = [];
        foreach (['category', 'name', 'title', 'body', 'footer', 'rule', 'assessment', 'programme', 'min_percent', 'requires_approval', 'validity_months', 'status'] as $f) {
            $in[$f] = (string) $this->request->input($f, '');
        }
        try {
            $this->s->credentials()->saveTemplate($i, $t, $in, $this->auth->user());
            $this->flash('success', 'Template saved.');
            return $this->redirect('/institutions/' . $i['uuid'] . '/credentials');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/institutions/' . $i['uuid'] . '/credentials/templates/' . ($t['uuid'] ?? 'new'));
        }
    }

    public function eligible(string $ref, string $template): Response
    {
        [$i, $m] = $this->tenant($ref, 'credentials.issue');
        $t = $this->s->credentials()->template((int) $i['id'], $template) ?? throw HttpException::notFound('Template');
        return $this->render('credentials/eligible', $this->pageVars($i, $m, 'credentials') + ['template' => $t, 'rows' => $this->s->credentials()->eligible((int) $i['id'], $t),
            'direct' => (int) $t['requires_approval'] === 0 && $this->s->credentials()->signatoryFor((int) $i['id'], (int) $m['id'], (string) $t['category']) !== null]);
    }

    public function issue(string $ref, string $template): Response
    {
        [$i, $m] = $this->tenant($ref, 'credentials.issue');
        $t = $this->s->credentials()->template((int) $i['id'], $template) ?? throw HttpException::notFound('Template');
        try {
            $members = array_values(array_filter((array) ($this->request->post['members'] ?? []), 'is_string'));
            if ($members === []) {
                throw new RuntimeException('Choose who receives it.');
            }
            $done = $this->s->credentials()->issue($i, $m, $this->auth->user(), $t, $members, (string) $this->request->input('note', ''),
                $this->request->input('allow_duplicate') === '1');
            $this->flash($done['skipped'] === [] ? 'success' : 'warning', sprintf('%d issued, %d waiting for a signatory\'s approval.', $done['issued'], $done['pending'])
                . ($done['skipped'] !== [] ? ' Not issued: ' . implode(' ', $done['skipped']) : ''));
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/credentials/templates/' . $t['uuid'] . '/eligible');
    }

    public function decide(string $ref, string $credential): Response
    {
        [$i, $m] = $this->tenant($ref, null);
        $c = $this->s->credentials()->find((int) $i['id'], $credential) ?? throw HttpException::notFound('Credential');
        $signatory = $this->s->credentials()->signatoryFor((int) $i['id'], (int) $m['id'], (string) $c['category'])
            ?? throw new HttpException(403, 'Only a signatory authorised for this kind of credential can approve it.');
        try {
            if ($this->request->input('decision') === 'approve') {
                $this->s->credentials()->approve($i, $this->auth->user(), $c, $signatory);
                $this->flash('success', $c['number'] . ' is issued.');
            } else {
                $this->s->credentials()->decline($i, $this->auth->user(), $c, (string) $this->request->input('reason', ''));
                $this->flash('success', $c['number'] . ' was declined.');
            }
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/credentials');
    }

    public function show(string $ref, string $credential): Response
    {
        [$i, $m] = $this->access($ref);
        $c = $this->s->credentials()->find((int) $i['id'], $credential) ?? throw HttpException::notFound('Credential');
        return $this->render('credentials/show', $this->pageVars($i, $m, 'credentials') + ['credential' => $c, 'events' => $this->s->credentials()->events((int) $c['id']),
            'canManage' => InstitutionAccess::can($m, 'credentials.manage'),
            'canSign' => $this->s->credentials()->signatoryFor((int) $i['id'], (int) $m['id'], (string) $c['category']) !== null,
            'verifyUrl' => $this->verifyUrl((string) $c['token'])]);
    }

    public function revoke(string $ref, string $credential): Response
    {
        [$i] = $this->tenant($ref, 'credentials.manage');
        $c = $this->s->credentials()->find((int) $i['id'], $credential) ?? throw HttpException::notFound('Credential');
        try {
            $this->s->credentials()->revoke($i, $this->auth->user(), $c, (string) $this->request->input('reason', ''));
            $this->flash('success', $c['number'] . ' is revoked. Its verification page now says so.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/credentials/' . $c['uuid']);
    }

    public function correct(string $ref, string $credential): Response
    {
        [$i, $m] = $this->tenant($ref, 'credentials.manage');
        $c = $this->s->credentials()->find((int) $i['id'], $credential) ?? throw HttpException::notFound('Credential');
        $signatory = $this->s->credentials()->signatoryFor((int) $i['id'], (int) $m['id'], (string) $c['category'])
            ?? throw new HttpException(403, 'A corrected credential is signed again, so it needs a signatory authorised for this kind of credential.');
        try {
            $number = $this->s->credentials()->correct($i, $this->auth->user(), $c, (string) $this->request->input('holder_name', ''), (string) $this->request->input('achievement', ''),
                (string) $this->request->input('reason', ''), $signatory);
            $this->flash('success', 'Issued ' . $number . ' to replace ' . $c['number'] . '. The original stays, marked replaced.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/credentials');
    }

    public function pdf(string $ref, string $credential): Response
    {
        [$i] = $this->access($ref);
        $c = $this->s->credentials()->find((int) $i['id'], $credential) ?? throw HttpException::notFound('Credential');
        if (!in_array($c['status'], ['issued', 'revoked', 'replaced'], true)) {
            throw HttpException::notFound('Credential');
        }
        return self::document($this, $i, $c);
    }

    public function signatory(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, null);
        if ($m['role'] !== InstitutionAccess::OWNER) {
            throw new HttpException(403, 'Only an owner chooses who signs credentials.');
        }
        try {
            $member = $this->s->institutions()->repo()->member((int) $i['id'], (string) $this->request->input('member', '')) ?? throw new RuntimeException('Choose a member of staff.');
            $this->s->credentials()->setSignatory($i, $member, (string) $this->request->input('position', ''),
                array_values(array_filter((array) ($this->request->post['categories'] ?? []), 'is_string')), $this->request->input('active', '1') === '1');
            $this->flash('success', 'Signatory saved.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/credentials');
    }

    public function logo(string $ref): Response
    {
        [$i] = $this->tenant($ref, 'credentials.manage');
        try {
            $file = $this->request->file('logo') ?? throw new RuntimeException('Choose a PNG or JPEG file.');
            if ($file['error'] !== UPLOAD_ERR_OK || !is_file($file['tmp_name']) || filesize($file['tmp_name']) > 1024 * 1024) {
                throw new RuntimeException('Choose a PNG or JPEG file of at most 1 MB.');
            }
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg'][$mime] ?? throw new RuntimeException('Choose a PNG or JPEG file.');
            $dir = $this->app->paths()->storage() . '/institution_documents/' . $i['uuid'];
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException('The logo could not be stored.');
            }
            if (!copy($file['tmp_name'], $dir . '/logo.' . $ext)) {
                throw new RuntimeException('The logo could not be stored.');
            }
            $this->app->db()->run('UPDATE institutions SET logo_file = ? WHERE id = ?', ['logo.' . $ext, (int) $i['id']]);
            $this->s->audit()->record('institution.logo_changed', ['institution' => $i['name']], 'institution', (string) $i['uuid']);
            $this->flash('success', 'Logo saved. It is printed on credentials issued from now on.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/credentials');
    }

    /** A credential as a PDF, with the issuer's logo, the signatory and a QR code to the verification page. @param array<string,mixed> $i @param array<string,mixed> $c */
    public static function document(Controller $ctl, array $i, array $c): Response
    {
        $logo = null;
        if (($i['logo_file'] ?? null) !== null) {
            $path = $ctl->app->paths()->storage() . '/institution_documents/' . $i['uuid'] . '/' . basename((string) $i['logo_file']);
            if (is_file($path)) {
                $logo = 'data:' . (str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg') . ';base64,' . base64_encode((string) file_get_contents($path));
            }
        }
        $url = $ctl->verifyUrl((string) $c['token']);
        $footer = isset($c['template_id']) && $c['template_id'] !== null
            ? $ctl->app->db()->value('SELECT footer FROM credential_templates WHERE id = ?', [(int) $c['template_id']]) : null;
        $html = (new View($ctl->app, $ctl->session, null))->render('credentials/pdf', ['credential' => $c, 'institution' => $i, 'logo' => $logo,
            'qr' => \App\Services\CertificateArt::qr($url), 'url' => $url, 'footer' => $footer !== null ? (string) $footer : null] + self::art($ctl), null);
        return new Response(\App\Services\Results::pdf($html, $ctl->app->paths()->storage() . '/tmp/pdf'), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $c['number']) . '.pdf"']);
    }

    /** The certificate artwork in the site's brand colour. @return array{art:array{page:string,seal:string},primary:string} */
    public static function art(Controller $ctl): array
    {
        $primary = $ctl->s->branding()->pdfCover('')['primary'];
        return ['art' => (new \App\Services\CertificateArt($ctl->app->paths()->storage() . '/tmp/pdf'))->forColour($primary), 'primary' => $primary];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} for anyone who issues, manages or signs credentials */
    private function access(string $ref): array
    {
        [$i, $m] = $this->tenant($ref, null);
        if (!InstitutionAccess::can($m, 'credentials.issue') && !InstitutionAccess::can($m, 'credentials.manage')
            && $this->app->db()->value('SELECT 1 FROM credential_signatories WHERE institution_id = ? AND membership_id = ? AND active = 1', [(int) $i['id'], (int) $m['id']]) === null) {
            throw new HttpException(403, 'Your role in ' . $i['name'] . ' does not include credentials.');
        }
        return [$i, $m];
    }
}
