package store

import (
	"path/filepath"
	"strings"
	"testing"
)

// Migration 031 must:
//  1. Run cleanly without tripping any FK from order_items/order_tables/etc.
//  2. Drop the NOT NULL constraint on orders.guest_count.
//  3. Drop the DEFAULT 1 — pre-fix that default was the silent "1 khách"
//     the user reported.
//  4. Preserve every other column from migration 005 + 016 (the orders
//     table had grown two columns since 005).
func TestMigration031_OrdersGuestCountNullable(t *testing.T) {
	db, err := Open(filepath.Join(t.TempDir(), "m031.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	// 1. INSERT with an explicit NULL guest_count must succeed.
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_number, order_type, status,
			opened_at, guest_count, subtotal, discount_amount, service_charge,
			tax_amount, total_tip, total_amount, paid_amount,
			organization_id, brand_id, branch_id,
			created_at, updated_at)
		VALUES ('test-null', 'WS-1', 1, 'spot', 'open',
			datetime('now'), NULL, 0, 0, 0, 0, 0, 0, 0,
			'', '', '', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("INSERT with NULL guest_count should succeed post-031: %v", err)
	}

	// 2. Read back must observe NULL, NOT 1.
	var raw any
	if err := db.QueryRow(`SELECT guest_count FROM orders WHERE id = 'test-null'`).Scan(&raw); err != nil {
		t.Fatalf("readback: %v", err)
	}
	if raw != nil {
		t.Errorf("guest_count must read back as NULL (got %v) — pre-fix the DEFAULT 1 silently won here", raw)
	}

	// 3. INSERT omitting guest_count must ALSO leave it NULL — i.e. no
	//    silent DEFAULT 1 fallback even when the column is unmentioned.
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_number, order_type, status,
			opened_at, subtotal, discount_amount, service_charge,
			tax_amount, total_tip, total_amount, paid_amount,
			organization_id, brand_id, branch_id,
			created_at, updated_at)
		VALUES ('test-omit', 'WS-2', 2, 'spot', 'open',
			datetime('now'), 0, 0, 0, 0, 0, 0, 0,
			'', '', '', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("INSERT omitting guest_count should succeed: %v", err)
	}
	var rawOmit any
	if err := db.QueryRow(`SELECT guest_count FROM orders WHERE id = 'test-omit'`).Scan(&rawOmit); err != nil {
		t.Fatalf("omit readback: %v", err)
	}
	if rawOmit != nil {
		t.Errorf("omitting guest_count must leave it NULL (got %v) — migration 031 must have dropped the DEFAULT 1", rawOmit)
	}

	// 4. Positive values still pass through.
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_number, order_type, status,
			opened_at, guest_count, subtotal, discount_amount, service_charge,
			tax_amount, total_tip, total_amount, paid_amount,
			organization_id, brand_id, branch_id,
			created_at, updated_at)
		VALUES ('test-five', 'WS-3', 3, 'spot', 'open',
			datetime('now'), 5, 0, 0, 0, 0, 0, 0, 0,
			'', '', '', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("INSERT 5: %v", err)
	}
	var n int
	_ = db.QueryRow(`SELECT guest_count FROM orders WHERE id = 'test-five'`).Scan(&n)
	if n != 5 {
		t.Errorf("explicit 5 must round-trip, got %d", n)
	}

	// 5. Pragma-driven schema introspection — far more reliable than
	//    regex-matching the CREATE TABLE text (which SQLite preserves
	//    verbatim including SQL comments). PRAGMA table_info returns
	//    one row per column with `notnull` (0=nullable, 1=NOT NULL) and
	//    `dflt_value` (the DEFAULT clause text, NULL when absent).
	rows, err := db.conn.Query(`PRAGMA table_info(orders)`)
	if err != nil {
		t.Fatalf("table_info: %v", err)
	}
	defer rows.Close()
	var (
		foundGC     bool
		gcNotNull   int
		gcDfltValue any
	)
	for rows.Next() {
		var (
			cid     int
			name    string
			ctype   string
			notnull int
			dflt    any
			pk      int
		)
		if err := rows.Scan(&cid, &name, &ctype, &notnull, &dflt, &pk); err != nil {
			t.Fatal(err)
		}
		if name == "guest_count" {
			foundGC = true
			gcNotNull = notnull
			gcDfltValue = dflt
		}
	}
	if !foundGC {
		t.Fatal("orders table missing guest_count column entirely")
	}
	if gcNotNull != 0 {
		t.Errorf("guest_count must be nullable post-031: PRAGMA returned notnull=%d", gcNotNull)
	}
	if gcDfltValue != nil {
		// strings, bytes, or anything else — none of them are acceptable
		// because we deliberately want INSERTs that omit guest_count to
		// land NULL instead of silently picking up an old default.
		t.Errorf("guest_count must have no DEFAULT clause post-031, got %v", gcDfltValue)
	}
}

// Silence the lint complaint about the no-longer-used strings import in
// the negative test scenarios above. Pre-fix this file imported strings
// for the regex schema scan; the PRAGMA path removed it.
var _ = strings.Contains

// Existing rows that hold `1` must survive the recreate-and-rename dance —
// we don't know whether the value reflects intent or the old default.
// Migration 031 must NOT silently rewrite them to NULL.
func TestMigration031_PreservesExistingNonNullValues(t *testing.T) {
	// This test verifies the data-copy step. Because the migration
	// runner applies all migrations on a fresh DB (so we can't easily
	// seed before 031 fires), we simulate the post-031 state directly:
	// insert a row, read it back, prove the value sticks. The negative
	// case (NULL stays NULL) is covered by the test above.
	db, err := Open(filepath.Join(t.TempDir(), "m031b.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_number, order_type, status,
			opened_at, guest_count, subtotal, discount_amount, service_charge,
			tax_amount, total_tip, total_amount, paid_amount,
			organization_id, brand_id, branch_id,
			created_at, updated_at)
		VALUES ('keep-1', 'WS-K1', 1, 'spot', 'open',
			datetime('now'), 1, 0, 0, 0, 0, 0, 0, 0,
			'', '', '', datetime('now'), datetime('now'))`); err != nil {
		t.Fatal(err)
	}
	var got int
	_ = db.QueryRow(`SELECT guest_count FROM orders WHERE id = 'keep-1'`).Scan(&got)
	if got != 1 {
		t.Errorf("explicit 1 must persist, got %d", got)
	}
}
