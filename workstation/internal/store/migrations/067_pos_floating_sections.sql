-- #1180 / #1319 — the spotlight ("Khung giờ ưu đãi") replica.
--
-- Cloud's `GET /workstation/menu-catalog` has been emitting five floating-section
-- arrays since ca373cdec; nothing on this side stored them, so a shop selling
-- offline could not see a spotlight at all, and a product that exists ONLY in a
-- spotlight was invisible on the POS.
--
-- Three things these tables must NOT do, each of which would be a money bug:
--
--   1. `pos_floating_section_products.tax_type_id` is the tier ALREADY COLLAPSED
--      by Cloud (`FloatingSectionProduct.tax_type_id ?? Product.tax_type_id`).
--      Nothing here re-walks the tax tiers: a second implementation of that walk
--      is a second thing that can drift, and drifting here means printing one
--      rate on the invoice and booking another. NULL is meaningful — it means
--      "inherit", and the device resolver carries on to branch then brand
--      default exactly as Cloud does.
--
--   2. The promo price lives in `pos_floating_section_product_skus.selling_price`
--      and ONLY there. `pos_product_skus.selling_price` stays the price that SKU
--      is sold at from a normal menu. The same SKU genuinely has two prices, and
--      collapsing them into one column is how you sell the promo price all day.
--
--   3. Schedules ship RAW (`days_of_week` is a bitmask `1 << dayOfWeek`, 0 =
--      Sunday — the same encoding Cloud's FloatingSectionPriceResolver matches
--      against). The device evaluates the window against its own clock because
--      it runs for hours between pulls; a feed that pre-filtered "open now"
--      would be stale minutes later.
--
-- Every table is a full-replace replica, wiped and rewritten inside the same
-- transaction as the rest of the catalog (see sync_pull_pos.go).

-- The section itself. Per-locale names are FLATTENED onto the row (Cloud sends
-- floating_section_translations pre-joined) — POS runs three languages, and a
-- migration that kept only `name` would show the base name in two of them.
CREATE TABLE IF NOT EXISTS pos_floating_sections (
    id               TEXT PRIMARY KEY,
    name             TEXT NOT NULL,
    name_ja          TEXT,
    name_en          TEXT,
    name_vi          TEXT,
    priority         INTEGER NOT NULL DEFAULT 0,  -- lower = shown first, like menus.sort_order
    is_active        INTEGER NOT NULL DEFAULT 1,
    start_date       TEXT,                        -- nullable Y-m-d, shop-local calendar date
    end_date         TEXT,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pos_floating_sections_active
    ON pos_floating_sections(is_active, priority);

-- Recurring windows inside a section's date bounds. A section with no schedule
-- row is open for its whole date range; that is a shape Cloud allows, so the
-- evaluator must not read "no rows" as "never".
CREATE TABLE IF NOT EXISTS pos_floating_section_schedules (
    id                  TEXT PRIMARY KEY,
    floating_section_id TEXT NOT NULL,
    days_of_week        INTEGER NOT NULL DEFAULT 0,  -- bitmask, 1 << dow, 0 = Sunday
    start_time          TEXT NOT NULL,               -- HH:MM or HH:MM:SS, shop-local
    end_time            TEXT NOT NULL,
    start_date          TEXT,
    end_date            TEXT,
    is_active           INTEGER NOT NULL DEFAULT 1,
    priority            INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at    TEXT,
    local_synced_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pos_floating_schedules_section
    ON pos_floating_section_schedules(floating_section_id, is_active);

-- Membership: this product is in this spotlight. `tax_type_id` is point 1 above.
CREATE TABLE IF NOT EXISTS pos_floating_section_products (
    id                  TEXT PRIMARY KEY,
    floating_section_id TEXT NOT NULL,
    product_id          TEXT NOT NULL,
    tax_type_id         TEXT,                        -- pre-collapsed; NULL = inherit
    is_active           INTEGER NOT NULL DEFAULT 1,
    display_order       INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at    TEXT,
    local_synced_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pos_floating_products_section
    ON pos_floating_section_products(floating_section_id, is_active, display_order);
CREATE INDEX IF NOT EXISTS idx_pos_floating_products_product
    ON pos_floating_section_products(product_id);

-- The promo price per SKU — point 2 above.
CREATE TABLE IF NOT EXISTS pos_floating_section_product_skus (
    id                          TEXT PRIMARY KEY,
    floating_section_product_id TEXT NOT NULL,
    product_sku_id              TEXT NOT NULL,
    selling_price               INTEGER NOT NULL DEFAULT 0,
    is_active                   INTEGER NOT NULL DEFAULT 1,
    is_price_overridden         INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at            TEXT,
    local_synced_at             TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pos_floating_skus_parent
    ON pos_floating_section_product_skus(floating_section_product_id, is_active);
CREATE INDEX IF NOT EXISTS idx_pos_floating_skus_sku
    ON pos_floating_section_product_skus(product_sku_id);

-- Tier-1 topping overrides that belong to the spotlight line, NOT to the menu
-- line. Keyed by floating_section_product_id for exactly that reason: the same
-- product bought from a menu resolves its toppings through
-- pos_menu_product_topping_overrides instead.
CREATE TABLE IF NOT EXISTS pos_floating_section_topping_overrides (
    id                          TEXT PRIMARY KEY,
    floating_section_product_id TEXT NOT NULL,
    topping_group_id            TEXT NOT NULL,
    topping_group_item_id       TEXT NOT NULL,
    product_sku_id              TEXT,
    is_hidden                   INTEGER NOT NULL DEFAULT 0,
    override_price              INTEGER,
    cloud_updated_at            TEXT,
    local_synced_at             TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pos_floating_topping_ov_parent
    ON pos_floating_section_topping_overrides(floating_section_product_id);
