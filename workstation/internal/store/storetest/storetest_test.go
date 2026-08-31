package storetest_test

import (
	"path/filepath"
	"sort"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// schemaOf reads back what the database actually contains, so the comparison
// below is against the real thing rather than against the migration files that
// were supposed to produce it.
func schemaOf(t *testing.T, db *store.DB) []string {
	t.Helper()

	rows, err := db.Conn().Query(
		`SELECT type || ' ' || name || ' ' || COALESCE(sql, '')
		   FROM sqlite_master
		  WHERE name NOT LIKE 'sqlite_%'`,
	)
	if err != nil {
		t.Fatalf("read sqlite_master: %v", err)
	}
	defer rows.Close()

	var out []string
	for rows.Next() {
		var s string
		if err := rows.Scan(&s); err != nil {
			t.Fatalf("scan: %v", err)
		}
		out = append(out, s)
	}
	if err := rows.Err(); err != nil {
		t.Fatalf("rows: %v", err)
	}

	sort.Strings(out)

	return out
}

func appliedMigrations(t *testing.T, db *store.DB) []string {
	t.Helper()

	rows, err := db.Conn().Query(`SELECT name FROM schema_migrations`)
	if err != nil {
		t.Fatalf("read schema_migrations: %v", err)
	}
	defer rows.Close()

	var out []string
	for rows.Next() {
		var s string
		if err := rows.Scan(&s); err != nil {
			t.Fatalf("scan: %v", err)
		}
		out = append(out, s)
	}

	sort.Strings(out)

	return out
}

// The whole point of the template is that it is INDISTINGUISHABLE from a
// database built the slow way. If it ever drifts, every suite using it is
// testing against a schema production never has.
func TestTemplateMatchesAFullMigration(t *testing.T) {
	slow, err := store.Open(filepath.Join(t.TempDir(), "slow.db"))
	if err != nil {
		t.Fatalf("full migrate: %v", err)
	}
	defer slow.Close()

	fast, err := storetest.Open(filepath.Join(t.TempDir(), "fast.db"))
	if err != nil {
		t.Fatalf("template open: %v", err)
	}
	defer fast.Close()

	wantSchema, gotSchema := schemaOf(t, slow), schemaOf(t, fast)
	if len(wantSchema) != len(gotSchema) {
		t.Fatalf("object count differs: full=%d template=%d", len(wantSchema), len(gotSchema))
	}
	for i := range wantSchema {
		if wantSchema[i] != gotSchema[i] {
			t.Errorf("schema object %d differs:\n full migrate: %s\n    template: %s", i, wantSchema[i], gotSchema[i])
		}
	}

	wantMigrations, gotMigrations := appliedMigrations(t, slow), appliedMigrations(t, fast)
	if len(wantMigrations) != len(gotMigrations) {
		t.Fatalf("schema_migrations differs: full=%d template=%d", len(wantMigrations), len(gotMigrations))
	}
	for i := range wantMigrations {
		if wantMigrations[i] != gotMigrations[i] {
			t.Errorf("migration %d differs: full=%q template=%q", i, wantMigrations[i], gotMigrations[i])
		}
	}
}

// Two databases from the same template must not share state — the copy has to
// be a copy, not a link.
func TestEachOpenIsIndependent(t *testing.T) {
	dir := t.TempDir()

	a, err := storetest.Open(filepath.Join(dir, "a.db"))
	if err != nil {
		t.Fatalf("open a: %v", err)
	}
	defer a.Close()

	b, err := storetest.Open(filepath.Join(dir, "b.db"))
	if err != nil {
		t.Fatalf("open b: %v", err)
	}
	defer b.Close()

	if _, err := a.Conn().Exec(`INSERT INTO settings (key, value) VALUES ('storetest_probe', '1')`); err != nil {
		t.Fatalf("write to a: %v", err)
	}

	var count int
	if err := b.Conn().QueryRow(`SELECT COUNT(*) FROM settings WHERE key = 'storetest_probe'`).Scan(&count); err != nil {
		t.Fatalf("read b: %v", err)
	}
	if count != 0 {
		t.Error("a write to one database was visible in another — the template is being shared, not copied")
	}
}

// A caller that has not opened anything yet must still get a usable database
// when it names a path inside a directory that does not exist.
func TestOpenCreatesMissingDirectories(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "nested", "deeper", "x.db"))
	if err != nil {
		t.Fatalf("open in a missing directory: %v", err)
	}
	defer db.Close()
}

// Reopening the same path must preserve what was written — several tests
// simulate a workstation restart that way, and seeding the template over an
// existing file made them fail as if persistence itself were broken.
func TestReopeningAnExistingPathKeepsItsData(t *testing.T) {
	path := filepath.Join(t.TempDir(), "restart.db")

	first, err := storetest.Open(path)
	if err != nil {
		t.Fatalf("first open: %v", err)
	}
	if _, err := first.Conn().Exec(
		`INSERT INTO settings (key, value) VALUES ('storetest_restart', 'survived')`,
	); err != nil {
		t.Fatalf("write: %v", err)
	}
	if err := first.Close(); err != nil {
		t.Fatalf("close: %v", err)
	}

	second, err := storetest.Open(path)
	if err != nil {
		t.Fatalf("reopen: %v", err)
	}
	defer second.Close()

	var value string
	if err := second.Conn().QueryRow(
		`SELECT value FROM settings WHERE key = 'storetest_restart'`,
	).Scan(&value); err != nil {
		t.Fatalf("the row written before the restart is gone: %v", err)
	}
	if value != "survived" {
		t.Errorf("value = %q, want %q", value, "survived")
	}
}
