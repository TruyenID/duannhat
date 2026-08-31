package handler

import (
	"encoding/json"
	"strings"
	"testing"
	"time"
)

// #2140 — 精算 và ảnh chụp đối soát phải đọc SỔ `order_conditions`, không đọc cột.
//
// Con số hai đường này tính ra bị ĐÓNG BĂNG vào `till_sessions.settled_*` /
// `settlement_snapshot`. Sai ở đây không lộ ra ở quầy — nó lộ ở báo cáo cuối
// tháng, và lúc đó không sửa lại được. Đó là lý do #2123 xếp tầng này SAU tầng
// hoá đơn in: hoá đơn hỏng thì thấy ngay trên giấy, cái này thì đóng băng.
//
// Trước #2140 không có bài test nào phủ trường hợp **sổ lệch cột** — tức đúng
// cái đang sửa. Bộ test cũ chỉ seed đơn có sổ và cột KHỚP nhau, nên nó xanh dù
// đọc từ đâu; nó không phân biệt được hai cách cài đặt.
//
// Ba bài dưới đây tách bạch ba quyết định mà quy ước này gói lại:
//
//   - sổ THẮNG cột khi đơn có dòng sổ;
//   - cột vẫn dùng khi đơn CHƯA có dòng sổ nào (dữ liệu cũ trước plan-045);
//   - tổng sổ bằng 0 KHÔNG có nghĩa là "chưa có sổ" — đúng lỗi #2074 ở Cloud.

// seedLedgerFor ghi ba dòng sổ mức ĐƠN cho một đơn đã seed sẵn.
//
// `discount` cố ý ghi giá trị ÂM: đó là quy ước thật của sổ, và cột
// `orders.discount_amount` thì DƯƠNG. Bài test nào seed dấu dương ở đây là đang
// đo một thế giới không tồn tại.
func seedLedgerFor(t *testing.T, s *Server, orderID string, tax, discount, service float64) {
	t.Helper()

	rows := []struct {
		id, kind string
		amount   float64
	}{
		{orderID + "-lc-tax", "tax", tax},
		{orderID + "-lc-dsc", "discount", -discount},
		{orderID + "-lc-svc", "service_charge", service},
	}

	for _, r := range rows {
		if _, err := s.db.Exec(`INSERT INTO order_conditions
			(id, conditionable_type, conditionable_id, type, label, amount, currency_code)
			VALUES (?, 'order', ?, ?, '', ?, 'JPY')`,
			r.id, orderID, r.kind, r.amount); err != nil {
			t.Fatalf("seed order_conditions (%s): %v", r.kind, err)
		}
	}
}

func TestBuildShiftReport_LedgerBeatsColumns(t *testing.T) {
	s := newLANPrintTestServer(t)
	sessionID := seedShiftForReport(t, s)

	// Cột nói thuế 182 / giảm giá 0 cho o1 và 133 / 225 cho o2 (xem
	// seedShiftForReport). Sổ nói khác — đó là trạng thái xảy ra khi Cloud tính
	// lại và đồng bộ xuống, còn cột thì giữ giá trị máy trạm ước lượng lúc bán.
	seedLedgerFor(t, s, "o1", 200, 0, 0)
	seedLedgerFor(t, s, "o2", 150, 300, 0)

	info, err := s.buildShiftReport(sessionID)
	if err != nil {
		t.Fatalf("buildShiftReport: %v", err)
	}

	if info.TaxTotal != 350 {
		t.Errorf("TaxTotal = %d, want 350 (200+150 từ SỔ, không phải 315 từ cột)", info.TaxTotal)
	}
	if info.DiscountTotalAmount != 300 {
		t.Errorf("DiscountTotalAmount = %d, want 300 (từ SỔ, dấu đã đảo về dương; cột nói 225)",
			info.DiscountTotalAmount)
	}
	// Đếm đơn có giảm giá cũng phải theo sổ: o1 sổ nói 0, o2 sổ nói 300 ⇒ đúng 1.
	if info.DiscountTotalCount != 1 {
		t.Errorf("DiscountTotalCount = %d, want 1", info.DiscountTotalCount)
	}
	// NetSales phái sinh từ TaxTotal — nếu nó không đi theo thì hai con số trên
	// cùng một tờ giấy sẽ mâu thuẫn nhau.
	if info.NetSales != info.GrossSales-info.TaxTotal {
		t.Errorf("NetSales = %d, không khớp GrossSales(%d) - TaxTotal(%d)",
			info.NetSales, info.GrossSales, info.TaxTotal)
	}
}

func TestBuildShiftReport_FallsBackToColumnsWhenNoLedgerRows(t *testing.T) {
	s := newLANPrintTestServer(t)
	sessionID := seedShiftForReport(t, s)

	// Không seed dòng sổ nào: đơn ghi trước plan-045. Cột vẫn là nguồn duy nhất.
	info, err := s.buildShiftReport(sessionID)
	if err != nil {
		t.Fatalf("buildShiftReport: %v", err)
	}

	if info.TaxTotal != 315 {
		t.Errorf("TaxTotal = %d, want 315 (từ cột — đơn chưa có sổ)", info.TaxTotal)
	}
	if info.DiscountTotalAmount != 225 {
		t.Errorf("DiscountTotalAmount = %d, want 225 (từ cột)", info.DiscountTotalAmount)
	}
}

func TestBuildShiftReport_ZeroLedgerIsNotMissingLedger(t *testing.T) {
	s := newLANPrintTestServer(t)
	sessionID := seedShiftForReport(t, s)

	// Đơn toàn hàng 非課税: sổ CÓ dòng, tổng thuế đúng bằng 0. Đọc "tổng = 0"
	// thành "chưa có sổ" rồi rơi về cột là lỗi #2074 ở phía Cloud — bài này ghim
	// để nó không tái diễn ở đây.
	seedLedgerFor(t, s, "o1", 0, 0, 0)
	seedLedgerFor(t, s, "o2", 0, 0, 0)

	info, err := s.buildShiftReport(sessionID)
	if err != nil {
		t.Fatalf("buildShiftReport: %v", err)
	}

	if info.TaxTotal != 0 {
		t.Errorf("TaxTotal = %d, want 0 — sổ có dòng và nói 0; %d là con số của CỘT, "+
			"nghĩa là 'tổng bằng 0' đang bị đọc nhầm thành 'chưa có sổ'", info.TaxTotal, info.TaxTotal)
	}
	if info.DiscountTotalAmount != 0 {
		t.Errorf("DiscountTotalAmount = %d, want 0 (sổ có dòng và nói 0)", info.DiscountTotalAmount)
	}
}

// #2140 — ảnh chụp đối soát (`till_sessions.settlement_snapshot`) cũng phải đọc sổ.
//
// Đây là đường NGUY HIỂM HƠN trong hai đường: 精算 in ra giấy còn đọc lại được,
// còn ảnh chụp này là JSON bất biến mà Cloud sẽ ghi đè bằng bản tính lại của nó
// (plan-046 R7). Hai bên lệch nhau thì lệch âm thầm.
//
// Trước bản này `buildLocalSettlementSnapshot` không có bài test nào gọi thẳng.
func TestBuildLocalSettlementSnapshot_ReadsLedgerNotColumns(t *testing.T) {
	s := newLANPrintTestServer(t)
	sessionID := seedShiftForReport(t, s)

	seedLedgerFor(t, s, "o1", 200, 0, 0)
	seedLedgerFor(t, s, "o2", 150, 300, 0)

	settledAt, err := time.Parse(time.RFC3339, "2026-07-04T00:00:00Z")
	if err != nil {
		t.Fatalf("parse settledAt: %v", err)
	}

	recon := &reconResult{
		CategoryExpected: map[string]float64{},
		TenderExpected:   map[string]float64{},
	}

	snapshotJSON, err := s.buildLocalSettlementSnapshot(sessionID, recon, 2000, 0, settledAt, nil)
	if err != nil {
		t.Fatalf("buildLocalSettlementSnapshot: %v", err)
	}

	var snap map[string]any
	if err := json.Unmarshal([]byte(snapshotJSON), &snap); err != nil {
		t.Fatalf("snapshot không phải JSON hợp lệ: %v", err)
	}

	// Tìm con số thuế ở bất kỳ đâu trong ảnh chụp: khoá có thể đổi tên theo
	// plan-046, nhưng GIÁ TRỊ thì không được là con số của cột.
	blob := snapshotJSON
	if strings.Contains(blob, "315") && !strings.Contains(blob, "350") {
		t.Errorf("ảnh chụp mang 315 (tổng thuế của CỘT) và không mang 350 (tổng của SỔ):\n%s", blob)
	}
	if !strings.Contains(blob, "350") {
		t.Errorf("ảnh chụp không chứa tổng thuế 350 lấy từ sổ:\n%s", blob)
	}
}

// #2736 — the 伝票削除 window inside `buildLocalSettlementSnapshot` compared a RAW
// `voided_at` against NORMALIZED bounds. This is the more dangerous of the two
// void windows: the Z report is paper a human can re-read, but this snapshot is
// the IMMUTABLE JSON Cloud reconciles against (plan-046 R7) — a void that falls
// out of it makes the two sides disagree silently, forever.
//
// The session window is 16:57→17:09 on 2026-07-03 (see seedShiftForReport).
func TestBuildLocalSettlementSnapshot_SpaceFormattedVoidStaysInTheWindow(t *testing.T) {
	s := newLANPrintTestServer(t)
	sessionID := seedShiftForReport(t, s)
	db := s.db.Conn()
	exec := func(q string, args ...any) {
		if _, err := db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v\n%s", err, q)
		}
	}

	// Voided INSIDE the window, stored with a space separator.
	exec(`INSERT INTO orders (id, order_code, status, opened_at, guest_count,
		subtotal, discount_amount, tax_amount, total_amount, created_at, voided_at)
		VALUES ('vin','WS-VIN','voided','2026-07-03T17:00:00Z',1, 600,0,0,600,
		        '2026-07-03T17:00:00Z','2026-07-03 17:05:00')`)
	// Voided OUTSIDE the window, also space-formatted — control, must stay out.
	exec(`INSERT INTO orders (id, order_code, status, opened_at, guest_count,
		subtotal, discount_amount, tax_amount, total_amount, created_at, voided_at)
		VALUES ('vout','WS-VOUT','voided','2026-07-03T18:00:00Z',1, 800,0,0,800,
		        '2026-07-03T18:00:00Z','2026-07-03 18:05:00')`)

	settledAt, err := time.Parse(time.RFC3339, "2026-07-03T17:09:00Z")
	if err != nil {
		t.Fatalf("parse settledAt: %v", err)
	}
	recon := &reconResult{
		CategoryExpected: map[string]float64{},
		TenderExpected:   map[string]float64{},
	}

	snapshotJSON, err := s.buildLocalSettlementSnapshot(sessionID, recon, 2000, 0, settledAt, nil)
	if err != nil {
		t.Fatalf("buildLocalSettlementSnapshot: %v", err)
	}

	var snap map[string]any
	if err := json.Unmarshal([]byte(snapshotJSON), &snap); err != nil {
		t.Fatalf("snapshot is not valid JSON: %v", err)
	}
	voids, ok := snap["voids"].(map[string]any)
	if !ok {
		t.Fatalf("snapshot has no voids block: %s", snapshotJSON)
	}
	if got, want := voids["unpaid_count"], float64(1); got != want {
		t.Errorf("voids.unpaid_count = %v, want %v — the in-window space-formatted void must be counted", got, want)
	}
	if got, want := voids["unpaid_amount"], float64(600); got != want {
		t.Errorf("voids.unpaid_amount = %v, want %v (the 18:05 void is out of window)", got, want)
	}
}
