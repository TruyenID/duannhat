-- ============================================================================
-- Menu eager-load replicas — LAN-offline /pos/menus/*.
--
-- Existing schema had `menu_items` (flat name/price cache) sufficient for
-- handy adds but missing the nested shape pos-web's MenuCatalog renders:
-- menus.menu_products[].skus[] with product, section, active promotion.
--
-- Adds the minimum tables + sync replicas for the 3 endpoints pos-web's
-- order-creation flow needs to render LAN-offline:
--   GET /api/v1/pos/menus
--   GET /api/v1/pos/menus/{menu}
--   GET /api/v1/pos/menus/by-day/{dayOfWeek}
--
-- TABLE NAMING: all replicas use a `pos_` prefix to avoid collision with
-- the omnify-generated `menus`, `products`, `product_skus` tables that
-- exist for cloud schema parity but follow a richer (organization_id /
-- brand_id / approval workflow) shape. Mixing the two in one query would
-- be ambiguous; keeping them separate also means future omnify regens
-- can't accidentally drop our shape.
--
-- Out of scope here (still proxy to Cloud):
--   - Gallery (productSku.gallery_first) — UI shows generic placeholder.
--   - Topping groups + product options — pos-web falls back to "no toppings"
--     prompt; the LAN-offline cart-add still works without it.
--   - The paginated /menus/{menu}/products list — pos-web's primary read
--     path is the bundled menu detail, which we DO serve.
-- ============================================================================

CREATE TABLE IF NOT EXISTS pos_menus (
    id               TEXT PRIMARY KEY,
    name             TEXT NOT NULL,
    description      TEXT,
    status           TEXT NOT NULL DEFAULT 'published',
    sort_order       INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pos_menus_status ON pos_menus(status, sort_order);

CREATE TABLE IF NOT EXISTS pos_menu_sections (
    id               TEXT PRIMARY KEY,
    menu_id          TEXT NOT NULL,
    name             TEXT NOT NULL,
    sort_order       INTEGER NOT NULL DEFAULT 0,
    is_active        INTEGER NOT NULL DEFAULT 1,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (menu_id) REFERENCES pos_menus(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_pos_menu_sections_menu ON pos_menu_sections(menu_id, sort_order);

CREATE TABLE IF NOT EXISTS pos_menu_products (
    id               TEXT PRIMARY KEY,
    menu_id          TEXT NOT NULL,
    product_id       TEXT NOT NULL,
    menu_section_id  TEXT,
    is_active        INTEGER NOT NULL DEFAULT 1,
    display_order    INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (menu_id) REFERENCES pos_menus(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_pos_menu_products_menu ON pos_menu_products(menu_id, display_order);
CREATE INDEX IF NOT EXISTS idx_pos_menu_products_section ON pos_menu_products(menu_section_id);
CREATE INDEX IF NOT EXISTS idx_pos_menu_products_product ON pos_menu_products(product_id);

CREATE TABLE IF NOT EXISTS pos_products (
    id               TEXT PRIMARY KEY,
    name             TEXT NOT NULL,
    description      TEXT,
    is_active        INTEGER NOT NULL DEFAULT 1,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS pos_product_skus (
    id               TEXT PRIMARY KEY,
    product_id       TEXT NOT NULL,
    name             TEXT,
    sku              TEXT,
    selling_price    INTEGER NOT NULL DEFAULT 0,
    is_active        INTEGER NOT NULL DEFAULT 1,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_pos_product_skus_product ON pos_product_skus(product_id);
