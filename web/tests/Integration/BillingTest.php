<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Billing;
use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** Invoices for a plan, paid by mobile money or bank transfer and confirmed by the operator. */
final class BillingTest extends HttpTestCase
{
    use InstitutionFixtures;

    private function db(string $sql, array $args = []): mixed
    {
        return $this->app->db()->value($sql, $args);
    }

    private function lastFlash(): string
    {
        $f = $this->flashes();
        return (string) (end($f)['message'] ?? '');
    }

    public function testAnInvoiceIsIssuedReportedConfirmedAndPaid(): void
    {
        $this->people();
        $a = $this->institution('olive', 'Lilongwe ICT Academy');
        $b = $this->institution('dan', 'Mzuzu Skills Centre');
        $this->join($a, 'olive', 'bob', 'admin');

        $this->as('admin');
        $this->post('/platform/billing/settings', ['currency' => 'mwk', 'issuer' => "ExamLib\nArea 3, Lilongwe", 'instructions' => "Airtel Money 099 000 0000 (ExamLib)\nNational Bank 100200300"]);
        self::assertSame('MWK', $this->s->billing()->settings()['currency']);
        $this->post('/platform/institutions/' . $a . '/invoices', ['description' => 'Starter plan', 'amount' => '0', 'due_on' => '2026-12-01']);
        self::assertStringContainsString('above zero', $this->lastFlash());
        $this->post('/platform/institutions/' . $a . '/invoices', ['description' => 'Starter plan', 'amount' => '40,000', 'due_on' => '2026-12-01',
            'period_from' => '2026-12-01', 'period_to' => '2026-12-31']);
        $this->post('/platform/institutions/' . $b . '/invoices', ['description' => 'Starter plan', 'amount' => '40000', 'due_on' => '2026-12-01']);
        $year = gmdate('Y');
        self::assertSame(['INV-' . $year . '-0001', 'INV-' . $year . '-0002'], array_column($this->app->db()->all('SELECT number FROM invoices ORDER BY id'), 'number'));
        $inv = (string) $this->db("SELECT uuid FROM invoices WHERE number = ?", ['INV-' . $year . '-0001']);
        $other = (string) $this->db("SELECT uuid FROM invoices WHERE number = ?", ['INV-' . $year . '-0002']);

        // The institution's managers are told and see it with how to pay; a student does not.
        self::assertSame(['olive'], array_column($this->app->db()->all("SELECT u.username FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.link = ?",
            ['/institutions/' . $a . '/billing']), 'username'), 'the owner (who manages the institution), not its other administrators');
        $this->as('olive');
        $page = $this->get('/institutions/' . $a . '/billing')->body;
        self::assertStringContainsString('MWK 40,000.00', $page);
        self::assertStringContainsString('Airtel Money 099 000 0000', $page);
        self::assertSame(404, $this->get('/institutions/' . $a . '/billing/' . $other . '.pdf')->status, 'another institution\'s invoice');
        $pdf = $this->get('/institutions/' . $a . '/billing/' . $inv . '.pdf');
        self::assertSame('application/pdf', $pdf->headers['Content-Type']);
        self::assertStringStartsWith('%PDF', $pdf->body);

        // Paid half by mobile money, reported; the operator confirms it; then the rest.
        $this->post('/institutions/' . $a . '/billing/' . $inv . '/payments', ['amount' => '20000', 'method' => 'mobile_money', 'reference' => '', 'paid_on' => gmdate('Y-m-d')]);
        self::assertStringContainsString('reference', $this->lastFlash());
        $this->post('/institutions/' . $a . '/billing/' . $inv . '/payments', ['amount' => '20000', 'method' => 'mobile_money', 'reference' => 'CI261110.1234.A00001', 'paid_on' => gmdate('Y-m-d')]);
        self::assertSame('reported', $this->db('SELECT status FROM invoice_payments'));
        self::assertSame('issued', $this->db('SELECT status FROM invoices WHERE uuid = ?', [$inv]), 'not paid until confirmed');

        $this->as('admin');
        self::assertStringContainsString('CI261110.1234.A00001', $this->get('/platform/billing')->body);
        $pay = (string) $this->db('SELECT uuid FROM invoice_payments');
        $this->post('/platform/payments/' . $pay, ['decision' => 'reject']);
        self::assertStringContainsString('Say why', $this->lastFlash());
        $this->post('/platform/payments/' . $pay, ['decision' => 'confirm']);
        self::assertSame('confirmed', $this->db('SELECT status FROM invoice_payments WHERE uuid = ?', [$pay]));
        self::assertSame('issued', $this->db('SELECT status FROM invoices WHERE uuid = ?', [$inv]), 'half paid');

        $this->post('/platform/invoices/' . $inv . '/void', ['reason' => 'Wrong amount']);
        self::assertStringContainsString('cannot be made void', $this->lastFlash());
        $this->post('/platform/invoices/' . $inv . '/payments', ['amount' => '20,000', 'method' => 'bank', 'reference' => 'NBM-55821', 'paid_on' => gmdate('Y-m-d')]);
        self::assertSame('paid', $this->db('SELECT status FROM invoices WHERE uuid = ?', [$inv]));
        $this->post('/platform/invoices/' . $inv . '/payments', ['amount' => '1', 'method' => 'cash', 'reference' => 'x', 'paid_on' => gmdate('Y-m-d')]);
        self::assertStringContainsString('paid already', $this->lastFlash());

        // A report the operator cannot find is refused with the reason, and the institution sees it.
        $this->as('dan');
        $this->post('/institutions/' . $b . '/billing/' . $other . '/payments', ['amount' => '40000', 'method' => 'bank', 'reference' => 'FAKE-1', 'paid_on' => gmdate('Y-m-d')]);
        $this->as('admin');
        $this->post('/platform/payments/' . $this->db("SELECT uuid FROM invoice_payments WHERE reference = 'FAKE-1'"), ['decision' => 'reject', 'note' => 'No such deposit']);
        $this->post('/platform/invoices/' . $other . '/void', ['reason' => 'Issued by mistake']);
        self::assertSame('void', $this->db('SELECT status FROM invoices WHERE uuid = ?', [$other]));
        $this->as('dan');
        $page = $this->get('/institutions/' . $b . '/billing')->body;
        self::assertStringContainsString('No such deposit', $page);
        self::assertStringContainsString('Issued by mistake', $page);

        // Rows are never deleted, and every step is in the activity history.
        self::assertSame(3, (int) $this->db('SELECT COUNT(*) FROM invoice_payments'));
        $actions = array_column($this->app->db()->all("SELECT action FROM audit_log WHERE action LIKE 'invoice.%'"), 'action');
        foreach (['invoice.issued', 'invoice.payment_reported', 'invoice.payment_confirmed', 'invoice.payment_recorded', 'invoice.paid', 'invoice.payment_rejected', 'invoice.voided'] as $a2) {
            self::assertContains($a2, $actions);
        }

        // A student or someone outside cannot see billing.
        foreach (['bob', 'carol'] as $who) {
            $this->as($who);
            self::assertContains($this->get('/institutions/' . $a . '/billing')->status, [403, 404], $who);
        }
        self::assertSame(403, $this->get('/platform/billing')->status);
    }

    public function testAmounts(): void
    {
        self::assertSame(40000.0, Billing::amount('40,000'));
        self::assertSame(40000.5, Billing::amount('40 000.50'));
        self::assertNull(Billing::amount('-5'));
        self::assertNull(Billing::amount('forty'));
        self::assertNull(Billing::amount('1.234'));
    }
}
