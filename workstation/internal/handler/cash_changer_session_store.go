package handler

import (
	"database/sql"
	"log/slog"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// *Server hiện thực chỗ lưu phiên 釣銭機 (#2535 B10) — cùng khuôn với
// `CashPaymentRecorder`: service giữ luật, handler giữ `*sql.DB`.
var _ service.CashSessionStore = (*Server)(nil)

// BeginSession ghi hàng TRƯỚC khi gọi máy. Xem `081_cash_changer_sessions.sql`
// về vì sao hàng phải có mặt trước chứ không phải sau.
func (s *Server) BeginSession(sessionID, orderID string, amount int, paymentMetadata string) error {
	// #2881 — đóng dấu MÁY ngay lúc mở phiên, không đợi tới lúc có kết cục.
	//
	// Đây là mảnh làm cho `stampRunningCashSession` hỏi được "phiên của MÁY
	// NÀY", thay vì "phiên mới nhất" — xem ghi chú ở đó.
	_, err := s.db.Exec(
		`INSERT INTO cash_changer_sessions
		 (id, order_id, amount, started_at, peripheral_device_id, payment_metadata)
		 VALUES (?, ?, ?, ?, ?, ?)`,
		sessionID, orderID, amount, time.Now().UTC().Format(time.RFC3339),
		s.cashChangerDeviceID(), paymentMetadata,
	)

	return err
}

// StampTransaction đóng dấu id giao dịch ngay khi máy trả nó.
//
// Đây là mảnh quyết định của cả bản vá: không có id thì lượt đối soát khởi động
// KHÔNG hỏi máy được (`GetTransaction` cần id) và chỉ còn cách báo cho người.
func (s *Server) StampTransaction(sessionID, gloryTransactionID string) error {
	_, err := s.db.Exec(
		`UPDATE cash_changer_sessions SET glory_transaction_id = ? WHERE id = ?`,
		gloryTransactionID, sessionID,
	)

	return err
}

// RecordMachineLedger đóng dấu sự thật của MÁY lên hàng phiên (#2878).
//
// Ghi cả khi `machine_outcome` đã có: một lượt đối soát khởi động hỏi lại máy
// và nhận được câu trả lời đầy đủ hơn phải được phép sửa hàng. Nhưng nó KHÔNG
// đụng `synced_at` — hàng đã đẩy mà đổi nội dung thì phải đẩy lại, và Cloud
// idempotent nên lượt đẩy lại vô hại.
func (s *Server) RecordMachineLedger(sessionID string, row service.MachineLedgerRow) error {
	var finishedAt any
	if !row.FinishedAt.IsZero() {
		finishedAt = row.FinishedAt.UTC().Format(time.RFC3339)
	}

	_, err := s.db.Exec(
		`UPDATE cash_changer_sessions
		 SET peripheral_device_id = ?, machine_outcome = ?, deposited = ?,
		     change_due = ?, dispensed = ?, error_title = ?, finished_at = ?,
		     synced_at = NULL
		 WHERE id = ?`,
		row.PeripheralDeviceID, row.Outcome, row.Deposited,
		row.ChangeDue, row.Dispensed, row.ErrorTitle, finishedAt,
		sessionID,
	)

	return err
}

// ResolveSession đóng hàng lại kèm kết cục.
func (s *Server) ResolveSession(sessionID, outcome string) error {
	_, err := s.db.Exec(
		`UPDATE cash_changer_sessions
		 SET resolved_at = ?, outcome = ?
		 WHERE id = ? AND resolved_at IS NULL`,
		time.Now().UTC().Format(time.RFC3339), outcome, sessionID,
	)

	return err
}

// UnresolvedSessions trả các phiên còn dở, cũ nhất trước.
func (s *Server) UnresolvedSessions() ([]service.UnresolvedCashSession, error) {
	rows, err := s.db.Query(
		`SELECT id, order_id, amount, COALESCE(glory_transaction_id, ''),
		        payment_metadata, started_at
		 FROM cash_changer_sessions
		 WHERE resolved_at IS NULL
		 ORDER BY started_at`,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []service.UnresolvedCashSession
	for rows.Next() {
		var (
			rec       service.UnresolvedCashSession
			startedAt sql.NullString
		)
		if err := rows.Scan(
			&rec.SessionID, &rec.OrderID, &rec.Amount, &rec.GloryTransactionID,
			&rec.PaymentMetadata, &startedAt,
		); err != nil {
			return nil, err
		}
		if startedAt.Valid {
			// Giờ hỏng không được làm mất cả hàng: thời điểm chỉ để hiển thị,
			// còn thứ quyết định cách xử là `glory_transaction_id`.
			rec.StartedAt, _ = time.Parse(time.RFC3339, startedAt.String)
		}
		out = append(out, rec)
	}

	return out, rows.Err()
}

// stampRunningCashSession nối hook `WithTransactionStarted` của collector với
// hàng phiên đang chạy (#2535 B10, siết ở #2881).
//
// Bản đầu tra "phiên chưa resolved MỚI NHẤT" vì máy chỉ có một và
// `CashChangerService` giữ mutex suốt lượt thu. Giả định đó đúng, nhưng nó chỉ
// sống trong một comment — và ngày quán lắp máy thứ hai, mã giao dịch của máy A
// sẽ đóng lên phiên của máy B **im lặng**: không lỗi, không alert, chỉ là tiền
// thu ở máy này ghi thành thu ở máy kia.
//
// Nay phép tra khoá theo MÁY (`BeginSession` đã đóng dấu thiết bị), nên giả
// định không còn phải đúng để mã chạy đúng. Routing thật cho N máy (client +
// mutex theo máy) vẫn là việc chưa làm — nhưng chỗ HỎNG IM LẶNG thì hết.
func (s *Server) stampRunningCashSession(gloryTransactionID string) {
	if gloryTransactionID == "" {
		return
	}

	deviceID := s.cashChangerDeviceID()

	var sessionID string
	if err := s.db.QueryRow(
		`SELECT id FROM cash_changer_sessions
		 WHERE resolved_at IS NULL AND glory_transaction_id = ''
		   AND peripheral_device_id = ?
		 ORDER BY started_at DESC LIMIT 1`,
		deviceID,
	).Scan(&sessionID); err != nil || sessionID == "" {
		// Không có hàng nào để đóng dấu: máy trạm chưa migrate, hoặc lượt ghi ở
		// `BeginSession` đã lỗi (đã log ở đó). Lượt thu vẫn phải chạy tiếp —
		// mất khả năng phục hồi tốt hơn mất khả năng bán.
		return
	}

	if err := s.StampTransaction(sessionID, gloryTransactionID); err != nil {
		slog.Error("không đóng dấu được mã giao dịch 釣銭機 — lượt này sẽ không hỏi lại được máy nếu máy trạm tắt",
			"err", err, "session", sessionID, "glory_txn", gloryTransactionID)
	}
}
