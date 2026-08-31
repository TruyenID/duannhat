-- name: GetSetting :one
SELECT value FROM settings WHERE key = ?;

-- name: UpsertSetting :exec
INSERT INTO settings (key, value) VALUES (?, ?)
ON CONFLICT(key) DO UPDATE SET value = excluded.value;

-- name: GetShopSetting :one
SELECT value FROM shop_settings WHERE key = ?;

-- name: UpsertShopSetting :exec
INSERT INTO shop_settings (key, value, cloud_updated_at, local_synced_at)
VALUES (?, ?, datetime('now'), datetime('now'))
ON CONFLICT(key) DO UPDATE SET
    value            = excluded.value,
    cloud_updated_at = excluded.cloud_updated_at,
    local_synced_at  = excluded.local_synced_at;
