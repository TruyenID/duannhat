package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// "In lại phiếu bếp" từ màn lịch sử của pos-web.
//
// Điều đắt nhất ở đây là thứ KHÔNG xảy ra: một lượt in lại không được chạm vào
// print_status. Nếu nó chạm, đơn vừa in lại sẽ có delta bị đóng/mở khác đi, và
// (qua đường fire) rơi trở lại màn hình bếp như việc mới — quán nấu hai lần một
// đĩa. Cả hai đường đều trả 200 nên chỉ có assertion trực tiếp lên DB mới bắt
// được việc gọi nhầm.

func TestLANPrintKitchenReprint_400_OnMissingOrderID(t *testing.T) {
	s := newLANPrintTestServer(t)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-reprint",
		bytes.NewBufferString(`{}`))
	req = stubAuth(req)
	s.handleLANPrintKitchenReprint(w, req)
	if w.Code != http.StatusBadRequest {
		t.Errorf("want 400, got %d (%s)", w.Code, w.Body.String())
	}
}

func TestLANPrintKitchenReprint_401_OnUnauth(t *testing.T) {
	s := newLANPrintTestServer(t)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-reprint",
		bytes.NewBufferString(`{"order_id":"x"}`))
	s.handleLANPrintKitchenReprint(w, req)
	if w.Code != http.StatusUnauthorized {
		t.Errorf("want 401, got %d", w.Code)
	}
}

func TestLANPrintKitchenReprint_404_WhenOrderMissingAndNoPuller(t *testing.T) {
	s := newLANPrintTestServer(t)
	s.puller = nil
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-reprint",
		bytes.NewBufferString(`{"order_id":"missing"}`))
	req = stubAuth(req)
	s.handleLANPrintKitchenReprint(w, req)
	if w.Code != http.StatusNotFound {
		t.Errorf("want 404, got %d (%s)", w.Code, w.Body.String())
	}
}

// Đây là toàn bộ lý do route này tồn tại riêng. `/kitchen-ticket` trả 422 "no
// unprinted items" đúng trên trạng thái mà thu ngân in lại — đơn đã xong, delta
// bằng 0 cho mọi dòng. Route này phải đi tiếp.
func TestLANPrintKitchenReprint_PrintsWhenEveryLineIsAlreadyFired(t *testing.T) {
	s := newLANPrintTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen"})
	for _, item := range o.Items {
		_ = s.orders.MarkItemPrinted(item.ID, item.Quantity, "2026-06-20T10:00:00Z")
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-reprint",
		bytes.NewBufferString(`{"order_id":"`+o.ID+`"}`))
	req = stubAuth(req)
	s.handleLANPrintKitchenReprint(w, req)

	// Máy test không cắm máy in nào, nên 503 no_printer là kết quả đúng — điều
	// được ghim là nó KHÔNG phải 422: đường này không có cổng delta.
	if w.Code == http.StatusUnprocessableEntity {
		t.Fatalf("in lại bị chặn bởi cổng delta của đường điều món: %s", w.Body.String())
	}
	if w.Code != http.StatusServiceUnavailable && w.Code != http.StatusOK {
		t.Errorf("want 200 or 503, got %d (%s)", w.Code, w.Body.String())
	}
}

// Món đã huỷ không bao giờ được in lên phiếu bếp, đường nào cũng vậy. Đơn chỉ
// còn món huỷ thì không còn gì để in — và đó là 422, không phải "in một tờ trống".
func TestLANPrintKitchenReprint_422_WhenEveryLineIsVoided(t *testing.T) {
	s := newLANPrintTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen"})
	for _, item := range o.Items {
		if _, err := s.db.Exec(`UPDATE order_items SET status = 'voided' WHERE id = ?`, item.ID); err != nil {
			t.Fatal(err)
		}
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-reprint",
		bytes.NewBufferString(`{"order_id":"`+o.ID+`"}`))
	req = stubAuth(req)
	s.handleLANPrintKitchenReprint(w, req)
	if w.Code != http.StatusUnprocessableEntity {
		t.Errorf("want 422, got %d (%s)", w.Code, w.Body.String())
	}
}

// In lại là GIẤY, không phải điều món. `printed_quantity` là sổ ghi "bếp đã được
// báo bao nhiêu đơn vị"; một lượt in lại không báo cho bếp thêm điều gì, nên nó
// không được sửa con số đó theo bất kỳ hướng nào.
func TestLANPrintKitchenReprint_DoesNotTouchPrintedQuantity(t *testing.T) {
	s := newLANPrintTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen"})

	before := map[string]int{}
	for _, item := range o.Items {
		var q int
		if err := s.db.QueryRow(
			`SELECT COALESCE(printed_quantity, 0) FROM order_items WHERE id = ?`, item.ID,
		).Scan(&q); err != nil {
			t.Fatal(err)
		}
		before[item.ID] = q
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-reprint",
		bytes.NewBufferString(`{"order_id":"`+o.ID+`"}`))
	req = stubAuth(req)
	s.handleLANPrintKitchenReprint(w, req)

	for id, want := range before {
		var got int
		if err := s.db.QueryRow(
			`SELECT COALESCE(printed_quantity, 0) FROM order_items WHERE id = ?`, id,
		).Scan(&got); err != nil {
			t.Fatal(err)
		}
		if got != want {
			t.Errorf("item %s: printed_quantity %d → %d — in lại đã điều món", id, want, got)
		}
	}
}

// `untargeted_scope` — phạm vi mà một lượt in KHÔNG NHẮM AI rơi vào (#2535 A7).
//
// Trường này tồn tại vì client KHÔNG suy ra được: `resolvePrintScope` branch ②
// đặt lượt in không-nhắm-ai của đơn một người trả vào phạm vi CHÍNH THANH TOÁN
// đó, chứ không phải `order_scope`. Giao diện đọc `order_scope` cho loại đơn ấy
// sẽ thấy số 0 vĩnh viễn, nên nút "In lại" không bao giờ sáng — đúng lỗi mà bản
// đầu của màn lịch sử mắc phải.
func TestLANPrintStatus_ReportsUntargetedScope(t *testing.T) {
	s := newLANPrintTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen"})

	read := func() map[string]any {
		w := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodGet,
			"/api/lan/print/status?order_id="+o.ID, nil)
		req = stubAuth(req)
		s.handleLANPrintStatus(w, req)
		var body map[string]any
		if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
			t.Fatalf("parse status: %v (%s)", err, w.Body.String())
		}
		return body
	}

	scope, ok := read()["untargeted_scope"].(map[string]any)
	if !ok {
		t.Fatal("untargeted_scope vắng mặt — client sẽ phải đoán, và đoán chính là lỗi")
	}
	// Chưa có thanh toán nào ⇒ branch ③, phạm vi cả đơn ⇒ payment_id null.
	if scope["payment_id"] != nil {
		t.Errorf("đơn chưa thanh toán: muốn payment_id=null, được %v", scope["payment_id"])
	}

	// Một thanh toán duy nhất, không có metadata chia bill ⇒ branch ②: phạm vi
	// là CHÍNH thanh toán đó. Đây là hình dạng dữ liệu làm hỏng giao diện.
	if _, err := s.db.Exec(
		`INSERT INTO payments (id, order_id, amount, status, payment_method)
		 VALUES ('pay-solo', ?, 2000, 'confirmed', 'cash')`,
		o.ID,
	); err != nil {
		t.Fatal(err)
	}
	scope, _ = read()["untargeted_scope"].(map[string]any)
	if scope["payment_id"] != "pay-solo" {
		t.Errorf("đơn một người trả: muốn payment_id=pay-solo, được %v — giao diện sẽ đọc nhầm phạm vi", scope["payment_id"])
	}
}
