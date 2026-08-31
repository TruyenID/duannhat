package printjob

import (
	"database/sql"
	"testing"
	"time"
)

// plan-053 TR-28 (#1171) — the layout version on the journal row.
//
// What these tests defend is ONE distinction, and it is the whole point of the
// column: a row that records no layout (NULL) must stay tellable apart from a
// row that records one. Collapse them and a reprint of a legacy-formatter sheet
// goes looking for a template version that never drew it — which is precisely
// the silent divergence ("phiếu in lại khác phiếu gốc, không ai phát hiện") the
// column exists to prevent.

// templateVersionOf reads the raw column, NULL included. The SyncPayload view
// cannot answer this on its own: it flattens NULL and the empty string into one Go
// string, so a test written only against it would pass on the exact bug.
func templateVersionOf(t *testing.T, j *Journal, id string) sql.NullString {
	t.Helper()

	var v sql.NullString
	if err := j.db.QueryRow(`SELECT template_version FROM print_jobs WHERE id = ?`, id).Scan(&v); err != nil {
		t.Fatalf("read template_version: %v", err)
	}

	return v
}

func TestRecord_StampsTheLayoutVersion(t *testing.T) {
	j := newTestJournal(t)

	id, err := j.Record(Entry{
		Kind:            KindReceipt,
		TemplateVersion: "brand:7",
	})
	if err != nil {
		t.Fatalf("record: %v", err)
	}

	got := templateVersionOf(t, j, id)
	if !got.Valid || got.String != "brand:7" {
		t.Fatalf("template_version = %#v, want brand:7", got)
	}
}

// The legacy formatter has NO version — it is code, not a published definition.
// The row must say so with NULL, not with an empty string that reads like a
// stamp identifying nothing.
func TestRecord_LegacyFormatterLeavesTheVersionNull(t *testing.T) {
	j := newTestJournal(t)

	id, err := j.Record(Entry{Kind: KindReceipt})
	if err != nil {
		t.Fatalf("record: %v", err)
	}

	if got := templateVersionOf(t, j, id); got.Valid {
		t.Fatalf("template_version = %q, want NULL — the legacy formatter has no version to record", got.String)
	}
}

// The drain carries the stamp UP, and carries the absence of one up as an
// absence.
func TestPending_CarriesTheLayoutVersion(t *testing.T) {
	j := newTestJournal(t)

	stampedID, err := j.Record(Entry{
		Kind:            KindRedInvoice,
		TemplateVersion: "shop:12",
		PrintedAt:       time.Now().Add(-2 * time.Minute),
	})
	if err != nil {
		t.Fatalf("record stamped: %v", err)
	}
	legacyID, err := j.Record(Entry{
		Kind:      KindRedInvoice,
		PrintedAt: time.Now().Add(-1 * time.Minute),
	})
	if err != nil {
		t.Fatalf("record legacy: %v", err)
	}

	pending, err := j.Pending(10)
	if err != nil {
		t.Fatalf("pending: %v", err)
	}
	if len(pending) != 2 {
		t.Fatalf("pending = %d rows, want 2", len(pending))
	}

	// Indexed by id, NOT by position. `Pending` sorts on `created_at, id`, and
	// created_at is RFC3339 SECOND precision — two rows written in the same
	// second tie-break on a random uuid. A positional assertion here passed on
	// the first run and failed on the full suite, which is the worst possible
	// version of this bug: it looks like a real regression somewhere else.
	byID := map[string]SyncPayload{}
	for _, p := range pending {
		byID[p.ID] = p
	}

	if got := byID[stampedID].TemplateVersion; got != "shop:12" {
		t.Errorf("stamped row = %q, want shop:12", got)
	}
	if got := byID[legacyID].TemplateVersion; got != "" {
		t.Errorf("legacy row = %q, want empty", got)
	}
}

// ── The two-phase money path ──────────────────────────────────────────────
//
// `Reserve` runs BEFORE the slip is rendered (the copy number has to be on the
// paper), so the version cannot be known at that point. It arrives at Confirm.
// A test that only exercised Record would miss every money document — which is
// exactly the set of sheets TR-28 is about.

func TestConfirm_SettlesTheLayoutVersion(t *testing.T) {
	j := newTestJournal(t)

	res, err := j.Reserve(Entry{Kind: KindRedInvoice, OrderID: "o1"}, Scope{OrderIDs: []string{"o1"}})
	if err != nil {
		t.Fatalf("reserve: %v", err)
	}

	if got := templateVersionOf(t, j, res.JobID); got.Valid {
		t.Fatalf("reservation already carries %q — nothing has been rendered yet, "+
			"so any value here is a claim about a slip that does not exist", got.String)
	}

	if err := j.Confirm(res.JobID, Outcome{
		Status:          StatusPrinted,
		TemplateVersion: "brand:3",
	}); err != nil {
		t.Fatalf("confirm: %v", err)
	}

	got := templateVersionOf(t, j, res.JobID)
	if !got.Valid || got.String != "brand:3" {
		t.Fatalf("template_version = %#v, want brand:3", got)
	}
}

// An empty Outcome version means "the legacy formatter drew it", and the COALESCE
// in Confirm must leave the column NULL rather than write "".
func TestConfirm_EmptyVersionLeavesTheColumnNull(t *testing.T) {
	j := newTestJournal(t)

	res, err := j.Reserve(Entry{Kind: KindDebtSlip, OrderID: "o1"}, Scope{OrderIDs: []string{"o1"}})
	if err != nil {
		t.Fatalf("reserve: %v", err)
	}
	if err := j.Confirm(res.JobID, Outcome{Status: StatusPrinted}); err != nil {
		t.Fatalf("confirm: %v", err)
	}

	if got := templateVersionOf(t, j, res.JobID); got.Valid {
		t.Fatalf("template_version = %q, want NULL", got.String)
	}
}

// A FAILED print still records which layout was attempted. The ledger records
// attempts (§4), and "we tried to draw it with brand:9" is as true of a jam as
// of a clean sheet — a reprint investigation needs it most when the first
// attempt went wrong.
func TestConfirm_FailedPrintStillRecordsTheLayout(t *testing.T) {
	j := newTestJournal(t)

	res, err := j.Reserve(Entry{Kind: KindReceipt, OrderID: "o1"}, Scope{OrderIDs: []string{"o1"}})
	if err != nil {
		t.Fatalf("reserve: %v", err)
	}
	if err := j.Confirm(res.JobID, Outcome{
		Status:          StatusFailed,
		LastError:       "paper out",
		TemplateVersion: "brand:9",
	}); err != nil {
		t.Fatalf("confirm: %v", err)
	}

	got := templateVersionOf(t, j, res.JobID)
	if !got.Valid || got.String != "brand:9" {
		t.Fatalf("template_version = %#v, want brand:9 on a failed print too", got)
	}
}
