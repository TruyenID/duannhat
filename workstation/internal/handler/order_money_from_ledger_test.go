package handler

import (
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// #2075 — máy trạm phải ĐỌC SỔ để tính tiền, không chỉ ghi ra rồi tự tính lại
// từ ba cột.
//
// Ba nhánh, và cả ba đều phải phân biệt được với nhau ở đầu ra. Đó là điểm
// chính: một hàm derive trả đúng số nhưng không nói được số ấy từ đâu thì fallback
// của nó là chế độ lỗi im lặng — đúng thứ bài này chữa.

// mọi ca dùng chung một đơn: giỏ 1.000, sổ nói thuế 100 / giảm 200 / phí 50.
func newLedgerOrder() *service.Order {
	return &service.Order{
		ID:             "lo1",
		Status:         "open",
		Subtotal:       1000,
		TaxAmount:      999, // cột CỐ Ý lệch khỏi sổ — xem TestDeriveOrderMoney_LedgerWins
		DiscountAmount: 888,
		ServiceCharge:  777,
	}
}

func TestDeriveOrderMoney_LedgerWins(t *testing.T) {
	// Cột và sổ được đặt LỆCH NHAU có chủ đích. Nếu hàm vẫn đọc cột thì test
	// đỏ ngay — chứ không phải "cả hai bằng nhau nên xanh dù đọc nhầm nguồn",
	// vốn là cách một bài parity tự vô hiệu hoá mình.
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_number, order_type, status, opened_at, created_at, updated_at)
		VALUES ('lo1', 'WS-1', 1, 'spot', 'open', datetime('now'), datetime('now'), datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, created_at)
		VALUES ('c-tax-10', 'order', 'lo1', 'tax', 'tax_type', '10%', 10, 60, 'JPY', datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, created_at)
		VALUES ('c-tax-8', 'order', 'lo1', 'tax', 'tax_type', '8%', 8, 40, 'JPY', datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, created_at)
		VALUES ('c-disc', 'order', 'lo1', 'discount', 'coupon', 'Coupon', NULL, -200, 'JPY', datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, created_at)
		VALUES ('c-sc', 'order', 'lo1', 'service_charge', 'service_charge', 'Service', 5, 50, 'JPY', datetime('now'))`)

	got := srv.deriveOrderMoney(newLedgerOrder())

	if !got.FromLedger {
		t.Fatal("FromLedger = false — hàm vẫn đọc cột dù sổ có dòng")
	}
	// Nhiều mức phải CỘNG LẠI, không lấy dòng đầu.
	if got.Tax != 100 {
		t.Errorf("Tax = %v, muốn 100 (60 + 40 hai mức)", got.Tax)
	}
	// Sổ ghi giảm giá bằng số ÂM; chỗ gọi trừ đi một số DƯƠNG.
	if got.Discount != 200 {
		t.Errorf("Discount = %d, muốn 200 (đảo dấu từ -200)", got.Discount)
	}
	if got.ServiceCharge != 50 {
		t.Errorf("ServiceCharge = %d, muốn 50", got.ServiceCharge)
	}
}

func TestDeriveOrderMoney_FallsBackToColumnsWhenLedgerEmpty(t *testing.T) {
	// Đơn ghi trước #2032, hoặc vừa tạo chưa kịp ghi sổ. Fallback là thứ cho
	// phép triển khai lệch pha giữa Cloud và các máy ngoài thực địa.
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_number, order_type, status, opened_at, created_at, updated_at)
		VALUES ('lo1', 'WS-1', 1, 'spot', 'open', datetime('now'), datetime('now'), datetime('now'))`)

	got := srv.deriveOrderMoney(newLedgerOrder())

	if got.FromLedger {
		t.Error("FromLedger = true dù sổ trống")
	}
	if got.Tax != 999 || got.Discount != 888 || got.ServiceCharge != 777 {
		t.Errorf("fallback không trả về ba cột: %+v", got)
	}
}

func TestDeriveOrderMoney_ZeroInLedgerIsAnAnswer(t *testing.T) {
	// Đơn giảm giá phủ hết giỏ: sổ ghi thuế 0 và đó là câu trả lời ĐÚNG.
	//
	// Đọc "tổng bằng 0" thành "sổ trống" rồi rơi về cột là đúng lỗi Cloud vừa
	// phải sửa ở #2074 — ở đó nó in 対価の額 1.000 cạnh thuế 0 trên hoá đơn.
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_number, order_type, status, opened_at, created_at, updated_at)
		VALUES ('lo1', 'WS-1', 1, 'spot', 'open', datetime('now'), datetime('now'), datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, created_at)
		VALUES ('c-tax-0', 'order', 'lo1', 'tax', 'tax_type', '10%', 10, 0, 'JPY', datetime('now'))`)

	got := srv.deriveOrderMoney(newLedgerOrder())

	if !got.FromLedger {
		t.Fatal("sổ ghi 0 bị coi là sổ trống — rơi về cột")
	}
	if got.Tax != 0 {
		t.Errorf("Tax = %v, muốn 0 (sổ nói 0)", got.Tax)
	}
}

func TestDeriveOrderMoney_IgnoresRefundAndItemLevelRows(t *testing.T) {
	// `refund` là dòng cấp MÓN, append-only, và KHÔNG phải một khoản dẫn xuất.
	// Cộng nhầm nó vào là trừ tiền hai lần.
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_number, order_type, status, opened_at, created_at, updated_at)
		VALUES ('lo1', 'WS-1', 1, 'spot', 'open', datetime('now'), datetime('now'), datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, created_at, updated_at)
		VALUES ('it-1', 'lo1', 'X', 1, 1000, 1000, datetime('now'), datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, created_at)
		VALUES ('c-tax', 'order', 'lo1', 'tax', 'tax_type', '10%', 10, 100, 'JPY', datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, created_at)
		VALUES ('c-refund', 'order_item', 'it-1', 'refund', 'manual', 'Refund', 10, -1100, 'JPY', datetime('now'))`)

	got := srv.deriveOrderMoney(newLedgerOrder())

	if got.Tax != 100 {
		t.Errorf("Tax = %v, muốn 100 — dòng refund cấp món đã lọt vào phép cộng", got.Tax)
	}
	if got.Discount != 0 {
		t.Errorf("Discount = %d, muốn 0 — sổ không có dòng discount nào", got.Discount)
	}
}

func TestSplitByItemsPreview_UsesLedgerNotColumns(t *testing.T) {
	// Đường đi thật của #2075: preview chia bill SUY NGƯỢC tỉ lệ từ số tiền.
	// Cột nói thuế 0 (mô phỏng payload sau khi Cloud bỏ cột), sổ nói 100.
	// Nếu preview vẫn đọc cột thì mỗi hoá đơn con ra 0 thuế — ngay tại quầy,
	// không lỗi nào.
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_number, order_type, status, opened_at, created_at, updated_at)
		VALUES ('lo1', 'WS-1', 1, 'spot', 'open', datetime('now'), datetime('now'), datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, created_at)
		VALUES ('c-tax', 'order', 'lo1', 'tax', 'tax_type', '10%', 10, 100, 'JPY', datetime('now'))`)

	order := &service.Order{
		ID:       "lo1",
		Status:   "open",
		Subtotal: 1000,
		// Tổng vẫn ĐÚNG (1.000 + 100 thuế) — chỉ CỘT tax_amount "biến mất",
		// đúng hình dạng payload sau khi Cloud bỏ cột. Thiếu tổng đúng thì bước
		// đối soát dồn phần dư vào bill cuối và test đo nhầm thứ khác.
		TotalAmount: 1100,
		TaxAmount:   0,
		Items: []service.Item{
			{ID: "it-1", MenuItemName: "A", Quantity: 2, UnitPrice: 500, Subtotal: 1000},
		},
	}
	allocs := []splitByItemsAllocationInput{
		{ItemID: "it-1", BillIndex: 0, Units: 1},
		{ItemID: "it-1", BillIndex: 1, Units: 1},
	}

	bills, err := computeByItemsPreviewBills(order, allocs, srv.formatAmount, srv.deriveOrderMoney(order), 0)
	if err != nil {
		t.Fatalf("computeByItemsPreviewBills: %v", err)
	}
	if len(bills) != 2 {
		t.Fatalf("muốn 2 hoá đơn con, có %d", len(bills))
	}

	// 1.000 giỏ, thuế 100 ⇒ 10%; mỗi bill 500 ⇒ thuế 50.
	for i, b := range bills {
		if b["tax"] != srv.formatAmount(50) {
			t.Errorf("bill %d: tax = %v, muốn %v — preview vẫn suy tỉ lệ từ CỘT",
				i, b["tax"], srv.formatAmount(50))
		}
	}
}

func TestSplitByItemsPreview_DiscountAlsoComesFromLedger(t *testing.T) {
	// Vòng 2 của #2075. Bản đầu chuyển `taxRate`/`serviceRate` sang đọc sổ nhưng
	// BỎ SÓT phép chia giảm giá ở ngay trong cùng hàm — một hàm, hai nguồn.
	//
	// Hệ quả không phải "lệch vài đồng": mẫu số của `taxRate` đã trừ giảm giá
	// của SỔ, còn số bị chia thì chưa, nên phần dư dồn hết vào bill cuối và ra
	// THUẾ ÂM trên phiếu khách nhìn thấy.
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_number, order_type, status, opened_at, created_at, updated_at)
		VALUES ('lo1', 'WS-1', 1, 'spot', 'open', datetime('now'), datetime('now'), datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, created_at)
		VALUES ('c-tax', 'order', 'lo1', 'tax', 'tax_type', '10%', 10, 80, 'JPY', datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, created_at)
		VALUES ('c-disc', 'order', 'lo1', 'discount', 'coupon', 'Coupon', NULL, -200, 'JPY', datetime('now'))`)

	order := &service.Order{
		ID:       "lo1",
		Status:   "open",
		Subtotal: 1000,
		// Cột đã "biến mất" (hình dạng sau #2041 bước 3); sổ mới là nguồn.
		TotalAmount:    880,
		TaxAmount:      0,
		DiscountAmount: 0,
		Items: []service.Item{
			{ID: "it-1", MenuItemName: "A", Quantity: 2, UnitPrice: 500, Subtotal: 1000},
		},
	}
	allocs := []splitByItemsAllocationInput{
		{ItemID: "it-1", BillIndex: 0, Units: 1},
		{ItemID: "it-1", BillIndex: 1, Units: 1},
	}

	bills, err := computeByItemsPreviewBills(order, allocs, srv.formatAmount, srv.deriveOrderMoney(order), 0)
	if err != nil {
		t.Fatalf("computeByItemsPreviewBills: %v", err)
	}

	// Giỏ 1.000, sổ giảm 200 ⇒ mỗi bill gánh 100; nền 400 × 10% = 40 thuế.
	for i, b := range bills {
		if b["discount"] != srv.formatAmount(100) {
			t.Errorf("bill %d: discount = %v, muốn %v — phép chia giảm giá vẫn đọc CỘT",
				i, b["discount"], srv.formatAmount(100))
		}
		if b["tax"] != srv.formatAmount(40) {
			t.Errorf("bill %d: tax = %v, muốn %v", i, b["tax"], srv.formatAmount(40))
		}
	}

	// Bất biến riêng, vì đây là triệu chứng mà khách NHÌN THẤY: không hoá đơn
	// con nào được mang thuế âm, dù phần dư rơi vào đâu.
	for i, b := range bills {
		if strings.HasPrefix(b["tax"].(string), "-") {
			t.Errorf("bill %d: THUẾ ÂM trên phiếu (%v)", i, b["tax"])
		}
	}
}
