package handler

import (
	"errors"
	"strconv"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printjob"
)

// #1875 — the copy number 「BAN IN #N」 is PER KIND and PER SCOPE.
//
// The product rule these tests exist to hold:
//
//	one payer   — the first red invoice prints clean, every later one is marked;
//	many payers — EVERY payer's first red invoice prints clean, and only that
//	              payer's own second one is marked.
//
// Both used to be false. One counter on `payments.metadata.print_history` was
// shared by receipt + red_invoice + debt_slip, and an untargeted print silently
// counted against `lastConfirmedPaymentID` — the last guest's number.

// printOnce drives the real numbering seam: resolve the scope, reserve the
// number, settle the row. Only the physical printer is left out (p == nil), so
// what these tests assert is exactly what the handlers do.
func printOnce(t *testing.T, s *Server, kind printjob.Kind, orderID, paymentID string) int {
	t.Helper()
	scope := s.resolvePrintScope(orderID, paymentID)
	entry := printjob.Entry{Kind: kind, OrderID: orderID, PaymentID: scope.PaymentID}
	res := s.beginMoneyPrint(nil, entry, scope)
	s.finishMoneyPrint(res, nil, entry, "manual reprint", nil)
	return res.ReprintNo
}

// seedSplit inserts one confirmed payment per guest, shaped the way pos-web
// actually sends each split mode.
func seedSplit(t *testing.T, s *Server, orderID, mode string, ids ...string) {
	t.Helper()
	for i, id := range ids {
		meta := ""
		if mode != "" {
			meta = `{"split_mode":"` + mode + `","total_bills":` + strconv.Itoa(len(ids)) +
				`,"bill_index":` + strconv.Itoa(i) + `}`
		}
		if _, err := s.db.Exec(`
			INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, metadata, created_at)
			VALUES (?, ?, 'cash', 1000, 'confirmed', ?, ?, ?)`,
			id, orderID, "idem-"+id, meta,
			"2026-08-05T10:0"+strconv.Itoa(i)+":00Z",
		); err != nil {
			t.Fatalf("seed payment %s: %v", id, err)
		}
	}
}

// W17 — THE headline regression. Printing a receipt must not make the
// customer's FIRST red invoice come out stamped as a copy.
func TestReprintCounter_ReceiptDoesNotMarkFirstRedInvoice(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	seedSplit(t, s, "o-1", "", "pay-1") // one payer, no split metadata

	if got := printOnce(t, s, printjob.KindReceipt, "o-1", ""); got != 1 {
		t.Fatalf("receipt #1 = %d, want 1", got)
	}
	if got := printOnce(t, s, printjob.KindRedInvoice, "o-1", ""); got != 1 {
		t.Errorf("first red invoice after a receipt = %d, want 1 (it is an ORIGINAL, "+
			"and #2 would print 「BAN IN #2」 on it)", got)
	}
	if got := printOnce(t, s, printjob.KindDebtSlip, "o-1", ""); got != 1 {
		t.Errorf("first debt slip = %d, want 1 (its own counter too)", got)
	}
	// …and each kind still counts its own repeats.
	if got := printOnce(t, s, printjob.KindRedInvoice, "o-1", ""); got != 2 {
		t.Errorf("second red invoice = %d, want 2", got)
	}
}

// W18 + W19 + W20 — every split mode, the two rules together: every payer's
// first sheet is clean, and a reprint marks ONLY that payer.
func TestReprintCounter_SplitBill_PerPayerCounters(t *testing.T) {
	for _, mode := range []string{"even", "by_amount", "by_items"} {
		t.Run(mode, func(t *testing.T) {
			cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
			s, _ := newServerWithAuth(t, cloud.URL)
			guests := []string{"pay-a", "pay-b", "pay-c", "pay-d"}
			seedSplit(t, s, "o-split", mode, guests...)

			// W18 — first red invoice for all four: every one an original.
			for _, g := range guests {
				if got := printOnce(t, s, printjob.KindRedInvoice, "o-split", g); got != 1 {
					t.Fatalf("%s first red invoice = %d, want 1 (no mark)", g, got)
				}
			}

			// W19 — guest B asks for another copy.
			if got := printOnce(t, s, printjob.KindRedInvoice, "o-split", "pay-b"); got != 2 {
				t.Errorf("pay-b second red invoice = %d, want 2 (marked)", got)
			}
			// The other three are untouched — their NEXT sheet is only their #2.
			for _, g := range []string{"pay-a", "pay-c", "pay-d"} {
				if got := printOnce(t, s, printjob.KindRedInvoice, "o-split", g); got != 2 {
					t.Errorf("%s next red invoice = %d, want 2 — a reprint for pay-b "+
						"must not advance anyone else's counter", g, got)
				}
			}
		})
	}
}

// W21 — the whole-order slip (split-bill footer, no payment_id) is its own
// document. It must not consume a guest's number, which is what the removed
// `lastConfirmedPaymentID` fallback did.
func TestReprintCounter_WholeOrderSlipDoesNotBurnAGuestNumber(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	seedSplit(t, s, "o-split", "even", "pay-a", "pay-b")

	if got := printOnce(t, s, printjob.KindRedInvoice, "o-split", ""); got != 1 {
		t.Fatalf("whole-order red invoice = %d, want 1", got)
	}
	// Both guests still have a clean first sheet.
	for _, g := range []string{"pay-a", "pay-b"} {
		if got := printOnce(t, s, printjob.KindRedInvoice, "o-split", g); got != 1 {
			t.Errorf("%s first red invoice after a whole-order slip = %d, want 1", g, got)
		}
	}
	// And the whole-order scope keeps counting on itself.
	if got := printOnce(t, s, printjob.KindRedInvoice, "o-split", ""); got != 2 {
		t.Errorf("second whole-order red invoice = %d, want 2", got)
	}
}

// W22 — a one-payer order is printed from PaymentReceiptDialog, which sends NO
// payment_id. Both UI paths must land in the SAME counter, or the second sheet
// prints unmarked.
func TestReprintCounter_SinglePayerUntargetedAndTargetedShareOneCounter(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	seedSplit(t, s, "o-1", "", "pay-1")

	if got := printOnce(t, s, printjob.KindRedInvoice, "o-1", ""); got != 1 {
		t.Fatalf("untargeted #1 = %d, want 1", got)
	}
	if got := printOnce(t, s, printjob.KindRedInvoice, "o-1", ""); got != 2 {
		t.Errorf("untargeted #2 = %d, want 2", got)
	}
	if got := printOnce(t, s, printjob.KindRedInvoice, "o-1", "pay-1"); got != 3 {
		t.Errorf("targeted print on the same sole payment = %d, want 3 — the two UI "+
			"paths must not keep separate counters for the same sheet", got)
	}
}

// W23 — an order with no payment yet. It used to be pinned at 1 forever, so ten
// sheets all came out unmarked.
func TestReprintCounter_UnpaidOrderCountsOnTheOrder(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)

	for want := 1; want <= 3; want++ {
		if got := printOnce(t, s, printjob.KindRedInvoice, "o-unpaid", ""); got != want {
			t.Errorf("unpaid order print #%d = %d, want %d", want, got, want)
		}
	}
}

// W25 — a merged table carries several linked order rows. Counting only the id
// the caller named would hand out #1 twice for the same document.
func TestReprintCounter_MergedOrderFamilyShareOneCounter(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)

	// The workstation row and its cloud-keyed sibling — the shape linkedOrderIDs
	// resolves.
	if _, err := s.db.Exec(`
		INSERT INTO orders (id, cloud_id, order_code, status, total_amount)
		VALUES ('o-local', 'o-cloud', 'WS-1', 'open', 1000),
		       ('o-cloud', 'o-cloud', 'WS-1', 'open', 1000)`); err != nil {
		t.Fatalf("seed orders: %v", err)
	}

	if got := printOnce(t, s, printjob.KindRedInvoice, "o-local", ""); got != 1 {
		t.Fatalf("first print via local id = %d, want 1", got)
	}
	if got := printOnce(t, s, printjob.KindRedInvoice, "o-cloud", ""); got != 2 {
		t.Errorf("print via the sibling id = %d, want 2 — the same piece of paper "+
			"must not be issued #1 twice", got)
	}
}

// A failed print still burns its number (plan-052 P-12): the attempt happened,
// and a shop must not be able to rewind the counter by unplugging the machine.
func TestReprintCounter_FailedPrintStillBurnsItsNumber(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	seedSplit(t, s, "o-1", "", "pay-1")

	scope := s.resolvePrintScope("o-1", "")
	entry := printjob.Entry{Kind: printjob.KindRedInvoice, OrderID: "o-1", PaymentID: scope.PaymentID}
	res := s.beginMoneyPrint(nil, entry, scope)
	s.finishMoneyPrint(res, nil, entry, "manual reprint", errPrintFailedForTest)

	if got := printOnce(t, s, printjob.KindRedInvoice, "o-1", ""); got != 2 {
		t.Errorf("print after a FAILED print = %d, want 2", got)
	}
}

var errPrintFailedForTest = errors.New("printer offline")

// The status endpoint's tally is what pos-web renders as "đã in ×N". It must
// report per payer, not just per order.
func TestPrintCountsBlock_ReportsPerPayer(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	seedSplit(t, s, "o-split", "even", "pay-a", "pay-b")

	printOnce(t, s, printjob.KindRedInvoice, "o-split", "pay-a")
	printOnce(t, s, printjob.KindRedInvoice, "o-split", "pay-a")
	printOnce(t, s, printjob.KindRedInvoice, "o-split", "pay-b")

	block := s.printCountsBlock(printjob.KindRedInvoice, s.linkedOrderIDs("o-split"))
	if block == nil {
		t.Fatal("printCountsBlock returned nil")
	}
	if block["printed"] != true {
		t.Errorf("printed = %v, want true", block["printed"])
	}

	byPayment, _ := block["by_payment"].([]map[string]any)
	counts := map[string]int{}
	for _, row := range byPayment {
		id, _ := row["payment_id"].(string)
		n, _ := row["count"].(int)
		counts[id] = n
	}
	if counts["pay-a"] != 2 {
		t.Errorf("pay-a count = %d, want 2", counts["pay-a"])
	}
	if counts["pay-b"] != 1 {
		t.Errorf("pay-b count = %d, want 1", counts["pay-b"])
	}
}

// An order nobody has printed for must not report `printed: true` — the badge
// would then claim paper that does not exist.
func TestPrintCountsBlock_CleanOrderReportsNotPrinted(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)

	block := s.printCountsBlock(printjob.KindRedInvoice, s.linkedOrderIDs("o-none"))
	if block == nil {
		t.Fatal("printCountsBlock returned nil")
	}
	if block["printed"] != false {
		t.Errorf("printed = %v, want false", block["printed"])
	}
}
