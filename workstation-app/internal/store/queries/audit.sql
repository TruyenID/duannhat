-- name: InsertAuditEntry :exec
INSERT INTO audit_log (actor, action, entity_type, entity_id, details, ip_address)
VALUES (?, ?, ?, ?, ?, ?);

-- name: QueryAuditLog :many
SELECT
    id, timestamp, actor, action,
    COALESCE(entity_type, '') AS entity_type,
    COALESCE(entity_id,   '') AS entity_id,
    COALESCE(details,     '') AS details,
    COALESCE(ip_address,  '') AS ip_address
FROM audit_log
ORDER BY id DESC
LIMIT ?;

-- name: PurgeAuditLog :exec
DELETE FROM audit_log WHERE datetime(timestamp) < datetime(?);
