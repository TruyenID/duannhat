package service

// Regression tests for RecordPayment error surfacing.
//
// Previous behaviour silently swallowed the `SELECT total_amount` error: a
// non-existent orderID returned sql.ErrNoRows, the SELECT returned 0, then
// the UPDATE matched 0 rows but RecordPayment still returned nil. The
// caller broadcast `order_paid` + wrote an audit row for an order that
// never existed. Tests below pin the new behaviour so that class of bug
// cannot regress.

import (
	"database/sql"
	"errors"
	"strings"
	"testing"
)

func TestRecordPaymentMissingOrderReturnsError(t *testing.T) {
	e, _ := newOrderEngineForTest(t)

	err := e.RecordPayment("ghost-order-id", "cash")
	if err == nil {
		t.Fatal("expected error for missing order, got nil")
	}
	// Both the handler's `errors.Is(err, sql.ErrNoRows)` branch AND the
	// substring fallback need to succeed — RecordPayment wraps the SELECT
	// error with `%w` so the sentinel survives. Asserting both here
	// pins the actual contract the handler relies on; the previous test
	// used `errors.Is(err, err)` which is trivially true for any non-nil.
	if !errors.Is(err, sql.ErrNoRows) {
		t.Errorf("expected wrapped sql.ErrNoRows, got %T %q", err, err.Error())
	}
	if !strings.Contains(err.Error(), "ghost-order-id") {
		t.Errorf("expected error message to mention the order id, got %q", err.Error())
	}
}

func TestRecordPaymentHappyPathStillCloses(t *testing.T) {
	e, db := newOrderEngineForTest(t)
	// Seed: insert a minimal viable order — same shape used elsewhere in
	// tests, total_amount = 12000 so we can assert paid_amount mirrors it.
	_, err := db.Exec(`
		INSERT INTO orders (
			id, order_code, order_number, order_type, status,
			opened_at, guest_count,
			subtotal, discount_amount, service_charge, tax_amount,
			total_tip, total_amount, paid_amount,
			organization_id, brand_id, branch_id,
			created_at, updated_at
		) VALUES ('order-xyz', 'OC1', 1, 'spot', 'preparing',
			datetime('now'), 1, 12000, 0, 0, 0, 0, 12000, 0,
			'', '', '', datetime('now'), datetime('now'))
	`)
	if err != nil {
		t.Fatalf("seed: %v", err)
	}

	if err := e.RecordPayment("order-xyz", "card"); err != nil {
		t.Fatalf("RecordPayment: %v", err)
	}

	var status, method string
	var paid int
	if err := db.QueryRow(
		"SELECT status, payment_method, paid_amount FROM orders WHERE id = ?", "order-xyz",
	).Scan(&status, &method, &paid); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if status != "closed" {
		t.Errorf("status: got %q, want closed", status)
	}
	if method != "card" {
		t.Errorf("method: got %q, want card", method)
	}
	if paid != 12000 {
		t.Errorf("paid_amount: got %d, want 12000", paid)
	}
}
