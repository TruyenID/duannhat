-- #1080 — the LAN mirror of effective payment options was a single flat
-- snapshot: no device_id, resolved as channel=workstation, served verbatim to
-- every POS/kiosk terminal. That silently dropped BOTH gates — a terminal's
-- DevicePaymentOption `disabled` had no effect on LAN reads, and the
-- capability layer matched the wrong channel.
--
-- Rebuild the table with (device_id, channel) scoping. Dropping is safe: the
-- table is a replace-all mirror repopulated on every pull tick — it holds no
-- locally-authored data. `device_id IS NULL` rows are the branch-level
-- fallback per channel; rows with channel='' are legacy pre-matrix data
-- (written only if the workstation runs against an old Cloud).

DROP TABLE IF EXISTS effective_payment_options;

CREATE TABLE effective_payment_options (
    row_id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id               TEXT,                       -- NULL = branch-level fallback
    channel                 TEXT NOT NULL DEFAULT '',   -- 'pos' | 'kiosk' | '' (legacy)
    id                      TEXT NOT NULL,              -- catalog option id
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

CREATE INDEX IF NOT EXISTS idx_effective_payment_options_scope
    ON effective_payment_options(channel, device_id, effective, sort_order);
CREATE INDEX IF NOT EXISTS idx_effective_payment_options_option
    ON effective_payment_options(id);
