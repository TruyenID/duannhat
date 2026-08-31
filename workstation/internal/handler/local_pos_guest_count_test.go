package handler

import (
	"bytes"
	"database/sql"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// HTTP-level end-to-end: POST /api/v1/pos/orders with an EMPTY body
// (the pos-web CreateOrderDialog payload when the cashier didn't enter
// guest_count) must:
//   - return 201,
//   - emit `guest_count: null` in the JSON response,
//   - persist orders.guest_count as SQL NULL in SQLite.
//
// The user reported "guest_count vẫn là 1" after rebuild. This test
// proves the full handler → service → DB path returns null. If THIS
// test passes but the user still sees 1, the issue is on their device
// (stale binary, stale React Query cache, or an order created before
// the rebuild).
func TestE2E_CreateOrder_EmptyBodyKeepsGuestCountNull(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedOpenShift(t, srv)
	srv.orders = service.NewOrderEngine(db)
	srv.hub = NewHub()

	req := httptest.NewRequest("POST", "/api/v1/pos/orders",
		bytes.NewReader([]byte(`{}`)))
	rec := httptest.NewRecorder()
	srv.handleLocalPosCreateOrder(rec, req)

	if rec.Code != http.StatusCreated {
		t.Fatalf("status: want 201, got %d body=%s", rec.Code, rec.Body.String())
	}

	// 1. JSON response: guest_count must be explicit null, not 0/1.
	var env struct {
		Data map[string]any `json:"data"`
	}
	if err := json.NewDecoder(rec.Body).Decode(&env); err != nil {
		t.Fatalf("decode: %v", err)
	}
	// Map lookup must return (nil, true) — the key exists and value is null.
	gc, present := env.Data["guest_count"]
	if !present {
		t.Errorf("response missing guest_count key entirely")
	}
	if gc != nil {
		t.Errorf("guest_count in response must be null (got %v of type %T) — the user-reported bug",
			gc, gc)
	}

	// 2. DB readback — round-trip through SQLite.
	orderID, _ := env.Data["id"].(string)
	if orderID == "" {
		t.Fatal("response missing id")
	}
	var gcRaw sql.NullInt64
	if err := db.QueryRow(
		`SELECT guest_count FROM orders WHERE id = ?`, orderID,
	).Scan(&gcRaw); err != nil {
		t.Fatalf("readback: %v", err)
	}
	if gcRaw.Valid {
		t.Errorf("orders.guest_count must be SQL NULL post-empty-POST (got %d) — migration 031 path is broken",
			gcRaw.Int64)
	}
}

// Counter-test: posting an explicit positive value still round-trips so
// "no auto-default" doesn't silently swallow real input.
func TestE2E_CreateOrder_ExplicitGuestCountPersists(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedOpenShift(t, srv)
	srv.orders = service.NewOrderEngine(db)
	srv.hub = NewHub()

	req := httptest.NewRequest("POST", "/api/v1/pos/orders",
		bytes.NewReader([]byte(`{"guest_count": 6}`)))
	rec := httptest.NewRecorder()
	srv.handleLocalPosCreateOrder(rec, req)

	if rec.Code != http.StatusCreated {
		t.Fatalf("status: %d body=%s", rec.Code, rec.Body.String())
	}
	var env struct {
		Data map[string]any `json:"data"`
	}
	_ = json.NewDecoder(rec.Body).Decode(&env)
	if gc, _ := env.Data["guest_count"].(float64); int(gc) != 6 {
		t.Errorf("explicit 6 must round-trip in response, got %v", env.Data["guest_count"])
	}
	orderID := env.Data["id"].(string)
	var got int
	_ = db.QueryRow(`SELECT guest_count FROM orders WHERE id = ?`, orderID).Scan(&got)
	if got != 6 {
		t.Errorf("orders.guest_count want 6, got %d", got)
	}
}

// Posting an explicit zero must coerce to NULL (Cloud spec minimum:1).
func TestE2E_CreateOrder_ZeroGuestCountCoercesToNull(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedOpenShift(t, srv)
	srv.orders = service.NewOrderEngine(db)
	srv.hub = NewHub()

	req := httptest.NewRequest("POST", "/api/v1/pos/orders",
		bytes.NewReader([]byte(`{"guest_count": 0}`)))
	rec := httptest.NewRecorder()
	srv.handleLocalPosCreateOrder(rec, req)

	if rec.Code != http.StatusCreated {
		t.Fatalf("status: %d", rec.Code)
	}
	var env struct {
		Data map[string]any `json:"data"`
	}
	_ = json.NewDecoder(rec.Body).Decode(&env)
	if env.Data["guest_count"] != nil {
		t.Errorf("explicit 0 must coerce to null in response, got %v", env.Data["guest_count"])
	}
}
