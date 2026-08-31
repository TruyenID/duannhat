-- Sync-queue dead-letter + rate-limit convergence (plan-042).
--
-- Before this, a push that Cloud permanently rejected (404 "order gone", 422
-- "table/customer/sku does not exist") fell into the generic non-retryable
-- branch: it burned all 5 attempts, then died silently at attempts>=max_attempts
-- with no operator signal, and its dependent children looped WARN forever on
-- "cloud_id empty". A push that kept failing *retryably* (5xx) never
-- dead-lettered at all and could head-of-line-block the queue.
--
-- These columns give a row an explicit terminal state so the engine can:
--   * dead-letter a data-conflict immediately (no 5 blind retries),
--   * dead-letter a persistent per-row 5xx once Cloud is proven up
--     (transient_failures / first_transient_at vs the engine's
--     in-memory lastCloudSuccessAt),
--   * surface the stuck rows to the operator and let them Discard /
--     Re-resolve / Re-create-on-Cloud (resolution).
--
--   dead_lettered_at    NULL = active; set when recognized as unpushable.
--   dead_letter_reason  machine code: cloud_404_order_gone,
--                       cloud_422_entity_missing, parent_order_dead,
--                       parent_session_dead, max_attempts_exhausted,
--                       stuck_transient, payment_orphan_order_gone.
--   resolved_at         set when an operator acts on the row.
--   resolution          discarded | re_resolved | recovered.
--   transient_failures  consecutive retryable (5xx/network) failure count.
--   first_transient_at  timestamp of the first failure in the current
--                       retryable streak; reset to NULL on any success.
ALTER TABLE sync_queue ADD COLUMN dead_lettered_at TEXT;
ALTER TABLE sync_queue ADD COLUMN dead_letter_reason TEXT;
ALTER TABLE sync_queue ADD COLUMN resolved_at TEXT;
ALTER TABLE sync_queue ADD COLUMN resolution TEXT;
ALTER TABLE sync_queue ADD COLUMN transient_failures INTEGER DEFAULT 0;
ALTER TABLE sync_queue ADD COLUMN first_transient_at TEXT;

-- Cheap lookups for the banner / recovery counts: only the small set of
-- unresolved dead-letters, never the (large) synced history.
CREATE INDEX idx_sync_queue_dead_letter ON sync_queue(dead_lettered_at)
    WHERE dead_lettered_at IS NOT NULL AND resolved_at IS NULL;

-- Deliberately NOT backfilled: existing attempts>=max_attempts rows predate the
-- dead-letter state and stay as-is (a one-shot operational script, or bulk
-- Discard from the new recovery page, adopts them). New behavior applies going
-- forward only, so this migration is purely additive and safe to re-run never.
