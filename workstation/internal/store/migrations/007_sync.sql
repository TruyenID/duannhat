-- Outbox queue for syncing local writes UP to Cloud.
--
-- Each row represents one Cloud API call to be attempted.
-- entity_type + operation map to a SyncEngine handler:
--   order.create   → POST /api/v1/workstation/orders
--   payment.create → POST /api/v1/{kiosk|workstation}/payments
--   payment.confirm → POST …/payments/{id}/confirm
--   payment.fail    → POST …/payments/{id}/fail
--
-- Retry behaviour:
--   - Worker picks rows WHERE synced_at IS NULL AND attempts < max_attempts.
--   - On transient error (5xx, timeout): attempts++ and back off.
--   - On permanent error (4xx except 408/429): attempts climbs to max_attempts,
--     row leaves the active pool. Manual RetryFailed() resets the counter.
--   - On success: synced_at = now(). Row kept for 7 days then purged.
--
-- priority: higher = processed first. Default 0; orders use 1 so they
-- arrive at Cloud before their payments.
CREATE TABLE IF NOT EXISTS sync_queue (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type     TEXT NOT NULL,
    entity_id       TEXT NOT NULL,
    operation       TEXT NOT NULL,
    payload         TEXT NOT NULL,          -- JSON
    idempotency_key TEXT UNIQUE,            -- UUID, used as Idempotency-Key header
    priority        INTEGER NOT NULL DEFAULT 0,
    attempts        INTEGER NOT NULL DEFAULT 0,
    max_attempts    INTEGER NOT NULL DEFAULT 5,
    last_error      TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    synced_at       TEXT
);

CREATE INDEX IF NOT EXISTS idx_sync_queue_pending  ON sync_queue(synced_at) WHERE synced_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_sync_queue_priority ON sync_queue(priority DESC, created_at ASC);

-- KDS bump dedup cache — prevents double-apply when a KDS tablet retries a
-- PATCH after network flake. Keyed on (key, device_id) so two tablets
-- deriving the same key independently don't collide.
-- Cleaned up by MaintenanceService after cfg.IdempotencyKeep (default 24 h).
CREATE TABLE IF NOT EXISTS idempotency_keys (
    key          TEXT NOT NULL,
    device_id    TEXT NOT NULL,
    request_hash TEXT NOT NULL,
    response     TEXT NOT NULL,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (key, device_id)
);

CREATE INDEX IF NOT EXISTS idx_idempotency_created ON idempotency_keys(created_at);
CREATE INDEX IF NOT EXISTS idx_idempotency_device  ON idempotency_keys(device_id);
