<?php

declare(strict_types=1);

namespace App\Services\Documents;

/** Supplies images for a PDF from local storage. dompdf itself never makes network requests. */
interface ImageSource
{
    /**
     * The local PNG, JPEG or GIF file for the image at this address, or null if there is no
     * saved copy to embed. dompdf reads the file itself: passing images as files rather than
     * data: URIs keeps a large exam's PDF within memory, since dompdf holds every data: URI
     * in its document tree and again as a key of its image cache.
     */
    public function file(string $url): ?string;
}
