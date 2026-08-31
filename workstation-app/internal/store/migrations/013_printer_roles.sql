-- Plan 07 — Device setup động: máy in ↔ NHIỀU tác vụ (multi-role).
--
-- 1. Add a `roles` JSON column to printers. A printer now carries a LIST of
--    roles (e.g. ["kitchen_printer","receipt_printer"]) instead of a single
--    fixed `type`. The legacy `type` column is kept for back-compat but is no
--    longer the resolution key.
-- 2. Backfill `roles` for any existing printer from its `type`.
-- 3. Migrate the legacy fixed IP settings (kitchen_printer_ip / hold_printer_ip
--    / bar_printer_ip) into real printer records — one device per distinct IP,
--    rolling up every role that pointed at the same IP into one multi-role
--    device. Then drop the legacy setting keys so the fallback is gone for good.
--
-- This migration runs exactly once (guarded by schema_migrations), so it is
-- inherently idempotent for a given DB.
--
-- KNOWN QUIRKS (intentional, not bugs):
--  * The `type` of a merged device = the alphabetically-first role at that IP
--    (ORDER BY role → "hold_printer" wins over "kitchen_printer"). `type` is a
--    legacy back-compat column only; print resolution goes through `roles`
--    (Manager.GetPrinterByRole), so the primary `type` value never affects
--    which printer a job routes to.
--  * Port normalisation uses `instr(ip, ':') = 0`, so it only appends :9100 to
--    an IP that has NO colon. A bare IPv6 literal (multiple colons) would be
--    left without a port — acceptable because the legacy *_printer_ip settings
--    only ever held IPv4 host[:port] values.

ALTER TABLE printers ADD COLUMN roles TEXT;

-- Backfill existing printers: roles = ["<type>"].
UPDATE printers
SET roles = json_array(type)
WHERE roles IS NULL OR roles = '';

-- Stage the legacy IP settings as (role, ip) pairs, ignoring blanks.
CREATE TEMP TABLE _legacy_printer_ips AS
SELECT 'kitchen_printer' AS role, trim(value) AS ip
FROM settings WHERE key = 'kitchen_printer_ip' AND trim(value) <> ''
UNION ALL
SELECT 'hold_printer', trim(value)
FROM settings WHERE key = 'hold_printer_ip' AND trim(value) <> ''
UNION ALL
SELECT 'bar_printer', trim(value)
FROM settings WHERE key = 'bar_printer_ip' AND trim(value) <> '';

-- Normalise: append default ESC/POS port 9100 when the IP has no ':port'.
UPDATE _legacy_printer_ips
SET ip = ip || ':9100'
WHERE instr(ip, ':') = 0;

-- One device per distinct IP, rolling every role at that IP into a JSON array.
-- Skip an IP that already belongs to a registered printer (idempotent re-merge
-- guard if this DB somehow already has the device).
INSERT INTO printers (id, type, name, connection_type, address, config, roles, is_active, created_at, updated_at)
SELECT
    lower(hex(randomblob(16))),
    (SELECT role FROM _legacy_printer_ips r2 WHERE r2.ip = r.ip ORDER BY role LIMIT 1),
    'Migrated printer (' || r.ip || ')',
    'network',
    r.ip,
    json_object('paper_width', 80, 'cut_type', 'full'),
    (SELECT json_group_array(role) FROM (
        SELECT DISTINCT role FROM _legacy_printer_ips r3 WHERE r3.ip = r.ip ORDER BY role
    )),
    1,
    datetime('now'),
    datetime('now')
FROM (SELECT DISTINCT ip FROM _legacy_printer_ips) r
WHERE NOT EXISTS (
    SELECT 1 FROM printers p WHERE p.address = r.ip AND p.is_active = 1
);

DROP TABLE _legacy_printer_ips;

-- Fallback is gone — purge the legacy setting keys.
DELETE FROM settings WHERE key IN ('kitchen_printer_ip', 'hold_printer_ip', 'bar_printer_ip');
