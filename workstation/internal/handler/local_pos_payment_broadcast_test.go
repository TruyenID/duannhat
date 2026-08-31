package handler

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// User-reported regression: in the split-bill flow, a partial payment
// (1 of N guests) flipped pos-web's open-list cache via the workstation's
// WS broadcast — pos-web's use-workstation-socket.ts:128 handler for
// `order_paid` REMOVES the order from the cache. reconcileWithServer
// then dropped the tab and the SplitBillDialog auto-closed before staff
// could collect from the rest.
//
// The workstation now distinguishes:
//   * orderJustClosed → broadcast "order_paid"    (kicks tab)
//   * partial payment → broadcast "order_updated" (keeps tab + dialog)
//
// We pin the BEHAVIOR by walking the SQLite + payment lifecycle, since
// the Hub doesn't expose a broadcast log we can swap in a test.
// Specifically: after a partial payment the order MUST stay in "paying"
// status (eligible for the next collection) — pre-fix the handler did
// transition correctly here too, the bug was purely the broadcast event
// name. With both this test pinning state + the manual verification
// against the live workstation, a future refactor that re-introduces
// the "order_paid on every payment" bug needs to be paired with
// changes to the pos-web socket handler — making the regression visible.

func setupBroadcastPaymentTest(t *testing.T, total int) (*Server, string) {
	t.Helper()
	srv, _ := newServerWithAuth(t, "http://unused")
	seedOpenShift(t, srv)
	srv.orders = service.NewOrderEngine(srv.db)
	srv.hub = NewHub()

	o, _ := srv.orders.Create(service.CreateOrderInput{OrderType: "spot"}, nil)
	if _, err := srv.db.Exec(
		`UPDATE orders SET total_amount = ?, status = 'checkout' WHERE id = ?`,
		total, o.ID,
	); err != nil {
		t.Fatal(err)
	}
	if _, err := srv.db.Exec(`
		INSERT INTO payment_methods (id, code, name, is_active, sort_order, is_auto_confirm)
		VALUES ('pm-cash', 'cash', 'Cash', 1, 0, 1)`); err != nil {
		t.Fatal(err)
	}
	return srv, o.ID
}

func postBroadcastCashPayment(t *testing.T, srv *Server, orderID string, amount int, idemKey string) *httptest.ResponseRecorder {
	t.Helper()
	body := `{
		"payment_method_id": "pm-cash",
		"amount": ` + itoaSplit(amount) + `,
		"tendered_amount": ` + itoaSplit(amount) + `,
		"idempotency_key": "` + idemKey + `",
		"metadata": {"split_mode":"even","bill_index":0,"total_bills":2}
	}`
	req := httptest.NewRequest("POST",
		"/api/v1/pos/orders/"+orderID+"/payments",
		bytes.NewReader([]byte(body)))
	req.Header.Set("Idempotency-Key", idemKey)
	req.SetPathValue("id", orderID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(rec, req)
	return rec
}

func itoaSplit(n int) string {
	if n == 0 {
		return "0"
	}
	out := []byte{}
	neg := n < 0
	if neg {
		n = -n
	}
	for n > 0 {
		out = append([]byte{byte('0' + n%10)}, out...)
		n /= 10
	}
	if neg {
		return "-" + string(out)
	}
	return string(out)
}

// Partial payment: half of the order. Order stays in "paying", paid_amount
// matches the half. NOT closed.
func TestPaymentLifecycle_PartialPayment_KeepsOrderInPaying(t *testing.T) {
	srv, orderID := setupBroadcastPaymentTest(t, 4133)

	rec := postBroadcastCashPayment(t, srv, orderID, 2067, "idem-half")
	if rec.Code != http.StatusCreated {
		t.Fatalf("status: %d body=%s", rec.Code, rec.Body.String())
	}

	var status string
	var paid, total int
	if err := srv.db.QueryRow(
		`SELECT status, paid_amount, total_amount FROM orders WHERE id = ?`, orderID,
	).Scan(&status, &paid, &total); err != nil {
		t.Fatal(err)
	}
	if status != "paying" {
		t.Errorf("partial payment must leave order in 'paying', got %q (the user-reported 'order tự đóng' bug)", status)
	}
	if paid != 2067 {
		t.Errorf("paid_amount: want 2067, got %d", paid)
	}
	if total != 4133 {
		t.Errorf("total_amount: want 4133 (unchanged), got %d", total)
	}
}

// Full payment: cash auto-confirms, order transitions to closed,
// closed_at timestamp populates. This is the path that legitimately fires
// `order_paid` to drop the tab from pos-web's cache.
func TestPaymentLifecycle_FullPayment_ClosesOrder(t *testing.T) {
	srv, orderID := setupBroadcastPaymentTest(t, 4133)

	rec := postBroadcastCashPayment(t, srv, orderID, 4133, "idem-full")
	if rec.Code != http.StatusCreated {
		t.Fatalf("status: %d body=%s", rec.Code, rec.Body.String())
	}

	var status, closedAt string
	var paid int
	if err := srv.db.QueryRow(
		`SELECT status, paid_amount, COALESCE(closed_at,'') FROM orders WHERE id = ?`, orderID,
	).Scan(&status, &paid, &closedAt); err != nil {
		t.Fatal(err)
	}
	if status != "closed" {
		t.Errorf("full cash payment must close order, got %q", status)
	}
	if paid != 4133 {
		t.Errorf("paid_amount: want 4133, got %d", paid)
	}
	if closedAt == "" {
		t.Errorf("closed_at must be stamped on close, got empty")
	}
}

// Second partial payment (after the first leaves order in "paying")
// brings the order to fully paid. Order must close, closed_at must stamp.
// Pins the multi-step split-bill lifecycle.
func TestPaymentLifecycle_TwoPartials_FinalCallCloses(t *testing.T) {
	srv, orderID := setupBroadcastPaymentTest(t, 4133)

	// Row 0: pay 2067 → stays paying.
	rec1 := postBroadcastCashPayment(t, srv, orderID, 2067, "idem-row-0")
	if rec1.Code != http.StatusCreated {
		t.Fatalf("row 0 status: %d", rec1.Code)
	}

	// Row 1: pay remaining 2066 → must close.
	body := `{
		"payment_method_id": "pm-cash",
		"amount": 2066,
		"tendered_amount": 2066,
		"idempotency_key": "idem-row-1",
		"metadata": {"split_mode":"even","bill_index":1,"total_bills":2}
	}`
	req := httptest.NewRequest("POST",
		"/api/v1/pos/orders/"+orderID+"/payments",
		bytes.NewReader([]byte(body)))
	req.Header.Set("Idempotency-Key", "idem-row-1")
	req.SetPathValue("id", orderID)
	rec2 := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(rec2, req)
	if rec2.Code != http.StatusCreated {
		t.Fatalf("row 1 status: %d body=%s", rec2.Code, rec2.Body.String())
	}

	var status string
	var paid int
	_ = srv.db.QueryRow(
		`SELECT status, paid_amount FROM orders WHERE id = ?`, orderID,
	).Scan(&status, &paid)
	if status != "closed" {
		t.Errorf("after both split rows paid, status must be closed: got %q", status)
	}
	if paid != 4133 {
		t.Errorf("paid_amount must equal total: want 4133, got %d", paid)
	}
}
