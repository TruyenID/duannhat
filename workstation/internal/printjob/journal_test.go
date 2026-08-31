package printjob

import (
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// plan-052 T1.2 (#1166) — the local print journal.
//
// These tests defend the two things Cloud cannot fix later: the TIME a print
// really happened (P-07), and the fact that a replay of the same job is the
// same job (P-09).

func newTestJournal(t *testing.T) *Journal {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "journal.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return NewJournal(db)
}

// P-07 — an offline evening must not land on tomorrow's business day.
func TestRecord_KeepsTheRealPrintTime(t *testing.T) {
	j := newTestJournal(t)

	// The shop printed at 20:15 last night; the uplink came back this morning.
	printedAt := time.Now().Add(-13 * time.Hour)

	id, err := j.Record(Entry{
		Kind:      KindReceipt,
		PrintedAt: printedAt,
	})
	if err != nil {
		t.Fatalf("record: %v", err)
	}
	if id == "" {
		t.Fatal("record must return the client-generated id — it is the idempotency key")
	}

	pending, err := j.Pending(10)
	if err != nil {
		t.Fatalf("pending: %v", err)
	}
	if len(pending) != 1 {
		t.Fatalf("pending = %d rows, want 1", len(pending))
	}

	got, err := time.Parse(time.RFC3339, pending[0].PrintedAt)
	if err != nil {
		t.Fatalf("printed_at is not RFC3339: %v", err)
	}
	if diff := got.Sub(printedAt); diff > time.Second || diff < -time.Second {
		t.Errorf("printed_at = %s, want the real print time %s (drift %s)",
			got, printedAt, diff)
	}
}

func TestRecord_DefaultsAreTheSafeOnes(t *testing.T) {
	j := newTestJournal(t)

	if _, err := j.Record(Entry{Kind: KindKitchen}); err != nil {
		t.Fatalf("record: %v", err)
	}

	pending, _ := j.Pending(10)
	p := pending[0]

	if p.Status != string(StatusPrinted) {
		t.Errorf("status = %q, want printed", p.Status)
	}
	if p.Confidence != string(ConfidenceSentOnly) {
		t.Errorf("confidence = %q — an unqualified print may only ever be sent_only (P-33)", p.Confidence)
	}
	if p.ReprintNo != 1 {
		t.Errorf("reprint_no = %d, want 1", p.ReprintNo)
	}
	if p.RequestedVia != "workstation" {
		t.Errorf("requested_via = %q, want workstation", p.RequestedVia)
	}
}

func TestRecord_RejectsAKindlessEntry(t *testing.T) {
	j := newTestJournal(t)

	// Kind drives the retry matrix and the TTL on the Cloud side. A row without
	// one has no policy at all, so it must never be written.
	if _, err := j.Record(Entry{}); err == nil {
		t.Fatal("a journal entry with no kind must be refused")
	}
}

// P-09 — every id is unique, so a replay collides at the primary key rather
// than relying on an application check a concurrent retry can race past.
func TestRecord_MintsAUniqueIdPerPrint(t *testing.T) {
	j := newTestJournal(t)

	seen := map[string]bool{}
	for i := 0; i < 25; i++ {
		id, err := j.Record(Entry{Kind: KindKitchen})
		if err != nil {
			t.Fatalf("record %d: %v", i, err)
		}
		if seen[id] {
			t.Fatalf("duplicate job id %s — idempotency would collapse two real prints into one", id)
		}
		seen[id] = true
	}
}

func TestPending_OldestFirstAndBounded(t *testing.T) {
	j := newTestJournal(t)

	for i := 0; i < 5; i++ {
		if _, err := j.Record(Entry{Kind: KindKitchen}); err != nil {
			t.Fatalf("record: %v", err)
		}
	}

	page, err := j.Pending(3)
	if err != nil {
		t.Fatalf("pending: %v", err)
	}
	if len(page) != 3 {
		t.Fatalf("pending(3) returned %d rows — a long outage must not be pushed in one request", len(page))
	}
}

func TestMarkSynced_RemovesRowsFromTheDrain(t *testing.T) {
	j := newTestJournal(t)

	first, _ := j.Record(Entry{Kind: KindKitchen})
	second, _ := j.Record(Entry{Kind: KindReceipt})

	if err := j.MarkSynced([]string{first}); err != nil {
		t.Fatalf("mark synced: %v", err)
	}

	pending, _ := j.Pending(10)
	if len(pending) != 1 || pending[0].ID != second {
		t.Fatalf("after syncing one row, pending = %+v, want only %s", pending, second)
	}

	synced, err := j.IsSynced(first)
	if err != nil || !synced {
		t.Errorf("IsSynced(%s) = %v, %v; want true", first, synced, err)
	}
}

func TestMarkSynced_EmptyListIsANoOp(t *testing.T) {
	j := newTestJournal(t)
	if _, err := j.Record(Entry{Kind: KindKitchen}); err != nil {
		t.Fatalf("record: %v", err)
	}

	if err := j.MarkSynced(nil); err != nil {
		t.Fatalf("marking nothing must not error: %v", err)
	}

	pending, _ := j.Pending(10)
	if len(pending) != 1 {
		t.Error("marking nothing must not drain anything")
	}
}

func TestIsSynced_MissingRowIsNothingLeftToPush(t *testing.T) {
	j := newTestJournal(t)

	synced, err := j.IsSynced("a-row-that-never-existed")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !synced {
		t.Error("a row that is gone has nothing left to push; the drain must not retry forever")
	}
}

func TestPendingCount(t *testing.T) {
	j := newTestJournal(t)

	for i := 0; i < 3; i++ {
		j.Record(Entry{Kind: KindKitchen})
	}
	n, err := j.PendingCount()
	if err != nil || n != 3 {
		t.Fatalf("PendingCount = %d, %v; want 3", n, err)
	}
}

func TestRecord_CarriesActorReasonAndPayload(t *testing.T) {
	j := newTestJournal(t)

	if _, err := j.Record(Entry{
		Kind:          KindReceipt,
		OrderID:       "order-1",
		PaymentID:     "pay-1",
		PrinterID:     "printer-1",
		ReprintNo:     3,
		RequestedByID: "user-1",
		RequestedVia:  "pos",
		ReprintReason: "khách làm rách hoá đơn",
		Payload:       map[string]any{"template": "payment_receipt"},
	}); err != nil {
		t.Fatalf("record: %v", err)
	}

	p, _ := j.Pending(1)
	got := p[0]

	if got.OrderID != "order-1" || got.PaymentID != "pay-1" || got.PrinterID != "printer-1" {
		t.Errorf("references lost: %+v", got)
	}
	if got.ReprintNo != 3 || got.ReprintReason != "khách làm rách hoá đơn" || got.RequestedByID != "user-1" {
		t.Errorf("reprint audit lost: %+v — this IS the P-10 trail", got)
	}
	if got.Payload["template"] != "payment_receipt" {
		t.Errorf("payload lost: %+v", got.Payload)
	}
}

// The notifier exists so the journal never has to know about the sync engine.
func TestOnRecorded_FiresAfterTheRowIsCommitted(t *testing.T) {
	j := newTestJournal(t)

	var notified string
	j.OnRecorded(func(jobID string) {
		notified = jobID
		// The row must already be readable when the notifier runs, or the
		// drain it schedules would find nothing.
		pending, err := j.Pending(10)
		if err != nil || len(pending) == 0 {
			t.Errorf("notifier fired before the row was committed: %d rows, %v", len(pending), err)
		}
	})

	id, err := j.Record(Entry{Kind: KindKitchen})
	if err != nil {
		t.Fatalf("record: %v", err)
	}
	if notified != id {
		t.Errorf("notified with %q, want %q", notified, id)
	}
}

func TestKind_MoneyDocuments(t *testing.T) {
	money := []Kind{KindReceipt, KindRedInvoice, KindDebtSlip}
	for _, k := range money {
		if !k.IsMoneyDocument() {
			t.Errorf("%s must be a money document — it must never auto-retry (PR1)", k)
		}
	}

	notMoney := []Kind{KindKitchen, KindBar, KindLabel, KindReport, KindDiagnostic}
	for _, k := range notMoney {
		if k.IsMoneyDocument() {
			t.Errorf("%s is not a money document", k)
		}
	}
}
