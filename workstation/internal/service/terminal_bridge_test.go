package service

import (
	"context"
	"errors"
	"fmt"
	"strings"
	stdsync "sync"
	"testing"
	"time"
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
	cmd, ok := b.NextCommand("")
	if !ok || cmd.SessionID != sid || cmd.Cancel {
		t.Fatalf("NextCommand = %+v ok=%v, want the AuthorizeSales for %s", cmd, ok, sid)
	}
	auth, _ := cmd.Request["AuthorizeSales"].(map[string]any)
	if auth["CurrentService"] != "Credit" || auth["Amount"] != 3000 {
		t.Errorf("request = %+v, want Credit/3000", auth)
	}
	// Once picked up, the session is processing → no second command.
	if _, ok := b.NextCommand(""); ok {
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
	b.NextCommand("")

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
	b.NextCommand("") // processing

	if err := b.Cancel(sid); err != nil {
		t.Fatalf("Cancel: %v", err)
	}
	// The frontend's next poll receives a Cancel command.
	cmd, ok := b.NextCommand("")
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
	b.NextCommand("")

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

// --- Expiry: the machine must free itself when nobody is driving it ---------
//
// Before this, a session had no deadline at all: if the Wails webview was not
// running the bridge, the charge sat `queued` forever and EVERY later charge in
// the shop answered 409, on every POS, until someone restarted the process.

// clockedBridge returns a bridge whose clock the test drives by hand, so expiry
// is exercised in microseconds instead of by sleeping past a 15-minute cap.
func clockedBridge(rec CardPaymentRecorder) (*TerminalBridge, func(d time.Duration)) {
	base := time.Date(2026, 8, 6, 10, 0, 0, 0, time.UTC)
	now := base
	b := NewTerminalBridge(rec)
	b.now = func() time.Time { return now }
	return b, func(d time.Duration) { now = now.Add(d) }
}

func TestTerminal_QueuedExpiresWhenNoBridgePicksUp(t *testing.T) {
	b, advance := clockedBridge(&fakeCardRecorder{})

	sid, err := b.Charge("order-1", 1320, ServiceCredit)
	if err != nil {
		t.Fatalf("Charge: %v", err)
	}

	// Just inside the grace window the session is still live — a bridge in deep
	// backoff (its own worst case is 38.4s) must not lose its command.
	advance(terminalQueuedGrace - time.Second)
	if snap, _ := b.Snapshot(sid); snap.Status != "queued" {
		t.Fatalf("status = %q at grace-1s, want queued", snap.Status)
	}
	if _, err := b.Charge("order-2", 500, ServiceCredit); !errors.Is(err, ErrTerminalBusy) {
		t.Fatalf("Charge inside grace = %v, want ErrTerminalBusy", err)
	}

	advance(2 * time.Second)
	snap, _ := b.Snapshot(sid)
	if snap.Status != "canceled" {
		t.Errorf("status = %q past grace, want canceled (the P400 was never touched)", snap.Status)
	}
	if !snap.Expired {
		t.Error("Expired = false, want true")
	}
	// And the whole point: the next customer can pay.
	if _, err := b.Charge("order-2", 500, ServiceCredit); err != nil {
		t.Fatalf("Charge after expiry: %v, want the machine to be free", err)
	}
}

func TestTerminal_ProcessingExpiresOnDriverSilence(t *testing.T) {
	b, advance := clockedBridge(&fakeCardRecorder{})
	sid, _ := b.Charge("order-1", 1320, ServiceCredit)
	if _, ok := b.NextCommand(""); !ok {
		t.Fatal("NextCommand did not hand out the queued command")
	}

	advance(terminalDriverSilence + time.Second)
	snap, _ := b.Snapshot(sid)
	if snap.Status != "unknown" {
		// NOT "declined": the card may already have been captured, and saying
		// otherwise is a lie with money behind it.
		t.Errorf("status = %q, want unknown", snap.Status)
	}
	if _, err := b.Charge("order-2", 500, ServiceCredit); err != nil {
		t.Fatalf("Charge after driver went silent: %v", err)
	}
}

// The case the whole liveness design exists for: nobody knows how long a swipe
// takes, so a transaction that is still being driven must NOT be killed on a
// guessed duration.
func TestTerminal_ProcessingSurvivesWhileDriverKeepsPolling(t *testing.T) {
	b, advance := clockedBridge(&fakeCardRecorder{})
	sid, _ := b.Charge("order-1", 1320, ServiceCredit)
	cmd, _ := b.NextCommand("")

	// Ten minutes of a slow customer, with the bridge polling all the way.
	for range 20 {
		advance(30 * time.Second)
		b.NextCommand(cmd.SessionID)
	}
	if snap, _ := b.Snapshot(sid); snap.Status != "processing" {
		t.Fatalf("status = %q after 10 driven minutes, want processing", snap.Status)
	}
	if _, err := b.Charge("order-2", 500, ServiceCredit); !errors.Is(err, ErrTerminalBusy) {
		t.Errorf("Charge = %v, want ErrTerminalBusy — a live transaction still owns the machine", err)
	}
}

// A poll that does not name the session is not liveness. A reloaded webview
// keeps polling but drives nothing, and must stop propping the session up.
func TestTerminal_PollWithoutSessionIDDoesNotKeepItAlive(t *testing.T) {
	b, advance := clockedBridge(&fakeCardRecorder{})
	sid, _ := b.Charge("order-1", 1320, ServiceCredit)
	b.NextCommand("")

	for range 5 {
		advance(30 * time.Second)
		b.NextCommand("") // reloaded bridge: polling, driving nothing
	}
	if snap, _ := b.Snapshot(sid); snap.Status != "unknown" {
		t.Errorf("status = %q, want unknown", snap.Status)
	}
}

func TestTerminal_ProcessingHitsAbsoluteCap(t *testing.T) {
	b, advance := clockedBridge(&fakeCardRecorder{})
	sid, _ := b.Charge("order-1", 1320, ServiceCredit)
	cmd, _ := b.NextCommand("")

	// Driver keeps polling faithfully, but VescaJS/the P400 is wedged. Liveness
	// alone can never tell that apart from a slow customer — the cap can.
	for range 40 {
		advance(30 * time.Second)
		b.NextCommand(cmd.SessionID)
	}
	snap, _ := b.Snapshot(sid)
	if snap.Status != "unknown" {
		t.Fatalf("status = %q after 20 driven minutes, want unknown", snap.Status)
	}
	if !strings.Contains(snap.Error, "15-minute") {
		t.Errorf("error = %q, want it to name the cap", snap.Error)
	}
}

// --- Late results: expiry must not turn a capture into a lost payment -------

func TestTerminal_CompleteOnExpiredSessionStillRecords(t *testing.T) {
	rec := &fakeCardRecorder{}
	b, advance := clockedBridge(rec)
	sid, _ := b.Charge("order-1", 1320, ServiceCredit)
	b.NextCommand("")

	advance(terminalDriverSilence + time.Second)
	if snap, _ := b.Snapshot(sid); snap.Status != "unknown" {
		t.Fatalf("precondition: status = %q, want unknown", snap.Status)
	}
	// The machine moves on to the next customer…
	if _, err := b.Charge("order-2", 500, ServiceCredit); err != nil {
		t.Fatalf("Charge: %v", err)
	}
	// …and only THEN does the first terminal's approval arrive. The money is
	// real; dropping it because b.active moved on would leave a charged card
	// with no payment row anywhere.
	if err := b.Complete(context.Background(), sid, map[string]any{"SlipNumber": "SLIP-LATE"}); err != nil {
		t.Fatalf("Complete on expired session: %v", err)
	}
	if len(rec.recorded) != 1 || rec.recorded[0].TerminalTxnID != "SLIP-LATE" {
		t.Fatalf("recorded = %+v, want the late capture", rec.recorded)
	}
	if snap, _ := b.Snapshot(sid); snap.Status != "approved" || snap.PaymentID == "" {
		t.Errorf("snapshot = %+v, want approved with a payment id", snap)
	}
}

func TestTerminal_HistoryIsBounded(t *testing.T) {
	b, advance := clockedBridge(&fakeCardRecorder{})
	var first string
	for i := range terminalHistoryLimit + 5 {
		sid, err := b.Charge(fmt.Sprintf("order-%d", i), 100, ServiceCredit)
		if err != nil {
			t.Fatalf("Charge %d: %v", i, err)
		}
		if i == 0 {
			first = sid
		}
		advance(terminalQueuedGrace + time.Second) // let it expire so the next can start
	}
	if _, ok := b.Snapshot(first); ok {
		t.Error("the oldest session is still addressable — history is unbounded")
	}
}

// --- Escape hatches ---------------------------------------------------------

// Cancel used to answer 200 while leaving a queued session holding the machine
// forever: it only set a flag for a frontend that, in the stuck case, is the
// dead part.
func TestTerminal_CancelQueuedFreesMachineImmediately(t *testing.T) {
	b, _ := clockedBridge(&fakeCardRecorder{})
	sid, _ := b.Charge("order-1", 1320, ServiceCredit)

	if err := b.Cancel(sid); err != nil {
		t.Fatalf("Cancel: %v", err)
	}
	if snap, _ := b.Snapshot(sid); snap.Status != "canceled" {
		t.Errorf("status = %q, want canceled", snap.Status)
	}
	if _, err := b.Charge("order-2", 500, ServiceCredit); err != nil {
		t.Fatalf("Charge after cancel: %v, want the machine free", err)
	}
	// Nothing is left to hand out for the cancelled session.
	if cmd, ok := b.NextCommand(""); ok && cmd.SessionID == sid {
		t.Error("NextCommand still serves the cancelled session")
	}
}

func TestTerminal_AbandonSettlesAsUnknown(t *testing.T) {
	b, _ := clockedBridge(&fakeCardRecorder{})
	sid, _ := b.Charge("order-1", 1320, ServiceCredit)
	b.NextCommand("")

	snap, ok := b.Abandon()
	if !ok {
		t.Fatal("Abandon reported nothing in progress")
	}
	if snap.SessionID != sid || snap.Status != "unknown" {
		// unknown, not canceled: whoever pressed the button does not know
		// whether the card was captured.
		t.Errorf("snapshot = %+v, want %s/unknown", snap, sid)
	}
	// Nothing left in flight, so a second press is a no-op rather than a way to
	// re-settle a session that already finished.
	if _, ok := b.Abandon(); ok {
		t.Error("Abandon on an idle machine reported success")
	}
	if _, err := b.Charge("order-2", 500, ServiceCredit); err != nil {
		t.Fatalf("Charge after abandon: %v", err)
	}
}

func TestTerminal_ActiveSnapshotNamesTheBlocker(t *testing.T) {
	b, _ := clockedBridge(&fakeCardRecorder{})
	if _, ok := b.ActiveSnapshot(); ok {
		t.Fatal("ActiveSnapshot reported a session on an idle bridge")
	}
	sid, _ := b.Charge("order-1", 1320, ServiceCredit)

	snap, ok := b.ActiveSnapshot()
	if !ok || snap.SessionID != sid || snap.OrderID != "order-1" || snap.Amount != 1320 {
		t.Fatalf("ActiveSnapshot = %+v ok=%v, want the blocking session named", snap, ok)
	}
	if snap.StartedAt.IsZero() {
		t.Error("StartedAt is zero — the cashier cannot tell how long it has been stuck")
	}
}

// Both thresholds must clear the bridge's own worst-case poll gap
// (POLL_MS * 2**5 = 38.4s at its backoff cap in terminal-bridge.tsx), or a live
// bridge that hit a few network blips gets its session killed underneath it.
func TestTerminal_ThresholdsClearBridgeBackoffCap(t *testing.T) {
	const bridgeWorstCasePollGap = 1200 * 32 * time.Millisecond // 38.4s
	if terminalQueuedGrace <= bridgeWorstCasePollGap {
		t.Errorf("terminalQueuedGrace %v <= bridge poll gap %v", terminalQueuedGrace, bridgeWorstCasePollGap)
	}
	if terminalDriverSilence <= bridgeWorstCasePollGap {
		t.Errorf("terminalDriverSilence %v <= bridge poll gap %v", terminalDriverSilence, bridgeWorstCasePollGap)
	}
}
