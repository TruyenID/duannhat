-- ============================================================================
-- Defensive: drop UNIQUE index on coupons.code, replace with plain index.
--
-- Why: PullCoupons does UPSERT ON CONFLICT(id) DO UPDATE. When Cloud
-- emits a new coupon row whose code happens to equal a stale local row
-- (e.g. an old soft-deleted coupon that lingered on the device because
-- it had been redeemed before Cloud removed it), the INSERT path of the
-- upsert trips the UNIQUE(code) constraint BEFORE ON CONFLICT(id) can
-- fire — because the conflict resolver picks the FIRST constraint
-- violation it sees, and UNIQUE(code) is what blocks the row.
--
-- Result on production: the whole sync transaction aborts, NONE of the
-- new coupons land on the device, and the cashier sees "tạo coupon mới
-- không sync xuống". The DB log shows the abort but operators rarely
-- watch it.
--
-- Backend already enforces unique-per-organization at the API layer, so
-- the local UNIQUE is redundant defensive armor that's now turned into
-- a footgun. Keep a plain INDEX for the `WHERE code = ?` lookup speed in
-- CouponEngine.findByCode.
-- ============================================================================

DROP INDEX IF EXISTS idx_coupons_code;
CREATE INDEX idx_coupons_code ON coupons(code);
