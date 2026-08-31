-- ============================================================================
-- Menu Promotion schema parity with backend (Plan: workstation full promo mirror).
--
-- Phase B of the coupon/promotion parity plan. Brings workstation's
-- menu_promotions in line with backend's column set so PullPromotions
-- can ingest a wider feed and the engine can honour the `applies_to`
-- enum (all_items | products | categories | mixed).
--
-- Pre-028 gaps (vs backend.menu_promotions):
--   * NO applies_to → engine always required a per-SKU pivot row, so
--     all_items / categories promotions never matched in LAN mode.
--   * NO branch_id / brand_id / organization_id → multi-branch routing
--     ambiguous.
--   * NO created_at → tie-breaker (highest discount_percent → earliest
--     created_at) couldn't run.
--   * NO description → cart / receipt explainability lost on local apply.
--   * NO stacking_mode → only the derived exclusive_with_coupons bool
--     was stored; mirroring the enum keeps the wire shape parity-safe
--     for future stacking modes.
--   * menu_promotion_products.product_sku_id keyed at SKU granularity,
--     but Cloud's menu_promotion_product pivot keys on PRODUCT (variant
--     overrides aren't a thing on the promotion side). Recreate the
--     pivot column under the backend-native name product_id.
--
-- Server-side category expansion (decision Q-B): backend's replica
-- controller flattens products+categories into a single product_ids[]
-- so workstation doesn't need a `pos_product_categories` pivot. We
-- store the FLATTENED product list — applies_to is kept on the row only
-- so debugging + audit trails can answer "did this promo match by
-- product or category" later.
--
-- SQLite migration runner wraps each .sql in a tx; no BEGIN/COMMIT here.
-- ============================================================================

ALTER TABLE menu_promotions ADD COLUMN description     TEXT;
ALTER TABLE menu_promotions ADD COLUMN applies_to      TEXT NOT NULL DEFAULT 'products';
ALTER TABLE menu_promotions ADD COLUMN stacking_mode   TEXT NOT NULL DEFAULT 'stackable_with_coupons';
ALTER TABLE menu_promotions ADD COLUMN branch_id       TEXT;
ALTER TABLE menu_promotions ADD COLUMN brand_id        TEXT;
ALTER TABLE menu_promotions ADD COLUMN organization_id TEXT;
ALTER TABLE menu_promotions ADD COLUMN promo_created_at TEXT;
-- promo_created_at name chosen to avoid colliding with the auto-managed
-- created_at the migration runner uses for local-row provenance (some
-- omnify-managed mirror tables already have `created_at`; we keep the
-- "when did this promotion get created on Cloud" timestamp distinct so
-- the tie-breaker query is unambiguous).

CREATE INDEX IF NOT EXISTS idx_menu_promotions_org_branch
    ON menu_promotions(organization_id, branch_id);

-- ─── menu_promotion_products: SKU → product key alignment ──────────────────
--
-- Pre-028 the pivot keyed on product_sku_id (workstation's invention).
-- Cloud's `menu_promotion_product` keys on product_id. Backend's replica
-- controller now flattens applies_to=categories into product_ids too, so
-- the pivot's semantic is "which products this promotion applies to" —
-- product granularity is the right level.
--
-- Migration data: convert each existing (promo, sku) row into
-- (promo, product). If pos_product_skus has the parent_id we use it;
-- otherwise the row is dropped — pre-028 those were almost always test
-- fixtures because LAN-mode promos rarely populated the SKU pivot in
-- production (the backend feed always shipped product_sku_ids:[], so
-- migrations from real installations migrate cleanly to empty).

CREATE TABLE menu_promotion_products_v2 (
    promotion_id     TEXT NOT NULL,
    product_id       TEXT NOT NULL,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (promotion_id, product_id),
    FOREIGN KEY (promotion_id) REFERENCES menu_promotions(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO menu_promotion_products_v2 (promotion_id, product_id)
SELECT mpp.promotion_id, COALESCE(ps.product_id, '')
FROM menu_promotion_products mpp
LEFT JOIN pos_product_skus ps ON ps.id = mpp.product_sku_id
WHERE COALESCE(ps.product_id, '') <> '';

DROP TABLE menu_promotion_products;
ALTER TABLE menu_promotion_products_v2 RENAME TO menu_promotion_products;

CREATE INDEX IF NOT EXISTS idx_promotion_products_product
    ON menu_promotion_products(product_id);
