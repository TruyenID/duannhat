package service

import "testing"

// #2188 — the branch/config fallback rate (`legacyTaxRate`) was REMOVED with
// the legacy ruling: every creation path stamps the per-line tax_rate, so a
// line with a nil snapshot is broken input. The engine DROPS it from the rate
// groups (and the caller warns) instead of pricing it at an invented rate —
// a visibly short total beats a silently mis-taxed one (#2067 pattern).
func TestRateSubtotalsFromItems_DropsUnstampedLines(t *testing.T) {
	ten := 10.0
	items := []Item{
		{Quantity: 1, UnitPrice: 1000, TaxRate: &ten}, // stamped → grouped
		{Quantity: 2, UnitPrice: 500},                 // nil rate → dropped
	}

	groups, dropped := rateSubtotalsFromItems(items)

	if dropped != 1 {
		t.Errorf("dropped = %d, want 1 (the unstamped line)", dropped)
	}
	if len(groups) != 1 {
		t.Fatalf("groups = %v, want exactly the stamped 10%% group", groups)
	}
	if got := groups[rateKey(10)]; got != 1000 {
		t.Errorf("10%% group subtotal = %g, want 1000 (unstamped money must NOT ride along)", got)
	}
}

// A voided or refund line is not "unstamped input" — it is excluded by its own
// rule and must not inflate the dropped counter (that counter drives a WARN
// log; a false positive there would cry wolf on every refund).
func TestRateSubtotalsFromItems_DroppedCounterIgnoresVoidedAndRefunds(t *testing.T) {
	refundOf := "src-line"
	items := []Item{
		{Quantity: 1, UnitPrice: 300, Status: ItemStatus(StatusVoided)},
		{Quantity: -1, UnitPrice: 300, RefundOfItemID: refundOf},
	}

	groups, dropped := rateSubtotalsFromItems(items)

	if dropped != 0 {
		t.Errorf("dropped = %d, want 0 — voided/refund lines have their own exclusions", dropped)
	}
	if len(groups) != 0 {
		t.Errorf("groups = %v, want empty", groups)
	}
}
