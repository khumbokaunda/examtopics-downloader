<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\View;
use App\Services\Billing;
use RuntimeException;

/**
 * Invoices and payments. The operator's side (institutions.manage) issues invoices, records and
 * confirms payments and sets the payment details; an institution's side (institution.manage)
 * sees its invoices, downloads them and reports a payment.
 */
final class BillingController extends TenantController
{
    // The operator ----------------------------------------------------------------------------

    public function index(): Response
    {
        $status = (string) $this->request->query('status', 'issued');
        $status = isset(Billing::STATUSES[$status]) ? $status : null;
        return $this->render('platform/billing', ['title' => 'Billing', 'nav' => 'platform-billing', 'status' => $status,
            'invoices' => $this->s->billing()->all($status), 'waiting' => $this->s->billing()->waiting(), 'settings' => $this->s->billing()->settings()]);
    }

    public function saveSettings(): Response
    {
        try {
            $this->s->billing()->saveSettings($this->request->post);
            $this->flash('success', 'Payment details saved.');
        } catch (RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
        }
        return $this->redirect('/platform/billing');
    }

    public function issue(string $ref): Response
    {
        $i = $this->s->institutions()->repo()->findByUuid($ref) ?? throw HttpException::notFound('Institution');
        try {
            $v = $this->s->billing()->issue($i, $this->request->post, (array) $this->auth->user());
            $this->flash('success', 'Invoice ' . $v['number'] . ' issued. The institution is told.');
        } catch (RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
        }
        return $this->redirect('/platform/institutions/' . $i['uuid']);
    }

    public function invoice(string $invoice): Response
    {
        $v = $this->s->billing()->find(null, $invoice) ?? throw HttpException::notFound('Invoice');
        return $this->render('platform/invoice', ['title' => $v['number'], 'nav' => 'platform-billing', 'invoice' => $v,
            'payments' => $this->s->billing()->payments((int) $v['id'])]);
    }

    public function void(string $invoice): Response
    {
        $v = $this->s->billing()->find(null, $invoice) ?? throw HttpException::notFound('Invoice');
        try {
            $this->s->billing()->void($v, (string) $this->request->input('reason', ''), (array) $this->auth->user());
            $this->flash('success', $v['number'] . ' is void.');
        } catch (RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
        }
        return $this->redirect('/platform/invoices/' . $v['uuid']);
    }

    public function record(string $invoice): Response
    {
        $v = $this->s->billing()->find(null, $invoice) ?? throw HttpException::notFound('Invoice');
        try {
            $this->s->billing()->pay($v, $this->request->post, (array) $this->auth->user(), true);
            $this->flash('success', 'Payment recorded.');
        } catch (RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
        }
        return $this->redirect('/platform/invoices/' . $v['uuid']);
    }

    public function decide(string $payment): Response
    {
        $p = $this->s->billing()->payment($payment) ?? throw HttpException::notFound('Payment');
        try {
            $confirm = $this->request->input('decision') === 'confirm';
            $this->s->billing()->decide($p, $confirm, (string) $this->request->input('note', ''), (array) $this->auth->user());
            $this->flash('success', $confirm ? 'Payment confirmed.' : 'Payment not confirmed. The institution is told why.');
        } catch (RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
        }
        return $this->redirect((string) $this->request->input('back') === 'invoice'
            ? '/platform/invoices/' . $this->app->db()->value('SELECT uuid FROM invoices WHERE id = ?', [(int) $p['invoice_id']]) : '/platform/billing');
    }

    public function platformPdf(string $invoice): Response
    {
        return $this->pdf($this->s->billing()->find(null, $invoice) ?? throw HttpException::notFound('Invoice'));
    }

    // The institution ---------------------------------------------------------------------------

    public function mine(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'institution.manage', false);
        $invoices = $this->s->billing()->forInstitution((int) $i['id']);
        $payments = [];
        foreach ($invoices as $v) {
            $payments[(int) $v['id']] = $this->s->billing()->payments((int) $v['id']);
        }
        return $this->render('institutions/billing', $this->pageVars($i, $m, 'billing') + ['invoices' => $invoices, 'payments' => $payments,
            'settings' => $this->s->billing()->settings(), 'plan' => $this->s->plans()->byId($i['plan_id'] !== null ? (int) $i['plan_id'] : null),
            'usage' => $this->s->plans()->usage($i)]);
    }

    public function report(string $ref, string $invoice): Response
    {
        [$i] = $this->tenant($ref, 'institution.manage', false);
        $v = $this->s->billing()->find((int) $i['id'], $invoice) ?? throw HttpException::notFound('Invoice');
        try {
            $this->s->billing()->pay($v, $this->request->post, (array) $this->auth->user(), false);
            $this->flash('success', 'Thank you. The payment is checked and you are told when it is confirmed.');
        } catch (RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/billing');
    }

    public function institutionPdf(string $ref, string $invoice): Response
    {
        [$i] = $this->tenant($ref, 'institution.manage', false);
        return $this->pdf($this->s->billing()->find((int) $i['id'], $invoice) ?? throw HttpException::notFound('Invoice'));
    }

    /** @param array<string,mixed> $v */
    private function pdf(array $v): Response
    {
        $view = new View($this->app, $this->session, $this->auth->user());
        $html = $view->render('billing/invoice_pdf', ['invoice' => $v, 'payments' => array_values(array_filter($this->s->billing()->payments((int) $v['id']),
            static fn(array $p): bool => $p['status'] === 'confirmed')), 'settings' => $this->s->billing()->settings(), 'brand' => $this->s->branding()->get()['name']], null);
        return new Response(\App\Services\Results::pdf($html, $this->app->paths()->storage() . '/tmp/pdf'), 200,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $v['number'] . '.pdf"']);
    }
}
