-- ─── Menu items ───────────────────────────────────────────────────────────────

-- name: ListActiveMenuItems :many
SELECT
    id, cloud_id, sku_id, name, COALESCE(name_ja, '') AS name_ja,
    COALESCE(description, '') AS description, COALESCE(category, '') AS category,
    price, discount_price, discount_pct,
    printer_group, sort_order, is_active,
    COALESCE(image_url, '') AS image_url
FROM menu_items
WHERE is_active = 1
ORDER BY sort_order, name;

-- name: GetMenuItemByID :one
SELECT id, sku_id, name, price, printer_group
FROM menu_items WHERE id = ?;

-- name: GetMenuItemBySkuID :one
-- Used by createItem() when caller sends product_sku_id (handy/kiosk path).
SELECT id, sku_id, name, price, printer_group
FROM menu_items WHERE sku_id = ? AND is_active = 1 LIMIT 1;

-- name: UpsertMenuItem :exec
INSERT INTO menu_items (
    id, cloud_id, sku_id, name, name_ja, description, category,
    price, discount_price, discount_pct,
    image_url, is_active, sort_order, printer_group,
    cloud_updated_at, local_updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON CONFLICT(id) DO UPDATE SET
    cloud_id         = excluded.cloud_id,
    sku_id           = excluded.sku_id,
    name             = excluded.name,
    description      = excluded.description,
    category         = excluded.category,
    price            = excluded.price,
    discount_price   = excluded.discount_price,
    discount_pct     = excluded.discount_pct,
    image_url        = excluded.image_url,
    is_active        = excluded.is_active,
    sort_order       = excluded.sort_order,
    cloud_updated_at = excluded.cloud_updated_at,
    local_updated_at = excluded.local_updated_at;
    -- printer_group, name_ja preserved (workstation-local fields)

-- name: SoftDeleteMenuItem :exec
UPDATE menu_items SET is_active = 0 WHERE id = ?;

-- name: DeactivateCloudMenuItemsKiosk :exec
-- Soft-deactivate kiosk-flat Cloud rows before PullMenu re-activates current items.
UPDATE menu_items SET is_active = 0 WHERE cloud_id IS NOT NULL AND sku_id IS NULL;

-- name: DeactivateCloudMenuItemsHandy :exec
-- Soft-deactivate handy SKU rows before PullHandyMenu re-activates current items.
UPDATE menu_items SET is_active = 0 WHERE cloud_id IS NOT NULL AND sku_id IS NOT NULL;

-- ─── Menu meta ────────────────────────────────────────────────────────────────

-- name: GetMenuMeta :one
SELECT cloud_menu_id, cloud_menu_name, cart_timeout_minutes, cart_deadline_iso, synced_at
FROM menu_meta WHERE id = 'current';

-- name: UpsertMenuMeta :exec
INSERT INTO menu_meta (id, cloud_menu_id, cloud_menu_name, cart_timeout_minutes, cart_deadline_iso, synced_at)
VALUES ('current', ?, ?, ?, ?, datetime('now'))
ON CONFLICT(id) DO UPDATE SET
    cloud_menu_id        = excluded.cloud_menu_id,
    cloud_menu_name      = excluded.cloud_menu_name,
    cart_timeout_minutes = excluded.cart_timeout_minutes,
    cart_deadline_iso    = excluded.cart_deadline_iso,
    synced_at            = excluded.synced_at;

-- ─── Handy menu cache ─────────────────────────────────────────────────────────

-- name: GetHandyMenuCache :one
SELECT day_of_week, payload, fetched_at
FROM handy_menu_cache WHERE id = 'current';

-- name: UpsertHandyMenuCache :exec
INSERT INTO handy_menu_cache (id, day_of_week, payload, fetched_at)
VALUES ('current', ?, ?, datetime('now'))
ON CONFLICT(id) DO UPDATE SET
    day_of_week = excluded.day_of_week,
    payload     = excluded.payload,
    fetched_at  = excluded.fetched_at;

-- ─── Menu schedules ───────────────────────────────────────────────────────────

-- name: ListMenuSchedulesByDOW :many
SELECT id, menu_id, day_of_week, is_active
FROM menu_schedules
WHERE day_of_week = ? AND is_active = 1;
