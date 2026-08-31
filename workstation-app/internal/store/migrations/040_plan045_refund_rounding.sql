-- plan-045 T10 — configurable tax rounding + order_condition ledger + refund lines.
--
-- Mirrors the Cloud (Laravel) plan-045 schema changes on the workstation SQLite
-- side so a LAN-priced / LAN-refunded order carries the SAME snapshot columns and
-- reconciles through sync (see plan-045/DESIGN.md § "Workstation & sync"):
--
--   1. orders.{tax_rounding_mode,tax_rounding_decimals} — the per-order rounding
--      snapshot, stamped at create time from shop_settings. The Go pricing engine
--      reads these off the ORDER ROW (not shop_settings) so a settings change
--      never re-rounds a historical order. mode defaults 'round' (round/ceil/
--      floor; legacy half_up/round_up/round_down still alias), decimals default 0.
--   2. order_items.{refund_of_item_id,refunded_quantity} — refund support. A
--      refund line is any row with refund_of_item_id != '' (carries quantity < 0,
--      subtotal < 0, tax_amount ≤ 0, product_sku_id COPIED from the original so
--      readOrderItemForSync never drops it — gap #2). refunded_quantity is the
--      accumulator on the ORIGINAL line (Σ refunded units; guard ≤ quantity).
--   3. order_conditions — the polymorphic append/regenerate ledger (mirror of the
--      Cloud OrderCondition table, template 032_customer_invoices_mirror.sql).
--      Cloud-authoritative for tax/discount rows; the workstation writes only
--      refund rows from a LAN refund and pulls the rest DOWN.
--
-- All new columns have safe DEFAULTs so pre-migration rows (and orders synced
-- from an OLD cloud that omits the fields) keep working. repairLegacySchema()
-- re-adds each column idempotently for DBs that pre-date this migration.

-- 1. orders — rounding snapshot. mode defaults 'round'; decimals defaults 0
--    (legacy NULL still reads as the currency step). Defaulted so the pull-DOWN
--    upsert can COALESCE an omitted field to the existing value.
ALTER TABLE orders ADD COLUMN tax_rounding_mode TEXT DEFAULT 'round';
ALTER TABLE orders ADD COLUMN tax_rounding_decimals INTEGER DEFAULT 0;

-- 2. order_items — refund columns. refund_of_item_id NULL/'' on normal lines,
--    set to the original line's id on refund lines. refunded_quantity is the
--    accumulator on the ORIGINAL line.
ALTER TABLE order_items ADD COLUMN refund_of_item_id TEXT;
ALTER TABLE order_items ADD COLUMN refunded_quantity INTEGER DEFAULT 0;

-- 3. order_conditions — value-copied audit ledger of every financial condition
--    applied to an order or a single line (tax / discount / refund). amount is
--    SIGNED (tax +, discount −, refund −). meta carries source ids + rate-group
--    key + refund_of_item_id as reference-only JSON.
CREATE TABLE IF NOT EXISTS order_conditions (
    id                 TEXT PRIMARY KEY,
    conditionable_type TEXT NOT NULL DEFAULT '',   -- morph alias: 'order' | 'order_item'
    conditionable_id   TEXT NOT NULL DEFAULT '',   -- morph id (order id / order_item id)
    type               TEXT NOT NULL DEFAULT '',   -- 'tax' | 'discount' | 'refund'
    source             TEXT,                        -- 'tax_type' | 'service_charge' | 'coupon' | 'promotion' | 'manual'
    label              TEXT NOT NULL DEFAULT '',
    rate               REAL,                        -- % when applicable
    amount             REAL NOT NULL DEFAULT 0,     -- SIGNED snapshot
    currency_code      TEXT NOT NULL DEFAULT 'VND',
    meta               TEXT,                        -- JSON: source ids, rate-group key, refund_of_item_id
    created_at         TEXT,
    updated_at         TEXT
);

CREATE INDEX IF NOT EXISTS idx_order_conditions_conditionable
    ON order_conditions(conditionable_type, conditionable_id);
CREATE INDEX IF NOT EXISTS idx_order_conditions_type
    ON order_conditions(type);
