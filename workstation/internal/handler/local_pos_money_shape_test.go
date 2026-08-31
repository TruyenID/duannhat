package handler

import "testing"

// #1246 — the LAN money pipeline carries amounts as WHOLE UNITS in an int, which
// is only correct while every shop runs a zero-decimal currency.
//
// Nothing is wrong today (JPY and VND are both zero-decimal), and this file does
// not pretend otherwise. What it pins is the part that used to be invisible: the
// render/parse pair must agree, and the currency table that decides whether an
// int can hold this currency at all must be the SAME table the rounding layer
// uses. Before this change the formatter was called `formatYen`, hard-coded
// ".00", and had no test — so the constraint lived entirely in a function name.

func TestFormatWholeUnits_MatchesBackendDecimalShape(t *testing.T) {
	cases := map[string]struct {
		amount int
		want   string
	}{
		"zero":     {0, "0.00"},
		"typical":  {50000, "50000.00"},
		"one":      {1, "1.00"},
		"negative": {-250, "-250.00"},
	}

	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			if got := formatWholeUnits(c.amount); got != c.want {
				t.Errorf("formatWholeUnits(%d) = %q, want %q", c.amount, got, c.want)
			}
		})
	}
}

// The split-by-items reconciliation reads its own output back to move the
// rounding difference onto the last non-empty bill. Round-tripping is therefore
// load-bearing: a render/parse mismatch would corrupt that bill's tax and total
// while every other row stayed plausible.
func TestParseWholeUnits_RoundTripsFormat(t *testing.T) {
	for _, amount := range []int{0, 1, 7, 250, 50000, 1234567, -250} {
		if got := parseWholeUnits(formatWholeUnits(amount)); got != amount {
			t.Errorf("round-trip of %d gave %d", amount, got)
		}
	}
}

// isWholeUnitCurrency is derived from service.CurrencyStep — the same table the
// rounding layer consults — so the display side can never quietly disagree with
// the maths side about what a currency's smallest unit is.
func TestIsWholeUnitCurrency_AgreesWithRoundingLayer(t *testing.T) {
	whole := []string{"JPY", "VND", "KRW", "CLP", "XOF"}
	fractional := []string{"USD", "EUR", "GBP", "THB", "KWD", "BHD"}

	for _, code := range whole {
		if !isWholeUnitCurrency(code) {
			t.Errorf("%s is zero-decimal — an int amount is lossless, want true", code)
		}
	}
	for _, code := range fractional {
		if isWholeUnitCurrency(code) {
			t.Errorf("%s has a fractional part — an int amount loses it, want false", code)
		}
	}
}

// Empty currency must not read as fractional. service.CurrencyStep defaults an
// empty code to VND (matching the PHP `?? 'VND'`), so a shop with no
// currency_code row stays on the correct whole-unit path instead of tripping the
// warning on every request.
func TestIsWholeUnitCurrency_EmptyDefaultsToWholeUnit(t *testing.T) {
	if !isWholeUnitCurrency("") {
		t.Error("empty currency code must default to the project default (VND, whole-unit)")
	}
}

// The guard is the point of #1246: a two-decimal shop must not pass through
// silently. It cannot repair the amount — the cents were lost upstream, before
// formatting — so all it can do is refuse to be quiet. Calling it must stay
// safe (no panic) because it runs inside request handling.
func TestAssertWholeUnitCurrency_DoesNotPanicOnFractionalCurrency(t *testing.T) {
	defer func() {
		if r := recover(); r != nil {
			t.Fatalf("assertWholeUnitCurrency panicked on a fractional currency: %v", r)
		}
	}()

	assertWholeUnitCurrency("USD")
	assertWholeUnitCurrency("JPY")
}
