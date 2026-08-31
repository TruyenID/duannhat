package service

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
)

// Plan-036 follow-up — locks in the contract the workstation push-UP loop
// has with Cloud's WorkstationTillController. Backend-side fixes (column
// rename `phase` → `count_phase`, NOT NULL field population, HasUuids
// id-preservation, abandoned_at vs closed_at) are useless if the
// workstation later sends the wrong shape — these tests catch that drift.
//
// All four sync paths are exercised: open / close / abandon / cash-event.

// TestTillSessionOpenPushUp_PathAndPayload asserts that an enqueued
// till_session.open POSTs to /api/v1/workstation/till/sessions with the
// workstation-supplied UUID + every field Cloud's openSession() validates.
func TestTillSessionOpenPushUp_PathAndPayload(t *testing.T) {
	var (
		seenPath  string
		seenAuth  string
		seenIdem  string
		seenBody  map[string]any
		callCount int32
	)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&callCount, 1)
		seenPath = r.URL.Path
		seenAuth = r.Header.Get("Authorization")
		seenIdem = r.Header.Get("Idempotency-Key")
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Header().Set("Content-Type", "application/json")
		// Echo back the workstation-supplied id — post-fix the Cloud
		// controller preserves it (HasUuids workaround).
		_, _ = w.Write([]byte(`{"data":{"id":"local-sess-1","session_code":"WS-0001","status":"open"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	// Seed local till_sessions row so the cloud_id UPDATE-back path can
	// match (post-fix: cloud_id == local id, write is a no-op but still
	// runs).
	_, err := db.Exec(`INSERT INTO till_sessions (
		id, session_code, status, business_date, default_currency_code,
		opening_float_amount, opened_at, till_id, branch_id
	) VALUES ('local-sess-1', 'WS-0001', 'open', '2026-06-18', 'JPY',
		30000, '2026-06-18T09:00:00Z', 'till-1', 'branch-1')`)
	if err != nil {
		t.Fatalf("seed till_session: %v", err)
	}

	payload := map[string]any{
		"bearer_token":         "ws-token-xyz",
		"id":                   "local-sess-1",
		"session_code":         "WS-0001",
		"till_id":              "till-1",
		"branch_id":            "branch-1",
		"currency_code":        "JPY",
		"opening_float_amount": 30000,
		"opening_note":         "Opened offline",
		"opened_by_id":         "cashier-7",
		"opener_name":          "Cashier A",
		"opened_at":            "2026-06-18T09:00:00Z",
		"opening_counts": []map[string]any{
			{"denomination_id": "denom-10000", "quantity": 3},
		},
	}
	if err := e.Enqueue("till_session", "local-sess-1", "open", payload, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if got := atomic.LoadInt32(&callCount); got != 1 {
		t.Fatalf("expected 1 cloud call, got %d", got)
	}
	if seenPath != "/api/v1/workstation/till/sessions" {
		t.Errorf("path: got %q, want /api/v1/workstation/till/sessions", seenPath)
	}
	if seenAuth != "Bearer ws-token-xyz" {
		t.Errorf("auth header: got %q", seenAuth)
	}
	if seenIdem == "" {
		t.Errorf("Idempotency-Key header missing")
	}
	// All fields Cloud's openSession validate() expects.
	for _, k := range []string{"id", "session_code", "till_id", "branch_id", "currency_code",
		"opening_float_amount", "opened_at", "opening_counts"} {
		if _, ok := seenBody[k]; !ok {
			t.Errorf("payload missing required field %q (body=%v)", k, seenBody)
		}
	}
	if seenBody["id"] != "local-sess-1" {
		t.Errorf("id round-trip broken: workstation sent local-sess-1, body has %v", seenBody["id"])
	}
}

// TestTillSessionClosePushUp_AllRequiredFields locks in the close payload
// schema. Pre-fix Cloud blew up on these; post-fix it consumes the very
// fields this test asserts the workstation sends.
func TestTillSessionClosePushUp_AllRequiredFields(t *testing.T) {
	var (
		seenPath string
		seenBody map[string]any
	)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seenPath = r.URL.Path
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":{"id":"local-sess-2","status":"settled"}}`))
	}))
	defer cloud.Close()

	e, _ := newSyncTestEngine(t, cloud.URL)

	payload := map[string]any{
		"bearer_token":   "ws-token",
		"session_id":     "local-sess-2",
		"closed_at":      "2026-06-18T17:30:00Z",
		"closing_counts": []map[string]any{{"denomination_id": "denom-10000", "quantity": 14}},
		"tender_details": []map[string]any{
			{"tender_key": "cash", "gross_amount": 140000, "cancel_amount": 0},
		},
		"closing_note":  "EOD",
		"counted_cash":  140000,
		"cash_variance": -300,
	}
	if err := e.Enqueue("till_session", "local-sess-2", "close", payload, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if seenPath != "/api/v1/workstation/till/sessions/local-sess-2/close" {
		t.Errorf("path: got %q", seenPath)
	}
	// These are the four numbers Cloud's close() persists onto the
	// session row so admin-web's variance KPI can render — losing any
	// of them silently makes the dashboard go blank.
	for _, k := range []string{"closed_at", "closing_counts", "tender_details", "counted_cash", "cash_variance"} {
		if _, ok := seenBody[k]; !ok {
			t.Errorf("close payload missing %q (body=%v)", k, seenBody)
		}
	}
}

// TestTillSessionAbandonPushUp_PathAndPayload — locks in the abandon
// payload shape. The Cloud handler stamps abandoned_at from the
// workstation's `closed_at` field — semantic mapping the workstation has
// no need to know about, but the field name in the wire payload is the
// contract that must not drift.
func TestTillSessionAbandonPushUp_PathAndPayload(t *testing.T) {
	var (
		seenPath string
		seenBody map[string]any
	)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seenPath = r.URL.Path
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":{"id":"local-sess-3","status":"abandoned"}}`))
	}))
	defer cloud.Close()

	e, _ := newSyncTestEngine(t, cloud.URL)

	payload := map[string]any{
		"bearer_token":   "ws-token",
		"session_id":     "local-sess-3",
		"abandon_reason": "Opened by mistake",
		"closed_at":      "2026-06-18T09:15:00Z",
	}
	if err := e.Enqueue("till_session", "local-sess-3", "abandon", payload, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if seenPath != "/api/v1/workstation/till/sessions/local-sess-3/abandon" {
		t.Errorf("path: got %q", seenPath)
	}
	for _, k := range []string{"abandon_reason", "closed_at"} {
		if _, ok := seenBody[k]; !ok {
			t.Errorf("abandon payload missing %q (body=%v)", k, seenBody)
		}
	}
}

// TestTillCashEventPushUp_PreservesWorkstationID — the cash-event handler
// fix on Cloud preserves the workstation-supplied UUID. This test pins
// that the workstation actually sends it in the body so the round-trip
// works.
func TestTillCashEventPushUp_PreservesWorkstationID(t *testing.T) {
	var (
		seenPath string
		seenBody map[string]any
	)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seenPath = r.URL.Path
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":{"id":"local-event-1"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	_, err := db.Exec(`INSERT INTO till_cash_events (
		id, session_id, event_type, amount, currency_code, occurred_at
	) VALUES ('local-event-1', 'sess-1', 'paid_out', 1500, 'JPY', '2026-06-18T10:00:00Z')`)
	if err != nil {
		t.Fatalf("seed cash event: %v", err)
	}

	payload := map[string]any{
		"bearer_token":  "ws-token",
		"session_id":    "sess-1",
		"id":            "local-event-1",
		"event_type":    "paid_out",
		"amount":        1500,
		"currency_code": "JPY",
		"reason":        "tip out",
		"occurred_at":   "2026-06-18T10:00:00Z",
	}
	if err := e.Enqueue("till_cash_event", "local-event-1", "create", payload, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if seenPath != "/api/v1/workstation/till/sessions/sess-1/cash-events" {
		t.Errorf("path: got %q", seenPath)
	}
	// `id` is the contract — without it Cloud's HasUuids workaround can't
	// preserve the workstation-side UUID.
	if seenBody["id"] != "local-event-1" {
		t.Errorf("id round-trip broken: body has %v, want local-event-1", seenBody["id"])
	}
	for _, k := range []string{"event_type", "amount", "currency_code", "occurred_at"} {
		if _, ok := seenBody[k]; !ok {
			t.Errorf("cash-event payload missing %q", k)
		}
	}
}
