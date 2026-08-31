-- Plan-038 T11.4 — workstation-local mirror of cloud's customer_invoices.
--
-- Pulled DOWN from /api/v1/workstation/invoices so the LAN print path can
-- render slips without a round-trip to Cloud. items_json carries the full
-- snapshot frozen at issue time.
CREATE TABLE IF NOT EXISTS customer_invoices (
    id                 TEXT PRIMARY KEY,
    invoice_no         TEXT NOT NULL,
    customer_order_id  TEXT NOT NULL,
    customer_id        TEXT NOT NULL,
    tax_code           TEXT NOT NULL,
    company_name       TEXT NOT NULL,
    billing_address    TEXT,
    recipient_email    TEXT,
    subtotal           REAL NOT NULL DEFAULT 0,
    tax_amount         REAL NOT NULL DEFAULT 0,
    total              REAL NOT NULL DEFAULT 0,
    currency_code      TEXT NOT NULL DEFAULT 'VND',
    tax_rate           REAL NOT NULL DEFAULT 0,
    items_json         TEXT NOT NULL DEFAULT '[]',
    status             TEXT NOT NULL DEFAULT 'issued',
    issued_at          TEXT NOT NULL,
    voided_at          TEXT,
    void_reason        TEXT,
    notes              TEXT,
    branch_id          TEXT NOT NULL,
    organization_id    TEXT NOT NULL,
    created_at         TEXT,
    updated_at         TEXT,
    synced_at          TEXT,
    void_notice_printed_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_customer_invoices_order
    ON customer_invoices(customer_order_id);
CREATE INDEX IF NOT EXISTS idx_customer_invoices_status
    ON customer_invoices(status, branch_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_customer_invoices_branch_no
    ON customer_invoices(branch_id, invoice_no);
