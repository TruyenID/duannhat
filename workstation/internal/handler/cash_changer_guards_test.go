package handler

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// stubCollector thay máy Glory thật. `Begin` cố ý KHÔNG chạm máy trước khi trả
// (đó là bất biến của B6: không I/O tiền trước khi mọi rào chạy xong), nên với
// các bài rào ở file này, `Collect` không bao giờ được gọi — nó trả lỗi để nếu
// một ngày `Begin` bắt đầu gọi máy sớm thì bài test đổi hành vi thay vì âm thầm
// đi tiếp.
type stubCollector struct{}

func (stubCollector) Collect(context.Context, int) (glory.Result, error) {
	return glory.Result{}, errors.New("stub: máy không được chạm trong bài rào")
}

func (stubCollector) Cancel(context.Context) error { return nil }

// #2535 B6/B8/B11 — rào của đường thu tiền mặt bằng 釣銭機.
//
// Ba thứ được đo ở đây đều có chung một hình dạng hỏng: máy khởi động và **thu
// tiền thật** trong một tình huống mà hệ thống không thể ghi nhận đúng khoản
// đó. Tiền mặt đã vào ngăn kéo thì không rollback được, nên rào phải nằm TRƯỚC
// lượt `Begin`, không phải ở chỗ ghi sổ.
//
// Không dùng máy giả cho các ca này: chúng phải bị từ chối **trước khi** có bất
// kỳ round-trip nào tới máy, nên một máy giả có mặt chỉ làm loãng phép đo. Ca
// duy nhất cần máy là ca "số tiền đòi đúng phần còn thiếu" — xem cuối file.

// insertCapturedCashPayment dựng một dòng tiền ĐÃ THU (`succeeded`) — đúng thứ
// `sumCapturedPaymentsForOrder` đếm, và cố ý KHÔNG dùng `pending`: một khoản
// đang treo của máy quẹt thẻ không được làm giảm số tiền mặt máy đòi (#555 M13).
func insertCapturedCashPayment(t *testing.T, s *Server, id, orderID string, amount int) {
	t.Helper()

	if _, err := s.db.Exec(
		`INSERT INTO payments (id, order_id, payment_method, amount, status)
		 VALUES (?, ?, 'cash', ?, 'succeeded')`, id, orderID, amount,
	); err != nil {
		t.Fatalf("dựng payment đã thu: %v", err)
	}
}

func collectAsPOS(t *testing.T, s *Server, orderID string) *httptest.ResponseRecorder {
	t.Helper()

	rr := httptest.NewRecorder()
	s.handleCashChangerCollect(cashChangerPOS)(rr, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/cash-changer/collect", strings.NewReader(`{"order_id":"`+orderID+`"}`)))

	return rr
}

func openShift(t *testing.T, s *Server) {
	t.Helper()

	if _, err := s.db.Exec(
		`INSERT INTO till_sessions
		   (id, session_code, status, business_date, default_currency_code,
		    opening_float_amount, opened_at, till_id, branch_id)
		 VALUES ('shift-1','SHIFT-1','open','2026-08-12','JPY',0,'2026-08-12T00:00:00Z','t1','br-1')`,
	); err != nil {
		t.Fatalf("mở ca: %v", err)
	}
}

func TestCashChangerCollect_POSRefusesWithoutOpenShift(t *testing.T) {
	s := newRecorderServer(t)
	withFastCashChanger(t, s, "http://127.0.0.1:1") // cấu hình có, nhưng không được gọi tới
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 1000)`)

	rr := collectAsPOS(t, s, "o1")

	// Envelope phải là ĐÚNG envelope của đường POS payment — pos-web nhận ra
	// `NO_OPEN_SHIFT` và mời mở ca, chứ không hiện một lỗi chung chung.
	if rr.Code != http.StatusConflict {
		t.Fatalf("code = %d, want 409 khi không có ca mở", rr.Code)
	}
	var body map[string]any
	_ = json.Unmarshal(rr.Body.Bytes(), &body)
	if body["code"] != "NO_OPEN_SHIFT" {
		t.Errorf("body = %+v, want code NO_OPEN_SHIFT", body)
	}
}

func TestCashChangerCollect_KioskIsDeliberatelyNotShiftGated(t *testing.T) {
	// `local_pos.go` nói thẳng: đường payment của kiosk *"is intentionally NOT
	// gated"* — máy tự phục vụ phải bán được trong khoảng đóng-ca→mở-ca, và
	// những khoản đó thành gap payment đối soát tay ở ca sau. Hai mount dùng
	// chung handler, nên nếu rào ca áp nhầm cho kiosk thì máy bán hàng tự động
	// đứng im mỗi lần quán đóng ca.
	s := newRecorderServer(t)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 1000)`)

	rr := httptest.NewRecorder()
	s.handleCashChangerCollect(cashChangerKiosk)(rr, httptest.NewRequest(http.MethodPost,
		"/api/v1/kiosk/cash-changer/collect", strings.NewReader(`{"order_id":"o1"}`)))

	// Không có máy cấu hình ⇒ 503. Điều đang đo là nó KHÔNG dừng ở 409 ca.
	if rr.Code == http.StatusConflict {
		t.Fatalf("kiosk bị rào ca — trái với ruling plan-044 Decision 6")
	}
}

// #2577 vòng 2 — bài trên VACUOUS, và review chỉ đúng chỗ.
//
// Không cấu hình máy nên request chết ở 503 (`cashChangerURL` không phân giải
// được) TRƯỚC khi rào-ca kịp chạy. Đổi guard để áp cả hai surface thì bài kia
// VẪN PASS — tức miễn-rào-ca cho kiosk chỉ đang được ghim một cách tình cờ.
//
// Bài này cấu hình máy thật, để request đi qua được 503 và chạm đúng rào ca.
// Kiosk không mở ca vẫn phải đi tiếp (202 hoặc bất cứ mã nào KHÁC 409).
func TestCashChangerCollect_KioskPassesShiftGateWithMachineConfigured(t *testing.T) {
	s := newRecorderServer(t)
	if _, err := s.db.Exec(
		`INSERT INTO orders (id, status, total_amount) VALUES ('o-kiosk', 'checkout', 1000)`,
	); err != nil {
		t.Fatal(err)
	}
	// Máy CÓ cấu hình ⇒ không còn 503 chắn đường; rào ca là thứ duy nhất còn
	// có thể chặn, nên 409 ở đây nghĩa là kiosk đã bị rào nhầm.
	if _, err := s.db.Exec(
		`INSERT INTO peripheral_devices (id, name, type, is_active, metadata)
		 VALUES ('cc-kiosk', 'Kiosk 釣銭機', 'coin_changer', 1, ?)`,
		`{"url":"http://127.0.0.1:1"}`,
	); err != nil {
		t.Fatal(err)
	}

	s.cashChanger = service.NewCashChangerService(stubCollector{}, s)

	rr := httptest.NewRecorder()
	s.handleCashChangerCollect(cashChangerKiosk)(rr, httptest.NewRequest(http.MethodPost,
		"/api/v1/kiosk/cash-changer/collect", strings.NewReader(`{"order_id":"o-kiosk"}`)))

	if rr.Code == http.StatusConflict {
		var body map[string]any
		_ = json.Unmarshal(rr.Body.Bytes(), &body)
		t.Fatalf("kiosk bị rào ca (409 %v) dù máy đã cấu hình — trái ruling plan-044 Decision 6: "+
			"kiosk phải bán được trong khoảng đóng-ca→mở-ca, khoản đó thành gap payment đối soát tay", body["code"])
	}
	if rr.Code == http.StatusServiceUnavailable {
		t.Fatalf("vẫn 503 — máy chưa được cấu hình thì bài này lại vacuous y như bài trên")
	}
}

func TestCashChangerCollect_RefusesTerminalOrder(t *testing.T) {
	for _, status := range []string{"closed", "voided"} {
		t.Run(status, func(t *testing.T) {
			s := newRecorderServer(t)
			withFastCashChanger(t, s, "http://127.0.0.1:1")
			openShift(t, s)
			s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', ?, 1000)`, status)

			rr := collectAsPOS(t, s, "o1")

			// Không rào thì lượt thu lật `closed → paying → closed`
			// (`transitionOrderStatus` là UPDATE trần) và tạo dòng payment thứ
			// hai — Cloud từ chối 409 và ghi
			// `workstation_payment_stranded_at_the_drawer`, sau khi tiền mặt đã
			// vào máy.
			if rr.Code != http.StatusConflict {
				t.Fatalf("code = %d, want 409 trên đơn %s", rr.Code, status)
			}
		})
	}
}

func TestCashChangerCollect_RefusesFullyPaidOrder(t *testing.T) {
	s := newRecorderServer(t)
	withFastCashChanger(t, s, "http://127.0.0.1:1")
	openShift(t, s)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'paying', 1000)`)
	insertCapturedCashPayment(t, s, "p1", "o1", 1000)

	rr := collectAsPOS(t, s, "o1")

	if rr.Code != http.StatusUnprocessableEntity {
		t.Fatalf("code = %d, want 422 khi đơn đã trả đủ", rr.Code)
	}
}

func TestCashChangerCollect_AsksForRemainingNotTotal(t *testing.T) {
	// Ca trung tâm của B6: đơn 1000 đã thu 700 thì máy phải đòi 300.
	//
	// Bản cũ truyền thẳng `total_amount` — máy đòi lại 1000 trong khi màn POS
	// ngay cạnh hiện `remaining_amount = 300`. Khách trả theo con số của máy thì
	// khoản dư không có đường về: lúc sync UP Cloud CẮT số tiền xuống
	// `outstanding` và chỉ ghi một dòng log.
	s := newRecorderServer(t)
	openShift(t, s)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'paying', 1000)`)
	insertCapturedCashPayment(t, s, "p1", "o1", 700)

	// `Begin` trả session id rồi chạy lượt thu trong goroutine, nên con số phải
	// nhận qua channel — đọc một biến ở đây vừa là data race vừa đọc trước khi
	// máy được gọi.
	asked := make(chan int, 1)
	machine := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.Method == http.MethodPost && strings.HasSuffix(r.URL.Path, "/transactions") {
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			if v, ok := body["total"].(float64); ok {
				select {
				case asked <- int(v):
				default:
				}
			}
			_ = json.NewEncoder(w).Encode(map[string]string{"transactionId": "T1"})
			return
		}
		// Giữ giao dịch ở trạng thái chạy: bài test này chỉ đo con số ĐÒI.
		_ = json.NewEncoder(w).Encode(map[string]any{
			"transactionId": "T1", "transactionStatus": "beginDeposit", "total": 0, "deposit": 0,
		})
	}))
	t.Cleanup(machine.Close)
	withFastCashChanger(t, s, machine.URL)

	if rr := collectAsPOS(t, s, "o1"); rr.Code != http.StatusAccepted {
		t.Fatalf("code = %d, want 202 (body: %s)", rr.Code, rr.Body.String())
	}

	select {
	case got := <-asked:
		if got != 300 {
			t.Errorf("máy được đòi %d, want 300 (tổng 1000 − đã thu 700)", got)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("máy chưa bao giờ được gọi mở giao dịch")
	}
}
