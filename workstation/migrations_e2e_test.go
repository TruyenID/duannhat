package workstation

// End-to-end migration coverage that the in-package store/migrate_test.go
// cannot provide on its own: omnify-generated migrations live at the project
// root (so the //go:embed directive can reach migrations/omnify/), and Go
// only honours embed paths at or below the embedding file. This test sits
// at the same root, wires `store.OmnifyMigrations` to the real embed, and
// verifies the full hand-written + omnify chain applies cleanly on a fresh
// SQLite file.
//
// What this pins (catches regressions on a future merge that store/'s narrow
// tests would miss):
//   - Every hand-written migration (001..00N) survives a `migrate:fresh`.
//   - Every omnify migration (001..0NN) applies AFTER the hand-written ones
//     at a 1000+ version offset — no collision on schema_migrations.
//   - All expected tables (workstation-local + omnify cloud-mirror) end up
//     in sqlite_master so a fresh dev/CI environment matches a paired
//     workstation.
//   - Opening the same DB a second time is a true no-op (zero new rows in
//     schema_migrations).

import (
	"io/fs"
	"path/filepath"
	"sort"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

func setupOmnify(t *testing.T) {
	t.Helper()
	store.OmnifyMigrations = &OmnifyMigrations
	t.Cleanup(func() { store.OmnifyMigrations = nil })
}

// countSQLFiles inspects the live embed.FS so the assertion stays in sync if
// migrations are added or removed — we don't want a magic-number that decays
// silently on the next migration.
func countSQLFiles(t *testing.T, root fs.FS, dir string) int {
	t.Helper()
	entries, err := fs.ReadDir(root, dir)
	if err != nil {
		t.Fatalf("read %s: %v", dir, err)
	}
	n := 0
	for _, e := range entries {
		if !e.IsDir() && strings.HasSuffix(e.Name(), ".sql") {
			n++
		}
	}
	return n
}

func TestMigrationsE2E_FullChainAppliesCleanly(t *testing.T) {
	setupOmnify(t)

	db, err := store.Open(filepath.Join(t.TempDir(), "e2e.db"))
	if err != nil {
		t.Fatalf("open fresh DB: %v", err)
	}
	defer db.Close()

	// Count what's on disk and what landed in schema_migrations — must match.
	want := countSQLFiles(t, OmnifyMigrations, "migrations/omnify")
	// Hand-written count is read via the store package's internal embed, so we
	// can't list it here directly. We count via the recorded versions instead.
	var total int
	if err := db.QueryRow("SELECT COUNT(*) FROM schema_migrations").Scan(&total); err != nil {
		t.Fatalf("count schema_migrations: %v", err)
	}
	if total < want {
		t.Errorf("schema_migrations rows: want at least %d (omnify alone), got %d", want, total)
	}

	// Versions split: 1..999 = hand-written, 1000+ = omnify-offset.
	var omnifyApplied int
	if err := db.QueryRow("SELECT COUNT(*) FROM schema_migrations WHERE version >= 1000").Scan(&omnifyApplied); err != nil {
		t.Fatalf("count omnify migrations: %v", err)
	}
	if omnifyApplied != want {
		t.Errorf("omnify migrations applied: want %d, got %d", want, omnifyApplied)
	}

	var handwritten int
	if err := db.QueryRow("SELECT COUNT(*) FROM schema_migrations WHERE version < 1000").Scan(&handwritten); err != nil {
		t.Fatalf("count hand-written migrations: %v", err)
	}
	if handwritten < 1 {
		t.Errorf("expected at least one hand-written migration applied, got %d", handwritten)
	}
}

func TestMigrationsE2E_AllExpectedTablesExist(t *testing.T) {
	setupOmnify(t)

	db, err := store.Open(filepath.Join(t.TempDir(), "tables.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	// Hand-written tables — workstation owns these locally (orders, payments,
	// auth cache, etc.). Names track the migrations in internal/store/migrations/.
	handWrittenTables := []string{
		// 001_initial_schema + 005_rename_devices_to_printers
		"menu_items", "orders", "order_items", "printers",
		"sync_queue", "settings", "order_counters",
		// 003_audit_log
		"audit_log",
		// 004_local_replica
		"payments", "tables", "zones", "auth_token_cache", "shop_settings",
	}

	// Omnify-generated tables — cloud-mirror tables the workstation pulls
	// from Cloud (read-only-from-our-side). Derived from migrations/omnify/.
	omnifyTables := []string{
		"branches", "brands", "categories",
		"customer_orders", "customer_order_items",
		"devices", "menus", "menu_product_skus",
		"organizations", "products", "product_skus",
	}

	all := append(append([]string{}, handWrittenTables...), omnifyTables...)
	sort.Strings(all)

	for _, name := range all {
		var found string
		err := db.QueryRow(
			"SELECT name FROM sqlite_master WHERE type='table' AND name=?", name,
		).Scan(&found)
		if err != nil {
			t.Errorf("table %q missing after full migrate: %v", name, err)
		}
	}
}

func TestMigrationsE2E_ReopenIsNoop(t *testing.T) {
	setupOmnify(t)

	dbPath := filepath.Join(t.TempDir(), "reopen.db")

	first, err := store.Open(dbPath)
	if err != nil {
		t.Fatalf("first open: %v", err)
	}
	var firstCount int
	if err := first.QueryRow("SELECT COUNT(*) FROM schema_migrations").Scan(&firstCount); err != nil {
		t.Fatalf("first count: %v", err)
	}
	first.Close()

	second, err := store.Open(dbPath)
	if err != nil {
		t.Fatalf("second open: %v", err)
	}
	defer second.Close()

	var secondCount int
	if err := second.QueryRow("SELECT COUNT(*) FROM schema_migrations").Scan(&secondCount); err != nil {
		t.Fatalf("second count: %v", err)
	}

	if secondCount != firstCount {
		t.Errorf("reopen applied extra migrations: first=%d, second=%d", firstCount, secondCount)
	}
}

// TestMigrationsE2E_OrderItemPrintStatusBackfilled is a thin regression test
// for migration 009 (workstation print workflow split). Verifies that the
// print_status column exists on order_items and defaults to 'pending' for
// new rows — guards against an accidental schema revert during a future
// rebase.
func TestMigrationsE2E_OrderItemPrintStatusBackfilled(t *testing.T) {
	setupOmnify(t)

	db, err := store.Open(filepath.Join(t.TempDir(), "print_status.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, err = db.Exec(`
		INSERT INTO orders (
			id, order_code, order_number, order_type, status,
			opened_at, guest_count,
			subtotal, discount_amount, service_charge, tax_amount,
			total_tip, total_amount, paid_amount,
			organization_id, brand_id, branch_id,
			created_at, updated_at
		) VALUES ('o1', 'WS-1', 1, 'spot', 'open',
			datetime('now'), 1, 0, 0, 0, 0, 0, 0, 0,
			'org', 'brand', 'branch', datetime('now'), datetime('now'))
	`)
	if err != nil {
		t.Fatalf("seed order: %v", err)
	}
	_, err = db.Exec(`
		INSERT INTO order_items (
			id, customer_order_id, menu_item_name,
			quantity, unit_price, subtotal, printer_group,
			created_at, updated_at
		) VALUES ('i1', 'o1', 'Ramen', 1, 1000, 1000, 'kitchen',
			datetime('now'), datetime('now'))
	`)
	if err != nil {
		t.Fatalf("seed item (print_status should default): %v", err)
	}

	var printStatus string
	if err := db.QueryRow("SELECT print_status FROM order_items WHERE id='i1'").Scan(&printStatus); err != nil {
		t.Fatalf("read print_status: %v", err)
	}
	if printStatus != "pending" {
		t.Errorf("print_status default: want pending, got %q", printStatus)
	}
}
