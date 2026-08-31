package store

import (
	"database/sql"
	"testing"
)

func TestDiagnostics_ReportsPoolAndOperationMetricsWithoutQuerying(t *testing.T) {
	db := openTestDB(t)
	before := db.Diagnostics()

	if _, err := db.Exec(`INSERT INTO settings (key, value) VALUES ('diag', '1')`); err != nil {
		t.Fatalf("exec: %v", err)
	}
	var value string
	if err := db.QueryRow(`SELECT value FROM settings WHERE key = 'diag'`).Scan(&value); err != nil {
		t.Fatalf("query: %v", err)
	}
	if err := db.Transaction(func(tx *sql.Tx) error {
		_, err := tx.Exec(`UPDATE settings SET value = '2' WHERE key = 'diag'`)
		return err
	}); err != nil {
		t.Fatalf("transaction: %v", err)
	}

	after := db.Diagnostics()
	if after.Status != "ready" {
		t.Errorf("status = %q, want ready", after.Status)
	}
	if after.MaxOpenConnections != 8 {
		t.Errorf("max_open_connections = %d, want 8", after.MaxOpenConnections)
	}
	if got := after.ExecCount - before.ExecCount; got != 1 {
		t.Errorf("exec count delta = %d, want 1", got)
	}
	if got := after.QueryCount - before.QueryCount; got != 1 {
		t.Errorf("query count delta = %d, want 1", got)
	}
	if got := after.TransactionCount - before.TransactionCount; got != 1 {
		t.Errorf("transaction count delta = %d, want 1", got)
	}
}
