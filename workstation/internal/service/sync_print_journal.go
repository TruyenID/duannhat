package service

import (
	"context"
	"fmt"

	"github.com/dxs-platform/workstation-app/internal/printjob"
)

// PrintJournalSyncHandler drains the LOCAL print journal UP to the Cloud
// ledger (plan-052 T1.2, #1166).
//
// Direction matters here and nowhere else in this file: the print already
// happened. This handler is reporting history, so it can be as slow, as
// retried, and as offline-tolerant as it likes — none of that reaches the
// paper. That is the whole point of DESIGN §1b: the queue lives at the tier
// closest to the printer, and Cloud only learns the outcome.
//
// It pushes a BATCH rather than one row per call: a busy evening produces two
// to four jobs per bill, and an evening spent offline produces hundreds at
// once. One queue row triggers a drain of everything still unsynced.
type PrintJournalSyncHandler struct {
	journal *printjob.Journal
	// post mirrors SyncEngine.cloudPost(ctx, path, bearer, idemKey, body). It
	// is a function rather than a *SyncEngine so the handler can be tested
	// against a fake without an HTTP server (plan-052 TESTS: every transport
	// has a fake).
	post func(ctx context.Context, path, bearer, idemKey string, body map[string]any) (map[string]any, bool, error)
	// batchSize bounds one push so a shop coming back after a long outage does
	// not send a single enormous request that times out and retries forever.
	batchSize int
}

func NewPrintJournalSyncHandler(
	journal *printjob.Journal,
	post func(ctx context.Context, path, bearer, idemKey string, body map[string]any) (map[string]any, bool, error),
) *PrintJournalSyncHandler {
	return &PrintJournalSyncHandler{journal: journal, post: post, batchSize: 50}
}

// PrintJournalOp is the sync-queue key for a journal drain.
const PrintJournalOp = "print_job.journal"

// RegisterPrintJournal wires the drain handler and hands the journal the
// notifier it uses to schedule one. Called once at startup.
//
// Note the direction of the dependency: the JOURNAL knows nothing about the
// sync engine — it is handed a callback. That is what keeps the print path
// free of any network dependency, and it is enforced by an architecture test
// (P-08).
func (e *SyncEngine) RegisterPrintJournal(journal *printjob.Journal) {
	if journal == nil {
		return
	}

	handler := NewPrintJournalSyncHandler(journal, e.cloudPost)
	e.RegisterHandler(PrintJournalOp, handler.Handle)

	journal.OnRecorded(func(jobID string) {
		// Enqueue failure is deliberately non-fatal and unreported to the
		// caller: the journal row is already committed, and the next drain
		// picks it up regardless. A print must never surface a sync problem.
		_ = e.Enqueue("print_job", jobID, "journal", map[string]any{"id": jobID}, 9)
	})
}

// Handle implements the syncHandler signature.
//
// The entityID names the row that triggered this drain, but the batch covers
// everything unsynced — so N queued rows collapse into far fewer requests, and
// a row whose own trigger was lost still goes UP on the next one.
func (h *PrintJournalSyncHandler) Handle(ctx context.Context, entityID string, _ map[string]any) (map[string]any, bool, error) {
	if h.journal == nil {
		return nil, false, nil
	}

	pending, err := h.journal.Pending(h.batchSize)
	if err != nil {
		return nil, true, err
	}
	if len(pending) == 0 {
		// Already drained by an earlier batch. Nothing to do, and definitely
		// not an error — this is the normal outcome once batching kicks in.
		return nil, false, nil
	}

	jobs := make([]map[string]any, 0, len(pending))
	for _, p := range pending {
		jobs = append(jobs, printJournalBody(p))
	}

	resp, retry, err := h.post(ctx, "/api/v1/workstation/print-jobs", "", entityID, map[string]any{
		"jobs": jobs,
	})
	if err != nil {
		return resp, retry, err
	}

	// Cloud answers with `accepted` (newly recorded) and `duplicates` (it
	// already held that id). A duplicate is SUCCESS — the fact is on record —
	// so both lists are stamped synced. Treating a duplicate as a failure is
	// how a retry loop spins forever on a row that arrived perfectly well the
	// first time (P-09).
	synced := append(
		stringList(resp, "accepted"),
		stringList(resp, "duplicates")...,
	)
	if len(synced) == 0 {
		return resp, true, fmt.Errorf("print journal: cloud acknowledged no ids")
	}
	if err := h.journal.MarkSynced(synced); err != nil {
		return resp, true, err
	}

	return resp, false, nil
}

func printJournalBody(p printjob.SyncPayload) map[string]any {
	body := map[string]any{
		"id":         p.ID,
		"kind":       p.Kind,
		"status":     p.Status,
		"confidence": p.Confidence,
		"reprint_no": p.ReprintNo,
		"attempts":   p.Attempts,
		// The shop's REAL clock at the moment the paper came out — never the
		// sync time (P-07).
		"printed_at": p.PrintedAt,
	}
	putIfSet(body, "printer_id", p.PrinterID)
	putIfSet(body, "order_id", p.OrderID)
	putIfSet(body, "payment_id", p.PaymentID)
	putIfSet(body, "requested_by_id", p.RequestedByID)
	putIfSet(body, "requested_via", p.RequestedVia)
	putIfSet(body, "reprint_reason", p.ReprintReason)
	putIfSet(body, "last_error", p.LastError)
	putIfSet(body, "printer_status", p.PrinterStatus)
	// TR-28 — WHICH layout definition drew the sheet. Absent (not empty) when
	// the legacy formatter drew it: that path has no version, and an empty
	// string on the wire would land in Cloud as a value that looks like a stamp
	// and identifies nothing.
	putIfSet(body, "template_version", p.TemplateVersion)
	if len(p.Payload) > 0 {
		body["payload"] = p.Payload
	}
	return body
}

func putIfSet(m map[string]any, key, value string) {
	if value != "" {
		m[key] = value
	}
}

// stringList pulls a list of ids out of the Cloud response, tolerating both
// the `{data: {...}}` envelope and a bare object.
func stringList(resp map[string]any, key string) []string {
	if resp == nil {
		return nil
	}

	container := resp
	if data, ok := resp["data"].(map[string]any); ok {
		container = data
	}

	raw, ok := container[key].([]any)
	if !ok {
		return nil
	}

	out := make([]string, 0, len(raw))
	for _, v := range raw {
		if s, ok := v.(string); ok && s != "" {
			out = append(out, s)
		}
	}
	return out
}
