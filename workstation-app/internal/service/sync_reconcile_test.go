package service

import "testing"

// #525 — a POS-created order is priced locally, but Cloud re-prices it on
// addItems (promo / a specific menu line). reconcileOrderFromCloud must adopt
// the Cloud-authoritative totals + line prices so the bill the cashier settles
// matches the Cloud balance — otherwise "pay all remaining" rings up more than
// the outstanding and payment.create 422s ("exceeds outstanding order balance").
func TestReconcileOrderFromCloud_AdoptsCloudTotals(t *testing.T) {
	e, db := newSyncTestEngine(t, "")

	// Local POS order priced HIGH: item 2246, total 2583.
	if _, err := db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status, opened_at,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, cloud_id, created_at, updated_at
	) VALUES ('o1', 'ORD-1', 1, 'spot', 'open', datetime('now'),
		2246, 0, 86, 251, 0, 2583, 0, '','','', 'cloud-o1', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO order_items
		(id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, status)
		VALUES ('i1', 'o1', 'sku-1', 'Beef Pho', 1, 2246, 2246, 'pending')`); err != nil {
		t.Fatalf("seed item: %v", err)
	}

	// Cloud addItems response — cloudPost already unwraps the envelope, so `resp`
	// IS the order data (re-priced to 1912.50 / total 2201, decimal strings).
	resp := map[string]any{
		"total_amount":    "2201.00",
		"subtotal":        "1912.50",
		"tax_amount":      "191.25",
		"service_charge":  "95.62",
		"discount_amount": "0.00",
		"items": []any{
			map[string]any{"id": "i1", "unit_price": "1912.50", "subtotal": "1912.50"},
		},
	}

	e.reconcileOrderFromCloud("o1", resp)

	var total, sub int
	if err := db.QueryRow("SELECT total_amount, subtotal FROM orders WHERE id='o1'").Scan(&total, &sub); err != nil {
		t.Fatal(err)
	}
	if total != 2201 {
		t.Fatalf("order total: want 2201 (Cloud), got %d", total)
	}
	if sub != 1913 { // 1912.50 rounds to 1913
		t.Fatalf("order subtotal: want 1913, got %d", sub)
	}

	var unit int
	if err := db.QueryRow("SELECT unit_price FROM order_items WHERE id='i1'").Scan(&unit); err != nil {
		t.Fatal(err)
	}
	if unit != 1913 {
		t.Fatalf("item unit_price: want 1913 (Cloud), got %d", unit)
	}
}

// A malformed / missing data payload must be a safe no-op (never touch the row).
func TestReconcileOrderFromCloud_NoopOnBadPayload(t *testing.T) {
	e, db := newSyncTestEngine(t, "")
	if _, err := db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status, opened_at,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, cloud_id, created_at, updated_at
	) VALUES ('o2', 'ORD-2', 2, 'spot', 'open', datetime('now'),
		2246, 0, 86, 251, 0, 2583, 0, '','','', 'cloud-o2', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	e.reconcileOrderFromCloud("o2", map[string]any{})            // no "data"
	e.reconcileOrderFromCloud("o2", map[string]any{"data": "x"}) // wrong type

	var total int
	if err := db.QueryRow("SELECT total_amount FROM orders WHERE id='o2'").Scan(&total); err != nil {
		t.Fatal(err)
	}
	if total != 2583 {
		t.Fatalf("total must be untouched (2583), got %d", total)
	}
}
