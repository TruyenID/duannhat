-- ─── Payments ────────────────────────────────────────────────────────────────

-- name: CreatePayment :exec
INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, created_at, updated_at)
VALUES (?, ?, ?, ?, 'pending', ?, ?, ?);

-- name: GetPaymentByID :one
SELECT id, cloud_id, order_id, payment_method, amount, refunded_amount, refunded_at,
       status, idempotency_key, terminal_response, created_at, updated_at, synced_at
FROM payments WHERE id = ?;

-- name: GetPaymentsByOrder :many
SELECT id, cloud_id, payment_method, amount, refunded_amount, status, created_at
FROM payments WHERE order_id = ?;

-- name: UpdatePaymentStatus :exec
UPDATE payments SET status = ?, updated_at = ? WHERE id = ?;

-- name: SetPaymentCloudID :exec
UPDATE payments SET cloud_id = ?, updated_at = ? WHERE id = ?;

-- name: MarkPaymentSynced :exec
UPDATE payments SET synced_at = ?, updated_at = ? WHERE id = ?;

-- name: AddRefundAmount :exec
UPDATE payments SET
    refunded_amount = refunded_amount + ?,
    refunded_at     = CASE WHEN refunded_amount + ? >= amount THEN ? ELSE refunded_at END,
    updated_at      = ?
WHERE id = ?;

-- ─── Payment methods ──────────────────────────────────────────────────────────

-- name: ListActivePaymentMethods :many
SELECT id, code, name, is_active, sort_order, requires_tendered, is_auto_confirm
FROM payment_methods
WHERE is_active = 1
ORDER BY sort_order;

-- name: UpsertPaymentMethod :exec
INSERT INTO payment_methods (id, code, name, is_active, sort_order, requires_tendered, is_auto_confirm, cloud_updated_at, local_synced_at)
VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
ON CONFLICT(id) DO UPDATE SET
    code              = excluded.code,
    name              = excluded.name,
    is_active         = excluded.is_active,
    sort_order        = excluded.sort_order,
    requires_tendered = excluded.requires_tendered,
    is_auto_confirm   = excluded.is_auto_confirm,
    cloud_updated_at  = excluded.cloud_updated_at,
    local_synced_at   = excluded.local_synced_at;

-- ─── Payment refunds ──────────────────────────────────────────────────────────

-- name: CreatePaymentRefund :exec
INSERT INTO payment_refunds (id, payment_id, amount, note, refunded_at)
VALUES (?, ?, ?, ?, ?);

-- name: ListPaymentRefunds :many
SELECT id, payment_id, amount, note, refunded_at, synced_at
FROM payment_refunds WHERE payment_id = ?;
