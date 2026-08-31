package handler

import "testing"

// A gap payment CLAIMED at open carries this session's till_session_id, but its
// created_at is by definition BEFORE the session opened — the claim window is
// (prev_end, opened_at]. reconcileSession sums payments by TIME WINDOW
// (created_at >= opened_at), so it does not see them; Cloud
// TillSessionService::reconcile filters on order_payments.till_session_id, so it
// does.
//
// The consequence is money the cashier cannot explain. Gap CASH is claimable on
// purpose (plan-044 R2 §3: staff physically held it aside, and the UI makes them
// tick a "held separately" ack), so the note is sitting in the drawer being
// counted — while expected_cash omits it. The close screen shows an overage
// exactly equal to the claimed gap cash, and Cloud later recomputes a different,
// authoritative figure and writes it back (R7).
//
// The same close screen is already inconsistent with itself: order-summary
// counts paid orders BY till_session_id, so the claimed payment appears in the
// order totals but not in the money.
func TestReconcileSession_IncludesClaimedGapPayments(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	// A previous shift that closed at 08:00, then this one opening at 09:00 —
	// the hour between them is the gap window.
	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('prev','S0','settled','2026-07-20','JPY',0,'2026-07-20T06:00:00Z','2026-07-20T08:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed previous session: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('sess','S1','settled','2026-07-20','JPY',0,'2026-07-20T09:00:00Z','2026-07-20T12:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed session: %v", err)
	}

	// Cash taken during the gap, then claimed onto this shift at open.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at, till_session_id)
		VALUES ('p-gap','o-gap','cash',10000,'confirmed','2026-07-20T08:30:00Z','sess')`); err != nil {
		t.Fatalf("seed gap payment: %v", err)
	}
	// An ordinary in-window sale, so the test also proves the window still works.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at, till_session_id)
		VALUES ('p-in','o-in','cash',2000,'confirmed','2026-07-20T10:00:00Z','sess')`); err != nil {
		t.Fatalf("seed in-window payment: %v", err)
	}

	recon, err := s.reconcileSession("sess")
	if err != nil {
		t.Fatalf("reconcileSession: %v", err)
	}

	const want = 12000.0 // 10,000 claimed from the gap + 2,000 sold in-shift
	if recon.CashSales != want {
		t.Errorf("cash_sales = %v, want %v — the claimed gap payment is attributed to this session but the time-window sum drops it", recon.CashSales, want)
	}
	if recon.ExpectedCash != want {
		t.Errorf("expected_cash = %v, want %v — the cashier counts this cash in the drawer, so omitting it reads as an overage", recon.ExpectedCash, want)
	}
	if got := recon.CategoryExpected["cash"]; got != want {
		t.Errorf("category_expected[cash] = %v, want %v", got, want)
	}
}

// The mirror case: a payment taken inside this shift's window but attributed
// away (claimed by a LATER shift, or deliberately left NULL) must NOT count
// here. Without this, "include by till_session_id" could be implemented as
// "include by window OR session", which double-counts across the two shifts.
func TestReconcileSession_ExcludesPaymentsAttributedElsewhere(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('sess','S1','settled','2026-07-20','JPY',0,'2026-07-20T09:00:00Z','2026-07-20T12:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed session: %v", err)
	}

	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at, till_session_id)
		VALUES ('p-mine','o1','cash',2000,'confirmed','2026-07-20T10:00:00Z','sess')`); err != nil {
		t.Fatalf("seed own payment: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at, till_session_id)
		VALUES ('p-theirs','o2','cash',5000,'confirmed','2026-07-20T11:00:00Z','other-sess')`); err != nil {
		t.Fatalf("seed foreign payment: %v", err)
	}

	recon, err := s.reconcileSession("sess")
	if err != nil {
		t.Fatalf("reconcileSession: %v", err)
	}

	if recon.CashSales != 2000 {
		t.Errorf("cash_sales = %v, want 2000 — a payment attributed to another shift must not count here", recon.CashSales)
	}
}
