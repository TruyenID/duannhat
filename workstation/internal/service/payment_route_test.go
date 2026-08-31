package service

// Routing test for paymentRouteBase — POS-originated payments must POST to
// /api/v1/workstation/payments (gated by device.auth:workstation, accepts
// the workstation's own bearer), while kiosk-originated and legacy/unmarked
// payments stay on /api/v1/kiosk/payments. A regression here silently
// flips one terminal class to the wrong cloud endpoint and 403s under the
// existing AuthenticateDevice middleware.

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// TestTransitionPaymentCloud_ForwardsTerminalFields verifies the A6 confirm/fail
// contract reaches Cloud: confirm forwards terminal_ref + terminal_data
// (decoded to an object), fail adds reason + error_code.
func TestTransitionPaymentCloud_ForwardsTerminalFields(t *testing.T) {
	var gotBody map[string]any
	var gotPath string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		raw, _ := io.ReadAll(r.Body)
		gotBody = map[string]any{}
		_ = json.Unmarshal(raw, &gotBody)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"data":{"id":"cloud-payment-1"}}`))
	}))
	t.Cleanup(srv.Close)

	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	// cloud_id must exist or transitionPaymentCloud short-circuits as transient.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, cloud_id) VALUES ('pay-1','o-1','card',1000,'pending','ik', 'cloud-pay-1')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}

	engine := NewSyncEngine(db, srv.URL, nil)

	// confirm
	confirmPayload := map[string]any{
		"bearer_token":  "kiosk-token",
		"payment_id":    "pay-1",
		"terminal_ref":  "TXN-1",
		"terminal_data": `{"approval":"A1"}`,
	}
	if _, _, err := engine.handlePaymentConfirm(t.Context(), "pay-1", confirmPayload); err != nil {
		t.Fatalf("handlePaymentConfirm: %v", err)
	}
	if !strings.HasSuffix(gotPath, "/cloud-pay-1/confirm") {
		t.Errorf("confirm path = %q", gotPath)
	}
	if gotBody["terminal_ref"] != "TXN-1" {
		t.Errorf("confirm terminal_ref = %v", gotBody["terminal_ref"])
	}
	if td, ok := gotBody["terminal_data"].(map[string]any); !ok || td["approval"] != "A1" {
		t.Errorf("confirm terminal_data not forwarded as object: %v", gotBody["terminal_data"])
	}

	// fail
	failPayload := map[string]any{
		"bearer_token": "kiosk-token",
		"payment_id":   "pay-1",
		"reason":       "declined",
		"error_code":   "51",
	}
	if _, _, err := engine.handlePaymentFail(t.Context(), "pay-1", failPayload); err != nil {
		t.Fatalf("handlePaymentFail: %v", err)
	}
	if !strings.HasSuffix(gotPath, "/cloud-pay-1/fail") {
		t.Errorf("fail path = %q", gotPath)
	}
	if gotBody["reason"] != "declined" || gotBody["error_code"] != "51" {
		t.Errorf("fail body missing reason/error_code: %v", gotBody)
	}
}

func TestPaymentRouteBase_PosTargetsWorkstationEndpoint(t *testing.T) {
	payload := map[string]any{"target": "workstation"}
	got := paymentRouteBase(payload)
	if got != "/api/v1/workstation/payments" {
		t.Errorf("pos route: want /api/v1/workstation/payments, got %q", got)
	}
}

func TestPaymentRouteBase_KioskDefaultsToKioskEndpoint(t *testing.T) {
	cases := []struct {
		name    string
		payload map[string]any
	}{
		{"kiosk explicit", map[string]any{"target": "kiosk"}},
		{"missing target", map[string]any{}},
		{"unknown target", map[string]any{"target": "tms"}},
		{"non-string target", map[string]any{"target": 1}},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := paymentRouteBase(c.payload)
			if got != "/api/v1/kiosk/payments" {
				t.Errorf("want /api/v1/kiosk/payments, got %q", got)
			}
		})
	}
}

// TestHandlePaymentCreate_PosFlowHitsWorkstationRoute verifies the routing
// fix end-to-end against an httptest server: a POS-flagged payload reaches
// /api/v1/workstation/payments, NOT /api/v1/kiosk/payments. Catches a
// regression where the route selector is bypassed (e.g. someone hardcodes
// the kiosk URL again).
func TestHandlePaymentCreate_PosFlowHitsWorkstationRoute(t *testing.T) {
	var gotPath string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"data":{"id":"cloud-payment-1"}}`))
	}))
	t.Cleanup(srv.Close)

	engine := &SyncEngine{
		cloudURL:   srv.URL,
		httpClient: srv.Client(),
	}

	payload := map[string]any{
		"target":          "workstation",
		"bearer_token":    "ws-token",
		"idempotency_key": "idem-1",
		"order_id":        "ord-1",
		"payment_method":  "cash",
		"amount":          1500,
	}

	_, _, err := engine.handlePaymentCreate(t.Context(), "pay-1", payload)
	if err != nil {
		t.Fatalf("handlePaymentCreate: %v", err)
	}
	if !strings.HasSuffix(gotPath, "/api/v1/workstation/payments") {
		t.Errorf("path: want suffix /api/v1/workstation/payments, got %q", gotPath)
	}
}

func TestHandlePaymentCreate_KioskFlowHitsKioskRoute(t *testing.T) {
	var gotPath string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"data":{"id":"cloud-payment-1"}}`))
	}))
	t.Cleanup(srv.Close)

	engine := &SyncEngine{
		cloudURL:   srv.URL,
		httpClient: srv.Client(),
	}

	payload := map[string]any{
		// no target → kiosk default
		"bearer_token":    "kiosk-token",
		"idempotency_key": "idem-1",
		"order_id":        "ord-1",
		"payment_method":  "card",
		"amount":          2000,
	}

	_, _, err := engine.handlePaymentCreate(t.Context(), "pay-1", payload)
	if err != nil {
		t.Fatalf("handlePaymentCreate: %v", err)
	}
	if !strings.HasSuffix(gotPath, "/api/v1/kiosk/payments") {
		t.Errorf("path: want suffix /api/v1/kiosk/payments, got %q", gotPath)
	}
}

// TestHandlePaymentCreate_ResolvesOrderCloudID is the regression for "paid
// amount under WS never syncs to Cloud": a WS-created order gets a different
// cloud_id once it syncs up, so the payment push MUST send the order's cloud_id
// — not the local order id Cloud doesn't recognise.
func TestHandlePaymentCreate_ResolvesOrderCloudID(t *testing.T) {
	var gotBody map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		gotBody = map[string]any{}
		_ = json.Unmarshal(raw, &gotBody)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"data":{"id":"cloud-payment-1"}}`))
	}))
	t.Cleanup(srv.Close)

	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	// WS-created order: local id "local-ord", Cloud assigned "cloud-ord".
	if _, err := db.Exec(`INSERT INTO orders (id, order_number, status, cloud_id) VALUES ('local-ord', 1, 'pending', 'cloud-ord')`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	engine := NewSyncEngine(db, srv.URL, nil)

	payload := map[string]any{
		"bearer_token":    "kiosk-token",
		"idempotency_key": "idem-1",
		"order_id":        "local-ord",
		"payment_method":  "cash",
		"amount":          805,
	}
	if _, _, err := engine.handlePaymentCreate(t.Context(), "pay-1", payload); err != nil {
		t.Fatalf("handlePaymentCreate: %v", err)
	}
	if gotBody["order_id"] != "cloud-ord" {
		t.Errorf("Cloud received order_id = %v, want cloud-ord (the order's cloud_id)", gotBody["order_id"])
	}

	// Order with NO cloud_id yet → transient retry (wait for the order to sync).
	if _, err := db.Exec(`INSERT INTO orders (id, order_number, status) VALUES ('unsynced', 2, 'pending')`); err != nil {
		t.Fatalf("seed unsynced order: %v", err)
	}
	_, retryable, err := engine.handlePaymentCreate(t.Context(), "pay-2", map[string]any{
		"order_id": "unsynced", "payment_method": "cash", "amount": 100,
	})
	if err == nil || !retryable {
		t.Errorf("unsynced order: want transient retry, got err=%v retryable=%v", err, retryable)
	}
}
