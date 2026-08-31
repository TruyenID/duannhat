package service

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
)

// writeOrderConditionsTx là bản đối xứng của `WritesCustomerOrders::writeConditions`
// bên Cloud: (tái) ghi sổ `order_conditions` cho một đơn từ kết quả định giá vừa
// tính — `tax` một dòng mỗi mức (kèm `taxable_base`), `discount` một dòng mỗi
// mức, `service_charge` một dòng.
//
// ## Vì sao máy trạm PHẢI ghi, dù Cloud mới là sổ kế toán (#2032)
//
// Vì `order_conditions` cũng là NGUỒN HIỂN THỊ mà chính máy trạm đọc:
// `loadOrderConditions` phơi `conditions[]` cho POS/KDS. Trước bài này, đơn máy
// trạm tự tạo có sổ trống cho tới khi sync UP và Cloud tính lại — nên một quán
// offline cả ngày có giao diện nói "không thuế, không giảm giá" trong khi tờ
// giấy đã in nói ngược lại. Máy hỏng trước khi sync thì sổ không bao giờ tồn
// tại.
//
// Máy trạm đã có đủ số để ghi: `priceGroups` là bản port chính xác của
// `OrderPricingCalculator`, có cổng parity dùng chung fixture
// (`tax_allocation_golden.json`). Nó chỉ là không ghi.
//
// ## Ba chi tiết KHÔNG được lệch khỏi Cloud
//
//  1. `refund` là append-only — do `refundItem` ghi, KHÔNG bao giờ bị xoá ở đây.
//  2. Mẫu số pro-rata của giảm giá là tiền món GỘP (chưa trừ giảm giá) của mức
//     đó, không phải `TaxGroup.Taxable` (đã là nền SAU giảm giá). Lấy nhầm thì
//     phân bổ theo một tỉ lệ khác tỉ lệ đã dùng lúc tính thuế, và phần dư đi lạc.
//  3. Phần dư đặt vào mức CUỐI để `Σ(discount) == −discount_amount` đúng tuyệt
//     đối, không phải xấp xỉ.
//
// Chạy trong tx của người gọi.
func (e *OrderEngine) writeOrderConditionsTx(tx *sql.Tx, orderID string, res PricingResult, discount float64) error {
	now := time.Now().UTC().Format(time.RFC3339)
	currency := e.currencyCode()

	// Xoá dòng dẫn xuất cũ (đơn + mọi dòng món của nó). `refund` không đụng tới.
	if _, err := tx.Exec(`
		DELETE FROM order_conditions
		WHERE type IN ('tax', 'discount', 'service_charge')
		  AND (
		    (conditionable_type = 'order' AND conditionable_id = ?)
		    OR (conditionable_type = 'order_item' AND conditionable_id IN (
		         SELECT id FROM order_items WHERE customer_order_id = ?))
		  )`, orderID, orderID); err != nil {
		return fmt.Errorf("clear derived conditions: %w", err)
	}

	insert := func(condType, source, label string, rate any, amount float64, taxableBase any, meta map[string]string) error {
		var metaJSON any
		if len(meta) > 0 {
			b, err := json.Marshal(meta)
			if err != nil {
				return err
			}
			metaJSON = string(b)
		}
		_, err := tx.Exec(`
			INSERT INTO order_conditions (
				id, conditionable_type, conditionable_id, type, source, label,
				rate, amount, taxable_base, currency_code, meta, created_at, updated_at
			) VALUES (?, 'order', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
			uuid.NewString(), orderID, condType, source, label,
			rate, amount, taxableBase, currency, metaJSON, now, now,
		)
		return err
	}

	// — thuế: một dòng mỗi mức, kèm nền chịu thuế đã in (#2031).
	for _, g := range res.Groups {
		// Bỏ qua khi nhóm KHÔNG CÓ GÌ — cả thuế lẫn nền đều 0.
		//
		// Điều kiện cũ `g.Tax == 0` nuốt mất nhóm 0%: đơn có món 非課税 cạnh
		// món 10% chỉ ghi dòng cho mức 10%, nên tổng nền trên sổ không còn bằng
		// subtotal. Peppol/EN16931 có BR-Z-08 (zero-rated) và BR-E-08 (exempt)
		// yêu cầu nhóm ấy phải xuất hiện kèm nền của nó; 非課税 ở Nhật cũng phải
		// phân biệt được trên chứng từ. "0 thuế" không đồng nghĩa "không tồn tại".
		if g.Tax == 0 && g.Taxable == 0 {
			continue
		}
		// Cloud ghi meta.rate_group trên dòng thuế (WritesCustomerOrders::writeConditions);
		// bản sao LAN phải mang cùng key để hai sổ đối chiếu được (#2100 D9).
		if err := insert("tax", "tax_type", formatRateLabel(g.Rate), g.Rate, g.Tax, g.Taxable,
			map[string]string{"rate_group": rateKey(g.Rate)}); err != nil {
			return fmt.Errorf("insert tax condition: %w", err)
		}
	}

	// — giảm giá: một dòng mỗi mức, pro-rata theo tiền món GỘP của mức đó.
	if discount > 0 {
		source, label, couponID, err := e.discountSourceTx(tx, orderID)
		if err != nil {
			return err
		}

		gross, err := e.grossByRateTx(tx, orderID)
		if err != nil {
			return err
		}

		type row struct {
			rate   *float64
			amount float64
		}
		var rows []row

		subtotal := res.Subtotal
		if subtotal > 0 && len(res.Groups) > 0 {
			// Cloud phân bổ theo RoundingMode::taxStep(tax_rounding_decimals, currency)
			// (WritesCustomerOrders::writeConditions) — không phải step tiền tệ trơn (#2100 D7).
			_, taxDecimals := e.orderRoundingSnapshot(tx, orderID)
			step := taxStep(taxDecimals, currency)
			allocated := 0.0
			last := len(res.Groups) - 1
			for i, g := range res.Groups {
				var share float64
				if i == last {
					share = discount - allocated
				} else {
					share = roundHalfUpToStep(discount*gross[rateKey(g.Rate)]/subtotal, step)
				}
				allocated += share
				if share > 0 {
					r := g.Rate
					rows = append(rows, row{rate: &r, amount: -share})
				}
			}
		}
		// Không dựng được nhóm nào (đơn không có dòng chịu thuế) — vẫn phải ghi
		// tổng, nếu không tiền biến mất khỏi sổ và bất biến Σ vỡ.
		if len(rows) == 0 {
			rows = append(rows, row{rate: nil, amount: -discount})
		}

		for _, r := range rows {
			var rateVal any
			meta := map[string]string{}
			// Cloud cộng coupon_id vào meta của MỌI dòng giảm giá của đơn coupon,
			// kể cả dòng dự phòng rate=nil (#2100 D9).
			if couponID != "" {
				meta["coupon_id"] = couponID
			}
			if r.rate != nil {
				rateVal = *r.rate
				meta["rate_group"] = rateKey(*r.rate)
			}
			if err := insert("discount", source, label, rateVal, r.amount, nil, meta); err != nil {
				return fmt.Errorf("insert discount condition: %w", err)
			}
		}
	}

	// — phí phục vụ: `rate` là MỨC THUẾ khoản phí chịu, không phải tỉ lệ tính
	//   phí. Tỉ lệ tính phí là cấu hình đổi lúc nào cũng được; sổ cần biết khoản
	//   tiền ấy rơi vào nhóm mức nào để dựng lại nền đã in.
	if res.ServiceCharge > 0 {
		var rateVal any
		if scRate := e.serviceChargeTaxRate(); scRate > 0 {
			rateVal = scRate
		}
		meta := map[string]string{}
		// Cloud ghi charge_rate hễ CỘT setting không NULL — kể cả "0.00". Điều
		// kiện là "cấu hình có mặt", không phải "> 0"; và ghi NGUYÊN VĂN chuỗi
		// đã sync để hai sổ ra cùng byte (#2100 D9).
		if raw := e.shopSettingString("service_charge_rate", ""); raw != "" {
			meta["charge_rate"] = raw
		}
		if err := insert("service_charge", "service_charge", "Service charge", rateVal, res.ServiceCharge, nil, meta); err != nil {
			return fmt.Errorf("insert service charge condition: %w", err)
		}
	}

	// #2189 — pivot coupon bám khoản giảm THỰC TẾ, cùng nhịp với sổ.
	//
	// `discount_applied` từng là INSERT-only (ghi một lần lúc áp), trong khi
	// `discount` ở đây là số ĐÃ KẸP mà máy trạm thật sự tính tiền — hai báo cáo
	// của cùng một ca (phiếu 精算 đọc `SUM(oc.discount_applied)` ở
	// `lan_shift_report.go` / `local_pos_till.go`, Z-report Cloud đọc sổ đổi đã
	// được #2154 đồng bộ) nói hai chuyện ngay lần void/refund đầu tiên.
	//
	// Đặt ở đây chứ không ở từng đường re-price, cùng lý do Cloud mắc observer
	// (#2154): chokepoint này là chỗ DUY NHẤT mà cả `recalcOrderTotalsTx` lẫn
	// nhánh inline của `AddItems` đều đi qua với số đã kẹp trong tay. Số LÚC ÁP
	// không mất — `coupon_redemptions.discount_amount` giữ nó (và `ReleaseCoupon`
	// trừ ngược theo đó). Stacking là exclusive nên tối đa một hàng sống.
	if _, err := tx.Exec(`
		UPDATE order_coupons SET discount_applied = ?
		WHERE order_id = ? AND released_at IS NULL`,
		int(math.Round(discount)), orderID); err != nil {
		return fmt.Errorf("sync coupon discount_applied: %w", err)
	}

	return nil
}

// grossByRateTx trả tiền món GỘP (chưa trừ giảm giá) theo từng mức — mẫu số của
// phép pro-rata, đúng cái `priceGroups` nhận vào qua `rateSubtotals`.
//
// Đọc lại từ dòng món thay vì lấy `TaxGroup.Taxable`, vì Taxable đã là nền SAU
// giảm giá: dùng nó làm mẫu số sẽ phân bổ theo tỉ lệ khác tỉ lệ đã dùng lúc tính
// thuế. Dòng hoàn tiền bị loại y như đường tính giá dương.
func (e *OrderEngine) grossByRateTx(tx *sql.Tx, orderID string) (map[string]float64, error) {
	// #2188 — dòng chưa đóng dấu tax_rate bị LOẠI, khớp đúng tập dòng mà
	// computeOrderTotalsFromDB đưa vào priceGroups (cùng recalc đã cảnh báo).
	rows, err := tx.Query(`
		SELECT tax_rate AS rate,
		       COALESCE(SUM(quantity * (unit_price + COALESCE(topping_subtotal, 0))), 0) AS sub
		FROM order_items
		WHERE customer_order_id = ?
		  AND tax_rate IS NOT NULL
		  AND (status IS NULL OR status != 'voided')
		  AND (refund_of_item_id IS NULL OR refund_of_item_id = '')
		GROUP BY rate`, orderID)
	if err != nil {
		return nil, fmt.Errorf("gross by rate: %w", err)
	}
	defer rows.Close()

	out := map[string]float64{}
	for rows.Next() {
		var rate, sub float64
		if err := rows.Scan(&rate, &sub); err != nil {
			return nil, err
		}
		out[rateKey(rate)] += sub
	}
	return out, rows.Err()
}

// discountSourceTx phân biệt giảm giá do coupon với giảm giá thu ngân nhập tay,
// và đóng băng nhãn tại thời điểm ghi (mã coupon có thể đổi tên sau). couponID
// trả về rỗng khi không phải coupon — meta của sổ cần nó (#2100 D9).
//
// Đọc từ `order_coupons` (binding thật, `released_at IS NULL`) — bảng `orders`
// phía máy trạm KHÔNG có cột coupon_id/coupon_code_snapshot; bản cũ query hai
// cột đó rồi nuốt lỗi bằng `_ =`, nên MỌI giảm giá đều bị ghi source='manual'
// kể cả đơn coupon (phát hiện khi làm #2100 D9).
func (e *OrderEngine) discountSourceTx(tx *sql.Tx, orderID string) (source, label, couponID string, err error) {
	var cID, couponCode sql.NullString
	scanErr := tx.QueryRow(`
		SELECT coupon_id, coupon_code FROM order_coupons
		WHERE order_id = ? AND released_at IS NULL
		ORDER BY applied_at DESC LIMIT 1`, orderID,
	).Scan(&cID, &couponCode)
	if scanErr != nil && !errors.Is(scanErr, sql.ErrNoRows) {
		return "", "", "", fmt.Errorf("resolve discount source: %w", scanErr)
	}

	if cID.Valid && cID.String != "" {
		label = "Discount"
		if couponCode.Valid && couponCode.String != "" {
			label = couponCode.String
		}
		return "coupon", label, cID.String, nil
	}
	return "manual", "Discount", "", nil
}

// formatRateLabel dựng nhãn "10%" / "8.5%" giống hệt phía PHP
// (`rtrim(rtrim(number_format($rate, 2), '0'), '.')`), vì nhãn này được đóng
// băng vào sổ và hai bên phải sinh ra cùng một chuỗi.
func formatRateLabel(rate float64) string {
	s := strconv.FormatFloat(rate, 'f', 2, 64)
	s = strings.TrimRight(s, "0")
	s = strings.TrimRight(s, ".")
	return s + "%"
}
