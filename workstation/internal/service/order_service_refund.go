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

// ErrCannotVoidRefundLine mirrors Cloud's CANNOT_VOID_REFUND_LINE (#2173).
// #2193 — chốt phải nằm Ở CHỖ TẠO OP, không phải chỗ gửi: máy trạm offline
// void thành công tại chỗ rồi op sync-UP ăn 409, mà đường 409-là-thành-công
// của sync_service còn đánh dấu nó synced — hai bên phân kỳ im lặng. Chặn ngay
// lúc thu ngân bấm, kể cả offline.
var ErrCannotVoidRefundLine = errors.New("cannot void a refund line")

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
			refundedQty     int
			taxTypeID       sql.NullString
			taxRate         sql.NullFloat64
			refundOfItemID  sql.NullString
		)
		err := tx.QueryRow(`
			SELECT COALESCE(product_sku_id, ''), COALESCE(topping_subtotal, 0),
			       unit_price, quantity,
			       COALESCE(refunded_quantity, 0), tax_type_id, tax_rate,
			       refund_of_item_id
			FROM order_items
			WHERE id = ? AND customer_order_id = ?`, itemID, orderID,
		).Scan(
			&productSkuID, &toppingSubtotal, &unitPrice, &origQty,
			&refundedQty, &taxTypeID, &taxRate, &refundOfItemID,
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

		// #2133 — làm tròn TỔNG LUỸ KẾ rồi lấy HIỆU, không làm tròn từng lần.
		//
		// Đây là NỬA GO của bản sửa Cloud cùng issue, và là nửa **thật sự đưa
		// tiền cho khách**: `POST /api/v1/pos/orders/{id}/items/{item}/refund`
		// đi thẳng vào đây rồi ra phiếu in tại quầy. Lúc mất mạng — lý do máy
		// trạm tồn tại — con số này là con số DUY NHẤT.
		//
		// Bản cũ làm tròn ĐỘC LẬP mỗi lần: dòng 3 món ¥1.005 @10% thu 302, hoàn
		// ba lần 1 món ra −101/−101/−101 = **303**. Khách nhận dư 1 đồng, ngăn
		// kéo hụt đúng chừng ấy so với sổ.
		//
		//	lần 1: round(302×1/3)=101 − 0   → −101
		//	lần 2: round(302×2/3)=201 − 101 → −100
		//	lần 3: round(302×3/3)=302 − 201 → −101      Σ = −302, khớp đã thu
		//
		// Mốc cuối luôn là `round(taxTotal)` = chính nó, nên Σ mọi lần hoàn LUÔN
		// bằng `tax_amount` khi hoàn hết — với MỌI cách chia nhỏ.
		//
		// `math.Abs` giữ ở cả hai mốc: làm tròn nửa-lên bất đối xứng qua 0, nên
		// phải làm tròn trên trị tuyệt đối rồi mới đảo dấu (#2117).
		//
		// Fixture `refund_tax_golden.json` (bản sinh đôi ở `backend/tests/
		// Fixtures/`) ghim hai engine ra cùng con số. Không có nó thì đúng lớp
		// lệch mà #2089 ghi nhận — "hai bản sửa của Cloud trôi mất khỏi Go" —
		// tái diễn ở lần refactor sau.
		taxMode, taxDecimals := e.orderRoundingSnapshot(tx, orderID)
		tStep := taxStep(taxDecimals, e.currencyCode())

		// #2232 (nửa Go của #2182) — ĐẦU VÀO của refundTaxDelta là thuế GỘP của
		// dòng gốc, KHÔNG phải `order_items.tax_amount`. tax_amount mang khoản
		// giảm đã pro-rata vào, trong khi phía coupon repo chọn ĐÁNH GIÁ LẠI chứ
		// không phân bổ theo tỉ lệ (#550/#2079/#2114): trả một món thì coupon
		// không đi theo món ấy — nó dồn sang phần hàng còn giữ — nên phần phải
		// hoàn là GỘP của món cộng thuế trên gộp. Cloud đã đổi
		// (`WritesCustomerOrders::refundItem` + `allocateLineTaxes`, xem
		// docs/guide/tax-types.md §nền GỘP); nền cũ làm con số máy trạm — con số
		// DUY NHẤT khi mất Cloud, con số thu ngân đếm tiền mặt trả khách — lệch
		// ¥25 mỗi dòng hoàn trên ca 2 × ¥1.000 + coupon ¥500 (−75 thay vì −100),
		// và hoàn HẾT giỏ vẫn để đọng thuế.
		//
		// CÙNG phép phân bổ largest-remainder đang dùng ở phía thu, gọi với
		// khoản giảm = 0 — KHÔNG tính lại bằng round(subtotal × rate): ba dòng
		// ¥1.005 @10% phân bổ 101/101/100 và Σ các số tính rời ≠ thuế nhóm.
		// Không có giảm giá thì nền gộp trùng tax_amount từng đồng (cùng phép,
		// khoản giảm 0) — mọi đơn không giảm giá không đổi một xu.
		includeTax := e.orderIsTaxIncluded(tx, orderID)
		grossTaxes, err := e.allocateLineTaxes(tx, orderID, 0, includeTax)
		if err != nil {
			return fmt.Errorf("allocate gross line taxes: %w", err)
		}
		refundTax := -1 * refundTaxDelta(grossTaxes[itemID], refundedQty, quantity, origQty, tStep, taxMode)

		now := time.Now().UTC().Format(time.RFC3339)
		refundLineID := uuid.New().String()

		// Append the refund line — copies product_sku_id (gap #2) + tax snapshot,
		// negated qty/subtotal/tax. status=served so it flows into totals.
		if _, err := tx.Exec(`
			INSERT INTO order_items (
				id, customer_order_id, product_sku_id, quantity, unit_price,
				topping_subtotal, subtotal, tax_type_id, tax_rate, tax_amount,
				refund_of_item_id, refunded_quantity,
				status, print_status, note, created_at, updated_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'printed', ?, ?, ?)`,
			refundLineID, orderID, nullableString(productSkuID.String),
			-1*quantity, unitPrice, toppingSubtotal.Int64, refundSubtotal,
			nullableString(taxTypeID.String), nullableFloat(taxRate), refundTax,
			itemID, string(ItemStatusServed),
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
		// #2108 — the ORDER's is_tax_included snapshot, not the live branch
		// flag (Cloud's WritesCustomerOrders::refundItem reads
		// $order->is_tax_included; reading the flipped live flag here made the
		// two repos negate different gross amounts for the same refund).
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

// refundTaxDelta trả về phần thuế (DƯƠNG) của MỘT lần hoàn từng phần, tính bằng
// hiệu hai mốc luỹ kế đã làm tròn (#2133).
//
// Tách thành hàm thuần để bài golden dùng chung với Cloud gọi thẳng được, không
// phải dựng đơn trong SQLite: fixture đo PHÉP TOÁN, và một fixture chỉ chạy được
// qua cả một transaction thì sẽ không ai chạy.
//
//	alreadyRefunded  số lượng ĐÃ hoàn trước lần này
//	quantity         số lượng của chính lần này
//	originalQty      số lượng gốc của dòng
func refundTaxDelta(taxTotal float64, alreadyRefunded, quantity, originalQty int, tStep float64, taxMode string) int {
	if originalQty <= 0 {
		return 0
	}

	at := func(cum int) float64 {
		return roundToStep(math.Abs(taxTotal*float64(cum)/float64(originalQty)), tStep, taxMode)
	}

	return int(math.Round(at(alreadyRefunded+quantity) - at(alreadyRefunded)))
}
