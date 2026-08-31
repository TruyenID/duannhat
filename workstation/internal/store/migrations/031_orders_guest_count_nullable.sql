-- ============================================================================
-- Make orders.guest_count nullable to match backend parity.
--
-- Pre-fix: workstation's `orders` table (migration 005) declared
--   guest_count INTEGER NOT NULL DEFAULT 1
-- combined with `order_service.Create` forcing `input.GuestCount = 1`
-- when the value was 0/missing. End result: a brand-new empty order
-- always landed with guest_count=1, even when the cashier never
-- entered a guest count.
--
-- Backend's `customer_orders.guest_count` is nullable (migrations/omnify/
-- 2000_01_01_000014_create_customer_orders_table.php → `nullable()`).
-- Cloud's CustomerOrderController spec also marks the field
-- `nullable: true`. The `init` endpoint applies guest_count "only if DB
-- value is currently null" — that first-write-wins semantic only works
-- when null is representable. The workstation diverged silently and
-- pos-web saw "1" for every empty order in LAN mode.
--
-- SQLite has no ALTER COLUMN DROP NOT NULL. Recreate-table + copy +
-- rename is the only correct path. The migration runner wraps each .sql
-- in a tx; FK is on by default (foreign_keys=ON via DSN), so we
-- explicitly toggle it off for the swap to avoid tripping the order_items,
-- order_tables, payments, etc. cascades during DROP TABLE.
--
-- Existing rows that currently hold `1` are left untouched. They were
-- written under the old default; changing them to NULL would lose
-- information about whether "1" reflected real intent or the silent
-- default. New empty orders post-031 land with NULL.
-- ============================================================================

PRAGMA foreign_keys = OFF;

CREATE TABLE orders_v2 (
    -- Identity (mirror migration 005 column-for-column, only flipping
    -- guest_count's NOT NULL off).
    id                       TEXT PRIMARY KEY,
    cloud_id                 TEXT,
    order_code               TEXT NOT NULL DEFAULT '',
    order_number             INTEGER NOT NULL DEFAULT 0,
    order_type               TEXT NOT NULL DEFAULT 'spot',

    -- Status + timing
    status                   TEXT NOT NULL DEFAULT 'open',
    opened_at                TEXT NOT NULL DEFAULT (datetime('now')),
    checkout_at              TEXT,
    closed_at                TEXT,
    voided_at                TEXT,
    void_reason              TEXT,

    -- Customer / table context
    table_id                 TEXT,
    table_number             TEXT,
    -- ← the actual change: nullable now.
    guest_count              INTEGER,
    customer_takeaway_name   TEXT,
    customer_takeaway_phone  TEXT,
    note                     TEXT,

    -- Amounts
    subtotal                 INTEGER NOT NULL DEFAULT 0,
    discount_amount          INTEGER NOT NULL DEFAULT 0,
    service_charge           INTEGER NOT NULL DEFAULT 0,
    tax_amount               INTEGER NOT NULL DEFAULT 0,
    total_tip                INTEGER NOT NULL DEFAULT 0,
    total_amount             INTEGER NOT NULL DEFAULT 0,
    paid_amount              INTEGER NOT NULL DEFAULT 0,
    payment_method           TEXT,

    -- Tenancy
    organization_id          TEXT NOT NULL DEFAULT '',
    brand_id                 TEXT NOT NULL DEFAULT '',
    branch_id                TEXT NOT NULL DEFAULT '',

    -- Tracking
    created_at               TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at               TEXT NOT NULL DEFAULT (datetime('now')),
    synced_at                TEXT,

    -- Migration 016 additions
    customer_id              TEXT,
    coupon_discount_amount   INTEGER NOT NULL DEFAULT 0
);

INSERT INTO orders_v2 (
    id, cloud_id, order_code, order_number, order_type,
    status, opened_at, checkout_at, closed_at, voided_at, void_reason,
    table_id, table_number, guest_count, customer_takeaway_name, customer_takeaway_phone, note,
    subtotal, discount_amount, service_charge, tax_amount, total_tip,
    total_amount, paid_amount, payment_method,
    organization_id, brand_id, branch_id,
    created_at, updated_at, synced_at,
    customer_id, coupon_discount_amount
)
SELECT
    id, cloud_id, order_code, order_number, order_type,
    status, opened_at, checkout_at, closed_at, voided_at, void_reason,
    table_id, table_number, guest_count, customer_takeaway_name, customer_takeaway_phone, note,
    subtotal, discount_amount, service_charge, tax_amount, total_tip,
    total_amount, paid_amount, payment_method,
    organization_id, brand_id, branch_id,
    created_at, updated_at, synced_at,
    customer_id, coupon_discount_amount
FROM orders;

DROP TABLE orders;
ALTER TABLE orders_v2 RENAME TO orders;

CREATE INDEX IF NOT EXISTS idx_orders_status     ON orders(status);
CREATE INDEX IF NOT EXISTS idx_orders_branch     ON orders(branch_id);
CREATE INDEX IF NOT EXISTS idx_orders_opened     ON orders(opened_at);
CREATE INDEX IF NOT EXISTS idx_orders_synced     ON orders(synced_at);
CREATE INDEX IF NOT EXISTS idx_orders_order_code ON orders(order_code);
CREATE INDEX IF NOT EXISTS idx_orders_customer
    ON orders(customer_id) WHERE customer_id IS NOT NULL;

PRAGMA foreign_keys = ON;
