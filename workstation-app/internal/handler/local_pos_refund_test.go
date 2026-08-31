package handler

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Refund handler regressions for #520 (Bug A: no auto-reopen) and #533
// (idempotency + payment-status guard).

// seedRefundOrder creates a fully-paid, closed order + a payment eligible for
// refund. Returns the server, order id, and payment id.
func seedRefundOrder(t *testing.T, paymentStatus string, total, paid int) (*Server, string, string) {
	t.Helper()
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.orders = service.NewOrderEngine(srv.db, 10)
	srv.hub = NewHub()

	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	mustExec(t, srv.db,
		`UPDATE orders SET total_amount = ?, paid_amount = ?, status = 'closed',
		 closed_at = '2026-07-08T00:00:00Z' WHERE id = ?`, total, paid, o.ID)
	payID := "pay-refund-1"
	mustExec(t, srv.db,
		`INSERT INTO payments (id, order_id, amount, refunded_amount, status, payment_method)
		 VALUES (?, ?, ?, 0, ?, 'cash')`, payID, o.ID, paid, paymentStatus)
	return srv, o.ID, payID
}

func postRefund(t *testing.T, srv *Server, orderID, payID, idemKey, body string) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest("POST",
		"/api/v1/pos/orders/"+orderID+"/payments/"+payID+"/refund",
		bytes.NewReader([]byte(body)))
	if idemKey != "" {
		req.Header.Set("Idempotency-Key", idemKey)
	}
	req.SetPathValue("id", orderID)
	req.SetPathValue("paymentId", payID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosRefundPayment(rec, req)
	return rec
}

func orderColsRefund(t *testing.T, srv *Server, orderID string) (status string, paid int, closedAt string) {
	t.Helper()
	if err := srv.db.QueryRow(
		`SELECT status, paid_amount, COALESCE(closed_at,'') FROM orders WHERE id = ?`, orderID,
	).Scan(&status, &paid, &closedAt); err != nil {
		t.Fatal(err)
	}
	return
}

func countRefundRows(t *testing.T, srv *Server, payID string) int {
	t.Helper()
	var n int
	if err := srv.db.QueryRow(
		`SELECT COUNT(*) FROM payment_refunds WHERE payment_id = ?`, payID).Scan(&n); err != nil {
		t.Fatal(err)
	}
	return n
}

// #533(A) — a missing Idempotency-Key is rejected; no refund is applied.
func TestRefund_RequiresIdempotencyKey(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "confirmed", 1000, 1000)
	rec := postRefund(t, srv, orderID, payID, "", `{"amount":300}`)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("want 400 without Idempotency-Key, got %d — %s", rec.Code, rec.Body.String())
	}
	if n := countRefundRows(t, srv, payID); n != 0 {
		t.Fatalf("no refund row must be written, got %d", n)
	}
	if _, paid, _ := orderColsRefund(t, srv, orderID); paid != 1000 {
		t.Fatalf("paid_amount must be unchanged, got %d", paid)
	}
}

// #533(A) — a replayed Idempotency-Key applies the partial refund exactly once.
func TestRefund_IdempotentReplay(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "confirmed", 1000, 1000)

	rec1 := postRefund(t, srv, orderID, payID, "refund-key-1", `{"amount":300}`)
	if rec1.Code != http.StatusOK {
		t.Fatalf("first refund want 200, got %d — %s", rec1.Code, rec1.Body.String())
	}
	rec2 := postRefund(t, srv, orderID, payID, "refund-key-1", `{"amount":300}`)
	if rec2.Code != http.StatusOK {
		t.Fatalf("replay want 200, got %d — %s", rec2.Code, rec2.Body.String())
	}
	if n := countRefundRows(t, srv, payID); n != 1 {
		t.Fatalf("replay must NOT double-refund; want 1 refund row, got %d", n)
	}
	if _, paid, _ := orderColsRefund(t, srv, orderID); paid != 700 {
		t.Fatalf("paid_amount must drop by 300 exactly once; want 700, got %d", paid)
	}
}

// #533(B) — a pending payment (money never collected) cannot be refunded.
func TestRefund_RejectsNonSucceededPayment(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "pending", 1000, 1000)
	rec := postRefund(t, srv, orderID, payID, "refund-key-2", `{"amount":300}`)
	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("want 422 refunding a pending payment, got %d — %s", rec.Code, rec.Body.String())
	}
	if n := countRefundRows(t, srv, payID); n != 0 {
		t.Fatalf("no refund row for pending payment, got %d", n)
	}
	if _, paid, _ := orderColsRefund(t, srv, orderID); paid != 1000 {
		t.Fatalf("paid_amount must be unchanged, got %d", paid)
	}
}

// #520 Bug A — a partial refund on a closed order reduces paid_amount but must
// NOT re-open the order: status stays `closed` and closed_at is preserved,
// matching Cloud's refundPayment behavior.
func TestRefund_DoesNotReopenClosedOrder(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "confirmed", 1000, 1000)

	rec := postRefund(t, srv, orderID, payID, "refund-key-3", `{"amount":400}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("refund want 200, got %d — %s", rec.Code, rec.Body.String())
	}
	status, paid, closedAt := orderColsRefund(t, srv, orderID)
	if status != "closed" {
		t.Fatalf("order must stay closed after refund, got %q", status)
	}
	if closedAt == "" {
		t.Fatalf("closed_at must be preserved, got empty")
	}
	if paid != 600 {
		t.Fatalf("paid_amount want 600, got %d", paid)
	}
}
