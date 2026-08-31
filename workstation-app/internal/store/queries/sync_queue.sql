-- ─── Sync queue ───────────────────────────────────────────────────────────────

-- name: EnqueueSync :exec
INSERT INTO sync_queue (entity_type, entity_id, operation, payload, idempotency_key, priority, created_at)
VALUES (?, ?, ?, ?, ?, ?, ?);

-- name: ListPendingSync :many
SELECT id, entity_type, entity_id, operation, payload, idempotency_key, attempts
FROM sync_queue
WHERE synced_at IS NULL AND attempts < max_attempts
ORDER BY priority DESC, created_at ASC
LIMIT ?;

-- name: PendingSyncCount :one
SELECT COUNT(*) FROM sync_queue WHERE synced_at IS NULL AND attempts < max_attempts;

-- name: FailedSyncCount :one
SELECT COUNT(*) FROM sync_queue WHERE synced_at IS NULL AND attempts >= max_attempts;

-- name: MarkSynced :exec
UPDATE sync_queue SET synced_at = ? WHERE id = ?;

-- name: IncrementSyncAttempt :exec
UPDATE sync_queue SET attempts = attempts + 1, last_error = ? WHERE id = ?;

-- name: RetryFailedSync :execresult
UPDATE sync_queue SET attempts = 0, last_error = NULL
WHERE synced_at IS NULL AND attempts >= max_attempts;

-- name: RecentSyncHistory :many
SELECT id, entity_type, entity_id, operation, attempts, last_error, created_at, synced_at
FROM sync_queue
ORDER BY id DESC
LIMIT ?;

-- name: PurgeSyncQueue :exec
DELETE FROM sync_queue WHERE synced_at IS NOT NULL AND datetime(synced_at) < datetime(?);
