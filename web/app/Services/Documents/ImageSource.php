<?php

declare(strict_types=1);

namespace App\Services\Documents;

/** Supplies images for a PDF from local storage. dompdf itself never makes network requests. */
interface ImageSource
{
    /** A data: URI for the image at this address, or null if no saved copy can be embedded. */
    public function dataUri(string $url): ?string;
}
