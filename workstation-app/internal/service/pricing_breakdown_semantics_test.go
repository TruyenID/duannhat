package service

import "testing"

// 課税売上 on the 精算 / 引き継ぎ slip must be the base its OWN tax line was
// computed from — an auditor reads the per-rate pair (base, tax) together and
// expects base × rate == tax (インボイス). PerRateTaxBuckets used to report the
// raw Σ line subtotal instead, which is a different number in two real cases.
func TestPerRateTaxBuckets_TaxableMatchesTheTaxItPrintsBesideIt(t *testing.T) {
	// The engine is the definition of record; assert the buckets agree with it.
	cases := []struct {
		name        string
		lineSub     float64
		discount    float64
		includeTax  bool
		wantTaxable float64
		wantTax     float64
	}{
		{
			name: "coupon discount — base is NET of the pro-rata discount",
			// 10,000 of 10% goods, 2,000 off → tax is 10% of 8,000.
			// Reporting 10,000 next to a tax of 800 contradicts itself.
			lineSub: 10000, discount: 2000, includeTax: false,
			wantTaxable: 8000, wantTax: 800,
		},
		{
			name: "tax-included (総額表示) — base has the 内税 extracted",
			// 11,000 gross incl. 10% → base 10,000, tax 1,000.
			// Reporting 11,000 would print the tax inside the taxable line.
			lineSub: 11000, discount: 0, includeTax: true,
			wantTaxable: 10000, wantTax: 1000,
		},
		{
			name:    "plain excluded, no discount — unchanged",
			lineSub: 10000, discount: 0, includeTax: false,
			wantTaxable: 10000, wantTax: 1000,
		},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			res := priceGroups(
				map[string]float64{"10": c.lineSub}, c.discount,
				0, 0, c.includeTax, 1, 1, "round",
			)
			if len(res.Groups) != 1 {
				t.Fatalf("expected 1 rate group, got %d", len(res.Groups))
			}
			g := res.Groups[0]
			if g.Taxable != c.wantTaxable {
				t.Errorf("engine taxable = %v, want %v", g.Taxable, c.wantTaxable)
			}
			if g.Tax != c.wantTax {
				t.Errorf("engine tax = %v, want %v", g.Tax, c.wantTax)
			}

			// Now the slip's own computation, fed the same shape, must land on
			// the SAME pair — that equality is the whole point.
			incl := 0
			if c.includeTax {
				incl = 1
			}
			gotTaxable, gotTax := perRateBucket(incl, c.discount, c.lineSub, 10, c.lineSub, 1)
			if gotTaxable != int(c.wantTaxable) {
				t.Errorf("slip 課税売上 = %d, want %d", gotTaxable, int(c.wantTaxable))
			}
			if gotTax != int(c.wantTax) {
				t.Errorf("slip 消費税 = %d, want %d", gotTax, int(c.wantTax))
			}
		})
	}
}
