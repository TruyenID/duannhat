package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// TestCreateOrderEnqueuesWithWorkstationToken locks the contract that the
// LAN POST /api/orders handler enqueues the sync UP entry using the
// WORKSTATION's own device_token from settings — NOT relaying the caller's
// kiosk-typed Bearer.
//
// Why: Cloud POST /api/v1/workstation/orders is gated by
// `device.auth:workstation`. Relaying a kiosk-token would 403 in production.
// The original `extractBearer(r)` design was a copy-paste from the payment
// path where Cloud /kiosk/payments accepts kiosk tokens — orders are
// workstation-scoped.
func TestCreateOrderEnqueuesWithWorkstationToken(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"cloud-1"}}`))
	}))
	defer cloud.Close()

	s, db := newServerWithAuth(t, cloud.URL)

	// Seed workstation's own device token + minimal order engine
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token', 'WS-WORKSTATION-TOKEN')`); err != nil {
		t.Fatalf("seed device_token: %v", err)
	}
	s.orders = service.NewOrderEngine(db, 10)
	s.hub = NewHub()

	mux := http.NewServeMux()
	s.registerRoutes(mux)

	body, _ := json.Marshal(map[string]any{
		"table_number":   "A1",
		"customer_count": 2,
		"notes":          "Không hành",
		"items":          []any{},
	})
	req := httptest.NewRequest("POST", "/api/orders", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	// POST /api/orders is a loopback-only desktop-UI route (#83), so the
	// request must originate from localhost. It still carries a caller token
	// to prove the handler ignores it and uses the workstation's own token.
	req.RemoteAddr = "127.0.0.1:54321"
	req.Header.Set("Authorization", "Bearer KIOSK-TOKEN-MUST-NOT-LEAK")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusCreated {
		t.Fatalf("expected 201, got %d body=%s", rec.Code, rec.Body.String())
	}

	// Read sync_queue row that was enqueued
	var payload string
	err := db.QueryRow(`SELECT payload FROM sync_queue WHERE entity_type='order' AND operation='create' ORDER BY id DESC LIMIT 1`).Scan(&payload)
	if err != nil {
		t.Fatalf("read sync_queue: %v", err)
	}

	var parsed map[string]any
	if err := json.Unmarshal([]byte(payload), &parsed); err != nil {
		t.Fatalf("parse payload: %v", err)
	}

	bearer, _ := parsed["bearer_token"].(string)
	if bearer != "WS-WORKSTATION-TOKEN" {
		t.Errorf("expected bearer_token=WS-WORKSTATION-TOKEN (workstation's own), got %q", bearer)
	}
	if bearer == "KIOSK-TOKEN-MUST-NOT-LEAK" {
		t.Error("kiosk token leaked into sync_queue payload — workstation must use its own token for Cloud /workstation/orders")
	}
}
