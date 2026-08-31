package handler

import (
	"testing"
)

// #2071 — loadOrderDiscountLines: các dòng `discount` của sổ đi tới tầng in
// NGUYÊN VĂN (dấu âm giữ nguyên, mỗi mức một dòng, thứ tự cố định), và KHÔNG có
// fallback về cột `orders.discount_amount` — cột là số YÊU CẦU, sổ là số ĐÃ ÁP
// DỤNG (#2031).

func seedDiscountRow(t *testing.T, s *Server, id, orderID string, rate any, amount float64) {
	t.Helper()
	if _, err := s.db.Exec(`INSERT INTO order_conditions
		(id, conditionable_type, conditionable_id, type, label, rate, amount, currency_code)
		VALUES (?, 'order', ?, 'discount', 'Discount', ?, ?, 'JPY')`,
		id, orderID, rate, amount); err != nil {
		t.Fatalf("seed discount row: %v", err)
	}
}

func TestLoadOrderDiscountLines_VerbatimPerRateSorted(t *testing.T) {
	s := newLANPrintTestServer(t)

	// Ghi lộn thứ tự để chứng minh ORDER BY của loader, không phải thứ tự ghi.
	seedDiscountRow(t, s, "d-10", "ord-disc-1", 10.0, -91)
	seedDiscountRow(t, s, "d-nil", "ord-disc-1", nil, -3)
	seedDiscountRow(t, s, "d-8", "ord-disc-1", 8.0, -6.4) // half-away-from-zero → −6

	lines := s.loadOrderDiscountLines("ord-disc-1")
	if len(lines) != 3 {
		t.Fatalf("muốn 3 dòng, có %d: %+v", len(lines), lines)
	}

	// rate ASC trước, dòng nil-rate cuối.
	if lines[0].Rate == nil || *lines[0].Rate != 8.0 || lines[0].Amount != -6 {
		t.Errorf("dòng 0 phải là (8%%, −6), có %+v", lines[0])
	}
	if lines[1].Rate == nil || *lines[1].Rate != 10.0 || lines[1].Amount != -91 {
		t.Errorf("dòng 1 phải là (10%%, −91), có %+v", lines[1])
	}
	if lines[2].Rate != nil || lines[2].Amount != -3 {
		t.Errorf("dòng 2 phải là (nil, −3), có %+v", lines[2])
	}
}

// Sổ trống ⇒ KHÔNG dòng nào — kể cả khi cột discount_amount khác 0. Fallback
// im lặng về cột chính là lớp lỗi mà #2067/#2071 đóng cửa.
func TestLoadOrderDiscountLines_NoLedgerMeansNoRows_NoColumnFallback(t *testing.T) {
	s := newLANPrintTestServer(t)

	if _, err := s.db.Exec(`UPDATE orders SET discount_amount = 500 WHERE id = ?`, "ord-x"); err != nil {
		// Đơn không tồn tại cũng được — phép đo là loader không đọc bảng orders.
		t.Logf("update orders (không bắt buộc): %v", err)
	}

	if lines := s.loadOrderDiscountLines("ord-x"); len(lines) != 0 {
		t.Fatalf("sổ trống mà loader trả %d dòng: %+v", len(lines), lines)
	}
}

// Chỉ nhặt dòng type='discount' ở MỨC ĐƠN — dòng tax/service_charge và dòng
// mức MÓN không được lẫn vào khối giảm giá.
func TestLoadOrderDiscountLines_IgnoresOtherTypesAndItemRows(t *testing.T) {
	s := newLANPrintTestServer(t)

	seedDiscountRow(t, s, "d-ok", "ord-disc-2", 10.0, -50)
	for _, row := range []struct{ id, ctype, ckind string }{
		{"d-tax", "order", "tax"},
		{"d-svc", "order", "service_charge"},
		{"d-item", "order_item", "discount"},
	} {
		if _, err := s.db.Exec(`INSERT INTO order_conditions
			(id, conditionable_type, conditionable_id, type, label, amount, currency_code)
			VALUES (?, ?, ?, ?, '', -7, 'JPY')`,
			row.id, row.ctype, "ord-disc-2", row.ckind); err != nil {
			t.Fatalf("seed %s: %v", row.id, err)
		}
	}

	lines := s.loadOrderDiscountLines("ord-disc-2")
	if len(lines) != 1 || lines[0].Amount != -50 {
		t.Fatalf("muốn đúng 1 dòng (10%%, −50), có %+v", lines)
	}
}
