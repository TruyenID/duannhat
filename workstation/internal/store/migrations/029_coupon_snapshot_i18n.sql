-- ============================================================================
-- Phase C — Snapshot + i18n + audit parity (Plan: workstation full
-- coupon/promotion mirror).
--
-- coupon_redemptions:
--   * coupon_snapshot      — JSON freeze of coupon state at apply time.
--                             Mirrors backend's CouponRedemption.coupon_snapshot
--                             so audit + Cloud-side reconciliation can survive
--                             a coupon edit between apply and sync UP.
--   * redeemed_via         — 'pos' | 'kiosk' | 'customer' (matches backend
--                             enum). Defaults to 'pos' since workstation's
--                             LAN apply path is the till.
--   * redeemed_by_user_id  — null when the device-token (anonymous till)
--                             path is taken; populated when an SSO user
--                             is in context (manual override flows).
--
-- coupon_translations:
--   Mirrors backend's coupon_translations 1:1 — workstation hydrates
--   localised name/description so a Vietnamese-locale till displays the
--   coupon under the cashier's locale even when LAN-offline.
--
-- menu_promotion_translations:
--   Same as coupons, but for menu promotions. Keeps receipt/HH-badge
--   labels coherent with the device's locale.
-- ============================================================================

ALTER TABLE coupon_redemptions ADD COLUMN coupon_snapshot      TEXT;          -- JSON blob
ALTER TABLE coupon_redemptions ADD COLUMN redeemed_via         TEXT NOT NULL DEFAULT 'pos';
ALTER TABLE coupon_redemptions ADD COLUMN redeemed_by_user_id  TEXT;

-- ─── Coupon i18n ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS coupon_translations (
    id               TEXT PRIMARY KEY,
    coupon_id        TEXT NOT NULL,
    locale           TEXT NOT NULL,
    name             TEXT NOT NULL,
    description      TEXT,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    UNIQUE (coupon_id, locale)
);

CREATE INDEX IF NOT EXISTS idx_coupon_translations_coupon
    ON coupon_translations(coupon_id);

-- ─── Menu promotion i18n ───────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS menu_promotion_translations (
    id                TEXT PRIMARY KEY,
    menu_promotion_id TEXT NOT NULL,
    locale            TEXT NOT NULL,
    name              TEXT NOT NULL,
    description       TEXT,
    cloud_updated_at  TEXT,
    local_synced_at   TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (menu_promotion_id) REFERENCES menu_promotions(id) ON DELETE CASCADE,
    UNIQUE (menu_promotion_id, locale)
);

CREATE INDEX IF NOT EXISTS idx_menu_promotion_translations_promo
    ON menu_promotion_translations(menu_promotion_id);
