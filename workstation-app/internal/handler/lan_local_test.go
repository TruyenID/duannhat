package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestLANHealthIsPublicAndReturnsStatus(t *testing.T) {
	s, _ := newServerWithAuth(t, "") // no Cloud configured — health doesn't need it

	mux := http.NewServeMux()
	mux.HandleFunc("GET /api/lan/health", s.handleLANHealth)

	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, httptest.NewRequest("GET", "/api/lan/health", nil))

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", rec.Code)
	}

	var resp map[string]any
	if err := json.NewDecoder(rec.Body).Decode(&resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if resp["status"] != "ok" {
		t.Errorf("expected status=ok, got %v", resp["status"])
	}
	if _, ok := resp["server_time"]; !ok {
		t.Error("expected server_time field")
	}
}

func TestLANPrintReceiptRequiresAuth(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// No Authorization header
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, httptest.NewRequest("POST", "/api/lan/print/payment-receipt", nil))

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("expected 401, got %d", rec.Code)
	}
}

func TestLANPrintReceiptRequiresOrderID(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("POST", "/api/lan/print/payment-receipt", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusBadRequest {
		t.Errorf("expected 400 (missing order_id), got %d", rec.Code)
	}
}

func TestLANPrintReceiptBestEffortWhenNoPrinter(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// orders/devices engines are nil in this harness → reprint is a no-op 200,
	// never a panic.
	req := httptest.NewRequest("POST", "/api/lan/print/payment-receipt",
		strings.NewReader(`{"order_id":"o-1"}`))
	req.Header.Set("Authorization", "Bearer kiosk-token")
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("expected 200 (best-effort no-op), got %d body=%s", rec.Code, rec.Body.String())
	}
}
