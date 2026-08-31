package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// Seeds a payment_methods row + an order so the full payment-lifecycle
// path can be exercised. amount controls the order's total_amount used
// by the overpayment / auto-close gates.
func seedOrderAndMethod(t *testing.T, srv *Server, orderID, methodID, code string,
	requiresTendered, autoConfirm bool, total int, orderStatus string,
) {
	t.Helper()
	// plan-044 — every payment test seeds an order to pay; the LAN POS payment
	// gate now requires an in-progress shift, so seed one here (single point).
	seedOpenShift(t, srv)
	rt, ac := 0, 0
	if requiresTendered {
		rt = 1
	}
	if autoConfirm {
		ac = 1
	}
	mustExec(t, srv.db,
		`INSERT INTO payment_methods (id, code, name, is_active, requires_tendered, is_auto_confirm)
		 VALUES (?, ?, ?, 1, ?, ?)`,
		methodID, code, code, rt, ac)
	mustExec(t, srv.db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    total_amount, branch_id, brand_id, organization_id)
		VALUES (?, ?, 'dine_in', ?, datetime('now'), ?, 'br-1', 'bd-1', 'or-1')`,
		orderID, "C-"+orderID, orderStatus, total)
}

// Pos-web sends payment_method_id (UUID). Pre-fix workstation required
// `payment_method` enum and rejected the request with "amount and
// payment_method required" — the PaymentDialog showed it as a generic
// error and staff retry-looped. The handler now resolves the method
// row, derives code + is_auto_confirm from it.
func TestHandlePosCreatePayment_AcceptsPaymentMethodID(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-id-1", "pm-cash-id", "cash",
		false, /* requires_tendered=false so happy-path is short */
		true /* auto_confirm */, 1000, "checkout")

	body := `{"payment_method_id":"pm-cash-id","amount":1000,"idempotency_key":"k-1"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-id-1/payments",
		strings.NewReader(body))
	req.SetPathValue("id", "o-id-1")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)

	if w.Code != http.StatusCreated {
		t.Fatalf("want 201, got %d body=%s", w.Code, w.Body.String())
	}
	var resp struct {
		Data struct {
			PaymentMethodID string `json:"payment_method_id"`
			PaymentMethod   string `json:"payment_method"`
			Status          string `json:"status"`
			PaidAt          string `json:"paid_at"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}
	if resp.Data.PaymentMethodID != "pm-cash-id" {
		t.Errorf("payment_method_id want pm-cash-id, got %q", resp.Data.PaymentMethodID)
	}
	if resp.Data.PaymentMethod != "cash" {
		t.Errorf("payment_method code want cash, got %q", resp.Data.PaymentMethod)
	}
	// Auto-confirm path: succeeded + paid_at stamped.
	if resp.Data.Status != "confirmed" && resp.Data.Status != "succeeded" {
		t.Errorf("auto-confirm status want confirmed/succeeded, got %q", resp.Data.Status)
	}
	if resp.Data.PaidAt == "" {
		t.Errorf("paid_at must be stamped for auto-confirm")
	}
}

// Cash → requires_tendered=1 → without tendered_amount the handler
// must 422 with the exact Cloud message so PaymentDialog renders the
// inline "Khách đưa" field.
func TestHandlePosCreatePayment_CashRequiresTenderedAmount(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-cash-1", "pm-cash-2", "cash",
		true /* requires_tendered */, true, 1000, "checkout")

	body := `{"payment_method_id":"pm-cash-2","amount":1000,"idempotency_key":"k-cash-1"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-cash-1/payments",
		strings.NewReader(body))
	req.SetPathValue("id", "o-cash-1")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)

	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("missing tendered: want 422, got %d body=%s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), "Tendered amount") {
		t.Errorf("error body must mention Tendered amount: %s", w.Body.String())
	}
}

// Tendered ≥ amount → change_amount is computed and the payment row
// goes through. Mirrors Cloud's `change_amount = tendered − amount − tip`.
func TestHandlePosCreatePayment_CashComputesChangeAmount(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-cash-2", "pm-cash-3", "cash",
		true, true, 7400, "checkout")

	body := `{"payment_method_id":"pm-cash-3","amount":7400,"tendered_amount":10000,"idempotency_key":"k-cash-2"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-cash-2/payments",
		strings.NewReader(body))
	req.SetPathValue("id", "o-cash-2")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)

	if w.Code != http.StatusCreated {
		t.Fatalf("want 201, got %d body=%s", w.Code, w.Body.String())
	}
	var resp struct {
		Data struct {
			TenderedAmount *int `json:"tendered_amount"`
			ChangeAmount   *int `json:"change_amount"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}
	if resp.Data.TenderedAmount == nil || *resp.Data.TenderedAmount != 10000 {
		t.Errorf("tendered want 10000, got %v", resp.Data.TenderedAmount)
	}
	if resp.Data.ChangeAmount == nil || *resp.Data.ChangeAmount != 2600 {
		t.Errorf("change want 2600 (10000-7400), got %v", resp.Data.ChangeAmount)
	}
}

// Auto-confirm + total payment fully covered → order transitions to
// closed AND closed_at stamps + paid_amount updates. The screenshot's
// happy-path flow lands here.
func TestHandlePosCreatePayment_FullPayment_AutoClosesOrder(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-close-1", "pm-cash-4", "cash",
		false, true, 5000, "checkout")

	body := `{"payment_method_id":"pm-cash-4","amount":5000,"idempotency_key":"k-close-1"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-close-1/payments",
		strings.NewReader(body))
	req.SetPathValue("id", "o-close-1")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)

	if w.Code != http.StatusCreated {
		t.Fatalf("want 201, got %d body=%s", w.Code, w.Body.String())
	}

	var status, closedAt string
	var paid int
	if err := db.QueryRow(
		`SELECT status, COALESCE(closed_at,''), COALESCE(paid_amount,0)
		   FROM orders WHERE id = 'o-close-1'`,
	).Scan(&status, &closedAt, &paid); err != nil {
		t.Fatal(err)
	}
	if status != "closed" {
		t.Errorf("auto-close: order.status want closed, got %q", status)
	}
	if closedAt == "" {
		t.Errorf("auto-close: closed_at must be stamped")
	}
	if paid != 5000 {
		t.Errorf("paid_amount want 5000, got %d", paid)
	}
}

// Partial payment (one of two split bills) keeps order at paying, NOT
// closed. The second payment then closes it.
func TestHandlePosCreatePayment_PartialPayment_KeepsPaying(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-part-1", "pm-cash-5", "cash",
		false, true, 5000, "checkout")

	// First payment 2000 — partial.
	body1 := `{"payment_method_id":"pm-cash-5","amount":2000,"idempotency_key":"k-part-1"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-part-1/payments",
		strings.NewReader(body1))
	req.SetPathValue("id", "o-part-1")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)
	if w.Code != http.StatusCreated {
		t.Fatalf("first part want 201, got %d body=%s", w.Code, w.Body.String())
	}

	var status string
	db.QueryRow(`SELECT status FROM orders WHERE id='o-part-1'`).Scan(&status)
	if status != "paying" {
		t.Errorf("after partial: status want paying, got %q", status)
	}

	// Second payment 3000 — completes.
	body2 := `{"payment_method_id":"pm-cash-5","amount":3000,"idempotency_key":"k-part-2"}`
	req = httptest.NewRequest("POST", "/api/v1/pos/orders/o-part-1/payments",
		strings.NewReader(body2))
	req.SetPathValue("id", "o-part-1")
	w = httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)
	if w.Code != http.StatusCreated {
		t.Fatalf("second part want 201, got %d body=%s", w.Code, w.Body.String())
	}
	db.QueryRow(`SELECT status FROM orders WHERE id='o-part-1'`).Scan(&status)
	if status != "closed" {
		t.Errorf("after full payment: status want closed, got %q", status)
	}
}

// Overpayment guard: two confirmed payments must not exceed total.
// Cloud rejects with 422 "exceeds the outstanding order balance."
func TestHandlePosCreatePayment_OverpaymentRejected(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-over-1", "pm-cash-6", "cash",
		false, true, 1000, "checkout")

	body1 := `{"payment_method_id":"pm-cash-6","amount":1000,"idempotency_key":"k-ov-1"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-over-1/payments",
		strings.NewReader(body1))
	req.SetPathValue("id", "o-over-1")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)
	if w.Code != http.StatusCreated {
		t.Fatalf("first want 201, got %d", w.Code)
	}

	body2 := `{"payment_method_id":"pm-cash-6","amount":500,"idempotency_key":"k-ov-2"}`
	req = httptest.NewRequest("POST", "/api/v1/pos/orders/o-over-1/payments",
		strings.NewReader(body2))
	req.SetPathValue("id", "o-over-1")
	w = httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)
	if w.Code != http.StatusUnprocessableEntity {
		t.Errorf("overpayment: want 422, got %d body=%s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), "exceeds") {
		t.Errorf("error body must mention exceeds: %s", w.Body.String())
	}
}

// expected_total_amount drift guard — when the snapshot pos-web took
// for split-bill no longer matches the live order total, 422 with the
// distinctive split_bill_total_drift code so PaymentDialog can re-fetch.
func TestHandlePosCreatePayment_SplitBillTotalDrift(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-drift-1", "pm-cash-7", "cash",
		false, true, 1000, "checkout")

	body := `{"payment_method_id":"pm-cash-7","amount":500,"expected_total_amount":7400,"idempotency_key":"k-d-1"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-drift-1/payments",
		strings.NewReader(body))
	req.SetPathValue("id", "o-drift-1")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)

	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("drift: want 422, got %d body=%s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), "split_bill_total_drift") {
		t.Errorf("error body must include split_bill_total_drift code: %s", w.Body.String())
	}
}

// Non-auto-confirm methods (stripe/terminal flow) stay pending +
// expires_at gets stamped 15 min out. The order moves checkout→paying
// but does NOT close.
func TestHandlePosCreatePayment_TerminalFlow_StaysPending(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-term-1", "pm-stripe", "stripe",
		false, false /* is_auto_confirm=0 */, 1000, "checkout")

	body := `{"payment_method_id":"pm-stripe","amount":1000,"idempotency_key":"k-t-1"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-term-1/payments",
		strings.NewReader(body))
	req.SetPathValue("id", "o-term-1")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)
	if w.Code != http.StatusCreated {
		t.Fatalf("want 201, got %d body=%s", w.Code, w.Body.String())
	}

	var status, paidAt, expiresAt string
	db.QueryRow(`
		SELECT status, COALESCE(paid_at,''), COALESCE(expires_at,'')
		FROM payments WHERE order_id='o-term-1' LIMIT 1
	`).Scan(&status, &paidAt, &expiresAt)
	if status != "pending" {
		t.Errorf("terminal flow status want pending, got %q", status)
	}
	if paidAt != "" {
		t.Errorf("paid_at must NOT be stamped while pending, got %q", paidAt)
	}
	if expiresAt == "" {
		t.Errorf("expires_at must be stamped for terminal flow")
	}

	var orderStatus string
	db.QueryRow(`SELECT status FROM orders WHERE id='o-term-1'`).Scan(&orderStatus)
	if orderStatus != "paying" {
		t.Errorf("order must be in paying (not closed) for terminal flow, got %q", orderStatus)
	}
}
