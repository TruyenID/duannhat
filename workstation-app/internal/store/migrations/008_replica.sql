-- Cloud-owned tables mirrored locally (sync DOWN) so LAN clients
-- (kiosk, customer-web, TMS) can read without a Cloud round-trip.
-- Workstation never writes to these except through SyncPuller transactions.

-- zones — TMS zone list. Replaced wholesale every 60 s.
CREATE TABLE IF NOT EXISTS zones (
    id               TEXT PRIMARY KEY,
    name             TEXT NOT NULL,
    sort_order       INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_zones_sort ON zones(sort_order);

-- tables — physical tables with QR tokens for customer ordering.
-- qr_token is the secret embedded in each table's QR code; it must be
-- UNIQUE so the customer-web lookup /tables?qr_token=... always returns 0-1 rows.
CREATE TABLE IF NOT EXISTS tables (
    id               TEXT PRIMARY KEY,
    qr_token         TEXT UNIQUE,
    name             TEXT NOT NULL,
    zone_id          TEXT,
    status           TEXT NOT NULL DEFAULT 'available',
    capacity         INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_tables_zone ON tables(zone_id);
CREATE INDEX IF NOT EXISTS idx_tables_qr   ON tables(qr_token);

-- auth_token_cache — caches Cloud /verify results for LAN device tokens
-- (kiosk, TMS, KDS) and SSO user tokens (pos-web) for up to 5 minutes.
-- On Cloud outage, stale entries are accepted with a degraded-mode flag.
--
-- token_hash : SHA-256 hex of the plain Bearer token (plain is never stored).
-- identity_type : 'device' | 'user'
-- device_* fields : populated for device tokens, NULL for SSO.
-- user_* fields   : populated for SSO tokens, NULL for device tokens.
CREATE TABLE IF NOT EXISTS auth_token_cache (
    token_hash    TEXT PRIMARY KEY,
    identity_type TEXT NOT NULL DEFAULT 'device',
    device_id     TEXT,
    device_type   TEXT,
    user_id       TEXT,
    user_name     TEXT,
    user_email    TEXT,
    branch_id     TEXT,
    device_info   TEXT,
    verified_at   TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at    TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_auth_cache_device  ON auth_token_cache(device_id);
CREATE INDEX IF NOT EXISTS idx_auth_cache_user    ON auth_token_cache(user_id);
CREATE INDEX IF NOT EXISTS idx_auth_cache_expires ON auth_token_cache(expires_at);

-- shop_settings — Cloud branch settings flattened into key-value rows.
-- Distinct from `settings` (workstation-local config).
-- Pulled every 60 s via SyncPuller.PullBranch.
CREATE TABLE IF NOT EXISTS shop_settings (
    key              TEXT PRIMARY KEY,
    value            TEXT NOT NULL,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- inventory_lots — material stock levels from Cloud /workstation/lots.
-- Read-only mirror; Cloud owns all stock movements.
-- Pulled every 5 min via SyncPuller.PullLots.
CREATE TABLE IF NOT EXISTS inventory_lots (
    id               TEXT PRIMARY KEY,
    material_id      TEXT NOT NULL,
    material_name    TEXT,
    warehouse_id     TEXT,
    warehouse_name   TEXT,
    quantity         REAL NOT NULL DEFAULT 0,
    unit             TEXT,
    expires_at       TEXT,
    status           TEXT,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_inventory_lots_material ON inventory_lots(material_id);
CREATE INDEX IF NOT EXISTS idx_inventory_lots_expires  ON inventory_lots(expires_at);
