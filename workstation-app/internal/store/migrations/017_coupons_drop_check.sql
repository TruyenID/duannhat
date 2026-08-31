-- ============================================================================
-- Drop legacy CHECK on coupons.discount_type.
--
-- Migration 009 baked `discount_type IN ('flat', 'percent')` into the table
-- when workstation kept its own naming. Cloud's CouponDiscountTypeEnum is
-- `'fixed' | 'percent'` — so PullCoupons (sync_pull_pos.go) crashes the
-- INSERT every time a Cloud row carries `'fixed'`. The controller already
-- accepts both spellings; this migration aligns the storage layer.
--
-- SQLite has no ALTER COLUMN / DROP CHECK — the only correct path is
-- recreate-table + copy + rename. The migration runner wraps each .sql in
-- a transaction so we don't add BEGIN/COMMIT here (would conflict).
-- ============================================================================

CREATE TABLE coupons_new (
    id                        TEXT PRIMARY KEY,
    code                      TEXT NOT NULL UNIQUE,
    name                      TEXT NOT NULL,
    discount_type             TEXT NOT NULL,            -- NO CHECK: 'fixed' | 'flat' | 'percent'
    discount_value            INTEGER NOT NULL,
    min_order_amount          INTEGER NOT NULL DEFAULT 0,
    max_discount              INTEGER,
    valid_from                TEXT,
    valid_until               TEXT,
    usage_limit_total         INTEGER,
    times_used                INTEGER NOT NULL DEFAULT 0,
    status                    TEXT NOT NULL DEFAULT 'active',
    stacking_mode             TEXT NOT NULL DEFAULT 'exclusive',
    cloud_updated_at          TEXT,
    local_synced_at           TEXT NOT NULL DEFAULT (datetime('now')),
    -- Plan-LAN-offline extensions (migration 016).
    exclusive_with_promotions INTEGER NOT NULL DEFAULT 0,
    per_customer_limit        INTEGER,
    applies_to                TEXT NOT NULL DEFAULT 'order'
);

INSERT INTO coupons_new
    SELECT id, code, name, discount_type, discount_value, min_order_amount,
           max_discount, valid_from, valid_until, usage_limit_total,
           times_used, status, stacking_mode, cloud_updated_at, local_synced_at,
           exclusive_with_promotions, per_customer_limit, applies_to
    FROM coupons;

DROP TABLE coupons;
ALTER TABLE coupons_new RENAME TO coupons;

CREATE INDEX IF NOT EXISTS idx_coupons_code   ON coupons(code);
CREATE INDEX IF NOT EXISTS idx_coupons_status ON coupons(status, valid_until);
