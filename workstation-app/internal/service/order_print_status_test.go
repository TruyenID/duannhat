package service

// Regression tests for the print_status / item status split (migration 009).
// Pins down two invariants the refactor relied on:
//   1. MarkItemPrinted only touches print_status + printed_at — never the
//      cloud-aligned status field — so a freshly printed item stays
//      whatever the cook left it at (pending / preparing / etc.).
//   2. createItem seeds new items with print_status = 'pending'.
//
// Class doc: PR #19 (chore/consolidate-item-status-enum).

import (
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

func TestMarkItemPrinted_KeepsStatusUntouched(t *testing.T) {
	db, err := store.Open(filepath.Join(t.TempDir(), "print_status.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	// Minimal order + item via direct INSERT (skips OrderEngine validation).
	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := db.Exec(`
		INSERT INTO orders (
			id, order_code, order_number, order_type, status,
			opened_at, guest_count,
			subtotal, discount_amount, service_charge, tax_amount,
			total_tip, total_amount, paid_amount,
			organization_id, brand_id, branch_id,
			created_at, updated_at
		) VALUES (?, ?, 1, 'spot', 'open',
			?, 1, 0, 0, 0, 0, 0, 0, 0, 'org', 'brand', 'branch', ?, ?)
	`, "order-1", "WS-000001", now, now, now); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.Exec(`
		INSERT INTO order_items (
			id, customer_order_id, menu_item_name,
			quantity, unit_price, subtotal,
			printer_group, status, print_status,
			created_at, updated_at
		) VALUES ('item-1', 'order-1', 'Ramen',
			1, 1000, 1000, 'kitchen', 'preparing', 'pending', ?, ?)
	`, now, now); err != nil {
		t.Fatalf("seed item: %v", err)
	}

	engine := NewOrderEngine(db, 0)
	if err := engine.MarkItemPrinted("item-1", 1, now); err != nil {
		t.Fatalf("MarkItemPrinted: %v", err)
	}

	var status, printStatus, printedAt string
	if err := db.QueryRow(
		"SELECT status, print_status, COALESCE(printed_at,'') FROM order_items WHERE id = ?",
		"item-1",
	).Scan(&status, &printStatus, &printedAt); err != nil {
		t.Fatalf("read back: %v", err)
	}

	// Status field stays where the cook left it. Print is a separate signal.
	if status != "preparing" {
		t.Errorf("status: want preparing (unchanged by print), got %q", status)
	}
	if printStatus != string(PrintStatusSentToKitchen) {
		t.Errorf("print_status: want sent_to_kitchen, got %q", printStatus)
	}
	if printedAt == "" {
		t.Error("printed_at: want non-empty timestamp, got empty")
	}
}

// TestMarkItemPrinted_DeltaPrinting verifies the printed_quantity bookkeeping
// that drives "print only the newly-added units" (plan-038 follow-up):
//   - after firing qty 2, printed_quantity = 2 → unprinted delta 0
//   - bumping quantity to 5 reopens a delta of 3
//   - firing again marks printed_quantity = 5 → delta back to 0
func TestMarkItemPrinted_DeltaPrinting(t *testing.T) {
	db, err := store.Open(filepath.Join(t.TempDir(), "delta_print.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := db.Exec(`
		INSERT INTO orders (
			id, order_code, order_number, order_type, status,
			opened_at, guest_count,
			subtotal, discount_amount, service_charge, tax_amount,
			total_tip, total_amount, paid_amount,
			organization_id, brand_id, branch_id,
			created_at, updated_at
		) VALUES ('order-1', 'WS-000001', 1, 'spot', 'open',
			?, 1, 0, 0, 0, 0, 0, 0, 0, 'org', 'brand', 'branch', ?, ?)
	`, now, now, now); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.Exec(`
		INSERT INTO order_items (
			id, customer_order_id, menu_item_name,
			quantity, unit_price, subtotal,
			printer_group, status, print_status,
			created_at, updated_at
		) VALUES ('item-1', 'order-1', 'Ramen',
			2, 1000, 2000, 'kitchen', 'pending', 'pending', ?, ?)
	`, now, now); err != nil {
		t.Fatalf("seed item: %v", err)
	}

	engine := NewOrderEngine(db, 0)

	readDelta := func() (qty, printedQty int) {
		if err := db.QueryRow(
			"SELECT quantity, COALESCE(printed_quantity,0) FROM order_items WHERE id = 'item-1'",
		).Scan(&qty, &printedQty); err != nil {
			t.Fatalf("read delta: %v", err)
		}
		return qty, printedQty
	}

	// Fire the initial 2 units.
	if err := engine.MarkItemPrinted("item-1", 2, now); err != nil {
		t.Fatalf("MarkItemPrinted(2): %v", err)
	}
	if qty, printed := readDelta(); qty-printed != 0 {
		t.Fatalf("after first fire: want delta 0, got qty=%d printed=%d", qty, printed)
	}

	// Cashier bumps the line to 5 — printed_quantity is untouched, so a delta
	// of 3 reopens.
	if _, err := db.Exec("UPDATE order_items SET quantity = 5 WHERE id = 'item-1'"); err != nil {
		t.Fatalf("bump qty: %v", err)
	}
	if qty, printed := readDelta(); qty-printed != 3 {
		t.Fatalf("after bump to 5: want delta 3, got qty=%d printed=%d", qty, printed)
	}

	// Fire again at the new full quantity — delta closes.
	if err := engine.MarkItemPrinted("item-1", 5, now); err != nil {
		t.Fatalf("MarkItemPrinted(5): %v", err)
	}
	if qty, printed := readDelta(); qty-printed != 0 {
		t.Fatalf("after second fire: want delta 0, got qty=%d printed=%d", qty, printed)
	}
}

func TestCreateItem_DefaultPrintStatusPending(t *testing.T) {
	db, err := store.Open(filepath.Join(t.TempDir(), "create_item.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	now := time.Now().UTC()
	// Seed branch + a menu item so OrderEngine.Create finds price + printer_group.
	if _, err := db.Exec(`
		INSERT INTO settings (key, value) VALUES
			('organization_id', 'org'), ('brand_id', 'brand'), ('workstation_branch_id', 'branch')
	`); err != nil {
		t.Fatalf("seed settings: %v", err)
	}
	if _, err := db.Exec(`
		INSERT INTO menu_items (id, name, price, category, printer_group, is_active, sort_order, local_updated_at)
		VALUES ('menu-1', 'Ramen', 1000, 'main', 'kitchen', 1, 0, ?)
	`, now.Format(time.RFC3339)); err != nil {
		t.Fatalf("seed menu_item: %v", err)
	}

	engine := NewOrderEngine(db, 0)
	order, err := engine.Create(CreateOrderInput{
		Items: []CreateItemInput{{MenuItemID: "menu-1", Quantity: 1}},
	}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if len(order.Items) != 1 {
		t.Fatalf("expected 1 item, got %d", len(order.Items))
	}

	got := order.Items[0]
	if got.PrintStatus != PrintStatusPending {
		t.Errorf("print_status: want pending, got %q", got.PrintStatus)
	}
	if got.Status != ItemStatusPending {
		t.Errorf("status: want pending, got %q", got.Status)
	}
}
