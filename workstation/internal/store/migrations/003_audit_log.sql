-- Immutable append-only audit trail for operator-visible actions.
-- actor  : 'system' | 'admin' | 'device:<id>' | client IP
-- action : 'order.create' | 'order.status_change' | 'menu.update' |
--           'device.pair' | 'settings.update' | 'payment.record' …
-- details: JSON object with before/after values. Serialised via
--          json.Marshal (not fmt.Sprintf) so values with " or \ are safe.
-- Purged by MaintenanceService after cfg.AuditKeep (default 90 days).
CREATE TABLE IF NOT EXISTS audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp   TEXT NOT NULL DEFAULT (datetime('now')),
    actor       TEXT NOT NULL DEFAULT 'system',
    action      TEXT NOT NULL,
    entity_type TEXT,
    entity_id   TEXT,
    details     TEXT,   -- JSON
    ip_address  TEXT
);

CREATE INDEX IF NOT EXISTS idx_audit_log_timestamp  ON audit_log(timestamp);
CREATE INDEX IF NOT EXISTS idx_audit_log_action     ON audit_log(action);
CREATE INDEX IF NOT EXISTS idx_audit_log_entity     ON audit_log(entity_type, entity_id);
