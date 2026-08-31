package printjob

import (
	"sync"
	"testing"
	"time"
)

// #1875 — the two-phase reprint counter.
//
// What these defend, in order of what it costs to get wrong:
//
//  1. two numbers are never issued twice (two sheets both claiming to be the
//     original);
//  2. a reservation never reaches Cloud (a ledger row stuck saying a print is
//     in flight on a machine that finished — or died — an hour ago);
//  3. a crash mid-print becomes a human's problem, not a silent gap.

func TestReserve_CountsPerKindAndScope(t *testing.T) {
	j := newTestJournal(t)
	scope := Scope{PaymentID: "pay-1"}

	first, err := j.Reserve(Entry{Kind: KindRedInvoice, OrderID: "o-1", PaymentID: "pay-1"}, scope)
	if err != nil {
		t.Fatalf("reserve: %v", err)
	}
	if first.ReprintNo != 1 {
		t.Fatalf("first red invoice = %d, want 1", first.ReprintNo)
	}
	if err := j.Confirm(first.JobID, Outcome{Status: StatusPrinted}); err != nil {
		t.Fatalf("confirm: %v", err)
	}

	// A RECEIPT on the same payment is a different document and starts at 1.
	receipt, err := j.Reserve(Entry{Kind: KindReceipt, OrderID: "o-1", PaymentID: "pay-1"}, scope)
	if err != nil {
		t.Fatalf("reserve receipt: %v", err)
	}
	if receipt.ReprintNo != 1 {
		t.Errorf("receipt on a payment that already has a red invoice = %d, want 1 — "+
			"the kinds must not share a counter", receipt.ReprintNo)
	}

	second, err := j.Reserve(Entry{Kind: KindRedInvoice, OrderID: "o-1", PaymentID: "pay-1"}, scope)
	if err != nil {
		t.Fatalf("reserve second: %v", err)
	}
	if second.ReprintNo != 2 {
		t.Errorf("second red invoice = %d, want 2", second.ReprintNo)
	}
}

// The order scope counts only rows with NO payment, so a whole-order slip and a
// payer's slip never consume each other's numbers.
func TestReserve_OrderScopeIgnoresPayerRows(t *testing.T) {
	j := newTestJournal(t)

	for _, pay := range []string{"pay-a", "pay-b"} {
		if _, err := j.Reserve(
			Entry{Kind: KindRedInvoice, OrderID: "o-1", PaymentID: pay},
			Scope{PaymentID: pay},
		); err != nil {
			t.Fatalf("reserve %s: %v", pay, err)
		}
	}

	whole, err := j.Reserve(
		Entry{Kind: KindRedInvoice, OrderID: "o-1"},
		Scope{OrderIDs: []string{"o-1"}},
	)
	if err != nil {
		t.Fatalf("reserve whole-order: %v", err)
	}
	if whole.ReprintNo != 1 {
		t.Errorf("whole-order slip = %d, want 1 — two payers' sheets must not make "+
			"the order's own first sheet look like a copy", whole.ReprintNo)
	}
}

// A scope naming nothing matches nothing. It must not silently fall back to
// some other document's counter.
func TestReserve_EmptyScopeStartsAtOne(t *testing.T) {
	j := newTestJournal(t)
	if _, err := j.Reserve(Entry{Kind: KindRedInvoice, OrderID: "o-1", PaymentID: "pay-1"}, Scope{PaymentID: "pay-1"}); err != nil {
		t.Fatalf("seed: %v", err)
	}

	res, err := j.Reserve(Entry{Kind: KindRedInvoice}, Scope{})
	if err != nil {
		t.Fatalf("reserve: %v", err)
	}
	if res.ReprintNo != 1 {
		t.Errorf("unidentifiable scope = %d, want 1", res.ReprintNo)
	}
}

// Two tablets hitting the same slip at the same moment. Without BEGIN IMMEDIATE
// around read-then-insert they both read MAX=0 and both print #1.
func TestReserve_ConcurrentCallersNeverShareANumber(t *testing.T) {
	j := newTestJournal(t)
	const callers = 8

	var wg sync.WaitGroup
	numbers := make([]int, callers)
	errs := make([]error, callers)
	for i := range callers {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			res, err := j.Reserve(
				Entry{Kind: KindRedInvoice, OrderID: "o-1", PaymentID: "pay-1"},
				Scope{PaymentID: "pay-1"},
			)
			numbers[i], errs[i] = res.ReprintNo, err
		}(i)
	}
	wg.Wait()

	seen := map[int]bool{}
	for i, err := range errs {
		if err != nil {
			t.Fatalf("caller %d: %v", i, err)
		}
		if seen[numbers[i]] {
			t.Fatalf("number %d handed out twice — two sheets would both claim to be "+
				"the same copy", numbers[i])
		}
		seen[numbers[i]] = true
	}
	for n := 1; n <= callers; n++ {
		if !seen[n] {
			t.Errorf("number %d was never issued; got %v", n, seen)
		}
	}
}

// A reservation must never sync. Cloud has no way to settle one.
func TestPending_ExcludesReservations(t *testing.T) {
	j := newTestJournal(t)

	res, err := j.Reserve(Entry{Kind: KindRedInvoice, OrderID: "o-1", PaymentID: "pay-1"}, Scope{PaymentID: "pay-1"})
	if err != nil {
		t.Fatalf("reserve: %v", err)
	}

	pending, err := j.Pending(50)
	if err != nil {
		t.Fatalf("pending: %v", err)
	}
	if len(pending) != 0 {
		t.Fatalf("queued row is syncable: got %d pending, want 0", len(pending))
	}
	if n, err := j.PendingCount(); err != nil || n != 0 {
		t.Errorf("PendingCount = %d (err %v), want 0 — a slow printer must not read "+
			"as a broken uplink", n, err)
	}

	if err := j.Confirm(res.JobID, Outcome{Status: StatusPrinted}); err != nil {
		t.Fatalf("confirm: %v", err)
	}
	pending, err = j.Pending(50)
	if err != nil {
		t.Fatalf("pending after confirm: %v", err)
	}
	if len(pending) != 1 {
		t.Errorf("after confirm: %d pending, want 1", len(pending))
	}
}

// Confirm is the moment the row becomes Cloud's business, so the drain wake-up
// belongs there and NOT at Reserve.
func TestConfirm_NotifiesTheDrainExactlyOnce(t *testing.T) {
	j := newTestJournal(t)
	var notified []string
	j.OnRecorded(func(id string) { notified = append(notified, id) })

	res, err := j.Reserve(Entry{Kind: KindReceipt, OrderID: "o-1"}, Scope{OrderIDs: []string{"o-1"}})
	if err != nil {
		t.Fatalf("reserve: %v", err)
	}
	if len(notified) != 0 {
		t.Fatalf("reserve notified the drain: %v", notified)
	}

	if err := j.Confirm(res.JobID, Outcome{Status: StatusPrinted}); err != nil {
		t.Fatalf("confirm: %v", err)
	}
	if len(notified) != 1 {
		t.Fatalf("confirm notified %d times, want 1", len(notified))
	}
	// A second Confirm is a no-op, not an error and not a second wake-up.
	if err := j.Confirm(res.JobID, Outcome{Status: StatusPrinted}); err != nil {
		t.Errorf("double confirm returned %v, want nil", err)
	}
	if len(notified) != 1 {
		t.Errorf("double confirm notified %d times, want 1", len(notified))
	}
}

// Crash between Reserve and Confirm. `needs_attention`, never `failed`: nobody
// knows whether that sheet came out, and telling a shop it did not would have
// them reprint a slip the customer may already be holding.
func TestSweepStaleReservations_OrphanBecomesNeedsAttention(t *testing.T) {
	j := newTestJournal(t)

	res, err := j.Reserve(Entry{Kind: KindRedInvoice, OrderID: "o-1", PaymentID: "pay-1"}, Scope{PaymentID: "pay-1"})
	if err != nil {
		t.Fatalf("reserve: %v", err)
	}
	// Age it past the window.
	if _, err := j.db.Exec(
		`UPDATE print_jobs SET created_at = ? WHERE id = ?`,
		time.Now().Add(-2*time.Hour).UTC().Format(time.RFC3339), res.JobID,
	); err != nil {
		t.Fatalf("age row: %v", err)
	}

	n, err := j.SweepStaleReservations(StaleReservationAge)
	if err != nil {
		t.Fatalf("sweep: %v", err)
	}
	if n != 1 {
		t.Fatalf("swept %d rows, want 1", n)
	}

	var status string
	if err := j.db.QueryRow(`SELECT status FROM print_jobs WHERE id = ?`, res.JobID).Scan(&status); err != nil {
		t.Fatalf("read status: %v", err)
	}
	if Status(status) != StatusNeedsAttention {
		t.Errorf("status = %q, want %q", status, StatusNeedsAttention)
	}

	// Swept rows DO sync — a manager resolves them in the Cloud ledger.
	pending, err := j.Pending(50)
	if err != nil {
		t.Fatalf("pending: %v", err)
	}
	if len(pending) != 1 {
		t.Errorf("swept row not syncable: %d pending, want 1", len(pending))
	}
}

// A print still in flight must survive the sweep — mis-sweeping a live job puts
// a spurious alert in front of a manager.
func TestSweepStaleReservations_LeavesFreshReservations(t *testing.T) {
	j := newTestJournal(t)
	if _, err := j.Reserve(Entry{Kind: KindRedInvoice, OrderID: "o-1", PaymentID: "pay-1"}, Scope{PaymentID: "pay-1"}); err != nil {
		t.Fatalf("reserve: %v", err)
	}

	n, err := j.SweepStaleReservations(StaleReservationAge)
	if err != nil {
		t.Fatalf("sweep: %v", err)
	}
	if n != 0 {
		t.Errorf("swept %d fresh reservations, want 0", n)
	}
}

// The number survives a failed print (P-12).
func TestReserve_FailedPrintKeepsItsNumber(t *testing.T) {
	j := newTestJournal(t)
	scope := Scope{PaymentID: "pay-1"}

	first, err := j.Reserve(Entry{Kind: KindRedInvoice, OrderID: "o-1", PaymentID: "pay-1"}, scope)
	if err != nil {
		t.Fatalf("reserve: %v", err)
	}
	if err := j.Confirm(first.JobID, Outcome{Status: StatusFailed, LastError: "printer offline"}); err != nil {
		t.Fatalf("confirm: %v", err)
	}

	next, err := j.Reserve(Entry{Kind: KindRedInvoice, OrderID: "o-1", PaymentID: "pay-1"}, scope)
	if err != nil {
		t.Fatalf("reserve next: %v", err)
	}
	if next.ReprintNo != 2 {
		t.Errorf("after a failed print the next number = %d, want 2", next.ReprintNo)
	}
}

// CountsForOrder backs the pos-web badge. It reports the highest number ISSUED,
// so the warning "this will print as #N+1" matches what Reserve will hand out.
func TestCountsForOrder_ReportsPerScope(t *testing.T) {
	j := newTestJournal(t)

	for range 2 {
		if _, err := j.Reserve(Entry{Kind: KindRedInvoice, OrderID: "o-1", PaymentID: "pay-a"}, Scope{PaymentID: "pay-a"}); err != nil {
			t.Fatalf("reserve pay-a: %v", err)
		}
	}
	if _, err := j.Reserve(Entry{Kind: KindRedInvoice, OrderID: "o-1"}, Scope{OrderIDs: []string{"o-1"}}); err != nil {
		t.Fatalf("reserve order scope: %v", err)
	}
	// A different kind must not leak into the tally.
	if _, err := j.Reserve(Entry{Kind: KindReceipt, OrderID: "o-1", PaymentID: "pay-a"}, Scope{PaymentID: "pay-a"}); err != nil {
		t.Fatalf("reserve receipt: %v", err)
	}

	order, byPayment, err := j.CountsForOrder(KindRedInvoice, []string{"o-1"})
	if err != nil {
		t.Fatalf("counts: %v", err)
	}
	if order.Count != 1 {
		t.Errorf("order scope count = %d, want 1", order.Count)
	}
	if len(byPayment) != 1 || byPayment[0].PaymentID != "pay-a" || byPayment[0].Count != 2 {
		t.Errorf("byPayment = %+v, want one row pay-a with count 2", byPayment)
	}
}
