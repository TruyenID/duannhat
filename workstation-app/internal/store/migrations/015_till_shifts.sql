-- ============================================================================
-- Cashier shift — LAN-offline package.
--
-- Workstation owns shift lifecycle locally so cashiers can open / record
-- cash events / close even when Cloud is unreachable. Sync UP fires
-- async via the sync_queue with shift.* operations against
-- /api/v1/workstation/till/* on Cloud (device-token auth, accepts the
-- workstation-supplied row id verbatim).
--
-- Tables:
--   tills                          Sync DOWN. Per-branch till identity.
--   denominations                  Sync DOWN. Cash-counter master.
--   till_tender_categories         Sync DOWN. UI groupings for close screen.
--   till_tender_types              Sync DOWN. Tender devices under each cat.
--   till_sessions                  SOT. Mở-ca / kết-ca lifecycle.
--   till_cash_events               SOT. Rút két (paid_in / paid_out / …).
--   till_cash_denomination_counts  SOT. Snapshot mệnh giá ở open + close.
--   till_settlement_tender_details SOT. Per-tender declared/expected at close.
-- ============================================================================

-- Per-branch till identity. Single MAIN till per branch in v1, but the row
-- carries cashier-shift policy (tolerance, currency, current_session_id).
CREATE TABLE IF NOT EXISTS tills (
    id                          TEXT PRIMARY KEY,
    branch_id                   TEXT NOT NULL,
    code                        TEXT NOT NULL DEFAULT 'MAIN',
    default_currency_code       TEXT NOT NULL DEFAULT 'JPY',
    variance_tolerance_amount   REAL NOT NULL DEFAULT 0,
    -- Mirror of Cloud's tills.current_session_id, kept eventually-consistent
    -- with the local till_sessions table. Cleared on close/abandon to make
    -- the "one open shift per till" guard a cheap O(1) check.
    current_session_id          TEXT,
    cloud_updated_at            TEXT,
    local_synced_at             TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_tills_branch ON tills(branch_id);

-- Cash-counter denominations (banknote + coin), per currency. Sync DOWN.
CREATE TABLE IF NOT EXISTS denominations (
    id               TEXT PRIMARY KEY,
    currency_code    TEXT NOT NULL,
    value            REAL NOT NULL,
    kind             TEXT NOT NULL,                 -- 'note' | 'coin'
    label            TEXT,
    sort_order       INTEGER NOT NULL DEFAULT 0,
    is_active        INTEGER NOT NULL DEFAULT 1,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_denominations_currency_active
    ON denominations(currency_code, is_active, sort_order);

-- Tender category (Thẻ / QR / Tiền điện tử / shop-defined). Sync DOWN.
CREATE TABLE IF NOT EXISTS till_tender_categories (
    id               TEXT PRIMARY KEY,
    key              TEXT NOT NULL,
    name             TEXT NOT NULL,
    sort_order       INTEGER NOT NULL DEFAULT 0,
    is_system        INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_till_tender_categories_key
    ON till_tender_categories(key);

-- Tender device under a category (PayPay / VISA / Voucher 50k …). Sync DOWN.
CREATE TABLE IF NOT EXISTS till_tender_types (
    id                       TEXT PRIMARY KEY,
    tender_key               TEXT NOT NULL,
    name                     TEXT NOT NULL,
    category                 TEXT NOT NULL,
    parent_tender_key        TEXT,
    currency_code            TEXT NOT NULL DEFAULT 'JPY',
    payment_method_code      TEXT,
    is_expected_anchor       INTEGER NOT NULL DEFAULT 0,
    requires_terminal_total  INTEGER NOT NULL DEFAULT 0,
    sort_order               INTEGER NOT NULL DEFAULT 0,
    is_active                INTEGER NOT NULL DEFAULT 1,
    cloud_updated_at         TEXT,
    local_synced_at          TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_till_tender_types_key
    ON till_tender_types(tender_key);
CREATE INDEX IF NOT EXISTS idx_till_tender_types_cat
    ON till_tender_types(category, sort_order);

-- ─── SOT tables (workstation owns, sync UP) ──────────────────────────────────

CREATE TABLE IF NOT EXISTS till_sessions (
    id                       TEXT PRIMARY KEY,
    cloud_id                 TEXT,
    session_code             TEXT NOT NULL,
    status                   TEXT NOT NULL,             -- open|closing|settled|abandoned
    business_date            TEXT NOT NULL,             -- YYYY-MM-DD
    default_currency_code    TEXT NOT NULL DEFAULT 'JPY',
    opening_float_amount     REAL NOT NULL DEFAULT 0,
    opening_note             TEXT,
    opened_by_id             TEXT,
    opener_name              TEXT,
    opened_at                TEXT NOT NULL,
    closed_at                TEXT,
    closed_by_id             TEXT,
    abandon_reason           TEXT,
    closing_note             TEXT,
    counted_cash             REAL,
    cash_variance            REAL,
    till_id                  TEXT NOT NULL,
    branch_id                TEXT NOT NULL,
    created_at               TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at               TEXT NOT NULL DEFAULT (datetime('now')),
    synced_at                TEXT
);

CREATE INDEX IF NOT EXISTS idx_till_sessions_till_status
    ON till_sessions(till_id, status);
CREATE INDEX IF NOT EXISTS idx_till_sessions_sync
    ON till_sessions(synced_at) WHERE synced_at IS NULL;

CREATE TABLE IF NOT EXISTS till_cash_events (
    id                TEXT PRIMARY KEY,
    cloud_id          TEXT,
    session_id        TEXT NOT NULL,
    event_type        TEXT NOT NULL,                   -- paid_in|paid_out|loan_from_safe|pickup_to_safe
    amount            REAL NOT NULL,
    currency_code     TEXT NOT NULL DEFAULT 'JPY',
    reason            TEXT,
    reference_no      TEXT,
    performed_by_id   TEXT,
    occurred_at       TEXT NOT NULL,
    created_at        TEXT NOT NULL DEFAULT (datetime('now')),
    synced_at         TEXT
);

CREATE INDEX IF NOT EXISTS idx_till_cash_events_session
    ON till_cash_events(session_id);
CREATE INDEX IF NOT EXISTS idx_till_cash_events_sync
    ON till_cash_events(synced_at) WHERE synced_at IS NULL;

-- Snapshot of denomination counts at OPEN and CLOSE. `phase` distinguishes.
CREATE TABLE IF NOT EXISTS till_cash_denomination_counts (
    id              TEXT PRIMARY KEY,
    session_id      TEXT NOT NULL,
    denomination_id TEXT NOT NULL,
    phase           TEXT NOT NULL,                    -- 'opening' | 'closing'
    quantity        INTEGER NOT NULL,
    subtotal_amount REAL NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_till_counts_session_phase
    ON till_cash_denomination_counts(session_id, phase);

-- Per-tender declared/expected at close. Snapshot — not editable after settle.
CREATE TABLE IF NOT EXISTS till_settlement_tender_details (
    id                       TEXT PRIMARY KEY,
    session_id               TEXT NOT NULL,
    tender_key               TEXT NOT NULL,
    category                 TEXT NOT NULL,
    currency_code            TEXT NOT NULL DEFAULT 'JPY',
    expected_amount          REAL,
    declared_gross_amount    REAL NOT NULL DEFAULT 0,
    declared_cancel_amount   REAL NOT NULL DEFAULT 0,
    declared_amount          REAL NOT NULL DEFAULT 0,
    terminal_batch_total     REAL,
    variance_amount          REAL,
    variance_reason          TEXT,
    created_at               TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_settlement_details_session
    ON till_settlement_tender_details(session_id);
