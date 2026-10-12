<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;

/** Saved question images, addressed by content hash. Signed-in users only, like every page. */
final class MediaController extends Controller
{
    public function show(string $hash): Response
    {
        $file = $this->s->media()->file($hash) ?? throw HttpException::notFound('Image');
        $r = new Response('', 200, [
            'Content-Type' => $file['mime'],
            'Content-Length' => (string) $file['bytes'],
            'Content-Disposition' => 'inline; filename="' . $hash . '"',
            'X-Content-Type-Options' => 'nosniff',
            // The address names the content, so it never changes.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            // Opened on its own, an image response may not run or load anything.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
        $r->file = $file['path'];
        return $r;
    }
}
