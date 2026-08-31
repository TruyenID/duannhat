-- ESC/POS printer hardware registry.
-- Workstation-local only — Cloud has no knowledge of physical printers.
-- Named `printers` (not `devices`) to avoid collision with the omnify-
-- generated `devices` table that tracks Cloud-paired kiosk/TMS/workstation
-- device registrations.
CREATE TABLE IF NOT EXISTS printers (
    id              TEXT PRIMARY KEY,
    type            TEXT NOT NULL,            -- 'receipt' | 'kitchen'
    name            TEXT NOT NULL,
    connection_type TEXT NOT NULL,            -- 'usb' | 'tcp'
    address         TEXT,                     -- IP:port for TCP, NULL for USB
    config          TEXT,                     -- JSON blob (paper width, encoding…)
    is_active       INTEGER NOT NULL DEFAULT 1,
    last_seen_at    TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
