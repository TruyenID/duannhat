package service

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"time"

	"github.com/google/uuid"
)

// plan-045 — LAN refund flow (port of Cloud CustomerOrderService::refundItem).
//
// A refund appends a NEW order_items row with negative quantity, the original
// line's copied+negated tax snapshot, a refund_of_item_id back-link, and bumps a
// refunded_quantity accumulator on the original (over-refund guard). The engine
// treats refund lines specially: they never re-enter the positive group-once tax
// (excluded in computeOrderTotalsFromDB / stampLineTaxAmounts); their negated
// snapshot tax is added directly so the reversal is numerically exact (Stripe
// pattern). An append-only order_conditions(type=refund) row records the event.
//
// The refund line MUST carry the original's product_sku_id (gap #2) — otherwise
// readOrderItemForSync (which skips SKU-empty items) would drop it from the sync
// UP payload.

// ErrCannotRefundRefundLine mirrors Cloud's CANNOT_REFUND_REFUND_LINE — the
// target line is itself a refund line (refund_of_item_id set).
var ErrCannotRefundRefundLine = errors.New("cannot refund a refund line")

// ErrRefundExceedsQuantity mirrors Cloud's REFUND_EXCEEDS_QUANTITY —
// refunded_quantity + quantity would exceed the original line's quantity.
var ErrRefundExceedsQuantity = errors.New("refund exceeds refundable quantity")

// ErrRefundQuantityInvalid surfaces a non-positive refund quantity (Cloud
// validates quantity gt:0).
var ErrRefundQuantityInvalid = errors.New("refund quantity must be greater than zero")

// RefundResult is the outcome of a successful RefundItem: the updated order plus
// the ids the handler needs to enqueue the sync-UP op idempotently.
type RefundResult struct {
	Order           *Order
	RefundLineID    string // the appended negative-qty order_items id (client_order_item_id)
	OriginalItemID  string // the refunded original line id
	Quantity        int    // units refunded (positive)
	RefundTaxAmount int    // negated once-rounded tax (≤ 0)
	RefundSubtotal  int    // negated subtotal (< 0)
	ConditionID     string // the appended refund order_conditions id
}

// RefundItem refunds `quantity` units of `itemID` on `orderID`. Guards mirror
// Cloud: own order, target not a refund line, refundable status (not voided),
// quantity > 0, refunded_quantity + quantity ≤ original quantity. Wrapped in a
// single SQLite transaction with the recompute + condition write.
func (e *OrderEngine) RefundItem(orderID, itemID string, quantity int, reason string) (*RefundResult, error) {
	if quantity <= 0 {
		return nil, ErrRefundQuantityInvalid
	}

	// Refundable-status gate BEFORE the tx (cheap read). A voided order can't be
	// refunded; open/dining/checkout/paying/closed all can (parity with Cloud,
	// which only blocks Voided).
	var status string
	if err := e.db.QueryRow(`SELECT status FROM orders WHERE id = ?`, orderID).Scan(&status); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, sql.ErrNoRows
		}
		return nil, fmt.Errorf("refund status lookup: %w", err)
	}
	if status == string(StatusVoided) {
		return nil, ErrOrderNotOpen
	}

	result := &RefundResult{OriginalItemID: itemID, Quantity: quantity}

	err := e.db.Transaction(func(tx *sql.Tx) error {
		// Load the ORIGINAL line under the tx (SQLite serialises writers, so this
		// is the lockForUpdate analogue — the guard re-check below can't race).
		var (
			productSkuID    sql.NullString
			toppingSubtotal sql.NullInt64
			unitPrice       int
			origQty         int
			taxAmount       int
			refundedQty     int
			taxTypeID       sql.NullString
			taxRate         sql.NullFloat64
			taxEscalated    int
			refundOfItemID  sql.NullString
		)
		err := tx.QueryRow(`
			SELECT COALESCE(product_sku_id, ''), COALESCE(topping_subtotal, 0),
			       unit_price, quantity, COALESCE(tax_amount, 0),
			       COALESCE(refunded_quantity, 0), tax_type_id, tax_rate,
			       COALESCE(tax_alcohol_escalated, 0), refund_of_item_id
			FROM order_items
			WHERE id = ? AND customer_order_id = ?`, itemID, orderID,
		).Scan(
			&productSkuID, &toppingSubtotal, &unitPrice, &origQty, &taxAmount,
			&refundedQty, &taxTypeID, &taxRate, &taxEscalated, &refundOfItemID,
		)
		if errors.Is(err, sql.ErrNoRows) {
			return sql.ErrNoRows
		}
		if err != nil {
			return fmt.Errorf("refund item lookup: %w", err)
		}

		// Can't refund a refund line.
		if refundOfItemID.Valid && refundOfItemID.String != "" {
			return ErrCannotRefundRefundLine
		}

		// Over-refund guard (re-checked inside the tx).
		if refundedQty+quantity > origQty {
			return ErrRefundExceedsQuantity
		}

		// Negated price snapshot — quantity carries the sign.
		unit := unitPrice + int(toppingSubtotal.Int64)
		refundSubtotal := -1 * unit * quantity

		// Proportional share of the original's (group-once allocated) tax,
		// rounded with the ORDER's snapshot rule, negated. A full-line refund
		// reverses exactly (tax_amount is already once-rounded).
		taxMode, taxDecimals := e.orderRoundingSnapshot(tx, orderID)
		tStep := taxStep(taxDecimals, e.currencyCode())
		perQtyTax := 0.0
		if origQty > 0 {
			perQtyTax = float64(taxAmount) * float64(quantity) / float64(origQty)
		}
		refundTax := -1 * int(math.Round(roundToStep(math.Abs(perQtyTax), tStep, taxMode)))

		now := time.Now().UTC().Format(time.RFC3339)
		refundLineID := uuid.New().String()

		// Append the refund line — copies product_sku_id (gap #2) + tax snapshot,
		// negated qty/subtotal/tax. status=served so it flows into totals.
		if _, err := tx.Exec(`
			INSERT INTO order_items (
				id, customer_order_id, product_sku_id, quantity, unit_price,
				topping_subtotal, subtotal, tax_type_id, tax_rate, tax_amount,
				tax_alcohol_escalated, refund_of_item_id, refunded_quantity,
				status, print_status, note, created_at, updated_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'printed', ?, ?, ?)`,
			refundLineID, orderID, nullableString(productSkuID.String),
			-1*quantity, unitPrice, toppingSubtotal.Int64, refundSubtotal,
			nullableString(taxTypeID.String), nullableFloat(taxRate), refundTax,
			taxEscalated, itemID, string(ItemStatusServed),
			nullableString(reason), now, now,
		); err != nil {
			return fmt.Errorf("insert refund line: %w", err)
		}

		// Bump the accumulator on the ORIGINAL line.
		if _, err := tx.Exec(
			`UPDATE order_items SET refunded_quantity = ?, updated_at = ? WHERE id = ?`,
			refundedQty+quantity, now, itemID,
		); err != nil {
			return fmt.Errorf("bump refunded_quantity: %w", err)
		}

		// Append-only refund condition (never regenerated). Amount is the negated
		// GROSS refunded (excluded: subtotal + tax; included: subtotal already
		// gross). meta links the original + carries the tax.
		includeTax := e.pricesIncludeTax()
		refundGross := refundSubtotal + refundTax
		if includeTax {
			refundGross = refundSubtotal
		}
		conditionID := uuid.New().String()
		label := reason
		if label == "" {
			label = "Refund"
		}
		meta, _ := json.Marshal(map[string]any{
			"refund_of_item_id": itemID,
			"quantity":          quantity,
			"tax":               refundTax,
		})
		var rateVal any
		if taxRate.Valid {
			rateVal = taxRate.Float64
		}
		if _, err := tx.Exec(`
			INSERT INTO order_conditions (
				id, conditionable_type, conditionable_id, type, source, label,
				rate, amount, currency_code, meta, created_at, updated_at
			) VALUES (?, 'order_item', ?, 'refund', 'manual', ?, ?, ?, ?, ?, ?, ?)`,
			conditionID, refundLineID, label, rateVal, refundGross,
			e.currencyCode(), string(meta), now, now,
		); err != nil {
			return fmt.Errorf("insert refund condition: %w", err)
		}

		// Recompute totals (refund line folded directly) + re-stamp positive
		// per-line tax. Reuses the same tx so everything is atomic.
		if err := e.recalcOrderTotalsTx(tx, orderID); err != nil {
			return err
		}

		result.RefundLineID = refundLineID
		result.RefundTaxAmount = refundTax
		result.RefundSubtotal = refundSubtotal
		result.ConditionID = conditionID
		return nil
	})
	if err != nil {
		return nil, err
	}

	order, err := e.GetByID(orderID)
	if err != nil {
		return nil, err
	}
	result.Order = order
	return result, nil
}

// nullableFloat maps a sql.NullFloat64 to a SQL-friendly value (NULL when
// invalid) so a refund line copies an unstamped original's NULL tax_rate rather
// than forcing 0 (which the engine would treat as an explicit 0% line).
func nullableFloat(f sql.NullFloat64) any {
	if !f.Valid {
		return nil
	}
	return f.Float64
}
