-- ============================================================================
-- LAN-offline orders — promotion / coupon engine + missing replicas.
--
-- Existing schema already had: orders, order_items, order_tables, order_coupons,
-- order_item_toppings, coupons (basic shape), menu_items (flat cache).
--
-- This migration adds what's missing for LAN-offline ordering with promotion
-- and coupon math:
--   - menu_promotions + day/time/product schedules (sync DOWN)
--   - coupon_redemptions (per-order ledger; sync UP individually so cap
--     enforcement at Cloud is authoritative)
--   - order_items.original_unit_price (snapshot the menu-listed price BEFORE
--     promotion ran, so reprints / refunds can show "X was struck through"
--     on receipt — backend has the same column on customer_order_items)
--   - order_items.promotion_id (so the refund flow knows which HH applied)
--   - orders.coupon_discount_amount (separate column so the receipt can
--     show "Subtotal − Coupon = Total" without re-computing from coupons)
--   - orders.customer_id (workstation needs this to enforce per-customer
--     coupon caps locally before sync UP)
--
-- SQLite doesn't support ALTER COLUMN; new columns are nullable defaults so
-- existing rows survive the migration without backfill.
-- ============================================================================

-- ─── Order-item promotion snapshot ─────────────────────────────────────────

ALTER TABLE order_items ADD COLUMN original_unit_price INTEGER;
ALTER TABLE order_items ADD COLUMN promotion_id        TEXT;
ALTER TABLE order_items ADD COLUMN promotion_label     TEXT;
ALTER TABLE order_items ADD COLUMN topping_subtotal    INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_order_items_promotion
    ON order_items(promotion_id) WHERE promotion_id IS NOT NULL;

-- ─── Order-level coupon + customer ──────────────────────────────────────────

ALTER TABLE orders ADD COLUMN customer_id            TEXT;
ALTER TABLE orders ADD COLUMN coupon_discount_amount INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_orders_customer
    ON orders(customer_id) WHERE customer_id IS NOT NULL;

-- ─── Coupons table — extend to match Cloud surface ─────────────────────────

ALTER TABLE coupons ADD COLUMN exclusive_with_promotions INTEGER NOT NULL DEFAULT 0;
ALTER TABLE coupons ADD COLUMN per_customer_limit         INTEGER;
ALTER TABLE coupons ADD COLUMN applies_to                 TEXT NOT NULL DEFAULT 'order';
-- applies_to: 'order' | 'item' (item-level coupons not supported in v1)

-- ─── Coupon redemption ledger ──────────────────────────────────────────────
--
-- One row per successful apply (released ones survive so per-customer cap
-- math knows the history). Cloud writes its own row on sync UP and reconciles
-- against this when retry hits; workstation rows have cloud_id NULL until
-- the sync UP succeeds.
CREATE TABLE IF NOT EXISTS coupon_redemptions (
    id               TEXT PRIMARY KEY,
    cloud_id         TEXT,
    coupon_id        TEXT NOT NULL,
    coupon_code      TEXT NOT NULL,
    order_id         TEXT NOT NULL,
    customer_id      TEXT,
    discount_amount  INTEGER NOT NULL,
    redeemed_at      TEXT NOT NULL DEFAULT (datetime('now')),
    released_at      TEXT,
    synced_at        TEXT,
    FOREIGN KEY (coupon_id) REFERENCES coupons(id),
    FOREIGN KEY (order_id)  REFERENCES orders(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_coupon_redemptions_coupon_active
    ON coupon_redemptions(coupon_id) WHERE released_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_coupon_redemptions_customer
    ON coupon_redemptions(customer_id) WHERE released_at IS NULL AND customer_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_coupon_redemptions_sync
    ON coupon_redemptions(synced_at) WHERE synced_at IS NULL;

-- ─── Menu promotions (Happy Hour / time-of-day discounts) ──────────────────
--
-- discount_type: 'percent' | 'amount' | 'fixed_unit_price'
--   percent          → discount = floor(unit_price * value / 10000) (basis points)
--   amount           → discount = value (yen)
--   fixed_unit_price → unit_price becomes value (yen)
--
-- exclusive_with_coupons: 1 means an order with this promotion-applied item
-- rejects coupon apply (matches Cloud's MenuPromotionService convention).
CREATE TABLE IF NOT EXISTS menu_promotions (
    id                       TEXT PRIMARY KEY,
    name                     TEXT NOT NULL,
    discount_type            TEXT NOT NULL CHECK (discount_type IN ('percent','amount','fixed_unit_price')),
    discount_value           INTEGER NOT NULL,
    starts_at                TEXT,
    ends_at                  TEXT,
    is_active                INTEGER NOT NULL DEFAULT 1,
    exclusive_with_coupons   INTEGER NOT NULL DEFAULT 0,
    priority                 INTEGER NOT NULL DEFAULT 100,
    cloud_updated_at         TEXT,
    local_synced_at          TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_menu_promotions_active
    ON menu_promotions(is_active, starts_at, ends_at);

-- Member SKUs (the promotion only applies to items whose product_sku_id
-- appears here).
CREATE TABLE IF NOT EXISTS menu_promotion_products (
    promotion_id   TEXT NOT NULL,
    product_sku_id TEXT NOT NULL,
    PRIMARY KEY (promotion_id, product_sku_id),
    FOREIGN KEY (promotion_id) REFERENCES menu_promotions(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_promotion_products_sku
    ON menu_promotion_products(product_sku_id);

-- Day-of-week + intra-day time windows. NULL day_of_week = every day.
-- Times are HH:MM in branch local time (NOT UTC — promotions are read in
-- branch-local clock and the workstation always runs in branch TZ).
CREATE TABLE IF NOT EXISTS menu_promotion_schedules (
    id              TEXT PRIMARY KEY,
    promotion_id    TEXT NOT NULL,
    day_of_week     INTEGER,                   -- 0=Sun..6=Sat, NULL = any day
    daily_time_from TEXT,                      -- "HH:MM", NULL = always-on for the day
    daily_time_to   TEXT,                      -- "HH:MM"
    FOREIGN KEY (promotion_id) REFERENCES menu_promotions(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_promotion_schedules_promo
    ON menu_promotion_schedules(promotion_id);
