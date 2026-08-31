package service

import (
	"context"
	"fmt"
	stdsync "sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
)

// CashChangerService drives cash collection through a Glory 釣銭機 (via the
// glory.Collector state machine) and records the result as a local cash
// payment. See docs/guide/cash-changer-glory-adapter.md.
//
// One physical machine serves many POS terminals but accepts ONE transaction at
// a time and rejects concurrent starts from other hosts (503 processing). This
// service therefore serializes every Collect on a single machine behind a mutex
// — the second caller waits for the first to reach a terminal state.
type CashChangerService struct {
	collector cashCollector
	recorder  CashPaymentRecorder
	mu        stdsync.Mutex // serialize N POS on the 1 physical machine

	depositWait time.Duration // bounds the async session goroutine

	sessMu stdsync.Mutex // guards `active`
	active *cashSession  // the one in-flight/last async collection (single machine)
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
}

// NewCashChangerService wires the service. collector is normally
// glory.NewCollector(glory.New(adapterURL, nil)); recorder is the *Server.
func NewCashChangerService(collector cashCollector, recorder CashPaymentRecorder) *CashChangerService {
	return &CashChangerService{collector: collector, recorder: recorder, depositWait: 5 * time.Minute}
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
		return out, err
	}

	pid, rerr := s.recorder.RecordCashPayment(ctx, CashPayment{
		OrderID:            orderID,
		Amount:             res.Total,
		Tendered:           res.Tendered,
		Change:             res.Change,
		GloryTransactionID: res.TransactionID,
	})
	if rerr != nil {
		// MONEY-CRITICAL: cash was collected and change dispensed, but persisting
		// the payment failed. Surface a distinct error so the caller alerts staff
		// and reconciles — never swallow this. The glory transaction id lets the
		// operator match the physical collection to the missing ledger row.
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
