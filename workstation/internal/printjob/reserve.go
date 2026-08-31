package printjob

import (
	"database/sql"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"
)

// ── The reprint counter (#1875) ───────────────────────────────────────────────
//
// `Record` writes a row AFTER the paper moved. That is right for a kitchen
// ticket, which carries no copy number, and wrong for the three money documents,
// which must print 「BAN IN #N」 — the number has to exist BEFORE the bytes reach
// the printer.
//
// So the money path is two-phase: `Reserve` takes the number inside a write
// transaction and parks a `queued` row holding it; `Confirm` settles that row
// once the attempt finishes, success or failure. Three properties fall out, and
// all three are load-bearing:
//
//   - Two tablets printing the same slip at the same moment cannot both get #1.
//     `MAX(reprint_no) + 1` and the INSERT happen in ONE transaction, so the
//     second one waits and reads the first one's row.
//
//   - A failed print still burns its number (plan-052 P-12). The row exists
//     before the printer is even opened, so a shop cannot rewind the counter by
//     unplugging the machine mid-print.
//
//   - The count is per KIND and per SCOPE. Before #1875 one counter on
//     `payments.metadata.print_history` was shared by receipt + red_invoice +
//     debt_slip, so printing a receipt made the customer's FIRST red invoice
//     come out stamped 「BAN IN #2」 — the mark claiming "copy" about an original.

// Scope is WHAT a copy number counts against.
//
// A red invoice on a split bill is not "the order's third print" — it is that
// one payer's first. Every split mode (chia đều / theo tiền / theo món) already
// creates one payment row per guest, so the payer's own id IS the natural scope
// and no split-mode-specific logic is needed here.
//
// PaymentID empty means the ORDER scope: the whole-order slip printed from the
// split-bill footer, and any order that has no payment yet. That scope spans the
// whole order FAMILY (`OrderIDs`), because a merged table carries several linked
// order rows and counting one of them alone would hand out #1 twice for the same
// piece of paper.
type Scope struct {
	OrderIDs  []string
	PaymentID string
}

// predicate renders the scope as a WHERE clause over print_jobs.
//
// A scope with neither a payment nor an order matches nothing — deliberately. It
// means the caller could not identify what this print belongs to, and the honest
// answer to "which copy is this" is then "the first one we can prove", not some
// other document's number.
func (sc Scope) predicate(kind Kind) (string, []any) {
	if id := strings.TrimSpace(sc.PaymentID); id != "" {
		return `kind = ? AND payment_id = ?`, []any{string(kind), id}
	}

	ids := make([]any, 0, len(sc.OrderIDs))
	for _, id := range sc.OrderIDs {
		if id = strings.TrimSpace(id); id != "" {
			ids = append(ids, id)
		}
	}
	if len(ids) == 0 {
		return `1 = 0`, nil
	}

	args := append([]any{string(kind)}, ids...)
	return `kind = ? AND order_id IN (` + strings.TrimSuffix(strings.Repeat("?,", len(ids)), ",") +
		`) AND COALESCE(payment_id, '') = ''`, args
}

// Reservation is a claimed copy number plus the row holding it.
type Reservation struct {
	JobID     string
	ReprintNo int
}

// Outcome is what Confirm learns after the attempt.
type Outcome struct {
	Status        Status
	Confidence    Confidence
	PrinterID     string
	PrinterStatus UposStatus
	LastError     string
	// TemplateVersion is settled here for a structural reason, not a stylistic
	// one: `Reserve` runs BEFORE the slip is rendered — the copy number has to
	// be on the paper, so it must exist first — and until the render happens
	// nobody knows which definition drew it. Stamping a guess at reserve time
	// would record the version that was current a moment EARLIER than the one
	// actually used (TR-28).
	//
	// Empty means "leave whatever the row already holds", which for a money
	// document is NULL: the legacy formatter has no version (see
	// handler.renderMoneySlip). It is never written as an empty string.
	TemplateVersion string
	// ReprintReason is settled here rather than at Reserve because whether a
	// reason is even meaningful depends on the number Reserve just handed out —
	// the first print of a document is not a reprint and must not be recorded
	// as one (see handler.reprintReasonFor).
	ReprintReason string
}

// reserveMaxAttempts / reserveBackoffStep mirror AppendPrintHistory's ladder:
// ~250 ms total, well under what a cashier notices, and the caller sees the
// error after that rather than the counter silently doing something else.
const (
	reserveMaxAttempts = 8
	reserveBackoffStep = 5 * time.Millisecond
)

// Reserve claims the next copy number for (entry.Kind, scope) and writes a
// `queued` row holding it.
//
// The returned number is what belongs on the paper. The row is NOT syncable yet
// — `Pending` skips `queued` — so a crash between here and Confirm leaves a
// local orphan that `SweepStaleReservations` turns into `needs_attention`,
// rather than a Cloud row stuck claiming a print is still in flight.
func (j *Journal) Reserve(entry Entry, scope Scope) (Reservation, error) {
	if entry.Kind == "" {
		return Reservation{}, fmt.Errorf("print journal: kind is required")
	}
	if entry.PrintedAt.IsZero() {
		entry.PrintedAt = time.Now()
	}
	if entry.RequestedVia == "" {
		entry.RequestedVia = "workstation"
	}
	if entry.Attempts < 1 {
		entry.Attempts = 1
	}

	var out Reservation
	var err error
	for attempt := 0; attempt < reserveMaxAttempts; attempt++ {
		err = j.reserveTx(entry, scope, &out)
		if err == nil {
			return out, nil
		}
		msg := err.Error()
		if !strings.Contains(msg, "database is locked") && !strings.Contains(msg, "SQLITE_BUSY") {
			return Reservation{}, err
		}
		time.Sleep(time.Duration(attempt+1) * reserveBackoffStep)
	}
	return Reservation{}, err
}

func (j *Journal) reserveTx(entry Entry, scope Scope, out *Reservation) error {
	id := uuid.New().String()
	printedAt := entry.PrintedAt.UTC().Format(time.RFC3339)
	now := time.Now().UTC().Format(time.RFC3339)
	payload := marshalPayload(entry.Payload)

	return j.db.Transaction(func(tx *sql.Tx) error {
		pred, args := scope.predicate(entry.Kind)

		// MAX, not COUNT: a number already handed out is spent even if its row
		// was later swept or deleted, so the sequence must never reissue one.
		var maxNo sql.NullInt64
		if err := tx.QueryRow(
			`SELECT MAX(reprint_no) FROM print_jobs WHERE `+pred, args...,
		).Scan(&maxNo); err != nil {
			return fmt.Errorf("print journal: reserve: read counter: %w", err)
		}
		n := int(maxNo.Int64) + 1

		if _, err := tx.Exec(`
			INSERT INTO print_jobs (
				id, kind, printer_id, order_id, payment_id, reprint_no,
				requested_by_id, requested_via, reprint_reason,
				status, confidence, attempts, last_error, payload,
				printer_status, template_version, printed_at, created_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
			id, string(entry.Kind), nullable(entry.PrinterID), nullable(entry.OrderID),
			nullable(entry.PaymentID), n, nullable(entry.RequestedByID), entry.RequestedVia,
			nullable(entry.ReprintReason), string(StatusQueued), string(ConfidenceSentOnly),
			entry.Attempts, nullable(entry.LastError), payload,
			nullable(string(entry.PrinterStatus)), nullable(entry.TemplateVersion), printedAt, now,
		); err != nil {
			return fmt.Errorf("print journal: reserve: insert: %w", err)
		}

		*out = Reservation{JobID: id, ReprintNo: n}
		return nil
	})
}

// Confirm settles a reserved row once the attempt is over.
//
// Like Record, it never reports "the print failed" — the paper has already
// either come out or not, and nothing a caller does with this error changes
// that. It is also the point at which the row becomes syncable, so the drain
// notification fires HERE and not at Reserve.
func (j *Journal) Confirm(jobID string, outcome Outcome) error {
	jobID = strings.TrimSpace(jobID)
	if jobID == "" {
		return fmt.Errorf("print journal: confirm: job id is required")
	}
	if outcome.Status == "" {
		outcome.Status = StatusPrinted
	}
	if outcome.Confidence == "" {
		outcome.Confidence = ConfidenceSentOnly
	}
	// A failed print confirms nothing about paper, whatever the machine can
	// normally report (P-33) — same rule journalPrint applies to Record.
	if outcome.Status == StatusFailed {
		outcome.Confidence = ConfidenceSentOnly
	}

	// printed_at moves to the moment the attempt ENDED. Reserve stamped it a
	// beat earlier so a row is never timestamp-less; this is the truthful one.
	now := time.Now().UTC().Format(time.RFC3339)

	res, err := j.db.Exec(`
		UPDATE print_jobs
		SET status = ?, confidence = ?,
		    printer_id = COALESCE(NULLIF(?, ''), printer_id),
		    printer_status = COALESCE(NULLIF(?, ''), printer_status),
		    template_version = COALESCE(NULLIF(?, ''), template_version),
		    last_error = ?, reprint_reason = ?, printed_at = ?
		WHERE id = ? AND status = ?`,
		string(outcome.Status), string(outcome.Confidence),
		strings.TrimSpace(outcome.PrinterID), string(outcome.PrinterStatus),
		strings.TrimSpace(outcome.TemplateVersion),
		nullable(outcome.LastError), nullable(outcome.ReprintReason), now,
		jobID, string(StatusQueued),
	)
	if err != nil {
		return fmt.Errorf("print journal: confirm: %w", err)
	}
	if n, _ := res.RowsAffected(); n == 0 {
		// Already settled (a sweep got there first, or a double Confirm). Not an
		// error: the fact is recorded either way, and the caller must not treat
		// it as a print problem.
		return nil
	}

	if j.notify != nil {
		j.notify(jobID)
	}
	return nil
}

// SweepStaleReservations settles reservations abandoned by a process that died
// between Reserve and Confirm.
//
// `needs_attention` rather than `failed`: nobody knows whether that sheet came
// out. `failed` would be a claim this workstation cannot support, and the shop
// would reprint a slip the customer may already be holding. `needs_attention` is
// the bucket the Cloud ledger already renders with a `resolve` action for a
// manager (plan-052 M2), which is exactly the right shape for "a human has to go
// look at the printer".
func (j *Journal) SweepStaleReservations(olderThan time.Duration) (int, error) {
	if olderThan <= 0 {
		olderThan = StaleReservationAge
	}
	cutoff := time.Now().Add(-olderThan).UTC().Format(time.RFC3339)

	res, err := j.db.Exec(`
		UPDATE print_jobs
		SET status = ?,
		    last_error = COALESCE(NULLIF(last_error, ''), ?)
		WHERE status = ? AND created_at < ?`,
		string(StatusNeedsAttention),
		"reservation abandoned — the workstation stopped between taking the copy number and finishing the print",
		string(StatusQueued), cutoff,
	)
	if err != nil {
		return 0, fmt.Errorf("print journal: sweep: %w", err)
	}
	n, _ := res.RowsAffected()
	return int(n), nil
}

// StaleReservationAge is how long a reservation may sit unsettled before the
// sweep calls it abandoned. Generous on purpose: a thermal printer on a slow USB
// bus, or one being power-cycled by a cashier mid-job, can legitimately hold a
// job open for a while, and mis-sweeping a live print would put a spurious
// alert in front of a manager.
const StaleReservationAge = 5 * time.Minute
