<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\ClientIp;
use RuntimeException;

/**
 * The public pages: pricing and contact (the home page is "/" for someone signed out, in
 * DashboardController). Off until an
 * administrator turns them on under Settings, Public site; while off, "/" goes to the sign-in
 * page as before and the other two are not found.
 */
final class SiteController extends Controller
{
    public function pricing(): Response
    {
        $this->assertOn();
        return $this->render('site/pricing', $this->vars('Pricing', 'pricing') + ['plans' => $this->s->plans()->listed()]);
    }

    public function contact(): Response
    {
        $this->assertOn();
        return $this->render('site/contact', $this->vars('Contact', 'contact') + ['old' => [], 'sent' => $this->request->query('sent') === '1']);
    }

    public function send(): Response
    {
        $this->assertOn();
        $in = ['name' => $this->request->input('name', ''), 'email' => $this->request->input('email', ''), 'organisation' => $this->request->input('organisation', ''),
            'phone' => $this->request->input('phone', ''), 'message' => $this->request->input('message', '')];
        // A field people cannot see: only a script fills it in.
        if (trim((string) $this->request->input('website', '')) !== '') {
            return $this->redirect('/contact', ['sent' => '1']);
        }
        try {
            $this->s->publicSite()->contact($in, ClientIp::of($this->request->server, $this->app->config()));
        } catch (RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
            return $this->render('site/contact', $this->vars('Contact', 'contact') + ['old' => $in, 'sent' => false], 422);
        }
        return $this->redirect('/contact', ['sent' => '1']);
    }

    private function assertOn(): void
    {
        if (!$this->s->publicSite()->on()) {
            throw HttpException::notFound();
        }
    }

    /** @return array<string,mixed> */
    private function vars(string $title, string $page): array
    {
        return ['title' => $title, 'site' => $this->s->publicSite()->settings(), 'sitePage' => $page, 'indexable' => true, 'wide' => true];
    }
}
