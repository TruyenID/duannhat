package handler

import (
	"bytes"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// TestNormalizeOrderForPrint_ResolvesNamesAndAdditiveTotals proves the payment
// slip fix: a kiosk-style order (blank menu_item_name, zeroed totals) gets its
// item names resolved from the catalog and its money broken down additively
// (subtotal + service + tax = total), so the printed slip is neither empty nor
// mismatched with the kiosk screen.
func TestNormalizeOrderForPrint_ResolvesNamesAndAdditiveTotals(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db, 10)

	for _, q := range []string{
		`INSERT INTO shop_settings (key, value) VALUES ('tax_rate', '10.00')`,
		`INSERT INTO shop_settings (key, value) VALUES ('service_charge_rate', '5.00')`,
		`INSERT INTO pos_products (id, name) VALUES ('prod-1', 'Regular')`,
		`INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES ('sku-1', 'prod-1', 'L', 1870)`,
		`INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES ('sku-2', 'prod-1', 'XL', 2210)`,
	} {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}

	o := &service.Order{
		ID: "ord-1", OrderCode: "ORD-2026-4240", TableNumber: "B1",
		Items: []service.Item{
			{ID: "it-1", ProductSkuID: "sku-1", MenuItemName: "", Quantity: 3, UnitPrice: 1870, Status: service.ItemStatus("pending")},
			{ID: "it-2", ProductSkuID: "sku-2", MenuItemName: "", Quantity: 1, UnitPrice: 2210, Status: service.ItemStatus("pending")},
		},
	}

	s.normalizeOrderForPrint(o)

	if o.Items[0].MenuItemName != "Regular" || o.Items[1].MenuItemName != "Regular" {
		t.Errorf("names not resolved: %q / %q", o.Items[0].MenuItemName, o.Items[1].MenuItemName)
	}
	if o.Subtotal != 7820 || o.ServiceCharge != 391 || o.TaxAmount != 782 || o.TotalAmount != 8993 {
		t.Errorf("additive totals wrong: subtotal=%d service=%d tax=%v total=%d (want 7820/391/782/8993)",
			o.Subtotal, o.ServiceCharge, o.TaxAmount, o.TotalAmount)
	}

	// Render the paid slip on a partial payment → product rows + additive
	// breakdown + real remaining all appear.
	//
	// Locale is pinned because the assertions below match Vietnamese labels.
	// This test is about the MONEY breakdown, not the language: it was written
	// when the print layer's no-locale default was vi, and silently broke when
	// that default became ja. Pinning states the intent instead of leaning on a
	// default that is free to change.
	cfg := service.PrintJobConfig{StoreName: "Store", PaperWidth: 48, TaxRate: 10, Locale: "vi"}
	out := service.FormatPaidTicket(o, o.Items, 0, cfg, service.PaymentSlipInfo{
		PaymentMethod: "cash", AmountPaid: 4000, Remaining: 4993,
	})
	for _, want := range []string{"Regular", "Tam tinh", "7,820", "Phi phuc vu", "391", "Thue", "782", "Tong", "8,993", "Con lai", "4,993"} {
		if !bytes.Contains(out, []byte(want)) {
			t.Errorf("slip missing %q", want)
		}
	}
}

// deriveSplitState mirrors backend CustomerOrderSplitStatusController: split
// metadata from the earliest confirmed payment, paid_count = confirmed count,
// remaining = total - sum(confirmed amounts).
func TestDeriveSplitState(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)

	// 4-way split of a ¥4000 order; two people have paid ¥1000 each. Metadata
	// uses the field names the kiosk/pos clients actually send.
	if _, err := db.Exec(`
		INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, metadata, created_at)
		VALUES
		  ('p1', 'o-1', 'qr', 1000, 'confirmed', 'i1', '{"split_mode":"equal","total_bills":4,"bill_index":0,"expected_total_amount":1000}', '2026-06-11T10:00:00Z'),
		  ('p2', 'o-1', 'qr', 1000, 'pending',   'i2', '{"split_mode":"equal","total_bills":4,"bill_index":1,"expected_total_amount":1000}', '2026-06-11T10:05:00Z'),
		  ('p3', 'o-1', 'qr', 1000, 'failed',    'i3', NULL, '2026-06-11T10:06:00Z')
	`); err != nil {
		t.Fatalf("seed payments: %v", err)
	}

	st := s.deriveSplitState("o-1", 4000, "")

	if st.splitCount != 4 {
		t.Errorf("splitCount = %d, want 4 (from total_bills)", st.splitCount)
	}
	// Per-slip fields come from the LATEST non-failed payment (p2: bill_index 1).
	if st.slipIndex != 2 {
		t.Errorf("slipIndex = %d, want 2 (bill_index 1 + 1)", st.slipIndex)
	}
	if st.expectedTotal != 1000 {
		t.Errorf("expectedTotal = %d, want 1000 (this sub-bill)", st.expectedTotal)
	}
	// p1 confirmed + p2 pending count (non-failed); p3 failed excluded.
	if st.paidCount != 2 {
		t.Errorf("paidCount = %d, want 2 (failed excluded)", st.paidCount)
	}
	if st.remaining != 2000 {
		t.Errorf("remaining = %d, want 2000 (4000 - 1000 - 1000 non-failed)", st.remaining)
	}
}

// A Cloud-settled order (e.g. a Stripe-paid takeaway) syncs its paid_amount onto
// the local orders row but carries NO local payments row. deriveSplitState must
// fold the order's authoritative paid_amount in, so a fully-paid receipt shows
// remaining 0 ("Con lai") instead of the full order total.
func TestDeriveSplitState_CloudSettledUsesOrderPaidAmount(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)

	// Order paid in full on Cloud — paid_amount stamped, but no local payment.
	if _, err := db.Exec(
		`INSERT INTO orders (id, status, total_amount, paid_amount) VALUES ('o-cloud', 'closed', 3000, 3000)`,
	); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	st := s.deriveSplitState("o-cloud", 3000, "")
	if st.remaining != 0 {
		t.Errorf("remaining = %d, want 0 (order.paid_amount fully settles it)", st.remaining)
	}

	// A genuinely partial order (paid_amount 0, one confirmed partial payment)
	// still shows the outstanding balance — the fold-in only ever reduces a stale
	// remaining, never masks a real one.
	if _, err := db.Exec(`
		INSERT INTO orders (id, status, total_amount, paid_amount) VALUES ('o-partial', 'checkout', 3000, 0);
		INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, created_at)
		VALUES ('pp1', 'o-partial', 'cash', 1000, 'confirmed', 'ip1', '2026-06-11T10:00:00Z')`,
	); err != nil {
		t.Fatalf("seed partial: %v", err)
	}
	partial := s.deriveSplitState("o-partial", 3000, "")
	if partial.remaining != 2000 {
		t.Errorf("partial remaining = %d, want 2000 (3000 - 1000 confirmed)", partial.remaining)
	}
}

// The targeted-payment path reads THAT payment's own metadata, not the latest.
// Printing person 1's slip must carry person 1's allocation even when person 2
// paid afterwards — the bug that stamped every payer's slip with the LAST
// person's items/label/amount.
func TestDeriveSplitState_TargetedPaymentUsesOwnMetadata(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)

	if _, err := db.Exec(`
		INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, metadata, created_at)
		VALUES
		  ('pay-1', 'o-3', 'cash', 3000, 'confirmed', 'k1',
			'{"split_mode":"by_items","total_bills":2,"bill_index":0,"expected_total_amount":3000,"item_allocations":[{"item_id":"it-A","units":1}]}',
			'2026-06-11T10:00:00Z'),
		  ('pay-2', 'o-3', 'cash', 2000, 'confirmed', 'k2',
			'{"split_mode":"by_items","total_bills":2,"bill_index":1,"expected_total_amount":2000,"item_allocations":[{"item_id":"it-B","units":1}]}',
			'2026-06-11T10:05:00Z')
	`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	// Latest is pay-2 (bill_index 1, it-B). Targeting pay-1 must return pay-1's
	// metadata (bill_index 0, it-A), NOT the latest payment's.
	st := s.deriveSplitState("o-3", 5000, "pay-1")
	if st.slipIndex != 1 {
		t.Errorf("targeted pay-1 slipIndex = %d, want 1 (bill_index 0)", st.slipIndex)
	}
	if st.allocations["it-A"] != 1 || st.allocations["it-B"] != 0 {
		t.Errorf("targeted pay-1 allocations = %v, want only it-A:1", st.allocations)
	}

	// Empty paymentID still falls back to the latest (pay-2 / bill_index 1 / it-B).
	latest := s.deriveSplitState("o-3", 5000, "")
	if latest.slipIndex != 2 || latest.allocations["it-B"] != 1 {
		t.Errorf("latest fallback wrong: slipIndex=%d allocs=%v", latest.slipIndex, latest.allocations)
	}
}

// TestDeriveSplitState_ByItemsAllocations proves the by-items split parses
// item_allocations and that filterItemsByAllocation trims the slip to this
// person's món at the units paid.
func TestDeriveSplitState_ByItemsAllocations(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)

	if _, err := db.Exec(`
		INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, metadata, created_at)
		VALUES ('p1', 'o-2', 'qr', 3740, 'confirmed', 'i1',
			'{"split_mode":"by_items","total_bills":2,"bill_index":0,"expected_total_amount":3740,"item_allocations":[{"item_id":"it-1","units":2}]}',
			'2026-06-11T10:00:00Z')
	`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	st := s.deriveSplitState("o-2", 8993, "")
	if st.splitCount != 2 || st.expectedTotal != 3740 {
		t.Fatalf("split parse wrong: count=%d expected=%d", st.splitCount, st.expectedTotal)
	}
	if st.allocations["it-1"] != 2 {
		t.Errorf("allocations[it-1] = %d, want 2", st.allocations["it-1"])
	}

	items := []service.Item{
		{ID: "it-1", MenuItemName: "Regular", Quantity: 3, UnitPrice: 1870},
		{ID: "it-2", MenuItemName: "XL", Quantity: 1, UnitPrice: 2210},
	}
	filtered := filterItemsByAllocation(items, st.allocations)
	if len(filtered) != 1 || filtered[0].ID != "it-1" || filtered[0].Quantity != 2 {
		t.Errorf("filtered = %+v, want only it-1 with qty 2", filtered)
	}
}

// TestPaidSlipInputs_ByItemsMonBased proves a by-items split slip is built from
// ONLY this person's món, with a món-based breakdown rendered (Tam tinh from the
// món, paid amount shown), not the whole order.
func TestPaidSlipInputs_ByItemsMonBased(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db, 10)
	for _, q := range []string{
		`INSERT INTO shop_settings (key, value) VALUES ('tax_rate', '10.00')`,
		`INSERT INTO shop_settings (key, value) VALUES ('service_charge_rate', '5.00')`,
	} {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}

	o := &service.Order{
		ID: "o1", OrderCode: "ORD-1", TableNumber: "B1",
		Items: []service.Item{
			{ID: "it-1", MenuItemName: "A", Quantity: 3, UnitPrice: 1000},
			{ID: "it-2", MenuItemName: "B", Quantity: 1, UnitPrice: 2000},
		},
	}
	st := splitState{splitCount: 2, slipIndex: 1, expectedTotal: 2300, allocations: map[string]int{"it-1": 2}}

	slipOrder, slipItems, slip := s.paidSlipInputs(o, st, 2300)

	// Only món A (2 units) is on this slip.
	if len(slipItems) != 1 || slipItems[0].ID != "it-1" || slipItems[0].Quantity != 2 {
		t.Fatalf("slipItems = %+v, want only it-1 x2", slipItems)
	}
	// Breakdown is món-based: subtotal 2×1000=2000, +5% svc 100, +10% tax 200 = 2300.
	if slipOrder.Subtotal != 2000 || slipOrder.TotalAmount != 2300 {
		t.Errorf("sub-order totals: subtotal=%d total=%d (want 2000/2300)", slipOrder.Subtotal, slipOrder.TotalAmount)
	}
	// BillTotal stays 0 so the formatter renders the món-based breakdown.
	if slip.BillTotal != 0 {
		t.Errorf("BillTotal = %d, want 0 (by-items uses sub-order total + breakdown)", slip.BillTotal)
	}
	if slip.AmountPaid != 2300 {
		t.Errorf("AmountPaid = %d, want 2300", slip.AmountPaid)
	}

	// Render: món A shows, Tam tinh + Da thanh toan present, whole-order món B absent.
	// Locale pinned — the assertions match Vietnamese labels; the subject here is
	// the món filtering, not the language (see TestNormalizeOrderForPrint).
	cfg := service.PrintJobConfig{StoreName: "S", PaperWidth: 48, TaxRate: 10, Locale: "vi"}
	out := service.FormatPaidTicket(slipOrder, slipItems, 0, cfg, slip)
	for _, want := range []string{"A", "Tam tinh", "2,000", "Da thanh toan", "2,300"} {
		if !bytes.Contains(out, []byte(want)) {
			t.Errorf("by-items slip missing %q", want)
		}
	}
}

// TestPaidSlipInputs_ByItemsNoTotalBills is the regression for the printed-bill
// report "thanh toán 1 món mà in hết tất cả món". The kiosk's chia-theo-món
// payload carries item_allocations but NO total_bills, so splitCount is 0. The
// slip must STILL filter to the allocated món (not print the whole order),
// driven by the allocation alone.
func TestPaidSlipInputs_ByItemsNoTotalBills(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db, 10)
	for _, q := range []string{
		`INSERT INTO shop_settings (key, value) VALUES ('tax_rate', '10.00')`,
		`INSERT INTO shop_settings (key, value) VALUES ('service_charge_rate', '5.00')`,
	} {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}

	o := &service.Order{
		ID: "o1", OrderCode: "ORD-1", TableNumber: "B1",
		Items: []service.Item{
			{ID: "it-1", MenuItemName: "A", Quantity: 3, UnitPrice: 1000},
			{ID: "it-2", MenuItemName: "B", Quantity: 1, UnitPrice: 2000},
		},
	}
	// splitCount 0 (no total_bills) — only the allocation marks this as a split.
	st := splitState{splitMode: "by_items", allocations: map[string]int{"it-1": 1}}

	slipOrder, slipItems, slip := s.paidSlipInputs(o, st, 1150)

	if len(slipItems) != 1 || slipItems[0].ID != "it-1" || slipItems[0].Quantity != 1 {
		t.Fatalf("slipItems = %+v, want only it-1 x1 (must not print whole order)", slipItems)
	}
	// món-based: 1×1000 +5% svc +10% tax = 1150.
	if slipOrder.Subtotal != 1000 || slipOrder.TotalAmount != 1150 {
		t.Errorf("sub-order totals: subtotal=%d total=%d (want 1000/1150)", slipOrder.Subtotal, slipOrder.TotalAmount)
	}

	// Locale pinned — assertions match Vietnamese labels; the subject is the
	// allocation-driven món filtering, not the language.
	cfg := service.PrintJobConfig{StoreName: "S", PaperWidth: 48, TaxRate: 10, Locale: "vi"}
	out := service.FormatPaidTicket(slipOrder, slipItems, 0, cfg, slip)
	if bytes.Contains(out, []byte("2,000")) {
		t.Error("by-items slip must NOT print món B (¥2,000) — only the món this person paid")
	}
	// The món the person paid + how much they paid (món A 1,150 → đã thanh toán 1,150).
	for _, want := range []string{"A", "Da thanh toan", "1,150"} {
		if !bytes.Contains(out, []byte(want)) {
			t.Errorf("by-items slip missing %q (must show the món + amount paid)", want)
		}
	}
}

// TestPaidSlipInputs_EqualShare: a chia-đều slip prints the WHOLE order (all món
// + the order's own total) plus the amount this person paid and the remaining —
// NOT a per-person sub-total. Only by-items filters the món list.
func TestPaidSlipInputs_EqualShare(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	o := &service.Order{ID: "o1", TotalAmount: 8993, Items: []service.Item{{ID: "x", MenuItemName: "A", Quantity: 1, UnitPrice: 8993}}}
	st := splitState{splitCount: 4, slipIndex: 2, expectedTotal: 2248, remaining: 6745} // no allocations

	slipOrder, slipItems, slip := s.paidSlipInputs(o, st, 2248)
	if slipOrder != o || len(slipItems) != 1 {
		t.Errorf("equal split should keep the full order + all items")
	}
	if slip.BillTotal != 0 {
		t.Errorf("BillTotal = %d, want 0 (equal split prints the whole order, not a sub-total)", slip.BillTotal)
	}
	if slip.AmountPaid != 2248 {
		t.Errorf("AmountPaid = %d, want 2248 (this person's share)", slip.AmountPaid)
	}
	if slip.Remaining != 6745 {
		t.Errorf("Remaining = %d, want 6745 (whole-order remaining)", slip.Remaining)
	}
}

// TestAllocatedItems_SkuFallback proves that when allocation ids don't match
// the local món ids (e.g. the kiosk read the order from Cloud), the items are
// still resolved by product_sku via the order_items table.
func TestAllocatedItems_SkuFallback(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)

	// Local order row "ord-L" with a món keyed by a LOCAL id; the Cloud copy of
	// the same món lives under a different id but the same product_sku.
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_number, status) VALUES ('ord-L', 1, 'pending');
		INSERT INTO order_items (id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal)
			VALUES ('local-item', 'ord-L', 'sku-9', 'A', 3, 1000, 3000);
		INSERT INTO order_items (id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal)
			VALUES ('cloud-item', 'ord-L', 'sku-9', 'A', 3, 1000, 3000)
	`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	o := &service.Order{ID: "ord-L", Items: []service.Item{
		{ID: "local-item", ProductSkuID: "sku-9", MenuItemName: "A", Quantity: 3, UnitPrice: 1000},
	}}

	// Allocation references the CLOUD item id (id mismatch) → must resolve via sku.
	got := s.allocatedItems(o, map[string]int{"cloud-item": 2})
	if len(got) != 1 || got[0].ID != "local-item" || got[0].Quantity != 2 {
		t.Errorf("sku fallback failed: got %+v, want local-item x2", got)
	}

	// Unknown id that resolves to no sku → nil (caller falls back to share slip).
	if got := s.allocatedItems(o, map[string]int{"ghost": 1}); got != nil {
		t.Errorf("unresolvable allocation should return nil, got %+v", got)
	}
}

func TestDeriveSplitState_NoSplitMetadata(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)

	if _, err := db.Exec(`
		INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, created_at)
		VALUES ('p1', 'o-9', 'cash', 1780, 'confirmed', 'i9', '2026-06-11T10:00:00Z')
	`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	st := s.deriveSplitState("o-9", 1780, "")
	if st.splitCount != 0 {
		t.Errorf("splitCount = %d, want 0 (no split metadata)", st.splitCount)
	}
	if st.paidCount != 1 {
		t.Errorf("paidCount = %d, want 1", st.paidCount)
	}
	if st.remaining != 0 {
		t.Errorf("remaining = %d, want 0 (fully paid)", st.remaining)
	}
}
