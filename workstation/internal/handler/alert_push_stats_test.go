package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// #2695 — panel alert phải trả lời được "HQ có nhận được cái này không".
//
// Endpoint nhận phía Cloud fail-open theo thiết kế, nên từ ngoài nhìn vào
// "chưa bao giờ gọi" và "gọi mà hỏng" trông y hệt nhau — và chính sự giống
// nhau đó giấu đường đứt suốt nhiều tháng (0 thông báo `workstation.alert`
// trên production). Bộ đếm phải đi KÈM danh sách alert, không nằm ở một
// endpoint riêng mà rồi không ai mở.
func TestListAlertsCarriesPushCounters(t *testing.T) {
	db := newTestDB(t)
	s := &Server{
		db:     db,
		alerts: service.NewAlertEmitter(service.NewAlertStore(db), nil),
		sync:   service.NewSyncEngine(db, "", nil),
	}

	s.alerts.Raise(service.KindCashRetained, "glory-txn-1", "Tiền còn kẹt trong máy", nil)

	rec := httptest.NewRecorder()
	s.handleListAlerts(rec, httptest.NewRequest(http.MethodGet, "/api/alerts", nil))

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, muốn 200", rec.Code)
	}

	var body struct {
		Alerts []map[string]any        `json:"alerts"`
		Push   *service.AlertPushStats `json:"push"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("giải mã: %v — %s", err, rec.Body.String())
	}

	if len(body.Alerts) != 1 {
		t.Fatalf("alerts = %d, muốn 1", len(body.Alerts))
	}
	if body.Push == nil {
		t.Fatal("thiếu khoá `push` — panel không phân biệt được 'chưa gọi' với 'gọi mà hỏng'")
	}
	// Engine vừa dựng: cả ba bộ đếm bằng 0 chính là chữ ký của "CHƯA BAO GIỜ
	// gọi", trạng thái mà production đang nằm trong đó trước #2695.
	if body.Push.OK != 0 || body.Push.Failed != 0 || body.Push.Skipped != 0 {
		t.Errorf("push = %+v, muốn tất cả 0 trên engine vừa dựng", *body.Push)
	}
}

// Không có sync engine (nhiều test và các lối dựng tối giản) thì panel vẫn
// phải trả về được — một bảng cảnh báo sập vì thiếu số đếm là bảng cảnh báo
// không còn cảnh báo gì.
func TestListAlertsWithoutSyncEngineStillAnswers(t *testing.T) {
	db := newTestDB(t)
	s := &Server{db: db, alerts: service.NewAlertEmitter(service.NewAlertStore(db), nil)}

	rec := httptest.NewRecorder()
	s.handleListAlerts(rec, httptest.NewRequest(http.MethodGet, "/api/alerts", nil))

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, muốn 200", rec.Code)
	}
	if !json.Valid(rec.Body.Bytes()) {
		t.Fatalf("body không phải JSON hợp lệ: %s", rec.Body.String())
	}
}
