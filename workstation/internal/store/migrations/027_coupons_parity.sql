-- ============================================================================
-- Coupon schema parity with backend (Plan: workstation full coupon mirror).
--
-- Pre-027 workstation coupons table used legacy names:
--   min_order_amount     ← backend.min_order_subtotal
--   max_discount         ← backend.max_discount_cap
--   per_customer_limit   ← backend.usage_limit_per_customer
-- and was missing:
--   description, brand_id, organization_id, deleted_at, coupon_branches pivot
--
-- This migration realigns workstation to backend column names so:
--   * sync_pull_pos.go::PullCoupons can ingest the controller's payload
--     verbatim (no rename map),
--   * grep across backend ↔ workstation for "min_order_subtotal" finds
--     both,
--   * future audits/snapshots emit backend-native shapes.
--
-- Branch whitelist (coupon_branches pivot): when a coupon has 1+ rows in
-- this pivot, only orders on those branches can apply it. Empty pivot =
-- all branches (matches CouponService::validateBranch). Workstation
-- enforces this locally so LAN-offline staff can't apply a coupon
-- intended for a sibling branch.
--
-- Computed status (scheduled | active | expired | exhausted) is derived
-- in Go at read-time — NOT stored — so the local replica doesn't need a
-- cron to flip the field as time passes. Migration just keeps the raw
-- `status` (draft | paused | active) column.
--
-- SQLite has no ALTER COLUMN / RENAME COLUMN-in-place workflow for tables
-- with existing CHECK / indexes — recreate-table + copy + rename is the
-- only path. Migration runner wraps each .sql in a transaction so no
-- BEGIN/COMMIT here.
-- ============================================================================

CREATE TABLE coupons_v2 (
    id                        TEXT PRIMARY KEY,
    code                      TEXT NOT NULL,
    name                      TEXT NOT NULL,
    description               TEXT,
    discount_type             TEXT NOT NULL,                       -- 'fixed' | 'percent'
    discount_value            INTEGER NOT NULL,
    min_order_subtotal        INTEGER NOT NULL DEFAULT 0,          -- was min_order_amount
    max_discount_cap          INTEGER,                              -- was max_discount; NULL = no cap
    valid_from                TEXT,
    valid_until               TEXT,
    usage_limit_total         INTEGER,                              -- NULL = unlimited
    times_used                INTEGER NOT NULL DEFAULT 0,
    usage_limit_per_customer  INTEGER,                              -- was per_customer_limit
    status                    TEXT NOT NULL DEFAULT 'active',       -- 'draft' | 'active' | 'paused'
    stacking_mode             TEXT NOT NULL DEFAULT 'exclusive',    -- workstation-side: 'exclusive' | 'stack'
    exclusive_with_promotions INTEGER NOT NULL DEFAULT 0,
    applies_to                TEXT NOT NULL DEFAULT 'order',        -- 'order' | 'item' (v1: order only)
    brand_id                  TEXT,
    organization_id           TEXT,
    deleted_at                TEXT,                                  -- soft-delete (Cloud sends only non-deleted rows; column present for round-trip parity)
    cloud_updated_at          TEXT,
    local_synced_at           TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Copy legacy rows. COALESCE on fields the v1 schema didn't carry. UNIQUE
-- constraint on `code` is enforced via the index below (not inline) so
-- the index name survives the table rename.
INSERT INTO coupons_v2 (
    id, code, name,
    discount_type, discount_value,
    min_order_subtotal, max_discount_cap,
    valid_from, valid_until,
    usage_limit_total, times_used, usage_limit_per_customer,
    status, stacking_mode, exclusive_with_promotions, applies_to,
    cloud_updated_at, local_synced_at
)
SELECT
    id, code, name,
    discount_type, discount_value,
    min_order_amount, max_discount,
    valid_from, valid_until,
    usage_limit_total, times_used, per_customer_limit,
    status, stacking_mode, exclusive_with_promotions, applies_to,
    cloud_updated_at, local_synced_at
FROM coupons;

DROP TABLE coupons;
ALTER TABLE coupons_v2 RENAME TO coupons;

CREATE UNIQUE INDEX idx_coupons_code            ON coupons(code);
CREATE INDEX        idx_coupons_status          ON coupons(status, valid_until);
CREATE INDEX        idx_coupons_org_brand       ON coupons(organization_id, brand_id);

-- ─── Branch whitelist pivot ────────────────────────────────────────────────
--
-- Mirrors backend's coupon_branch table. Composite PK (coupon_id, branch_id)
-- — Cloud's pivot has its own `id` PK plus a UNIQUE(coupon_id, branch_id);
-- workstation reads it as a pure relation so the simpler composite PK is
-- enough.
CREATE TABLE IF NOT EXISTS coupon_branches (
    coupon_id        TEXT NOT NULL,
    branch_id        TEXT NOT NULL,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (coupon_id, branch_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_coupon_branches_branch
    ON coupon_branches(branch_id);
