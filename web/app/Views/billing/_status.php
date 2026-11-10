<?php /** One invoice's status chip. @var array $invoice */
$s = (string) $invoice['status'];
echo chip(\App\Services\Billing::STATUSES[$s] ?? $s, ['issued' => ($invoice['due_on'] < gmdate('Y-m-d') ? 'danger' : 'warning'), 'paid' => 'success', 'void' => 'neutral'][$s] ?? 'neutral');
