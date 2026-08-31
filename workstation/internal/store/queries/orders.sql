-- ─── Orders ──────────────────────────────────────────────────────────────────

-- name: ListActiveOrders :many
SELECT
    id, order_code, order_number, order_type, status, opened_at,
    table_id, table_number, guest_count, note,
    subtotal, discount_amount, service_charge, tax_amount,
    total_tip, total_amount, paid_amount, payment_method,
    organization_id, brand_id, branch_id,
    created_at, updated_at
FROM orders
WHERE status NOT IN ('closed', 'voided')
ORDER BY opened_at DESC;

-- name: GetOrderByID :one
SELECT
    id, cloud_id, order_code, order_number, order_type,
    status, opened_at, checkout_at, closed_at, voided_at, void_reason,
    table_id, table_number, guest_count,
    customer_takeaway_name, customer_takeaway_phone, note,
    subtotal, discount_amount, service_charge, tax_amount,
    total_tip, total_amount, paid_amount, payment_method,
    organization_id, brand_id, branch_id,
    created_at, updated_at, synced_at
FROM orders WHERE id = ?;

-- name: CreateOrder :exec
INSERT INTO orders (
    id, order_code, order_number, order_type, status,
    opened_at, table_id, guest_count,
    customer_takeaway_name, customer_takeaway_phone, note,
    subtotal, discount_amount, service_charge, tax_amount,
    total_tip, total_amount, paid_amount,
    organization_id, brand_id, branch_id,
    created_at, updated_at
) VALUES (
    ?, ?, ?, ?, ?,
    ?, ?, ?,
    ?, ?, ?,
    0, 0, 0, 0,
    0, 0, 0,
    ?, ?, ?,
    ?, ?
);

-- name: UpdateOrderStatus :exec
UPDATE orders SET status = ?, updated_at = ? WHERE id = ?;

-- name: UpdateOrderStatusWithTimestamp :exec
-- Use for terminal transitions that set a dedicated timing column.
-- Pass checkout_at / closed_at / voided_at as appropriate; others stay NULL.
UPDATE orders SET
    status      = ?,
    checkout_at = CASE WHEN ? THEN ? ELSE checkout_at END,
    closed_at   = CASE WHEN ? THEN ? ELSE closed_at   END,
    voided_at   = CASE WHEN ? THEN ? ELSE voided_at   END,
    updated_at  = ?
WHERE id = ?;

-- name: UpdateOrderTotals :exec
UPDATE orders SET
    subtotal        = ?,
    discount_amount = ?,
    tax_amount      = ?,
    total_amount    = ?,
    updated_at      = ?
WHERE id = ?;

-- name: RecordPayment :exec
UPDATE orders SET
    status         = 'closed',
    payment_method = ?,
    closed_at      = ?,
    paid_amount    = ?,
    updated_at     = ?
WHERE id = ?;

-- name: ListOrdersByDate :many
SELECT
    id, order_code, order_number, order_type, status, opened_at,
    table_id, table_number, guest_count, note,
    subtotal, discount_amount, service_charge, tax_amount,
    total_tip, total_amount, paid_amount, payment_method,
    closed_at, voided_at,
    organization_id, brand_id, branch_id,
    created_at, updated_at
FROM orders
WHERE date(opened_at) = ?
ORDER BY opened_at DESC;

-- name: CountActiveOrders :one
SELECT COUNT(*) FROM orders WHERE status NOT IN ('closed', 'voided');

-- name: DailyOrderStats :one
SELECT COUNT(*), COALESCE(SUM(total_amount), 0)
FROM orders
WHERE date(opened_at) = ? AND status != 'voided';

-- ─── Order items ──────────────────────────────────────────────────────────────

-- name: GetOrderItems :many
SELECT
    id, customer_order_id,
    COALESCE(product_sku_id, ''), COALESCE(menu_item_id, ''),
    menu_item_name, quantity, unit_price, subtotal,
    COALESCE(note, ''), printer_group, status, print_status,
    started_preparing_at, ready_at, served_at,
    voided_at, COALESCE(void_reason, ''),
    printed_at, created_at, updated_at
FROM order_items
WHERE customer_order_id = ?
ORDER BY created_at;

-- name: CreateOrderItem :exec
INSERT INTO order_items (
    id, customer_order_id, menu_item_id, product_sku_id,
    menu_item_name, quantity, unit_price, subtotal,
    note, printer_group, status, print_status,
    created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);

-- name: UpdateOrderItemStatus :exec
UPDATE order_items SET status = ?, updated_at = ? WHERE id = ?;

-- name: MarkItemPrinted :exec
UPDATE order_items SET
    print_status = 'sent_to_kitchen',
    printed_at   = ?,
    updated_at   = ?
WHERE id = ?;

-- name: MarkItemPreparing :exec
UPDATE order_items SET
    status               = 'preparing',
    started_preparing_at = ?,
    updated_at           = ?
WHERE id = ?;

-- name: MarkItemReady :exec
UPDATE order_items SET
    status     = 'ready',
    ready_at   = ?,
    updated_at = ?
WHERE id = ?;

-- name: MarkItemServed :exec
UPDATE order_items SET
    status     = 'served',
    served_at  = ?,
    updated_at = ?
WHERE id = ?;

-- name: VoidOrderItem :exec
UPDATE order_items SET
    status      = 'voided',
    voided_at   = ?,
    void_reason = ?,
    updated_at  = ?
WHERE id = ?;

-- ─── Order tables (multi-table / merged) ─────────────────────────────────────

-- name: GetOrderTables :many
SELECT order_id, table_id, sort_order, bound_at
FROM order_tables
WHERE order_id = ?
ORDER BY sort_order;

-- name: BindOrderTable :exec
INSERT OR IGNORE INTO order_tables (order_id, table_id, sort_order, bound_at)
VALUES (?, ?, ?, ?);

-- name: UnbindOrderTable :exec
DELETE FROM order_tables WHERE order_id = ? AND table_id = ?;

-- ─── Order coupons ────────────────────────────────────────────────────────────

-- name: GetActiveOrderCoupon :one
SELECT id, order_id, coupon_id, coupon_code, discount_applied, applied_at
FROM order_coupons
WHERE order_id = ? AND released_at IS NULL
LIMIT 1;

-- name: ApplyOrderCoupon :exec
INSERT INTO order_coupons (id, order_id, coupon_id, coupon_code, discount_applied)
VALUES (?, ?, ?, ?, ?);

-- name: ReleaseOrderCoupon :exec
UPDATE order_coupons SET released_at = ? WHERE order_id = ? AND released_at IS NULL;
