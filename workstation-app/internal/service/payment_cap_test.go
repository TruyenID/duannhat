package service

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// #538 — a payment must never be synced for MORE than the order's outstanding
// balance, or Cloud 422s ("Payment amount exceeds the outstanding order
// balance"), the order never closes on Cloud, and admin/POS stays "Chờ thanh
// toán" while the workstation shows it paid. Cap the amount to the (reconciled,
// #525) local outstanding so the order settles + propagates.
func TestHandlePaymentCreate_CapsAmountToOutstanding(t *testing.T) {
	var gotAmount float64
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		if a, ok := body["amount"].(float64); ok {
			gotAmount = a
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"data":{"id":"cloud-pay-1"}}`))
	}))
	t.Cleanup(srv.Close)

	e, db := newSyncTestEngine(t, srv.URL)
	// Order reconciled to Cloud total 2836 (#525); the payment carries the
	// pre-reconcile local total 3330 (cashier paid before reconcile landed).
	if _, err := db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status, opened_at,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, cloud_id, created_at, updated_at
	) VALUES ('o1','ORD-1',1,'dine_in','open',datetime('now'),
		2836,0,0,0,0,2836,0,'','','','cloud-o1',datetime('now'),datetime('now'))`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	payload := map[string]any{
		"target":         "workstation",
		"order_id":       "o1",
		"payment_method": "cash",
		"amount":         3330, // exceeds the 2836 outstanding
	}
	if _, _, err := e.handlePaymentCreate(t.Context(), "pay-1", payload); err != nil {
		t.Fatalf("handlePaymentCreate: %v", err)
	}
	if gotAmount != 2836 {
		t.Fatalf("amount sent to Cloud: want capped 2836, got %v", gotAmount)
	}
}

// A payment within the outstanding is forwarded verbatim (no cap).
func TestHandlePaymentCreate_KeepsAmountWithinOutstanding(t *testing.T) {
	var gotAmount float64
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		if a, ok := body["amount"].(float64); ok {
			gotAmount = a
		}
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"data":{"id":"c1"}}`))
	}))
	t.Cleanup(srv.Close)

	e, db := newSyncTestEngine(t, srv.URL)
	if _, err := db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status, opened_at,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, cloud_id, created_at, updated_at
	) VALUES ('o2','ORD-2',2,'dine_in','open',datetime('now'),
		2836,0,0,0,0,2836,0,'','','','cloud-o2',datetime('now'),datetime('now'))`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	payload := map[string]any{
		"target":         "workstation",
		"order_id":       "o2",
		"payment_method": "cash",
		"amount":         2836, // exactly the outstanding
	}
	if _, _, err := e.handlePaymentCreate(t.Context(), "pay-2", payload); err != nil {
		t.Fatalf("handlePaymentCreate: %v", err)
	}
	if gotAmount != 2836 {
		t.Fatalf("amount: want 2836 verbatim, got %v", gotAmount)
	}
}
