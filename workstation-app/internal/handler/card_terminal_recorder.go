package handler

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"time"

	"github.com/dxs-platform/workstation-app/internal/domain"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/google/uuid"
)

// *Server implements the card-payment port the TerminalBridge depends on.
var _ service.CardPaymentRecorder = (*Server)(nil)

// RecordCardPayment persists an APPROVED Verifone P400 (VescaJS) card charge as a
// local card payment and drives the offline-first path: SQLite insert → order
// close → sync UP. The terminal has already captured funds by the time this is
// called (on the approved result), so the payment is recorded confirmed — the
// same offline-first shape as the cash-changer recorder.
//
// Idempotent on the terminal transaction id: a replayed result returns the
// existing payment without a duplicate.
func (s *Server) RecordCardPayment(_ context.Context, p service.CardPayment) (string, error) {
	if p.OrderID == "" || p.Amount <= 0 {
		return "", fmt.Errorf("card payment: order id and positive amount required")
	}

	idemKey := "terminal:" + p.TerminalTxnID
	if existing, ok := s.lookupPaymentByIdempotency(idemKey); ok {
		return existing.ID, nil // replay — already recorded
	}

	var orderStatus string
	var orderTotal int
	if err := s.db.QueryRow(
		`SELECT status, COALESCE(total_amount, 0) FROM orders WHERE id = ?`, p.OrderID,
	).Scan(&orderStatus, &orderTotal); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return "", fmt.Errorf("card payment: order %s not found", p.OrderID)
		}
		return "", err
	}

	method, _ := s.resolvePaymentMethod("", string(domain.PaymentMethodCard))

	now := time.Now().UTC()
	metadata, _ := json.Marshal(map[string]any{
		"capture_source":  "p400_vesca",
		"terminal_txn_id": p.TerminalTxnID,
		"service":         string(p.Service),
	})

	payment := domain.Payment{
		ID:              uuid.NewString(),
		OrderID:         p.OrderID,
		PaymentMethodID: method.ID,
		PaymentMethod:   domain.PaymentMethodCard,
		Amount:          p.Amount,
		ReferenceNo:     p.TerminalTxnID,
		// The terminal approved and captured funds → recorded confirmed (maps to
		// Cloud "succeeded" on sync UP).
		Status:           domain.PaymentStatusConfirmed,
		IdempotencyKey:   idemKey,
		TerminalResponse: p.TerminalResponse,
		Metadata:         string(metadata),
		PaidAt:           &now,
		CapturedAt:       &now,
		TillSessionID:    s.currentTillSessionID(),
		CreatedAt:        now,
		UpdatedAt:        now,
	}
	if err := s.insertPayment(payment, "workstation"); err != nil {
		return "", err
	}

	// Card capture closes the order when fully paid (mirror the cash path).
	paidTotal, _ := s.sumActivePaymentsForOrder(p.OrderID)
	if orderStatus == "checkout" {
		_ = s.transitionOrderStatus(p.OrderID, "paying")
	}
	if orderTotal > 0 && paidTotal >= orderTotal {
		_ = s.transitionOrderStatus(p.OrderID, "closed")
		_, _ = s.db.Exec(`UPDATE orders SET closed_at = ?, paid_amount = ? WHERE id = ?`,
			now.Format(time.RFC3339), paidTotal, p.OrderID)
		s.applyTableStatusAfterPayment([]string{p.OrderID})
		if s.hub != nil {
			s.hub.BroadcastEvent("order_paid", map[string]any{"order_id": p.OrderID, "payment_id": payment.ID})
		}
	} else {
		_, _ = s.db.Exec(`UPDATE orders SET paid_amount = ? WHERE id = ?`, paidTotal, p.OrderID)
	}

	if s.sync != nil {
		payload := map[string]any{
			"bearer_token":      s.GetDeviceToken(),
			"target":            "workstation",
			"payment_id":        payment.ID,
			"order_id":          payment.OrderID,
			"payment_method_id": payment.PaymentMethodID,
			"payment_method":    string(domain.PaymentMethodCard),
			"amount":            payment.Amount,
			"reference_no":      payment.ReferenceNo,
			"idempotency_key":   payment.IdempotencyKey,
			"terminal_response": payment.TerminalResponse,
			"metadata":          payment.Metadata,
			"captured_at":       now.Format(time.RFC3339Nano),
		}
		if err := s.sync.Enqueue("payment", payment.ID, "create", payload, 1); err != nil {
			slog.Error("enqueue payment.create (card_terminal)", "err", err, "payment", payment.ID)
		}
	}

	return payment.ID, nil
}
