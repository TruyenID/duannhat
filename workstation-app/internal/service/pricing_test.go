package service

import (
	"math"
	"testing"
)

// plan-043 §8 — Go↔PHP engine parity fixtures. These reproduce the SAME
// hand-verified cases the backend OrderPricingCalculatorTest asserts (JPY,
// step=1). If any of these drift, the workstation no longer agrees with Cloud
// to the yen — a parity-CRITICAL regression.

// pricingFixture is one row of the shared fixture table.
type pricingFixture struct {
	name              string
	rateSubtotals     map[string]float64
	discount          float64
	serviceRate       float64
	serviceTaxRate    float64
	pricesIncludeTax  bool
	wantTax           int
	wantTotal         int
	wantServiceCharge int
	wantServiceTax    int
	// optional per-group assertions: rate → {tax, taxable}
	wantGroups map[float64][2]int
}

func TestPriceGroups_ParityFixtures(t *testing.T) {
	const step = 1.0 // JPY

	fixtures := []pricingFixture{
		{
			name:          "proof case — {8:1000, 10:500} excluded → tax 130 total 1630",
			rateSubtotals: map[string]float64{"8": 1000, "10": 500},
			wantTax:       130, // 80 + 50
			wantTotal:     1630,
			wantGroups:    map[float64][2]int{8: {80, 1000}, 10: {50, 500}},
		},
		{
			name:          "rounding once — {8:999} → tax 80 (NOT 81)",
			rateSubtotals: map[string]float64{"8": 999},
			wantTax:       80, // round(999*0.08=79.92)=80, NOT 3×round(333*0.08)=81
			wantTotal:     1079,
			wantGroups:    map[float64][2]int{8: {80, 999}},
		},
		{
			name:             "included — {8:1080, 10:550} → total 1630 tax 130 group8 tax 80 taxable 1000",
			rateSubtotals:    map[string]float64{"8": 1080, "10": 550},
			pricesIncludeTax: true,
			wantTax:          130,
			wantTotal:        1630,
			wantGroups:       map[float64][2]int{8: {80, 1000}, 10: {50, 500}},
		},
		{
			name:          "coupon pro-rata — {8:3000, 10:2000} disc 500 excluded → g8 216 g10 180 total 4896",
			rateSubtotals: map[string]float64{"8": 3000, "10": 2000},
			discount:      500,
			wantTax:       396, // 216 + 180
			wantTotal:     4896,
			wantGroups:    map[float64][2]int{8: {216, 2700}, 10: {180, 1800}},
		},
		{
			name:              "service charge — {10:2000} scRate 10 scTaxRate 10 excluded → sc 200 scTax 20 tax 220 total 2420",
			rateSubtotals:     map[string]float64{"10": 2000},
			serviceRate:       10,
			serviceTaxRate:    10,
			wantTax:           220, // group10 200 + scTax 20
			wantTotal:         2420,
			wantServiceCharge: 200,
			wantServiceTax:    20,
			wantGroups:        map[float64][2]int{10: {220, 2200}}, // 200 item tax + 20 sc tax; taxable 2000 + 200 sc net
		},
		{
			name:             "exempt — {0:1000} included → tax 0 total 1000",
			rateSubtotals:    map[string]float64{"0": 1000},
			pricesIncludeTax: true,
			wantTax:          0,
			wantTotal:        1000,
			wantGroups:       map[float64][2]int{0: {0, 1000}},
		},
	}

	for _, f := range fixtures {
		t.Run(f.name, func(t *testing.T) {
			res := priceGroups(f.rateSubtotals, f.discount, f.serviceRate, f.serviceTaxRate, f.pricesIncludeTax, step, step, "round")

			if got := int(res.TaxAmount); got != f.wantTax {
				t.Errorf("taxAmount = %d, want %d", got, f.wantTax)
			}
			if got := int(res.TotalAmount); got != f.wantTotal {
				t.Errorf("totalAmount = %d, want %d", got, f.wantTotal)
			}
			if f.wantServiceCharge != 0 && int(res.ServiceCharge) != f.wantServiceCharge {
				t.Errorf("serviceCharge = %d, want %d", int(res.ServiceCharge), f.wantServiceCharge)
			}
			if f.wantServiceTax != 0 && int(res.ServiceChargeTax) != f.wantServiceTax {
				t.Errorf("serviceChargeTax = %d, want %d", int(res.ServiceChargeTax), f.wantServiceTax)
			}
			for rate, want := range f.wantGroups {
				var found *TaxGroup
				for i := range res.Groups {
					if res.Groups[i].Rate == rate {
						found = &res.Groups[i]
						break
					}
				}
				if found == nil {
					t.Errorf("group @%g%% missing", rate)
					continue
				}
				if int(found.Tax) != want[0] {
					t.Errorf("group @%g%% tax = %d, want %d", rate, int(found.Tax), want[0])
				}
				if int(found.Taxable) != want[1] {
					t.Errorf("group @%g%% taxable = %d, want %d", rate, int(found.Taxable), want[1])
				}
			}

			// Groups must be sorted by rate ascending (parity with PHP ksort).
			for i := 1; i < len(res.Groups); i++ {
				if res.Groups[i-1].Rate > res.Groups[i].Rate {
					t.Errorf("groups not sorted ascending by rate: %v", res.Groups)
				}
			}
		})
	}
}

// TestRoundHalfUpToStep pins the shared rounding rule (mirror of
// RoundingMode::roundHalfUpToStep).
func TestRoundHalfUpToStep(t *testing.T) {
	cases := []struct {
		v, step, want float64
	}{
		{79.92, 1, 80}, // proof: 999 × 8% rounds up
		{79.4, 1, 79},  // below .5 rounds down
		{79.5, 1, 80},  // exactly .5 → up
		{0, 1, 0},      // zero
		{2.5, 0, 2.5},  // step ≤ 0 falls back to 0.01 → 2.50
		{1.02, 0.01, 1.02},
	}
	for _, c := range cases {
		if got := roundHalfUpToStep(c.v, c.step); math.Abs(got-c.want) > 1e-9 {
			t.Errorf("roundHalfUpToStep(%g, %g) = %g, want %g", c.v, c.step, got, c.want)
		}
	}
}

// TestCurrencyStep pins the step resolution (mirror of RoundingMode::step auto).
func TestCurrencyStep(t *testing.T) {
	cases := map[string]float64{
		"JPY": 1, "VND": 1, "KRW": 1,
		"USD": 0.01, "EUR": 0.01, "THB": 0.01,
		"KWD": 0.001, "BHD": 0.001,
		"": 1, // empty → VND default → 1
	}
	for cur, want := range cases {
		if got := currencyStep(cur); got != want {
			t.Errorf("currencyStep(%q) = %g, want %g", cur, got, want)
		}
	}
}

// TestAllocateGroupTax — per-line allocation (largest remainder). Mirrors the
// backend OrderPricingCalculatorTest allocateGroupTax cases: the parts sum
// EXACTLY to the group tax and never re-introduce the per-line-rounded figure.
func TestAllocateGroupTax(t *testing.T) {
	sum := func(xs []float64) float64 {
		s := 0.0
		for _, x := range xs {
			s += x
		}
		return s
	}
	eq := func(a, b []float64) bool {
		if len(a) != len(b) {
			return false
		}
		for i := range a {
			if math.Abs(a[i]-b[i]) > 1e-9 {
				return false
			}
		}
		return true
	}

	// 3×¥333 @8% excluded: group round(999×0.08)=80 → 27+27+26, NOT 81.
	got := AllocateGroupTax([]float64{26.64, 26.64, 26.64}, 80, 1)
	if !eq(got, []float64{27, 27, 26}) || sum(got) != 80 {
		t.Errorf("3×333@8%%: got %v (sum %g), want [27 27 26] sum 80 (not 81)", got, sum(got))
	}

	// 5×¥333 @8%: group round(1665×0.08=133.2)=133 → 27,27,27,26,26 (input order).
	got = AllocateGroupTax([]float64{26.64, 26.64, 26.64, 26.64, 26.64}, 133, 1)
	if !eq(got, []float64{27, 27, 27, 26, 26}) || sum(got) != 133 {
		t.Errorf("5×333@8%%: got %v (sum %g), want [27 27 27 26 26] sum 133", got, sum(got))
	}

	// included (内税) 3×¥360 @8%: group 1080 − round(1080/1.08)=80 → sum 80, ≥0.
	nets := []float64{360, 360, 360}
	ideals := make([]float64, 3)
	for i, n := range nets {
		ideals[i] = LineTaxIdeal(n, 8, true)
	}
	gt := GroupTaxFor(1080, 8, true, 1, "round")
	got = AllocateGroupTax(ideals, gt, 1)
	if gt != 80 || sum(got) != 80 {
		t.Errorf("included: group=%g sum=%g, want 80/80", gt, sum(got))
	}
	for _, v := range got {
		if v < 0 {
			t.Errorf("included produced negative line: %v", got)
		}
	}

	// USD sub-unit: 3×$3.33 @8% → group round(9.99×0.08=0.7992)=0.80.
	gt = GroupTaxFor(9.99, 8, false, 0.01, "round")
	got = AllocateGroupTax([]float64{0.2664, 0.2664, 0.2664}, gt, 0.01)
	if math.Abs(sum(got)-0.80) > 1e-9 {
		t.Errorf("USD sum = %g, want 0.80", sum(got))
	}

	// single line takes the whole group tax; exempt allocates zero.
	if got = AllocateGroupTax([]float64{79.92}, 80, 1); len(got) != 1 || got[0] != 80 {
		t.Errorf("single = %v, want [80]", got)
	}
	if got = AllocateGroupTax([]float64{0, 0}, 0, 1); sum(got) != 0 {
		t.Errorf("exempt sum = %g, want 0", sum(got))
	}
}
