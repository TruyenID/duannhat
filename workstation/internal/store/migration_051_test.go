package store

import (
	"path/filepath"
	"strings"
	"testing"
)

// Migration 051 renames the printer role hold_printer → hall_printer. The value
// lives in two columns — `roles` (JSON array, authoritative since 013) and
// `type` (the primary role, kept in sync for back-compat) — and missing either
// one leaves a printer that no longer matches any role, i.e. a station that
// silently stops printing.
func TestMigration051_RenamesHoldPrinterRole(t *testing.T) {
	db, err := Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	defer db.Close()

	// Simulate rows written before the rename, then re-run the migration's
	// statements — the chain already ran on Open, so seeding first would be
	// missed by it.
	if _, err := db.Exec(`
		INSERT INTO printers (id, type, name, connection_type, address, roles, is_active, created_at, updated_at)
		VALUES
		  ('p-hall', 'hold_printer', 'Hall', 'network', '10.0.0.1:9100',
		   '["hold_printer"]', 1, datetime('now'), datetime('now')),
		  ('p-multi', 'kitchen_printer', 'Kitchen+Hall', 'network', '10.0.0.2:9100',
		   '["kitchen_printer","hold_printer"]', 1, datetime('now'), datetime('now')),
		  ('p-other', 'bar_printer', 'Bar', 'network', '10.0.0.3:9100',
		   '["bar_printer"]', 1, datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	for _, stmt := range []string{
		`UPDATE printers SET roles = REPLACE(roles, '"hold_printer"', '"hall_printer"') WHERE roles LIKE '%"hold_printer"%'`,
		`UPDATE printers SET type = 'hall_printer' WHERE type = 'hold_printer'`,
	} {
		if _, err := db.Exec(stmt); err != nil {
			t.Fatalf("migration stmt: %v", err)
		}
	}

	cases := []struct {
		id        string
		wantType  string
		wantRoles string
	}{
		{"p-hall", "hall_printer", `["hall_printer"]`},
		{"p-multi", "kitchen_printer", `["kitchen_printer","hall_printer"]`},
		// Untouched: the rename must not rewrite unrelated roles.
		{"p-other", "bar_printer", `["bar_printer"]`},
	}
	for _, c := range cases {
		var gotType, gotRoles string
		if err := db.QueryRow(`SELECT type, roles FROM printers WHERE id = ?`, c.id).
			Scan(&gotType, &gotRoles); err != nil {
			t.Fatalf("%s: read back: %v", c.id, err)
		}
		if gotType != c.wantType {
			t.Errorf("%s type = %q, want %q", c.id, gotType, c.wantType)
		}
		if gotRoles != c.wantRoles {
			t.Errorf("%s roles = %q, want %q", c.id, gotRoles, c.wantRoles)
		}
		if strings.Contains(gotRoles, "hold_printer") {
			t.Errorf("%s still carries the legacy role: %s", c.id, gotRoles)
		}
	}
}

// The migration file itself must ship both statements — a test that only
// exercises hand-copied SQL would pass even if the file were empty.
func TestMigration051_FileCoversBothColumns(t *testing.T) {
	sql, err := localMigrationsFS.ReadFile("migrations/051_hall_printer_role_rename.sql")
	if err != nil {
		t.Fatalf("read migration: %v", err)
	}
	body := string(sql)
	for _, want := range []string{"SET roles = REPLACE(", "SET type = 'hall_printer'"} {
		if !strings.Contains(body, want) {
			t.Errorf("migration 051 is missing %q", want)
		}
	}
}
