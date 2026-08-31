-- ─── Printers (ESC/POS hardware) ─────────────────────────────────────────────

-- name: ListActivePrinters :many
SELECT id, type, name, connection_type, address, config, is_active
FROM printers WHERE is_active = 1;

-- name: CreatePrinter :exec
INSERT INTO printers (id, type, name, connection_type, address, config, is_active, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?);

-- name: DeactivatePrinter :exec
UPDATE printers SET is_active = 0 WHERE id = ?;

-- name: UpdatePrinterLastSeen :exec
UPDATE printers SET last_seen_at = ? WHERE id = ?;
