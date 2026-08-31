// Package printjob is the workstation's LOCAL print journal (plan-052 T1.2,
// #1166).
//
// It records what this workstation printed, on this workstation's clock, in
// this workstation's database. It contains NO network code and imports no
// Cloud client — deliberately, and there is an architecture test
// (journal_arch_test.go) that keeps it that way.
//
// The reason is the one hard constraint of the whole print pipeline: a shop
// must be able to print with the internet down. If recording a print could
// ever block on, or fail because of, Cloud, then a Cloud outage would become a
// printing outage (plan-052 RISKS PR2). So the journal writes locally and the
// sync engine drains it later, at its own pace, with its own retries.
package printjob

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

// Kind mirrors the Cloud ledger's `kind` vocabulary exactly. A value that is
// not in this list has no retry policy and no TTL on the Cloud side, so it is
// not a thing this workstation may invent locally.
type Kind string

const (
	KindReceipt    Kind = "receipt"
	KindKitchen    Kind = "kitchen"
	KindBar        Kind = "bar"
	KindRedInvoice Kind = "red_invoice"
	KindDebtSlip   Kind = "debt_slip"
	KindReport     Kind = "report"
	KindLabel      Kind = "label"
	// KindDiagnostic is the printer setup wizard's test sheet (P-41). It rides
	// the ledger like everything else so a failure is visible, but it is not a
	// money document: it never takes a 「Bản in #N」 and never appears in
	// invoice audit.
	KindDiagnostic Kind = "diagnostic"
)

// IsMoneyDocument reports whether a second copy of this is an accounting
// event. Money documents are never auto-retried anywhere in the pipeline
// (P-05) — an ACK-lost receipt that some machine re-sends on its own is how a
// shop ends up holding two originals of one インボイス.
func (k Kind) IsMoneyDocument() bool {
	switch k {
	case KindReceipt, KindRedInvoice, KindDebtSlip:
		return true
	default:
		return false
	}
}

// Status uses the IPP/CUPS words (RFC 8011) that Cloud stores, so an operator
// reading either side sees the same vocabulary.
type Status string

const (
	StatusPrinted        Status = "printed"
	StatusFailed         Status = "failed"
	StatusNeedsAttention Status = "needs_attention"
	// StatusQueued is a copy number that has been RESERVED but whose print has
	// not finished (#1875, see reserve.go). It is the one status that must never
	// reach Cloud: the row exists only so a concurrent print cannot take the
	// same number, and Cloud has no way to settle it. `Pending` filters it out;
	// `Confirm` or `SweepStaleReservations` moves it to a terminal status, and
	// only then does it sync.
	StatusQueued Status = "queued"
)

// UPOS printer status — plan-052 T1.3. Every transport speaks its own dialect
// (the workstation has online/offline/error/printing, StarPRNT has an ASB
// status byte, CloudPRNT posts JSON); they all collapse to this vocabulary so a
// dashboard row means the same thing whichever machine produced it (STANDARDS
// §1, UnifiedPOS).
type UposStatus string

const (
	UposOk           UposStatus = "ok"
	UposCoverOpen    UposStatus = "cover_open"
	UposPaperEnd     UposStatus = "paper_end"
	UposPaperNearEnd UposStatus = "paper_near_end"
	UposOffline      UposStatus = "offline"
	UposError        UposStatus = "error"
)

// UposFromDeviceStatus maps the workstation's own printer status onto UPOS.
// `printing` is a busy state, not a fault.
//
// Note what this map does NOT claim: a level-A machine on a raw socket cannot
// report cover_open or paper_end at all, so those values only ever arrive from
// a machine that really can report them. Inventing them here would be the same
// dishonesty P-33 forbids for confidence.
func UposFromDeviceStatus(status string) UposStatus {
	switch status {
	case "online", "printing":
		return UposOk
	case "offline":
		return UposOffline
	case "error":
		return UposError
	default:
		return UposOffline
	}
}

// Confidence — P-33 [HARD]. `sent_only` is the honest answer for a machine
// that can only tell us the socket accepted the bytes. Nothing in this package
// (or on Cloud) ever upgrades it.
type Confidence string

const (
	ConfidenceSentOnly  Confidence = "sent_only"
	ConfidenceConfirmed Confidence = "confirmed"
)

// Entry is one print, as the print path knows it at the moment it finishes.
type Entry struct {
	Kind          Kind
	PrinterID     string
	OrderID       string
	PaymentID     string
	ReprintNo     int
	RequestedByID string
	RequestedVia  string
	ReprintReason string
	Status        Status
	Confidence    Confidence
	// PrinterStatus is what the machine looked like around this print, in UPOS
	// terms. The tier that OWNS the queue is the only one that can observe it
	// (P-38), which for ws_lan is this workstation.
	PrinterStatus UposStatus
	Attempts      int
	LastError     string
	// TemplateVersion is WHICH layout definition drew this sheet, as
	// `<scope>:<version>` (`system:0`, `brand:7`, `shop:12`) — see
	// service.PrintTemplateSource.Stamp.
	//
	// EMPTY is a real answer, not a missing one: the slip came off the legacy
	// FORMATTER, which is code rather than a published definition and has no
	// version to record. It stores as NULL and must stay distinguishable from a
	// stamp, because a reprint that resolved `system:0` for a sheet the
	// formatter drew would quietly produce a different document (TR-28).
	TemplateVersion string
	Payload         map[string]any
	// PrintedAt is the shop's real wall-clock time for this print. Callers
	// leave it zero and the journal stamps time.Now() — the workstation runs on
	// the shop PC, so its clock IS the shop clock (see CLAUDE.md "Workstation
	// has its own clock, correctly").
	PrintedAt time.Time
}

// Record is a Kind + error → a journal row, which is the shape every print
// site actually has.
type Journal struct {
	db *store.DB
	// notify wakes the sync drain. Optional: a nil notifier simply means the
	// row waits for the next scheduled drain. It is a callback rather than a
	// SyncEngine reference precisely so this package cannot acquire a network
	// dependency by accident.
	notify func(jobID string)
}

func NewJournal(db *store.DB) *Journal {
	return &Journal{db: db}
}

// OnRecorded wires the post-write hook the sync engine uses to enqueue the row
// for sync UP. It is called AFTER the local write has committed, so a failure
// in the notifier can never lose the journal row.
func (j *Journal) OnRecorded(fn func(jobID string)) {
	j.notify = fn
}

// Record writes one print into the journal and returns its client-generated
// id — which is also the primary key Cloud will use, so a replay is idempotent
// at the constraint (P-09).
//
// It never returns an error that a caller should treat as "the print failed":
// the paper already came out. A journal write that fails is logged by the
// caller and the print stands.
func (j *Journal) Record(entry Entry) (string, error) {
	if entry.Kind == "" {
		return "", fmt.Errorf("print journal: kind is required")
	}
	if entry.Status == "" {
		entry.Status = StatusPrinted
	}
	if entry.Confidence == "" {
		entry.Confidence = ConfidenceSentOnly
	}
	if entry.ReprintNo < 1 {
		entry.ReprintNo = 1
	}
	if entry.Attempts < 1 {
		entry.Attempts = 1
	}
	if entry.PrintedAt.IsZero() {
		entry.PrintedAt = time.Now()
	}
	if entry.RequestedVia == "" {
		entry.RequestedVia = "workstation"
	}

	id := uuid.New().String()
	// RFC3339 UTC on disk, like every other timestamp in this database — the
	// instant is preserved, so Cloud reconstructs the shop's real local time
	// from it without ever comparing a wall-clock string.
	printedAt := entry.PrintedAt.UTC().Format(time.RFC3339)
	now := time.Now().UTC().Format(time.RFC3339)

	payload := marshalPayload(entry.Payload)

	_, err := j.db.Exec(`
		INSERT INTO print_jobs (
			id, kind, printer_id, order_id, payment_id, reprint_no,
			requested_by_id, requested_via, reprint_reason,
			status, confidence, attempts, last_error, payload,
			printer_status, template_version, printed_at, created_at
		) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		id, string(entry.Kind), nullable(entry.PrinterID), nullable(entry.OrderID), nullable(entry.PaymentID),
		entry.ReprintNo, nullable(entry.RequestedByID), entry.RequestedVia, nullable(entry.ReprintReason),
		string(entry.Status), string(entry.Confidence), entry.Attempts, nullable(entry.LastError), payload,
		nullable(string(entry.PrinterStatus)), nullable(entry.TemplateVersion), printedAt, now,
	)
	if err != nil {
		return "", fmt.Errorf("print journal: record: %w", err)
	}

	if j.notify != nil {
		j.notify(id)
	}

	return id, nil
}

// SyncPayload is one journal row in the shape Cloud's batch endpoint accepts.
type SyncPayload struct {
	ID            string         `json:"id"`
	Kind          string         `json:"kind"`
	Status        string         `json:"status"`
	Confidence    string         `json:"confidence"`
	PrinterID     string         `json:"printer_id,omitempty"`
	OrderID       string         `json:"order_id,omitempty"`
	PaymentID     string         `json:"payment_id,omitempty"`
	ReprintNo     int            `json:"reprint_no"`
	RequestedByID string         `json:"requested_by_id,omitempty"`
	RequestedVia  string         `json:"requested_via,omitempty"`
	ReprintReason string         `json:"reprint_reason,omitempty"`
	Attempts      int            `json:"attempts"`
	LastError     string         `json:"last_error,omitempty"`
	Payload       map[string]any `json:"payload,omitempty"`
	PrinterStatus string         `json:"printer_status,omitempty"`
	// TemplateVersion is omitempty on purpose: the legacy-formatter path has no
	// version, and sending `""` would let Cloud store an empty string that is
	// neither a stamp nor an honest NULL (TR-28).
	TemplateVersion string `json:"template_version,omitempty"`
	PrintedAt       string `json:"printed_at"`
}

// Pending returns up to limit journal rows that have not reached Cloud yet,
// oldest first so the ledger reconstructs the shop's evening in order.
//
// `queued` rows are EXCLUDED (#1875). They are reservations holding a copy
// number for a print still in flight (reserve.go), and Cloud has no way to
// settle one: pushing it would leave a ledger row permanently claiming a print
// is in progress, on a workstation that may have finished it — or died — an hour
// ago. Confirm or SweepStaleReservations moves the row to a terminal status
// first, and it syncs then.
func (j *Journal) Pending(limit int) ([]SyncPayload, error) {
	if limit <= 0 {
		limit = 50
	}

	rows, err := j.db.Query(`
		SELECT id, kind, status, confidence, printer_id, order_id, payment_id,
		       reprint_no, requested_by_id, requested_via, reprint_reason,
		       attempts, last_error, payload, printer_status, template_version, printed_at
		FROM print_jobs
		WHERE synced_at IS NULL AND status != ?
		ORDER BY created_at ASC, id ASC
		LIMIT ?`, string(StatusQueued), limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []SyncPayload
	for rows.Next() {
		var (
			p                                                              SyncPayload
			printerID, orderID, paymentID                                  sql.NullString
			requestedByID, requestedVia, reprintReason, lastError, payload sql.NullString
			printerStatus, templateVersion                                 sql.NullString
		)
		if err := rows.Scan(
			&p.ID, &p.Kind, &p.Status, &p.Confidence, &printerID, &orderID, &paymentID,
			&p.ReprintNo, &requestedByID, &requestedVia, &reprintReason,
			&p.Attempts, &lastError, &payload, &printerStatus, &templateVersion, &p.PrintedAt,
		); err != nil {
			return nil, err
		}
		p.PrinterID = printerID.String
		p.OrderID = orderID.String
		p.PaymentID = paymentID.String
		p.RequestedByID = requestedByID.String
		p.RequestedVia = requestedVia.String
		p.ReprintReason = reprintReason.String
		p.LastError = lastError.String
		p.PrinterStatus = printerStatus.String
		p.TemplateVersion = templateVersion.String
		if payload.Valid && payload.String != "" {
			var m map[string]any
			if json.Unmarshal([]byte(payload.String), &m) == nil {
				p.Payload = m
			}
		}
		out = append(out, p)
	}

	return out, rows.Err()
}

// MarkSynced stamps rows Cloud has acknowledged. Cloud reports an id it
// already held as a DUPLICATE rather than an error, and a duplicate is
// success — the fact is recorded — so callers pass both lists here and the
// retry loop settles instead of spinning forever on a row Cloud already has.
func (j *Journal) MarkSynced(ids []string) error {
	if len(ids) == 0 {
		return nil
	}

	placeholders := make([]string, len(ids))
	args := make([]any, 0, len(ids)+1)
	args = append(args, time.Now().UTC().Format(time.RFC3339))
	for i, id := range ids {
		placeholders[i] = "?"
		args = append(args, id)
	}

	_, err := j.db.Exec(
		`UPDATE print_jobs SET synced_at = ? WHERE id IN (`+strings.Join(placeholders, ",")+`)`,
		args...,
	)
	return err
}

// IsSynced reports whether a specific journal row already reached Cloud.
func (j *Journal) IsSynced(id string) (bool, error) {
	var synced sql.NullString
	err := j.db.QueryRow(`SELECT synced_at FROM print_jobs WHERE id = ?`, id).Scan(&synced)
	if err == sql.ErrNoRows {
		return true, nil // gone → nothing left to push
	}
	if err != nil {
		return false, err
	}
	return synced.Valid && synced.String != "", nil
}

// PendingCount backs the diagnostics panel: "how much printing history has not
// reached Cloud yet". A number that grows for days means the uplink is broken,
// NOT that printing is.
//
// Counts the same rows `Pending` would return — reservations excluded. A queued
// row is not history the uplink owes Cloud; including it would make a printer
// that is merely slow look like a broken uplink.
func (j *Journal) PendingCount() (int, error) {
	var n int
	err := j.db.QueryRow(
		`SELECT COUNT(*) FROM print_jobs WHERE synced_at IS NULL AND status != ?`,
		string(StatusQueued),
	).Scan(&n)
	return n, err
}

func nullable(s string) sql.NullString {
	s = strings.TrimSpace(s)
	return sql.NullString{String: s, Valid: s != ""}
}

// marshalPayload encodes the free-form payload, dropping it silently when it
// cannot be encoded. A payload is context for a human reading the ledger later;
// losing it must never cost the row itself, which carries the facts that matter.
func marshalPayload(payload map[string]any) sql.NullString {
	if len(payload) == 0 {
		return sql.NullString{}
	}
	raw, err := json.Marshal(payload)
	if err != nil {
		return sql.NullString{}
	}
	return sql.NullString{String: string(raw), Valid: true}
}
