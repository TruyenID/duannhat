package handler

import (
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// The printed slip must show the cash the cashier actually took, not the bill.
//
// A ¥2,000 note against a ¥1,793 bill printed as "tendered ¥1,793 / change ¥0"
// because pos-web sent the row's own amount as the tender and Cloud's
// workstation payment route then overwrote whatever it did receive with
// `amount + tip`. Both were fixed; these two tests pin the two workstation-side
// links that carry the corrected figures to paper, neither of which had a guard:
//
//   - loadTenderedChange must return the payment's OWN tendered/change rather
//     than collapsing to the charged amount, and must find the row by amount
//     when no payment id is supplied (the auto-print-on-confirm path).
//   - the renderer must emit both お預かり and お釣り lines with those numbers.
func TestLoadTenderedChange_ReturnsTheCashTakenNotTheBill(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `
		INSERT INTO payments (id, order_id, payment_method, amount, status,
		                      tendered_amount, change_amount, paid_at, idempotency_key, created_at)
		VALUES ('pay-1','ord-1','cash',495,'succeeded',5000,4505,'2026-08-04T06:24:00Z','idem-1','2026-08-04T06:24:00Z')`)

	tendered, change := srv.loadTenderedChange("ord-1", 495, "")

	if tendered != 5000 {
		t.Errorf("tendered: want 5000, got %d", tendered)
	}
	if change != 4505 {
		t.Errorf("change: want 4505, got %d", change)
	}
}

// A cash payment settled to the yen records tendered == amount, so change is 0
// and the slip is right without a surplus. Guards the other direction: the
// lookup must not invent a change line for an exact-tender sale.
func TestLoadTenderedChange_ExactTenderHasNoChange(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `
		INSERT INTO payments (id, order_id, payment_method, amount, status,
		                      tendered_amount, change_amount, paid_at, idempotency_key, created_at)
		VALUES ('pay-2','ord-2','cash',495,'succeeded',495,0,'2026-08-04T06:24:00Z','idem-2','2026-08-04T06:24:00Z')`)

	tendered, change := srv.loadTenderedChange("ord-2", 495, "")

	if tendered != 495 || change != 0 {
		t.Errorf("exact tender: want (495, 0), got (%d, %d)", tendered, change)
	}
}

func TestPaidTicket_PrintsTenderedAndChange(t *testing.T) {
	order := &service.Order{
		ID:          "ord-1",
		OrderCode:   "ORD-2026-3246",
		PaidAmount:  495,
		TotalAmount: 495,
		Items: []service.Item{{
			ID:           "it1",
			ProductSkuID: "sk1",
			MenuItemName: "Nuoc gung",
			Quantity:     1,
			UnitPrice:    450,
		}},
	}

	slip := decodeSJIS(service.FormatPaidTicket(order, order.Items, 0,
		service.PrintJobConfig{Locale: "ja", PaperWidth: 48},
		service.PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: 495, Tendered: 5000, Change: 4505}))

	for _, want := range []string{"5,000", "4,505"} {
		if !strings.Contains(slip, want) {
			t.Errorf("slip missing %q in:\n%s", want, slip)
		}
	}
	// The bug printed the bill as the tender and a zero change; neither may
	// appear on a slip where ¥5,000 changed hands.
	if strings.Contains(slip, "お釣り") && strings.Contains(slip, "お釣り: 0") {
		t.Errorf("slip still reports zero change:\n%s", slip)
	}
}

// ---------------------------------------------------------------------------
//  Split bill: the slip must carry the tender of the guest it names
// ---------------------------------------------------------------------------

// Chia đều puts the SAME amount on every guest's row, so the amount match
// cannot tell them apart — it returns the newest, i.e. the last person to pay.
// Once each guest tenders their own note (pos-web collects it per row), that
// printed guest #3's お預かり/お釣り on guest #1's slip: plausible numbers,
// wrong guest, and the person holding the paper is the one who finds out.
func TestLoadTenderedChange_TargetsTheNamedPaymentInASplitBill(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	// Three guests, ¥1,000 each. Different notes, inserted oldest → newest.
	mustExec(t, db, `
		INSERT INTO payments (id, order_id, payment_method, amount, status,
		                      tendered_amount, change_amount, paid_at, idempotency_key, created_at)
		VALUES
		  ('pay-a','ord-s','cash',1000,'succeeded',1000,   0,'2026-08-05T01:00:00Z','idem-a','2026-08-05T01:00:00Z'),
		  ('pay-b','ord-s','cash',1000,'succeeded',5000,4000,'2026-08-05T01:01:00Z','idem-b','2026-08-05T01:01:00Z'),
		  ('pay-c','ord-s','cash',1000,'succeeded',2000,1000,'2026-08-05T01:02:00Z','idem-c','2026-08-05T01:02:00Z')`)

	for _, tc := range []struct {
		paymentID              string
		wantTendered, wantChge int
	}{
		{"pay-a", 1000, 0},
		{"pay-b", 5000, 4000},
		{"pay-c", 2000, 1000},
	} {
		tendered, change := srv.loadTenderedChange("ord-s", 1000, tc.paymentID)
		if tendered != tc.wantTendered || change != tc.wantChge {
			t.Errorf("%s: want (%d, %d), got (%d, %d)",
				tc.paymentID, tc.wantTendered, tc.wantChge, tendered, change)
		}
	}
}

// A guest who paid by card has no tender at all. Falling back to the amount
// match there would borrow a CASH guest's figures onto a card receipt — the
// customer would read an お預かり line for money they never handed over.
func TestLoadTenderedChange_NamedNonCashPaymentBorrowsNothing(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `
		INSERT INTO payments (id, order_id, payment_method, amount, status,
		                      tendered_amount, change_amount, paid_at, idempotency_key, created_at)
		VALUES
		  ('pay-cash','ord-m','cash',1000,'succeeded',5000,4000,'2026-08-05T01:00:00Z','idem-x','2026-08-05T01:00:00Z'),
		  ('pay-card','ord-m','card',1000,'succeeded',NULL,NULL,'2026-08-05T01:01:00Z','idem-y','2026-08-05T01:01:00Z')`)

	tendered, change := srv.loadTenderedChange("ord-m", 1000, "pay-card")

	if tendered != 0 || change != 0 {
		t.Errorf("card slip must print no tender line: got (%d, %d)", tendered, change)
	}
}

// An id that does not belong to this order (stale client, crossed reprint) must
// not silently poison the slip — it falls through to the existing behaviour
// rather than printing zeros for a cash sale.
func TestLoadTenderedChange_UnknownPaymentIDFallsBackToAmount(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `
		INSERT INTO payments (id, order_id, payment_method, amount, status,
		                      tendered_amount, change_amount, paid_at, idempotency_key, created_at)
		VALUES ('pay-only','ord-u','cash',495,'succeeded',5000,4505,'2026-08-05T01:00:00Z','idem-u','2026-08-05T01:00:00Z')`)

	tendered, change := srv.loadTenderedChange("ord-u", 495, "pay-from-another-order")

	if tendered != 5000 || change != 4505 {
		t.Errorf("want fallback (5000, 4505), got (%d, %d)", tendered, change)
	}
}

// The paper itself, for one guest of a chia đều split: the slip must carry the
// note THAT guest handed over. Composes the two links that were separately
// broken — the per-payment lookup and the renderer — because a correct lookup
// feeding a slip that omits the lines is still a customer holding a receipt
// with no お預かり on it.
func TestSplitSlip_CarriesTheNamedGuestsCash(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `
		INSERT INTO payments (id, order_id, payment_method, amount, status,
		                      tendered_amount, change_amount, paid_at, idempotency_key, created_at)
		VALUES
		  ('pay-1','ord-eq','cash',1000,'succeeded',5000,4000,'2026-08-05T01:00:00Z','idem-1','2026-08-05T01:00:00Z'),
		  ('pay-2','ord-eq','cash',1000,'succeeded',2000,1000,'2026-08-05T01:01:00Z','idem-2','2026-08-05T01:01:00Z')`)

	// Guest 1's slip — 5,000 in, 4,000 back. Guest 2 paid later with a
	// different note, so an amount-matched lookup would print 2,000 / 1,000.
	tendered, change := srv.loadTenderedChange("ord-eq", 1000, "pay-1")

	order := &service.Order{
		ID: "ord-eq", OrderCode: "ORD-EQ-1", PaidAmount: 1000, TotalAmount: 2000,
		Items: []service.Item{{
			ID: "it1", ProductSkuID: "sk1", MenuItemName: "Nuoc gung",
			Quantity: 1, UnitPrice: 1000,
		}},
	}
	slip := decodeSJIS(service.FormatPaidTicket(order, order.Items, 0,
		service.PrintJobConfig{Locale: "ja", PaperWidth: 48},
		service.PaymentSlipInfo{
			PaymentMethod: "cash", AmountPaid: 1000,
			Tendered: tendered, Change: change,
		}))

	// Assert on the two LINES, not on the sheet as a whole: the order total and
	// the item price legitimately print other figures, so a whole-sheet
	// substring check would be both noisy and unable to prove attribution.
	tenderedLine := lineContaining(slip, "お預かり")
	changeLine := lineContaining(slip, "お釣り")
	if !strings.Contains(tenderedLine, "5,000") {
		t.Errorf("お預かり line = %q, want the 5,000 this guest handed over", tenderedLine)
	}
	if !strings.Contains(changeLine, "4,000") {
		t.Errorf("お釣り line = %q, want 4,000", changeLine)
	}
	// Guest 2's note (2,000 → 1,000 back) must not be on guest 1's paper.
	if strings.Contains(tenderedLine, "2,000") || strings.Contains(changeLine, "1,000") {
		t.Errorf("slip carries the OTHER guest's cash: %q / %q", tenderedLine, changeLine)
	}
}

// lineContaining returns the first line of a rendered slip holding `needle`,
// or "" when absent — so an assertion on a missing line fails loudly instead of
// passing against the whole sheet.
func lineContaining(slip, needle string) string {
	for _, line := range strings.Split(slip, "\n") {
		if strings.Contains(line, needle) {
			return line
		}
	}
	return ""
}

// A merged table (gộp bàn) carries several linked order rows, and a guest's
// payment can be tied to the cloud-keyed sibling rather than the row the caller
// names. Matching on the raw order id alone missed it and fell through to the
// amount match — i.e. back to printing the wrong guest's お預かり, in exactly the
// case where several guests owe the same amount.
func TestLoadTenderedChange_SiblingOrderRowStillTargetsTheNamedPayment(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)

	// The workstation row and its cloud-keyed sibling — the shape linkedOrderIDs
	// resolves.
	if _, err := db.Exec(`
		INSERT INTO orders (id, cloud_id, order_code, status, total_amount)
		VALUES ('o-local', 'o-cloud', 'WS-1', 'open', 2000),
		       ('o-cloud', 'o-cloud', 'WS-1', 'open', 2000)`); err != nil {
		t.Fatalf("seed orders: %v", err)
	}

	// Two guests, SAME amount (chia đều) — the amount match cannot tell them
	// apart. Both payments hang off the SIBLING row.
	if _, err := db.Exec(`
		INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key,
		                      tendered_amount, change_amount, created_at)
		VALUES ('pay-1', 'o-cloud', 'cash', 1000, 'confirmed', 'i1', 2000, 1000, '2026-08-05T10:00:00Z'),
		       ('pay-2', 'o-cloud', 'cash', 1000, 'confirmed', 'i2', 5000, 4000, '2026-08-05T10:05:00Z')`); err != nil {
		t.Fatalf("seed payments: %v", err)
	}

	// Ask via the LOCAL id for the FIRST guest. Without sibling awareness this
	// returns guest #2's ¥5,000 note — plausible numbers, wrong guest.
	tendered, change := s.loadTenderedChange("o-local", 1000, "pay-1")
	if tendered != 2000 || change != 1000 {
		t.Errorf("tendered/change = %d/%d, want 2000/1000 (guest #1's own note)", tendered, change)
	}
}
