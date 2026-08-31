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
	s.orders = service.NewOrderEngine(db)

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

	// #2188 — lines carry their tax snapshot; unstamped lines are dropped by
	// the engine, never priced at a fallback rate.
	ten := 10.0
	o := &service.Order{
		ID: "ord-1", OrderCode: "ORD-2026-4240", TableNumber: "B1",
		Items: []service.Item{
			{ID: "it-1", ProductSkuID: "sku-1", MenuItemName: "", Quantity: 3, UnitPrice: 1870, Status: service.ItemStatus("pending"), TaxRate: &ten},
			{ID: "it-2", ProductSkuID: "sku-2", MenuItemName: "", Quantity: 1, UnitPrice: 2210, Status: service.ItemStatus("pending"), TaxRate: &ten},
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
	// #2188 — the fixture lines are tax-stamped, so the slip prints the
	// per-rate インボイス block ("Chiu thue 10% … thue trong"), not the legacy
	// single "Thue" row (which since #2067 only appears for an order-level
	// tax fact with no per-line snapshots).
	for _, want := range []string{"Regular", "Tam tinh", "7,820", "Phi phuc vu", "391", "Chiu thue 10%", "thue trong", "782", "Tong", "8,993", "Con lai", "4,993"} {
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
		  ('p1', 'o-1', 'qr', 1000, 'confirmed', 'i1', '{"split_mode":"even","total_bills":4,"bill_index":0,"expected_total_amount":1000}', '2026-06-11T10:00:00Z'),
		  ('p2', 'o-1', 'qr', 1000, 'pending',   'i2', '{"split_mode":"even","total_bills":4,"bill_index":1,"expected_total_amount":1000}', '2026-06-11T10:05:00Z'),
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
	s.orders = service.NewOrderEngine(db)
	for _, q := range []string{
		`INSERT INTO shop_settings (key, value) VALUES ('tax_rate', '10.00')`,
		`INSERT INTO shop_settings (key, value) VALUES ('service_charge_rate', '5.00')`,
	} {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}

	ten := 10.0
	o := &service.Order{
		ID: "o1", OrderCode: "ORD-1", TableNumber: "B1",
		Items: []service.Item{
			{ID: "it-1", MenuItemName: "A", Quantity: 3, UnitPrice: 1000, TaxRate: &ten},
			{ID: "it-2", MenuItemName: "B", Quantity: 1, UnitPrice: 2000, TaxRate: &ten},
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
	s.orders = service.NewOrderEngine(db)
	for _, q := range []string{
		`INSERT INTO shop_settings (key, value) VALUES ('tax_rate', '10.00')`,
		`INSERT INTO shop_settings (key, value) VALUES ('service_charge_rate', '5.00')`,
	} {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}

	ten := 10.0
	o := &service.Order{
		ID: "o1", OrderCode: "ORD-1", TableNumber: "B1",
		Items: []service.Item{
			{ID: "it-1", MenuItemName: "A", Quantity: 3, UnitPrice: 1000, TaxRate: &ten},
			{ID: "it-2", MenuItemName: "B", Quantity: 1, UnitPrice: 2000, TaxRate: &ten},
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
		INSERT INTO order_items (id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, tax_rate)
			VALUES ('local-item', 'ord-L', 'sku-9', 'A', 3, 1000, 3000, 10);
		INSERT INTO order_items (id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, tax_rate)
			VALUES ('cloud-item', 'ord-L', 'sku-9', 'A', 3, 1000, 3000, 10)
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

// #3044 — dòng đã HUỶ không được xuất hiện trên chứng từ TIỀN.
//
// Dựng lại đúng ca của quán 人形町店 ngày 2026-08-16 (`ORD-2026-0651`): khách
// gọi một tô, không thích, quán huỷ dòng đó rồi làm lại tô khác. Tờ biên lai in
// ra CẢ HAI dòng — cộng lại ¥2.500 — trong khi tổng in ¥1.250.
//
// Điều làm nó sống lâu: tiền KHÔNG sai. Hai nửa của tờ giấy đi hai đường dữ
// liệu khác nhau, nên phần tổng vẫn đúng và không sổ nào lệch, không cảnh báo
// nào kêu. Chỉ khách cầm tờ giấy mới thấy — và điều họ kết luận là "quán tính
// thiếu tiền".
func TestPaidSlipInputs_DropsVoidedItems(t *testing.T) {
	s := &Server{}
	o := &service.Order{
		ID: "ord-0651", OrderCode: "ORD-2026-0651", TableNumber: "C9",
		TotalAmount: 1250,
		Items: []service.Item{
			// Tô bị huỷ — 「Khách không thích」.
			{ID: "it-voided", MenuItemName: "野菜フォー", Quantity: 1, UnitPrice: 1100,
				Status: service.ItemStatusVoided},
			// Tô làm lại, là thứ khách thật sự trả tiền.
			{ID: "it-served", MenuItemName: "野菜フォー", Quantity: 1, UnitPrice: 1100},
		},
	}

	_, slipItems, _ := s.paidSlipInputs(o, splitState{}, 1250)

	if len(slipItems) != 1 {
		t.Fatalf("biên lai phải còn 1 dòng, có %d: %+v", len(slipItems), slipItems)
	}
	if slipItems[0].ID != "it-served" {
		t.Fatalf("dòng còn lại phải là món đã phục vụ, đang là %q", slipItems[0].ID)
	}
}

// Nhánh chia-theo-món dựng lại danh sách từ `o.Items` GỐC, nên nó bỏ qua phép
// lọc ở đầu hàm. Không có bài này thì một đơn chia bill vẫn in lại đúng lỗi vừa
// sửa, và nó sẽ không bị bắt bởi bài trên.
func TestPaidSlipInputs_DropsVoidedItemsOnByItemsSplit(t *testing.T) {
	// `s.orders` PHẢI có thật: nhánh chia-theo-món gác bằng `s.orders != nil`,
	// nên một `&Server{}` trần sẽ đi thẳng qua nó và bài test xanh mà không đo
	// gì cả — đúng cái bẫy "rào xanh vì lý do khác". Đã kiểm bằng đột biến.
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)

	o := &service.Order{
		ID: "o1", OrderCode: "ORD-1", TableNumber: "B1",
		Items: []service.Item{
			{ID: "it-voided", MenuItemName: "A", Quantity: 1, UnitPrice: 1000,
				Status: service.ItemStatusVoided},
			{ID: "it-live", MenuItemName: "B", Quantity: 1, UnitPrice: 2000},
		},
	}
	// Phân bổ CỐ Ý trỏ vào cả dòng đã huỷ — đúng thứ xảy ra khi thu ngân chia
	// bill trên một đơn từng có món bị huỷ.
	st := splitState{
		splitCount:  2,
		slipIndex:   1,
		allocations: map[string]int{"it-voided": 1, "it-live": 1},
	}

	_, slipItems, _ := s.paidSlipInputs(o, st, 2000)

	for _, it := range slipItems {
		if it.ID == "it-voided" {
			t.Fatalf("dòng đã huỷ lọt vào phiếu chia bill: %+v", slipItems)
		}
	}
}

// #3044 — bộ ca biên của phép lọc dòng đã huỷ.
//
// Phép lọc này đứng trên đường CHỨNG TỪ TIỀN, nên nó phải đúng ở cả hai chiều:
// bỏ đúng thứ cần bỏ, và KHÔNG đụng vào thứ không được bỏ. Một bộ lọc quá tay ở
// đây xoá món khách thật sự đã mua khỏi tờ giấy họ cầm — tệ hơn hẳn lỗi ban đầu,
// vì lỗi ban đầu chỉ thừa, còn cái này thiếu.
func TestNonVoidedItems_EdgeCases(t *testing.T) {
	voided := func(id string) service.Item {
		return service.Item{ID: id, MenuItemName: "X", Quantity: 1, UnitPrice: 100,
			Status: service.ItemStatusVoided}
	}
	live := func(id string) service.Item {
		return service.Item{ID: id, MenuItemName: "Y", Quantity: 1, UnitPrice: 200}
	}
	ids := func(items []service.Item) []string {
		out := make([]string, 0, len(items))
		for _, it := range items {
			out = append(out, it.ID)
		}

		return out
	}

	cases := []struct {
		name string
		in   []service.Item
		want []string
	}{
		{
			// Chiều IM: không có gì bị huỷ thì danh sách phải nguyên vẹn. Đây là
			// ca thường ngày và chiếm gần hết lượt in — một bộ lọc làm sai ở đây
			// hỏng mọi hoá đơn của quán, không phải một ca hiếm.
			name: "không có dòng huỷ — giữ nguyên",
			in:   []service.Item{live("a"), live("b")},
			want: []string{"a", "b"},
		},
		{
			// THỨ TỰ phải giữ. Biên lai in theo thứ tự gọi món; đảo thứ tự làm
			// khách không dò được tờ giấy với thứ họ đã ăn.
			name: "dòng huỷ nằm GIỮA — phần còn lại giữ đúng thứ tự",
			in:   []service.Item{live("a"), voided("v"), live("b")},
			want: []string{"a", "b"},
		},
		{
			name: "dòng huỷ ĐẦU tiên",
			in:   []service.Item{voided("v"), live("a")},
			want: []string{"a"},
		},
		{
			name: "dòng huỷ CUỐI cùng",
			in:   []service.Item{live("a"), voided("v")},
			want: []string{"a"},
		},
		{
			name: "nhiều dòng huỷ liên tiếp",
			in:   []service.Item{voided("v1"), voided("v2"), live("a"), voided("v3")},
			want: []string{"a"},
		},
		{
			// Đơn bị huỷ SẠCH nhưng vẫn có thanh toán (ví dụ khách đã trả rồi
			// quán huỷ toàn bộ để làm lại). Phải ra danh sách RỖNG chứ không
			// panic và không rơi về "in tất cả" — nhánh fallback kiểu đó là cách
			// một phép lọc tự vô hiệu hoá chính nó.
			name: "TẤT CẢ đều huỷ — danh sách rỗng, không panic",
			in:   []service.Item{voided("v1"), voided("v2")},
			want: []string{},
		},
		{
			name: "danh sách rỗng từ đầu",
			in:   []service.Item{},
			want: []string{},
		},
		{
			name: "nil",
			in:   nil,
			want: []string{},
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := ids(nonVoidedItems(tc.in))
			if len(got) != len(tc.want) {
				t.Fatalf("got %v, want %v", got, tc.want)
			}
			for i := range got {
				if got[i] != tc.want[i] {
					t.Fatalf("got %v, want %v", got, tc.want)
				}
			}
		})
	}
}

// Trạng thái KHÁC `voided` không được đụng tới.
//
// Vòng đời món có nhiều trạng thái, và chỉ đúng MỘT nghĩa là "khách không mua
// cái này". Lọc theo "khác served" hay "có VoidedAt" đều sẽ ăn nhầm món đang
// chờ bếp — tức xoá khỏi hoá đơn một món khách đã gọi và sẽ phải trả tiền.
func TestNonVoidedItems_OnlyVoidedStatusIsDropped(t *testing.T) {
	for _, st := range []service.ItemStatus{"", "ordered", "preparing", "ready", "served", "sent_to_kitchen"} {
		items := []service.Item{{ID: "keep", Quantity: 1, UnitPrice: 100, Status: st}}
		if got := nonVoidedItems(items); len(got) != 1 {
			t.Fatalf("status %q bị bỏ nhầm khỏi biên lai", st)
		}
	}
}

// #3044 — CẢ BA chế độ chia bill đều phải loại dòng đã huỷ.
//
// Ba chế độ dựng danh sách món theo ba đường khác nhau, và đó chính là lý do
// một phép lọc duy nhất không đủ:
//
//	even      → dùng `slipItems` ở đầu hàm
//	by_items  → dựng lại từ phân bổ, đọc `o.Items` GỐC
//	by_amount → trả thẳng `o.Items`
//
// Bản vá đầu chỉ phủ hai chế độ; chế độ thứ ba lộ ra khi chủ dự án yêu cầu
// kiểm mọi ca biên. Bài này ghim cả ba để lần sau ai thêm chế độ thứ tư sẽ
// thấy ngay là phải lọc.
func TestPaidSlipInputs_AllSplitModesDropVoided(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)

	newOrder := func() *service.Order {
		return &service.Order{
			ID: "o1", OrderCode: "ORD-1", TableNumber: "B1", TotalAmount: 2000,
			Items: []service.Item{
				{ID: "it-voided", MenuItemName: "A", Quantity: 1, UnitPrice: 1000,
					Status: service.ItemStatusVoided},
				{ID: "it-live", MenuItemName: "B", Quantity: 1, UnitPrice: 2000},
			},
		}
	}

	cases := []struct {
		name string
		st   splitState
	}{
		{"không chia bill", splitState{}},
		{"even", splitState{splitCount: 2, slipIndex: 1, splitMode: "even"}},
		{"by_amount", splitState{
			splitCount: 2, slipIndex: 1, splitMode: "by_amount", byAmountAmount: 1000,
		}},
		{"by_items", splitState{
			splitCount:  2,
			slipIndex:   1,
			splitMode:   "by_items",
			allocations: map[string]int{"it-voided": 1, "it-live": 1},
		}},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, slipItems, _ := s.paidSlipInputs(newOrder(), tc.st, 1000)
			for _, it := range slipItems {
				if it.ID == "it-voided" {
					t.Fatalf("dòng đã huỷ lọt vào phiếu chế độ %q: %+v", tc.name, slipItems)
				}
			}
			if len(slipItems) == 0 {
				t.Fatalf("chế độ %q không được lọc sạch cả món còn sống", tc.name)
			}
		})
	}
}
