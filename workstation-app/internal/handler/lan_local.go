package handler

import (
	"log/slog"
	"net/http"
	"time"

	"github.com/dxs-platform/workstation-app/internal/config"
)

// GET /api/lan/health  (no auth)
//
// Lightweight liveness probe used by:
//   - mDNS discovery clients to confirm they've reached a real workstation
//   - POS-web/kiosk connection banners
//   - On-call dashboards / manual curl checks
//
// Response intentionally minimal to keep this cheap on the hot polling path.
func (s *Server) handleLANHealth(w http.ResponseWriter, r *http.Request) {
	cloudConnected := false
	if s.sync != nil {
		cloudConnected = s.sync.IsOnline()
	}
	storeName := ""
	if s.config != nil {
		storeName = s.config.Get().StoreName
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"status":           "ok",
		"workstation_name": storeName,
		"branch_id":        s.workstationBranchID(),
		"version":          config.Version,
		"cloud_connected":  cloudConnected,
		"server_time":      time.Now().UTC().Format(time.RFC3339),
	})
}

// POST /api/lan/print/payment-receipt   (auth: Bearer, LAN)
//
// Prints the "DA THANH TOAN" receipt and the "PHAN CON LAI" remainder slip
// for a payment on an order. Plan-038 T2.2 extends the legacy body with
// optional `payment_id` (target a specific split row) and `reprint_reason`
// (audit copy) — without them the handler falls back to legacy behaviour
// (most-recent confirmed payment, legacy `lastConfirmedPaymentAmount`) so
// the existing kiosk "In lại" button keeps working.
//
// Body:
//
//	{ order_id: "uuid",
//	  payment_id?: "uuid",          // when set, print THIS payment's slip
//	  reprint_reason?: "string" }    // free-form; default "auto"
//
// Side effects:
//   - payments.metadata.print_history[] appended for the target payment
//     (or the legacy last-confirmed payment when payment_id is omitted).
//   - Audit log: action='payment.receipt_printed' with
//     {payment_id, reprint_no, reason}.
func (s *Server) handleLANPrintReceipt(w http.ResponseWriter, r *http.Request) {
	if _, ok := DeviceFromContext(r.Context()); !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}

	var body struct {
		OrderID       string `json:"order_id"`
		PaymentID     string `json:"payment_id"`
		ReprintReason string `json:"reprint_reason"`
	}
	if err := readJSON(r, &body); err != nil || body.OrderID == "" {
		writeError(w, http.StatusBadRequest, "order_id required")
		return
	}
	if len(body.ReprintReason) > 256 {
		writeError(w, http.StatusBadRequest, "reprint_reason too long")
		return
	}
	reason := body.ReprintReason
	if reason == "" {
		reason = "auto"
	}

	// Resolve which payment to project the receipt around.
	paymentID := body.PaymentID
	amount := 0
	if paymentID != "" {
		// Targeted reprint — must be a confirmed payment on this order.
		var status string
		err := s.db.QueryRow(
			`SELECT status, COALESCE(amount, 0) FROM payments WHERE id = ? AND order_id = ?`,
			paymentID, body.OrderID,
		).Scan(&status, &amount)
		if err != nil {
			writeError(w, http.StatusNotFound, "payment not found")
			return
		}
		if status != "succeeded" && status != "confirmed" {
			writeError(w, http.StatusConflict, "payment not confirmed")
			return
		}
	} else {
		// Legacy behaviour preserved — last confirmed payment.
		amount = s.lastConfirmedPaymentAmount(body.OrderID)
	}

	s.rememberPrintLocale(localeFromRequest(r)) // keep the auto-print fallback fresh
	// Pass the targeted paymentID so THIS payer's split metadata drives the slip
	// (items/label/amount), not whichever payment happens to be latest.
	if err := s.printPaymentReceipt(body.OrderID, amount, s.printLabelLocale(), paymentID); err != nil {
		writeServerError(w, r, err)
		return
	}

	// Append print history to the targeted payment so the formatter can
	// render Bản in #N on next reprint. Legacy callers (no payment_id) skip
	// this — they implicitly target whatever lastConfirmedPaymentAmount
	// returned and we don't have a stable id without an extra query.
	reprintNo := 1
	if paymentID != "" && s.orders != nil {
		entry, err := s.orders.AppendPrintHistory(paymentID, reason)
		if err == nil {
			reprintNo = entry.ReprintNo
		} else {
			slog.Warn("AppendPrintHistory failed (non-fatal)", "payment_id", paymentID, "err", err)
		}
		s.auditLogPOS(r, "payment.receipt_printed", "payment", paymentID, auditDetails(map[string]any{
			"payment_id": paymentID,
			"reprint_no": reprintNo,
			"reason":     reason,
		}))
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"status":           "ok",
		"slips_printed":    1,
		"reprint_no":       reprintNo,
		"remaining_amount": "0",
	})
}

// lastConfirmedPaymentAmount returns the amount of the most recently confirmed
// payment on an order (0 when none) — used as the "Da thanh toan" figure when
// reprinting a receipt.
func (s *Server) lastConfirmedPaymentAmount(orderID string) int {
	// Sibling-aware: the payment may be tied to the cloud-keyed copy of the
	// order rather than the row we're printing (see linkedOrderIDs). Look across
	// the whole order family so a reprint finds it regardless.
	ids := s.linkedOrderIDs(orderID)
	ph, args := inPlaceholders(ids)
	var amount int
	// Non-failed, not just 'confirmed': the kiosk leaves the WS payment PENDING
	// (it confirms against Cloud), so a reprint must still surface that amount.
	_ = s.db.QueryRow(`
		SELECT COALESCE(amount, 0)
		FROM payments
		WHERE order_id IN (`+ph+`) AND status != 'failed'
		ORDER BY updated_at DESC
		LIMIT 1`, args...).Scan(&amount)
	return amount
}
