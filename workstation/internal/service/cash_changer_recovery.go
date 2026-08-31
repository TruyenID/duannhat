package service

import (
	"context"
	"errors"
	"log/slog"
	"time"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
)

// #2535 B10 — phiên thu tiền mặt phải sống sót qua một lần restart.
//
// Phiên vốn chỉ sống trong bộ nhớ (`CashChangerService.active`), nên workstation
// tắt giữa lúc máy đã nhận tiền và trước khi `RecordCashPayment` chạy xong để
// lại: không payment, không alert, không queue item, **không gì để reconcile**.
// Tiền thật trong ngăn kéo, hệ thống không biết nó tồn tại.
//
// Ba mảnh dưới đây là toàn bộ bản vá: một cổng ghi (bảng `cash_changer_sessions`),
// một dấu đóng id giao dịch ngay khi máy trả nó, và MỘT lượt đối soát lúc khởi
// động hỏi thẳng máy.

// CashSessionStore là chỗ lưu phiên trên đĩa. Hiện thực nằm ở tầng handler (nơi
// có `*sql.DB`), cùng khuôn với `CashPaymentRecorder`.
type CashSessionStore interface {
	// BeginSession ghi hàng TRƯỚC khi gọi máy.
	BeginSession(sessionID, orderID string, amount int, paymentMetadata string) error
	// StampTransaction đóng dấu id giao dịch ngay khi máy trả nó.
	StampTransaction(sessionID, gloryTransactionID string) error
	// ResolveSession đóng hàng lại kèm kết cục.
	ResolveSession(sessionID, outcome string) error
	// UnresolvedSessions trả các phiên còn dở, cũ nhất trước.
	UnresolvedSessions() ([]UnresolvedCashSession, error)
	// RecordMachineLedger đóng dấu SỰ THẬT CỦA MÁY lên hàng phiên (#2878).
	//
	// Tách khỏi `ResolveSession` vì hai cái trả lời hai câu hỏi khác nhau:
	// `outcome` là "CHÚNG TA đã làm gì", `MachineLedgerRow` là "MÁY đã làm gì".
	// Chỉ cái thứ hai được phép đi lên Cloud — cái thứ nhất là từ vựng phục
	// hồi cục bộ và ánh xạ giữa hai bên lossy theo cả hai chiều.
	RecordMachineLedger(sessionID string, row MachineLedgerRow) error
}

// MachineLedgerRow là những gì MÁY nói về một lượt thu, nguyên văn.
//
// `Outcome` mang thẳng `glory.Status` ở dạng chuỗi — không chuẩn hoá, không
// ánh xạ. Adapter phát một trạng thái mới thì nó đi lên nguyên vẹn và Cloud
// từ chối hàng đó một cách nhìn thấy được, thay vì bị một `default:` phía Go
// nuốt thành một giá trị sai mà trông vẫn hợp lệ.
type MachineLedgerRow struct {
	PeripheralDeviceID string // UUID trong registry — KHÔNG phải serial/server_id
	Outcome            string // glory.Status kết thúc, nguyên văn
	Deposited          int    // khách đã bỏ vào
	ChangeDue          int    // tiền thối phải trả
	Dispensed          int    // máy thật sự đã nhả
	ErrorTitle         string // glory.Error.Title, rỗng khi không lỗi
	FinishedAt         time.Time
}

// UnresolvedCashSession là một phiên chưa ngã ngũ, đọc lên lúc khởi động.
type UnresolvedCashSession struct {
	SessionID          string
	OrderID            string
	Amount             int
	GloryTransactionID string // rỗng = máy chưa kịp trả id
	PaymentMetadata    string // canonical split context; rỗng = thu thường
	StartedAt          time.Time
}

// Kết cục ghi vào cột `outcome`. Đây là từ vựng của bảng, không phải của máy:
// `retained` gộp mọi kiểu "máy còn giữ tiền", `unknown` là ca không hỏi được.
const (
	CashSessionRecorded = "recorded"
	CashSessionReturned = "returned"
	CashSessionRetained = "retained"
	CashSessionUnknown  = "unknown"
)

// transactionQuerier là phép hỏi "giao dịch này kết thúc thế nào" — đúng một
// method của `glory.Machine`, tách ra để lượt đối soát không cần cả cỗ máy.
type transactionQuerier interface {
	GetTransaction(ctx context.Context, id string) (glory.Transaction, error)
}

// SetSessionStore nối chỗ lưu phiên. Nil-safe: máy trạm chưa migrate (hoặc test
// không quan tâm) chạy y như trước, chỉ là không phục hồi được.
func (s *CashChangerService) SetSessionStore(store CashSessionStore) {
	if s != nil {
		s.sessions = store
	}
}

// SetTransactionQuerier nối phép hỏi máy dùng cho lượt đối soát khởi động.
func (s *CashChangerService) SetTransactionQuerier(q transactionQuerier) {
	if s != nil {
		s.txns = q
	}
}

// ReconcileUnfinishedSessions xử mọi phiên còn dở từ lần chạy trước.
//
// Gọi MỘT lần lúc khởi động, không chạy nền và không định kỳ: một vòng nền hỏi
// máy giữa lúc đang có giao dịch chạy là cách dựng ra đúng loại đua mà cả luồng
// này đang tránh. Tiền lệ: `SweepStaleReservations` của sổ in.
//
// Trả về số phiên đã xử. Lỗi chỉ khi không đọc nổi bảng — một phiên hỏng không
// được phép chặn các phiên còn lại.
func (s *CashChangerService) ReconcileUnfinishedSessions(ctx context.Context) (int, error) {
	if s == nil || s.sessions == nil {
		return 0, nil
	}

	rows, err := s.sessions.UnresolvedSessions()
	if err != nil {
		return 0, err
	}

	for _, sess := range rows {
		s.reconcileOne(ctx, sess)
	}

	return len(rows), nil
}

func (s *CashChangerService) reconcileOne(ctx context.Context, sess UnresolvedCashSession) {
	detail := map[string]any{
		"order_id":   sess.OrderID,
		"amount":     sess.Amount,
		"session_id": sess.SessionID,
		"started_at": sess.StartedAt.Format(time.RFC3339),
	}

	// Máy chưa kịp trả id ⇒ không có gì để hỏi. KHÔNG đoán theo hướng nào:
	// "chắc chưa nhận tiền" là câu đoán làm mất một khoản thu, "chắc đã nhận"
	// là câu đoán ghi vào sổ một khoản không có thật. Người phải mở sổ của máy.
	if sess.GloryTransactionID == "" {
		s.alerts.Raise(KindCashCollectedNotRecorded, sess.SessionID,
			"Lượt thu tiền mặt bị đứt giữa chừng — kiểm sổ của máy rồi đối soát", detail)
		_ = s.sessions.ResolveSession(sess.SessionID, CashSessionUnknown)

		return
	}

	detail["glory_transaction_id"] = sess.GloryTransactionID

	if s.txns == nil {
		s.alerts.Raise(KindCashCollectedNotRecorded, sess.GloryTransactionID,
			"Lượt thu tiền mặt bị đứt giữa chừng — không hỏi được máy", detail)
		_ = s.sessions.ResolveSession(sess.SessionID, CashSessionUnknown)

		return
	}

	txn, err := s.txns.GetTransaction(ctx, sess.GloryTransactionID)
	if err != nil {
		// Máy tắt / mất mạng LAN / adapter đổi địa chỉ. Không hỏi được thì
		// không kết luận được — và một khoản tiền mặt không kết luận được phải
		// đi tới một con người, không đi vào một dòng log.
		detail["error"] = err.Error()
		s.alerts.Raise(KindCashCollectedNotRecorded, sess.GloryTransactionID,
			"Lượt thu tiền mặt bị đứt giữa chừng — không hỏi được máy", detail)
		_ = s.sessions.ResolveSession(sess.SessionID, CashSessionUnknown)

		return
	}

	switch txn.TransactionStatus {
	case glory.StatusFinish:
		// Máy ĐÃ thu và ĐÃ thối tiền. Ghi lại lượt thu ấy — an toàn để lặp:
		// `RecordCashPayment` idempotent trên `glory:<txn>`, nên khởi động hai
		// lần liên tiếp không sinh dòng payment thứ hai.
		pid, rerr := s.recorder.RecordCashPayment(ctx, CashPayment{
			OrderID:            sess.OrderID,
			Amount:             txn.Total,
			Tendered:           txn.Deposit,
			Change:             txn.DispensedCash,
			GloryTransactionID: sess.GloryTransactionID,
			ServerID:           s.currentServerID(),
			PaymentMetadata:    sess.PaymentMetadata,
		})
		if rerr != nil {
			detail["error"] = rerr.Error()
			s.alerts.Raise(KindCashCollectedNotRecorded, sess.GloryTransactionID,
				"Đã thu tiền mặt nhưng KHÔNG ghi được vào sổ — đối soát ngay", detail)
			_ = s.sessions.ResolveSession(sess.SessionID, CashSessionUnknown)

			return
		}

		slog.Warn("phục hồi lượt thu tiền mặt sau restart",
			"session", sess.SessionID, "order", sess.OrderID,
			"glory_txn", sess.GloryTransactionID, "payment", pid)
		_ = s.sessions.ResolveSession(sess.SessionID, CashSessionRecorded)

	case glory.StatusCancel:
		// Tiền đã trả lại khách — không có gì để ghi và không có gì để cảnh báo.
		_ = s.sessions.ResolveSession(sess.SessionID, CashSessionReturned)

	default:
		// Mọi kết cục còn lại: máy có thể đang giữ tiền. Cùng ranh giới với
		// `cashMayBeRetained` trên đường thu trực tiếp.
		detail["status"] = string(txn.TransactionStatus)
		s.alerts.Raise(KindCashRetained, sess.GloryTransactionID,
			"Giao dịch máy thối tiền không hoàn tất — kiểm tra tiền còn trong máy", detail)
		_ = s.sessions.ResolveSession(sess.SessionID, CashSessionRetained)
	}
}

// outcomeFor dịch kết cục của một lượt thu TRỰC TIẾP sang từ vựng của bảng.
func outcomeFor(err error) string {
	switch {
	case err == nil:
		return CashSessionRecorded
	case errors.Is(err, glory.ErrCanceled), errors.Is(err, glory.ErrChangeShortage):
		return CashSessionReturned
	case cashMayBeRetained(err):
		return CashSessionRetained
	default:
		return CashSessionUnknown
	}
}

// changeDue là phần khách trả THỪA, không bao giờ âm (#2878).
//
// Lượt `timeout` để lại `deposited < total` — khách bỏ vào chưa đủ thì máy giữ
// tiền và không có tiền thối nào. Một số âm ở đây sẽ đi thẳng lên Cloud và làm
// hỏng phép đối soát của T2 (#2879), vì vế "kỳ vọng máy" cộng dồn cột này.
func changeDue(deposited, total int) int {
	if deposited <= total {
		return 0
	}

	return deposited - total
}

// gloryErrorTitle rút `Title` nguyên văn của adapter ra khỏi err (#2878).
//
// Rỗng khi err không phải lỗi adapter — kể cả khi nó là lỗi thật (ctx hết giờ,
// máy trạm tắt). Cột `error_title` mang TỪ VỰNG CỦA MÁY; nhét thông điệp lỗi
// của Go vào đó sẽ làm báo cáo sự cố của T5 (#2882) đếm nhầm hai thứ khác nhau
// vào cùng một nhóm.
func gloryErrorTitle(err error) string {
	if err == nil {
		return ""
	}

	var ge *glory.Error
	if errors.As(err, &ge) {
		return ge.Title
	}

	return ""
}
