package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// #2580 — the 精算 / 「キット・カ最終」 screen the cashier balances the drawer against
// is served by THIS database, not Cloud. So the workstation's cash figure has to
// be right for the refund representation Cloud uses, because that representation
// can reach this table: a refund created on Cloud (pos-web's LAN→Cloud fallback
// in `web/pos/src/lib/api.ts`, or any SSO caller on
// `POST /api/v1/{pos,shops/…}/orders/{o}/payments/{p}/refund`) writes a NEW
// negative row with `refund_of_id` → the original and flips the original to
// `status='refunded'`.
//
// Production shape (人形町店, SHIFT-20260812-001): 38,490 JPY of cash sales,
// 7,980 JPY refunded, so the drawer owes 30,510 JPY.
//
// The old status filter — `status IN ('pending','confirmed','succeeded')`, blind
// to `refund_of_id` — got 25,510: it counted both negative rows but DROPPED the
// fully-refunded original, subtracting that refund twice. Off by the exact amount
// of a refund is the failure mode that stops a shift from closing.
func TestReconcileSession_CloudModelRefundRowsNetAgainstTheirOriginals(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	// An OPEN shift — the refund lands while the cashier is still on the floor,
	// which is when the wrong figure actually blocks someone. closed_at NULL means
	// reconcileSession takes now() as the upper bound.
	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, till_id, branch_id)
		VALUES ('sess-2580','SHIFT-20260812-001','open','2026-08-12','JPY',0,'2026-08-12T00:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed session: %v", err)
	}

	// Every row is ATTRIBUTED to the shift (till_session_id), the way a real
	// payment is stamped — so this measures the status half of the predicate and
	// not the time-window fallback.
	sale := func(id string, amount int, status string) {
		if _, err := db.Exec(`INSERT INTO payments
			(id, order_id, payment_method, amount, status, till_session_id, created_at)
			VALUES (?, ?, 'cash', ?, ?, 'sess-2580', '2026-08-12T02:00:00Z')`,
			id, "o-"+id, amount, status); err != nil {
			t.Fatalf("seed sale %s: %v", id, err)
		}
	}
	// Cloud's refund: NEW row, negative amount, refund_of_id → the original,
	// status succeeded. `refunded_amount` on either row stays 0 — that column is
	// the workstation's own (old) representation and the two never coexist.
	refund := func(id, of string, amount int) {
		if _, err := db.Exec(`INSERT INTO payments
			(id, order_id, payment_method, amount, status, refund_of_id, till_session_id, created_at)
			VALUES (?, ?, 'cash', ?, 'succeeded', ?, 'sess-2580', '2026-08-12T03:00:00Z')`,
			id, "o-"+of, -amount, of); err != nil {
			t.Fatalf("seed refund %s: %v", id, err)
		}
	}

	sale("p1", 20000, "succeeded") // untouched
	sale("p2", 13490, "succeeded") // partially refunded — original keeps `succeeded`
	sale("p3", 5000, "refunded")   // fully refunded — Cloud flips the original
	refund("r2", "p2", 2980)
	refund("r3", "p3", 5000)
	// gross 38,490 − refunds 7,980 = 30,510

	recon, err := s.reconcileSession("sess-2580")
	if err != nil {
		t.Fatalf("reconcileSession: %v", err)
	}

	const want = 30510.0
	if got := recon.CategoryExpected["cash"]; got != want {
		t.Errorf("category_expected[cash] = %v, want %v (38,490 sold − 7,980 refunded)", got, want)
	}
	if got := recon.ExpectedCash; got != want {
		t.Errorf("expected_cash = %v, want %v — this is the number the cashier counts against", got, want)
	}
}

// The representation the workstation writes TODAY (`local_pos_phase5.go`: a
// cumulative `refunded_amount` on the original, `status='refunded'` once it is
// fully refunded) must keep producing the SAME figure. Layer 1 widens the status
// filter to admit `refunded` originals; it would be a silent money regression if
// that made a locally-refunded sale count gross.
func TestReconcileSession_LocalRefundedAmountRepresentationUnchanged(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, till_id, branch_id)
		VALUES ('sess-legacy','S-L','open','2026-08-12','JPY',0,'2026-08-12T00:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO payments
		(id, order_id, payment_method, amount, refunded_amount, status, till_session_id, created_at)
		VALUES ('q1','o-q1','cash',20000,0,'succeeded','sess-legacy','2026-08-12T02:00:00Z'),
		       ('q2','o-q2','cash',13490,2980,'succeeded','sess-legacy','2026-08-12T02:00:00Z'),
		       ('q3','o-q3','cash',5000,5000,'refunded','sess-legacy','2026-08-12T02:00:00Z')`); err != nil {
		t.Fatalf("seed payments: %v", err)
	}

	recon, err := s.reconcileSession("sess-legacy")
	if err != nil {
		t.Fatalf("reconcileSession: %v", err)
	}
	if got := recon.CategoryExpected["cash"]; got != 30510.0 {
		t.Errorf("category_expected[cash] = %v, want 30510 — the refunded_amount representation must not shift", got)
	}
}

// The close screen's order-summary tile (`GET /pos/till/sessions/{id}/order-summary`)
// carries its own copy of the status filter — it attributes on till_session_id
// alone, with no window fallback, so it cannot call paidPaymentsPredicate. A copy
// of a money filter is exactly what drifts, so pin it to the same answer.
func TestTillOrderSummary_CountsSignedRefundRows(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, till_id, branch_id)
		VALUES ('sess-sum','S-SUM','open','2026-08-12','JPY',0,'2026-08-12T00:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO payments
		(id, order_id, payment_method, amount, status, refund_of_id, till_session_id, created_at)
		VALUES ('s1','o-s1','cash',5000,'refunded',NULL,'sess-sum','2026-08-12T02:00:00Z'),
		       ('s1r','o-s1','cash',-5000,'succeeded','s1','sess-sum','2026-08-12T03:00:00Z')`); err != nil {
		t.Fatalf("seed payments: %v", err)
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/sessions/sess-sum/order-summary", nil)
	req.SetPathValue("id", "sess-sum")
	s.handleLocalPosTillOrderSummary(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d, body %s", w.Code, w.Body.String())
	}
	var resp struct {
		Data struct {
			PaidOrdersCount int     `json:"paid_orders_count"`
			PaidOrdersTotal float64 `json:"paid_orders_total"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v — body %s", err, w.Body.String())
	}
	if resp.Data.PaidOrdersTotal != 0 {
		t.Errorf("paid_orders_total = %v, want 0 (sale fully refunded inside the shift)", resp.Data.PaidOrdersTotal)
	}
	if resp.Data.PaidOrdersCount != 1 {
		t.Errorf("paid_orders_count = %d, want 1 (both rows belong to one order)", resp.Data.PaidOrdersCount)
	}
}
