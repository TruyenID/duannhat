package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// #2941 — thu PHẦN CỦA MỘT NGƯỜI khi chia bill.
//
// Trước bản vá, `collect` chỉ nhận `{order_id}` và luôn đòi toàn bộ phần còn
// thiếu. Chia đều 4 người mà chưa ai trả thì khách đầu tiên bị máy đòi 4/4 —
// cách duy nhất đi vòng là bắt ba người kia trả thẻ trước, tức một ràng buộc
// THỨ TỰ mà quầy không kiểm soát được.
//
// Số tiền nay đến TỪ CLIENT, và bài quan trọng nhất ở đây là phép KẸP: máy
// trạm phải từ chối mọi số lớn hơn phần còn nợ. Đó là thứ rào cũ thật sự bảo
// vệ — số lớn hơn thì Cloud CẮT xuống `outstanding` lúc sync UP và tiền dư mắc
// kẹt trong ngăn kéo.

func collectWithBody(t *testing.T, s *Server, body string) *httptest.ResponseRecorder {
	t.Helper()

	rr := httptest.NewRecorder()
	s.handleCashChangerCollect(cashChangerPOS)(rr, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/cash-changer/collect", strings.NewReader(body)))

	return rr
}

/** Dựng một đơn 4.000 với ca mở + máy cấu hình sẵn. */
func splitBillFixture(t *testing.T) *Server {
	t.Helper()

	s := newRecorderServer(t)
	withFastCashChanger(t, s, "http://127.0.0.1:1")
	openShift(t, s)

	if _, err := s.db.Exec(
		`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 4000)`,
	); err != nil {
		t.Fatalf("dựng đơn: %v", err)
	}

	return s
}

func requestedTotal(t *testing.T, rr *httptest.ResponseRecorder) float64 {
	t.Helper()

	var body map[string]any
	if err := json.Unmarshal(rr.Body.Bytes(), &body); err != nil {
		t.Fatalf("đọc body: %v — %s", err, rr.Body.String())
	}

	data, _ := body["data"].(map[string]any)
	total, ok := data["total"].(float64)
	if !ok {
		t.Fatalf("không có `data.total` trong %s", rr.Body.String())
	}

	return total
}

// ─────────────────────────────────────────────────────────────────────────────
// PHẢI CHẠY
// ─────────────────────────────────────────────────────────────────────────────

func TestCashChangerCollect_CollectsOneGuestShare(t *testing.T) {
	s := splitBillFixture(t)

	// Chia đều 4 trên đơn 4.000, chưa ai trả: máy phải đòi 1.000, KHÔNG phải 4.000.
	rr := collectWithBody(t, s, `{"order_id":"o1","amount":1000,"metadata":{`+
		`"split_mode":"even","bill_index":0,"total_bills":4}}`)

	if rr.Code != http.StatusAccepted {
		t.Fatalf("code = %d, want 202 — body %s", rr.Code, rr.Body.String())
	}
	if got := requestedTotal(t, rr); got != 1000 {
		t.Errorf("máy đòi %v, muốn 1000", got)
	}
}

// PHẢI KHÔNG HỒI QUY — đường cũ không đổi một chút nào.
func TestCashChangerCollect_NoAmountStillAsksForOutstanding(t *testing.T) {
	s := splitBillFixture(t)

	rr := collectWithBody(t, s, `{"order_id":"o1"}`)

	if rr.Code != http.StatusAccepted {
		t.Fatalf("code = %d, want 202 — body %s", rr.Code, rr.Body.String())
	}
	if got := requestedTotal(t, rr); got != 4000 {
		t.Errorf("máy đòi %v, muốn 4000 (toàn bộ phần còn thiếu)", got)
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// PHẢI TỪ CHỐI — đây là rào thay cho câu "không nhận số từ client"
// ─────────────────────────────────────────────────────────────────────────────

func TestCashChangerCollect_RefusesAmountAboveOutstanding(t *testing.T) {
	s := splitBillFixture(t)

	// Bài quan trọng nhất của cả file. Nếu lọt, lượt thu này sẽ nuốt 5.000
	// tiền mặt, rồi Cloud cắt xuống 4.000 lúc sync UP và 1.000 mắc kẹt trong
	// ngăn kéo kèm đúng một dòng log.
	rr := collectWithBody(t, s, `{"order_id":"o1","amount":5000}`)

	if rr.Code != http.StatusUnprocessableEntity {
		t.Fatalf("code = %d, want 422 khi amount > outstanding — body %s", rr.Code, rr.Body.String())
	}
}

func TestCashChangerCollect_RefusesAmountAboveRemainingAfterPartialPayment(t *testing.T) {
	s := splitBillFixture(t)
	// Một chân đã trả 3.000 ⇒ còn 1.000. Số 2.000 nhỏ hơn TỔNG nhưng lớn hơn
	// phần CÒN LẠI — phép kẹp phải so với phần còn lại, không so với tổng.
	insertCapturedCashPayment(t, s, "p1", "o1", 3000)

	rr := collectWithBody(t, s, `{"order_id":"o1","amount":2000}`)

	if rr.Code != http.StatusUnprocessableEntity {
		t.Fatalf("code = %d, want 422 — body %s", rr.Code, rr.Body.String())
	}
}

func TestCashChangerCollect_RefusesZeroAndNegativeAmount(t *testing.T) {
	for _, body := range []string{
		`{"order_id":"o1","amount":0}`,
		`{"order_id":"o1","amount":-100}`,
	} {
		s := splitBillFixture(t)
		rr := collectWithBody(t, s, body)

		if rr.Code != http.StatusUnprocessableEntity {
			t.Errorf("body %s → code %d, want 422", body, rr.Code)
		}
	}
}

// `amount: 0` phải phân biệt được với "không gửi amount".
//
// Nếu trường là `int` thay vì `*int` thì hai thứ đó gộp làm một, và một lỗi
// phía POS (gửi 0) biến thành một lượt thu NGUYÊN ĐƠN — im lặng, đúng hình
// dạng hỏng tệ nhất cho tiền mặt.
func TestCashChangerCollect_ZeroIsNotTheSameAsAbsent(t *testing.T) {
	s := splitBillFixture(t)
	zero := collectWithBody(t, s, `{"order_id":"o1","amount":0}`)

	s2 := splitBillFixture(t)
	absent := collectWithBody(t, s2, `{"order_id":"o1"}`)

	if zero.Code == absent.Code {
		t.Fatalf("amount=0 và vắng mặt cho cùng code %d — chúng phải khác nhau", zero.Code)
	}
}
