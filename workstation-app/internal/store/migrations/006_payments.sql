-- Payments — workstation is source of truth, syncs UP to Cloud.
--
-- status: pending | succeeded | failed | refunded
--
-- Payment flow:
--   1. POS/kiosk creates a local payment row (status=pending).
--   2. SyncEngine enqueues payment.create → POST /api/v1/{kiosk|workstation}/payments.
--   3. Cloud responds with cloud_id; SyncEngine writes it back.
--   4. On terminal result: SyncEngine enqueues payment.confirm or payment.fail.
--
-- refunded_amount accumulates partial refunds; refunded_at marks full refund.
-- Partial refund history is in payment_refunds.
CREATE TABLE IF NOT EXISTS payments (
    id                TEXT PRIMARY KEY,
    cloud_id          TEXT,
    order_id          TEXT NOT NULL,
    payment_method    TEXT NOT NULL,
    amount            INTEGER NOT NULL,
    refunded_amount   INTEGER NOT NULL DEFAULT 0,
    refunded_at       TEXT,
    status            TEXT NOT NULL DEFAULT 'pending',
    idempotency_key   TEXT UNIQUE,
    terminal_response TEXT,
    created_at        TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT NOT NULL DEFAULT (datetime('now')),
    synced_at         TEXT
);

CREATE INDEX IF NOT EXISTS idx_payments_status  ON payments(status);
CREATE INDEX IF NOT EXISTS idx_payments_synced  ON payments(synced_at) WHERE synced_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_payments_order   ON payments(order_id);

-- Audit trail for partial refunds. Cloud remains authority on issuance;
-- workstation mirrors refunds after Cloud confirms them.
CREATE TABLE IF NOT EXISTS payment_refunds (
    id          TEXT PRIMARY KEY,
    payment_id  TEXT NOT NULL,
    amount      INTEGER NOT NULL,
    note        TEXT,
    refunded_at TEXT NOT NULL DEFAULT (datetime('now')),
    synced_at   TEXT,
    FOREIGN KEY (payment_id) REFERENCES payments(id)
);

CREATE INDEX IF NOT EXISTS idx_payment_refunds_payment ON payment_refunds(payment_id);
CREATE INDEX IF NOT EXISTS idx_payment_refunds_sync    ON payment_refunds(synced_at) WHERE synced_at IS NULL;

-- Payment methods accepted at this branch — sync DOWN from Cloud.
-- PaymentDialog in pos-web renders these as cashier buttons.
--   requires_tendered : staff must enter cash received → change_amount computed.
--   is_auto_confirm   : Cloud auto-closes order when paid; pos-web skips polling.
CREATE TABLE IF NOT EXISTS payment_methods (
    id                TEXT PRIMARY KEY,
    code              TEXT NOT NULL,
    name              TEXT NOT NULL,
    is_active         INTEGER NOT NULL DEFAULT 1,
    sort_order        INTEGER NOT NULL DEFAULT 0,
    requires_tendered INTEGER NOT NULL DEFAULT 0,
    is_auto_confirm   INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at  TEXT,
    local_synced_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_payment_methods_active ON payment_methods(is_active, sort_order);
