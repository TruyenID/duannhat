package service

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// forceOnline (flips the ConnMonitor so processQueue drains) is defined in
// sync_push_regressions_test.go and reused here.

func enqueueClose(t *testing.T, e *SyncEngine, sessionID string) {
	t.Helper()
	if err := e.Enqueue("till_session", sessionID, "close", map[string]any{
		"bearer_token": "cashier-token",
		"session_id":   sessionID,
		"closed_at":    "2026-07-15T10:00:00Z",
		"counted_cash": 1000,
	}, 1); err != nil {
		t.Fatalf("enqueue close: %v", err)
	}
}

func closeRowState(t *testing.T, db *store.DB, sessionID string) (synced bool, deadReason string, attempts int, deferredUntil string) {
	t.Helper()
	var synced_, dead_, deferred_ *string
	err := db.QueryRow(`SELECT synced_at, dead_letter_reason, attempts, deferred_until
		FROM sync_queue WHERE entity_type='till_session' AND operation='close' AND entity_id=?`, sessionID).
		Scan(&synced_, &dead_, &attempts, &deferred_)
	if err != nil {
		t.Fatalf("read close row: %v", err)
	}
	if synced_ != nil {
		synced = *synced_ != ""
	}
	if dead_ != nil {
		deadReason = *dead_
	}
	if deferred_ != nil {
		deferredUntil = *deferred_
	}
	return
}

// WS-1: a workstation-route payment.create forwards tip_amount + captured_at
// to Cloud (the fields Cloud's B4 auto-tender + B3 attribution depend on).
func TestWorkstationPayment_ForwardsTipAndCapturedAt(t *testing.T) {
	var seenBody map[string]any
	var seenPath string
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seenPath = r.URL.Path
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Write([]byte(`{"data":{"payment_id":"cloud-pay-1","status":"succeeded"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	forceOnline(e)
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, tip_amount, status, idempotency_key, till_session_id)
		VALUES ('pay-w','cloud-ord-1','cash',5000,200,'confirmed','idem-w','sess-1')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}
	if err := e.Enqueue("payment", "pay-w", "create", map[string]any{
		"bearer_token":    "dev",
		"target":          "workstation",
		"order_id":        "cloud-ord-1",
		"payment_method":  "cash",
		"amount":          5000,
		"tip_amount":      200,
		"tendered_amount": 5200,
		"captured_at":     "2026-07-15T09:30:00Z",
		"idempotency_key": "idem-w",
	}, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}
	e.processQueue()

	if seenPath != "/api/v1/workstation/payments" {
		t.Fatalf("expected workstation route, got %q", seenPath)
	}
	if got := seenBody["captured_at"]; got != "2026-07-15T09:30:00Z" {
		t.Errorf("captured_at not forwarded: %v", got)
	}
	if got := seenBody["tip_amount"]; got == nil || got.(float64) != 200 {
		t.Errorf("tip_amount not forwarded: %v", got)
	}
}

// WS-4: a 503 RECONCILE_PENDING close must be parked (deferred_until set), NOT
// synced, NOT dead-lettered, must NOT burn an attempt, and must NOT trip the
// global cooldown (other sessions keep draining).
func TestTillClose_ReconcilePending_DefersWithoutBurnOrCooldown(t *testing.T) {
	var calls int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&calls, 1)
		w.Header().Set("Retry-After", "7")
		w.WriteHeader(http.StatusServiceUnavailable)
		w.Write([]byte(`{"code":"RECONCILE_PENDING","message":"manifest not drained"}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	forceOnline(e)
	enqueueClose(t, e, "sess-rp")
	e.processQueue()

	if atomic.LoadInt32(&calls) != 1 {
		t.Fatalf("expected 1 close POST, got %d", calls)
	}
	synced, deadReason, attempts, deferredUntil := closeRowState(t, db, "sess-rp")
	if synced {
		t.Error("close must NOT be synced on RECONCILE_PENDING")
	}
	if deadReason != "" {
		t.Errorf("close must NOT be dead-lettered, got reason %q", deadReason)
	}
	if attempts != 0 {
		t.Errorf("RECONCILE_PENDING must not burn an attempt, got attempts=%d", attempts)
	}
	if deferredUntil == "" {
		t.Error("expected deferred_until to be set (Retry-After park)")
	}
	if e.inCooldown() {
		t.Error("RECONCILE_PENDING must NOT trip the global cooldown (row-specific, not Cloud-wide)")
	}
}

// WS-4: a 422 VARIANCE_REASON_REQUIRED close is fatal — dead-letter immediately
// (no five blind attempts) with a specific reason so it surfaces to the operator.
func TestTillClose_VarianceReasonRequired_DeadLetters(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnprocessableEntity)
		w.Write([]byte(`{"code":"VARIANCE_REASON_REQUIRED","message":"reason required"}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	forceOnline(e)
	enqueueClose(t, e, "sess-var")
	e.processQueue()

	synced, deadReason, _, _ := closeRowState(t, db, "sess-var")
	if synced {
		t.Error("out-of-tolerance no-reason close must not be synced")
	}
	if deadReason != "close_variance_reason_required" {
		t.Errorf("expected dead_letter_reason=close_variance_reason_required, got %q", deadReason)
	}
}

// WS-4: SHIFT_REAPED arriving as a 409 must NOT be mistaken for an idempotent
// success — it dead-letters + surfaces so a manager runs manualSettle.
func TestTillClose_ShiftReaped_DeadLettersNotSuccess(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusConflict)
		w.Write([]byte(`{"code":"SHIFT_REAPED","message":"expired by reaper"}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	forceOnline(e)
	enqueueClose(t, e, "sess-reap")
	e.processQueue()

	synced, deadReason, _, _ := closeRowState(t, db, "sess-reap")
	if synced {
		t.Error("SHIFT_REAPED (409) must NOT be treated as idempotent success")
	}
	if deadReason != "close_shift_reaped" {
		t.Errorf("expected dead_letter_reason=close_shift_reaped, got %q", deadReason)
	}
}

// WS-5: the close must defer while any of its drawer rows (here an unsynced
// payment.confirm) is still queued — and must NOT contact Cloud for the close.
func TestTillClose_OrderingGuard_DefersUntilDrawerDrains(t *testing.T) {
	var closeCalls int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/workstation/till/sessions/sess-ord/close" {
			atomic.AddInt32(&closeCalls, 1)
		}
		w.Write([]byte(`{"data":{}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	// Payment bound to the shift, no cloud_id → its confirm can't push yet.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, till_session_id)
		VALUES ('pay-ord','ord-1','card',500,'pending','idem-ord','sess-ord')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}
	// Unsynced payment.confirm row for that payment (skips on missing cloud_id).
	if err := e.Enqueue("payment", "pay-ord", "confirm", map[string]any{"bearer_token": "x", "payment_id": "pay-ord"}, 1); err != nil {
		t.Fatalf("enqueue confirm: %v", err)
	}
	enqueueClose(t, e, "sess-ord")
	forceOnline(e)
	e.processQueue()

	if atomic.LoadInt32(&closeCalls) != 0 {
		t.Errorf("close must NOT be sent while a drawer payment is unsynced, got %d calls", closeCalls)
	}
	synced, deadReason, attempts, _ := closeRowState(t, db, "sess-ord")
	if synced || deadReason != "" || attempts != 0 {
		t.Errorf("close should be deferred (not synced/dead/attempt-burned): synced=%v reason=%q attempts=%d", synced, deadReason, attempts)
	}
}

// WS-5: one session deferring (RECONCILE_PENDING) must not block another
// session's close from settling in the same drain.
func TestTillClose_PerSessionPartition_OneDeferDoesNotBlockOther(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/workstation/till/sessions/sess-A/close" {
			w.Header().Set("Retry-After", "5")
			w.WriteHeader(http.StatusServiceUnavailable)
			w.Write([]byte(`{"code":"RECONCILE_PENDING"}`))
			return
		}
		w.Write([]byte(`{"data":{}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	forceOnline(e)
	enqueueClose(t, e, "sess-A") // enqueued first → processed first, defers
	enqueueClose(t, e, "sess-B") // must still settle
	e.processQueue()

	aSynced, _, _, aDeferred := closeRowState(t, db, "sess-A")
	bSynced, _, _, _ := closeRowState(t, db, "sess-B")
	if aSynced {
		t.Error("sess-A should have deferred, not synced")
	}
	if aDeferred == "" {
		t.Error("sess-A should be parked with deferred_until")
	}
	if !bSynced {
		t.Error("sess-B must settle despite sess-A deferring (per-session partition)")
	}
}

// WS-3: once the drawer has drained, the close carries a typed manifest built
// from the local ledger — payments keyed by {idempotency_key, cloud order_id},
// cash_events by their workstation ids.
func TestTillClose_ManifestBuiltFromLedger(t *testing.T) {
	var seenBody map[string]any
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Write([]byte(`{"data":{}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	forceOnline(e)
	// Order already synced (has cloud_id); payment terminal + bound to the shift.
	if _, err := db.Exec(`INSERT INTO orders (id, cloud_id) VALUES ('ord-m','cloud-ord-m')`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, till_session_id)
		VALUES ('pay-m','ord-m','cash',900,'confirmed','idem-m','sess-m')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO till_cash_events (id, session_id, event_type, amount, occurred_at)
		VALUES ('ev-m','sess-m','paid_in',100,'2026-07-15T09:00:00Z')`); err != nil {
		t.Fatalf("seed cash event: %v", err)
	}
	enqueueClose(t, e, "sess-m")
	e.processQueue()

	manifest, ok := seenBody["manifest"].(map[string]any)
	if !ok {
		t.Fatalf("close body missing manifest: %+v", seenBody)
	}
	payments, _ := manifest["payments"].([]any)
	if len(payments) != 1 {
		t.Fatalf("expected 1 manifest payment, got %+v", manifest["payments"])
	}
	p0 := payments[0].(map[string]any)
	if p0["idempotency_key"] != "idem-m" || p0["order_id"] != "cloud-ord-m" {
		t.Errorf("manifest payment keys wrong (want idem-m / cloud-ord-m): %+v", p0)
	}
	if p0["client_order_id"] != "ord-m" {
		t.Errorf("manifest should also carry client_order_id=ord-m for backend key-robustness: %+v", p0)
	}
	cashEvents, _ := manifest["cash_events"].([]any)
	if len(cashEvents) != 1 || cashEvents[0] != "ev-m" {
		t.Errorf("manifest cash_events wrong (want [ev-m]): %+v", manifest["cash_events"])
	}
	if synced, _, _, _ := closeRowState(t, db, "sess-m"); !synced {
		t.Error("close should be synced once manifest drained and Cloud accepted")
	}
}
