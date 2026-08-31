package handler

import "testing"

// reconcileSession must roll payments into the SAME categories Cloud
// (TillSessionService::reconcile) does, or a shift reconciles differently on
// LAN vs Cloud and money silently leaks. The critical case: card_terminal
// (決済端末 / Stera) folds into `card`; e_wallet is `emoney`, transfer is `qr`.
func TestReconcileSession_MergesCardTerminalAndMapsNonCash(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	// A settled session with a fixed [opened, closed] window so the test is
	// time-independent.
	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('sess1','S1','settled','2026-07-20','JPY',0,'2026-07-20T09:00:00Z','2026-07-20T12:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed session: %v", err)
	}

	// One confirmed payment per method, all inside the window.
	seed := func(id, method string, amount int) {
		if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
			VALUES (?, 'o1', ?, ?, 'confirmed', '2026-07-20T10:00:00Z')`, id, method, amount); err != nil {
			t.Fatalf("seed payment %s: %v", method, err)
		}
	}
	seed("p-cash", "cash", 1000)
	seed("p-card", "card", 500)
	seed("p-term", "card_terminal", 700) // the Stera terminal payment
	seed("p-emoney", "e_wallet", 300)
	seed("p-qr", "transfer", 200)

	recon, err := s.reconcileSession("sess1")
	if err != nil {
		t.Fatalf("reconcileSession: %v", err)
	}

	want := map[string]float64{
		"cash":   1000,
		"card":   1200, // 500 card + 700 card_terminal — the fix
		"qr":     200,  // transfer
		"emoney": 300,  // e_wallet
	}
	for cat, exp := range want {
		if got := recon.CategoryExpected[cat]; got != exp {
			t.Errorf("category_expected[%s] = %v, want %v", cat, got, exp)
		}
	}
}

// A card anchor tender's per-row expected must ALSO include card_terminal, since
// the batch slip the cashier reconciles against holds both.
func TestReconcileSession_CardAnchorTenderIncludesTerminal(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('sess2','S2','settled','2026-07-20','JPY',0,'2026-07-20T09:00:00Z','2026-07-20T12:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO till_tender_types
		(id, tender_key, name, category, payment_method_code, is_expected_anchor, sort_order)
		VALUES ('tt-credit','credit','Credit','card','card',1,0)`); err != nil {
		t.Fatalf("seed tender type: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('p-card','o1','card',500,'confirmed','2026-07-20T10:00:00Z'),
		       ('p-term','o1','card_terminal',700,'confirmed','2026-07-20T10:05:00Z')`); err != nil {
		t.Fatalf("seed payments: %v", err)
	}

	recon, err := s.reconcileSession("sess2")
	if err != nil {
		t.Fatalf("reconcileSession: %v", err)
	}
	if got := recon.TenderExpected["credit"]; got != 1200 {
		t.Errorf("credit anchor expected = %v, want 1200 (card+card_terminal)", got)
	}
}
