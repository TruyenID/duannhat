package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strconv"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// #3062 — danh sách ca, nền cho trang lịch sử + nút in lại phiếu 精算.
//
// Bài này canh hợp đồng mà pos-web dựa vào. Thứ dễ hỏng nhất không phải câu
// truy vấn mà là PHẠM VI của nó: một endpoint trả ca của quầy khác, hoặc trả cả
// ca đang mở như thể đã chốt, sẽ mời thu ngân in một tờ 精算 cho ca chưa kết
// thúc.
func seedHistory(t *testing.T, db *store.DB) {
	t.Helper()
	exec := func(q string, args ...any) {
		if _, err := db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}
	exec(`INSERT INTO tills (id, branch_id, code, current_session_id)
	      VALUES ('till-1','b1','0001', NULL)`)
	exec(`INSERT INTO tills (id, branch_id, code) VALUES ('till-2','b1','0002')`)

	add := func(id, status, businessDate, openedAt, tillID string) {
		exec(`INSERT INTO till_sessions
			(id, session_code, status, business_date, default_currency_code,
			 opening_float_amount, opened_at, counted_cash, cash_variance,
			 till_id, branch_id)
			VALUES (?,?,?,?, 'JPY', 1000, ?, 0, 0, ?, 'b1')`,
			id, "WS-"+id, status, businessDate, openedAt, tillID)
	}
	add("s-old", "settled", "2026-08-14", "2026-08-14T01:00:00Z", "till-1")
	add("s-mid", "settled", "2026-08-15", "2026-08-15T01:00:00Z", "till-1")
	add("s-new", "open", "2026-08-16", "2026-08-16T01:00:00Z", "till-1")
	// Quầy KHÁC — không được lọt vào danh sách.
	add("s-other", "settled", "2026-08-15", "2026-08-15T02:00:00Z", "till-2")
}

func listSessions(t *testing.T, s *Server, query string) []map[string]any {
	t.Helper()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/sessions"+query, nil)
	rec := httptest.NewRecorder()
	s.handleLocalPosTillSessionIndex(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, body=%s", rec.Code, rec.Body.String())
	}
	var out struct {
		Data []map[string]any `json:"data"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &out); err != nil {
		t.Fatalf("decode: %v", err)
	}

	return out.Data
}

func ids(rows []map[string]any) []string {
	out := make([]string, 0, len(rows))
	for _, r := range rows {
		out = append(out, r["id"].(string))
	}

	return out
}

func TestTillSessionIndex_ScopedToThisTill_NewestFirst(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "b1")
	s, db := newServerWithAuth(t, cloud.URL)
	seedHistory(t, db)

	got := ids(listSessions(t, s, ""))

	// Mới nhất trước: thu ngân đi tìm ca VỪA chốt, nên nó phải ở trên cùng.
	want := []string{"s-new", "s-mid", "s-old"}
	if len(got) != len(want) {
		t.Fatalf("got %v, want %v", got, want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Fatalf("got %v, want %v", got, want)
		}
	}
}

// Ca của quầy khác KHÔNG được lọt vào. Một máy quầy in tờ 精算 của quầy bên cạnh
// là đưa cho người này con số của người kia — và cả hai đều ký nhận.
func TestTillSessionIndex_ExcludesOtherTills(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "b1")
	s, db := newServerWithAuth(t, cloud.URL)
	seedHistory(t, db)

	for _, id := range ids(listSessions(t, s, "")) {
		if id == "s-other" {
			t.Fatalf("ca của quầy khác lọt vào danh sách")
		}
	}
}

// Lọc theo NGÀY NGHIỆP VỤ, không theo `opened_at` — ca đêm mở 23:50 và đóng
// 02:10 thuộc ngày hôm trước, và đó là điều nhân viên nghĩ khi nói "ca hôm qua"
// (#1091).
func TestTillSessionIndex_FiltersByBusinessDate(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "b1")
	s, db := newServerWithAuth(t, cloud.URL)
	seedHistory(t, db)

	got := ids(listSessions(t, s, "?business_date_from=2026-08-15&business_date_to=2026-08-15"))
	if len(got) != 1 || got[0] != "s-mid" {
		t.Fatalf("got %v, want [s-mid]", got)
	}
}

func TestTillSessionIndex_RespectsLimit(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "b1")
	s, db := newServerWithAuth(t, cloud.URL)
	seedHistory(t, db)

	// 40 ca — PHẢI nhiều hơn trần mặc định (30), nếu không bài này không đo gì.
	// Bản đầu chỉ seed 4 ca: `limit=9999` trả 4 hàng, assert `> 30` không bao
	// giờ chạm, và đột biến gỡ trần vẫn xanh. Đã bắt được bằng thử ngược.
	for i := 0; i < 40; i++ {
		if _, err := db.Exec(`INSERT INTO till_sessions
			(id, session_code, status, business_date, default_currency_code,
			 opening_float_amount, opened_at, counted_cash, cash_variance,
			 till_id, branch_id)
			VALUES (?,?, 'settled','2026-08-10','JPY',0,?,0,0,'till-1','b1')`,
			"bulk-"+strconv.Itoa(i), "WS-bulk", "2026-08-10T01:00:00Z"); err != nil {
			t.Fatalf("seed bulk: %v", err)
		}
	}

	if got := listSessions(t, s, "?limit=1"); len(got) != 1 {
		t.Fatalf("limit=1 trả %d hàng", len(got))
	}

	// `limit` do client gửi KHÔNG được tin: vượt trần thì rơi về mặc định, chứ
	// không kéo cả nghìn hàng vào một tablet quầy.
	if got := listSessions(t, s, "?limit=9999"); len(got) != 30 {
		t.Fatalf("limit vượt trần phải rơi về 30, trả %d hàng", len(got))
	}

	// Trong trần thì được tôn trọng.
	if got := listSessions(t, s, "?limit=35"); len(got) != 35 {
		t.Fatalf("limit=35 (trong trần) trả %d hàng", len(got))
	}
}

// Chưa ghép quầy ⇒ danh sách RỖNG, không phải 404. Máy chưa cấu hình là trạng
// thái bình thường; trả lỗi ở đó làm màn hình hiện "hỏng" cho một chuyện không
// hỏng.
func TestTillSessionIndex_NoTillIsEmptyNotError(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "b1")
	s, _ := newServerWithAuth(t, cloud.URL)

	if got := listSessions(t, s, ""); len(got) != 0 {
		t.Fatalf("chưa ghép quầy phải trả rỗng, có %d hàng", len(got))
	}
}
