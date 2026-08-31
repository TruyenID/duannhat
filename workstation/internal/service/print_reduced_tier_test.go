package service

import "testing"

// #2086 — 非課税 0% KHÔNG phải 軽減税率, nên nó không được mang dấu ※.
//
// Dấu ※ trỏ tới chú thích 「※は軽減税率対象」 ở chân phiếu, tức nó là một khẳng
// định CỤ THỂ về chế độ thuế của dòng đó. 非課税 / 免税 và 軽減税率 là hai chế độ
// khác hẳn — Peppol còn tách thành hai loại riêng (Z/E so với S). Dán ※ lên một
// món miễn thuế là tuyên bố sai trên chứng từ thuế.
//
// Cloud sửa ở `d1634ec9a`. Bản Go mang y hệt hai nửa của cùng lỗi — chọn mức
// THẤP NHẤT làm "mức giảm", và `isReducedLine` so `< maxRate` mà không loại 0 —
// nên cùng một đơn in ở hai đường cho ra hai tờ giấy khác nhau về pháp lý.
//
// Lỗi chỉ tới được giấy sau khi nhóm 0% bắt đầu vào bảng thuế; trước đó nó bị
// che vì bảng không bao giờ có dòng 0%.

func rateP(v float64) *float64 { return &v }

func TestReducedTier_ZeroRateIsNotReduced(t *testing.T) {
	order := &Order{IsTaxIncluded: false, TaxRoundingMode: "round"}
	items := []Item{
		{MenuItemName: "Sách (非課税)", Quantity: 1, UnitPrice: 500, TaxRate: rateP(0)},
		{MenuItemName: "Bia", Quantity: 1, UnitPrice: 1000, TaxRate: rateP(10)},
	}

	s := buildReceiptTaxSummary(order, items, 1)

	// Khối 0% VẪN phải có mặt trong bảng thuế — 非課税 là một mức thật, và hoá
	// đơn phải nêu được phần ấy. Chỉ cái NHÃN là sai.
	if len(s.Blocks) != 2 {
		t.Fatalf("muốn 2 khối (0%% và 10%%), có %d", len(s.Blocks))
	}
	if s.HasReduced {
		t.Errorf("0%% bị coi là mức giảm — chân phiếu sẽ in 「※は軽減税率対象」 cho một món 非課税")
	}
	if isReducedLine(items[0], s.blockMaxRate()) {
		t.Errorf("dòng 非課税 mang dấu ※")
	}
}

func TestReducedTier_RealReducedStillMarked(t *testing.T) {
	// Mặt kia: bản sửa không được cắt oan 軽減 thật. Ca này là lý do phép lọc
	// phải là "thấp nhất LỚN HƠN 0", không phải "bỏ hẳn khái niệm mức giảm".
	order := &Order{IsTaxIncluded: false, TaxRoundingMode: "round"}
	items := []Item{
		{MenuItemName: "Bentō", Quantity: 1, UnitPrice: 1000, TaxRate: rateP(8)},
		{MenuItemName: "Bia", Quantity: 1, UnitPrice: 1000, TaxRate: rateP(10)},
	}

	s := buildReceiptTaxSummary(order, items, 1)

	if !s.HasReduced || s.ReducedRate != 8 {
		t.Errorf("8%% phải là mức giảm khi đứng cạnh 10%%: HasReduced=%v ReducedRate=%v",
			s.HasReduced, s.ReducedRate)
	}
	if !isReducedLine(items[0], s.blockMaxRate()) {
		t.Errorf("dòng 軽減 8%% mất dấu ※")
	}
}

func TestReducedTier_ZeroPlusReducedPlusStandard(t *testing.T) {
	// Ca phân biệt thật sự: có cả 非課税, 軽減 thật, và 標準 trên cùng một phiếu.
	// Bản cũ chọn 0% làm "mức giảm" ⇒ vừa dán nhãn sai lên món miễn thuế, vừa
	// khiến `ReducedRate` không còn trỏ tới mức 軽減 thật.
	order := &Order{IsTaxIncluded: false, TaxRoundingMode: "round"}
	items := []Item{
		{MenuItemName: "Sách", Quantity: 1, UnitPrice: 500, TaxRate: rateP(0)},
		{MenuItemName: "Bentō", Quantity: 1, UnitPrice: 1000, TaxRate: rateP(8)},
		{MenuItemName: "Bia", Quantity: 1, UnitPrice: 1000, TaxRate: rateP(10)},
	}

	s := buildReceiptTaxSummary(order, items, 1)

	if !s.HasReduced || s.ReducedRate != 8 {
		t.Errorf("mức giảm phải là 8%%, có %v", s.ReducedRate)
	}
	if isReducedLine(items[0], s.blockMaxRate()) {
		t.Errorf("dòng 非課税 mang dấu ※")
	}
	if !isReducedLine(items[1], s.blockMaxRate()) {
		t.Errorf("dòng 軽減 8%% mất dấu ※")
	}
	if isReducedLine(items[2], s.blockMaxRate()) {
		t.Errorf("dòng 標準 10%% mang dấu ※")
	}
}

func TestReducedTier_SingleRateMarksNothing(t *testing.T) {
	// Một mức thì không có gì để phân biệt — インボイス 適格簡易請求書 §1.3.2 yêu
	// cầu ※ để tách HAI tầng.
	order := &Order{IsTaxIncluded: false, TaxRoundingMode: "round"}
	items := []Item{{MenuItemName: "Bia", Quantity: 1, UnitPrice: 1000, TaxRate: rateP(10)}}

	s := buildReceiptTaxSummary(order, items, 1)

	if s.HasReduced {
		t.Error("đơn một mức mà vẫn khai có mức giảm")
	}
}

func TestReducedTier_AllZeroRateMarksNothing(t *testing.T) {
	// Đơn toàn 非課税: không có mức nào cao hơn để phân biệt, và cũng không có
	// 軽減 nào. Bản cũ cho `HasReduced = false` ở đây (vì chỉ một mức) — đúng
	// một cách TÌNH CỜ, nên vẫn ghim lại.
	order := &Order{IsTaxIncluded: false, TaxRoundingMode: "round"}
	items := []Item{
		{MenuItemName: "Sách A", Quantity: 1, UnitPrice: 500, TaxRate: rateP(0)},
		{MenuItemName: "Sách B", Quantity: 1, UnitPrice: 700, TaxRate: rateP(0)},
	}

	s := buildReceiptTaxSummary(order, items, 1)

	if s.HasReduced {
		t.Error("đơn toàn 非課税 mà vẫn khai có mức giảm")
	}
	if isReducedLine(items[0], s.blockMaxRate()) {
		t.Error("dòng 非課税 mang dấu ※")
	}
}
