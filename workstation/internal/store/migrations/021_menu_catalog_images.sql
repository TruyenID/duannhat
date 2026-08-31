-- Parity with Shop\MenuController::show + listProducts.
--
-- pos-web's MenuCatalog reads (cards, carousel, combo variant, cart
-- thumbnail) lean on:
--
--   product.image_url            -- card thumbnail
--   product.gallery[]            -- order-picker carousel
--   product.product_type_code    -- 'combo' switches the card layout
--   sku.image_url                -- cart line thumbnail
--
-- Pre-021 the LAN replica had none of these — pos-web rendered
-- placeholder-only cards and the cart line never got a thumbnail when
-- the workstation served the menu. Migration 018 explicitly punted on
-- gallery+toppings; this migration brings the gallery + identity fields
-- on board. Topping groups remain a follow-up (plan-015 shop-local
-- time logic is its own surface).
--
-- Schema decisions:
--   * pos_products gets `image_url TEXT` (the resolved galleryFirst
--     URL, identical to what ProductResource.image_url emits — already
--     a single canonical URL on Cloud, no need to mirror the File
--     table) and `product_type_code TEXT` (lower-case, matches
--     BrandCoreCatalogService).
--   * pos_product_skus gets `image_url TEXT` for the per-variant
--     thumbnail (resolved server-side from productSku.galleryFirst).
--   * pos_product_galleries is a fresh table — every gallery item is
--     one row, ordered by sort_order (NULL last). Backed by FK to
--     pos_products with ON DELETE CASCADE so a future product wipe
--     doesn't leave orphan rows.
--
-- All ALTERs use ADD COLUMN without IF NOT EXISTS — SQLite doesn't
-- support the conditional, but the migration runner gates each version
-- on `schema_migrations` so this never re-runs on the same DB.

ALTER TABLE pos_products ADD COLUMN image_url         TEXT;
ALTER TABLE pos_products ADD COLUMN product_type_code TEXT;

ALTER TABLE pos_product_skus ADD COLUMN image_url     TEXT;

CREATE TABLE IF NOT EXISTS pos_product_galleries (
    id               TEXT PRIMARY KEY,
    product_id       TEXT NOT NULL,
    url              TEXT NOT NULL,
    original_name    TEXT,
    mime_type        TEXT,
    sort_order       INTEGER,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_pos_product_galleries_product
    ON pos_product_galleries(product_id, sort_order);
