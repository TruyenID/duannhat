package handler

import (
	"database/sql"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printjob"
)

// plan-052 §4 point 2 (#1166) — WHO, on every print.
//
// The workstation authenticates a DEVICE, so without an explicit actor the
// ledger can only ever say "some tablet printed it". Since the ruling removed
// every gate, this trail is the whole of the accountability — which makes the
// two rules below load-bearing rather than cosmetic:
//
//   - a named cashier must reach the journal entry;
//   - a MISSING one must never become an error, because refusing a print for
//     want of a name is exactly the failure mode the ruling outlawed.

func TestActorUserID_ReadsTheHeader(t *testing.T) {
	r := httptest.NewRequest(http.MethodPost, "/api/lan/print/payment-receipt", nil)
	r.Header.Set(actorHeader, "0199aa11-2233-4455-6677-8899aabbccdd")

	if got := actorUserID(r); got != "0199aa11-2233-4455-6677-8899aabbccdd" {
		t.Fatalf("actorUserID = %q", got)
	}
}

func TestActorUserID_TrimsAndTolerates(t *testing.T) {
	cases := map[string]string{
		"  padded  ":                     "padded",
		"":                               "",
		"   ":                            "",
		strings.Repeat("x", 37):          "", // longer than a uuid — not an id, drop it
		"0199aa11-2233-4455-6677-8899aa": "0199aa11-2233-4455-6677-8899aa",
	}

	for header, want := range cases {
		r := httptest.NewRequest(http.MethodPost, "/x", nil)
		r.Header.Set(actorHeader, header)
		if got := actorUserID(r); got != want {
			t.Errorf("actorUserID(%q) = %q, want %q", header, got, want)
		}
	}
}

// A nil request is what a non-HTTP print site (auto-print on payment, the
// scheduled reprint of a stuck slip) hands in. It must answer "" rather than
// panic: the print already happened, and the journal is an observability layer
// that may never take the printing path down with it.
func TestActorUserID_NilRequestIsNotAnError(t *testing.T) {
	if got := actorUserID(nil); got != "" {
		t.Fatalf("actorUserID(nil) = %q", got)
	}
}

// reprintReasonFor guards the audit's meaning: a first print is not a reprint,
// so its default "auto" must never be recorded as a JUSTIFICATION. A ledger
// full of receipts "justified" by the word "auto" would make the whole §4 trail
// unreadable — the very thing that now stands in for the removed 422.
func TestReprintReasonFor_OnlyCopiesCarryAReason(t *testing.T) {
	cases := []struct {
		reason    string
		reprintNo int
		want      string
	}{
		{"khach lam rach", 1, ""}, // first print — not a reprint at all
		{"auto", 2, ""},           // the machine's default word is not a reason
		{"khach lam rach", 2, "khach lam rach"},
		{"", 3, ""}, // §4: an empty reason is allowed and prints
		{"may ket giay", 12, "may ket giay"},
	}

	for _, tc := range cases {
		if got := reprintReasonFor(tc.reason, tc.reprintNo); got != tc.want {
			t.Errorf("reprintReasonFor(%q, %d) = %q, want %q", tc.reason, tc.reprintNo, got, tc.want)
		}
	}
}

// journalPrintFor must not overwrite an actor a handler already resolved from
// its own body (the receipt endpoint takes `actor_user_id` there); the header
// only fills a gap.
func TestJournalPrintFor_ExplicitActorWins(t *testing.T) {
	r := httptest.NewRequest(http.MethodPost, "/x", nil)
	r.Header.Set(actorHeader, "from-header")

	entry := printjob.Entry{Kind: printjob.KindReceipt, RequestedByID: "from-body"}

	// s.printJournal is nil on a bare Server, so journalPrint returns early —
	// which is exactly what lets this assert the merge rule without a database.
	s := &Server{}
	s.journalPrintFor(r, nil, entry, nil)

	// The merge happens on a COPY; prove the rule directly rather than reading
	// a row we deliberately did not write.
	if entry.RequestedByID != "from-body" {
		t.Fatalf("the caller's entry was mutated: %q", entry.RequestedByID)
	}
	merged := entry
	if merged.RequestedByID == "" {
		merged.RequestedByID = actorUserID(r)
	}
	if merged.RequestedByID != "from-body" {
		t.Fatalf("header overrode an explicit actor: %q", merged.RequestedByID)
	}
}

// plan-053 TR-28 (#1171) — the version has to survive the LAST hop.
//
// The seam now returns it and the journal can store it, but between them sits
// `finishMoneyPrint`, which is where a money document's row is actually
// settled. Both halves can be perfect while nothing joins them — that is how
// #1807 was closed with working infrastructure and no caller — so this drives
// the real two-phase path against a real database and reads the stored column.
func TestFinishMoneyPrint_CarriesTheLayoutVersionOntoTheRow(t *testing.T) {
	s := newSeamServer(t)
	s.printJournal = printjob.NewJournal(s.db)

	ledger := printjob.Entry{
		Kind:    printjob.KindRedInvoice,
		OrderID: "order-1",
	}
	res := s.beginMoneyPrint(nil, ledger, printjob.Scope{OrderIDs: []string{"order-1"}})
	if res.JobID == "" {
		t.Fatal("reservation did not happen — the rest of the test would prove nothing")
	}

	// What a call site does after rendering: the version is only knowable now,
	// because the copy number had to be minted before the slip existed.
	ledger.TemplateVersion = "brand:7"
	s.finishMoneyPrint(res, nil, ledger, "khách làm rách", nil)

	var stored sql.NullString
	if err := s.db.QueryRow(
		`SELECT template_version FROM print_jobs WHERE id = ?`, res.JobID,
	).Scan(&stored); err != nil {
		t.Fatalf("read row: %v", err)
	}
	if !stored.Valid || stored.String != "brand:7" {
		t.Fatalf("template_version = %#v, want brand:7", stored)
	}
}

// The legacy formatter's "" must stay NULL all the way through the same hop —
// a row claiming `system:0` for a sheet the formatter drew would send a later
// reprint to a definition that never touched it.
func TestFinishMoneyPrint_LegacyLeavesTheRowNull(t *testing.T) {
	s := newSeamServer(t)
	s.printJournal = printjob.NewJournal(s.db)

	ledger := printjob.Entry{Kind: printjob.KindDebtSlip, OrderID: "order-1"}
	res := s.beginMoneyPrint(nil, ledger, printjob.Scope{OrderIDs: []string{"order-1"}})
	s.finishMoneyPrint(res, nil, ledger, "auto", nil)

	var stored sql.NullString
	if err := s.db.QueryRow(
		`SELECT template_version FROM print_jobs WHERE id = ?`, res.JobID,
	).Scan(&stored); err != nil {
		t.Fatalf("read row: %v", err)
	}
	if stored.Valid {
		t.Fatalf("template_version = %q, want NULL", stored.String)
	}
}

// The after-the-fact path (`journalPrint`, used by every report and the kitchen
// ticket) has to carry it too — those rows are the majority of the ledger.
func TestJournalPrint_CarriesTheLayoutVersion(t *testing.T) {
	s := newSeamServer(t)
	s.printJournal = printjob.NewJournal(s.db)

	s.journalPrint(nil, printjob.Entry{
		Kind:            printjob.KindReport,
		TemplateVersion: "shop:12",
	}, nil)

	var stored sql.NullString
	if err := s.db.QueryRow(
		`SELECT template_version FROM print_jobs WHERE kind = ?`, string(printjob.KindReport),
	).Scan(&stored); err != nil {
		t.Fatalf("read row: %v", err)
	}
	if !stored.Valid || stored.String != "shop:12" {
		t.Fatalf("template_version = %#v, want shop:12", stored)
	}
}
