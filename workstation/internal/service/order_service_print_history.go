package service

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log/slog"
	"strings"
	"time"
)

// PrintHistoryEntry is one row appended to payments.metadata.print_history
// every time a workstation prints a payment-related slip (auto-fire from
// /api/lan/print/payment-receipt, manual reprint from pos-web's "In lại",
// debt-slip, VAT invoice). Used by the formatter to render the "Bản in #N"
// header when N>1, and by audit reporting to count reprints per payment.
type PrintHistoryEntry struct {
	At        string `json:"at"`         // RFC3339 UTC
	Reason    string `json:"reason"`     // "auto" | "manual reprint" | …
	ReprintNo int    `json:"reprint_no"` // 1-indexed
}

// AppendPrintHistory adds one entry to payments.metadata.print_history and
// returns the newly assigned reprint_no (1 for the first call). All work
// happens inside a single SQLite BEGIN IMMEDIATE transaction so concurrent
// callers can't interleave reads, get the same N, and write duplicate
// entries.
//
// Malformed metadata (corruption from older code, or an empty-string
// `metadata`) is
// tolerated: the method re-inits to `{}` with a warn log, then appends
// normally. Other top-level metadata keys (split_mode, bill_index,
// item_allocations, …) are preserved.
//
// Returns ErrPaymentNotFound when no row matches paymentID.
func (e *OrderEngine) AppendPrintHistory(paymentID, reason string) (PrintHistoryEntry, error) {
	if paymentID == "" {
		return PrintHistoryEntry{}, fmt.Errorf("payment_id required")
	}

	entry := PrintHistoryEntry{
		At:     time.Now().UTC().Format(time.RFC3339),
		Reason: reason,
	}

	// SQLite's deferred-transaction mode + concurrent reprint races can hit
	// SQLITE_BUSY when two appends contend for the same row. Retry a few
	// times with linear back-off — total budget ~250 ms which is well
	// under user-perceptible. After that surface the error to the caller.
	const maxAttempts = 8
	var assigned PrintHistoryEntry
	var err error
	for attempt := 0; attempt < maxAttempts; attempt++ {
		err = e.appendPrintHistoryTx(paymentID, entry, &assigned)
		if err == nil {
			return assigned, nil
		}
		msg := err.Error()
		if !strings.Contains(msg, "database is locked") &&
			!strings.Contains(msg, "SQLITE_BUSY") {
			return assigned, err
		}
		time.Sleep(time.Duration(5*(attempt+1)) * time.Millisecond)
	}
	return assigned, err
}

// appendPrintHistoryTx is the inner BEGIN IMMEDIATE transaction. Split out
// so tests can call it deterministically. Uses the existing
// store.DB.Transaction wrapper (which calls Begin() → IMMEDIATE on
// modernc.org/sqlite when the first write occurs).
func (e *OrderEngine) appendPrintHistoryTx(paymentID string, entry PrintHistoryEntry, out *PrintHistoryEntry) error {
	return e.db.Transaction(func(tx *sql.Tx) error {
		var raw sql.NullString
		if err := tx.QueryRow(
			`SELECT metadata FROM payments WHERE id = ?`, paymentID,
		).Scan(&raw); err != nil {
			if err == sql.ErrNoRows {
				return ErrPaymentNotFound
			}
			return fmt.Errorf("read metadata: %w", err)
		}

		meta := map[string]any{}
		if raw.Valid && raw.String != "" {
			if err := json.Unmarshal([]byte(raw.String), &meta); err != nil {
				slog.Warn("AppendPrintHistory: malformed metadata, re-initialising",
					"payment_id", paymentID, "err", err)
				meta = map[string]any{}
			}
		}

		// Coerce the existing history slice. Tolerate any shape:
		//   nil / missing / non-array → re-init []
		history := []PrintHistoryEntry{}
		if rawList, ok := meta["print_history"].([]any); ok {
			for _, item := range rawList {
				if m, ok := item.(map[string]any); ok {
					h := PrintHistoryEntry{}
					if at, _ := m["at"].(string); at != "" {
						h.At = at
					}
					if r, _ := m["reason"].(string); r != "" {
						h.Reason = r
					}
					switch n := m["reprint_no"].(type) {
					case float64:
						h.ReprintNo = int(n)
					case int:
						h.ReprintNo = n
					}
					history = append(history, h)
				}
			}
		}

		entry.ReprintNo = len(history) + 1
		history = append(history, entry)
		meta["print_history"] = history

		encoded, err := json.Marshal(meta)
		if err != nil {
			return fmt.Errorf("marshal metadata: %w", err)
		}

		now := time.Now().UTC().Format(time.RFC3339)
		res, err := tx.Exec(
			`UPDATE payments SET metadata = ?, updated_at = ? WHERE id = ?`,
			string(encoded), now, paymentID,
		)
		if err != nil {
			return fmt.Errorf("write metadata: %w", err)
		}
		n, _ := res.RowsAffected()
		if n == 0 {
			return ErrPaymentNotFound
		}

		if out != nil {
			*out = entry
		}
		return nil
	})
}

// ErrPaymentNotFound is returned by AppendPrintHistory when the payment id
// doesn't exist. Distinct from a generic "no rows" so callers can map it
// to a 404 cleanly.
var ErrPaymentNotFound = fmt.Errorf("payment not found")
