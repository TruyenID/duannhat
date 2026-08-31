package service

import "testing"

// Audit fixes 2.1 + item_update payload shape (2026-07-14).

// 2.1 — reconcileOrderFromCloud must adopt Cloud's PER-LINE tax snapshot, not
// just the order-level totals: Cloud re-resolves at item_add time (escalation
// / overrides can differ from the offline local resolution), and a stale local
// rate feeds the LAN Z-report the wrong figure.
func TestReconcileOrderFromCloud_AdoptsPerLineTax(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	// Local line resolved offline at 8% (reduced, takeaway) — Cloud escalated
	// it to 10% (alcohol topping detected server-side).
	if _, err := e.db.Exec(`INSERT INTO order_items
		(id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status,
		 tax_type_id, tax_rate, tax_amount, tax_alcohol_escalated)
		VALUES ('it-1', 'ord-local', 'Bentō', 1, 1000, 1000, 'pending', 'tt-red', 8, 80, 0)`); err != nil {
		t.Fatalf("seed item: %v", err)
	}

	e.reconcileOrderFromCloud("ord-local", map[string]any{
		"total_amount": "1100.00",
		"subtotal":     "1000.00",
		"tax_amount":   "100.00",
		"items": []any{
			map[string]any{
				"id":                    "it-1",
				"unit_price":            "1000.00",
				"subtotal":              "1000.00",
				"tax_rate":              "10.00",
				"tax_amount":            "100.00",
				"tax_type_id":           "tt-std",
				"tax_alcohol_escalated": true,
			},
		},
	})

	var rate float64
	var taxAmt, escalated int
	var typeID string
	if err := e.db.QueryRow(`
		SELECT tax_rate, tax_amount, COALESCE(tax_type_id,''), tax_alcohol_escalated
		FROM order_items WHERE id = 'it-1'`).Scan(&rate, &taxAmt, &typeID, &escalated); err != nil {
		t.Fatal(err)
	}
	// Pre-fix: only unit_price/subtotal were adopted — rate stayed 8/80.
	if rate != 10 || taxAmt != 100 || typeID != "tt-std" || escalated != 1 {
		t.Errorf("line after reconcile = rate %v / tax %d / type %q / escalated %d, want 10/100/tt-std/1",
			rate, taxAmt, typeID, escalated)
	}

	var orderTax int
	_ = e.db.QueryRow(`SELECT tax_amount FROM orders WHERE id = 'ord-local'`).Scan(&orderTax)
	if orderTax != 100 {
		t.Errorf("order tax_amount = %d, want 100", orderTax)
	}
}

// Found while fixing 2.4: the POS call site enqueues the item edit NESTED
// under "patch" but the sync handler only read the FLAT keys — every LAN
// qty/note edit synced UP as nulls and Cloud patched nothing. The handler now
// reads both shapes; this pins the REAL production payload shape.
func TestOrderItemUpdate_ForwardsNestedPatchShape(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.item_update", "ord-local", map[string]any{
		"item_id": "item-xyz",
		// The production shape (local_pos_phase1.go handleLocalPosUpdateItem).
		"patch": map[string]any{"quantity": 3, "note": "extra spicy"},
	})
	forceOnline(e)
	e.processQueue()

	call := rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/items/item-xyz")
	if q, ok := call.body["quantity"].(float64); !ok || q != 3 {
		t.Errorf("quantity not forwarded from nested patch: %+v", call.body)
	}
	if n, _ := call.body["note"].(string); n != "extra spicy" {
		t.Errorf("note not forwarded from nested patch: %+v", call.body)
	}
}
