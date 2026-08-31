package store

import (
	"path/filepath"
	"testing"
)

// Migration 040 (plan-045) must:
//  1. Add orders.{tax_rounding_mode DEFAULT 'round', tax_rounding_decimals DEFAULT 0}.
//  2. Add order_items.{refund_of_item_id, refunded_quantity DEFAULT 0}.
//  3. Create the order_conditions ledger table + its two indexes.
func TestMigration040_RefundRoundingColumns(t *testing.T) {
	db, err := Open(filepath.Join(t.TempDir(), "m040.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	// 1. orders rounding snapshot — omitting the columns defaults mode to
	//    'round' and decimals to 0 (rev-B).
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_number, order_type, status,
			opened_at, subtotal, discount_amount, service_charge,
			tax_amount, total_tip, total_amount, paid_amount,
			organization_id, brand_id, branch_id, created_at, updated_at)
		VALUES ('o40', 'WS-40', 40, 'spot', 'open',
			datetime('now'), 0, 0, 0, 0, 0, 0, 0,
			'', '', '', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("insert order: %v", err)
	}
	var mode string
	var decimals any
	if err := db.QueryRow(`SELECT tax_rounding_mode, tax_rounding_decimals FROM orders WHERE id='o40'`).Scan(&mode, &decimals); err != nil {
		t.Fatalf("read rounding snapshot: %v", err)
	}
	if mode != "round" {
		t.Errorf("tax_rounding_mode default: want round, got %q", mode)
	}
	if decimals != int64(0) {
		t.Errorf("tax_rounding_decimals default: want 0, got %v", decimals)
	}

	// 2. order_items refund columns — refunded_quantity defaults 0,
	//    refund_of_item_id NULL on a normal line.
	if _, err := db.Exec(`
		INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, created_at, updated_at)
		VALUES ('i40', 'o40', 'Ramen', 1, 1000, 1000, datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("insert item: %v", err)
	}
	var refundOf any
	var refundedQty int
	if err := db.QueryRow(`SELECT refund_of_item_id, refunded_quantity FROM order_items WHERE id='i40'`).Scan(&refundOf, &refundedQty); err != nil {
		t.Fatalf("read refund columns: %v", err)
	}
	if refundOf != nil {
		t.Errorf("refund_of_item_id default: want NULL, got %v", refundOf)
	}
	if refundedQty != 0 {
		t.Errorf("refunded_quantity default: want 0, got %d", refundedQty)
	}

	// 3. order_conditions table + indexes exist and accept a signed amount.
	assertTableExists(t, db, "order_conditions")
	assertIndexExists(t, db, "idx_order_conditions_conditionable")
	assertIndexExists(t, db, "idx_order_conditions_type")

	if _, err := db.Exec(`
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, meta, created_at, updated_at)
		VALUES ('c40', 'order_item', 'i40', 'refund', 'manual', 'Refund', 10, -110, 'JPY', '{"refund_of_item_id":"i40"}', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("insert order_condition: %v", err)
	}
	var amount float64
	if err := db.QueryRow(`SELECT amount FROM order_conditions WHERE id='c40'`).Scan(&amount); err != nil {
		t.Fatalf("read condition amount: %v", err)
	}
	if amount != -110 {
		t.Errorf("signed amount round-trip: want -110, got %v", amount)
	}
}
