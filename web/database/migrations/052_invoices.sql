-- Paying for a plan without a payment gateway: the operator issues an invoice, the institution
-- pays by mobile money, bank transfer or cash and says so with the reference, and the operator
-- confirms what arrived. An issued invoice is never changed: it is paid, or voided with a reason.
CREATE TABLE IF NOT EXISTS invoices (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    number         VARCHAR(32)  NOT NULL,            -- INV-2026-0001
    institution_id INT UNSIGNED NOT NULL,
    plan_id        INT UNSIGNED NULL,
    description    VARCHAR(255) NOT NULL,
    currency       CHAR(3)      NOT NULL,
    amount         DECIMAL(12,2) NOT NULL,
    period_from    DATE         NULL,
    period_to      DATE         NULL,
    due_on         DATE         NOT NULL,
    status         VARCHAR(16)  NOT NULL DEFAULT 'issued',   -- issued | paid | void
    issued_by      INT UNSIGNED NULL,
    issued_at      DATETIME     NOT NULL,
    paid_at        DATETIME     NULL,
    void_reason    VARCHAR(255) NULL,
    voided_by      INT UNSIGNED NULL,
    voided_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoices_uuid (uuid),
    UNIQUE KEY uq_invoices_number (number),
    UNIQUE KEY uq_invoices_institution (institution_id, id),
    KEY ix_invoices_status (status, due_on),
    CONSTRAINT fk_invoices_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_invoices_plan FOREIGN KEY (plan_id) REFERENCES plans (id),
    CONSTRAINT fk_invoices_issuer FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_voider FOREIGN KEY (voided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_payments (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    invoice_id     INT UNSIGNED NOT NULL,
    amount         DECIMAL(12,2) NOT NULL,
    method         VARCHAR(16)  NOT NULL,            -- mobile_money | bank | cash | other
    reference      VARCHAR(80)  NOT NULL,            -- the transaction id or deposit slip number
    paid_on        DATE         NOT NULL,
    note           VARCHAR(255) NULL,
    status         VARCHAR(16)  NOT NULL DEFAULT 'reported',  -- reported | confirmed | rejected
    reported_by    INT UNSIGNED NULL,
    reported_at    DATETIME     NOT NULL,
    decided_by     INT UNSIGNED NULL,
    decided_at     DATETIME     NULL,
    decision_note  VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_payments_uuid (uuid),
    KEY ix_invoice_payments_status (status, reported_at),
    CONSTRAINT fk_invoice_payments_invoice FOREIGN KEY (institution_id, invoice_id) REFERENCES invoices (institution_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_payments_reporter FOREIGN KEY (reported_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_invoice_payments_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
