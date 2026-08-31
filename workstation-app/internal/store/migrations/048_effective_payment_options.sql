-- Plan-047 T6.3 — device-effective payment policy projection (non-secret).
--
-- Cloud publishes revision + snapshot_hash; workstation mirrors option rows
-- for LAN POS/kiosk offline checkout. No credentials or secret references.

CREATE TABLE IF NOT EXISTS payment_policy_snapshot (
    id                  INTEGER PRIMARY KEY CHECK (id = 1),
    revision            INTEGER NOT NULL DEFAULT 0,
    snapshot_hash       TEXT NOT NULL DEFAULT '',
    ownership_revision  TEXT,
    published_at        TEXT,
    local_synced_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT OR IGNORE INTO payment_policy_snapshot (id, revision, snapshot_hash)
VALUES (1, 0, '');

CREATE TABLE IF NOT EXISTS effective_payment_options (
    id                      TEXT PRIMARY KEY,
    display_name            TEXT NOT NULL,
    provider                TEXT NOT NULL,
    rail                    TEXT NOT NULL,
    effective               INTEGER NOT NULL DEFAULT 0,
    source                  TEXT,
    reason                  TEXT,
    error_code              TEXT,
    connection_id           TEXT,
    connection_option_id    TEXT,
    shop_option_id          TEXT,
    owner_scope             TEXT,
    shop_preference         TEXT,
    device_preference       TEXT,
    capabilities_json       TEXT NOT NULL DEFAULT '{}',
    connection_display_json TEXT NOT NULL DEFAULT '{}',
    sort_order              INTEGER NOT NULL DEFAULT 0,
    local_synced_at         TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_effective_payment_options_effective
    ON effective_payment_options(effective, sort_order);

-- Plan-047 T6.5 — immutable policy identity stamped at payment capture time.
ALTER TABLE payments ADD COLUMN payment_option_id TEXT;
ALTER TABLE payments ADD COLUMN policy_revision INTEGER;
ALTER TABLE payments ADD COLUMN connection_id TEXT;
ALTER TABLE payments ADD COLUMN connection_option_id TEXT;
ALTER TABLE payments ADD COLUMN attempt_idempotency_key TEXT;

CREATE INDEX IF NOT EXISTS idx_payments_policy_option ON payments(payment_option_id);
