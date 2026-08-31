package store

import (
	"fmt"
	"io/fs"
	"path/filepath"
	"strings"
	"testing"
)

// allExpectedTables is the complete list of tables created by hand-written
// migrations 001–010. Update this slice whenever a new migration adds a table.
var allExpectedTables = []string{
	// 001_settings
	"settings",
	"order_counters",
	// 002_printers
	"printers",
	// 003_audit_log
	"audit_log",
	// 004_menu
	"menu_items",
	"menu_meta",
	"handy_menu_cache",
	"menu_schedules",
	// 005_orders
	"orders",
	"order_items",
	"order_tables",
	"order_coupons",
	// 006_payments
	"payments",
	"payment_refunds",
	"payment_methods",
	// 007_sync
	"sync_queue",
	"idempotency_keys",
	// 008_replica
	"zones",
	"tables",
	"auth_token_cache",
	"shop_settings",
	"inventory_lots",
	// 009_pos_replica
	"customers",
	"coupons",
	// 010_kitchen_ticket_counter
	"kitchen_ticket_counters",
	// 049_peripheral_devices
	"peripheral_devices",
}

func openTestDB(t *testing.T) *DB {
	t.Helper()
	db, err := Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open DB: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return db
}

func assertTableExists(t *testing.T, db *DB, name string) {
	t.Helper()
	var found string
	err := db.QueryRow(
		"SELECT name FROM sqlite_master WHERE type='table' AND name=?", name,
	).Scan(&found)
	if err != nil {
		t.Errorf("table %q missing: %v", name, err)
	}
}

func assertIndexExists(t *testing.T, db *DB, name string) {
	t.Helper()
	var found string
	err := db.QueryRow(
		"SELECT name FROM sqlite_master WHERE type='index' AND name=?", name,
	).Scan(&found)
	if err != nil {
		t.Errorf("index %q missing: %v", name, err)
	}
}

// TestMigrationsApply verifies every expected table exists after a fresh open.
func TestMigrationsApply(t *testing.T) {
	db := openTestDB(t)
	for _, name := range allExpectedTables {
		assertTableExists(t, db, name)
	}
}

// TestMigrations_UniquePrefixes (plan-046 P5-2) guards against a duplicate
// migration number. runMigrationsWithOffset keys on the integer prefix and
// silently `continue`s on an already-recorded version, so a second file sharing
// a prefix is NEVER applied — no error, no log, and a schema that looks fine
// for as long as something else happens to cover the gap.
//
// The tree carried exactly one such pair (040_payments_sync_target.sql +
// 040_plan045_refund_rounding.sql), and it was ALLOWLISTED here as
// "grandfathered, healed via repair.go". That allowlist is gone: #1196 showed
// the plan-045 half had never run on ANY database and was surviving only
// because repairLegacySchema() re-added the same columns — a safety net for old
// databases silently delivering schema to new ones. It is renumbered to 064.
//
// Do not re-add an entry here. A duplicate is a bug to renumber, not to record.
func TestMigrations_UniquePrefixes(t *testing.T) {

	entries, err := fs.ReadDir(localMigrationsFS, "migrations")
	if err != nil {
		t.Fatalf("read migrations dir: %v", err)
	}
	seen := map[int]string{}
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".sql") {
			continue
		}
		var version int
		if _, err := fmt.Sscanf(e.Name(), "%d_", &version); err != nil || version == 0 {
			t.Errorf("migration %q has no numeric prefix", e.Name())
			continue
		}
		if prev, dup := seen[version]; dup {
			t.Errorf("duplicate migration prefix %d: %q and %q — the runner silently skips the second; renumber it (see #1196)", version, prev, e.Name())
			continue
		}
		seen[version] = e.Name()
	}
}

// TestTillSessionChainColumns (plan-046 T1.4) verifies migration 044 adds the
// four chain-of-shifts columns + the chain index to the local till_sessions.
func TestTillSessionChainColumns(t *testing.T) {
	db := openTestDB(t)
	for _, col := range []string{"chain_id", "chain_sequence", "settlement_kind", "settlement_snapshot"} {
		ok, err := db.columnExists("till_sessions", col)
		if err != nil {
			t.Fatalf("introspect till_sessions.%s: %v", col, err)
		}
		if !ok {
			t.Errorf("till_sessions.%s missing after migration 044", col)
		}
	}
	assertIndexExists(t, db, "idx_till_sessions_chain")
}

// TestMigrationsIdempotent opens the same DB twice and confirms no error.
func TestMigrationsIdempotent(t *testing.T) {
	dir := t.TempDir()
	db1, err := Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("first open: %v", err)
	}
	db1.Close()

	db2, err := Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("second open: %v", err)
	}
	defer db2.Close()

	var count int
	if err := db2.QueryRow("SELECT COUNT(*) FROM schema_migrations").Scan(&count); err != nil {
		t.Fatalf("count migrations: %v", err)
	}
	if count < 10 {
		t.Errorf("expected at least 10 hand-written migrations applied, got %d", count)
	}
}

// TestKeyIndexesExist spot-checks critical indexes.
func TestKeyIndexesExist(t *testing.T) {
	db := openTestDB(t)
	indexes := []string{
		"idx_orders_branch_opened",
		"idx_orders_branch_type_opened",
		"idx_orders_status",
		"idx_orders_synced",
		"idx_orders_order_code",
		"idx_order_items_status",
		"idx_order_items_print_status",
		"idx_sync_queue_pending",
		"idx_payments_synced",
		"idx_auth_cache_expires",
		"idx_menu_items_sku_id",
	}
	for _, idx := range indexes {
		assertIndexExists(t, db, idx)
	}
}

// TestSettingsSchema verifies UPSERT semantics on the settings table.
func TestSettingsSchema(t *testing.T) {
	db := openTestDB(t)

	// Seed rows from migration 001 must exist.
	var val string
	if err := db.QueryRow("SELECT value FROM settings WHERE key='device_token'").Scan(&val); err != nil {
		t.Fatalf("device_token seed row missing: %v", err)
	}

	// UPSERT must overwrite.
	if _, err := db.Exec("INSERT INTO settings (key, value) VALUES ('test_key', 'v1') ON CONFLICT(key) DO UPDATE SET value=excluded.value"); err != nil {
		t.Fatalf("upsert settings: %v", err)
	}
	if _, err := db.Exec("INSERT INTO settings (key, value) VALUES ('test_key', 'v2') ON CONFLICT(key) DO UPDATE SET value=excluded.value"); err != nil {
		t.Fatalf("upsert settings overwrite: %v", err)
	}
	if err := db.QueryRow("SELECT value FROM settings WHERE key='test_key'").Scan(&val); err != nil {
		t.Fatalf("read after upsert: %v", err)
	}
	if val != "v2" {
		t.Errorf("expected 'v2' after upsert, got %q", val)
	}
}

// TestPaymentsSchema verifies constraints on the payments table.
func TestPaymentsSchema(t *testing.T) {
	db := openTestDB(t)

	// Basic insert.
	if _, err := db.Exec(`
		INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key)
		VALUES ('p1', 'o1', 'cash', 1000, 'pending', 'idem-1')
	`); err != nil {
		t.Fatalf("insert payment: %v", err)
	}

	// idempotency_key UNIQUE constraint.
	if _, err := db.Exec(`
		INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key)
		VALUES ('p2', 'o1', 'cash', 1000, 'pending', 'idem-1')
	`); err == nil {
		t.Error("expected idempotency_key UNIQUE violation")
	}

	// Default status = 'pending'.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount) VALUES ('p3', 'o1', 'card', 500)`); err != nil {
		t.Fatalf("insert with default status: %v", err)
	}
	var status string
	if err := db.QueryRow("SELECT status FROM payments WHERE id='p3'").Scan(&status); err != nil {
		t.Fatalf("read status: %v", err)
	}
	if status != "pending" {
		t.Errorf("expected default status 'pending', got %q", status)
	}

	// refunded_amount defaults to 0.
	var refunded int
	if err := db.QueryRow("SELECT refunded_amount FROM payments WHERE id='p1'").Scan(&refunded); err != nil {
		t.Fatalf("read refunded_amount: %v", err)
	}
	if refunded != 0 {
		t.Errorf("expected refunded_amount=0, got %d", refunded)
	}
}

// TestAuthTokenCacheSchema verifies token_hash PK + both identity types.
func TestAuthTokenCacheSchema(t *testing.T) {
	db := openTestDB(t)

	// Device token row.
	if _, err := db.Exec(`
		INSERT INTO auth_token_cache (token_hash, identity_type, device_id, device_type, expires_at)
		VALUES ('hash1', 'device', 'dev-1', 'kiosk', datetime('now', '+5 minutes'))
	`); err != nil {
		t.Fatalf("insert device token: %v", err)
	}

	// SSO user token row (device_id NULL).
	if _, err := db.Exec(`
		INSERT INTO auth_token_cache (token_hash, identity_type, user_id, user_email, expires_at)
		VALUES ('hash2', 'user', 'user-1', 'staff@godx.jp', datetime('now', '+5 minutes'))
	`); err != nil {
		t.Fatalf("insert user token: %v", err)
	}

	// Duplicate token_hash → PK violation.
	if _, err := db.Exec(`
		INSERT INTO auth_token_cache (token_hash, identity_type, device_id, device_type, expires_at)
		VALUES ('hash1', 'device', 'dev-2', 'pos', datetime('now', '+5 minutes'))
	`); err == nil {
		t.Error("expected PRIMARY KEY violation on duplicate token_hash")
	}
}

// TestTablesUniqueQRToken verifies qr_token UNIQUE constraint.
func TestTablesUniqueQRToken(t *testing.T) {
	db := openTestDB(t)

	if _, err := db.Exec(`INSERT INTO tables (id, qr_token, name) VALUES ('t1', 'qr-abc', 'Table 1')`); err != nil {
		t.Fatalf("insert table 1: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO tables (id, qr_token, name) VALUES ('t2', 'qr-abc', 'Table 2')`); err == nil {
		t.Error("expected UNIQUE violation on duplicate qr_token")
	}
}

// TestIdempotencyKeysCompositePK verifies (key, device_id) is the PK so two
// devices can share the same bump key without collision.
func TestIdempotencyKeysCompositePK(t *testing.T) {
	db := openTestDB(t)

	if _, err := db.Exec(`
		INSERT INTO idempotency_keys (key, device_id, request_hash, response)
		VALUES ('bump-1', 'kds-a', 'hashA', '{"ok":true}')
	`); err != nil {
		t.Fatalf("insert device A: %v", err)
	}
	// Same key, different device — must succeed.
	if _, err := db.Exec(`
		INSERT INTO idempotency_keys (key, device_id, request_hash, response)
		VALUES ('bump-1', 'kds-b', 'hashB', '{"ok":true}')
	`); err != nil {
		t.Errorf("different device same key should be allowed: %v", err)
	}
	// Same key + same device — PK conflict.
	if _, err := db.Exec(`
		INSERT INTO idempotency_keys (key, device_id, request_hash, response)
		VALUES ('bump-1', 'kds-a', 'hashA2', '{"ok":true}')
	`); err == nil {
		t.Error("expected PK violation for same (key, device_id)")
	}
}

// TestOrderItemsCascadeDelete verifies ON DELETE CASCADE from orders → order_items.
func TestOrderItemsCascadeDelete(t *testing.T) {
	db := openTestDB(t)

	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, status, opened_at, created_at, updated_at)
		VALUES ('ord-1', 'WS-001', 'open', datetime('now'), datetime('now'), datetime('now'))
	`); err != nil {
		t.Fatalf("insert order: %v", err)
	}
	if _, err := db.Exec(`
		INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, created_at, updated_at)
		VALUES ('item-1', 'ord-1', 'Test Item', 1, 500, 500, datetime('now'), datetime('now'))
	`); err != nil {
		t.Fatalf("insert order item: %v", err)
	}

	if _, err := db.Exec(`DELETE FROM orders WHERE id = 'ord-1'`); err != nil {
		t.Fatalf("delete order: %v", err)
	}

	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM order_items WHERE customer_order_id = 'ord-1'`).Scan(&count); err != nil {
		t.Fatalf("count orphan items: %v", err)
	}
	if count != 0 {
		t.Errorf("expected 0 orphan order_items after cascade delete, got %d", count)
	}
}

// TestOrderItemPrintStatus verifies print_status column defaults and values.
func TestOrderItemPrintStatus(t *testing.T) {
	db := openTestDB(t)

	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, status, opened_at, created_at, updated_at)
		VALUES ('ord-2', 'WS-002', 'open', datetime('now'), datetime('now'), datetime('now'))
	`); err != nil {
		t.Fatalf("insert order: %v", err)
	}
	if _, err := db.Exec(`
		INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, created_at, updated_at)
		VALUES ('item-2', 'ord-2', 'Ramen', 1, 1000, 1000, datetime('now'), datetime('now'))
	`); err != nil {
		t.Fatalf("insert item: %v", err)
	}

	var ps string
	if err := db.QueryRow(`SELECT print_status FROM order_items WHERE id='item-2'`).Scan(&ps); err != nil {
		t.Fatalf("read print_status: %v", err)
	}
	if ps != "pending" {
		t.Errorf("expected print_status='pending', got %q", ps)
	}
}

// TestCouponsCodeIndexAllowsDuplicates verifies migration 030 dropped
// the UNIQUE on coupons.code (replaced with plain index). Backend
// enforces unique-per-organization at the API layer; the local UNIQUE
// was redundant defensive armor that turned into a footgun — a new
// HQ-created coupon reusing a soft-deleted local row's code would
// abort the entire sync transaction. With the plain index, duplicate
// codes are allowed (CouponEngine.findByCode picks the first row,
// which is the most recently synced).
func TestCouponsCodeIndexAllowsDuplicates(t *testing.T) {
	db := openTestDB(t)

	if _, err := db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value)
		VALUES ('c1', 'SAVE10', '10% off', 'percent', 10)
	`); err != nil {
		t.Fatalf("insert coupon: %v", err)
	}
	if _, err := db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value)
		VALUES ('c2', 'SAVE10', 'Duplicate', 'flat', 500)
	`); err != nil {
		t.Errorf("duplicate code should succeed post-030: %v", err)
	}
}

// TestMenuItemsSkuIDIndex verifies partial index on menu_items.sku_id.
func TestMenuItemsSkuIDIndex(t *testing.T) {
	db := openTestDB(t)
	assertIndexExists(t, db, "idx_menu_items_sku_id")
}
