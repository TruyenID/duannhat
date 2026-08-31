package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

// insertPaymentRow drops a raw payment row so the expiry guard can be
// exercised without driving the whole create flow. expiresAt is stored
// verbatim (pass "" for a NULL-equivalent no-expiry row).
func insertPaymentRow(t *testing.T, srv *Server, id, orderID, status string, amount int, expiresAt string) {
	t.Helper()
	var exp any
	if expiresAt != "" {
		exp = expiresAt
	}
	mustExec(t, srv.db, `
		INSERT INTO payments (id, order_id, payment_method, amount, status, expires_at, created_at, updated_at)
		VALUES (?, ?, 'card', ?, ?, ?, datetime('now'), datetime('now'))`,
		id, orderID, amount, status, exp)
}

// #562 — an abandoned non-auto-confirm payment leaves a pending row past
// its expires_at. Cloud's per-minute sweeper fails it, so the overpay
// guard self-heals; the workstation had no sweeper AND counted the
// phantom pending, so the order got stuck at `paying` (422 forever).
// sumActivePaymentsForOrder must now ignore expired pending rows.
func TestSumActivePayments_IgnoresExpiredPending(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	past := time.Now().UTC().Add(-time.Minute).Format(time.RFC3339Nano)
	future := time.Now().UTC().Add(time.Minute).Format(time.RFC3339Nano)

	// Order o-1: only an expired pending → counts as 0 (phantom dropped).
	insertPaymentRow(t, srv, "p-expired", "o-1", "pending", 1000, past)
	// Order o-2: a live pending → still blocks (guard intact).
	insertPaymentRow(t, srv, "p-live", "o-2", "pending", 1000, future)
	// Order o-3: a confirmed row that retains a stale expires_at from its
	// pending phase (confirm never clears it) → real money, must count.
	insertPaymentRow(t, srv, "p-confirmed-stale", "o-3", "confirmed", 1000, past)
	// Order o-4: a no-expiry pending (NULL) → counts (e.g. kiosk path).
	insertPaymentRow(t, srv, "p-noexpiry", "o-4", "pending", 1000, "")

	cases := []struct {
		order string
		want  int
	}{
		{"o-1", 0},
		{"o-2", 1000},
		{"o-3", 1000},
		{"o-4", 1000},
	}
	for _, c := range cases {
		got, err := srv.sumActivePaymentsForOrder(c.order)
		if err != nil {
			t.Fatalf("%s: sumActivePaymentsForOrder: %v", c.order, err)
		}
		if got != c.want {
			t.Errorf("%s: sum want %d, got %d", c.order, c.want, got)
		}
	}
}

// End-to-end: an order whose only prior payment is an expired pending
// must accept a fresh full payment (201), not 422. Pre-fix the phantom
// pending was summed and the create was rejected as overpayment.
func TestHandlePosCreatePayment_ExpiredPendingDoesNotBlock(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-stuck-1", "pm-cash-exp", "cash",
		false, true, 1000, "paying")

	// A prior non-auto-confirm payment was abandoned: full-amount pending,
	// expires_at 16 min in the past (well beyond the 15-min window).
	stale := time.Now().UTC().Add(-16 * time.Minute).Format(time.RFC3339Nano)
	insertPaymentRow(t, srv, "p-stuck", "o-stuck-1", "pending", 1000, stale)

	body := `{"payment_method_id":"pm-cash-exp","amount":1000,"idempotency_key":"k-stuck-1"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-stuck-1/payments",
		strings.NewReader(body))
	req.SetPathValue("id", "o-stuck-1")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)

	if w.Code != http.StatusCreated {
		t.Fatalf("expired pending must not block: want 201, got %d body=%s",
			w.Code, w.Body.String())
	}
}

// Control: a still-live pending (within its 15-min window) must keep
// blocking overpayment so the guard isn't defeated wholesale.
func TestHandlePosCreatePayment_LivePendingStillBlocks(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-live-1", "pm-cash-live", "cash",
		false, true, 1000, "paying")

	live := time.Now().UTC().Add(10 * time.Minute).Format(time.RFC3339Nano)
	insertPaymentRow(t, srv, "p-live-blk", "o-live-1", "pending", 1000, live)

	body := `{"payment_method_id":"pm-cash-live","amount":1000,"idempotency_key":"k-live-1"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-live-1/payments",
		strings.NewReader(body))
	req.SetPathValue("id", "o-live-1")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)

	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("live pending must still block overpay: want 422, got %d body=%s",
			w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), "exceeds") {
		t.Errorf("error body must mention exceeds: %s", w.Body.String())
	}
}
