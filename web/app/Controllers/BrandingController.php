<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Branding;

/** Settings > Branding, and the public logo and colour files the sign-in page needs too. */
final class BrandingController extends Controller
{
    public function index(): Response
    {
        return $this->render('settings/branding', [
            'title' => 'Branding',
            'nav' => 'settings',
            'brand' => $this->s->branding()->get(),
            'logoUrl' => $this->s->branding()->logoUrl(),
        ]);
    }

    public function save(): Response
    {
        $branding = $this->s->branding();
        $error = $branding->save([
            'name' => $this->request->input('name', ''),
            'tagline' => $this->request->input('tagline', ''),
            'accent' => $this->request->input('accent', 'teal'),
            'color' => $this->request->input('color', ''),
            'theme' => $this->request->input('theme', 'system'),
        ]);
        $upload = $this->request->file('logo');
        if ($error === null && $upload !== null) {
            $error = $branding->storeLogo($upload);
        }
        $user = (array) $this->auth->user();
        if ($error !== null) {
            $this->flash('danger', $error);
        } else {
            $b = $branding->get();
            $this->app->logger()->info('security', 'Branding updated', [
                'username' => $user['username'], 'accent' => $b['accent'], 'color' => $b['color'], 'logo' => $upload !== null,
            ]);
            $this->flash('success', 'Branding saved.');
        }
        return $this->redirect('/settings/branding');
    }

    public function removeLogo(): Response
    {
        $this->s->branding()->removeLogo();
        $this->app->logger()->info('security', 'Branding logo removed', ['username' => $this->auth->user()['username'] ?? null]);
        $this->flash('success', 'Logo removed. The name stands alone.');
        return $this->redirect('/settings/branding');
    }

    /** A logo file. Public, because the sign-in page shows it. */
    public function file(string $file): Response
    {
        $branding = $this->s->branding();
        if (!Branding::validFile($file) || !is_file($branding->path($file))) {
            throw HttpException::notFound('File');
        }
        $path = $branding->path($file);
        $r = new Response('', 200, [
            'Content-Type' => str_ends_with($file, '.svg') ? 'image/svg+xml' : 'image/png',
            'Content-Length' => (string) filesize($path),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
            // Opened on its own, an SVG may not run or load anything.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
        $r->file = $path;
        return $r;
    }

    /** The custom colour's stylesheet (the CSP allows no inline styles). */
    public function css(): Response
    {
        return new Response($this->s->branding()->css(), 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
