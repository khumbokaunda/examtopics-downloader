<?php

declare(strict_types=1);

namespace App\Services\Documents\Pdf;

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;

/**
 * dompdf's CPDF canvas, writing through OutlineCpdf so the document can carry bookmarks.
 * Selected with Options::setPdfBackend(PdfCanvas::class).
 */
class PdfCanvas extends CPDF
{
    public function __construct($paper = 'letter', string $orientation = 'portrait', ?Dompdf $dompdf = null)
    {
        parent::__construct($paper, $orientation, $dompdf);
        // Same set-up as the parent, on the subclass.
        $this->_pdf = new OutlineCpdf(
            [0.0, 0.0, (float) $this->_width, (float) $this->_height],
            true,
            $this->_dompdf->getOptions()->getFontCache(),
            $this->_dompdf->getOptions()->getTempDir()
        );
        $this->_pdf->addInfo('Producer', sprintf('%s + CPDF', $this->_dompdf->version));
        $time = substr_replace(date('YmdHisO'), '\'', -2, 0) . '\'';
        $this->_pdf->addInfo('CreationDate', "D:$time");
        $this->_pdf->addInfo('ModDate', "D:$time");
        $this->_pages = [$this->_pdf->getFirstPageId()];
    }

    /** The PDF page object being drawn now, for bookmarks. (_pages holds content streams.) */
    public function currentPageObjectId(): int
    {
        return (int) $this->_pdf->currentPage;
    }

    public function outline(): OutlineCpdf
    {
        /** @var OutlineCpdf */
        return $this->_pdf;
    }
}
