package handler

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"time"

	"github.com/google/uuid"
)

// Phase 5 — payment refund handler.
//
// POST /api/v1/pos/orders/{id}/payments/{paymentId}/refund
//
// Body: { amount?, note? }
//
// Refund semantics:
//   - amount omitted → refund the remaining refundable balance (amount - refunded_amount)
//   - amount > remaining → 422
//   - amount == remaining → mark refunded_at, set status='refunded' on payments row
//
// The order's paid_amount is reduced by the refunded amount. The order's
// status + closed_at are left untouched: Cloud's refundPayment records the
// negative payment WITHOUT re-opening the order (a closed order stays closed),
// so the workstation must match to avoid a permanent status divergence
// (#520 Bug A). Re-collecting on a refunded order is a separate, explicit
// "reopen" operation, not a side effect of the refund.
//
// Idempotency (#533): the caller must supply an Idempotency-Key (header or
// `idempotency_key` body field, mirroring create/confirm). A retry / double-tap
// replays the cached response instead of applying a second partial refund.
func (s *Server) handleLocalPosRefundPayment(w http.ResponseWriter, r *http.Request) {
	orderID := r.PathValue("id")
	paymentID := r.PathValue("paymentId")
	if orderID == "" || paymentID == "" {
		writeError(w, http.StatusBadRequest, "order id + payment id required")
		return
	}

	var body struct {
		Amount         *int   `json:"amount"`
		Note           string `json:"note"`
		IdempotencyKey string `json:"idempotency_key"`
	}
	if r.ContentLength > 0 {
		if err := readJSON(r, &body); err != nil {
			writeError(w, http.StatusBadRequest, err.Error())
			return
		}
	}

	// #533 — refund is a financial mutation: require an Idempotency-Key so a
	// network retry or double-tap can't apply the same partial refund twice.
	idemKey := r.Header.Get("Idempotency-Key")
	if idemKey == "" {
		idemKey = body.IdempotencyKey
	}
	if idemKey == "" {
		writeError(w, http.StatusBadRequest,
			"Idempotency-Key required (as HTTP header or `idempotency_key` body field)")
		return
	}
	deviceID := ""
	if d, ok := DeviceFromContext(r.Context()); ok && d != nil {
		deviceID = d.ID
	}
	if s.idemReplayOrProceed(w, idemKey, deviceID) {
		return
	}

	var (
		amount, refunded int
		paymentOrderID   string
		status           string
	)
	err := s.db.QueryRow(`
		SELECT amount, refunded_amount, order_id, status
		FROM payments WHERE id = ?`, paymentID,
	).Scan(&amount, &refunded, &paymentOrderID, &status)
	if errors.Is(err, sql.ErrNoRows) {
		writeError(w, http.StatusNotFound, "payment not found")
		return
	}
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	if paymentOrderID != orderID {
		writeError(w, http.StatusNotFound, "payment does not belong to order")
		return
	}

	// #533 — only a payment that actually collected money can be refunded.
	// A `pending`/`failed` payment never contributed to paid_amount, so
	// refunding it would drive paid_amount negative and reopen a terminal
	// order against phantom money. Mirrors Cloud's refund guard.
	if status != "succeeded" && status != "confirmed" {
		writeError(w, http.StatusUnprocessableEntity,
			fmt.Sprintf("cannot refund a payment in status %q; only succeeded/confirmed payments are refundable", status))
		return
	}

	remaining := amount - refunded
	if remaining <= 0 {
		writeError(w, http.StatusConflict, "payment already fully refunded")
		return
	}

	refundAmount := remaining
	if body.Amount != nil {
		refundAmount = *body.Amount
		if refundAmount <= 0 {
			writeError(w, http.StatusBadRequest, "amount must be > 0")
			return
		}
		if refundAmount > remaining {
			writeError(w, http.StatusUnprocessableEntity,
				fmt.Sprintf("amount %d exceeds refundable %d", refundAmount, remaining))
			return
		}
	}

	refundID := uuid.NewString()
	now := time.Now().UTC().Format(time.RFC3339)
	newRefunded := refunded + refundAmount

	err = s.db.Transaction(func(tx *sql.Tx) error {
		if _, err := tx.Exec(`
			INSERT INTO payment_refunds (id, payment_id, amount, note, refunded_at)
			VALUES (?, ?, ?, ?, ?)`,
			refundID, paymentID, refundAmount, nullableStringHandler(body.Note), now,
		); err != nil {
			return err
		}

		newStatus := status
		var refundedAt sql.NullString
		if newRefunded >= amount {
			newStatus = "refunded"
			refundedAt = sql.NullString{String: now, Valid: true}
		}
		if _, err := tx.Exec(`
			UPDATE payments SET refunded_amount = ?, status = ?, refunded_at = ?, updated_at = ?
			WHERE id = ?`,
			newRefunded, newStatus, refundedAt, now, paymentID,
		); err != nil {
			return err
		}

		// Reduce the order's paid_amount only. Status + closed_at are left
		// untouched so a refunded-below-total order stays `closed` — matching
		// Cloud's refundPayment, which records the negative payment without
		// re-opening the order (#520 Bug A). Re-collecting is a separate
		// explicit reopen op, not an implicit side effect of the refund.
		// Negative paid is clamped at 0.
		_, err := tx.Exec(`
			UPDATE orders
			SET paid_amount = MAX(paid_amount - ?, 0),
			    updated_at = ?
			WHERE id = ?`,
			refundAmount, now, orderID,
		)
		return err
	})
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	s.enqueueOrderSync("payment.refund", orderID, map[string]any{
		"payment_id": paymentID,
		"refund_id":  refundID,
		"amount":     refundAmount,
		"note":       body.Note,
	})
	s.auditLogPOS(r, "payment.refund", "payment", paymentID,
		fmt.Sprintf(`{"amount":%d}`, refundAmount))

	response := map[string]any{
		"data": map[string]any{
			"id":              paymentID,
			"order_id":        orderID,
			"amount":          amount,
			"refunded_amount": newRefunded,
			"refund_id":       refundID,
			"refund_amount":   refundAmount,
			"refunded_at":     now,
		},
	}
	// Cache under the Idempotency-Key so a retry replays this exact result
	// instead of applying a second refund (#533).
	if respBytes, mErr := json.Marshal(response); mErr == nil {
		s.idemCachePut(idemKey, deviceID, string(respBytes))
	}
	writeJSON(w, http.StatusOK, response)
}

// nullableStringHandler mirrors service.nullableString but lives in handler/
// to avoid an import cycle for tiny helper-only callers.
func nullableStringHandler(s string) any {
	if s == "" {
		return nil
	}
	return s
}
