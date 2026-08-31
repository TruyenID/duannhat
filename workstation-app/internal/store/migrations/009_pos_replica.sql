-- POS-namespace Cloud replicas — sync DOWN so pos-web can work offline-first.

-- customers — enough for "find by phone" + outstanding-balance display.
-- local_pending_sync = 1 marks rows created locally by pos-web that have not
-- yet been ACK'd by Cloud; the next pull DOWN may replace them with the
-- canonical Cloud row (canonical id).
CREATE TABLE IF NOT EXISTS customers (
    id                 TEXT PRIMARY KEY,
    first_name         TEXT NOT NULL,
    last_name          TEXT,
    full_name          TEXT NOT NULL,
    phone              TEXT,
    email              TEXT,
    organization_id    TEXT,
    brand_id           TEXT,
    branch_id          TEXT,
    local_pending_sync INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at   TEXT,
    local_synced_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers(phone);

-- coupons — local replica sufficient for apply/release flows.
-- Cloud remains the authority on issuance and usage caps; sync UP fires on
-- apply so Cloud can hard-reject if usage_limit was already exhausted.
--
-- discount_type : 'flat' | 'percent'
-- discount_value: flat=amount in yen; percent=basis-points/100 (e.g. 10 = 10%)
-- stacking_mode : 'exclusive' | 'stack'
CREATE TABLE IF NOT EXISTS coupons (
    id                 TEXT PRIMARY KEY,
    code               TEXT NOT NULL UNIQUE,
    name               TEXT NOT NULL,
    discount_type      TEXT NOT NULL CHECK (discount_type IN ('flat', 'percent')),
    discount_value     INTEGER NOT NULL,
    min_order_amount   INTEGER NOT NULL DEFAULT 0,
    max_discount       INTEGER,              -- cap for percent type; NULL = no cap
    valid_from         TEXT,
    valid_until        TEXT,
    usage_limit_total  INTEGER,             -- NULL = unlimited
    times_used         INTEGER NOT NULL DEFAULT 0,
    status             TEXT NOT NULL DEFAULT 'active',       -- active | paused
    stacking_mode      TEXT NOT NULL DEFAULT 'exclusive',    -- exclusive | stack
    cloud_updated_at   TEXT,
    local_synced_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_coupons_code   ON coupons(code);
CREATE INDEX IF NOT EXISTS idx_coupons_status ON coupons(status, valid_until);
