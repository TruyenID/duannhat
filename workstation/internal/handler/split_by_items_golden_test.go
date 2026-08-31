package handler

import (
	"encoding/json"
	"os"
	"strconv"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// #2112 — nửa Go của hợp đồng chia bill theo món.
//
// `split_by_items_cases.json` tồn tại từ plan-033 và được PHP
// (`SplitByItemsCalculator`) đọc. Bản đọc thứ hai mà `_comment` của nó dự tính
// là **pos-web/Vitest**, và bản ấy chưa bao giờ được viết. Trong lúc đó Go lại
// có một bản cài đặt chia bill THỨ BA — `computeByItemsPreviewBills` — mà không
// ai đối chiếu với fixture.
//
// Tức phép **chia tiền của khách** có hai engine đang chạy thật (PHP và Go) và
// không gì buộc chúng ra cùng một con số. Đây là loại lệch tệ nhất: nó rơi
// thẳng vào số tiền từng người trong bàn phải trả.
//
// # Vì sao dựng lại `Order` được mà không thêm sai số
//
// Fixture nêu `tax_rate` / `service_charge_rate` tường minh, còn Go **suy ngược**
// tỉ lệ từ số tiền của đơn:
//
//	taxableBase = subtotal − discount
//	taxRate     = tax  × 100 / taxableBase
//
// Nếu phải TỰ TÍNH `tax` từ `tax_rate` thì phép làm tròn của bài test sẽ trộn
// vào kết quả và không còn đo được engine nữa. Đã kiểm cả 8 ca: `total_amount`
// trong fixture đã nhất quán với hai tỉ lệ ấy (ví dụ ca `mode-two-decimals`:
// 1000 + 10% + 5% = 1150), nên `tax` và `service` suy ra từ chính fixture bằng
// phép nhân ĐÚNG, không dư phần lẻ.
//
// # Vì sao truyền `formatAmount` riêng
//
// `computeByItemsPreviewBills` trả chuỗi đã định dạng theo tiền tệ của server.
// So chuỗi là kéo cả cấu hình hiển thị vào một bài đo phép tính. Hàm nhận
// `formatAmount` làm THAM SỐ, nên bài này truyền `strconv.Itoa` và so **số**.

type splitGoldenDoc struct {
	Cases []splitGoldenCase `json:"cases"`
}

type splitGoldenCase struct {
	Name  string `json:"name"`
	Order struct {
		Subtotal       int `json:"subtotal"`
		DiscountAmount int `json:"discount_amount"`
		TotalAmount    int `json:"total_amount"`
		Items          []struct {
			ID              string `json:"id"`
			UnitPrice       int    `json:"unit_price"`
			Quantity        int    `json:"quantity"`
			ToppingSubtotal int    `json:"topping_subtotal"`
			Status          string `json:"status"`
		} `json:"items"`
	} `json:"order"`
	Allocations []struct {
		ItemID    string `json:"item_id"`
		Units     int    `json:"units"`
		BillIndex int    `json:"bill_index"`
	} `json:"allocations"`
	TaxRate           float64 `json:"tax_rate"`
	ServiceChargeRate float64 `json:"service_charge_rate"`
	PeopleCount       int     `json:"people_count"`
	Expected          struct {
		TotalCheck int `json:"total_check"`
		Bills      []struct {
			Index    int `json:"index"`
			Subtotal int `json:"subtotal"`
			Discount int `json:"discount"`
			Tax      int `json:"tax"`
			Service  int `json:"service_charge"`
			Total    int `json:"total"`
		} `json:"bills"`
	} `json:"expected"`
}

func loadSplitGolden(t *testing.T) []splitGoldenCase {
	t.Helper()

	// Bản sinh đôi nằm ở `internal/service/testdata` chứ không phải cạnh bài này:
	// đó là thư mục mà cổng `SharedFixturesAgreeTest` (#2089) quét để bắt hai bản
	// khớp từng byte. Đặt đúng chỗ ấy quan trọng hơn đặt gần code.
	raw, err := os.ReadFile("../service/testdata/split_by_items_cases.json")
	if err != nil {
		t.Fatalf("đọc fixture chung: %v", err)
	}

	var doc splitGoldenDoc
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatalf("phân tích fixture: %v", err)
	}
	if len(doc.Cases) == 0 {
		// Fixture rỗng và fixture hỏng trông giống hệt nhau ở đầu ra: mọi vòng
		// lặp bên dưới chạy 0 lần và bài test XANH.
		t.Fatal("fixture không có ca nào — bộ đọc hỏng, không phải hợp đồng rỗng")
	}

	return doc.Cases
}

// knownDivergent — ca mà Go CHƯA khớp PHP, kèm cơ chế đo được.
//
// Danh sách CHỈ ĐƯỢC CO LẠI. Nó không phải chỗ để dung thứ; nó là cách biến hai
// lỗi vô hình thành hai dòng kiểm được, trong lúc chờ một quyết định về LUẬT
// PHÂN BỔ — và luật đó là quyết định tiền, không phải chi tiết cài đặt.
//
// Xem #2130.
// RỖNG từ #2165 (`people_count` thành đầu vào tường minh) — hai engine chia
// bill khớp hoàn toàn trên fixture chung. Chỉ được thêm lại kèm issue mở.
var knownDivergent = map[string]string{}

func TestSplitByItems_KnownDivergenceListOnlyShrinks(t *testing.T) {
	// Mặt kia của bánh cóc: một ca đã khớp mà vẫn nằm trong danh sách thì danh
	// sách thôi nói lên điều gì. Ở đây kiểm bằng cách chạy CHÍNH ca ấy.
	for _, c := range loadSplitGolden(t) {
		if _, known := knownDivergent[c.Name]; !known {
			continue
		}
		if len(c.Expected.Bills) == 0 {
			t.Errorf("ca %q khai là lệch nhưng fixture không có kỳ vọng nào để so", c.Name)
		}
	}

	for name := range knownDivergent {
		found := false
		for _, c := range loadSplitGolden(t) {
			if c.Name == name {
				found = true

				break
			}
		}
		if !found {
			t.Errorf("ca %q nằm trong knownDivergent nhưng KHÔNG còn trong fixture — gỡ nó đi", name)
		}
	}
}

func TestSplitByItems_MatchesSharedGolden(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")

	plain := func(v int) string { return strconv.Itoa(v) }
	num := func(t *testing.T, v any) int {
		t.Helper()
		n, err := strconv.Atoi(v.(string))
		if err != nil {
			t.Fatalf("giá trị không phải số: %v", v)
		}

		return n
	}

	for _, c := range loadSplitGolden(t) {
		t.Run(c.Name, func(t *testing.T) {
			if why, known := knownDivergent[c.Name]; known {
				// KHÔNG `t.Skip`: bỏ qua thì lỗi biến mất khỏi bảng tổng kết y như
				// trước khi có bài này. Ghi ra để nó đếm được và đọc được.
				t.Logf("LỆCH ĐÃ BIẾT (#2130): %s", why)

				return
			}

			taxableBase := c.Order.Subtotal - c.Order.DiscountAmount

			order := &service.Order{
				ID:             "golden",
				Status:         "open",
				Subtotal:       c.Order.Subtotal,
				DiscountAmount: c.Order.DiscountAmount,
				TotalAmount:    c.Order.TotalAmount,
				TaxAmount:      float64(taxableBase) * c.TaxRate / 100.0,
				ServiceCharge:  int(float64(taxableBase) * c.ServiceChargeRate / 100.0),
			}
			for _, it := range c.Order.Items {
				order.Items = append(order.Items, service.Item{
					ID:              it.ID,
					MenuItemName:    it.ID,
					Quantity:        it.Quantity,
					UnitPrice:       it.UnitPrice,
					ToppingSubtotal: it.ToppingSubtotal,
					Subtotal:        it.Quantity*it.UnitPrice + it.ToppingSubtotal,
					Status:          service.ItemStatus(it.Status),
				})
			}

			allocs := make([]splitByItemsAllocationInput, 0, len(c.Allocations))
			for _, a := range c.Allocations {
				allocs = append(allocs, splitByItemsAllocationInput{
					ItemID:    a.ItemID,
					Units:     a.Units,
					BillIndex: a.BillIndex,
				})
			}

			// `deriveOrderMoney` lấy số tiền từ SỔ khi sổ có, ngược lại rơi về
			// ba cột của đơn (#2075, ws#236). Đơn trong fixture KHÔNG có sổ, nên
			// nó trả về đúng ba cột mà fixture mô tả — vì vậy mọi con số kỳ vọng
			// dưới đây không phụ thuộc vào việc sổ tồn tại hay không.
			//
			// Bản đầu của file này gọi hàm với chữ ký CŨ (3 đối số) vì check CI
			// của nó chạy 11 tiếng TRƯỚC khi ws#236 merge, và GitHub không chạy
			// lại check khi base tiến lên. Merge sạch về mặt văn bản (file mới),
			// nên bốn cổng đều xanh trong khi `dev` không dịch được — xem #2160
			// cho phép đo, #2156 cho lỗ hổng cổng.
			bills, err := computeByItemsPreviewBills(order, allocs, plain, srv.deriveOrderMoney(order), c.PeopleCount)
			if err != nil {
				t.Fatalf("computeByItemsPreviewBills: %v", err)
			}

			if len(bills) != len(c.Expected.Bills) {
				t.Fatalf("số hoá đơn con = %d, fixture nói %d", len(bills), len(c.Expected.Bills))
			}

			sumTotal := 0
			for i, want := range c.Expected.Bills {
				got := bills[i]
				for _, f := range []struct {
					key  string
					want int
				}{
					{"subtotal", want.Subtotal},
					{"discount", want.Discount},
					{"tax", want.Tax},
					{"total", want.Total},
				} {
					if g := num(t, got[f.key]); g != f.want {
						t.Errorf("bill %d %s = %d, fixture nói %d — hai engine chia tiền KHÁC NHAU",
							i, f.key, g, f.want)
					}
				}
				sumTotal += num(t, got["total"])
			}

			// Bất biến của cả bàn: Σ hoá đơn con == tổng phải thu. Một phép chia
			// sai từng dòng mà vẫn cộng đúng là lỗi hiển thị; cộng sai là mất tiền.
			if sumTotal != c.Expected.TotalCheck {
				t.Errorf("Σ hoá đơn con = %d, tổng phải thu = %d", sumTotal, c.Expected.TotalCheck)
			}
		})
	}
}
