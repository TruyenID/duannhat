package service

import (
	"context"
	"errors"
	"fmt"
	"strings"
	stdsync "sync"
	"testing"
)

type fakeCardRecorder struct {
	mu       stdsync.Mutex
	recorded []CardPayment
	err      error
	seq      int
}

func (f *fakeCardRecorder) RecordCardPayment(_ context.Context, p CardPayment) (string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.err != nil {
		return "", f.err
	}
	f.seq++
	f.recorded = append(f.recorded, p)
	return fmt.Sprintf("card-%d", f.seq), nil
}

func TestTerminal_ChargeApproveRecords(t *testing.T) {
	rec := &fakeCardRecorder{}
	b := NewTerminalBridge(rec)

	sid, err := b.Charge("order-1", 3000, ServiceCredit)
	if err != nil {
		t.Fatalf("Charge: %v", err)
	}

	// Frontend polls the command: a queued session yields the AuthorizeSales.
	cmd, ok := b.NextCommand()
	if !ok || cmd.SessionID != sid || cmd.Cancel {
		t.Fatalf("NextCommand = %+v ok=%v, want the AuthorizeSales for %s", cmd, ok, sid)
	}
	auth, _ := cmd.Request["AuthorizeSales"].(map[string]any)
	if auth["CurrentService"] != "Credit" || auth["Amount"] != 3000 {
		t.Errorf("request = %+v, want Credit/3000", auth)
	}
	// Once picked up, the session is processing → no second command.
	if _, ok := b.NextCommand(); ok {
		t.Error("NextCommand returned a command while processing")
	}

	// Frontend reports an approved result → payment recorded.
	if err := b.Complete(context.Background(), sid, map[string]any{"SlipNumber": "SLIP-9", "ApprovalCode": "OK"}); err != nil {
		t.Fatalf("Complete: %v", err)
	}
	snap, _ := b.Snapshot(sid)
	if snap.Status != "approved" || snap.PaymentID == "" {
		t.Errorf("snapshot = %+v, want approved + payment id", snap)
	}
	if len(rec.recorded) != 1 {
		t.Fatalf("recorded %d, want 1", len(rec.recorded))
	}
	got := rec.recorded[0]
	if got.OrderID != "order-1" || got.Amount != 3000 || got.TerminalTxnID != "SLIP-9" {
		t.Errorf("recorded = %+v, want order-1/3000/SLIP-9", got)
	}
}

func TestTerminal_Busy(t *testing.T) {
	b := NewTerminalBridge(&fakeCardRecorder{})
	sid, _ := b.Charge("o1", 1000, ServiceCredit)
	if _, err := b.Charge("o2", 1000, ServiceCredit); err != ErrTerminalBusy {
		t.Fatalf("second Charge err = %v, want ErrTerminalBusy", err)
	}
	// After the first finishes, a new charge succeeds.
	b.Complete(context.Background(), sid, map[string]any{"SlipNumber": "S1"})
	if _, err := b.Charge("o3", 1000, ServiceCredit); err != nil {
		t.Fatalf("Charge after done: %v", err)
	}
}

func TestTerminal_DeclineNoRecord(t *testing.T) {
	rec := &fakeCardRecorder{}
	b := NewTerminalBridge(rec)
	sid, _ := b.Charge("o1", 1000, ServiceCredit)
	b.NextCommand()

	if err := b.Fail(sid, "card declined"); err != nil {
		t.Fatalf("Fail: %v", err)
	}
	snap, _ := b.Snapshot(sid)
	if snap.Status != "declined" || snap.Error != "card declined" {
		t.Errorf("snapshot = %+v, want declined + reason", snap)
	}
	if len(rec.recorded) != 0 {
		t.Errorf("recorded %d, want 0 on decline", len(rec.recorded))
	}
}

func TestTerminal_Cancel(t *testing.T) {
	rec := &fakeCardRecorder{}
	b := NewTerminalBridge(rec)
	sid, _ := b.Charge("o1", 1000, ServiceCredit)
	b.NextCommand() // processing

	if err := b.Cancel(sid); err != nil {
		t.Fatalf("Cancel: %v", err)
	}
	// The frontend's next poll receives a Cancel command.
	cmd, ok := b.NextCommand()
	if !ok || !cmd.Cancel || cmd.SessionID != sid {
		t.Fatalf("NextCommand after cancel = %+v ok=%v, want a Cancel command", cmd, ok)
	}
	// Frontend confirms the cancel finished.
	b.Fail(sid, "canceled by operator")
	snap, _ := b.Snapshot(sid)
	if snap.Status != "canceled" {
		t.Errorf("status = %q, want canceled", snap.Status)
	}
	if len(rec.recorded) != 0 {
		t.Errorf("recorded %d, want 0 on cancel", len(rec.recorded))
	}
}

func TestTerminal_RecordFailureMoneyCritical(t *testing.T) {
	rec := &fakeCardRecorder{err: errors.New("db down")}
	b := NewTerminalBridge(rec)
	sid, _ := b.Charge("o1", 5000, ServiceCredit)
	b.NextCommand()

	err := b.Complete(context.Background(), sid, map[string]any{"SlipNumber": "SLIP-X"})
	if err == nil {
		t.Fatal("want error when card captured but recording failed")
	}
	if !strings.Contains(err.Error(), "SLIP-X") || !strings.Contains(err.Error(), "recording failed") {
		t.Errorf("err = %q, want it to mention the terminal txn + recording failed", err)
	}
}

func TestTerminal_UnknownSession(t *testing.T) {
	b := NewTerminalBridge(&fakeCardRecorder{})
	if _, ok := b.Snapshot("nope"); ok {
		t.Error("Snapshot of unknown session returned ok")
	}
	if err := b.Cancel("nope"); err == nil {
		t.Error("Cancel of unknown session returned nil")
	}
	if err := b.Complete(context.Background(), "nope", nil); err == nil {
		t.Error("Complete of unknown session returned nil")
	}
}
