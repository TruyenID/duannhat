package handler

import (
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// #2881 (T4 của #2876) — phiên thu tiền phải khoá theo MÁY.
//
// Trước bản vá, `stampRunningCashSession` tra "phiên chưa resolved MỚI NHẤT".
// Giả định đằng sau nó — quán chỉ có một máy — đúng ở mọi quán hôm nay, nhưng
// nó chỉ sống trong một comment. Ngày quán lắp máy thứ hai, mã giao dịch của
// máy A đóng lên phiên của máy B **im lặng**: không lỗi, không alert, chỉ là
// tiền thu ở máy này ghi thành thu ở máy kia — và đối soát T2 (#2879) sẽ báo
// lệch ở CẢ HAI máy mà không ai hiểu vì sao.
//
// File này chuyển giả định đó từ comment sang phép đo.

func insertCashDevice(t *testing.T, s *Server, id string) {
	t.Helper()

	if _, err := s.db.Exec(
		`INSERT INTO peripheral_devices (id, name, type, is_active, updated_at)
		 VALUES (?, ?, 'coin_changer', 1, datetime('now'))`, id, "glory-"+id,
	); err != nil {
		t.Fatalf("dựng máy %s: %v", id, err)
	}
}

func sessionDeviceAndTxn(t *testing.T, s *Server, sessionID string) (string, string) {
	t.Helper()

	var device, txn string
	if err := s.db.QueryRow(
		`SELECT peripheral_device_id, glory_transaction_id
		 FROM cash_changer_sessions WHERE id = ?`, sessionID,
	).Scan(&device, &txn); err != nil {
		t.Fatalf("đọc phiên %s: %v", sessionID, err)
	}

	return device, txn
}

// PHẢI TỪ CHỐI ĐOÁN — hai máy đang bật thì không quy lượt thu về máy nào cả.
//
// Đây là kết luận của #2881, và nó KHÔNG phải cái ban đầu định làm. Ý định ban
// đầu là "khoá phiên theo máy" — nhưng phép đo cho thấy `cashChangerDeviceID()`
// tra "máy CỦA QUÁN", không phải "máy vừa chạy lượt này": hai máy thì nó trả về
// một cái tuỳ ý (`ORDER BY updated_at DESC LIMIT 1`, mà `updated_at` chỉ có độ
// phân giải giây). Khoá theo một giá trị đoán vẫn là đoán.
//
// Routing thật (client + mutex theo máy, id đi xuyên qua collector) là refactor
// trên đường TIỀN, và hôm nay 0 quán có hai máy. Nên bản này chuyển hỏng-im
// thành hỏng-kêu: không quy máy, có alert, bán hàng vẫn chạy.
func TestCashDeviceID_RefusesToGuessWhenAmbiguous(t *testing.T) {
	s := newRecorderServer(t)
	// `newRecorderServer` cố ý gọn (chỉ `db`), nên alert centre phải nối tay —
	// mà chính alert LÀ thứ đang đo ở đây.
	s.alerts = service.NewAlertEmitter(service.NewAlertStore(s.db), nil)

	insertCashDevice(t, s, "dev-A")
	insertCashDevice(t, s, "dev-B")

	if got := s.cashChangerDeviceID(); got != "" {
		t.Errorf("máy = %q, muốn rỗng — hai máy thì không được đoán", got)
	}

	open, err := s.alerts.ListOpen()
	if err != nil {
		t.Fatalf("đọc alert: %v", err)
	}

	var found bool
	for _, a := range open {
		if a.Kind == service.KindCashDeviceAmbiguous {
			found = true
		}
	}
	if !found {
		t.Error("không có alert cash_device_ambiguous — mập mờ mà im lặng là đúng lỗi đang chữa")
	}
}

// PHẢI KHÔNG CHẶN BÁN — mập mờ thì mất khả năng quy máy, không mất lượt thu.
func TestCashDeviceAmbiguous_StillStampsTransaction(t *testing.T) {
	s := newRecorderServer(t)

	insertCashDevice(t, s, "dev-A")
	insertCashDevice(t, s, "dev-B")

	if err := s.BeginSession("sess-amb", "order-amb", 1000, ""); err != nil {
		t.Fatalf("mở phiên: %v", err)
	}
	s.stampRunningCashSession("T-amb")

	dev, txn := sessionDeviceAndTxn(t, s, "sess-amb")
	if dev != "" {
		t.Errorf("máy = %q, muốn rỗng", dev)
	}
	// Mã giao dịch VẪN phải được đóng dấu: nó là thứ cho phép lượt đối soát
	// khởi động hỏi lại máy. Mất nó là mất đường phục hồi, nặng hơn nhiều so
	// với mất quy máy.
	if txn != "T-amb" {
		t.Errorf("mã giao dịch = %q, muốn T-amb — mập mờ không được chặn phục hồi", txn)
	}
}

// PHẢI IM — quán MỘT máy (toàn bộ production hôm nay) hành xử y như trước.
func TestStampRunningCashSession_SingleMachineUnchanged(t *testing.T) {
	s := newRecorderServer(t)

	insertCashDevice(t, s, "dev-only")
	if err := s.BeginSession("sess-1", "order-1", 1000, ""); err != nil {
		t.Fatalf("mở phiên: %v", err)
	}

	s.stampRunningCashSession("T-001")

	dev, txn := sessionDeviceAndTxn(t, s, "sess-1")
	if dev != "dev-only" || txn != "T-001" {
		t.Errorf("phiên = (%q, %q), muốn (dev-only, T-001)", dev, txn)
	}
}

// PHẢI IM — quán chạy env fallback, chưa đăng ký máy nào trong registry.
//
// Chuỗi rỗng là câu trả lời ĐÚNG ở đây (cùng ruling với
// `cash_changer_traceability_test.go`), và đường thu tiền vẫn phải chạy: mất
// khả năng truy nguyên tốt hơn mất khả năng bán.
func TestStampRunningCashSession_NoRegisteredDeviceStillStamps(t *testing.T) {
	s := newRecorderServer(t)

	if err := s.BeginSession("sess-x", "order-x", 1000, ""); err != nil {
		t.Fatalf("mở phiên: %v", err)
	}

	s.stampRunningCashSession("T-x")

	dev, txn := sessionDeviceAndTxn(t, s, "sess-x")
	if dev != "" {
		t.Errorf("máy = %q, muốn rỗng (chưa đăng ký)", dev)
	}
	if txn != "T-x" {
		t.Errorf("mã giao dịch = %q, muốn T-x — không đăng ký máy không được chặn thu tiền", txn)
	}
}
