package service

import (
	"encoding/json"
	"math"
	"os"
	"path/filepath"
	"testing"
)

// #2253 — nửa Go của hợp đồng phân bổ khoản giảm theo gross CÒN SỐNG (#2240).
// Máy trạm offline in từ số local; lệch ở priceGroups(discountWeights) ⇒
// tax_breakdown khách đọc mang nhóm thuế ÂM.
type discountWeightsGoldenDoc struct {
	Cases []discountWeightsGoldenCase `json:"cases"`
}

type discountWeightsGoldenCase struct {
	Name                 string                      `json:"name"`
	Why                  string                      `json:"why"`
	RateSubtotals        map[string]float64          `json:"rate_subtotals"`
	DiscountWeights      map[string]float64          `json:"discount_weights"`
	Discount             float64                     `json:"discount"`
	PricesIncludeTax     bool                        `json:"prices_include_tax"`
	ServiceChargeRate    float64                     `json:"service_charge_rate"`
	ServiceChargeTaxRate float64                     `json:"service_charge_tax_rate"`
	Step                 float64                     `json:"step"`
	TaxStep              float64                     `json:"tax_step"`
	TaxMode              string                      `json:"tax_mode"`
	RefundLines          []discountWeightsRefundLine `json:"refund_lines"`
	ExpectedTax          float64                     `json:"expected_tax"`
	ExpectedTotal        float64                     `json:"expected_total"`
	MinGroupTax          float64                     `json:"min_group_tax"`
}

type discountWeightsRefundLine struct {
	Rate      float64 `json:"rate"`
	Subtotal  float64 `json:"subtotal"`
	TaxAmount float64 `json:"tax_amount"`
}

func TestDiscountWeights_MatchesSharedGolden(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join("testdata", "discount_weights_golden.json"))
	if err != nil {
		t.Fatalf("đọc fixture chung: %v", err)
	}

	var doc discountWeightsGoldenDoc
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatalf("phân tích fixture: %v", err)
	}
	if len(doc.Cases) == 0 {
		t.Fatal("fixture không có ca nào")
	}

	for _, c := range doc.Cases {
		t.Run(c.Name, func(t *testing.T) {
			base := priceGroups(
				c.RateSubtotals,
				c.Discount,
				c.ServiceChargeRate,
				c.ServiceChargeTaxRate,
				c.PricesIncludeTax,
				c.Step,
				c.TaxStep,
				c.TaxMode,
				c.DiscountWeights,
			)
			res := applyRefundLines(base, toRefundLines(c.RefundLines))

			if math.Abs(res.TaxAmount-c.ExpectedTax) > 1e-9 {
				t.Errorf("tax = %v, fixture nói %v\n%s", res.TaxAmount, c.ExpectedTax, c.Why)
			}
			if math.Abs(res.TotalAmount-c.ExpectedTotal) > 1e-9 {
				t.Errorf("total = %v, fixture nói %v\n%s", res.TotalAmount, c.ExpectedTotal, c.Why)
			}
			for _, g := range res.Groups {
				if g.Tax < c.MinGroupTax {
					t.Errorf("nhóm %.0f%% tax = %v < min %v — tax_breakdown khách đọc không được âm\n%s",
						g.Rate, g.Tax, c.MinGroupTax, c.Why)
				}
			}

			// Regression guard: nil weights reproduces the pre-#2253 wrong totals.
			if c.Name == "2240-canonical-a-refunded" {
				legacy := applyRefundLines(priceGroups(
					c.RateSubtotals, c.Discount,
					c.ServiceChargeRate, c.ServiceChargeTaxRate,
					c.PricesIncludeTax, c.Step, c.TaxStep, c.TaxMode,
					nil,
				), toRefundLines(c.RefundLines))
				if math.Abs(legacy.TaxAmount-35) > 1e-9 || math.Abs(legacy.TotalAmount-535) > 1e-9 {
					t.Fatalf("legacy nil-weights baseline moved — cập nhật fixture, không phải xoá guard")
				}
			}
		})
	}
}

func toRefundLines(in []discountWeightsRefundLine) []RefundLine {
	out := make([]RefundLine, len(in))
	for i, r := range in {
		out[i] = RefundLine{Subtotal: r.Subtotal, TaxAmount: r.TaxAmount, Rate: r.Rate}
	}
	return out
}

func TestDiscountWeights_FixtureBytesMatchBackend(t *testing.T) {
	ours := filepath.Join("testdata", "discount_weights_golden.json")
	theirs := filepath.Join("..", "..", "..", "backend", "tests", "Fixtures", "discount_weights_golden.json")
	o, err := os.ReadFile(ours)
	if err != nil {
		t.Fatalf("đọc fixture Go: %v", err)
	}
	b, err := os.ReadFile(theirs)
	if err != nil {
		t.Skipf("backend fixture chưa có trong worktree: %v", err)
	}
	if string(o) != string(b) {
		t.Fatal("discount_weights_golden.json lệch byte với backend/tests/Fixtures/")
	}
}
