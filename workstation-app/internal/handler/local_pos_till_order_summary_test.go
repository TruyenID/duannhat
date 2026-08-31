package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Plan-044 R2 — GET /api/v1/pos/till/sessions/{id}/order-summary (local replica).

func TestOrderSummary_PaidAndUnpaidCarry(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	// Paid order: has a payment attributed to sess1.
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, status, opened_at, total_amount)
		VALUES ('paid1','WS-1','paying','2026-07-15T11:00:00Z',1000)`); err != nil {
		t.Fatalf("seed paid order: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at, till_session_id)
		VALUES ('p1','paid1','cash',1000,'confirmed','2026-07-15T11:05:00Z','sess1')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}
	// Unpaid active order: no payment → carries.
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, status, opened_at, total_amount)
		VALUES ('open1','WS-2','open','2026-07-15T11:10:00Z',500)`); err != nil {
		t.Fatalf("seed unpaid order: %v", err)
	}
	// Closed order: excluded from unpaid_carry (terminal).
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, status, opened_at, total_amount)
		VALUES ('closed1','WS-3','closed','2026-07-15T11:20:00Z',700)`); err != nil {
		t.Fatalf("seed closed order: %v", err)
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/sessions/sess1/order-summary", nil)
	req.SetPathValue("id", "sess1")
	s.handleLocalPosTillOrderSummary(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d, body %s", w.Code, w.Body.String())
	}
	var resp struct {
		Data struct {
			PaidOrdersCount  int     `json:"paid_orders_count"`
			PaidOrdersTotal  float64 `json:"paid_orders_total"`
			UnpaidCarryCount int     `json:"unpaid_carry_count"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v — body %s", err, w.Body.String())
	}
	if resp.Data.PaidOrdersCount != 1 {
		t.Errorf("paid_orders_count = %d, want 1", resp.Data.PaidOrdersCount)
	}
	if resp.Data.PaidOrdersTotal != 1000 {
		t.Errorf("paid_orders_total = %v, want 1000", resp.Data.PaidOrdersTotal)
	}
	if resp.Data.UnpaidCarryCount != 1 {
		t.Errorf("unpaid_carry_count = %d, want 1 (open1 only; closed excluded)", resp.Data.UnpaidCarryCount)
	}
}
