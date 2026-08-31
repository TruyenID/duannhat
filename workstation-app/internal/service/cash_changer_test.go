package service

import (
	"context"
	"errors"
	"fmt"
	"strings"
	stdsync "sync"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
)

type fakeCollector struct {
	res         glory.Result
	err         error
	delay       time.Duration
	mu          stdsync.Mutex
	inFlight    int
	maxInFlight int
	cancelCalls int
}

func (f *fakeCollector) Collect(_ context.Context, total int) (glory.Result, error) {
	f.mu.Lock()
	f.inFlight++
	if f.inFlight > f.maxInFlight {
		f.maxInFlight = f.inFlight
	}
	f.mu.Unlock()

	time.Sleep(f.delay)

	f.mu.Lock()
	f.inFlight--
	f.mu.Unlock()

	r := f.res
	r.Total = total
	return r, f.err
}

func (f *fakeCollector) Cancel(context.Context) error {
	f.mu.Lock()
	f.cancelCalls++
	f.mu.Unlock()
	return nil
}

type fakeRecorder struct {
	mu       stdsync.Mutex
	recorded []CashPayment
	err      error
	seq      int
}

func (f *fakeRecorder) RecordCashPayment(_ context.Context, p CashPayment) (string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.err != nil {
		return "", f.err
	}
	f.seq++
	f.recorded = append(f.recorded, p)
	return fmt.Sprintf("pay-%d", f.seq), nil
}

func TestCashChanger_Finish_Records(t *testing.T) {
	col := &fakeCollector{res: glory.Result{TransactionID: "T1", Status: glory.StatusFinish, Tendered: 10000, Change: 1350}}
	rec := &fakeRecorder{}
	svc := NewCashChangerService(col, rec)

	out, err := svc.Collect(context.Background(), "order-1", 8650)
	if err != nil {
		t.Fatalf("Collect: %v", err)
	}
	if out.PaymentID != "pay-1" || out.Status != glory.StatusFinish {
		t.Errorf("outcome = %+v, want paymentID pay-1 status finish", out)
	}
	if len(rec.recorded) != 1 {
		t.Fatalf("recorded %d payments, want 1", len(rec.recorded))
	}
	got := rec.recorded[0]
	if got.OrderID != "order-1" || got.Amount != 8650 || got.Tendered != 10000 || got.Change != 1350 || got.GloryTransactionID != "T1" {
		t.Errorf("recorded = %+v, want order-1/8650/10000/1350/T1", got)
	}
}

func TestCashChanger_ChangeShortage_NoRecord(t *testing.T) {
	col := &fakeCollector{res: glory.Result{TransactionID: "T2"}, err: glory.ErrChangeShortage}
	rec := &fakeRecorder{}
	svc := NewCashChangerService(col, rec)

	_, err := svc.Collect(context.Background(), "order-1", 1000)
	if !errors.Is(err, glory.ErrChangeShortage) {
		t.Fatalf("err = %v, want ErrChangeShortage", err)
	}
	if len(rec.recorded) != 0 {
		t.Errorf("recorded %d payments, want 0 (no money recognized on shortage)", len(rec.recorded))
	}
}

func TestCashChanger_RecordFailure_IsMoneyCritical(t *testing.T) {
	col := &fakeCollector{res: glory.Result{TransactionID: "T3", Status: glory.StatusFinish, Tendered: 5000, Change: 4000}}
	rec := &fakeRecorder{err: errors.New("disk full")}
	svc := NewCashChangerService(col, rec)

	out, err := svc.Collect(context.Background(), "order-1", 1000)
	if err == nil {
		t.Fatal("want an error when cash collected but recording failed")
	}
	// The error must name the glory transaction so staff can reconcile.
	if !strings.Contains(err.Error(), "T3") || !strings.Contains(err.Error(), "recording failed") {
		t.Errorf("err = %q, want it to mention txn T3 + recording failed", err)
	}
	// Outcome still carries the collected amounts for the alert.
	if out.Tendered != 5000 || out.Change != 4000 {
		t.Errorf("outcome = %+v, want tendered 5000 change 4000", out)
	}
}

func TestCashChanger_SerializesConcurrentPOS(t *testing.T) {
	// 3 POS terminals hit the same machine at once — the mutex must let only one
	// transaction run at a time (the machine rejects concurrent starts).
	col := &fakeCollector{
		res:   glory.Result{TransactionID: "T", Status: glory.StatusFinish, Tendered: 1000},
		delay: 15 * time.Millisecond,
	}
	rec := &fakeRecorder{}
	svc := NewCashChangerService(col, rec)

	var wg stdsync.WaitGroup
	for i := 0; i < 3; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			_, _ = svc.Collect(context.Background(), "order", 1000)
		}()
	}
	wg.Wait()

	if col.maxInFlight != 1 {
		t.Errorf("maxInFlight = %d, want 1 (collections must be serialized)", col.maxInFlight)
	}
	if len(rec.recorded) != 3 {
		t.Errorf("recorded %d, want 3 (all three eventually run)", len(rec.recorded))
	}
}

func TestCashChanger_Cancel_Delegates(t *testing.T) {
	col := &fakeCollector{}
	svc := NewCashChangerService(col, &fakeRecorder{})
	if err := svc.Cancel(context.Background()); err != nil {
		t.Fatalf("Cancel: %v", err)
	}
	if col.cancelCalls != 1 {
		t.Errorf("cancelCalls = %d, want 1", col.cancelCalls)
	}
}
