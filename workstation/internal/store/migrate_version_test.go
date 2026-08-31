package store

import (
	"database/sql"
	"io/fs"
	"path/filepath"
	"strings"
	"testing"
)

// Every migration must actually be recorded once the store opens, on a fresh
// database. Before #1196 the plan-045 file was absent from schema_migrations
// no matter how many times the app booted.
func TestEveryMigrationIsRecordedOnFreshDatabase(t *testing.T) {
	db, err := Open(filepath.Join(t.TempDir(), "fresh.db"))
	if err != nil {
		t.Fatalf("open store: %v", err)
	}
	defer db.Close()

	applied := map[string]bool{}
	rows, err := db.conn.Query("SELECT name FROM schema_migrations")
	if err != nil {
		t.Fatalf("read schema_migrations: %v", err)
	}
	defer rows.Close()

	for rows.Next() {
		var name string
		if err := rows.Scan(&name); err != nil {
			t.Fatalf("scan: %v", err)
		}
		applied[name] = true
	}
	if err := rows.Err(); err != nil {
		t.Fatalf("rows: %v", err)
	}

	entries, err := fs.ReadDir(localMigrationsFS, "migrations")
	if err != nil {
		t.Fatalf("read migrations dir: %v", err)
	}

	for _, entry := range entries {
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".sql") {
			continue
		}
		if !applied[entry.Name()] {
			t.Errorf("migration %s never ran on a fresh database", entry.Name())
		}
	}
}

// The renumbered plan-045 migration must survive a database where
// repairLegacySchema() already added its columns — the state of every
// workstation in the field. Without the guard marker this aborts the boot with
// "duplicate column name" and the app never starts.
func TestGuardedMigrationSurvivesPreRepairedColumns(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "guard.db")

	conn, err := sql.Open("sqlite", path)
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer conn.Close()

	// Minimal stand-in for a database that already carries the columns.
	if _, err := conn.Exec(`CREATE TABLE orders (id TEXT PRIMARY KEY, tax_rounding_mode TEXT)`); err != nil {
		t.Fatalf("seed orders: %v", err)
	}

	tx, err := conn.Begin()
	if err != nil {
		t.Fatalf("begin: %v", err)
	}
	defer tx.Rollback()

	content := guardAddColumnMarker + `
ALTER TABLE orders ADD COLUMN tax_rounding_mode TEXT DEFAULT 'round';
ALTER TABLE orders ADD COLUMN tax_rounding_decimals INTEGER DEFAULT 0;
CREATE TABLE IF NOT EXISTS guard_probe (id TEXT PRIMARY KEY);
`

	if err := execMigration(tx, "guard_test.sql", content); err != nil {
		t.Fatalf("guarded migration must tolerate an existing column, got: %v", err)
	}

	// The already-present column is skipped; everything after it still runs.
	exists, err := columnExistsTx(tx, "orders", "tax_rounding_decimals")
	if err != nil {
		t.Fatalf("inspect: %v", err)
	}
	if !exists {
		t.Error("the second ADD COLUMN was not applied — the guard must skip, not abort")
	}

	var name string
	err = tx.QueryRow(`SELECT name FROM sqlite_master WHERE type='table' AND name='guard_probe'`).Scan(&name)
	if err != nil {
		t.Errorf("statements after the skipped ADD COLUMN did not run: %v", err)
	}
}

// Without the marker a duplicate column must still be a hard error: the guard
// is opt-in precisely so an unexpected pre-existing column stays loud.
func TestUnguardedMigrationStillFailsOnDuplicateColumn(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "unguarded.db")

	conn, err := sql.Open("sqlite", path)
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer conn.Close()

	if _, err := conn.Exec(`CREATE TABLE orders (id TEXT PRIMARY KEY, already TEXT)`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	tx, err := conn.Begin()
	if err != nil {
		t.Fatalf("begin: %v", err)
	}
	defer tx.Rollback()

	err = execMigration(tx, "unguarded.sql", `ALTER TABLE orders ADD COLUMN already TEXT;`)
	if err == nil {
		t.Fatal("an unmarked migration must still fail on a duplicate column")
	}
}
