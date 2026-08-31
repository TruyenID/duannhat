package handler

import (
	"os"
	"strings"
	"testing"
)

// #1951 — the cash drawer.
//
// The defect these pin: `KickDrawer` existed, was profile-aware and correct, and
// was called from exactly ONE place — the printer setup wizard. So on a cash POS
// the till never opened during a sale. Nothing was broken; nothing was wired.
//
// The tests below are mostly about the RULINGS rather than the pulse, because
// the pulse was never the hard part:
//
//   - cash only (a card sale opening the till is a shrinkage hole)
//   - keyed to the PAYMENT, never to the slip (a reprint must not pop the till)
//   - a machine with no drawer is a valid shop, not an error

func TestOrderHasCashPayment_UsesTheLocalColumnNames(t *testing.T) {
	// The query is asserted as text on purpose. The first draft used
	// `customer_order_id` / `payment_method_code` — the CLOUD names — which
	// SQLite rejects at RUNTIME, inside a goroutine on the payment path where
	// nobody is watching. The visible symptom would have been "the till just
	// never opens", i.e. indistinguishable from the bug being fixed.
	src := readSourceFile(t, "drawer.go")

	for _, cloudOnly := range []string{"customer_order_id", "payment_method_code"} {
		if strings.Contains(sqlOf(src), cloudOnly) {
			t.Fatalf("drawer query uses the Cloud column %q; locally it is order_id / payment_method", cloudOnly)
		}
	}

	for _, want := range []string{"order_id = ?", "payment_method", "'cash'"} {
		if !strings.Contains(sqlOf(src), want) {
			t.Fatalf("drawer query lost %q", want)
		}
	}
}

func TestDrawer_ActiveStatusSetMatchesTheTillReconciliation(t *testing.T) {
	// `local_pos_till.go` already decides what counts as money in the drawer.
	// If this file's idea of an active payment drifts from that one, the till
	// opens for a payment the shift count will not include — or worse, stays
	// shut for one it will.
	drawer := sqlOf(readSourceFile(t, "drawer.go"))
	if !strings.Contains(drawer, "'pending', 'confirmed', 'succeeded'") &&
		!strings.Contains(drawer, "'pending','confirmed','succeeded'") {
		t.Fatal("drawer.go no longer uses the till's active-status set")
	}
}

func TestDrawer_IsNotCalledFromAnyPrintHandler(t *testing.T) {
	// THE load-bearing ruling. Hang the kick off printing and a REPRINT pops the
	// till: a receipt reprinted at 22:00 for a 19:00 order moves no money, and a
	// drawer that springs open because somebody pressed "print again" is a theft
	// window.
	//
	// Asserted structurally rather than behaviourally because the failure is a
	// future edit, not a current bug: someone adding the kick "next to the other
	// print stuff" is the natural mistake, and it would look right in review.
	// The list is DISCOVERED, not typed. My hand-written version listed six
	// files and missed `lan_local.go` — which is where `handleLANPrintReceipt`
	// actually lives, i.e. the single most likely place for someone to add the
	// kick "next to the other print stuff". A mutation proved the gap: planting
	// a call there left this test green.
	//
	// `auto_print.go` is excluded because it IS the payment path — see the
	// companion test below.
	for _, file := range printHandlerFiles(t) {
		if file == "auto_print.go" {
			continue
		}

		src := readSourceFile(t, file)
		if strings.Contains(src, "kickDrawerForCashPayment") || strings.Contains(src, "openCashDrawer") {
			t.Fatalf("%s calls the drawer: the kick must key off the PAYMENT, "+
				"otherwise a reprint opens the till", file)
		}
	}
}

// printHandlerFiles finds every file that owns a print entry point, so the
// guard above cannot rot as files are added or handlers move between them.
func printHandlerFiles(t *testing.T) []string {
	t.Helper()

	entries, err := os.ReadDir(".")
	if err != nil {
		t.Fatalf("read handler dir: %v", err)
	}

	markers := []string{
		"func (s *Server) handleLANPrint",
		"func (s *Server) printPaymentReceipt",
		"func (s *Server) autoPrintPaymentReceipt",
		"func (s *Server) fireKitchenForOrder",
	}

	var out []string
	for _, e := range entries {
		name := e.Name()
		if e.IsDir() || !strings.HasSuffix(name, ".go") || strings.HasSuffix(name, "_test.go") {
			continue
		}
		src := readSourceFile(t, name)
		for _, m := range markers {
			if strings.Contains(src, m) {
				out = append(out, name)

				break
			}
		}
	}

	if len(out) < 5 {
		t.Fatalf("only found %d print-handler files (%v) — the markers have drifted", len(out), out)
	}

	return out
}

func TestDrawer_IsCalledFromThePaymentPath(t *testing.T) {
	// The converse of the test above. Without this, deleting the single call
	// site restores the original bug — a drawer that is never asked to open —
	// and every other test here would still pass.
	src := readSourceFile(t, "auto_print.go")
	if !strings.Contains(src, "kickDrawerForCashPayment") {
		t.Fatal("nothing on the payment path opens the drawer — this is the #1951 bug itself")
	}
}

func TestDrawer_ManualOpenIsAudited(t *testing.T) {
	// Third ruling. The trade does not control WHO may open a till — refusing a
	// cashier change makes them wedge the drawer open all shift, which is worse
	// — it controls that every open carries a name. "No-sale count" is the
	// classic loss-prevention metric and it needs the rows to exist.
	src := readSourceFile(t, "drawer.go")
	if !strings.Contains(src, `s.auditLog(r, "drawer.open"`) {
		t.Fatal("the manual open no longer audits")
	}

	// And it must audit even when nothing opened: "tried to open the till and
	// the machine refused" is exactly as interesting to a review as a success.
	openFn := src[strings.Index(src, "func (s *Server) handleLANDrawerOpen"):]
	auditAt := strings.Index(openFn, "s.auditLog(")
	errorReturnAt := strings.Index(openFn, "writeServerError(")
	if auditAt < 0 || errorReturnAt < 0 || auditAt > errorReturnAt {
		t.Fatal("the audit must be written BEFORE the error return, or a failed open leaves no trace")
	}
}

func TestDrawer_NoDrawerIsNotAnError(t *testing.T) {
	// A cafe with a cash box under the counter is a supported configuration,
	// exactly like a shop with no printer. Returning an error here would put a
	// red toast in front of a cashier for a machine behaving correctly.
	src := readSourceFile(t, "drawer.go")

	body := src[strings.Index(src, "func (s *Server) openCashDrawer"):]
	body = body[:strings.Index(body, "\nfunc ")]

	// COUNT them, do not merely look for one. `openCashDrawer` has TWO guards
	// that answer "this machine has no drawer" — the profile flag and the
	// encoder's own re-check — and a `Contains` test is satisfied by either.
	// Measured: turning the first into an error left this test green, because
	// the second still matched. An assertion that passes while the thing it
	// guards is half-broken is the failure mode this whole file is about.
	if n := strings.Count(body, `Reason: "drawer_not_supported"}, nil`); n != 2 {
		t.Fatalf("both no-drawer guards must return (not-kicked, nil); found %d of 2", n)
	}
	if !strings.Contains(body, `Reason: "no_receipt_printer"}, nil`) {
		t.Fatal("a shop with no receipt printer must return (not-kicked, nil) — not an error")
	}

	// And nothing in here may MINT an error: the only errors this function may
	// surface are the printer's own (Connect / Print). A synthesised one turns a
	// correctly-behaving cash-box shop into a red toast at the counter.
	if strings.Contains(body, "errors.New(") || strings.Contains(body, "fmt.Errorf(") {
		t.Fatal("openCashDrawer minted an error; it may only propagate the printer's")
	}
}

// ── helpers ────────────────────────────────────────────────────────────────
//
// These tests read SOURCE rather than exercising behaviour, which is unusual
// and deliberate. What they guard is not "does the pulse come out" — that is
// `escpos`'s own test — but WHERE the call sits. A behavioural test cannot see
// the difference between a kick on the payment event and a kick on the print
// event; both open the drawer on the happy path, and the reprint hole only
// appears months later on a machine in a shop.

func readSourceFile(t *testing.T, name string) string {
	t.Helper()

	body, err := os.ReadFile(name)
	if err != nil {
		t.Fatalf("read %s: %v", name, err)
	}

	return string(body)
}

// sqlOf strips Go comments so a column name MENTIONED in prose (this file's own
// docblocks name the Cloud columns in order to warn about them) is not mistaken
// for one used in a query.
func sqlOf(src string) string {
	var b strings.Builder
	for _, line := range strings.Split(src, "\n") {
		if strings.HasPrefix(strings.TrimSpace(line), "//") {
			continue
		}
		b.WriteString(line)
		b.WriteString("\n")
	}

	return b.String()
}
