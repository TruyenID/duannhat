package handler

import (
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// plan-043 T5.6 — 4th split mirror (per-rate parity).
//
// Proves the workstation equal-split-by-people path computes each person's
// share from a per-rate consumption-tax total that matches the backend PHP
// pricing engine (OrderPricingCalculator::priceGroups) to the yen.
//
// Shared fixture (the canonical §1.2 / §6.1 proof case):
//   - bentō ¥1000 @ 8% (軽減) + beer ¥500 @ 10% (標準), mode excluded (JPY, step 1).
//   - Per-rate, once-per-group rounding: 8% group tax = round(1000×0.08) = 80;
//     10% group tax = round(500×0.10) = 50.
//   - order tax_amount = 130, total = 1500 + 130 = 1630.
//   - Split equally by 2 → 1630 / 2 = 815 each, Σ = 1630 (clean, no remainder).
//
// The test drives the REAL per-rate engine (NormalizedTotals → priceGroups):
// items carry their per-line tax_rate snapshot, so the total is derived from
// the per-rate groups, NOT a raw SQL total (the sibling parity test seeds
// total_amount directly, which would mask a per-rate regression). It then
// invokes the split handler and asserts the mirror splits the engine total.
func TestSplitBill_EqualMode_MixedRateTaxParity(t *testing.T) {
	srv, _, orderID := setupOrderForSplit(t, 0)

	// Seed two per-rate lines with immutable tax_rate snapshots (the shape the
	// §7 resolver stamps at add-item time). tax_amount is the informational
	// per-line column; the engine recomputes per RATE GROUP.
	for _, it := range []struct {
		id      string
		name    string
		unit    int
		taxRate float64
		lineTax int
	}{
		{"it-bento", "弁当", 1000, 8, 80},
		{"it-beer", "ビール", 500, 10, 50},
	} {
		if _, err := srv.db.Exec(`
			INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity,
			    unit_price, subtotal, topping_subtotal, status, print_status, printer_group,
			    tax_rate, tax_amount, created_at, updated_at)
			VALUES (?, ?, ?, 1, ?, ?, 0, 'pending', 'pending', 'kitchen', ?, ?, datetime('now'), datetime('now'))`,
			it.id, orderID, it.name, it.unit, it.unit, it.taxRate, it.lineTax); err != nil {
			t.Fatal(err)
		}
	}

	// Run the per-rate engine over the freshly-seeded lines and persist the
	// result, exactly as production AddItems does. NormalizedTotals groups by
	// snapshot rate, taxes each group once, and returns integer yen.
	order, err := srv.orders.GetByID(orderID)
	if err != nil {
		t.Fatal(err)
	}
	subtotal, _, tax, serviceCharge, total := srv.orders.NormalizedTotals(order)

	// Engine parity assertions (per-rate, once-per-group rounding).
	if subtotal != 1500 {
		t.Errorf("subtotal: want 1500, got %d", subtotal)
	}
	if tax != 130 {
		t.Errorf("per-rate tax: want 130 (80 @8%% + 50 @10%%), got %v", tax)
	}
	if total != 1630 {
		t.Errorf("total: want 1630, got %d", total)
	}

	if _, err := srv.db.Exec(`
		UPDATE orders SET subtotal = ?, tax_amount = ?, service_charge = ?, total_amount = ?, status = 'checkout'
		WHERE id = ?`,
		subtotal, tax, serviceCharge, total, orderID); err != nil {
		t.Fatal(err)
	}

	// Equal-split-by-people mirror: divide the engine total across 2 people.
	data := getSplitBill(t, srv, orderID, 2)

	if v, _ := data["total_amount"].(string); v != "1630.00" {
		t.Errorf("total_amount: want '1630.00', got %v", data["total_amount"])
	}
	if v, _ := data["remaining_amount"].(string); v != "1630.00" {
		t.Errorf("remaining_amount: want '1630.00', got %v", data["remaining_amount"])
	}
	if v, _ := data["per_person_amount"].(string); v != "815.00" {
		t.Errorf("per_person_amount: want '815.00', got %v", data["per_person_amount"])
	}

	amounts, ok := data["per_person_amounts"].([]any)
	if !ok || len(amounts) != 2 {
		t.Fatalf("per_person_amounts: want len-2 string array, got %T %v",
			data["per_person_amounts"], data["per_person_amounts"])
	}

	// Each person pays 815; Σ person amounts == 1630 (the per-rate engine total).
	sum := 0
	for i, a := range amounts {
		if a != "815.00" {
			t.Errorf("per_person_amounts[%d]: want '815.00', got %v", i, a)
		}
		sum += yenStringToInt(t, a)
	}
	if sum != 1630 {
		t.Errorf("Σ per-person amounts: want 1630, got %d", sum)
	}

	// 1630 / 2 is exact → no remainder → no first-payer rounding note.
	if data["rounding_note"] != nil {
		t.Errorf("rounding_note: want null for clean split, got %v", data["rounding_note"])
	}
}

// yenStringToInt parses a workstation decimal-yen string ("815.00") back to
// integer yen. Workstation tracks amounts as whole yen so the fractional part
// is always ".00"; use service parity helpers instead of duplicating parsing.
func yenStringToInt(t *testing.T, s any) int {
	t.Helper()
	str, ok := s.(string)
	if !ok {
		t.Fatalf("amount is not a string: %T %v", s, s)
	}
	// Strip the fixed ".00" suffix and atoi the yen portion.
	if len(str) < 3 || str[len(str)-3:] != ".00" {
		t.Fatalf("amount %q is not in the N.00 shape", str)
	}
	n := 0
	for _, c := range str[:len(str)-3] {
		if c < '0' || c > '9' {
			t.Fatalf("amount %q has a non-digit yen portion", str)
		}
		n = n*10 + int(c-'0')
	}
	return n
}

// Compile-time nudge: this test depends on the exported service engine API
// (NormalizedTotals + GetByID) staying stable — the per-rate mirror asserts
// through it rather than re-deriving tax in the handler.
var _ = service.NewOrderEngine
