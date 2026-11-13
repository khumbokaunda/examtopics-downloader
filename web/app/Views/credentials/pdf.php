<?php
/** A credential for PDF: the shared certificate page, filled in. For dompdf only.
 *  @var array $credential @var array $institution @var string|null $logo @var string $qr @var string $url
 *  @var array{page:string,seal:string} $art @var string $primary @var string|null $footer the template's small print */
$c = $credential;
$date = static fn(string $d): string => gmdate('j F Y', (int) strtotime($d . ' UTC'));
$lines = [[(string) $c['achievement'], false]];
$of = array_values(array_filter([(string) $c['programme_name'], (string) $c['assessment_title']], static fn(string $s): bool => $s !== ''));
if ($of !== []) {
    $lines[] = [implode(' · ', $of), true];
}
$details = [['Achieved', $date((string) $c['achieved_on'])], ['Issued', $date(substr((string) $c['issued_at'], 0, 10))]];
if ($c['expires_on'] !== null) {
    $details[] = ['Valid until', $date((string) $c['expires_on'])];
}
$details[] = ['Certificate', (string) $c['number']];
$by = trim((string) $c['signatory_name']) !== '' ? 'Awarded by ' . $c['signatory_name'] . (trim((string) $c['signatory_position']) !== '' ? ', ' . $c['signatory_position'] : '') : null;
$note = trim(implode(' ', array_filter([(string) ($footer ?? ''), $c['fingerprint'] !== null ? 'Fingerprint ' . substr((string) $c['fingerprint'], 0, 16) . '.' : ''])));
echo \App\Core\View::current()->partial('certificates/_page', [
    'art' => $art, 'primary' => $primary, 'logo' => $logo,
    'issuer' => (string) ($c['issuer_name'] ?? $institution['name']),
    'title' => (string) $c['title'], 'name' => (string) $c['holder_name'], 'lead' => 'This certificate is proudly presented to',
    'lines' => $lines, 'awardedBy' => $by, 'details' => $details, 'qr' => $qr, 'verify' => $url, 'note' => $note,
    'stamp' => $c['status'] === 'issued' ? null : ($c['status'] === 'revoked' ? 'REVOKED' : 'REPLACED'),
]);
