-- Offline-first peripheral config. The workstation can now create/edit/delete
-- peripherals (P400 / 釣銭機 / printer identities) LOCALLY with no network, then
-- sync UP to Cloud when online. Two flags track a row's sync state:
--
--   pending_sync   = 1  local create/edit not yet confirmed on Cloud
--   pending_delete = 1  tombstone: deleted locally, delete-on-Cloud pending
--
-- PullPeripheralDevices preserves rows with either flag set (never overwrites a
-- local edit or resurrects a local delete); the sync engine clears the flag once
-- Cloud acknowledges (peripheral.upsert / peripheral.delete ops).

ALTER TABLE peripheral_devices ADD COLUMN pending_sync INTEGER NOT NULL DEFAULT 0;
ALTER TABLE peripheral_devices ADD COLUMN pending_delete INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_peripheral_devices_pending
    ON peripheral_devices (pending_sync, pending_delete);
