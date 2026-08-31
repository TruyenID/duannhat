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

// #555 M4 — a partial refund keeps the payment `succeeded` and only bumps
// refunded_amount, so the overpay guard must count the NET amount. Pre-fix
// it summed gross, and re-collecting the refunded portion 422'd forever.
func TestSumActivePayments_SubtractsPartialRefund(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")

	insertPaymentRow(t, srv, "p-partial", "o-refund-1", "succeeded", 1000, "")
	mustExec(t, srv.db,
		`UPDATE payments SET refunded_amount = 400 WHERE id = 'p-partial'`)

	got, err := srv.sumActivePaymentsForOrder("o-refund-1")
	if err != nil {
		t.Fatalf("sumActivePaymentsForOrder: %v", err)
	}
	if got != 600 {
		t.Errorf("net sum after partial refund: want 600, got %d", got)
	}
}

// End-to-end: order total 1000, paid 1000, then 400 partially refunded —
// re-collecting the 400 must pass the overpay guard (201), and collecting
// MORE than the refunded portion must still 422.
func TestHandlePosCreatePayment_PartialRefundReopensHeadroom(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-recollect-1", "pm-cash-ref", "cash",
		false, true, 1000, "paying")

	insertPaymentRow(t, srv, "p-refunded-part", "o-recollect-1", "succeeded", 1000, "")
	mustExec(t, srv.db,
		`UPDATE payments SET refunded_amount = 400 WHERE id = 'p-refunded-part'`)

	over := `{"payment_method_id":"pm-cash-ref","amount":500,"idempotency_key":"k-rec-over"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-recollect-1/payments",
		strings.NewReader(over))
	req.SetPathValue("id", "o-recollect-1")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("amount above refunded headroom must 422, got %d body=%s",
			w.Code, w.Body.String())
	}

	body := `{"payment_method_id":"pm-cash-ref","amount":400,"idempotency_key":"k-rec-1"}`
	req = httptest.NewRequest("POST", "/api/v1/pos/orders/o-recollect-1/payments",
		strings.NewReader(body))
	req.SetPathValue("id", "o-recollect-1")
	w = httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)
	if w.Code != http.StatusCreated {
		t.Fatalf("re-collecting the refunded portion must pass: want 201, got %d body=%s",
			w.Code, w.Body.String())
	}
}

// #555 M13 — a payment born pending (non-auto-confirm terminal method) must
// NOT be written into orders.paid_amount: it is in-flight, not captured, and
// a later /fail never reversed the inflation. Cloud only counts captured.
func TestHandlePosCreatePayment_PendingDoesNotInflatePaidAmount(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-m13-pend", "pm-card-m13", "card",
		false, false, 1000, "checkout")

	body := `{"payment_method_id":"pm-card-m13","amount":400,"idempotency_key":"k-m13-1"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-m13-pend/payments",
		strings.NewReader(body))
	req.SetPathValue("id", "o-m13-pend")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)
	if w.Code != http.StatusCreated {
		t.Fatalf("create: want 201, got %d body=%s", w.Code, w.Body.String())
	}

	var paid int
	var status string
	if err := srv.db.QueryRow(
		`SELECT paid_amount, status FROM orders WHERE id = 'o-m13-pend'`,
	).Scan(&paid, &status); err != nil {
		t.Fatal(err)
	}
	if paid != 0 {
		t.Errorf("pending payment must not inflate paid_amount: want 0, got %d", paid)
	}
	if status != "paying" {
		t.Errorf("order must move checkout → paying, got %q", status)
	}
}

// #555 M13 — the close decision must count captured money only. Pre-fix an
// auto-confirm cash payment that merely COMPLETED the total together with a
// live pending row closed the order on in-flight terminal money.
func TestHandlePosCreatePayment_PendingDoesNotDriveClose(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-m13-close", "pm-cash-m13", "cash",
		false, true, 1000, "paying")

	live := time.Now().UTC().Add(10 * time.Minute).Format(time.RFC3339Nano)
	insertPaymentRow(t, srv, "p-m13-pending", "o-m13-close", "pending", 400, live)

	body := `{"payment_method_id":"pm-cash-m13","amount":600,"idempotency_key":"k-m13-2"}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-m13-close/payments",
		strings.NewReader(body))
	req.SetPathValue("id", "o-m13-close")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)
	if w.Code != http.StatusCreated {
		t.Fatalf("create: want 201, got %d body=%s", w.Code, w.Body.String())
	}

	var paid int
	var status string
	if err := srv.db.QueryRow(
		`SELECT paid_amount, status FROM orders WHERE id = 'o-m13-close'`,
	).Scan(&paid, &status); err != nil {
		t.Fatal(err)
	}
	if paid != 600 {
		t.Errorf("paid_amount must be captured-only: want 600, got %d", paid)
	}
	if status != "paying" {
		t.Errorf("order must stay open on in-flight pending: want paying, got %q", status)
	}
}
