package service

import (
	"errors"
	"fmt"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// seedRefundOrder inserts an order (rounding snapshot round/0, excluded tax)
// with one served line qty 3 @ ¥100 net, tax_rate 10%, tax_amount 30, carrying a
// product_sku_id (gap #2). Returns the order + item ids.
func seedRefundOrder(t *testing.T, db *store.DB) (orderID, itemID string) {
	t.Helper()
	orderID = "ord-refund-1"
	itemID = "item-refund-1"
	if _, err := db.Exec(`
		INSERT INTO orders (
			id, order_code, order_number, order_type, status, opened_at,
			subtotal, discount_amount, service_charge, tax_amount,
			total_tip, total_amount, paid_amount, is_tax_included,
			tax_rounding_mode, tax_rounding_decimals,
			organization_id, brand_id, branch_id, created_at, updated_at
		) VALUES (?, 'WS-R1', 1, 'spot', 'open', datetime('now'),
			300, 0, 0, 30, 0, 330, 0, 0,
			'round', 0, '', '', '', datetime('now'), datetime('now'))`,
		orderID); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.Exec(`
		INSERT INTO order_items (
			id, customer_order_id, product_sku_id, menu_item_name, quantity,
			unit_price, subtotal, tax_type_id, tax_rate, tax_amount,
			refunded_quantity, status, print_status, created_at, updated_at
		) VALUES (?, ?, 'sku-1', 'Ramen', 3, 100, 300, 'tt-1', 10, 30, 0,
			'served', 'printed', datetime('now'), datetime('now'))`,
		itemID, orderID); err != nil {
		t.Fatalf("seed item: %v", err)
	}
	return orderID, itemID
}

// [Go] refund appends a negative line and lowers the total by exactly the gross.
func TestRefundItem_AppendsNegativeLine(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID, itemID := seedRefundOrder(t, db)

	res, err := eng.RefundItem(orderID, itemID, 2, "customer changed mind")
	if err != nil {
		t.Fatalf("RefundItem: %v", err)
	}

	// Refund line: qty −2, subtotal −200, tax −20, refund_of_item_id set, SKU carried.
	var qty, subtotal, tax int
	var refundOf, sku string
	if err := db.QueryRow(`
		SELECT quantity, subtotal, tax_amount, COALESCE(refund_of_item_id,''), COALESCE(product_sku_id,'')
		FROM order_items WHERE id = ?`, res.RefundLineID).
		Scan(&qty, &subtotal, &tax, &refundOf, &sku); err != nil {
		t.Fatalf("read refund line: %v", err)
	}
	if qty != -2 {
		t.Errorf("refund qty want -2, got %d", qty)
	}
	if subtotal != -200 {
		t.Errorf("refund subtotal want -200, got %d", subtotal)
	}
	if tax != -20 {
		t.Errorf("refund tax want -20, got %d", tax)
	}
	if refundOf != itemID {
		t.Errorf("refund_of_item_id want %q, got %q", itemID, refundOf)
	}
	if sku != "sku-1" {
		t.Errorf("refund line must carry the original SKU (gap #2), got %q", sku)
	}

	// Original's refunded_quantity bumped to 2.
	var refunded int
	_ = db.QueryRow(`SELECT refunded_quantity FROM order_items WHERE id = ?`, itemID).Scan(&refunded)
	if refunded != 2 {
		t.Errorf("original refunded_quantity want 2, got %d", refunded)
	}

	// Order total dropped by exactly 2×(100+10) = 220 → 330 − 220 = 110.
	if res.Order.TotalAmount != 110 {
		t.Errorf("order total want 110, got %d", res.Order.TotalAmount)
	}
	if res.Order.TaxAmount != 10 {
		t.Errorf("order tax want 10 (30 − 20), got %g", res.Order.TaxAmount)
	}
	if res.Order.Subtotal != 100 {
		t.Errorf("order subtotal want 100 (300 − 200), got %d", res.Order.Subtotal)
	}
}

// [Go] over-refund guard: refunded 2 already, +2 exceeds qty 3 → error.
func TestRefundItem_OverRefundGuard(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID, itemID := seedRefundOrder(t, db)

	if _, err := eng.RefundItem(orderID, itemID, 2, ""); err != nil {
		t.Fatalf("first refund: %v", err)
	}
	if _, err := eng.RefundItem(orderID, itemID, 2, ""); !errors.Is(err, ErrRefundExceedsQuantity) {
		t.Errorf("expected ErrRefundExceedsQuantity, got %v", err)
	}
	// Exact boundary: the remaining 1 unit succeeds.
	if _, err := eng.RefundItem(orderID, itemID, 1, ""); err != nil {
		t.Errorf("exact-boundary refund should succeed: %v", err)
	}
	var refunded int
	_ = db.QueryRow(`SELECT refunded_quantity FROM order_items WHERE id = ?`, itemID).Scan(&refunded)
	if refunded != 3 {
		t.Errorf("refunded_quantity want 3, got %d", refunded)
	}
}

// [Go] can't refund a refund line.
func TestRefundItem_CannotRefundRefundLine(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID, itemID := seedRefundOrder(t, db)

	res, err := eng.RefundItem(orderID, itemID, 1, "")
	if err != nil {
		t.Fatalf("refund: %v", err)
	}
	if _, err := eng.RefundItem(orderID, res.RefundLineID, 1, ""); !errors.Is(err, ErrCannotRefundRefundLine) {
		t.Errorf("expected ErrCannotRefundRefundLine, got %v", err)
	}
}

// [Go] a voided order is not refundable.
func TestRefundItem_VoidedOrderNotRefundable(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID, itemID := seedRefundOrder(t, db)
	if _, err := db.Exec(`UPDATE orders SET status = 'voided' WHERE id = ?`, orderID); err != nil {
		t.Fatalf("void order: %v", err)
	}
	if _, err := eng.RefundItem(orderID, itemID, 1, ""); !errors.Is(err, ErrOrderNotOpen) {
		t.Errorf("expected ErrOrderNotOpen (not refundable), got %v", err)
	}
}

// [Go] refund writes an append-only refund order_conditions row with negated
// amount + refund_of_item_id in meta.
func TestRefundItem_WritesRefundCondition(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID, itemID := seedRefundOrder(t, db)

	res, err := eng.RefundItem(orderID, itemID, 2, "spill")
	if err != nil {
		t.Fatalf("refund: %v", err)
	}

	var ctype, cid, typ, label string
	var amount float64
	if err := db.QueryRow(`
		SELECT conditionable_type, conditionable_id, type, label, amount
		FROM order_conditions WHERE id = ?`, res.ConditionID).
		Scan(&ctype, &cid, &typ, &label, &amount); err != nil {
		t.Fatalf("read condition: %v", err)
	}
	if ctype != "order_item" || cid != res.RefundLineID {
		t.Errorf("condition should attach to the refund line, got %s/%s", ctype, cid)
	}
	if typ != "refund" {
		t.Errorf("condition type want refund, got %q", typ)
	}
	if label != "spill" {
		t.Errorf("condition label want 'spill', got %q", label)
	}
	// Excluded mode: gross = subtotal + tax = −200 + (−20) = −220.
	if int(amount) != -220 {
		t.Errorf("condition amount want -220 (negated gross), got %v", amount)
	}
}

// [Go] refund is EXCLUDED from the positive group-once (the negative line never
// hits AllocateGroupTax's ≥0 clamp); the original line's tax stays 30.
func TestRefundItem_RefundExcludedFromGroupOnce(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID, itemID := seedRefundOrder(t, db)

	if _, err := eng.RefundItem(orderID, itemID, 1, ""); err != nil {
		t.Fatalf("refund: %v", err)
	}
	// The ORIGINAL line's tax_amount must remain the group-once figure (30) —
	// stampLineTaxAmounts must not have re-allocated over a refund line.
	var origTax int
	_ = db.QueryRow(`SELECT tax_amount FROM order_items WHERE id = ?`, itemID).Scan(&origTax)
	if origTax != 30 {
		t.Errorf("original tax want 30 (unchanged by refund), got %d", origTax)
	}
	// The refund line's tax must be −10 (per-unit 10, negated) — from the
	// snapshot, NOT re-derived through the allocator.
	var refundTax int
	_ = db.QueryRow(`
		SELECT tax_amount FROM order_items
		WHERE customer_order_id = ? AND refund_of_item_id = ?`, orderID, itemID).Scan(&refundTax)
	if refundTax != -10 {
		t.Errorf("refund line tax want -10, got %d", refundTax)
	}
}

// seedDiscountedRefundOrder inserts an order (round/0, excluded tax) carrying a
// manual discount plus one served line per given unit price, all at `rate`%.
// Totals are seeded zeroed — callers recalc before asserting.
func seedDiscountedRefundOrder(t *testing.T, db *store.DB, orderID string, unitPrices []int, rate float64, discount int) []string {
	t.Helper()
	if _, err := db.Exec(`
		INSERT INTO orders (
			id, order_code, order_number, order_type, status, opened_at,
			subtotal, discount_amount, service_charge, tax_amount,
			total_tip, total_amount, paid_amount, is_tax_included,
			tax_rounding_mode, tax_rounding_decimals,
			organization_id, brand_id, branch_id, created_at, updated_at
		) VALUES (?, ?, 1, 'spot', 'open', datetime('now'),
			0, ?, 0, 0, 0, 0, 0, 0,
			'round', 0, '', '', '', datetime('now'), datetime('now'))`,
		orderID, orderID, discount); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	itemIDs := make([]string, len(unitPrices))
	for i, price := range unitPrices {
		itemIDs[i] = fmt.Sprintf("%s-item-%d", orderID, i+1)
		if _, err := db.Exec(`
			INSERT INTO order_items (
				id, customer_order_id, product_sku_id, menu_item_name, quantity,
				unit_price, subtotal, tax_type_id, tax_rate, tax_amount,
				refunded_quantity, status, print_status, created_at, updated_at
			) VALUES (?, ?, 'sku-1', 'Item', 1, ?, ?, 'tt-1', ?, 0, 0,
				'served', 'printed', datetime('now'), datetime('now'))`,
			itemIDs[i], orderID, price, price, rate); err != nil {
			t.Fatalf("seed item %d: %v", i, err)
		}
	}
	return itemIDs
}

// [Go] #2232 (nửa Go của #2182) — thuế dòng hoàn tính trên nền GỘP (chưa trừ
// giảm giá), và khoản giảm áp dụng kẹp theo giỏ SỐNG. Ca đo của issue:
// 2 × ¥1.000 @10% + giảm ¥500 — hoàn một món phải là −100 (không phải −75, cái
// lệch ¥25 mỗi dòng thu ngân chỉ thấy lúc đếm két); hoàn hết giỏ phải về đúng
// 0 (không đọng 75 thuế, không total âm −475 kiểu #2114).
func TestRefundItem_TaxOnGrossBase_Discounted(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID := "ord-refund-gross"
	itemIDs := seedDiscountedRefundOrder(t, db, orderID, []int{1000, 1000}, 10, 500)

	if err := eng.RecalcOrderTotals(orderID); err != nil {
		t.Fatalf("recalc: %v", err)
	}
	// Pre-refund sanity: net 1500 @10% → tax 150, total 2150 − 500 = 2150? no:
	// 2000 − 500 + 150 = 1650. Line stamps carry the pro-rata discount (75/75).
	var tax, total int
	_ = db.QueryRow(`SELECT tax_amount, total_amount FROM orders WHERE id = ?`, orderID).Scan(&tax, &total)
	if tax != 150 || total != 1650 {
		t.Fatalf("pre-refund want tax 150 / total 1650, got %d / %d", tax, total)
	}

	// Refund the first item: the refund line's tax is the GROSS share (−100),
	// NOT the discounted snapshot (−75).
	res, err := eng.RefundItem(orderID, itemIDs[0], 1, "")
	if err != nil {
		t.Fatalf("refund 1: %v", err)
	}
	if res.RefundTaxAmount != -100 {
		t.Errorf("refund tax want -100 (gross base, #2232), got %d", res.RefundTaxAmount)
	}
	// Order after one refund: khách còn nợ phần giữ lại 1.000 − 500 = 500 @10%.
	if res.Order.TaxAmount != 50 {
		t.Errorf("order tax after 1st refund want 50 (150 − 100), got %g", res.Order.TaxAmount)
	}
	if res.Order.TotalAmount != 550 {
		t.Errorf("order total after 1st refund want 550, got %d", res.Order.TotalAmount)
	}
	// Invariant: Σ per-line tax == order tax (75 + 75 − 100 = 50) — live-line
	// snapshots allocate on the base BEFORE the refund fold.
	var sumLineTax int
	_ = db.QueryRow(`SELECT COALESCE(SUM(tax_amount), 0) FROM order_items WHERE customer_order_id = ?`, orderID).Scan(&sumLineTax)
	if sumLineTax != 50 {
		t.Errorf("Σ line tax after 1st refund want 50, got %d", sumLineTax)
	}

	// Refund the second item: basket fully returned → everything cancels.
	res, err = eng.RefundItem(orderID, itemIDs[1], 1, "")
	if err != nil {
		t.Fatalf("refund 2: %v", err)
	}
	if res.RefundTaxAmount != -100 {
		t.Errorf("2nd refund tax want -100, got %d", res.RefundTaxAmount)
	}
	if res.Order.TaxAmount != 0 {
		t.Errorf("order tax after full refund want 0, got %g", res.Order.TaxAmount)
	}
	if res.Order.TotalAmount != 0 {
		t.Errorf("order total after full refund want 0 (manual discount must not survive an empty basket), got %d", res.Order.TotalAmount)
	}
	if res.Order.Subtotal != 0 {
		t.Errorf("order subtotal after full refund want 0, got %d", res.Order.Subtotal)
	}
	// Live lines re-stamp at gross (the discount died with the basket): 100/100.
	for _, id := range itemIDs {
		var lineTax int
		_ = db.QueryRow(`SELECT tax_amount FROM order_items WHERE id = ?`, id).Scan(&lineTax)
		if lineTax != 100 {
			t.Errorf("live line %s tax after full refund want 100 (discount clamped to live basket), got %d", id, lineTax)
		}
	}
}

// [Go] #2232 — the gross base is the ALLOCATED largest-remainder share, not a
// per-line round(subtotal × rate). Three ¥1,005 lines @10% allocate 101/101/100
// (group tax 302); refunding each line must reverse its own share so the sum is
// exactly −302, not 3 × −101 = −303.
func TestRefundItem_GrossBaseUsesAllocatedShare(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID := "ord-refund-lr"
	itemIDs := seedDiscountedRefundOrder(t, db, orderID, []int{1005, 1005, 1005}, 10, 300)

	if err := eng.RecalcOrderTotals(orderID); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	want := []int{-101, -101, -100}
	sum := 0
	for i, id := range itemIDs {
		res, err := eng.RefundItem(orderID, id, 1, "")
		if err != nil {
			t.Fatalf("refund %d: %v", i, err)
		}
		if res.RefundTaxAmount != want[i] {
			t.Errorf("refund %d tax want %d (allocated gross share), got %d", i, want[i], res.RefundTaxAmount)
		}
		sum += res.RefundTaxAmount
	}
	if sum != -302 {
		t.Errorf("Σ refund tax want -302 (= −group tax, exact reversal), got %d", sum)
	}
	var tax, total int
	_ = db.QueryRow(`SELECT tax_amount, total_amount FROM orders WHERE id = ?`, orderID).Scan(&tax, &total)
	if tax != 0 || total != 0 {
		t.Errorf("fully refunded order want tax 0 / total 0, got %d / %d", tax, total)
	}
}

// [Go] snapshot rounding: an order stamped ceil/decimals=0 rounds a net-1005
// @10% line's tax UP to 101; a round sibling lands 101; floor 100. Proves
// the recompute reads the ORDER row, not shop_settings.
func TestOrderRoundingSnapshot_DrivesRecompute(t *testing.T) {
	eng, db := newOrderEngineForTest(t)

	seed := func(id, mode string) {
		if _, err := db.Exec(`
			INSERT INTO orders (
				id, order_code, order_number, order_type, status, opened_at,
				subtotal, discount_amount, service_charge, tax_amount,
				total_tip, total_amount, paid_amount, is_tax_included,
				tax_rounding_mode, tax_rounding_decimals,
				organization_id, brand_id, branch_id, created_at, updated_at
			) VALUES (?, ?, 1, 'spot', 'open', datetime('now'),
				0, 0, 0, 0, 0, 0, 0, 0, ?, 0, '', '', '', datetime('now'), datetime('now'))`,
			id, id, mode); err != nil {
			t.Fatalf("seed order %s: %v", id, err)
		}
		if _, err := db.Exec(`
			INSERT INTO order_items (
				id, customer_order_id, product_sku_id, menu_item_name, quantity,
				unit_price, subtotal, tax_rate, tax_amount, status, print_status,
				created_at, updated_at
			) VALUES (?, ?, 'sku-x', 'X', 1, 1005, 1005, 10, 0, 'served', 'printed',
				datetime('now'), datetime('now'))`,
			"it-"+id, id); err != nil {
			t.Fatalf("seed item %s: %v", id, err)
		}
	}
	seed("ord-up", "ceil")
	seed("ord-down", "floor")
	seed("ord-half", "round")

	if err := eng.RecalcOrderTotals("ord-up"); err != nil {
		t.Fatalf("recalc up: %v", err)
	}
	if err := eng.RecalcOrderTotals("ord-down"); err != nil {
		t.Fatalf("recalc down: %v", err)
	}
	if err := eng.RecalcOrderTotals("ord-half"); err != nil {
		t.Fatalf("recalc half: %v", err)
	}

	read := func(id string) int {
		var tax int
		_ = db.QueryRow(`SELECT tax_amount FROM orders WHERE id = ?`, id).Scan(&tax)
		return tax
	}
	if got := read("ord-up"); got != 101 {
		t.Errorf("ceil tax want 101 (ceil 100.5), got %d", got)
	}
	if got := read("ord-down"); got != 100 {
		t.Errorf("floor tax want 100 (floor 100.5), got %d", got)
	}
	if got := read("ord-half"); got != 101 {
		t.Errorf("round tax want 101 (100.5), got %d", got)
	}
}
