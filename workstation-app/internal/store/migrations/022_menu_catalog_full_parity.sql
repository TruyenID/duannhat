-- Full parity with Shop\MenuController::show + listProducts.
--
-- pos-web reads these resources verbatim:
--   * MenuProductSkuResource — is_price_overridden + default_price
--     overlay (badge cashier sees when the shop tier-1 override is
--     active).
--   * ProductSkuResource.option_value1/2/3 + .option — variant labels
--     ("Size: Large").
--   * MenuToppingGroupResource + MenuToppingGroupItemResource +
--     MenuToppingGroupItemSkuResource — Plan-015 topping selector with
--     effective_min/max_select (override → fallback) and 3-tier price
--     resolution (shop_override → product_override → base extra_price).
--
-- Pre-022 the LAN replica had:
--   * Flattened sku price (override merged into selling_price, no flag,
--     no canonical fallback)
--   * No option_values / no options table at all
--   * No topping_groups / items / item_skus / pivot / overrides
--
-- The fix walks the full set: extend pos_product_skus with the three
-- option_value FKs + the price-override pair, then add 8 normalized
-- tables for options, option_values, topping_groups, items, item_skus,
-- product↔group pivot, plus tier-1 (menu_product) + tier-2 (product)
-- override tables. The workstation handler then resolves the same
-- effective_min/max + 3-tier extra_price at read time so the wire
-- shape matches Cloud byte-for-byte.

-- ─── pos_product_skus extensions ────────────────────────────────────────────

ALTER TABLE pos_product_skus ADD COLUMN default_price       INTEGER;
ALTER TABLE pos_product_skus ADD COLUMN is_price_overridden INTEGER NOT NULL DEFAULT 0;
ALTER TABLE pos_product_skus ADD COLUMN option_value1_id    TEXT;
ALTER TABLE pos_product_skus ADD COLUMN option_value2_id    TEXT;
ALTER TABLE pos_product_skus ADD COLUMN option_value3_id    TEXT;

CREATE INDEX IF NOT EXISTS idx_pos_product_skus_opt1 ON pos_product_skus(option_value1_id) WHERE option_value1_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_pos_product_skus_opt2 ON pos_product_skus(option_value2_id) WHERE option_value2_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_pos_product_skus_opt3 ON pos_product_skus(option_value3_id) WHERE option_value3_id IS NOT NULL;

-- ─── product_options + option_values ────────────────────────────────────────

CREATE TABLE IF NOT EXISTS pos_product_options (
    id               TEXT PRIMARY KEY,
    product_id       TEXT NOT NULL,
    key              TEXT NOT NULL,
    name             TEXT NOT NULL,
    position         INTEGER NOT NULL DEFAULT 0,
    is_active        INTEGER NOT NULL DEFAULT 1,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_pos_product_options_product
    ON pos_product_options(product_id, position);

CREATE TABLE IF NOT EXISTS pos_product_option_values (
    id               TEXT PRIMARY KEY,
    option_id        TEXT NOT NULL,
    value            TEXT NOT NULL,
    label            TEXT,
    position         INTEGER NOT NULL DEFAULT 0,
    is_active        INTEGER NOT NULL DEFAULT 1,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (option_id) REFERENCES pos_product_options(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_pos_product_option_values_option
    ON pos_product_option_values(option_id, position);

-- ─── topping_groups (HQ-level) ──────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS pos_topping_groups (
    id               TEXT PRIMARY KEY,
    name             TEXT NOT NULL,
    selection_type   TEXT NOT NULL,             -- single | multiple
    modifier_type    TEXT NOT NULL,             -- add | remove
    price_strategy   TEXT NOT NULL,             -- flat | free_up_to_n
    free_quantity    INTEGER,
    min_select       INTEGER NOT NULL DEFAULT 0,
    max_select       INTEGER,
    max_qty_per_item INTEGER NOT NULL DEFAULT 1,
    sort_order       INTEGER NOT NULL DEFAULT 0,
    is_active        INTEGER NOT NULL DEFAULT 1,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pos_topping_groups_active
    ON pos_topping_groups(is_active, sort_order);

CREATE TABLE IF NOT EXISTS pos_topping_group_items (
    id               TEXT PRIMARY KEY,
    topping_group_id TEXT NOT NULL,
    product_id       TEXT NOT NULL,
    sort_order       INTEGER NOT NULL DEFAULT 0,
    is_default       INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (topping_group_id) REFERENCES pos_topping_groups(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_pos_topping_group_items_group
    ON pos_topping_group_items(topping_group_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_pos_topping_group_items_product
    ON pos_topping_group_items(product_id);

CREATE TABLE IF NOT EXISTS pos_topping_group_item_skus (
    id                      TEXT PRIMARY KEY,
    topping_group_item_id   TEXT NOT NULL,
    product_sku_id          TEXT,
    extra_price             INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at        TEXT,
    local_synced_at         TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (topping_group_item_id) REFERENCES pos_topping_group_items(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_pos_topping_group_item_skus_item
    ON pos_topping_group_item_skus(topping_group_item_id);

-- ─── product ↔ topping_group pivot (with effective_*_select overrides) ──────

CREATE TABLE IF NOT EXISTS pos_product_topping_groups (
    product_id          TEXT NOT NULL,
    topping_group_id    TEXT NOT NULL,
    sort_order          INTEGER NOT NULL DEFAULT 0,
    min_select_override INTEGER,
    max_select_override INTEGER,
    cloud_updated_at    TEXT,
    local_synced_at     TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (product_id, topping_group_id),
    FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE CASCADE,
    FOREIGN KEY (topping_group_id) REFERENCES pos_topping_groups(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_pos_product_topping_groups_product
    ON pos_product_topping_groups(product_id, sort_order);

-- ─── tier-1: per-menu_product item-sku override (shop level) ────────────────

CREATE TABLE IF NOT EXISTS pos_menu_product_topping_overrides (
    id                    TEXT PRIMARY KEY,
    menu_product_id       TEXT NOT NULL,
    topping_group_id      TEXT NOT NULL,
    topping_group_item_id TEXT NOT NULL,
    product_sku_id        TEXT,
    is_hidden             INTEGER NOT NULL DEFAULT 0,
    override_price        INTEGER,
    cloud_updated_at      TEXT,
    local_synced_at       TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pos_mp_topping_overrides_mp
    ON pos_menu_product_topping_overrides(menu_product_id);
CREATE INDEX IF NOT EXISTS idx_pos_mp_topping_overrides_item
    ON pos_menu_product_topping_overrides(topping_group_item_id);

-- ─── tier-2: per-product item-sku override (HQ level) ───────────────────────

CREATE TABLE IF NOT EXISTS pos_product_topping_item_overrides (
    id                    TEXT PRIMARY KEY,
    product_id            TEXT NOT NULL,
    topping_group_item_id TEXT NOT NULL,
    product_sku_id        TEXT,
    override_price        INTEGER,
    is_hidden             INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at      TEXT,
    local_synced_at       TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pos_product_topping_overrides_product
    ON pos_product_topping_item_overrides(product_id);
CREATE INDEX IF NOT EXISTS idx_pos_product_topping_overrides_item
    ON pos_product_topping_item_overrides(topping_group_item_id);
