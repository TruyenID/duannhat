package service

import (
	"database/sql"
	"testing"
)

// Audit fixes 1.1 + 2.6 (2026-07-14) — see order_service_tax_reresolve.go.

func seedReResolveFixture(t *testing.T, e *OrderEngine) {
	t.Helper()
	exec := func(q string, args ...any) {
		t.Helper()
		if _, err := e.db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v\n%s", err, q)
		}
	}
	exec(`INSERT INTO shop_settings (key, value) VALUES ('currency_code','JPY')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)
	// Reduced type: dine-in 10 / takeaway 8 (the flip-sensitive case).
	exec(`INSERT INTO tax_types (id, code, name, rate_dine_in, rate_takeaway, is_default, is_active)
		VALUES ('tt-red', 'REDUCED', '軽減税率', 10, 8, 1, 1)`)
	exec(`INSERT INTO menu_items (id, sku_id, name, price, is_active, tax_type_id, is_alcohol)
		VALUES ('mi-1', 'sku-1', 'Bentō', 1000, 1, 'tt-red', 0)`)
	exec(`INSERT INTO orders
		(id, order_code, order_number, order_type, status, opened_at,
		 subtotal, discount_amount, tax_amount, service_charge, total_amount, paid_amount,
		 is_tax_included, created_at, updated_at)
		VALUES ('o1', 'RR-1', 1, 'takeaway', 'open', datetime('now'),
		 1000, 0, 80, 0, 1080, 0, 0, datetime('now'), datetime('now'))`)
	exec(`INSERT INTO order_items
		(id, customer_order_id, product_sku_id, menu_item_id, menu_item_name,
		 quantity, unit_price, subtotal, status, tax_type_id, tax_rate, tax_amount)
		VALUES ('i1', 'o1', 'sku-1', 'mi-1', 'Bentō', 1, 1000, 1000, 'pending', 'tt-red', 8, 80)`)
}

// 1.1 — an order_type flip must re-resolve every line (8% takeaway → 10%
// dine-in on a reduced type) and re-run the per-rate engine, mirroring Cloud.
func TestUpdateMeta_OrderTypeFlipReResolvesLineTax(t *testing.T) {
	e, db := newOrderEngineForTest(t)
	seedReResolveFixture(t, e)

	dineIn := "dine_in"
	if _, err := e.UpdateMeta("o1", OrderUpdateInput{OrderType: &dineIn}); err != nil {
		t.Fatalf("UpdateMeta: %v", err)
	}

	var rate float64
	var lineTax, orderTax, orderTotal int
	if err := db.QueryRow(`SELECT tax_rate, tax_amount FROM order_items WHERE id = 'i1'`).Scan(&rate, &lineTax); err != nil {
		t.Fatal(err)
	}
	if err := db.QueryRow(`SELECT tax_amount, total_amount FROM orders WHERE id = 'o1'`).Scan(&orderTax, &orderTotal); err != nil {
		t.Fatal(err)
	}

	// Pre-fix: rate stayed 8 / tax 80 — the LAN under-collected ¥20.
	if rate != 10 {
		t.Errorf("line tax_rate = %v, want 10 (re-resolved for dine_in)", rate)
	}
	if lineTax != 100 {
		t.Errorf("line tax_amount = %d, want 100", lineTax)
	}
	if orderTax != 100 || orderTotal != 1100 {
		t.Errorf("order tax/total = %d/%d, want 100/1100", orderTax, orderTotal)
	}
}

// 2.6 — a legacy NULL-rate line (created offline before the first tax-type
// sync) is lazily re-resolved on the next recalc once types exist locally.
func TestRecalc_LazyReStampsUnstampedLinesOnceTypesExist(t *testing.T) {
	e, db := newOrderEngineForTest(t)
	seedReResolveFixture(t, e)
	// Simulate the offline-created line: no snapshot at all.
	if _, err := db.Exec(`UPDATE order_items SET tax_rate = NULL, tax_type_id = NULL, tax_amount = 0 WHERE id = 'i1'`); err != nil {
		t.Fatal(err)
	}

	if err := e.RecalcOrderTotals("o1"); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	var rate float64
	var typeID string
	var orderTax int
	if err := db.QueryRow(`SELECT tax_rate, COALESCE(tax_type_id,'') FROM order_items WHERE id = 'i1'`).Scan(&rate, &typeID); err != nil {
		t.Fatal(err)
	}
	_ = db.QueryRow(`SELECT tax_amount FROM orders WHERE id = 'o1'`).Scan(&orderTax)

	// takeaway on the reduced type → 8%; pre-fix the line stayed NULL forever.
	if rate != 8 || typeID != "tt-red" {
		t.Errorf("line rate/type = %v/%q, want 8/tt-red (lazy re-stamp)", rate, typeID)
	}
	if orderTax != 80 {
		t.Errorf("order tax = %d, want 80", orderTax)
	}
}

// Round-3 B5 — a line whose product dropped off the local menu keeps its OWN
// tax type on re-resolve (Cloud parity: product.taxType outlives the menu),
// instead of falling to the branch/brand default.
func TestReResolve_MenuRowGoneKeepsLineType(t *testing.T) {
	e, db := newOrderEngineForTest(t)
	seedReResolveFixture(t, e)
	// A second, DEFAULT standard type the line must NOT fall back to.
	if _, err := db.Exec(`INSERT INTO tax_types (id, code, name, rate_dine_in, rate_takeaway, is_default, is_active)
		VALUES ('tt-std', 'STANDARD', '標準税率', 10, 10, 0, 1)`); err != nil {
		t.Fatal(err)
	}
	// Menu mirror lost the product (menu re-sync dropped it).
	if _, err := db.Exec(`DELETE FROM menu_items WHERE id = 'mi-1'`); err != nil {
		t.Fatal(err)
	}

	dineIn := "dine_in"
	if _, err := e.UpdateMeta("o1", OrderUpdateInput{OrderType: &dineIn}); err != nil {
		t.Fatalf("UpdateMeta: %v", err)
	}

	var rate float64
	var typeID string
	if err := db.QueryRow(`SELECT tax_rate, COALESCE(tax_type_id,'') FROM order_items WHERE id = 'i1'`).Scan(&rate, &typeID); err != nil {
		t.Fatal(err)
	}
	// Keeps tt-red (its own type) and re-picks the dine-in rate 10 — NOT the
	// default type.
	if typeID != "tt-red" || rate != 10 {
		t.Errorf("line = type %q rate %v, want tt-red/10 (own type kept off-menu)", typeID, rate)
	}
}

// Round-3 B4 — a stamped line is never UN-stamped when resolution falls to the
// legacy no-type fallback (e.g. every local tax type vanished).
func TestReResolve_NeverUnstampsWhenTypesVanish(t *testing.T) {
	e, db := newOrderEngineForTest(t)
	seedReResolveFixture(t, e)
	// All types gone locally (pathological sync state) + menu row too.
	if _, err := db.Exec(`DELETE FROM tax_types`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`DELETE FROM menu_items`); err != nil {
		t.Fatal(err)
	}

	dineIn := "dine_in"
	if _, err := e.UpdateMeta("o1", OrderUpdateInput{OrderType: &dineIn}); err != nil {
		t.Fatalf("UpdateMeta: %v", err)
	}

	var rate sql.NullFloat64
	var typeID string
	if err := db.QueryRow(`SELECT tax_rate, COALESCE(tax_type_id,'') FROM order_items WHERE id = 'i1'`).Scan(&rate, &typeID); err != nil {
		t.Fatal(err)
	}
	// Snapshot survives — pre-fix it was overwritten with NULL/legacy.
	if !rate.Valid || typeID != "tt-red" {
		t.Errorf("line = type %q rate valid=%v, want snapshot kept (tt-red, rate NOT NULL)", typeID, rate.Valid)
	}
}

// Round-3 B8 — reconcile must accept escalated as JSON bool OR 0/1 number.
func TestJsonToBool_AcceptsBoolAndNumbers(t *testing.T) {
	cases := []struct {
		in   any
		want bool
		ok   bool
	}{
		{true, true, true}, {false, false, true},
		{float64(1), true, true}, {float64(0), false, true},
		{"true", false, false}, {nil, false, false},
	}
	for _, c := range cases {
		got, ok := jsonToBool(c.in)
		if got != c.want || ok != c.ok {
			t.Errorf("jsonToBool(%v) = (%v,%v), want (%v,%v)", c.in, got, ok, c.want, c.ok)
		}
	}
}
