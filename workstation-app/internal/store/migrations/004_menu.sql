-- Menu cache pulled DOWN from Cloud every 60 s via SyncPuller.PullMenu.
--
-- Columns:
--   cloud_id        : Cloud menu-product UUID (NULL for local-only items).
--   sku_id          : Canonical product_sku_id from Cloud — used by createItem()
--                     to resolve handy orders where the caller sends product_sku_id
--                     instead of the local menu_items.id.
--   printer_group   : Workstation-local — which kitchen station receives the ticket.
--                     NOT overwritten by PullMenu so staff config survives re-syncs.
--   name_ja         : Workstation-local Japanese display name override.
--   discount_price  : Pre-computed discounted price when active_promotion is set.
--   discount_pct    : Promotion discount percent (0-100). NULL = no active promotion.
--
-- Two pull paths write here:
--   PullMenu      — kiosk-flat shape (/workstation/menu). Sets sku_id IS NULL.
--   PullHandyMenu — handy-shaped SKU rows (/workstation/menu/handy). Sets sku_id.
-- The two sets are partitioned by (cloud_id IS NOT NULL AND sku_id IS NULL) vs
-- (cloud_id IS NOT NULL AND sku_id IS NOT NULL) so each puller deactivates only
-- its own slice without touching the other's rows.
CREATE TABLE IF NOT EXISTS menu_items (
    id               TEXT PRIMARY KEY,
    cloud_id         TEXT UNIQUE,
    sku_id           TEXT,              -- product_sku_id; NULL for kiosk-flat rows
    name             TEXT NOT NULL,
    name_ja          TEXT,
    description      TEXT,
    category         TEXT,
    price            INTEGER NOT NULL DEFAULT 0,
    discount_price   INTEGER,          -- NULL = no active promotion
    discount_pct     REAL,             -- 0-100; NULL = no active promotion
    printer_group    TEXT NOT NULL DEFAULT 'kitchen',
    sort_order       INTEGER NOT NULL DEFAULT 0,
    is_active        INTEGER NOT NULL DEFAULT 1,
    image_url        TEXT,
    cloud_updated_at TEXT,
    local_updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_menu_items_category ON menu_items(category);
CREATE INDEX IF NOT EXISTS idx_menu_items_active   ON menu_items(is_active);
CREATE INDEX IF NOT EXISTS idx_menu_items_sku_id   ON menu_items(sku_id) WHERE sku_id IS NOT NULL;

-- Per-sync scalars from the Cloud menu payload (cart timeout, menu identity).
-- Singleton row: id = 'current'. Replaced wholesale on every successful PullMenu.
CREATE TABLE IF NOT EXISTS menu_meta (
    id                   TEXT PRIMARY KEY DEFAULT 'current',
    cloud_menu_id        TEXT,
    cloud_menu_name      TEXT,
    cart_timeout_minutes INTEGER,
    cart_deadline_iso    TEXT,
    synced_at            TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Raw JSON cache of the full handy-shaped menu (ShopMenuByDayResource[]).
-- Singleton row: id = 'current'. Invalidated when day_of_week changes at midnight.
-- Served as-is by the /handy/menu handler without re-serialisation.
CREATE TABLE IF NOT EXISTS handy_menu_cache (
    id          TEXT PRIMARY KEY DEFAULT 'current',
    day_of_week INTEGER NOT NULL,   -- 0=Sun … 6=Sat
    payload     TEXT NOT NULL,      -- raw JSON: ShopMenuByDayResource[]
    fetched_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Menu → day-of-week schedule mirror (sync DOWN from Cloud).
-- pos-web reads GET /pos/menus/by-day/{dow} from this table.
CREATE TABLE IF NOT EXISTS menu_schedules (
    id               TEXT PRIMARY KEY,
    menu_id          TEXT NOT NULL,
    day_of_week      INTEGER NOT NULL CHECK (day_of_week BETWEEN 0 AND 6),
    is_active        INTEGER NOT NULL DEFAULT 1,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_menu_schedules_dow  ON menu_schedules(day_of_week, is_active);
CREATE INDEX IF NOT EXISTS idx_menu_schedules_menu ON menu_schedules(menu_id);
