package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Plan-044 R2 (T-R2.D2.5) — an order sync-UP must NOT carry till_session_id. Orders
// don't own cashier-shift attribution in R2 (only payments do); Cloud stamps its own
// open session at create per R6. A local workstation session id landing on Cloud's
// display-only column would be meaningless (different id space when workstation is
// authoritative). Guard: the enqueued order.create payload's `order` shape has no
// till_session_id key.
func TestCreateOrder_SyncUpOmitsTillSessionID(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"cloud-1"}}`))
	}))
	defer cloud.Close()

	s, db := newServerWithAuth(t, cloud.URL)
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token', 'WS-TOKEN')`); err != nil {
		t.Fatalf("seed device_token: %v", err)
	}
	s.orders = service.NewOrderEngine(db)
	s.hub = NewHub()

	mux := http.NewServeMux()
	s.registerRoutes(mux)

	body, _ := json.Marshal(map[string]any{
		"table_number":   "A1",
		"customer_count": 2,
		"items":          []any{},
	})
	req := httptest.NewRequest("POST", "/api/orders", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.RemoteAddr = "127.0.0.1:54321"
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusCreated {
		t.Fatalf("expected 201, got %d body=%s", rec.Code, rec.Body.String())
	}

	var payload string
	if err := db.QueryRow(`SELECT payload FROM sync_queue WHERE entity_type='order' AND operation='create' ORDER BY id DESC LIMIT 1`).Scan(&payload); err != nil {
		t.Fatalf("read sync_queue: %v", err)
	}
	var parsed map[string]any
	if err := json.Unmarshal([]byte(payload), &parsed); err != nil {
		t.Fatalf("decode payload: %v", err)
	}

	// Neither the envelope nor the order shape may carry till_session_id.
	if _, leaked := parsed["till_session_id"]; leaked {
		t.Errorf("order.create envelope leaked till_session_id: %v", parsed["till_session_id"])
	}
	order, ok := parsed["order"].(map[string]any)
	if !ok {
		t.Fatalf("order shape missing from payload: %v", parsed)
	}
	if _, leaked := order["till_session_id"]; leaked {
		t.Errorf("order shape leaked till_session_id: %v", order["till_session_id"])
	}
}
