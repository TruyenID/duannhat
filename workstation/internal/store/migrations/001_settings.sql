-- Workstation-local key-value config store.
-- Holds device auth (device_token, device_id, device_name, device_type,
-- cloud_api_url) and runtime cursors (sync.customer_orders.last_pulled …).
-- Distinct from `shop_settings` which mirrors Cloud-owned branch settings.
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);

-- Seed device auth keys so code can always SELECT without ErrNoRows.
INSERT OR IGNORE INTO settings (key, value) VALUES ('device_token', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('device_name', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('device_type', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('device_id', '');

-- Daily counter — resets each calendar day. Used by LocalCodeGenerator
-- to stamp WS-YYMMDD-NNNN order codes offline without a Cloud round-trip.
CREATE TABLE IF NOT EXISTS order_counters (
    date    TEXT PRIMARY KEY,   -- YYYY-MM-DD
    counter INTEGER NOT NULL DEFAULT 0
);
