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
//   - amount omitted → refund the remaining refundable balance
//     (amount − everything already refunded against this payment)
//   - amount > remaining → 422
//   - nothing left to refund → 409
//
// #2656 — a refund is an append-only SIGNED ROW (negative `amount`,
// `refund_of_id` → the original, `status='succeeded'`), not a mutation of the
// original. The original is left exactly as the cashier's collection left it,
// including its status: see payment_refund_shape.go for why 'succeeded' — not
// 'refunded' — is what keeps every money aggregate (and a rolled-back binary)
// netting correctly. Two partial refunds are now two rows with their own time,
// note and operator trail, which the old cumulative `refunded_amount` column
// could not represent at all.
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
		refundOfID       string
	)
	// `refunded` is the DERIVED total: the signed refund rows, plus any residue
	// in the retired cumulative column (paymentRefundedSumSQL). Reading only the
	// column here would let a payment be refunded twice over.
	err := s.db.QueryRow(`
		SELECT p.amount, `+paymentRefundedSumSQL("p")+`, p.order_id, p.status,
		       COALESCE(p.refund_of_id, '')
		FROM payments p WHERE p.id = ?`, paymentID,
	).Scan(&amount, &refunded, &paymentOrderID, &status, &refundOfID)
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

	// A refund row is itself `status='succeeded'`, so without this guard the
	// status check below would happily let a refund be "refunded" — money out of
	// the drawer twice, from a row that never collected any.
	if refundOfID != "" {
		writeError(w, http.StatusUnprocessableEntity,
			"cannot refund a refund row; refund the original payment instead")
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
	// Attribute the refund to the shift whose drawer the money physically leaves,
	// the way a collection is stamped at capture time (#817 Phase B). No open
	// shift → NULL, and the Z-report's window fallback picks it up by created_at,
	// same as any payment taken outside a shift.
	tillSessionID := s.currentTillSessionID()

	err = s.db.Transaction(func(tx *sql.Tx) error {
		if _, err := tx.Exec(`
			INSERT INTO payment_refunds (id, payment_id, amount, note, refunded_at)
			VALUES (?, ?, ?, ?, ?)`,
			refundID, paymentID, refundAmount, nullableStringHandler(body.Note), now,
		); err != nil {
			return err
		}

		// The signed refund row. Method + order are copied from the original so
		// the refund lands on the SAME tender line of the 精算 slip that the sale
		// did — a cash refund must reduce the cash drawer, not some other bucket.
		// It shares the `payment_refunds` id: one refund, one identity, and it is
		// the id the `payment.refund` sync op already sends Cloud as `refund_id`.
		if _, err := tx.Exec(`
			INSERT INTO payments (
				id, order_id, payment_method, payment_method_id, amount, status,
				refund_of_id, till_session_id, note, idempotency_key,
				created_at, updated_at)
			SELECT ?, p.order_id, p.payment_method, p.payment_method_id, ?, 'succeeded',
			       p.id, ?, ?, ?, ?, ?
			FROM payments p WHERE p.id = ?`,
			refundID, -refundAmount, nullIfEmpty(tillSessionID),
			nullableStringHandler(body.Note), "refund:"+refundID, now, now, paymentID,
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
