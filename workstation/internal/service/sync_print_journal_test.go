package service

import (
	"context"
	"errors"
	"path/filepath"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printjob"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// plan-052 T1.2 (#1166) — draining the local journal UP to the Cloud ledger.
//
// A fake `post` stands in for Cloud (plan-052 TESTS: no real HTTP in tests), so
// these exercise the behaviour that actually costs a shop something: a retry
// that never settles, or a print that vanishes from history.

type fakeCloud struct {
	calls    []map[string]any
	response map[string]any
	err      error
	retry    bool
}

func (f *fakeCloud) post(_ context.Context, _, _, _ string, body map[string]any) (map[string]any, bool, error) {
	f.calls = append(f.calls, body)
	return f.response, f.retry, f.err
}

func (f *fakeCloud) sentIDs() []string {
	var ids []string
	for _, call := range f.calls {
		jobs, _ := call["jobs"].([]map[string]any)
		for _, j := range jobs {
			ids = append(ids, j["id"].(string))
		}
	}
	return ids
}

func newJournalUnderTest(t *testing.T) *printjob.Journal {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "sync.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return printjob.NewJournal(db)
}

func acceptAll(ids ...string) map[string]any {
	list := make([]any, len(ids))
	for i, id := range ids {
		list[i] = id
	}
	return map[string]any{"data": map[string]any{"accepted": list, "duplicates": []any{}}}
}

func TestPrintJournalSync_PushesEveryUnsyncedRowInOneBatch(t *testing.T) {
	j := newJournalUnderTest(t)

	var ids []string
	for i := 0; i < 3; i++ {
		id, err := j.Record(printjob.Entry{Kind: printjob.KindKitchen})
		if err != nil {
			t.Fatalf("record: %v", err)
		}
		ids = append(ids, id)
	}

	cloud := &fakeCloud{response: acceptAll(ids...)}
	h := NewPrintJournalSyncHandler(j, cloud.post)

	if _, retry, err := h.Handle(context.Background(), ids[0], nil); err != nil || retry {
		t.Fatalf("handle: err=%v retry=%v", err, retry)
	}

	if len(cloud.calls) != 1 {
		t.Fatalf("made %d requests for 3 rows — a busy evening must not be one request per slip", len(cloud.calls))
	}
	if got := len(cloud.sentIDs()); got != 3 {
		t.Errorf("sent %d ids, want 3", got)
	}

	remaining, _ := j.PendingCount()
	if remaining != 0 {
		t.Errorf("%d rows still pending after a successful push", remaining)
	}
}

// P-09 — a duplicate is SUCCESS. Treating it as a failure is how a retry loop
// spins forever on a row Cloud already recorded perfectly well.
func TestPrintJournalSync_DuplicateIsSuccessNotRetry(t *testing.T) {
	j := newJournalUnderTest(t)
	id, _ := j.Record(printjob.Entry{Kind: printjob.KindReceipt})

	cloud := &fakeCloud{response: map[string]any{
		"data": map[string]any{
			"accepted":   []any{},
			"duplicates": []any{id},
		},
	}}
	h := NewPrintJournalSyncHandler(j, cloud.post)

	_, retry, err := h.Handle(context.Background(), id, nil)
	if err != nil || retry {
		t.Fatalf("a duplicate must settle the queue row: err=%v retry=%v", err, retry)
	}

	synced, _ := j.IsSynced(id)
	if !synced {
		t.Error("a row Cloud already holds must be marked synced, or it is pushed forever")
	}
}

func TestPrintJournalSync_CloudDownKeepsTheRowForLater(t *testing.T) {
	j := newJournalUnderTest(t)
	id, _ := j.Record(printjob.Entry{Kind: printjob.KindKitchen})

	cloud := &fakeCloud{err: errors.New("cloud unreachable"), retry: true}
	h := NewPrintJournalSyncHandler(j, cloud.post)

	_, retry, err := h.Handle(context.Background(), id, nil)
	if err == nil || !retry {
		t.Fatal("an unreachable Cloud must be retryable, not swallowed")
	}

	// The print already happened. Losing the row would erase history that
	// exists nowhere else.
	if n, _ := j.PendingCount(); n != 1 {
		t.Errorf("pending = %d, want the row kept for the next drain", n)
	}
}

// An empty batch is the NORMAL outcome once batching kicks in: N queue rows,
// one drain. It must not look like an error.
func TestPrintJournalSync_AlreadyDrainedIsANoOp(t *testing.T) {
	j := newJournalUnderTest(t)
	id, _ := j.Record(printjob.Entry{Kind: printjob.KindKitchen})
	_ = j.MarkSynced([]string{id})

	cloud := &fakeCloud{response: acceptAll()}
	h := NewPrintJournalSyncHandler(j, cloud.post)

	_, retry, err := h.Handle(context.Background(), id, nil)
	if err != nil || retry {
		t.Fatalf("a drained journal must be a clean no-op: err=%v retry=%v", err, retry)
	}
	if len(cloud.calls) != 0 {
		t.Error("nothing pending must mean no request at all")
	}
}

// If Cloud answers 200 but acknowledges nothing, the safe reading is "it did
// not land" — marking rows synced on that basis would silently lose history.
func TestPrintJournalSync_EmptyAcknowledgementRetries(t *testing.T) {
	j := newJournalUnderTest(t)
	id, _ := j.Record(printjob.Entry{Kind: printjob.KindReceipt})

	cloud := &fakeCloud{response: map[string]any{"data": map[string]any{}}}
	h := NewPrintJournalSyncHandler(j, cloud.post)

	_, retry, err := h.Handle(context.Background(), id, nil)
	if err == nil || !retry {
		t.Fatal("an empty acknowledgement must retry, never mark synced")
	}
	if synced, _ := j.IsSynced(id); synced {
		t.Error("nothing may be marked synced on an unacknowledged push")
	}
}

// P-07 — the body carries the real print time, and the audit fields survive.
func TestPrintJournalSync_BodyCarriesTheAuditTrail(t *testing.T) {
	j := newJournalUnderTest(t)
	id, _ := j.Record(printjob.Entry{
		Kind:          printjob.KindReceipt,
		OrderID:       "order-1",
		PaymentID:     "pay-1",
		ReprintNo:     2,
		ReprintReason: "khách làm rách hoá đơn",
		RequestedVia:  "pos",
	})

	cloud := &fakeCloud{response: acceptAll(id)}
	h := NewPrintJournalSyncHandler(j, cloud.post)
	if _, _, err := h.Handle(context.Background(), id, nil); err != nil {
		t.Fatalf("handle: %v", err)
	}

	jobs := cloud.calls[0]["jobs"].([]map[string]any)
	job := jobs[0]

	for key, want := range map[string]any{
		"id":             id,
		"kind":           string(printjob.KindReceipt),
		"order_id":       "order-1",
		"payment_id":     "pay-1",
		"reprint_no":     2,
		"reprint_reason": "khách làm rách hoá đơn",
		"requested_via":  "pos",
		"confidence":     string(printjob.ConfidenceSentOnly),
	} {
		if job[key] != want {
			t.Errorf("body[%q] = %v, want %v", key, job[key], want)
		}
	}
	if job["printed_at"] == "" || job["printed_at"] == nil {
		t.Error("printed_at must always be sent — it is the whole point of P-07")
	}
	// Fields that are empty must be omitted rather than sent as "", so Cloud's
	// nullable columns stay null instead of holding an empty string.
	if _, present := job["last_error"]; present {
		t.Error("an empty last_error must be omitted, not sent blank")
	}
}

// The handler is registered under the key the queue dispatches on. Without the
// registration pushToCloud silently drains the row as a no-op "success" — the
// exact class of bug behind #534.
func TestRegisterPrintJournal_WiresTheDispatchKey(t *testing.T) {
	j := newJournalUnderTest(t)
	e := NewSyncEngine(nil, "", nil)

	e.RegisterPrintJournal(j)

	if !e.HasHandler(PrintJournalOp) {
		t.Fatalf("no handler registered for %q — journal rows would drain into nothing", PrintJournalOp)
	}
}

func TestRegisterPrintJournal_NilJournalIsSafe(t *testing.T) {
	e := NewSyncEngine(nil, "", nil)
	e.RegisterPrintJournal(nil) // headless boot / tests

	if e.HasHandler(PrintJournalOp) {
		t.Error("no journal means no handler")
	}
}

// plan-053 TR-28 (#1171) — the layout version rides UP with the row.
//
// Both halves matter and the second is the one that rots quietly: a stamp that
// is present must arrive, and an ABSENT stamp must arrive as absent. Sending
// `""` would let Cloud store an empty string in a column whose NULL means
// "drawn by the legacy formatter", and from then on nothing could tell the two
// apart on either side.
func TestPrintJournalSync_BodyCarriesTheLayoutVersion(t *testing.T) {
	j := newJournalUnderTest(t)
	id, _ := j.Record(printjob.Entry{
		Kind:            printjob.KindRedInvoice,
		TemplateVersion: "brand:7",
	})

	cloud := &fakeCloud{response: acceptAll(id)}
	h := NewPrintJournalSyncHandler(j, cloud.post)
	if _, _, err := h.Handle(context.Background(), id, nil); err != nil {
		t.Fatalf("handle: %v", err)
	}

	job := cloud.calls[0]["jobs"].([]map[string]any)[0]
	if job["template_version"] != "brand:7" {
		t.Errorf("body[template_version] = %v, want brand:7", job["template_version"])
	}
}

func TestPrintJournalSync_LegacyRowOmitsTheLayoutVersion(t *testing.T) {
	j := newJournalUnderTest(t)
	id, _ := j.Record(printjob.Entry{Kind: printjob.KindReceipt})

	cloud := &fakeCloud{response: acceptAll(id)}
	h := NewPrintJournalSyncHandler(j, cloud.post)
	if _, _, err := h.Handle(context.Background(), id, nil); err != nil {
		t.Fatalf("handle: %v", err)
	}

	job := cloud.calls[0]["jobs"].([]map[string]any)[0]
	if v, present := job["template_version"]; present {
		t.Errorf("a sheet drawn by the legacy formatter has no version; "+
			"sending %v makes Cloud store a value that identifies nothing", v)
	}
}
