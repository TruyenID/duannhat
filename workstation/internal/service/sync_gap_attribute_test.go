package service

// Plan-044 R2 (T-R2.D2.2 / T-R2.D2.6) — the workstation → Cloud gap-claim sync op
// `payment.attribute`. It forwards the payment's NEW till_session_id UP to Cloud via
// POST /api/v1/workstation/payments/{cloud_id}/attribution. The session id is verbatim
// (Cloud upserts sessions by the workstation id) so only the payment is remapped
// local→cloud; if the payment's create hasn't synced yet the op retries transiently.

import (
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func TestHandlePaymentAttribute_PostsToCloudWithSessionID(t *testing.T) {
	var gotPath string
	var gotBody map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		raw, _ := io.ReadAll(r.Body)
		gotBody = map[string]any{}
		_ = json.Unmarshal(raw, &gotBody)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"data":{"id":"cloud-pay-1","till_session_id":"sess-2"}}`))
	}))
	t.Cleanup(srv.Close)

	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	// Local payment already synced UP → cloud_id known, so attribution can be sent.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, cloud_id)
		VALUES ('pay-local','o-1','cash',1000,'confirmed','ik','cloud-pay-1')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}

	engine := NewSyncEngine(db, srv.URL, nil)

	_, retryable, err := engine.handlePaymentAttribute(t.Context(), "pay-local", map[string]any{
		"bearer_token":    "cashier-token",
		"till_session_id": "sess-2",
	})
	if err != nil {
		t.Fatalf("handlePaymentAttribute: err=%v retryable=%v", err, retryable)
	}
	// Remaps the LOCAL id to the payment's cloud_id in the URL.
	if !strings.HasSuffix(gotPath, "/api/v1/workstation/payments/cloud-pay-1/attribution") {
		t.Errorf("path = %q, want .../payments/cloud-pay-1/attribution", gotPath)
	}
	if gotBody["till_session_id"] != "sess-2" {
		t.Errorf("body till_session_id = %v, want sess-2", gotBody["till_session_id"])
	}
	// D2.4 — the local mirror adopts Cloud's resolved session id (DOWN reflection),
	// so local + Cloud are byte-identical after the cycle.
	var localSess string
	_ = db.QueryRow(`SELECT COALESCE(till_session_id,'') FROM payments WHERE id='pay-local'`).Scan(&localSess)
	if localSess != "sess-2" {
		t.Errorf("local till_session_id = %q, want sess-2 (adopted from Cloud)", localSess)
	}
}

// TestHandlePaymentAttribute_RetriesUntilCloudApplies — Cloud echoes an empty
// till_session_id (its own till_session.open hasn't synced, so the branch guard
// found no session). The op must retry transiently and MUST NOT stamp the local
// mirror to a value Cloud didn't accept — otherwise the two DBs diverge silently.
func TestHandlePaymentAttribute_RetriesUntilCloudApplies(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"data":{"id":"cloud-pay-1","till_session_id":null}}`))
	}))
	t.Cleanup(srv.Close)

	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, cloud_id)
		VALUES ('pay-local','o-1','cash',1000,'confirmed','ik','cloud-pay-1')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}

	engine := NewSyncEngine(db, srv.URL, nil)
	_, retryable, err := engine.handlePaymentAttribute(t.Context(), "pay-local", map[string]any{
		"bearer_token":    "cashier-token",
		"till_session_id": "sess-2",
	})
	if err == nil || !retryable {
		t.Fatalf("want transient retry until cloud applies, got err=%v retryable=%v", err, retryable)
	}
	if !errors.Is(err, errDependencyNotReady) {
		t.Errorf("want errDependencyNotReady, got %v", err)
	}
	var localSess string
	_ = db.QueryRow(`SELECT COALESCE(till_session_id,'') FROM payments WHERE id='pay-local'`).Scan(&localSess)
	if localSess != "" {
		t.Errorf("local mirror stamped to %q despite Cloud not applying — must stay NULL", localSess)
	}
}

// TestHandlePaymentCreate_CarriesClaimedTillSession — a gap payment claimed at the
// next shift open (local till_session_id stamped) whose OWN create hadn't synced
// yet: the create must carry the attribution (send-time read) so Cloud attributes it
// in one shot, without waiting on a follow-up payment.attribute op. Workstation route.
func TestHandlePaymentCreate_CarriesClaimedTillSession(t *testing.T) {
	var gotBody map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		gotBody = map[string]any{}
		_ = json.Unmarshal(raw, &gotBody)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"data":{"payment_id":"cloud-pay-1"}}`))
	}))
	t.Cleanup(srv.Close)

	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	// Order synced (cloud_id) so the create isn't stalled on the order dependency.
	if _, err := db.Exec(`INSERT INTO orders (id, order_number, status, cloud_id) VALUES ('ord-1',1,'pending','cloud-ord')`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	// Payment already stamped with a claimed session; its create is only now syncing.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, till_session_id)
		VALUES ('pay-local','ord-1','cash',500,'confirmed','ik','sess-9')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}

	engine := NewSyncEngine(db, srv.URL, nil)
	if _, _, err := engine.handlePaymentCreate(t.Context(), "pay-local", map[string]any{
		"target":          "workstation",
		"bearer_token":    "ws-token",
		"idempotency_key": "idem-1",
		"order_id":        "ord-1",
		"payment_method":  "cash",
		"amount":          500,
	}); err != nil {
		t.Fatalf("handlePaymentCreate: %v", err)
	}
	if gotBody["till_session_id"] != "sess-9" {
		t.Errorf("create body till_session_id = %v, want sess-9 (carried claimed attribution)", gotBody["till_session_id"])
	}
}

func TestHandlePaymentAttribute_TransientWhenPaymentNotSynced(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	// Payment exists locally but has NOT synced UP (no cloud_id) → the attribution
	// must wait for payment.create, exactly like confirm/fail.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key)
		VALUES ('pay-local','o-1','cash',1000,'confirmed','ik')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}

	engine := NewSyncEngine(db, "http://127.0.0.1:0", nil)

	_, retryable, err := engine.handlePaymentAttribute(t.Context(), "pay-local", map[string]any{
		"bearer_token":    "cashier-token",
		"till_session_id": "sess-2",
	})
	if err == nil || !retryable {
		t.Fatalf("want transient retry, got err=%v retryable=%v", err, retryable)
	}
	if !errors.Is(err, errDependencyNotReady) {
		t.Errorf("want errDependencyNotReady, got %v", err)
	}
}

// TestPaymentAttribute_HandlerRegistered guards the wiring: pushToCloud dispatches
// "payment.attribute" to a handler (a missing entry would silently drain the queue
// row as a no-op, dropping the gap claim on the floor).
func TestPaymentAttribute_HandlerRegistered(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	engine := NewSyncEngine(db, "http://127.0.0.1:0", nil)
	if _, ok := engine.handlers["payment.attribute"]; !ok {
		t.Fatal("payment.attribute handler not registered")
	}
}
