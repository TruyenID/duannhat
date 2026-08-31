package glory

import (
	"context"
	"errors"
	"time"
)

// Terminal outcomes of Collect. finish returns nil; the rest return the Result
// (with Tendered/Change populated for reconciliation) plus one of these.
var (
	// ErrCanceled — the transaction was cancelled; deposited cash was returned.
	ErrCanceled = errors.New("glory: transaction canceled")
	// ErrTimedOut — deposit not completed in time; the machine KEPT the cash
	// (operator must reconcile the drawer).
	ErrTimedOut = errors.New("glory: transaction timed out, cash retained")
	// ErrAborted — power/OS crash mid-transaction; reconcile manually.
	ErrAborted = errors.New("glory: transaction aborted, reconcile manually")
	// ErrFailed — machine error while dispensing change / awaiting pickup. Cash
	// was taken and change may have dispensed partially; use Result.Change
	// (dispensedCash) and reconcile manually.
	ErrFailed = errors.New("glory: transaction failed during dispense, reconcile manually")
	// ErrChangeShortage — the recycler could not dispense change ("empty"); the
	// transaction was cancelled. Refill change and retry.
	ErrChangeShortage = errors.New("glory: change shortage, transaction canceled")
)

// Result is the outcome of a cash collection. On a clean finish it maps to a
// cash ledger row: Total=amount paid, Tendered=deposit, Change=dispensedCash.
type Result struct {
	TransactionID string
	Status        Status
	Total         int // amount due (JPY)
	Tendered      int // deposit — cash the customer inserted
	Change        int // dispensedCash — change actually dispensed (finish)
}

// Collector drives one cash collection through the transaction state machine
// over a Machine (§3.3 of the design doc). It is transport-agnostic: swap the
// Machine for the serial method-B implementation and this logic is unchanged.
//
// Collector is NOT concurrency-safe against the physical machine — the machine
// allows one transaction at a time. Serialize access to a single machine one
// level up (the CashChangerService mutex/queue).
type Collector struct {
	m             Machine
	pollInterval  time.Duration
	depositWait   time.Duration
	depositWaitFn func() time.Duration
	onStarted     func(id string) // #2535 B10
}

// Option configures a Collector.
type Option func(*Collector)

// WithPollInterval sets the GetTransaction poll cadence (spec: ≥1s in prod;
// tests use a tiny value).
func WithPollInterval(d time.Duration) Option { return func(c *Collector) { c.pollInterval = d } }

// WithDepositTimeout sets the deposit-completion timeout passed to the machine
// (0 = no timeout).
func WithDepositTimeout(d time.Duration) Option { return func(c *Collector) { c.depositWait = d } }

// WithDepositTimeoutResolver makes the deposit timeout resolve on EVERY
// collection instead of being frozen at construction, so a value edited in the
// Cloud peripheral registry takes effect on the next sale without restarting
// the workstation — the same contract the adapter URL already has
// ({@link NewResolving}). A resolver that returns <= 0 falls back to the static
// value, so a missing/garbage setting behaves exactly like "not configured".
// WithTransactionStarted gọi `fn` NGAY khi máy trả id giao dịch (#2535 B10).
//
// Đây là khoảnh khắc sớm nhất mà "máy có thể đang giữ tiền của khách" trở nên
// đúng, và là khoảnh khắc duy nhất id đó tồn tại trước khi `Collect` kết thúc.
// Không đóng dấu nó xuống đĩa ở đây thì một lần tắt máy giữa chừng để lại một
// giao dịch KHÔNG HỎI LẠI ĐƯỢC: `GetTransaction` cần id.
//
// `fn` chạy đồng bộ trên đường thu tiền — giữ nó ngắn, và nó KHÔNG được phép
// làm hỏng lượt thu: lỗi của nó là chuyện của chỗ gọi, `Collect` đi tiếp.
func WithTransactionStarted(fn func(id string)) Option {
	return func(c *Collector) { c.onStarted = fn }
}

func WithDepositTimeoutResolver(fn func() time.Duration) Option {
	return func(c *Collector) { c.depositWaitFn = fn }
}

// depositTimeout is the value to send on THIS transaction.
func (c *Collector) depositTimeout() time.Duration {
	if c.depositWaitFn != nil {
		if d := c.depositWaitFn(); d > 0 {
			return d
		}
	}
	return c.depositWait
}

// NewCollector builds a Collector with prod defaults (1s poll, 300s deposit
// timeout).
func NewCollector(m Machine, opts ...Option) *Collector {
	c := &Collector{m: m, pollInterval: time.Second, depositWait: 300 * time.Second}
	for _, o := range opts {
		o(c)
	}
	return c
}

// Collect runs a cash collection for `total` JPY to a terminal state. It starts
// the transaction with ShowFixDepositButton=false and drives the commit itself
// (FixDeposit once deposit >= total). It blocks until terminal or ctx ends.
func (c *Collector) Collect(ctx context.Context, total int) (Result, error) {
	id, err := c.m.StartTransaction(ctx, StartRequest{
		Total:                total,
		ShowFixDepositButton: false,
		Timeout:              int(c.depositTimeout().Seconds()),
	})
	if err != nil {
		return Result{}, err
	}

	if c.onStarted != nil {
		c.onStarted(id)
	}

	res := Result{TransactionID: id, Total: total}
	fixSent := false

	for {
		t, err := c.m.GetTransaction(ctx, id)
		if err != nil {
			ge := asError(err)
			switch {
			case ge != nil && ge.IsChangeShortage():
				c.cancelBestEffort(ctx)
				return res, ErrChangeShortage
			case ge != nil && (ge.NeedsOperator() || ge.IsBusy() || ge.IsConnectivity()):
				// Recoverable mid-transaction: the machine error clears when the
				// operator fixes it, then GetTransaction returns 200 again. Keep
				// polling — bounded by ctx (and the machine's own deposit timeout).
				if werr := wait(ctx, c.pollInterval); werr != nil {
					return res, werr
				}
				continue
			default:
				// notFound / forbidden / decode / network — unrecoverable here.
				return res, err
			}
		}

		res.Status = t.TransactionStatus
		res.Tendered = t.Deposit
		res.Change = t.DispensedCash

		switch t.TransactionStatus {
		case StatusBeginDeposit:
			if !fixSent && !t.FixDeposit && t.Deposit >= t.Total {
				if ferr := c.m.FixDeposit(ctx); ferr != nil {
					ge := asError(ferr)
					switch {
					case ge != nil && ge.IsNotEnoughDeposit():
						// Race: deposit dropped below total between poll and fix.
						// Keep polling until it is enough again.
					case ge != nil && ge.IsChangeShortage():
						c.cancelBestEffort(ctx)
						return res, ErrChangeShortage
					case ge != nil && (ge.NeedsOperator() || ge.IsBusy()):
						// Retry the fix on the next tick after the operator clears it.
					default:
						return res, ferr
					}
				} else {
					fixSent = true
				}
			}
		case StatusFinish:
			return res, nil
		case StatusCancel:
			return res, ErrCanceled
		case StatusTimeout:
			return res, ErrTimedOut
		case StatusAbort:
			return res, ErrAborted
		case StatusFailure:
			return res, ErrFailed
		}

		if werr := wait(ctx, c.pollInterval); werr != nil {
			return res, werr
		}
	}
}

// Cancel requests the machine return the deposited cash. Call this from the POS
// "cancel" button while Collect is polling — Collect observes the resulting
// cancel status and returns ErrCanceled. Only valid before the deposit is fixed.
func (c *Collector) Cancel(ctx context.Context) error {
	return c.m.Cancel(ctx)
}

func (c *Collector) cancelBestEffort(ctx context.Context) {
	_ = c.m.Cancel(ctx)
}

// wait sleeps for d, returning early with ctx.Err() if ctx ends first.
func wait(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}
