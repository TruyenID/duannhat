package service

import (
	"context"
	"errors"
	"fmt"
	stdsync "sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
)

// cashMayBeRetained phân biệt "máy CÒN GIỮ tiền của khách" với "tiền đã về tay
// khách" — hai kết cục mà bản đầu của luồng này gộp làm một (#2535 B5).
//
// Danh sách đi theo chính docblock của `glory`: `ErrTimedOut` ("the machine KEPT
// the cash"), `ErrAborted` và `ErrFailed` ("cash was taken … reconcile
// manually"). Hai lỗi còn lại nói ngược hẳn — `ErrCanceled` ("deposited cash was
// returned") và `ErrChangeShortage` ("the transaction was cancelled") — nên
// chúng KHÔNG được sinh cảnh báo tiền kẹt.
//
// Lỗi lạ (ctx hết hạn, adapter chưa biết) đi theo hướng AN TOÀN: coi như có thể
// còn tiền trong máy. Một cảnh báo thừa thì người đóng được; một lần tiền kẹt
// không ai biết thì không.
func cashMayBeRetained(err error) bool {
	switch {
	case errors.Is(err, glory.ErrCanceled), errors.Is(err, glory.ErrChangeShortage):
		return false
	default:
		return true
	}
}

// CashChangerService drives cash collection through a Glory 釣銭機 (via the
// glory.Collector state machine) and records the result as a local cash
// payment. See docs/guide/cash-changer-glory-adapter.md.
//
// One physical machine serves many POS terminals but accepts ONE transaction at
// a time and rejects concurrent starts from other hosts (503 processing). This
// service therefore serializes every Collect on a single machine behind a mutex
// — the second caller waits for the first to reach a terminal state.
type CashChangerService struct {
	// #1806 — alert centre. Nil-safe.
	alerts    *AlertEmitter
	collector cashCollector
	recorder  CashPaymentRecorder
	mu        stdsync.Mutex // serialize N POS on the 1 physical machine

	depositWait   time.Duration        // bounds the async session goroutine
	depositWaitFn func() time.Duration // #2422 — per-shop override, resolved per session

	sessMu stdsync.Mutex // guards `active`
	active *cashSession  // the one in-flight/last async collection (single machine)

	// #2535 B10 — chỗ lưu phiên trên đĩa + phép hỏi máy cho lượt đối soát khởi
	// động. Cả hai nil-safe: thiếu chúng thì luồng chạy y như trước, chỉ là
	// không phục hồi được sau restart.
	sessions CashSessionStore
	txns     transactionQuerier

	// #2535 B9 — định danh máy đang thu, đi vào actor audit và
	// `metadata.server_id`.
	//
	// RESOLVER chứ không phải giá trị: máy được đăng ký ở Cloud rồi sync DOWN,
	// nên nó có thể tới SAU lúc service được dựng, và quán đổi máy không được
	// đòi khởi động lại. Cùng khuôn với `depositWaitFn` ngay trên (#2422) và vì
	// cùng một lý do.
	serverIDFn func() string

	// #2878 — UUID thiết bị trong registry, TÁCH khỏi `serverIDFn`.
	//
	// `currentServerID()` ưu tiên `metadata.server_id`/`serial` — một chuỗi do
	// người lắp máy đặt — nên nó KHÔNG dùng làm khoá Cloud được. Sổ đi lên
	// Cloud khoá theo `peripheral_devices.id`, và hai định danh này chỉ trùng
	// nhau ở những quán tình cờ không khai serial. Gộp chúng là gieo một lỗi
	// chỉ nổ ở quán có khai.
	deviceIDFn func() string

	// #2882 — sổ sự cố có dấu thời gian. Nil-safe: thiếu nó thì luồng chạy y
	// như trước, chỉ là không có sổ.
	observer CashDeviceObserver
}

// SetDeviceObserver nối sổ quan sát máy (#2879 在高 + #2882 sự cố).
func (s *CashChangerService) SetDeviceObserver(o CashDeviceObserver) {
	if s != nil {
		s.observer = o
	}
}

// SetServerIDResolver nối phép tra định danh máy 釣銭機 (#2535 B9).
func (s *CashChangerService) SetServerIDResolver(fn func() string) {
	if s != nil {
		s.serverIDFn = fn
	}
}

// SetDeviceIDResolver nối phép tra UUID thiết bị cho sổ đi lên Cloud (#2878).
func (s *CashChangerService) SetDeviceIDResolver(fn func() string) {
	if s != nil {
		s.deviceIDFn = fn
	}
}

func (s *CashChangerService) currentDeviceID() string {
	if s == nil || s.deviceIDFn == nil {
		return ""
	}

	return s.deviceIDFn()
}

func (s *CashChangerService) currentServerID() string {
	if s == nil || s.serverIDFn == nil {
		return ""
	}
	return s.serverIDFn()
}

// cashCollector is the transaction state machine the service drives. Satisfied
// by *glory.Collector; a fake is injected in tests.
type cashCollector interface {
	Collect(ctx context.Context, total int) (glory.Result, error)
	Cancel(ctx context.Context) error
}

// CashPaymentRecorder persists a completed cash payment (SQLite row + sync UP to
// Cloud) and returns the local payment id. Implemented in the handler layer by
// *Server, reusing the existing insertPayment + sync.Enqueue + order-lifecycle
// path so there is a single writer of the payments table.
type CashPaymentRecorder interface {
	RecordCashPayment(ctx context.Context, p CashPayment) (paymentID string, err error)
}

// CashPayment is a finished cash collection to record.
type CashPayment struct {
	OrderID            string
	Amount             int // total due (JPY)
	Tendered           int // deposit — cash the customer inserted
	Change             int // dispensedCash — change dispensed
	GloryTransactionID string
	ServerID           string // adapter X-Server-Id, for audit metadata (optional)
	// Canonical split-bill audit JSON supplied by POS. Empty for a normal
	// collection. It never participates in money calculations.
	PaymentMetadata string
}

// NewCashChangerService wires the service. collector is normally
// glory.NewCollector(glory.New(adapterURL, nil)); recorder is the *Server.
func NewCashChangerService(collector cashCollector, recorder CashPaymentRecorder) *CashChangerService {
	return &CashChangerService{collector: collector, recorder: recorder, depositWait: 5 * time.Minute}
}

// SetDepositWaitResolver makes the async-session bound follow the SAME
// configured deposit timeout the machine gets (#2422). Without this the
// goroutine budget stays frozen at 5 minutes: a shop that raises the machine
// timeout to 10 minutes would have the watchdog cancel its own session
// mid-count, with the customer's cash already inside.
//
// Nil-safe; a resolver returning <= 0 keeps the built-in default.
func (s *CashChangerService) SetDepositWaitResolver(fn func() time.Duration) {
	if s != nil {
		s.depositWaitFn = fn
	}
}

// sessionBudget is how long the background collection goroutine may live:
// the machine's own deposit timeout plus slack for the final poll + dispense.
func (s *CashChangerService) sessionBudget() time.Duration {
	wait := s.depositWait
	if s.depositWaitFn != nil {
		if d := s.depositWaitFn(); d > 0 {
			wait = d
		}
	}

	return wait + 2*time.Minute
}

// CollectOutcome is the result of a collection attempt.
type CollectOutcome struct {
	PaymentID     string       // local payment id — set only when recorded (finish)
	TransactionID string       // Glory transaction id
	Status        glory.Status // terminal status reached
	Total         int          // amount due
	Tendered      int          // deposit
	Change        int          // change dispensed (finish)
}

// Collect runs a cash collection for `total` JPY on the (single) machine and, on
// a clean finish, records the cash payment. It blocks until terminal or ctx ends
// and holds the machine mutex for the whole transaction so concurrent POS
// callers are serialized.
//
// Non-finish terminals (change shortage / timeout / failure / abort / cancel)
// return the collector's error and do NOT record a payment — no money is
// recognized unless the recycler actually completed the sale.
func (s *CashChangerService) Collect(ctx context.Context, orderID string, total int) (CollectOutcome, error) {
	return s.CollectWithPaymentMetadata(ctx, orderID, total, "")
}

// CollectWithPaymentMetadata is Collect plus split-bill audit context. Keeping
// Collect as a wrapper preserves the non-split API and makes omission explicit.
func (s *CashChangerService) CollectWithPaymentMetadata(
	ctx context.Context,
	orderID string,
	total int,
	paymentMetadata string,
) (CollectOutcome, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	res, err := s.collector.Collect(ctx, total)
	out := CollectOutcome{
		TransactionID: res.TransactionID,
		Status:        res.Status,
		Total:         res.Total,
		Tendered:      res.Tendered,
		Change:        res.Change,
	}
	if err != nil {
		// Change shortage, timeout (cash retained), failure/abort (reconcile) —
		// surface to the caller; nothing is recorded.
		//
		// #1806 — nhưng KHÔNG chỉ trả lỗi rồi thôi: tiền có thể đang nằm trong
		// máy. `cash_retained` là kind duy nhất khai `AutoResolvable: false`,
		// nên nó phải có người bấm xác nhận đã lấy tiền ra — không được tự biến
		// mất chỉ vì lần đếm sau không thấy nữa.
		//
		// #2535 B5 — nhưng CHỈ khi máy thật sự đang giữ tiền. Bản cũ raise cho
		// MỌI `err != nil`, gồm `ErrCanceled` ("deposited cash **RETURNED**")
		// và `ErrChangeShortage` ("transaction canceled") — hai ca tiền đã về
		// tay khách. Kind này là critical + không tự đóng + dội lên HQ, nên mỗi
		// lần khách đổi ý bấm huỷ lại để lại một cảnh báo đỏ vĩnh viễn bảo nhân
		// viên đi tìm số tiền không tồn tại. Đó là cách nhanh nhất dạy quán bấm
		// -qua đúng cái cảnh báo duy nhất không được phép bỏ qua.
		if cashMayBeRetained(err) {
			s.alerts.Raise(KindCashRetained, out.TransactionID,
				"Giao dịch máy thối tiền không hoàn tất — kiểm tra tiền còn trong máy",
				map[string]any{
					"status":   string(out.Status),
					"total":    out.Total,
					"tendered": out.Tendered,
					"error":    err.Error(),
				})
		}

		return out, err
	}

	pid, rerr := s.recorder.RecordCashPayment(ctx, CashPayment{
		OrderID:            orderID,
		Amount:             res.Total,
		Tendered:           res.Tendered,
		Change:             res.Change,
		GloryTransactionID: res.TransactionID,
		// #2535 B9 — thiếu trường này thì actor audit là chuỗi
		// `"cash_changer:"` cụt và `metadata.server_id` rỗng: dòng audit của
		// một lượt thu tiền mặt không nói được nó đến từ MÁY NÀO. Quán một máy
		// thì còn đoán ra; quán hai máy thì không.
		ServerID:        s.currentServerID(),
		PaymentMetadata: paymentMetadata,
	})
	if rerr != nil {
		// MONEY-CRITICAL: cash was collected and change dispensed, but persisting
		// the payment failed. Surface a distinct error so the caller alerts staff
		// and reconciles — never swallow this. The glory transaction id lets the
		// operator match the physical collection to the missing ledger row.
		//
		// #2535 B4 — "surface to the caller" là TOÀN BỘ thứ nhánh này từng làm,
		// và caller thì render chuỗi lỗi trong một khối chỉ hiện khi máy đang
		// giữ tiền — nên ca nguy hiểm nhất của cả luồng đi ra màn hình dưới dạng
		// "Đã thu xong". Tiền mặt thật đã vào máy, không dòng payment nào, đơn
		// không đóng, không gì lên Cloud, và **không một cảnh báo nào**.
		//
		// Alert riêng chứ không dùng lại `cash_retained`: máy KHÔNG giữ tiền ở
		// đây (nó đã thối tiền xong) — việc phải làm là ghi tay dòng thu vào sổ
		// và đối soát, không phải đi mở máy lấy tiền ra. Hai việc khác nhau thì
		// không được chung một cảnh báo.
		s.alerts.Raise(KindCashCollectedNotRecorded, out.TransactionID,
			"Đã thu tiền mặt nhưng KHÔNG ghi được vào sổ — đối soát ngay",
			map[string]any{
				"order_id": orderID,
				"total":    out.Total,
				"tendered": out.Tendered,
				"change":   out.Change,
				"error":    rerr.Error(),
			})

		return out, fmt.Errorf("cash collected (glory txn %s) but recording failed: %w", res.TransactionID, rerr)
	}
	out.PaymentID = pid
	return out, nil
}

// Cancel asks the machine to return the deposited cash. Call from the POS cancel
// button while a Collect is in flight; the in-flight Collect observes the cancel
// status and returns glory.ErrCanceled. Valid only before the deposit is fixed.
func (s *CashChangerService) Cancel(ctx context.Context) error {
	return s.collector.Cancel(ctx)
}

// SetAlerts nối alert centre. Setter vì cùng lý do như SyncPuller.
func (s *CashChangerService) SetAlerts(a *AlertEmitter) {
	if s != nil {
		s.alerts = a
	}
}
