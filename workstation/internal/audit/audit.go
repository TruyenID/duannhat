package audit

import (
	"fmt"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// AuditEntry represents a single audit log row.
type AuditEntry struct {
	ID         int    `json:"id"`
	Timestamp  string `json:"timestamp"`
	Actor      string `json:"actor"`
	Action     string `json:"action"`
	EntityType string `json:"entity_type"`
	EntityID   string `json:"entity_id"`
	Details    string `json:"details"`
	IPAddress  string `json:"ip_address"`
}

// AuditFilter controls which audit entries are returned by Query.
type AuditFilter struct {
	From       time.Time
	To         time.Time
	Action     string
	EntityType string
	Limit      int
}

// Logger writes and queries the audit_log table.
type Logger struct {
	db *store.DB
}

// NewLogger creates a new audit Logger backed by the given database.
func NewLogger(database *store.DB) *Logger {
	return &Logger{db: database}
}

// Log records an audit event.
func (l *Logger) Log(actor, action, entityType, entityID, details, ip string) {
	if actor == "" {
		actor = "system"
	}
	l.db.Exec(
		`INSERT INTO audit_log (actor, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)`,
		actor, action, entityType, entityID, details, ip,
	)
}

// Query returns audit entries matching the given filter.
func (l *Logger) Query(filter AuditFilter) ([]AuditEntry, error) {
	var clauses []string
	var args []any

	if !filter.From.IsZero() {
		clauses = append(clauses, "timestamp >= ?")
		args = append(args, filter.From.UTC().Format("2006-01-02 15:04:05"))
	}
	if !filter.To.IsZero() {
		clauses = append(clauses, "timestamp <= ?")
		args = append(args, filter.To.UTC().Format("2006-01-02 15:04:05"))
	}
	if filter.Action != "" {
		clauses = append(clauses, "action = ?")
		args = append(args, filter.Action)
	}
	if filter.EntityType != "" {
		clauses = append(clauses, "entity_type = ?")
		args = append(args, filter.EntityType)
	}

	query := "SELECT id, timestamp, actor, action, COALESCE(entity_type, ''), COALESCE(entity_id, ''), COALESCE(details, ''), COALESCE(ip_address, '') FROM audit_log"
	if len(clauses) > 0 {
		query += " WHERE " + strings.Join(clauses, " AND ")
	}
	query += " ORDER BY id DESC"

	limit := filter.Limit
	if limit <= 0 {
		limit = 100
	}
	query += fmt.Sprintf(" LIMIT %d", limit)

	rows, err := l.db.Query(query, args...)
	if err != nil {
		return nil, fmt.Errorf("audit query: %w", err)
	}
	defer rows.Close()

	var entries []AuditEntry
	for rows.Next() {
		var e AuditEntry
		if err := rows.Scan(&e.ID, &e.Timestamp, &e.Actor, &e.Action, &e.EntityType, &e.EntityID, &e.Details, &e.IPAddress); err != nil {
			continue
		}
		entries = append(entries, e)
	}
	if entries == nil {
		entries = []AuditEntry{}
	}
	return entries, nil
}
