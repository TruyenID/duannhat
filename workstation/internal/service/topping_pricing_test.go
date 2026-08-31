package service

import "testing"

// Unit tests for the free_up_to_n / flat topping-pricing port. Pure (no DB).
// Vectors mirror pos-web/src/app/pos/lib/topping-pricing.ts so the LAN cart
// line matches the pos-web running total and Cloud's persisted subtotal.

func TestPriceLine_Flat(t *testing.T) {
	flat := toppingGroupPricing{PriceStrategy: "flat"}

	cases := []struct {
		name string
		sel  []pricedTopping
		want int
	}{
		{"empty", nil, 0},
		{"single qty1", []pricedTopping{{UnitPrice: 300, Quantity: 1}}, 300},
		{"single qty3 expands", []pricedTopping{{UnitPrice: 300, Quantity: 3}}, 900},
		{"qty0 treated as 1", []pricedTopping{{UnitPrice: 250, Quantity: 0}}, 250},
		{"qty negative treated as 1", []pricedTopping{{UnitPrice: 250, Quantity: -5}}, 250},
		{"two selections", []pricedTopping{{UnitPrice: 100, Quantity: 2}, {UnitPrice: 50, Quantity: 1}}, 250},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := priceLine(c.sel, flat); got != c.want {
				t.Errorf("priceLine(flat) = %d, want %d", got, c.want)
			}
		})
	}
}

func TestPriceLine_FreeUpToN(t *testing.T) {
	cases := []struct {
		name string
		sel  []pricedTopping
		free int
		want int
	}{
		// Waives the MOST EXPENSIVE unit → charges 300+200.
		{"free1 waives dearest", []pricedTopping{{UnitPrice: 500, Quantity: 1}, {UnitPrice: 300, Quantity: 1}, {UnitPrice: 200, Quantity: 1}}, 1, 500},
		// qty expansion: 300×3 units, waive 2 dearest (both 300) → charge one.
		{"free2 with qty3", []pricedTopping{{UnitPrice: 300, Quantity: 3}}, 2, 300},
		// free >= unit count → all waived.
		{"free covers all", []pricedTopping{{UnitPrice: 400, Quantity: 1}, {UnitPrice: 100, Quantity: 1}}, 5, 0},
		// free == 0 → flat.
		{"free0 is flat", []pricedTopping{{UnitPrice: 400, Quantity: 1}, {UnitPrice: 100, Quantity: 1}}, 0, 500},
		// free negative → flat.
		{"free negative is flat", []pricedTopping{{UnitPrice: 400, Quantity: 1}}, -3, 400},
		// equal prices, free1 → waive one, charge two.
		{"equal prices tie", []pricedTopping{{UnitPrice: 200, Quantity: 3}}, 1, 400},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			g := toppingGroupPricing{PriceStrategy: "free_up_to_n", FreeQuantity: c.free}
			if got := priceLine(c.sel, g); got != c.want {
				t.Errorf("priceLine(free_up_to_n free=%d) = %d, want %d", c.free, got, c.want)
			}
		})
	}
}

func TestPriceLine_UnknownStrategyIsFlat(t *testing.T) {
	sel := []pricedTopping{{UnitPrice: 120, Quantity: 2}}
	for _, strategy := range []string{"", "bogus", "FLAT", "percentage"} {
		g := toppingGroupPricing{PriceStrategy: strategy, FreeQuantity: 5}
		if got := priceLine(sel, g); got != 240 {
			t.Errorf("priceLine(strategy=%q) = %d, want 240 (flat fallback)", strategy, got)
		}
	}
}

func TestPriceLineAcrossGroups_NoLeakBetweenGroups(t *testing.T) {
	// Group A: flat, 2 units × 200 = 400. Group B: free_up_to_n free=1 over
	// [400,100] → waive 400 → 100. Free quota must NOT leak into group A.
	toppings := []pricedTopping{
		{ToppingGroupID: "A", UnitPrice: 200, Quantity: 2},
		{ToppingGroupID: "B", UnitPrice: 400, Quantity: 1},
		{ToppingGroupID: "B", UnitPrice: 100, Quantity: 1},
	}
	groups := map[string]toppingGroupPricing{
		"A": {PriceStrategy: "flat"},
		"B": {PriceStrategy: "free_up_to_n", FreeQuantity: 1},
	}
	if got := priceLineAcrossGroups(toppings, groups); got != 500 {
		t.Errorf("priceLineAcrossGroups = %d, want 500 (400 flat A + 100 B)", got)
	}
}

func TestPriceLineAcrossGroups_MissingGroupConfigIsFlat(t *testing.T) {
	// Group "X" has no config entry (unsynced) → zero value → flat.
	toppings := []pricedTopping{{ToppingGroupID: "X", UnitPrice: 150, Quantity: 2}}
	if got := priceLineAcrossGroups(toppings, map[string]toppingGroupPricing{}); got != 300 {
		t.Errorf("priceLineAcrossGroups(missing config) = %d, want 300 (flat)", got)
	}
}

func TestPriceLineAcrossGroups_Empty(t *testing.T) {
	if got := priceLineAcrossGroups(nil, map[string]toppingGroupPricing{}); got != 0 {
		t.Errorf("priceLineAcrossGroups(nil) = %d, want 0", got)
	}
}
