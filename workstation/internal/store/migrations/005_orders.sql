-- Orders and line-items — workstation is source of truth, syncs UP to Cloud.
--
-- Status machine (cloud-aligned):
--   open → dining → checkout → paying → closed
--   any non-terminal → voided
--
-- order_type: 'dine_in' | 'takeaway' | 'spot'
--
-- Amounts are stored as integer yen (no decimals at workstation layer).
-- Tax is calculated workstation-side (taxRate from settings) and stored;
-- Cloud recalculates on sync UP — the stored value is for offline display.
--
-- Tenancy (organization_id, brand_id, branch_id) is populated at order
-- creation from the settings table (written during device pairing).
CREATE TABLE IF NOT EXISTS orders (
    -- Identity
    id                       TEXT PRIMARY KEY,
    cloud_id                 TEXT,            -- set after successful sync UP
    order_code               TEXT NOT NULL DEFAULT '',  -- WS-YYMMDD-NNNN offline code
    order_number             INTEGER NOT NULL DEFAULT 0,
    order_type               TEXT NOT NULL DEFAULT 'spot',   -- dine_in | takeaway | spot

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
    guest_count              INTEGER NOT NULL DEFAULT 1,
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
    synced_at                TEXT    -- NULL = pending sync UP
);

CREATE INDEX IF NOT EXISTS idx_orders_status     ON orders(status);
CREATE INDEX IF NOT EXISTS idx_orders_branch     ON orders(branch_id);
CREATE INDEX IF NOT EXISTS idx_orders_opened     ON orders(opened_at);
CREATE INDEX IF NOT EXISTS idx_orders_synced     ON orders(synced_at);
CREATE INDEX IF NOT EXISTS idx_orders_order_code ON orders(order_code);

-- Line items within an order.
--
-- status follows the cloud OrderItemStatus enum:
--   pending → preparing → ready → served | voided
--
-- print_status is workstation-local — tracks whether the kitchen ticket was
-- sent to the Star SDK printer. Cloud never sees this column.
--   pending | sent_to_kitchen | failed
--
-- KDS timestamps (started_preparing_at, ready_at, served_at) let the KDS
-- compute aging_minutes and time_in_current_status_seconds server-side,
-- matching Cloud's KdsItemResource. All three may be NULL (backfilled on
-- first transition after migration).
CREATE TABLE IF NOT EXISTS order_items (
    id                    TEXT PRIMARY KEY,
    customer_order_id     TEXT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_sku_id        TEXT,     -- Cloud SKU UUID; NULL for legacy/local items
    menu_item_id          TEXT,     -- local menu_items.id fallback
    menu_item_name        TEXT NOT NULL DEFAULT '',
    quantity              INTEGER NOT NULL DEFAULT 1,
    unit_price            INTEGER NOT NULL DEFAULT 0,
    subtotal              INTEGER NOT NULL DEFAULT 0,
    note                  TEXT,
    printer_group         TEXT NOT NULL DEFAULT 'kitchen',

    -- Cloud-aligned lifecycle status
    status                TEXT NOT NULL DEFAULT 'pending',
    started_preparing_at  TEXT,
    ready_at              TEXT,
    served_at             TEXT,
    voided_at             TEXT,
    void_reason           TEXT,

    -- Workstation-local print tracking
    print_status          TEXT NOT NULL DEFAULT 'pending',
    printed_at            TEXT,

    created_at            TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at            TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_order_items_order        ON order_items(customer_order_id);
CREATE INDEX IF NOT EXISTS idx_order_items_status       ON order_items(status);
CREATE INDEX IF NOT EXISTS idx_order_items_print_status ON order_items(print_status);

-- Junction for multi-table (merged) orders.
-- orders.table_id remains the "primary" table for backward-compatible reads.
-- Each physical table assigned to an order gets a row here.
CREATE TABLE IF NOT EXISTS order_tables (
    order_id   TEXT NOT NULL,
    table_id   TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    bound_at   TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (order_id, table_id),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_order_tables_order ON order_tables(order_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_order_tables_table ON order_tables(table_id);

-- Applied coupon binding per order.
-- released_at replaces a hard DELETE so the audit trail survives the lifecycle.
CREATE TABLE IF NOT EXISTS order_coupons (
    id               TEXT PRIMARY KEY,
    order_id         TEXT NOT NULL,
    coupon_id        TEXT NOT NULL,
    coupon_code      TEXT NOT NULL,
    discount_applied INTEGER NOT NULL,
    applied_at       TEXT NOT NULL DEFAULT (datetime('now')),
    released_at      TEXT,
    FOREIGN KEY (order_id)  REFERENCES orders(id)  ON DELETE CASCADE,
    FOREIGN KEY (coupon_id) REFERENCES coupons(id)
);

CREATE INDEX IF NOT EXISTS idx_order_coupons_active ON order_coupons(order_id) WHERE released_at IS NULL;
