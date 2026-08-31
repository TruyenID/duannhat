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
	// Local line resolved offline at 8% (the reduced type on the takeaway menu)
	// — Cloud re-resolved it to 10%, e.g. the menu override changed between the
	// offline sale and the sync.
	if _, err := e.db.Exec(`INSERT INTO order_items
		(id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status,
		 tax_type_id, tax_rate, tax_amount)
		VALUES ('it-1', 'ord-local', 'Bentō', 1, 1000, 1000, 'pending', 'tt-red', 8, 80)`); err != nil {
		t.Fatalf("seed item: %v", err)
	}

	e.reconcileOrderFromCloud("ord-local", map[string]any{
		"total_amount": "1100.00",
		"subtotal":     "1000.00",
		"tax_amount":   "100.00",
		"items": []any{
			map[string]any{
				"id":          "it-1",
				"unit_price":  "1000.00",
				"subtotal":    "1000.00",
				"tax_rate":    "10.00",
				"tax_amount":  "100.00",
				"tax_type_id": "tt-std",
			},
		},
	})

	var rate float64
	var taxAmt int
	var typeID string
	if err := e.db.QueryRow(`
		SELECT tax_rate, tax_amount, COALESCE(tax_type_id,'')
		FROM order_items WHERE id = 'it-1'`).Scan(&rate, &taxAmt, &typeID); err != nil {
		t.Fatal(err)
	}
	// Pre-fix: only unit_price/subtotal were adopted — rate stayed 8/80.
	if rate != 10 || taxAmt != 100 || typeID != "tt-std" {
		t.Errorf("line after reconcile = rate %v / tax %d / type %q, want 10/100/tt-std",
			rate, taxAmt, typeID)
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
	if _, err := e.db.Exec(`INSERT INTO order_items
		(id, customer_order_id, product_sku_id, menu_item_name, quantity,
		 unit_price, subtotal, topping_subtotal, status)
		VALUES ('item-xyz', 'ord-local', 'sku-large', 'Pho Large', 3,
		 1600, 4950, 50, 'pending')`); err != nil {
		t.Fatal(err)
	}
	if _, err := e.db.Exec(`INSERT INTO order_item_toppings
		(id, order_item_id, topping_group_item_id, product_sku_id, name,
		 modifier_type, quantity, unit_price)
		VALUES ('top-1', 'item-xyz', 'tgi-cheese', 'sku-cheddar', 'Cheese',
		 'add', 1, 50)`); err != nil {
		t.Fatal(err)
	}
	enqueueLikeHelper(t, e, "order.item_update", "ord-local", map[string]any{
		"item_id": "item-xyz",
		// The production shape (local_pos_phase1.go handleLocalPosUpdateItem).
		"patch": map[string]any{
			"product_sku_id":      "sku-large",
			"menu_product_sku_id": "menu-sku-large",
			"quantity":            3,
			"note":                "extra spicy",
			"toppings": []any{
				map[string]any{
					"topping_group_item_id": "tgi-cheese",
					"product_sku_id":        "sku-cheddar",
					"quantity":              1,
				},
			},
		},
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
	if sku, _ := call.body["product_sku_id"].(string); sku != "sku-large" {
		t.Errorf("product_sku_id not forwarded: %+v", call.body)
	}
	if menuSku, _ := call.body["menu_product_sku_id"].(string); menuSku != "menu-sku-large" {
		t.Errorf("menu_product_sku_id not forwarded: %+v", call.body)
	}
	toppings, ok := call.body["toppings"].([]any)
	if !ok || len(toppings) != 1 {
		t.Fatalf("resolved toppings not forwarded: %+v", call.body)
	}
	topping, _ := toppings[0].(map[string]any)
	if unitPrice, ok := topping["unit_price"].(float64); !ok || unitPrice != 50 {
		t.Errorf("stored topping unit_price not forwarded: %+v", topping)
	}
}
