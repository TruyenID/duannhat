package service

import (
	"math"
	"testing"
)

func intp(v int) *int { return &v }

// [Go] roundTax modes (parity with the Pest Unit rows).
func TestRoundToStep_Modes(t *testing.T) {
	cases := []struct {
		name  string
		value float64
		step  float64
		mode  string
		want  float64
	}{
		{"jpy ceil", 123.4, 1, "ceil", 124},
		{"jpy floor", 123.4, 1, "floor", 123},
		{"jpy round", 123.4, 1, "round", 123},
		// 1.005 không biểu diễn được chính xác trong float64 (≈1.00499999…), nên
		// floor(1.005/0.01 + 0.5) TRẦN cho ra 1.00 — tức THU THIẾU ở một biên
		// .xx5 có thật.
		//
		// Ca này TRƯỚC ĐÂY kỳ vọng 1.00, và comment của nó viết: "matches Cloud
		// PHP's identical floor(v/step + 0.5)*step, which is the parity contract
		// — the workstation MUST agree with Cloud to the step."
		//
		// Nguyên tắc ấy ĐÚNG. Sự thật thì đã đổi: Cloud sửa ở e8275ad97 (#821
		// E1) bằng cách chuẩn hoá thương số về 9 chữ số trước khi quyết định .5.
		// Nên chính nguyên tắc parity mà ca này viện dẫn giờ đòi kết quả NGƯỢC
		// LẠI. Bản port Go giữ nguyên hành vi cũ suốt từ đó (#2082).
		{"usd round 1.005 (biên .xx5)", 1.005, 0.01, "round", 1.01},
		{"usd round 1.235", 1.235, 0.01, "round", 1.24},
		{"usd floor 1.005", 1.005, 0.01, "floor", 1.00},
		{"usd ceil 1.005", 1.005, 0.01, "ceil", 1.01},
		{"unknown mode → round", 123.4, 1, "banker", 123},
		// Legacy aliases (pre-rev-B snapshots) must price identically.
		{"legacy round_up → ceil", 123.4, 1, "round_up", 124},
		{"legacy round_down → floor", 123.4, 1, "round_down", 123},
		{"legacy half_up → round", 123.4, 1, "half_up", 123},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := roundToStep(c.value, c.step, c.mode); math.Abs(got-c.want) > 1e-9 {
				t.Errorf("roundToStep(%v,%v,%q) = %v, want %v", c.value, c.step, c.mode, got, c.want)
			}
		})
	}
}

// [Go] taxStep: nil decimals matches the currency step (backward-compat); a
// value forces 10^-decimals when FINER than the currency unit — the tax carries
// sub-unit precision for DISPLAY (option-B; total still rounds to yen).
//
// Nhưng KHÔNG BAO GIỜ THÔ HƠN (#2082). Ý định gốc của ca này là cho phép mịn
// hơn, và điều đó được giữ nguyên. Cái nó khẳng định thêm — `taxStep(0, "USD")
// == 1` — là THÔ HƠN đơn vị tiền tệ, và đó đúng thứ Cloud kẹp lại ở 790e007e4
// (#821 E1f): `tax_rounding_decimals = 0` là MẶC ĐỊNH của DB, nên bản cũ làm
// mọi đơn USD làm tròn thuế về nguyên đô — dòng $1.45 @10% thu $0.00.
func TestTaxStep_NilDecimalsMatchesCurrencyStep(t *testing.T) {
	if got := taxStep(nil, "JPY"); got != currencyStep("JPY") {
		t.Errorf("nil decimals JPY: got %v, want %v", got, currencyStep("JPY"))
	}
	if got := taxStep(nil, "USD"); got != currencyStep("USD") {
		t.Errorf("nil decimals USD: got %v, want %v", got, currencyStep("USD"))
	}
	// KẸP: 10^0 = 1 thô hơn xu ⇒ phải về 0,01, không phải 1.
	if got := taxStep(intp(0), "USD"); math.Abs(got-0.01) > 1e-12 {
		t.Errorf("USD decimals 0: got %v, want 0.01 (kẹp theo đơn vị tiền tệ)", got)
	}
	// decimals forces 10^-decimals even sub-currency-unit (display precision).
	if got := taxStep(intp(2), "JPY"); math.Abs(got-0.01) > 1e-12 {
		t.Errorf("JPY decimals 2: got %v, want 0.01", got)
	}
	if got := taxStep(intp(3), "JPY"); math.Abs(got-0.001) > 1e-12 {
		t.Errorf("JPY decimals 3: got %v, want 0.001", got)
	}
}

// [Go] roundTax(nil decimals) matches the legacy half-up-to-currency-step form.
func TestRoundTax_NilDecimalsBackwardCompat(t *testing.T) {
	for _, v := range []float64{100.0, 100.4, 100.5, 99.6, 1234.49} {
		want := roundHalfUpToStep(v, currencyStep("JPY"))
		got := roundToStep(v, taxStep(nil, "JPY"), "half_up")
		if math.Abs(got-want) > 1e-9 {
			t.Errorf("v=%v: roundTax=%v, legacy=%v", v, got, want)
		}
	}
}

// [Go] priceGroups with an order snapshot mode=ceil decimals=0 on a net-1000
// @10% order → tax_amount ceil(100)=100 (net-1000 lands exactly, so proves the
// engine reads the passed step/mode, not the currency step).
func TestPriceGroups_RoundUpSnapshot(t *testing.T) {
	// net 1000 @ 10% excluded = exactly 100 → any mode gives 100.
	res := priceGroups(map[string]float64{"10": 1000}, 0, 0, 0, false, 1, taxStep(intp(0), "JPY"), "ceil", nil)
	if int(res.TaxAmount) != 100 {
		t.Fatalf("tax want 100, got %v", res.TaxAmount)
	}

	// net 1005 @ 10% = 100.5 → ceil 101, floor 100, round 101.
	up := priceGroups(map[string]float64{"10": 1005}, 0, 0, 0, false, 1, taxStep(intp(0), "JPY"), "ceil", nil)
	down := priceGroups(map[string]float64{"10": 1005}, 0, 0, 0, false, 1, taxStep(intp(0), "JPY"), "floor", nil)
	half := priceGroups(map[string]float64{"10": 1005}, 0, 0, 0, false, 1, taxStep(intp(0), "JPY"), "round", nil)
	if int(up.TaxAmount) != 101 {
		t.Errorf("ceil tax want 101, got %v", up.TaxAmount)
	}
	if int(down.TaxAmount) != 100 {
		t.Errorf("floor tax want 100, got %v", down.TaxAmount)
	}
	if int(half.TaxAmount) != 101 {
		t.Errorf("round tax want 101 (100.5), got %v", half.TaxAmount)
	}
}

// [Go] a rate group containing a positive line + a refund (negative) line: the
// refund line is EXCLUDED from AllocateGroupTax (no ≥0 clamp corruption) and its
// negated snapshot tax is added directly; the group total reconciles.
func TestApplyRefundLines_ExactReversal(t *testing.T) {
	// Positive: net 1000 @10% excluded → base tax 100, subtotal 1000, total 1100.
	base := priceGroups(map[string]float64{"10": 1000}, 0, 0, 0, false, 1, 1, "round", nil)
	if int(base.TaxAmount) != 100 || int(base.TotalAmount) != 1100 {
		t.Fatalf("base: tax=%v total=%v, want 100/1100", base.TaxAmount, base.TotalAmount)
	}

	// Refund half the line: subtotal −500, tax −50 (negated snapshot, NOT re-rounded).
	res := applyRefundLines(base, []RefundLine{{Subtotal: -500, TaxAmount: -50, Rate: 10}})
	if int(res.TaxAmount) != 50 {
		t.Errorf("tax after refund want 50, got %v", res.TaxAmount)
	}
	if int(res.Subtotal) != 500 {
		t.Errorf("subtotal after refund want 500, got %v", res.Subtotal)
	}
	if int(res.TotalAmount) != 550 {
		t.Errorf("total after refund want 550 (1100 − 550), got %v", res.TotalAmount)
	}
	// The 10% group's tax + taxable must have dropped by the refund share.
	var g10 *TaxGroup
	for i := range res.Groups {
		if res.Groups[i].Rate == 10 {
			g10 = &res.Groups[i]
		}
	}
	if g10 == nil {
		t.Fatal("missing 10% group after refund")
	}
	if int(g10.Tax) != 50 {
		t.Errorf("10%% group tax want 50, got %v", g10.Tax)
	}
	if int(g10.Taxable) != 500 {
		t.Errorf("10%% group taxable want 500, got %v", g10.Taxable)
	}

	// Full reversal: refund the whole line → tax 0, total 0.
	full := applyRefundLines(base, []RefundLine{{Subtotal: -1000, TaxAmount: -100, Rate: 10}})
	if int(full.TaxAmount) != 0 || int(full.TotalAmount) != 0 {
		t.Errorf("full reversal: tax=%v total=%v, want 0/0", full.TaxAmount, full.TotalAmount)
	}
}

// [Go] refund at a rate whose positive group is already gone still gets its own
// row so Σ groups.tax == tax_amount.
func TestApplyRefundLines_OrphanRateGroup(t *testing.T) {
	base := priceGroups(map[string]float64{"10": 1000}, 0, 0, 0, false, 1, 1, "round", nil)
	// Refund carries an 8% rate that never existed in the positive groups.
	res := applyRefundLines(base, []RefundLine{{Subtotal: -540, TaxAmount: -40, Rate: 8}})
	sumTax := 0.0
	for _, g := range res.Groups {
		sumTax += g.Tax
	}
	if int(sumTax) != int(res.TaxAmount) {
		t.Errorf("Σ groups.tax=%v != tax_amount=%v", sumTax, res.TaxAmount)
	}
	// 8% group must exist with tax −40.
	found := false
	for _, g := range res.Groups {
		if g.Rate == 8 {
			found = true
			if int(g.Tax) != -40 {
				t.Errorf("8%% orphan group tax want -40, got %v", g.Tax)
			}
		}
	}
	if !found {
		t.Error("expected a synthetic 8% group for the orphan refund rate")
	}
}

// [GoInt] Cloud==Go round-trip parity: for a net-1234 @10% excluded order the Go
// engine must produce the SAME tax_amount Cloud's OrderPricingCalculator would
// for each mode×decimals combination. Figures are hand-computed from the same
// once-per-group formula Cloud uses (roundToStep(1234*0.10=123.4, step, mode)).
func TestPriceGroups_CloudParityAcrossModesDecimals(t *testing.T) {
	cases := []struct {
		mode     string
		decimals *int
		wantTax  int
	}{
		// decimals 0 → step 1: 123.4 → round 123, ceil 124, floor 123.
		{"round", intp(0), 123},
		{"ceil", intp(0), 124},
		{"floor", intp(0), 123},
		// nil decimals on JPY → currency step 1 (same as decimals 0).
		{"round", nil, 123},
		{"ceil", nil, 124},
		{"floor", nil, 123},
	}
	for _, c := range cases {
		name := c.mode
		if c.decimals == nil {
			name += "_nil"
		}
		t.Run(name, func(t *testing.T) {
			step := taxStep(c.decimals, "JPY")
			res := priceGroups(map[string]float64{"10": 1234}, 0, 0, 0, false, 1, step, c.mode, nil)
			if int(res.TaxAmount) != c.wantTax {
				t.Errorf("mode=%s decimals=%v: tax=%v, want %d", c.mode, c.decimals, res.TaxAmount, c.wantTax)
			}
		})
	}
}

// [Go] included (内税) refund reversal is exact from the negated snapshot.
func TestApplyRefundLines_IncludedMode(t *testing.T) {
	// Gross 1100 @10% included → tax = 1100 − round(1100/1.1) = 100.
	base := priceGroups(map[string]float64{"10": 1100}, 0, 0, 0, true, 1, 1, "round", nil)
	if int(base.TaxAmount) != 100 {
		t.Fatalf("included base tax want 100, got %v", base.TaxAmount)
	}
	// Refund the whole line: subtotal −1100 (gross), tax −100 (extracted).
	res := applyRefundLines(base, []RefundLine{{Subtotal: -1100, TaxAmount: -100, Rate: 10}})
	if int(res.TaxAmount) != 0 {
		t.Errorf("included refund tax want 0, got %v", res.TaxAmount)
	}
	if int(res.TotalAmount) != 0 {
		t.Errorf("included refund total want 0 (gross inside subtotal), got %v", res.TotalAmount)
	}
}
