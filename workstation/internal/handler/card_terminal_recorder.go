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
		// The terminal approved and captured funds → recorded succeeded.
		Status:           domain.PaymentStatusSucceeded,
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
	// #555 M13 — paid_amount and the close decision count CAPTURED money only
	// (succeeded/confirmed net of refunds). The POS path was fixed for this;
	// this recorder was not, and kept summing ACTIVE money, which includes
	// `pending`. On a split bill whose other leg is an in-flight terminal
	// authorisation, that wrote the pending leg into orders.paid_amount — a
	// later /fail never reverses it — and could close the order on money that
	// had not been captured.
	paidTotal, err := s.sumCapturedPaymentsForOrder(p.OrderID)
	if err != nil {
		return "", err
	}
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
		// The order closed locally here (same as kiosk/POS counter-pay), so the
		// sync-down onOrderPaid hook never fires for it — this recorder owns
		// firing the receipt/kitchen print itself, same as local_kiosk.go does
		// on its own auto-confirmed close. Missing this call was why a P400
		// capture closed the order but never printed anything.
		s.handleLocalPaymentAutoPrint(p.OrderID, p.Amount)
	} else {
		_, _ = s.db.Exec(`UPDATE orders SET paid_amount = ? WHERE id = ?`, paidTotal, p.OrderID)
		// #1252 — a PARTIAL payment must reach pos-web too. order_paid tells it
		// to drop the order from the open list; a partial collection needs the
		// opposite, order_updated, which keeps the tab and rewrites its cached
		// balance for the next collection. Without it the cashier saw the old
		// remaining amount, and nothing else refreshed it: the open-orders query
		// only polls when the workstation is UNREACHABLE.
		if s.hub != nil && s.orders != nil {
			if o, err := s.orders.GetByID(p.OrderID); err == nil {
				s.hub.BroadcastEvent("order_updated", s.shapeOrderForBroadcast(o))
			}
		}
	}

	// Every payment taken through an HTTP handler is audited
	// (auditLogPOS → payment.create). Peripheral-driven payments were not, so
	// cash through the changer and card captures left no audit row at all —
	// the trail that reconstructs who took what money, and the one surface
	// where cash most needs it.
	//
	// auditLogPOS() needs a *http.Request for the device in context and the
	// client IP; a peripheral has neither. The identity is still knowable, so
	// the actor is derived from the device itself and the IP left empty rather
	// than faked.
	if s.audit != nil {
		s.audit.Log(
			fmt.Sprintf("card_terminal:%s", p.TerminalTxnID),
			"payment.create", "payment", payment.ID,
			fmt.Sprintf(`{"amount":%d}`, payment.Amount), "",
		)
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
			// The POS path records this in the AUDIT log, not just the process
			// log — and rightly so: a payment that never enqueues is money
			// recorded here and never at Cloud, the same silent divergence as a
			// void that never syncs. The process log has no reader; the audit
			// log has a dashboard.
			if s.audit != nil {
				s.audit.Log(
					fmt.Sprintf("card_terminal:%s", p.TerminalTxnID),
					"payment.sync_enqueue_failed", "payment", payment.ID,
					fmt.Sprintf(`{"err":%q}`, err.Error()), "",
				)
			}
		} else {
			// The terminal already captured funds, so Cloud must settle too — but
			// Cloud's "card" payment method is not is_auto_confirm, so `create`
			// alone lands it `pending` there and the order never leaves "paying"
			// (no settle event, no print). Unlike the kiosk's auto-confirmed
			// tender path (local_kiosk.go), nothing else calls a local /confirm
			// handler for a peripheral-driven P400 capture, so this recorder must
			// replay the confirm itself. handlePaymentConfirm retries via
			// errDependencyNotReady until `create` has synced and stamped
			// cloud_id, so enqueue order here doesn't matter.
			if err := s.sync.Enqueue("payment", payment.ID, "confirm", map[string]any{
				"bearer_token": s.GetDeviceToken(),
				"target":       "workstation",
				"payment_id":   payment.ID,
			}, 1); err != nil {
				slog.Error("enqueue payment.confirm (card_terminal)", "err", err, "payment", payment.ID)
			}

			// Nudge the worker instead of waiting out its 5s fallback tick. Six
			// other enqueue sites do this; these two did not. Latency only —
			// nothing is lost without it.
			s.sync.Wake()
		}
	}

	return payment.ID, nil
}
