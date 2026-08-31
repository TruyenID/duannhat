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

	// #2535 B6 — rào vượt-thu, parity với `handlePOSPayment` ("Payment amount
	// exceeds the outstanding order balance", `local_pos.go`). Recorder này
	// trước đây đi thẳng vào `insertPayment`, nên khoản dư chỉ lộ ra ở đầu kia
	// của đường sync: `handlePaymentCreate` **cắt** số tiền xuống `outstanding`
	// và ghi một dòng `slog.Error` — tức tiền mặt nằm trong máy mà Cloud không
	// bao giờ ghi nhận.
	//
	// Chặn ở đây là fail-closed đúng chỗ: lỗi trả về cho `CashChangerService`,
	// nơi nó thành alert `cash_collected_not_recorded` (#2571) — nhân viên biết
	// ngay là phải ghi tay và đối soát, thay vì phát hiện khi so sổ cuối ca.
	//
	// `orderTotal > 0` giữ nguyên ngoại lệ của đường POS: test cũ seed đơn không
	// có tổng tính trước và sẽ vướng rào một cách vô nghĩa.
	if capturedBefore, cerr := s.sumCapturedPaymentsForOrder(p.OrderID); cerr != nil {
		return "", cerr
	} else if orderTotal > 0 && capturedBefore+p.Amount > orderTotal {
		return "", fmt.Errorf(
			"cash payment: amount %d exceeds the outstanding balance (total %d, already captured %d)",
			p.Amount, orderTotal, capturedBefore,
		)
	}

	// Cash is auto-confirm — resolve the branch's cash method (id + is_auto_confirm).
	method, _ := s.resolvePaymentMethod("", string(domain.PaymentMethodCash))

	now := time.Now().UTC()
	tendered := p.Tendered
	change := p.Change
	metadataValues := map[string]any{}
	if p.PaymentMetadata != "" {
		if err := json.Unmarshal([]byte(p.PaymentMetadata), &metadataValues); err != nil {
			// Audit context must never turn a physically completed cash collection
			// into a missing payment. The request path validates this before the
			// machine starts; this fallback only protects corrupt recovery rows.
			slog.Error("bỏ qua metadata chia bill hỏng khi ghi lượt thu tiền mặt",
				"err", err, "order", p.OrderID, "glory_txn", p.GloryTransactionID)
			metadataValues = map[string]any{}
		}
		// JSON `null` is syntactically valid and unmarshals a map to nil. A
		// corrupt recovery row containing it must not panic when provenance is
		// assigned below — cash has already moved at this point.
		if metadataValues == nil {
			slog.Error("bỏ qua metadata chia bill null khi ghi lượt thu tiền mặt",
				"order", p.OrderID, "glory_txn", p.GloryTransactionID)
			metadataValues = map[string]any{}
		}
	}
	// Provenance is workstation-owned. Assign after the forwarded context so a
	// client can never forge or erase the machine identity.
	metadataValues["capture_source"] = "cash_changer"
	metadataValues["glory_transaction_id"] = p.GloryTransactionID
	metadataValues["server_id"] = p.ServerID
	metadata, _ := json.Marshal(metadataValues)

	payment := domain.Payment{
		ID:              uuid.NewString(),
		OrderID:         p.OrderID,
		PaymentMethodID: method.ID,
		PaymentMethod:   domain.PaymentMethodCash,
		Amount:          p.Amount,
		TenderedAmount:  &tendered,
		ChangeAmount:    &change,
		ReferenceNo:     p.GloryTransactionID,
		// Auto-confirm cash captures instantly — same as the POS cash path.
		Status:         domain.PaymentStatusSucceeded,
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
	if method.IsAutoConfirm && orderTotal > 0 && paidTotal >= orderTotal {
		_ = s.transitionOrderStatus(p.OrderID, "closed")
		_, _ = s.db.Exec(`UPDATE orders SET closed_at = ?, paid_amount = ? WHERE id = ?`,
			now.Format(time.RFC3339), paidTotal, p.OrderID)
		s.applyTableStatusAfterPayment([]string{p.OrderID})
		if s.hub != nil {
			s.hub.BroadcastEvent("order_paid", map[string]any{"order_id": p.OrderID, "payment_id": payment.ID})
		}
		// #2535 B2 — đơn đóng NGAY TẠI ĐÂY, nên hook sync-down `onOrderPaid`
		// không bao giờ chạy cho nó: recorder này phải tự bắn lượt in. Bản sao
		// của dòng này ở `card_terminal_recorder.go` kèm nguyên văn lý do —
		// *"Missing this call was why a P400 capture closed the order but never
		// printed anything."* Cùng lỗi, cùng họ file, và tiền mặt chưa bao giờ
		// được sửa: quán bật `auto_print_bill` thu bằng 釣銭機 vẫn không ra tờ
		// giấy nào, mà đường in tay thì cũng mất (#2535 B1).
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
			fmt.Sprintf("cash_changer:%s", p.ServerID),
			"payment.create", "payment", payment.ID,
			fmt.Sprintf(`{"amount":%d}`, payment.Amount), "",
		)
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
			// The POS path records this in the AUDIT log, not just the process
			// log — and rightly so: a payment that never enqueues is money
			// recorded here and never at Cloud, the same silent divergence as a
			// void that never syncs. The process log has no reader; the audit
			// log has a dashboard.
			if s.audit != nil {
				s.audit.Log(
					fmt.Sprintf("cash_changer:%s", p.ServerID),
					"payment.sync_enqueue_failed", "payment", payment.ID,
					fmt.Sprintf(`{"err":%q}`, err.Error()), "",
				)
			}
		} else {
			// Nudge the worker instead of waiting out its 5s fallback tick. Six
			// other enqueue sites do this; these two did not. Latency only —
			// nothing is lost without it.
			s.sync.Wake()
		}
	}

	return payment.ID, nil
}
