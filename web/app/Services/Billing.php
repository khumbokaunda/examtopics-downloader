<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use App\Repositories\InstitutionRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use RuntimeException;

/**
 * Paying for a plan, for training providers who pay by mobile money, bank transfer or cash:
 * the operator issues an invoice; the institution pays and reports it with the transaction
 * reference; the operator confirms what arrived. There is no card gateway.
 *
 * An issued invoice is never edited. It becomes paid when confirmed payments cover it, or void
 * with a reason. Payments are kept whatever is decided about them.
 */
final class Billing
{
    public const METHODS = ['mobile_money' => 'Mobile money', 'bank' => 'Bank transfer', 'cash' => 'Cash', 'other' => 'Other'];
    public const STATUSES = ['issued' => 'Unpaid', 'paid' => 'Paid', 'void' => 'Void'];

    public function __construct(private Database $db, private AuditLog $audit, private Notifier $notifier, private UserRepository $users,
        private InstitutionRepository $institutions)
    {
    }

    // Settings: what the invoices say and how to pay ------------------------------------------

    /** @return array{currency:string,issuer:string,instructions:string} */
    public function settings(): array
    {
        $repo = new SettingsRepository($this->db);
        return ['currency' => (string) $repo->get('billing.currency', 'MWK'), 'issuer' => (string) $repo->get('billing.issuer', ''),
            'instructions' => (string) $repo->get('billing.instructions', '')];
    }

    /** @param array<string,mixed> $in */
    public function saveSettings(array $in): void
    {
        $currency = strtoupper(trim((string) ($in['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new RuntimeException('The currency is a three-letter code, such as MWK.');
        }
        $repo = new SettingsRepository($this->db);
        $repo->set('billing.currency', $currency);
        $repo->set('billing.issuer', mb_substr(trim(str_replace("\r\n", "\n", (string) ($in['issuer'] ?? ''))), 0, 500));
        $repo->set('billing.instructions', mb_substr(trim(str_replace("\r\n", "\n", (string) ($in['instructions'] ?? ''))), 0, 1000));
        $this->audit->record('billing.settings_saved', ['currency' => $currency], 'settings', 'billing');
    }

    // Invoices ------------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> with what is confirmed paid */
    public function forInstitution(int $institutionId): array
    {
        return $this->db->all("SELECT v.*, (SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p WHERE p.invoice_id = v.id AND p.status = 'confirmed') AS paid_amount,
                               (SELECT COUNT(*) FROM invoice_payments p WHERE p.invoice_id = v.id AND p.status = 'reported') AS waiting
                               FROM invoices v WHERE v.institution_id = ? ORDER BY v.issued_at DESC, v.id DESC", [$institutionId]);
    }

    /** @return list<array<string,mixed>> every invoice, or only $status, with its institution */
    public function all(?string $status = null): array
    {
        return $this->db->all("SELECT v.*, i.name AS institution_name, i.uuid AS institution_uuid,
                               (SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p WHERE p.invoice_id = v.id AND p.status = 'confirmed') AS paid_amount
                               FROM invoices v JOIN institutions i ON i.id = v.institution_id"
            . ($status !== null ? ' WHERE v.status = ?' : '') . ' ORDER BY v.issued_at DESC, v.id DESC LIMIT 2000', $status !== null ? [$status] : []);
    }

    /** @return array<string,mixed>|null an institution's invoice (null $institutionId: any) */
    public function find(?int $institutionId, string $uuid): ?array
    {
        if (!Ids::isUuid($uuid)) {
            return null;
        }
        return $this->db->one('SELECT v.*, i.name AS institution_name, i.uuid AS institution_uuid, i.address AS institution_address, i.email AS institution_email
                               FROM invoices v JOIN institutions i ON i.id = v.institution_id WHERE v.uuid = ?' . ($institutionId !== null ? ' AND v.institution_id = ?' : ''),
            $institutionId !== null ? [$uuid, $institutionId] : [$uuid]);
    }

    /** @return list<array<string,mixed>> */
    public function payments(int $invoiceId): array
    {
        return $this->db->all('SELECT p.*, r.username AS reporter_username, r.display_name AS reporter_name, d.username AS decider_username, d.display_name AS decider_name
                               FROM invoice_payments p LEFT JOIN users r ON r.id = p.reported_by LEFT JOIN users d ON d.id = p.decided_by
                               WHERE p.invoice_id = ? ORDER BY p.reported_at, p.id', [$invoiceId]);
    }

    /** @return list<array<string,mixed>> payments institutions reported, waiting to be confirmed */
    public function waiting(): array
    {
        return $this->db->all("SELECT p.*, v.number, v.uuid AS invoice_uuid, v.currency, i.name AS institution_name, i.uuid AS institution_uuid
                               FROM invoice_payments p JOIN invoices v ON v.id = p.invoice_id JOIN institutions i ON i.id = p.institution_id
                               WHERE p.status = 'reported' ORDER BY p.reported_at");
    }

    /** @return array<string,mixed>|null */
    public function payment(string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM invoice_payments WHERE uuid = ?', [$uuid]) : null;
    }

    /**
     * Issue an invoice to an institution.
     *
     * @param array<string,mixed> $institution @param array<string,mixed> $in @param array<string,mixed> $actor
     * @return array<string,mixed> the invoice
     */
    public function issue(array $institution, array $in, array $actor): array
    {
        $description = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['description'] ?? '')));
        $amount = self::amount((string) ($in['amount'] ?? ''));
        $due = self::date((string) ($in['due_on'] ?? ''));
        $from = trim((string) ($in['period_from'] ?? '')) !== '' ? self::date((string) $in['period_from']) : null;
        $to = trim((string) ($in['period_to'] ?? '')) !== '' ? self::date((string) $in['period_to']) : null;
        if ($description === '' || mb_strlen($description) > 255) {
            throw new RuntimeException('Say what the invoice is for, in up to 255 characters.');
        }
        if ($amount === null || $amount <= 0) {
            throw new RuntimeException('Give an amount above zero.');
        }
        if ($due === null) {
            throw new RuntimeException('Give the date payment is due.');
        }
        if (($from === null) !== ($to === null) || ($from !== null && $to < $from)) {
            throw new RuntimeException('Give both ends of the period, the second on or after the first.');
        }
        $uuid = Ids::uuid();
        $currency = $this->settings()['currency'];
        $this->db->transaction(function (Database $db) use ($institution, $description, $amount, $due, $from, $to, $uuid, $currency, $actor): void {
            $year = gmdate('Y');
            $last = (string) ($db->value("SELECT number FROM invoices WHERE number LIKE ? ORDER BY number DESC LIMIT 1 FOR UPDATE", ['INV-' . $year . '-%']) ?? '');
            $next = $last !== '' ? (int) substr($last, -4) + 1 : 1;
            $db->run('INSERT INTO invoices (uuid, number, institution_id, plan_id, description, currency, amount, period_from, period_to, due_on, issued_by, issued_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$uuid, sprintf('INV-%s-%04d', $year, $next), (int) $institution['id'], $institution['plan_id'] ?? null, $description, $currency, number_format($amount, 2, '.', ''),
                    $from, $to, $due, (int) ($actor['id'] ?? 0) ?: null, Database::now()]);
        });
        $invoice = (array) $this->find((int) $institution['id'], $uuid);
        $this->audit->record('invoice.issued', ['institution' => $institution['name'], 'number' => $invoice['number'], 'amount' => self::money($invoice)], 'institution', (string) $institution['uuid']);
        $this->tellInstitution($institution, 'Invoice ' . $invoice['number'] . ': ' . self::money($invoice), $description . '. Due ' . $due . '.', $invoice);
        return $invoice;
    }

    /** @param array<string,mixed> $invoice @param array<string,mixed> $actor */
    public function void(array $invoice, string $reason, array $actor): void
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        if ($reason === '') {
            throw new RuntimeException('Say why the invoice is void.');
        }
        if ($invoice['status'] !== 'issued') {
            throw new RuntimeException('Only an unpaid invoice can be made void.');
        }
        if ((float) $this->db->value("SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = ? AND status = 'confirmed'", [(int) $invoice['id']]) > 0) {
            throw new RuntimeException('Money has been confirmed against this invoice, so it cannot be made void.');
        }
        $this->db->run("UPDATE invoices SET status = 'void', void_reason = ?, voided_by = ?, voided_at = ? WHERE id = ? AND status = 'issued'",
            [mb_substr($reason, 0, 255), (int) $actor['id'], Database::now(), (int) $invoice['id']]);
        $this->audit->record('invoice.voided', ['institution' => $invoice['institution_name'], 'number' => $invoice['number'], 'reason' => $reason], 'institution', (string) $invoice['institution_uuid']);
    }

    // Payments ------------------------------------------------------------------------------------

    /**
     * A payment against an invoice. Reported by the institution, it waits to be confirmed; recorded
     * by the operator ($confirmed), it counts at once.
     *
     * @param array<string,mixed> $invoice @param array<string,mixed> $in @param array<string,mixed> $actor
     */
    public function pay(array $invoice, array $in, array $actor, bool $confirmed): void
    {
        if ($invoice['status'] !== 'issued') {
            throw new RuntimeException('This invoice is ' . strtolower(self::STATUSES[$invoice['status']] ?? $invoice['status']) . ' already.');
        }
        $amount = self::amount((string) ($in['amount'] ?? ''));
        $method = (string) ($in['method'] ?? '');
        $reference = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['reference'] ?? '')));
        $paidOn = self::date((string) ($in['paid_on'] ?? ''));
        $note = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['note'] ?? '')));
        if ($amount === null || $amount <= 0) {
            throw new RuntimeException('Give the amount paid.');
        }
        if (!isset(self::METHODS[$method])) {
            throw new RuntimeException('Choose how it was paid.');
        }
        if ($reference === '' || mb_strlen($reference) > 80) {
            throw new RuntimeException('Give the transaction reference or deposit slip number.');
        }
        if ($paidOn === null || $paidOn > gmdate('Y-m-d', time() + 86400)) {
            throw new RuntimeException('Give the date it was paid.');
        }
        $uuid = Ids::uuid();
        $now = Database::now();
        $this->db->run('INSERT INTO invoice_payments (uuid, institution_id, invoice_id, amount, method, reference, paid_on, note, status, reported_by, reported_at, decided_by, decided_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uuid, (int) $invoice['institution_id'], (int) $invoice['id'], number_format($amount, 2, '.', ''), $method, $reference, $paidOn, $note !== '' ? mb_substr($note, 0, 255) : null,
                $confirmed ? 'confirmed' : 'reported', (int) $actor['id'], $now, $confirmed ? (int) $actor['id'] : null, $confirmed ? $now : null]);
        $money = $invoice['currency'] . ' ' . number_format($amount, 2);
        $this->audit->record($confirmed ? 'invoice.payment_recorded' : 'invoice.payment_reported',
            ['institution' => $invoice['institution_name'], 'number' => $invoice['number'], 'amount' => $money, 'method' => self::METHODS[$method], 'reference' => $reference],
            'institution', (string) $invoice['institution_uuid']);
        if ($confirmed) {
            $this->settle($invoice);
        } else {
            $this->notifier->notifyPermission('institutions.manage', 'institution', $invoice['institution_name'] . ' reported a payment',
                $money . ' for ' . $invoice['number'] . ' by ' . self::METHODS[$method] . ', reference ' . $reference . '.', '/platform/billing', 'payment:' . $uuid, 'payment:' . $uuid);
        }
    }

    /** Confirm a reported payment, or reject it with a reason. @param array<string,mixed> $payment @param array<string,mixed> $actor */
    public function decide(array $payment, bool $confirm, string $note, array $actor): void
    {
        if ($payment['status'] !== 'reported') {
            throw new RuntimeException('That payment is decided already.');
        }
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));
        if (!$confirm && $note === '') {
            throw new RuntimeException('Say why it is not confirmed (for example: no such transaction).');
        }
        $this->db->run("UPDATE invoice_payments SET status = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ? AND status = 'reported'",
            [$confirm ? 'confirmed' : 'rejected', (int) $actor['id'], Database::now(), $note !== '' ? mb_substr($note, 0, 255) : null, (int) $payment['id']]);
        $this->notifier->resolve('payment:' . $payment['uuid']);
        $invoice = (array) $this->db->one('SELECT v.*, i.name AS institution_name, i.uuid AS institution_uuid FROM invoices v JOIN institutions i ON i.id = v.institution_id WHERE v.id = ?',
            [(int) $payment['invoice_id']]);
        $money = $invoice['currency'] . ' ' . number_format((float) $payment['amount'], 2);
        $this->audit->record($confirm ? 'invoice.payment_confirmed' : 'invoice.payment_rejected',
            ['institution' => $invoice['institution_name'], 'number' => $invoice['number'], 'amount' => $money, 'reference' => $payment['reference'], 'note' => $note !== '' ? $note : null],
            'institution', (string) $invoice['institution_uuid']);
        $institution = (array) $this->institutions->find((int) $invoice['institution_id']);
        if ($confirm) {
            if (!$this->settle($invoice)) {
                $this->tellInstitution($institution, 'Payment received for ' . $invoice['number'], $money . ' is confirmed. The rest is still due.', $invoice);
            }
        } else {
            $this->tellInstitution($institution, 'Payment for ' . $invoice['number'] . ' not confirmed', $money . ', reference ' . $payment['reference'] . ': ' . $note, $invoice);
        }
    }

    /** Mark the invoice paid when confirmed payments cover it. @param array<string,mixed> $invoice @return bool whether it is paid now */
    private function settle(array $invoice): bool
    {
        $paid = (float) $this->db->value("SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = ? AND status = 'confirmed'", [(int) $invoice['id']]);
        if ($paid + 0.005 < (float) $invoice['amount']) {
            return false;
        }
        $this->db->run("UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ? AND status = 'issued'", [Database::now(), (int) $invoice['id']]);
        $this->audit->record('invoice.paid', ['institution' => $invoice['institution_name'], 'number' => $invoice['number']], 'institution', (string) $invoice['institution_uuid']);
        $this->tellInstitution((array) $this->institutions->find((int) $invoice['institution_id']), 'Invoice ' . $invoice['number'] . ' is paid', 'Thank you. The receipt is on the billing page.', $invoice);
        return true;
    }

    /** Tell the people who manage the institution. @param array<string,mixed> $institution @param array<string,mixed> $invoice */
    private function tellInstitution(array $institution, string $title, string $body, array $invoice): void
    {
        foreach ($this->institutions->members((int) $institution['id']) as $m) {
            if (InstitutionAccess::can($m, 'institution.manage') && ($user = $this->users->find((int) $m['user_id'])) !== null && $user['status'] === 'active') {
                $this->notifier->notify($user, 'institution', $title, $body, '/institutions/' . $institution['uuid'] . '/billing', null,
                    'invoice:' . $invoice['uuid'] . ':' . md5($title));
            }
        }
    }

    // Helpers -------------------------------------------------------------------------------------

    /** @param array<string,mixed> $invoice */
    public static function money(array $invoice, ?string $amount = null): string
    {
        return $invoice['currency'] . ' ' . number_format((float) ($amount ?? $invoice['amount']), 2);
    }

    /** "40,000", "40000.50" and "40 000" are all amounts. */
    public static function amount(string $text): ?float
    {
        $clean = str_replace([',', ' ', "\u{00A0}"], '', trim($text));
        return preg_match('/^\d{1,10}(\.\d{1,2})?$/', $clean) === 1 ? (float) $clean : null;
    }

    private static function date(string $text): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($text));
        return $d !== false && $d->format('Y-m-d') === trim($text) ? $d->format('Y-m-d') : null;
    }
}
