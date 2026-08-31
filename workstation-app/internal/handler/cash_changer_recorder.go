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

// *Server implements the cash-payment port the CashChangerService depends on.
var _ service.CashPaymentRecorder = (*Server)(nil)

// RecordCashPayment persists a completed 釣銭機 cash collection as a local cash
// payment and drives the same offline-first path as the POS payment handler:
// SQLite insert (via insertPayment) → order lifecycle (cash is auto-confirm) →
// sync UP to Cloud (payment.create). It is the single writer of the payments
// table for the cash-changer flow.
//
// Idempotent on the Glory transaction id: a replayed collection (sync retry, or
// the state machine re-recording after a transient error) returns the existing
// payment without inserting a duplicate.
func (s *Server) RecordCashPayment(_ context.Context, p service.CashPayment) (string, error) {
	if p.OrderID == "" || p.Amount <= 0 {
		return "", fmt.Errorf("cash payment: order id and positive amount required")
	}

	idemKey := "glory:" + p.GloryTransactionID
	if existing, ok := s.lookupPaymentByIdempotency(idemKey); ok {
		return existing.ID, nil // replay — already recorded
	}

	// Order must exist; read the columns the auto-close gate needs.
	var orderStatus string
	var orderTotal int
	if err := s.db.QueryRow(
		`SELECT status, COALESCE(total_amount, 0) FROM orders WHERE id = ?`, p.OrderID,
	).Scan(&orderStatus, &orderTotal); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return "", fmt.Errorf("cash payment: order %s not found", p.OrderID)
		}
		return "", err
	}

	// Cash is auto-confirm — resolve the branch's cash method (id + is_auto_confirm).
	method, _ := s.resolvePaymentMethod("", string(domain.PaymentMethodCash))

	now := time.Now().UTC()
	tendered := p.Tendered
	change := p.Change
	metadata, _ := json.Marshal(map[string]any{
		"capture_source":       "cash_changer",
		"glory_transaction_id": p.GloryTransactionID,
		"server_id":            p.ServerID,
	})

	payment := domain.Payment{
		ID:              uuid.NewString(),
		OrderID:         p.OrderID,
		PaymentMethodID: method.ID,
		PaymentMethod:   domain.PaymentMethodCash,
		Amount:          p.Amount,
		TenderedAmount:  &tendered,
		ChangeAmount:    &change,
		ReferenceNo:     p.GloryTransactionID,
		// Auto-confirm cash is recorded confirmed locally (maps to Cloud
		// "succeeded" on sync UP) — same as the POS cash path.
		Status:         domain.PaymentStatusConfirmed,
		IdempotencyKey: idemKey,
		Metadata:       string(metadata),
		PaidAt:         &now,
		CapturedAt:     &now, // #817 device-clock shift attribution key
		TillSessionID:  s.currentTillSessionID(),
		CreatedAt:      now,
		UpdatedAt:      now,
	}
	if err := s.insertPayment(payment, "workstation"); err != nil {
		return "", err
	}

	// Order lifecycle — mirror handlePOSPayment: checkout→paying, then close on
	// full payment (cash auto-confirm). sumActivePaymentsForOrder now includes
	// the row just inserted.
	paidTotal, _ := s.sumActivePaymentsForOrder(p.OrderID)
	if orderStatus == "checkout" {
		_ = s.transitionOrderStatus(p.OrderID, "paying")
	}
	if method.IsAutoConfirm && orderTotal > 0 && paidTotal >= orderTotal {
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

	// Sync UP — same payload shape + workstation target as handlePOSPayment.
	if s.sync != nil {
		payload := map[string]any{
			"bearer_token":      s.GetDeviceToken(),
			"target":            "workstation",
			"payment_id":        payment.ID,
			"order_id":          payment.OrderID,
			"payment_method_id": payment.PaymentMethodID,
			"payment_method":    string(domain.PaymentMethodCash),
			"amount":            payment.Amount,
			"tendered_amount":   tendered,
			"change_amount":     change,
			"reference_no":      payment.ReferenceNo,
			"idempotency_key":   payment.IdempotencyKey,
			"metadata":          payment.Metadata,
			"captured_at":       now.Format(time.RFC3339Nano),
		}
		if err := s.sync.Enqueue("payment", payment.ID, "create", payload, 1); err != nil {
			slog.Error("enqueue payment.create (cash_changer)", "err", err, "payment", payment.ID)
		}
	}

	return payment.ID, nil
}
