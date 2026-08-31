package handler

import (
	"log/slog"
	"net/http"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printjob"
)

// journalPrint records one print into the LOCAL journal (plan-052 T1.2).
//
// Call it immediately after a Print() attempt, success or failure. It is
// deliberately fire-and-forget:
//
//   - it returns nothing, so no print site can accidentally start treating a
//     journal problem as a print problem;
//   - it makes NO network call and holds no lock the printer needs. The
//     critical path — connect, write bytes, cut — is untouched, which is the
//     P-08 [HARD] guarantee that a Cloud outage cannot stop a shop printing.
//
// The confidence is taken from the printer's capability profile, never from
// the caller: a machine that cannot report back earns `sent_only` no matter
// how confidently the calling code phrases things (P-33).
// journalPrintFor is journalPrint with the acting cashier attached — the form
// every HTTP print site should use (plan-052 §4 point 2: WHO, on every row,
// including the first print). An explicit RequestedByID on the entry wins; the
// header only fills the gap.
func (s *Server) journalPrintFor(r *http.Request, p *printer.Printer, entry printjob.Entry, printErr error) {
	if entry.RequestedByID == "" {
		entry.RequestedByID = actorUserID(r)
	}
	s.journalPrint(p, entry, printErr)
}

func (s *Server) journalPrint(p *printer.Printer, entry printjob.Entry, printErr error) {
	if s.printJournal == nil {
		return
	}

	if printErr != nil {
		entry.Status = printjob.StatusFailed
		if entry.LastError == "" {
			entry.LastError = printErr.Error()
		}
	} else if entry.Status == "" {
		entry.Status = printjob.StatusPrinted
	}

	if p != nil {
		if entry.PrinterID == "" {
			entry.PrinterID = p.ID()
		}
		entry.Confidence = printjob.Confidence(p.Profile().PrintConfidence())
		// T1.3 — the machine's state, in the UPOS words Cloud stores. The
		// workstation owns this printer's queue, so it is the only tier that
		// can observe it (P-38).
		entry.PrinterStatus = printjob.UposFromDeviceStatus(string(p.Status()))
	}
	// A failed print confirms nothing about paper, whatever the machine can
	// normally report.
	if entry.Status == printjob.StatusFailed {
		entry.Confidence = printjob.ConfidenceSentOnly
	}

	if _, err := s.printJournal.Record(entry); err != nil {
		// The paper already came out. A journal write that fails is an
		// observability loss, not a print failure — log and move on.
		slog.Warn("print journal record failed", "kind", entry.Kind, "err", err)
	}
}

// ── Money documents: reserve the number, print, then settle (#1875) ──────────
//
// `journalPrint` records AFTER the fact, which is right for a kitchen ticket and
// wrong for receipt / red_invoice / debt_slip: those print 「BAN IN #N」, so N has
// to exist before the bytes leave. These two wrap the printjob two-phase API and
// are the ONLY way a money document should reach the ledger.
//
// Neither may ever stop a print. A journal that cannot be written is an
// observability loss; a cashier who cannot reprint at the counter is the failure
// plan-052 §4 forbids.

// beginMoneyPrint claims the copy number for one money document.
//
// A `JobID` of "" means the reservation did not happen (no journal wired, or the
// database refused past its retry budget). Callers must carry on printing — the
// returned ReprintNo is then 1, the honest "we cannot prove this is a copy" —
// and `finishMoneyPrint` falls back to writing an after-the-fact row so the
// event is still recorded.
func (s *Server) beginMoneyPrint(r *http.Request, entry printjob.Entry, scope printjob.Scope) printjob.Reservation {
	if entry.RequestedByID == "" {
		entry.RequestedByID = actorUserID(r)
	}
	if s.printJournal == nil {
		return printjob.Reservation{ReprintNo: 1}
	}
	res, err := s.printJournal.Reserve(entry, scope)
	if err != nil {
		slog.Warn("print journal reserve failed (non-fatal)",
			"kind", entry.Kind, "order", entry.OrderID, "payment", entry.PaymentID, "err", err)
		return printjob.Reservation{ReprintNo: 1}
	}
	return res
}

// finishMoneyPrint settles the reservation once the attempt is over.
//
// `reason` is the operator's raw reprint reason; it is normalised through
// reprintReasonFor here rather than at reserve time because whether a reason
// means anything depends on the number that was handed out — the first print of
// a document is not a reprint (P-10).
func (s *Server) finishMoneyPrint(res printjob.Reservation, p *printer.Printer, entry printjob.Entry, reason string, printErr error) {
	entry.ReprintNo = res.ReprintNo
	entry.ReprintReason = reprintReasonFor(reason, res.ReprintNo)

	if res.JobID == "" {
		// No reservation to settle — record the event the old way so a database
		// hiccup costs the copy NUMBER's accuracy, never the audit row itself.
		s.journalPrint(p, entry, printErr)
		return
	}

	outcome := printjob.Outcome{
		Status: printjob.StatusPrinted,
		// TR-28 — the reservation was taken before the slip was rendered, so the
		// layout version can only be known now. Callers fill it in on the entry
		// from `renderMoneySlip`'s second return value; an empty one is the
		// legacy formatter, which has no version, and Confirm leaves the column
		// NULL rather than writing "".
		TemplateVersion: entry.TemplateVersion,
		ReprintReason:   entry.ReprintReason,
	}
	if printErr != nil {
		outcome.Status = printjob.StatusFailed
		outcome.LastError = printErr.Error()
	}
	if p != nil {
		outcome.PrinterID = p.ID()
		outcome.Confidence = printjob.Confidence(p.Profile().PrintConfidence())
		outcome.PrinterStatus = printjob.UposFromDeviceStatus(string(p.Status()))
	}

	if err := s.printJournal.Confirm(res.JobID, outcome); err != nil {
		slog.Warn("print journal confirm failed (non-fatal)",
			"job", res.JobID, "kind", entry.Kind, "err", err)
	}
}

// journalReceiptPrinter resolves the receipt printer for a ledger entry
// WITHOUT assuming the device registry is wired.
//
// `printPaymentReceipt` already tolerates a headless server (no registry → it
// simply does not print), so the journal must tolerate it too — otherwise
// adding the ledger would turn a supported "nothing to print" path into a
// crash, which is precisely the kind of regression the ledger exists to avoid.
func (s *Server) journalReceiptPrinter() *printer.Printer {
	if s.devices == nil {
		return nil
	}
	return s.resolveReceiptPrinter()
}

// actorHeader is how pos-web names the human behind a print.
//
// The workstation authenticates the TERMINAL (a device token), not the person,
// so without this the ledger could only ever say "some tablet printed it".
// plan-052 §4 point 2 requires WHO on every row, so pos-web sends the signed-in
// cashier's Cloud user id alongside the device token. It is a HEADER rather
// than a body field so every print endpoint gets it at once — including the
// ones whose body shape is fixed by an older client.
//
// An empty value is a supported answer ("nobody was signed in"): a blank actor
// is an honest gap, and it must never be a reason to refuse a print (§4).
const actorHeader = "X-Actor-User-Id"

// actorUserID reads the acting cashier from the request, if named.
func actorUserID(r *http.Request) string {
	if r == nil {
		return ""
	}
	id := strings.TrimSpace(r.Header.Get(actorHeader))
	if len(id) > 36 {
		return ""
	}
	return id
}

// reprintReasonFor normalises the print-history `reason` string into the
// ledger's `reprint_reason`.
//
// The first print of a document is not a reprint, so its default reason
// ("auto") is NOT a justification for anything and must not be recorded as
// one — a ledger full of receipts "justified" by the word "auto" would make
// the P-10 audit worthless. Only copy ≥2 carries a reason.
func reprintReasonFor(reason string, reprintNo int) string {
	if reprintNo < 2 || reason == "auto" {
		return ""
	}
	return reason
}
