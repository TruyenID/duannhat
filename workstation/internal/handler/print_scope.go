package handler

import (
	"encoding/json"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printjob"
)

// resolvePrintScope decides what a money document's copy number counts against
// (#1875). It is the single place that answer is made, so the receipt, the red
// invoice and the debt slip cannot drift into three different opinions about
// which piece of paper is "the same document printed again".
//
// Three branches, in order:
//
//	① paymentID given → that payer.
//	  A split bill creates one payment row per guest in every mode (chia đều /
//	  theo tiền / theo món), so the guest's payment id IS their identity. This is
//	  what makes each payer's FIRST red invoice print clean while a SECOND one
//	  for that same guest carries 「BAN IN #2」, leaving the other guests' slips
//	  untouched.
//
//	② no paymentID, and the order has exactly ONE non-failed payment carrying no
//	  split metadata → that payment.
//	  Not an optimisation — a correctness requirement. A one-payer order is
//	  printed from PaymentReceiptDialog, which sends NO payment_id, while the
//	  split-bill dialog sends one per guest. Without this branch the two UI paths
//	  on the SAME one-payer order would count in two different scopes and both
//	  hand out #1, so the second sheet would come out unmarked.
//
//	③ otherwise → the whole ORDER FAMILY.
//	  Covers the split-bill footer slip (one sheet for the whole order, which is
//	  its own document and must not consume a guest's number) and an order with
//	  no payment at all. The family, not the single id: a merged table carries
//	  several linked order rows, and counting only the one the caller happened to
//	  name would issue #1 twice for the same document.
//
// Before #1875 branch ③ did not exist: a whole-order print silently counted
// against `lastConfirmedPaymentID`, i.e. it burned the LAST guest's number. That
// guest then received their own first red invoice stamped 「BAN IN #2」.
func (s *Server) resolvePrintScope(orderID, paymentID string) printjob.Scope {
	if id := strings.TrimSpace(paymentID); id != "" {
		return printjob.Scope{PaymentID: id}
	}

	ids := s.linkedOrderIDs(orderID)
	if soleID, ok := s.solePaymentForScope(ids); ok {
		return printjob.Scope{PaymentID: soleID}
	}
	return printjob.Scope{OrderIDs: ids}
}

// solePaymentForScope returns the id of the order family's ONLY non-failed
// payment, and whether branch ② above applies.
//
// It answers false as soon as there is more than one payment, or the single
// payment carries split metadata. Both mean the order is (or is becoming) a
// split, and on a split an untargeted print is the whole-order document — a
// separate thing from any guest's slip.
func (s *Server) solePaymentForScope(orderIDs []string) (string, bool) {
	if s.db == nil || len(orderIDs) == 0 {
		return "", false
	}
	ph, args := inPlaceholders(orderIDs)

	// #2656 — a signed refund row is not a second payer. Counting it would make
	// a one-payment order that was later refunded look like a split, and the
	// reprint counter would start numbering per-payment slips on an order that
	// has exactly one.
	rows, err := s.db.Query(`
		SELECT id, COALESCE(metadata, '')
		FROM payments
		WHERE order_id IN (`+ph+`) AND status != 'failed'
		  AND `+sqlOnlyOriginalPayments+`
		LIMIT 2`, args...)
	if err != nil {
		return "", false
	}
	defer rows.Close()

	var (
		id, meta string
		found    int
	)
	for rows.Next() {
		if found == 1 {
			return "", false // a second row: this is a split
		}
		if err := rows.Scan(&id, &meta); err != nil {
			return "", false
		}
		found++
	}
	if rows.Err() != nil || found != 1 {
		return "", false
	}
	if paymentCarriesSplitMetadata(meta) {
		return "", false
	}
	return id, true
}

// paymentCarriesSplitMetadata reports whether a payment's metadata marks it as
// one bill of a split.
//
// Deliberately generous about what counts: any of the three modes' markers is
// enough, and `total_bills > 1` catches a split whose mode string a future
// client renames. Being wrong in this direction costs a shared counter becoming
// two counters — the ORDER scope and the payment scope diverge, which shows up
// as a missing 「BAN IN #N」. Being wrong the other way would stamp one guest's
// slip with another guest's number, which is a lie about a money document.
//
// Reads the field names clients ACTUALLY send (pos-web + kiosk), the same set
// deriveSplitState decodes. `settles_payment_id` (debt settlement) is NOT a
// split marker and is correctly ignored here.
func paymentCarriesSplitMetadata(raw string) bool {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return false
	}
	var meta struct {
		SplitMode       string `json:"split_mode"`
		TotalBills      int    `json:"total_bills"`
		SplitCount      int    `json:"split_count"` // legacy fallback
		ItemAllocations []struct {
			ItemID string `json:"item_id"`
		} `json:"item_allocations"`
	}
	if json.Unmarshal([]byte(raw), &meta) != nil {
		// Unreadable metadata is not proof of a split, and must not be: a
		// corrupt blob would otherwise silently move every one-payer order onto
		// the order scope and stop marking its reprints.
		return false
	}
	return strings.TrimSpace(meta.SplitMode) != "" ||
		meta.TotalBills > 1 || meta.SplitCount > 1 ||
		len(meta.ItemAllocations) > 0
}
