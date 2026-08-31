package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// POS is a staff-driven counter, so a takeaway order it creates must open
// directly (status=open) — NOT the self-service `pending` used for kiosk /
// customer orders a staff member has to confirm. Otherwise the cashier can't
// check it out without first confirming, and it diverges from Cloud.
func TestE2E_CreatePosTakeaway_StartsOpen(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedOpenShift(t, srv)
	srv.orders = service.NewOrderEngine(db, 0)
	srv.hub = NewHub()

	req := httptest.NewRequest("POST", "/api/v1/pos/orders",
		bytes.NewReader([]byte(`{"order_type":"takeaway","customer_takeaway_name":"An"}`)))
	rec := httptest.NewRecorder()
	srv.handleLocalPosCreateOrder(rec, req)

	if rec.Code != http.StatusCreated {
		t.Fatalf("status: want 201, got %d body=%s", rec.Code, rec.Body.String())
	}
	var env struct {
		Data map[string]any `json:"data"`
	}
	if err := json.NewDecoder(rec.Body).Decode(&env); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if got := env.Data["status"]; got != "open" {
		t.Errorf("POS takeaway want status=open, got %v", got)
	}
	if env.Data["order_type"] != "takeaway" {
		t.Errorf("order_type should stay takeaway, got %v", env.Data["order_type"])
	}

	// DB readback — the persisted row is open too.
	orderID, _ := env.Data["id"].(string)
	var status string
	if err := db.QueryRow(`SELECT status FROM orders WHERE id = ?`, orderID).Scan(&status); err != nil {
		t.Fatalf("readback: %v", err)
	}
	if status != "open" {
		t.Errorf("persisted POS takeaway status want open, got %q", status)
	}
}
