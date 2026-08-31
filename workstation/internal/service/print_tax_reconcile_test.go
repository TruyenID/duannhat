package service

import (
	"math"
	"reflect"
	"sort"
	"strconv"
	"testing"
)

// #2090 — CON SỐ TRÊN GIẤY phải cộng đúng.
//
// Trước bài này không test nào khẳng định điều đó. Ba bài hàng xóm gần nhất đều
// đo thứ khác:
//
//	SlipByteParityTest   cùng dữ liệu ⇒ cùng byte hai repo — KHÔNG chứng minh
//	                     hai NGUỒN dữ liệu bằng nhau
//	print_golden.json    sha256 mỗi ca — bắt được THAY ĐỔI, không bắt được SAI
//	BillTaxBreakdownQr   in đúng snapshot, không tự tính lại — không cộng tổng
//
// Một hash chỉ đóng băng nguyên trạng; nguyên trạng sai thì nó đóng băng cái sai.
//
// # Hai bất biến
//
// Đây là điểm mà issue gốc gộp làm một, và tách ra mới thấy được hình dạng thật:
//
//	A. TỔNG TIỀN   số trên giấy cộng ra tổng của chính tờ giấy
//	B. THUẾ        Σ(Tax) đối chiếu được với order.TaxAmount
//
// Khi #2090 dựng bài này, A đúng còn B SAI lệch 20: `buildReceiptTaxSummary`
// gọi `priceGroups(rateSubtotals, 0, 0, 0, …)` — giảm giá và phí dịch vụ bị ép
// về 0. #2170 sửa B bằng cách cho tầng in ĐỌC SỔ `order_conditions`
// (Order.TaxLines); phép tính cũ (`computeReceiptTaxSummary`) chỉ còn là nhánh
// rơi về cho đơn chưa có sổ, và hình dạng của A đổi theo nguồn: khối từ sổ là
// HẬU-giảm-giá + đã gộp thuế phí dịch vụ, khối tính lại là TRƯỚC-giảm-giá.
// Cả hai nhánh đều được canh ở dưới.

// reconcileGap là khoảng lệch giữa Σ thuế in ra và `order.TaxAmount`.
//
// #2170 ĐÃ luồn sổ `order_conditions` vào tầng in (Order.TaxLines →
// buildReceiptTaxSummary), nên khoảng lệch là 0 và PHẢI Ở 0: dòng sổ được ghi
// bởi chính engine định giá với đủ giảm giá + phí dịch vụ, Σ amount của các
// dòng `tax` chính là `order.TaxAmount`. Một khoảng lệch khác 0 nghĩa là bảng
// thuế trên giấy vừa rời khỏi sổ — hoặc ai đó gỡ đường đọc sổ, hoặc fixture
// TaxLines thôi khớp engine (TestGoldenOrderIsEngineDerived bắt vế sau).
//
// Lịch sử: trước #2170 hằng số này là 20 (đo trên `goldenOrder()`: Σ Tax = 295,
// `order.TaxAmount` = 315) vì `computeReceiptTaxSummary` ép discount + phí dịch
// vụ về 0 (#2090/#2106).
const reconcileGap = 0

func TestPrintedTaxTable_MoneyTotalsReconcile(t *testing.T) {
	// Bất biến A — tờ giấy phải cộng ra tổng của chính nó.
	//
	// #2170 — các khối giờ lấy từ SỔ `order_conditions` (Order.TaxLines), và
	// hình dạng bất biến ĐÃ ĐỔI đúng như cảnh báo #2090 để lại: `Taxable` của
	// dòng sổ là nền HẬU-giảm-giá, và thuế phí dịch vụ đã được engine GỘP vào
	// nhóm cùng mức (gap #7, pricing.go). Nên tiền gộp của các khối phủ TOÀN BỘ
	// đối giá của đơn:
	//
	//	tổng đơn = Σ(taxable + tax)   — không trừ giảm giá (trừ nữa là trừ
	//	                                HAI LẦN), không cộng phí dịch vụ
	//	                                (nó đã nằm TRONG khối cùng mức).
	order, items := goldenOrder()
	sum := buildReceiptTaxSummary(order, items, 1)

	if len(sum.Blocks) == 0 {
		t.Fatal("không có khối thuế nào — bộ quét hỏng, không phải đơn sạch")
	}

	gross := 0
	for _, b := range sum.Blocks {
		gross += b.Taxable + b.Tax
	}
	if gross != order.TotalAmount {
		t.Errorf("số trên giấy KHÔNG cộng ra tổng đơn: Σ(taxable+tax) từ sổ = %d, nhưng total_amount = %d",
			gross, order.TotalAmount)
	}

	// Nhánh RƠI VỀ (#2170) — đơn chưa có sổ vẫn phải in, bằng phép tính cũ, và
	// phép tính cũ giữ nguyên hình dạng bất biến TRƯỚC-giảm-giá của #2090:
	//
	//	tổng đơn = Σ gộp − giảm giá + phí dịch vụ
	//
	// Canh cả nhánh này để "ưu tiên sổ" không lặng lẽ biến thành "chỉ còn sổ".
	bare := *order
	bare.TaxLines = nil
	fallback := buildReceiptTaxSummary(&bare, items, 1)
	if len(fallback.Blocks) == 0 {
		t.Fatal("nhánh rơi về không dựng được khối nào — đơn cũ sẽ in bảng thuế rỗng")
	}
	fGross := 0
	for _, b := range fallback.Blocks {
		fGross += b.Taxable + b.Tax
	}
	got := fGross - order.DiscountAmount + order.ServiceCharge
	if got != order.TotalAmount {
		t.Errorf("nhánh rơi về không cộng ra tổng đơn: Σ(taxable+tax)=%d − giảm %d + phí %d = %d, nhưng total_amount = %d",
			fGross, order.DiscountAmount, order.ServiceCharge, got, order.TotalAmount)
	}
}

func TestPrintedTaxTable_TaxGapIsPinnedAndOnlyShrinks(t *testing.T) {
	// Bất biến B — Σ thuế in ra phải ĐỐI CHIẾU ĐƯỢC với `order.TaxAmount`.
	//
	// #2090 ghim khoảng lệch ở 20 để #2106/#2170 làm nó về 0; #2170 đã làm:
	// bảng thuế đọc sổ `order_conditions`, và Σ amount các dòng `tax` của sổ
	// CHÍNH LÀ `order.TaxAmount`. Bánh cóc đã siết hết cỡ — mọi khoảng lệch
	// khác 0, ở BẤT KỲ hướng nào, đều là bảng thuế trên giấy rời khỏi sổ.
	order, items := goldenOrder()
	sum := buildReceiptTaxSummary(order, items, 1)

	printed := 0
	for _, b := range sum.Blocks {
		printed += b.Tax
	}

	// `TaxAmount` là float64 (option-B, độ chính xác dưới đơn vị); bảng in là
	// số nguyên đơn vị. So ở int là đúng đơn vị của tờ giấy.
	gap := int(order.TaxAmount+0.5) - printed

	if gap < 0 {
		t.Fatalf("bảng in ra THỪA thuế so với đơn (Σ in %d > order %d) — bảng thuế vừa rời khỏi sổ, đo lại trước khi nới hằng số",
			printed, int(order.TaxAmount+0.5))
	}
	if gap > reconcileGap {
		t.Errorf("khoảng lệch thuế TĂNG: %d (trần %d). Σ thuế in ra = %d, order.TaxAmount = %d.\n"+
			"Bảng thuế trên giấy vừa rời khỏi sổ — đường đọc sổ (Order.TaxLines → "+
			"buildReceiptTaxSummary, #2170) có còn được đi qua không?",
			gap, reconcileGap, printed, int(order.TaxAmount+0.5))
	}
}

func TestPrintedTaxTable_GapIsCausedByDiscountAndServiceCharge(t *testing.T) {
	// Ghim NGUYÊN NHÂN của khoảng lệch lịch sử (#2090, trước khi #2170 đưa sổ
	// vào tầng in): cùng bộ món ấy, nếu đưa giảm giá thật vào engine thì Σ thuế
	// ra khác — tức khoảng lệch của phép tính cũ đúng là do tham số bị ép về 0,
	// không phải do làm tròn. Mốc này vẫn đứng vì nhánh rơi về
	// (`computeReceiptTaxSummary`) vẫn mang đúng tính chất đó — nó là lý do
	// nhánh ấy KHÔNG được dùng cho đơn đã có sổ.
	order, items := goldenOrder()

	rateSubtotals := map[string]float64{}
	for _, it := range items {
		if it.TaxRate == nil || it.Status == ItemStatusVoided {
			continue
		}
		rateSubtotals[rateKey(*it.TaxRate)] += float64(itemTaxableSubtotal(it))
	}

	tStep := taxStepFrom(order.TaxRoundingDecimals, 1)

	zeroed := priceGroups(rateSubtotals, 0, 0, 0, order.IsTaxIncluded, 1, tStep, "round", nil)
	real := priceGroups(rateSubtotals, float64(order.DiscountAmount), 0, 0, order.IsTaxIncluded, 1, tStep, "round", nil)

	sumTax := func(r PricingResult) float64 {
		t := 0.0
		for _, g := range r.Groups {
			t += g.Tax
		}

		return t
	}

	if sumTax(zeroed) == sumTax(real) {
		t.Errorf("đưa giảm giá %d vào engine KHÔNG đổi Σ thuế (%v) — vậy khoảng lệch ở bài trên đến từ chỗ khác, đo lại",
			order.DiscountAmount, sumTax(zeroed))
	}
}

// #2169 — `goldenOrder()` phải là số ENGINE SINH RA, không phải số gõ tay.
//
// # Vì sao cần một bài riêng cho một fixture
//
// Fixture này là đầu vào của **9 khoá `receipt|*` + 5 loại phiếu khác** đang
// được hash trong `print_input_golden.json`. Một con số gõ tay ở đây không sai
// ở chỗ nó xấu — nó làm **mọi phép đo dựa trên fixture mất nghĩa**: khoảng lệch
// đo được lẫn giữa "tầng in tính sai" và "fixture vốn đã không cộng đúng", và
// không ai tách được hai thứ đó ra.
//
// Đã trả giá thật ở #2106: bản cũ ghi `ServiceCharge: 330` / `TotalAmount: 3530`
// — phí dịch vụ tính trên **3300 GỘP** thay vì trên `3300 − 100` như engine làm.
// Không cấu hình phí nào sinh ra được bộ số ấy. Người làm #2106 đo `reconcileGap`
// và không thể biết 20 đồng lệch có bao nhiêu phần là do fixture.
//
// # Vì sao ghim ĐÚNG engine chứ không ghim hằng số
//
// Viết `if order.TotalAmount != 3520` chỉ chép lại con số gõ tay sang một chỗ
// khác. Bài này chạy CHÍNH `priceGroups` — cùng hàm mà
// `priceRateSubtotalsWithRounding` gọi cho đơn thật — nên nó đỏ khi ai đó sửa
// fixture **hoặc** khi engine đổi cách tính. Cả hai đều là thứ cần biết.
const (
	goldenServiceChargeRate    = 10.0
	goldenServiceChargeTaxRate = 10.0
)

func TestGoldenOrderIsEngineDerived(t *testing.T) {
	order, items := goldenOrder()

	rateSubtotals := map[string]float64{}
	for _, it := range items {
		if it.VoidedAt != nil || it.Status == ItemStatusVoided || it.TaxRate == nil {
			continue
		}
		rateSubtotals[rateKey(*it.TaxRate)] += float64(itemTaxableSubtotal(it))
	}
	if len(rateSubtotals) == 0 {
		// Fixture rỗng và bộ đọc hỏng trông giống hệt nhau ở đầu ra: mọi so sánh
		// dưới đây chạy trên 0 và bài test XANH.
		t.Fatal("không gom được nhóm mức nào từ items — bộ đọc hỏng, không phải đơn sạch")
	}

	res := priceGroups(
		rateSubtotals,
		float64(order.DiscountAmount),
		goldenServiceChargeRate,
		goldenServiceChargeTaxRate,
		order.IsTaxIncluded,
		1, 1, "round",
		nil,
	)

	for _, c := range []struct {
		field string
		got   float64
		want  float64
	}{
		{"ServiceCharge", float64(order.ServiceCharge), res.ServiceCharge},
		{"TaxAmount", order.TaxAmount, res.TaxAmount},
		{"TotalAmount", float64(order.TotalAmount), res.TotalAmount},
	} {
		// So bằng epsilon, KHÔNG bằng `!=`: `TaxAmount` cố ý mang độ chính xác
		// dưới đơn vị (option-B hiển thị), nên hai đường tính ra "cùng 315" vẫn
		// có thể lệch ở bit cuối của float64. Bản đầu của bài này dùng `!=` và
		// đỏ với thông điệp "315 nhưng engine sinh ra 315" — đúng loại thông
		// điệp làm người đọc nghi ngờ phép đo thay vì nghi ngờ phép so.
		if math.Abs(c.got-c.want) > 1e-9 {
			t.Errorf("goldenOrder().%s = %.0f nhưng engine sinh ra %.0f.\n"+
				"Fixture phải là số engine tính được cho (items, discount=%d, phí %.0f%%, thuế phí %.0f%%, "+
				"総額表示=%v, round bước 1) — đừng gõ tay.",
				c.field, c.got, c.want,
				order.DiscountAmount, goldenServiceChargeRate, goldenServiceChargeTaxRate, order.IsTaxIncluded)
		}
	}

	// #2071 — các dòng Discounts của fixture phải là ĐÚNG phép chia mà
	// writeOrderConditionsTx ghi vào sổ: pro-rata theo tiền món GỘP của từng
	// mức (half-up bước 1), phần dư vào mức CUỐI, Σ = −DiscountAmount tuyệt
	// đối. Gõ tay một bộ số khác là golden ghim một tờ giấy mà không đơn thật
	// nào in ra.
	{
		rates := make([]float64, 0, len(rateSubtotals))
		for k := range rateSubtotals {
			r, err := strconv.ParseFloat(k, 64)
			if err != nil {
				t.Fatalf("rateKey %q không parse được: %v", k, err)
			}
			rates = append(rates, r)
		}
		sort.Float64s(rates)

		discount := float64(order.DiscountAmount)
		subtotal := float64(order.Subtotal)
		want := map[float64]int{}
		allocated := 0.0
		for i, r := range rates {
			var share float64
			if i == len(rates)-1 {
				share = discount - allocated
			} else {
				share = roundHalfUpToStep(discount*rateSubtotals[rateKey(r)]/subtotal, 1)
			}
			allocated += share
			if share > 0 {
				want[r] = -int(share)
			}
		}

		got := map[float64]int{}
		sum := 0
		for _, d := range order.Discounts {
			if d.Rate == nil {
				t.Fatalf("fixture có dòng discount không rate — basket này có nhóm mức, dòng nil-rate chỉ dành cho đơn không dòng chịu thuế")
			}
			got[*d.Rate] = d.Amount
			sum += d.Amount
		}
		if !reflect.DeepEqual(got, want) {
			t.Errorf("goldenOrder().Discounts = %v nhưng phép chia của sổ là %v", got, want)
		}
		if sum != -order.DiscountAmount {
			t.Errorf("Σ Discounts = %d, phải bằng −DiscountAmount = %d", sum, -order.DiscountAmount)
		}
	}

	// #2170 — các dòng TaxLines của fixture phải là ĐÚNG các dòng `tax` mà
	// writeOrderConditionsTx ghi vào sổ: res.Groups nguyên văn (hậu-giảm-giá,
	// thuế phí dịch vụ đã gộp vào nhóm cùng mức — gap #7), làm tròn
	// half-away-from-zero về đơn vị như `(int) round` phía Cloud, nhóm rỗng
	// (tax=0 và taxable=0) bị bỏ như người ghi bỏ. Gõ tay một bộ số khác là
	// golden ghim một bảng thuế mà không đơn thật nào in ra.
	{
		want := make([]OrderTaxLine, 0, len(res.Groups))
		for _, g := range res.Groups {
			if g.Tax == 0 && g.Taxable == 0 {
				continue
			}
			want = append(want, OrderTaxLine{
				Rate:    g.Rate,
				Taxable: int(math.Round(g.Taxable)),
				Tax:     int(math.Round(g.Tax)),
			})
		}
		if !reflect.DeepEqual(order.TaxLines, want) {
			t.Errorf("goldenOrder().TaxLines = %v nhưng sổ của engine là %v", order.TaxLines, want)
		}
	}

	// Đơn đã thanh toán đủ: phiếu in ra dòng お預かり/お釣り từ bộ ba này, nên
	// lệch ở đây là lệch trên giấy khách cầm.
	if order.PaidAmount != order.TotalAmount {
		t.Errorf("PaidAmount %d ≠ TotalAmount %d — fixture là một SALE ĐÃ ĐÓNG",
			order.PaidAmount, order.TotalAmount)
	}
	slip := goldenSlip()
	if slip.AmountPaid != order.TotalAmount {
		t.Errorf("goldenSlip().AmountPaid %d ≠ TotalAmount %d", slip.AmountPaid, order.TotalAmount)
	}
	if slip.Tendered-slip.AmountPaid != slip.Change {
		t.Errorf("tiền thối không khớp: %d − %d = %d, phiếu ghi %d",
			slip.Tendered, slip.AmountPaid, slip.Tendered-slip.AmountPaid, slip.Change)
	}
}
