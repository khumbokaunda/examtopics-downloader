<?php
/** A readiness certificate for PDF: the shared certificate page, filled in. For dompdf only.
 *  @var array $cert @var string $verifyUrl @var string $brand @var string|null $logo @var string $qr
 *  @var array{page:string,seal:string} $art @var string $primary */
use App\Services\Readiness;

echo \App\Core\View::current()->partial('certificates/_page', [
    'art' => $art, 'primary' => $primary, 'logo' => $logo, 'issuer' => $brand,
    'title' => 'Certificate of Demonstrated Readiness', 'name' => (string) $cert['holder_name'],
    'lead' => 'This certificate is proudly presented to',
    'lines' => [['for meeting the readiness criteria in preparation for', false], [(string) $cert['exam_title'], true],
        [Readiness::fmt((float) $cert['percent']) . '% on a timed assessment of ' . (int) $cert['questions'] . ' questions', false]],
    'awardedBy' => null,
    'details' => [['Issued', gmdate('j F Y', (int) strtotime($cert['issued_at'] . ' UTC'))], ['Score', Readiness::fmt((float) $cert['percent']) . '%'],
        ['Certificate', (string) $cert['number']]],
    'qr' => $qr, 'verify' => $verifyUrl, 'note' => Readiness::DISCLAIMER,
    'stamp' => $cert['status'] === 'revoked' ? 'REVOKED' : null,
]);
