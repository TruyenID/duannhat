-- Plan-047 T7 — workstation replica of external peripheral devices (printers,
-- payment terminal, coin changer, pos/workstation/kiosk identities).
--
-- Cloud remains source-of-truth for peripheral definitions. The workstation
-- needs device definitions to validate printer/payment operations offline.
-- Secrets are never stored locally.

CREATE TABLE IF NOT EXISTS peripheral_devices (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    type TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    metadata TEXT,
    registered_by_device_id TEXT,
    branch_id TEXT,
    organization_id TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    local_synced_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_peripheral_devices_organization
    ON peripheral_devices (organization_id);

CREATE INDEX IF NOT EXISTS idx_peripheral_devices_branch
    ON peripheral_devices (branch_id);

CREATE INDEX IF NOT EXISTS idx_peripheral_devices_type
    ON peripheral_devices (type);

