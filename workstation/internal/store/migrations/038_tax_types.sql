-- plan-043 Phase 3 (T3.1) — consumption-tax (軽減税率 / インボイス) mirror + snapshots.
--
-- The workstation runs the SAME 6-step pricing engine offline as Cloud PHP
-- (OrderPricingCalculator) so a mixed takeaway order (bentō 8% + beer 10%)
-- prices to the yen locally. That needs three things mirrored down:
--   1. a `tax_types` table (the brand's tax types: two rates each),
--   2. per-order-item snapshot columns (tax_type_id / tax_rate / tax_amount /
--      tax_alcohol_escalated) — stamped at add-item time, immutable,
--      NOTE: tax_alcohol_escalated is dropped again by migration 059 and the
--      rate pair by 054 (#1099) — this file is history, not current shape.
--   3. per-menu-item resolution inputs (tax_type_id + is_alcohol) + the alcohol
--      flag on topping-group items so 酒類 escalation runs offline too.
-- Plus `orders.is_tax_included` (the tax-mode snapshot taken at order creation).
--
-- All new columns have safe DEFAULTs so pre-migration rows (and orders synced
-- from an OLD cloud that omits the fields) keep working: a missing tax_rate
-- decodes to NULL → the engine falls back to the legacy branch rate (never a
-- hardcoded 10%), a missing tax_alcohol_escalated / is_alcohol / is_tax_included
-- reads 0 → no escalation, no panic. repairLegacySchema() re-adds each column
-- idempotently for DBs that pre-date this migration.

-- 1. tax_types mirror — synced DOWN from GET /workstation/menu (top-level
--    `tax_types[]`). is_default marks the brand default (resolver tier 4);
--    is_active=0 blocks NEW assignment but keeps historical references valid.
CREATE TABLE IF NOT EXISTS tax_types (
    id               TEXT PRIMARY KEY,
    code             TEXT NOT NULL DEFAULT '',
    name             TEXT NOT NULL DEFAULT '',
    rate_dine_in     REAL NOT NULL DEFAULT 0,   -- applied to spot + dine_in
    rate_takeaway    REAL NOT NULL DEFAULT 0,   -- applied to takeaway
    is_default       INTEGER NOT NULL DEFAULT 0,
    is_active        INTEGER NOT NULL DEFAULT 1,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_tax_types_default ON tax_types(is_default) WHERE is_default = 1;

-- 2. order_items snapshot columns (immutable, engine-stamped). All nullable /
--    defaulted so the pull-DOWN upsert can pass NULL for an old-cloud payload
--    that omits them (SQLite rejects an explicit NULL into NOT NULL even with a
--    DEFAULT — the default only fires when the column is OMITTED from INSERT).
--    tax_rate NULL = unstamped/legacy line → engine legacy fallback.
ALTER TABLE order_items ADD COLUMN tax_type_id TEXT;
ALTER TABLE order_items ADD COLUMN tax_rate REAL;
ALTER TABLE order_items ADD COLUMN tax_amount INTEGER DEFAULT 0;
ALTER TABLE order_items ADD COLUMN tax_alcohol_escalated INTEGER DEFAULT 0;

-- 3. orders tax-mode snapshot taken at creation (mirrors
--    customer_orders.is_tax_included). Nullable so the pull upsert can COALESCE
--    an omitted field to the existing value; reads use COALESCE(...,0).
ALTER TABLE orders ADD COLUMN is_tax_included INTEGER DEFAULT 0;

-- 4. menu_items resolution inputs — tax_type_id (resolved MenuProduct→Product,
--    NULL = inherit branch/brand default) + is_alcohol (酒類 flag for escalation).
ALTER TABLE menu_items ADD COLUMN tax_type_id TEXT;
ALTER TABLE menu_items ADD COLUMN is_alcohol INTEGER DEFAULT 0;

-- 5. pos_topping_group_items alcohol flag — mirror of the component product's
--    is_alcohol, so a combo that offers an alcohol component escalates offline.
ALTER TABLE pos_topping_group_items ADD COLUMN is_alcohol INTEGER DEFAULT 0;
