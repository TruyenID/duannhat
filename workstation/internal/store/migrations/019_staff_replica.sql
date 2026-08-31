-- ============================================================================
-- Staff replica — LAN-offline /pos/staff.
--
-- Pos-web's "Open Shift" form loads a cashier dropdown from /pos/staff.
-- Previously cloud-proxied; without LAN coverage, a workstation in a
-- store with a flaky uplink could leave staff unable to even open a
-- shift. This adds the minimum mirror so the dropdown stays usable
-- offline.
--
-- Sync DOWN happens in the slow loop alongside the other catalog
-- replicas. Replace-all is safe because the row set is small (<50 staff
-- per shop) and Cloud is the only writer.
-- ============================================================================

CREATE TABLE IF NOT EXISTS staff (
    id               TEXT PRIMARY KEY,
    full_name        TEXT NOT NULL,
    role_slug        TEXT,
    is_active        INTEGER NOT NULL DEFAULT 1,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_staff_active ON staff(is_active, full_name);
