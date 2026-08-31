package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
)

// #2848 — cột `resolved_by` của một alert lệch tiền phải mang tên NGƯỜI.
//
// Bề mặt duy nhất gọi ack từng gửi hằng số `"workstation-ui"`, nên mọi lượt xác
// nhận lệch tiền ở mọi máy mọi ngày mang cùng một "người". Các test dưới đây
// khoá bốn nhánh của phép phân giải mới, kể cả nhánh TỪ CHỐI — một rào chỉ biết
// cho qua thì không phải rào.

func newAckTestServer(t *testing.T) *Server {
	t.Helper()
	db := newTestDB(t)
	return &Server{db: db, alerts: service.NewAlertEmitter(service.NewAlertStore(db), nil)}
}

func seedShift(t *testing.T, db *store.DB, id, code, openedByID, openerName string) {
	t.Helper()
	var byID, name any
	if openedByID != "" {
		byID = openedByID
	}
	if openerName != "" {
		name = openerName
	}
	if _, err := db.Exec(`
		INSERT INTO till_sessions
		  (id, session_code, status, business_date, default_currency_code,
		   opening_float_amount, opened_by_id, opener_name, opened_at,
		   till_id, branch_id)
		VALUES (?, ?, 'open', '2026-08-17', 'JPY', 0, ?, ?,
		        '2026-08-17T01:00:00Z', 'till-1', 'br-1')`,
		id, code, byID, name); err != nil {
		t.Fatalf("seed till_session: %v", err)
	}
}

func raiseMoneyAlert(t *testing.T, s *Server, subject string) string {
	t.Helper()
	s.alerts.Raise(service.KindCloudMoneyOverwrite, subject, "Cloud ghi đè số tiền", nil)
	var id string
	if err := s.db.QueryRow(
		`SELECT id FROM alerts WHERE kind = ? AND subject = ? AND resolved_at IS NULL`,
		string(service.KindCloudMoneyOverwrite), subject).Scan(&id); err != nil {
		t.Fatalf("raise: %v", err)
	}
	return id
}

func postAck(s *Server, id string, body string) *httptest.ResponseRecorder {
	rec := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/alerts/"+id+"/ack", strings.NewReader(body))
	req.SetPathValue("id", id)
	s.handleAckAlert(rec, req)
	return rec
}

func postAckKind(s *Server, body string) *httptest.ResponseRecorder {
	rec := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/alerts/ack-kind", strings.NewReader(body))
	s.handleAckAlertKind(rec, req)
	return rec
}

func resolvedBy(t *testing.T, s *Server, alertID string) (by string, resolved bool) {
	t.Helper()
	var val, at any
	if err := s.db.QueryRow(
		`SELECT resolved_by, resolved_at FROM alerts WHERE id = ?`, alertID).Scan(&val, &at); err != nil {
		t.Fatalf("đọc alert: %v", err)
	}
	if at == nil {
		return "", false
	}
	if val == nil {
		return "", true
	}
	return val.(string), true
}

func refusalCode(t *testing.T, rec *httptest.ResponseRecorder) string {
	t.Helper()
	var body struct {
		Code string `json:"code"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("giải mã từ chối: %v — %s", err, rec.Body.String())
	}
	return body.Code
}

// Không có ca mở ⇒ KHÔNG ai được ghi vào sổ. Đây là nhánh mà bản cũ âm thầm ghi
// `"workstation-ui"` — thoả yêu cầu về hình thức, vô hiệu về thực chất.
func TestAckRefusedWithoutOpenShift(t *testing.T) {
	s := newAckTestServer(t)
	id := raiseMoneyAlert(t, s, "order-1")

	rec := postAck(s, id, `{}`)

	if rec.Code != http.StatusConflict {
		t.Fatalf("status = %d, muốn 409 — không có ca thì không có người", rec.Code)
	}
	if code := refusalCode(t, rec); code != "NO_OPEN_SHIFT" {
		t.Errorf("code = %q, muốn NO_OPEN_SHIFT", code)
	}
	if _, resolved := resolvedBy(t, s, id); resolved {
		t.Error("alert đã bị đóng dù không phân giải được người — fail-open")
	}
}

// Tên tự khai KHÔNG mở được cửa khi không có ca: một ô nhập tên không thay thế
// được ngữ cảnh ca, và lệch tiền phải gắn được vào một ca để đi vào 過不足.
func TestAckRefusedWithoutOpenShiftEvenWithDeclaredName(t *testing.T) {
	s := newAckTestServer(t)
	id := raiseMoneyAlert(t, s, "order-1")

	rec := postAck(s, id, `{"declared_by":"Nguyễn Văn A"}`)

	if rec.Code != http.StatusConflict {
		t.Fatalf("status = %d, muốn 409", rec.Code)
	}
	if _, resolved := resolvedBy(t, s, id); resolved {
		t.Error("alert bị đóng bằng một cái tên gõ tay ngoài mọi ca")
	}
}

// Ca có tên tự khai lúc mở ⇒ ghi đúng tên đó, KÈM mã ca.
func TestAckUsesOpenShiftOpenerName(t *testing.T) {
	s := newAckTestServer(t)
	seedShift(t, s.db, "sess-1", "OPEN-1", "", "田中 太郎")
	id := raiseMoneyAlert(t, s, "order-1")

	rec := postAck(s, id, `{}`)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, muốn 200 — %s", rec.Code, rec.Body.String())
	}
	by, resolved := resolvedBy(t, s, id)
	if !resolved {
		t.Fatal("alert chưa đóng")
	}
	if !strings.Contains(by, "田中 太郎") || !strings.Contains(by, "OPEN-1") {
		t.Errorf("resolved_by = %q, muốn có cả tên người lẫn mã ca", by)
	}
}

// Ca chọn nhân viên từ danh sách chỉ mang `opened_by_id`; tên phải tra qua bản
// sao `staff` — y hệt hai phiếu ca đã làm.
func TestAckResolvesOpenerThroughStaffMirror(t *testing.T) {
	s := newAckTestServer(t)
	if _, err := s.db.Exec(`INSERT INTO staff (id, full_name) VALUES ('u-7', '山田太郎')`); err != nil {
		t.Fatalf("seed staff: %v", err)
	}
	seedShift(t, s.db, "sess-1", "OPEN-1", "u-7", "")
	id := raiseMoneyAlert(t, s, "order-1")

	if rec := postAck(s, id, `{}`); rec.Code != http.StatusOK {
		t.Fatalf("status = %d, muốn 200 — %s", rec.Code, rec.Body.String())
	}
	by, _ := resolvedBy(t, s, id)
	if !strings.Contains(by, "山田太郎") {
		t.Errorf("resolved_by = %q, muốn tên phân giải từ opened_by_id", by)
	}
}

// Sổ ca đã biết ai thì MÀN HÌNH không ghi đè được — nếu không thì cột này quay
// về đúng chỗ cũ: ai bấm cũng khai được bất cứ ai.
func TestDeclaredNameCannotOverrideTheShiftsCashier(t *testing.T) {
	s := newAckTestServer(t)
	seedShift(t, s.db, "sess-1", "OPEN-1", "", "田中 太郎")
	id := raiseMoneyAlert(t, s, "order-1")

	if rec := postAck(s, id, `{"declared_by":"ai đó khác"}`); rec.Code != http.StatusOK {
		t.Fatalf("status = %d, muốn 200", rec.Code)
	}
	by, _ := resolvedBy(t, s, id)
	if strings.Contains(by, "ai đó khác") {
		t.Errorf("resolved_by = %q — client ghi đè được người phụ trách ca", by)
	}
}

// Ca mở nhưng KHUYẾT tên (mặc định của pos-web: chọn "__me" ⇒ không gửi khoá
// nào) ⇒ phải hỏi tên, không được lẳng lặng ghi mã ca hay nhãn thiết bị.
func TestAckOnAnonymousShiftDemandsADeclaredName(t *testing.T) {
	s := newAckTestServer(t)
	seedShift(t, s.db, "sess-1", "OPEN-1", "", "")
	id := raiseMoneyAlert(t, s, "order-1")

	rec := postAck(s, id, `{}`)

	if rec.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, muốn 400", rec.Code)
	}
	if code := refusalCode(t, rec); code != "ACTOR_NAME_REQUIRED" {
		t.Errorf("code = %q, muốn ACTOR_NAME_REQUIRED", code)
	}
	if _, resolved := resolvedBy(t, s, id); resolved {
		t.Error("alert bị đóng mà không có tên nào")
	}
}

// Và khi có tên gõ tay, sổ phải NÓI RÕ tên đó không đến từ sổ ca.
func TestDeclaredNameIsStampedAsSelfDeclared(t *testing.T) {
	s := newAckTestServer(t)
	seedShift(t, s.db, "sess-1", "OPEN-1", "", "")
	id := raiseMoneyAlert(t, s, "order-1")

	if rec := postAck(s, id, `{"declared_by":"  Nguyễn Thời Vụ  "}`); rec.Code != http.StatusOK {
		t.Fatalf("status = %d, muốn 200 — %s", rec.Code, rec.Body.String())
	}
	by, _ := resolvedBy(t, s, id)
	if !strings.Contains(by, "Nguyễn Thời Vụ") {
		t.Errorf("resolved_by = %q, muốn tên đã trim", by)
	}
	if !strings.Contains(by, "tự khai") {
		t.Errorf("resolved_by = %q — thiếu dấu 'tự khai', người đọc sổ sau này "+
			"không phân biệt được tên lấy từ sổ ca với tên gõ vào lúc bấm", by)
	}
}

// ack-kind đi qua ĐÚNG phép phân giải đó — nếu không, đường hàng loạt sẽ thành
// cửa sau của đường đơn lẻ.
func TestAckKindRefusedWithoutOpenShift(t *testing.T) {
	s := newAckTestServer(t)
	raiseMoneyAlert(t, s, "order-1")
	raiseMoneyAlert(t, s, "order-2")

	rec := postAckKind(s, `{"kind":"cloud_money_overwrite"}`)

	if rec.Code != http.StatusConflict {
		t.Fatalf("status = %d, muốn 409 — %s", rec.Code, rec.Body.String())
	}
	open, err := s.alerts.ListOpen()
	if err != nil {
		t.Fatalf("ListOpen: %v", err)
	}
	if len(open) != 2 {
		t.Errorf("còn %d alert mở, muốn 2 — đợt bị đóng dù không có người", len(open))
	}
}

// Đường hàng loạt đóng CẢ ĐỢT bằng MỘT khẳng định, và mỗi dòng mang cùng tên
// người — đây là lý do #2167 dựng nó.
func TestAckKindClosesTheWholeBatchUnderTheShiftsCashier(t *testing.T) {
	s := newAckTestServer(t)
	seedShift(t, s.db, "sess-1", "OPEN-1", "", "田中 太郎")
	a := raiseMoneyAlert(t, s, "order-1")
	b := raiseMoneyAlert(t, s, "order-2")

	rec := postAckKind(s, `{"kind":"cloud_money_overwrite"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, muốn 200 — %s", rec.Code, rec.Body.String())
	}

	var body struct {
		Acked int `json:"acked"`
	}
	_ = json.Unmarshal(rec.Body.Bytes(), &body)
	if body.Acked != 2 {
		t.Errorf("acked = %d, muốn 2", body.Acked)
	}
	for _, id := range []string{a, b} {
		by, resolved := resolvedBy(t, s, id)
		if !resolved {
			t.Fatalf("alert %s chưa đóng", id)
		}
		if !strings.Contains(by, "田中 太郎") {
			t.Errorf("resolved_by = %q, muốn tên người phụ trách ca", by)
		}
	}
}

// Panel phải BIẾT trước khi bấm là ai sẽ bị ghi vào sổ, và biết khi nào nó chưa
// biết — nếu không thì nút chỉ báo lỗi sau khi bấm.
func TestListAlertsCarriesAckActorAndAckRequired(t *testing.T) {
	s := newAckTestServer(t)
	seedShift(t, s.db, "sess-1", "OPEN-1", "", "田中 太郎")
	raiseMoneyAlert(t, s, "order-1")

	rec := httptest.NewRecorder()
	s.handleListAlerts(rec, httptest.NewRequest(http.MethodGet, "/api/alerts", nil))

	var body struct {
		Alerts []struct {
			Kind        string `json:"kind"`
			AckRequired bool   `json:"ack_required"`
		} `json:"alerts"`
		AckActor struct {
			ShiftInProgress bool   `json:"shift_in_progress"`
			SessionCode     string `json:"session_code"`
			Name            string `json:"name"`
		} `json:"ack_actor"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("giải mã: %v — %s", err, rec.Body.String())
	}

	if len(body.Alerts) != 1 {
		t.Fatalf("alerts = %d, muốn 1", len(body.Alerts))
	}
	// Nửa còn lại của #2848: `cloud_money_overwrite` không tự đóng được, nên
	// nó PHẢI có nút. Panel cũ gõ cứng `kind === "cash_retained"` và vì thế ba
	// dòng ở 本郷店/人形町店 không có đường đóng nào.
	if !body.Alerts[0].AckRequired {
		t.Error("ack_required = false cho cloud_money_overwrite — kind không tự đóng được thì phải có nút")
	}
	if !body.AckActor.ShiftInProgress || body.AckActor.Name != "田中 太郎" || body.AckActor.SessionCode != "OPEN-1" {
		t.Errorf("ack_actor = %+v, muốn ca OPEN-1 của 田中 太郎", body.AckActor)
	}
}

// Kind tự đóng được thì KHÔNG có nút: cho bấm tay chỉ tạo ra đường dọn màn hình
// mà không sửa gì (docblock của panel).
func TestAutoResolvableKindIsNotAckable(t *testing.T) {
	s := newAckTestServer(t)
	s.alerts.Raise(service.KindSyncStalled, "queue", "Hàng đợi đứng", nil)

	rec := httptest.NewRecorder()
	s.handleListAlerts(rec, httptest.NewRequest(http.MethodGet, "/api/alerts", nil))

	var body struct {
		Alerts []struct {
			AckRequired bool `json:"ack_required"`
		} `json:"alerts"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("giải mã: %v", err)
	}
	if len(body.Alerts) != 1 || body.Alerts[0].AckRequired {
		t.Errorf("ack_required = %+v, muốn false cho kind tự đóng được", body.Alerts)
	}
}

// Không có ca ⇒ panel phải nói được điều đó, chứ không im lặng để nút trông
// như bấm được.
func TestListAlertsReportsNoShiftActor(t *testing.T) {
	s := newAckTestServer(t)
	raiseMoneyAlert(t, s, "order-1")

	rec := httptest.NewRecorder()
	s.handleListAlerts(rec, httptest.NewRequest(http.MethodGet, "/api/alerts", nil))

	var body struct {
		AckActor struct {
			ShiftInProgress bool   `json:"shift_in_progress"`
			Name            string `json:"name"`
		} `json:"ack_actor"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("giải mã: %v", err)
	}
	if body.AckActor.ShiftInProgress || body.AckActor.Name != "" {
		t.Errorf("ack_actor = %+v, muốn rỗng khi không có ca", body.AckActor)
	}
}
