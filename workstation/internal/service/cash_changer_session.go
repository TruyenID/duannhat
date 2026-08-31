package service

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"log/slog"
	stdsync "sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
)

// ErrMachineBusy is returned by Begin when a collection is already running on
// the (single) machine.
var ErrMachineBusy = errors.New("cash changer: a collection is already in progress")

// A cash collection takes 30–300s (the customer feeding cash + the machine
// dispensing change), so it CANNOT block an HTTP request. Begin runs it in the
// background and returns a session id the POS polls via Snapshot; the cancel
// button hits CancelSession. Only one runs at a time (single machine).

type cashSession struct {
	id      string
	orderID string
	cancel  context.CancelFunc

	mu      stdsync.Mutex
	running bool
	outcome CollectOutcome
	err     error
}

// SessionSnapshot is the pollable state of a collection.
type SessionSnapshot struct {
	SessionID string
	OrderID   string
	Running   bool
	// Set once terminal:
	Status    glory.Status
	PaymentID string // set on a recorded finish
	Total     int
	Tendered  int
	Change    int
	Error     string // terminal non-finish reason (shortage/timeout/failure/cancel)
}

// Begin starts an async cash collection for `total` JPY on the machine. Returns
// the session id immediately, or ErrMachineBusy when one is already running.
func (s *CashChangerService) Begin(orderID string, total int) (string, error) {
	return s.BeginWithPaymentMetadata(orderID, total, "")
}

// BeginWithPaymentMetadata starts a collection while durably carrying the
// split-bill audit context through the async session and restart recovery.
func (s *CashChangerService) BeginWithPaymentMetadata(
	orderID string,
	total int,
	paymentMetadata string,
) (string, error) {
	s.sessMu.Lock()
	defer s.sessMu.Unlock()

	if s.active != nil {
		s.active.mu.Lock()
		running := s.active.running
		s.active.mu.Unlock()
		if running {
			return "", ErrMachineBusy
		}
	}

	// Bound the background run generously past the machine's own deposit timeout
	// so a stuck poll can't leak a goroutine forever. The bound FOLLOWS the
	// configured timeout (#2422) — a fixed budget under a longer machine timeout
	// would cancel the session while the customer is still feeding cash in.
	ctx, cancel := context.WithTimeout(context.Background(), s.sessionBudget())
	sess := &cashSession{id: newSessionID(), orderID: orderID, cancel: cancel, running: true}
	s.active = sess

	// #2535 B10 — ghi hàng TRƯỚC khi gọi máy. Một hàng thừa (phiên chưa bao giờ
	// nhận tiền) tự đóng ở lượt đối soát kế; một lượt thu KHÔNG có hàng nào là
	// thứ không tìm lại được sau một lần tắt máy.
	//
	// Lỗi ghi không chặn lượt thu: khách đang đứng trước máy, và mất khả năng
	// phục hồi vẫn tốt hơn mất luôn khả năng bán.
	if s.sessions != nil {
		if err := s.sessions.BeginSession(sess.id, orderID, total, paymentMetadata); err != nil {
			slog.Error("không ghi được phiên thu tiền mặt — lượt này sẽ không phục hồi được nếu máy trạm tắt",
				"err", err, "session", sess.id, "order", orderID)
		}
	}

	go func() {
		out, err := s.CollectWithPaymentMetadata(ctx, orderID, total, paymentMetadata)
		cancel()

		// #2535 B10 — đóng hàng lại với kết cục thật. Từ đây lượt đối soát khởi
		// động không còn thấy nó nữa.
		if s.sessions != nil {
			if rerr := s.sessions.ResolveSession(sess.id, outcomeFor(err)); rerr != nil {
				slog.Error("không đóng được phiên thu tiền mặt", "err", rerr, "session", sess.id)
			}

			// #2878 — sự thật của MÁY, tách khỏi kết cục phục hồi ở trên.
			//
			// Chỉ ghi khi máy ĐÃ ngã ngũ (`out.Status` rỗng nghĩa là chưa bao
			// giờ hỏi được máy). Đẩy lên Cloud một hàng không có kết cục máy
			// là đẩy một khẳng định bịa — và một khẳng định bịa về tiền thì
			// tệ hơn không có hàng nào.
			if out.Status != "" {
				if lerr := s.sessions.RecordMachineLedger(sess.id, MachineLedgerRow{
					PeripheralDeviceID: s.currentDeviceID(),
					Outcome:            string(out.Status),
					Deposited:          out.Tendered,
					ChangeDue:          changeDue(out.Tendered, out.Total),
					Dispensed:          out.Change,
					ErrorTitle:         gloryErrorTitle(err),
					FinishedAt:         time.Now().UTC(),
				}); lerr != nil {
					slog.Error("không ghi được sổ máy thu tiền", "err", lerr, "session", sess.id)
				}
			}

			// #2882 — sự cố có dấu thời gian, tách khỏi alert.
			//
			// Alert trả lời "bây giờ có sao không"; sổ này trả lời "tháng qua
			// mất bao nhiêu". Câu thứ hai KHÔNG suy ra được từ câu thứ nhất,
			// nên cả hai cùng chạy chứ không thay nhau.
			s.recordDeviceIncident(err, out.TransactionID)
		}

		sess.mu.Lock()
		sess.running = false
		sess.outcome = out
		sess.err = err
		sess.mu.Unlock()
	}()

	return sess.id, nil
}

// Snapshot returns the state of the given session. ok is false when the id does
// not match the current/last session.
func (s *CashChangerService) Snapshot(sessionID string) (SessionSnapshot, bool) {
	s.sessMu.Lock()
	sess := s.active
	s.sessMu.Unlock()
	if sess == nil || sess.id != sessionID {
		return SessionSnapshot{}, false
	}

	sess.mu.Lock()
	defer sess.mu.Unlock()
	snap := SessionSnapshot{
		SessionID: sess.id,
		OrderID:   sess.orderID,
		Running:   sess.running,
		Status:    sess.outcome.Status,
		PaymentID: sess.outcome.PaymentID,
		Total:     sess.outcome.Total,
		Tendered:  sess.outcome.Tendered,
		Change:    sess.outcome.Change,
	}
	if sess.err != nil {
		snap.Error = sess.err.Error()
	}
	return snap, true
}

// CancelSession asks the machine to return the deposited cash for the given
// session. The in-flight collection then terminates as canceled.
func (s *CashChangerService) CancelSession(ctx context.Context, sessionID string) error {
	s.sessMu.Lock()
	sess := s.active
	s.sessMu.Unlock()
	if sess == nil || sess.id != sessionID {
		return errors.New("cash changer: unknown session")
	}
	return s.Cancel(ctx)
}

func newSessionID() string {
	var b [12]byte
	_, _ = rand.Read(b[:])
	return hex.EncodeToString(b[:])
}

// recordDeviceIncident mở hoặc đóng một sự cố thiết bị (#2882).
//
// Lượt thu KHÔNG lỗi ⇒ đóng mọi sự cố đang mở của máy. Đó là cách `cleared_at`
// được đóng dấu, và là nửa cho phép tính thời lượng — không có nó thì không
// trả lời được "chặn mất bao nhiêu phút", mà đó chính là con số quy ra tiền.
//
// Lỗi ngoài từ vựng adapter (ctx hết giờ, máy trạm tắt) cho `group` rỗng và
// KHÔNG vào sổ: cột `error_group` mang từ vựng của MÁY, trộn lỗi Go vào sẽ làm
// báo cáo đếm nhầm hai thứ khác loại vào cùng một nhóm.
func (s *CashChangerService) recordDeviceIncident(err error, transactionID string) {
	if s == nil || s.observer == nil {
		return
	}

	title := gloryErrorTitle(err)
	group := ErrorGroupFor(err)

	if title == "" || group == "" {
		// Lượt trơn tru: đóng sự cố đang mở, nếu có.
		s.clearKnownIncidents()

		return
	}

	if rerr := s.observer.RaiseDeviceError(title, group, transactionID, ""); rerr != nil {
		slog.Error("không ghi được sự cố máy thu tiền", "err", rerr, "title", title)
	}
}

// clearKnownIncidents đóng mọi sự cố thuộc từ vựng đang theo dõi.
//
// Đóng theo DANH SÁCH thay vì "đóng tất": một lượt thu thành công chứng minh
// máy nhận và nhả được tiền, nhưng KHÔNG chứng minh khay từ chối đã được dọn.
// Danh sách này là những title mà một lượt thu trót lọt thật sự bác bỏ.
func (s *CashChangerService) clearKnownIncidents() {
	for _, title := range []string{"empty", "ifError", "notReady", "forbidden"} {
		if cerr := s.observer.ClearDeviceError(title); cerr != nil {
			slog.Error("không đóng được sự cố máy thu tiền", "err", cerr, "title", title)
		}
	}
}
