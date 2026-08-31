package service

import (
	"encoding/json"
	"os"
	"testing"
)

// #2133 — nửa GO của hợp đồng thuế khoản hoàn từng phần.
//
// # Vì sao cần fixture DÙNG CHUNG chứ không phải test riêng mỗi bên
//
// Cloud (`WritesCustomerOrders::refundItem`) và máy trạm (`RefundItem`) là HAI
// bản cài đặt của cùng một phép toán, và đường Go là đường **thật sự đưa tiền
// cho khách**: `POST /api/v1/pos/orders/{id}/items/{item}/refund` → hàm này →
// phiếu in tại quầy. Lúc mất mạng, con số local là con số DUY NHẤT.
//
// Trước bài này **không rào nào** canh hai bên: `grep -ril refund` trên bốn
// fixture golden đang có (`offline_signing`, `split_by_items_cases`,
// `tax_allocation`, `tax_resolution`) ra **rỗng**. Đó đúng lớp lệch mà #2089 ghi
// nhận — "hai bản sửa của Cloud trôi mất khỏi Go" — và nó đã xảy ra thật: bản
// sửa #2133 vào Cloud trước, Go giữ nguyên phép làm tròn từng lần, nên cùng một
// thao tác hoàn ra 303 ở quầy và 302 trên sổ.
//
// # Vì sao đặt ở `internal/service/testdata`
//
// Cùng thư mục mà cổng `SharedFixturesAgreeTest` (#2089) quét để bắt hai bản
// khớp TỪNG BYTE với `backend/tests/Fixtures/`. Đặt chỗ khác thì hai file trôi
// khỏi nhau mà không ai thấy.
type refundGoldenDoc struct {
	Cases []refundGoldenCase `json:"cases"`
}

type refundGoldenCase struct {
	Name            string   `json:"name"`
	Why             string   `json:"why"`
	TaxTotal        float64  `json:"tax_total"`
	StampedTaxTotal *float64 `json:"stamped_tax_total,omitempty"`
	OriginalQty     int      `json:"original_qty"`
	TaxStep         float64  `json:"tax_step"`
	TaxMode         string   `json:"tax_mode"`
	Refunds         []int    `json:"refunds"`
	RefundTaxes     []int    `json:"refund_taxes"`
	SumRefund       int      `json:"sum_refund_tax"`
}

func TestRefundTax_MatchesSharedGolden(t *testing.T) {
	raw, err := os.ReadFile("testdata/refund_tax_golden.json")
	if err != nil {
		t.Fatalf("đọc fixture chung: %v", err)
	}

	var doc refundGoldenDoc
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatalf("phân tích fixture: %v", err)
	}
	if len(doc.Cases) == 0 {
		// Fixture rỗng và fixture hỏng trông giống hệt nhau ở đầu ra: vòng lặp
		// dưới chạy 0 lần và bài test XANH.
		t.Fatal("fixture không có ca nào — bộ đọc hỏng, không phải hợp đồng rỗng")
	}

	for _, c := range doc.Cases {
		t.Run(c.Name, func(t *testing.T) {
			if len(c.Refunds) != len(c.RefundTaxes) {
				t.Fatalf("fixture tự mâu thuẫn: %d lần hoàn nhưng %d giá trị thuế",
					len(c.Refunds), len(c.RefundTaxes))
			}

			cum, sum := 0, 0
			for i, q := range c.Refunds {
				got := refundTaxDelta(c.TaxTotal, cum, q, c.OriginalQty, c.TaxStep, c.TaxMode)
				if got != c.RefundTaxes[i] {
					t.Errorf("lần hoàn %d (đã hoàn %d, thêm %d): thuế = %d, fixture nói %d — hai engine hoàn tiền KHÁC NHAU\n%s",
						i+1, cum, q, got, c.RefundTaxes[i], c.Why)
				}

				if c.StampedTaxTotal != nil && *c.StampedTaxTotal != c.TaxTotal {
					wrong := refundTaxDelta(*c.StampedTaxTotal, cum, q, c.OriginalQty, c.TaxStep, c.TaxMode)
					if wrong == c.RefundTaxes[i] {
						t.Errorf("lần hoàn %d: dùng thuế STAMPED %.0f ra %d — trùng kỳ vọng gross; hoàn phải dùng tax_total %.0f\n%s",
							i+1, *c.StampedTaxTotal, wrong, c.TaxTotal, c.Why)
					}
				}
				cum += q
				sum += got
			}

			if sum != c.SumRefund {
				t.Errorf("Σ thuế hoàn = %d, fixture nói %d", sum, c.SumRefund)
			}

			// Bất biến của cả dòng: hoàn HẾT thì Σ phải bằng đúng thuế đã thu —
			// với MỌI cách chia. Đây là tính chất, không phải một con số.
			if cum == c.OriginalQty && float64(sum) != c.TaxTotal {
				t.Errorf("hoàn hết %d/%d nhưng Σ = %d ≠ thuế đã thu %.0f — quán %s",
					cum, c.OriginalQty, sum, c.TaxTotal,
					map[bool]string{true: "trả DƯ", false: "trả THIẾU"}[float64(sum) > c.TaxTotal])
			}
		})
	}
}
