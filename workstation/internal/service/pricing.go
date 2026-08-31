package service

import (
	"database/sql"
	"math"
	"sort"
	"strconv"
	"strings"
)

// rowQueryer is the read subset shared by *store.DB and *sql.Tx, so the
// per-rate grouping query can run either inside a write transaction (where the
// in-memory Item slice isn't materialised) or against the pooled connection.
type rowQueryer interface {
	Query(query string, args ...any) (*sql.Rows, error)
	QueryRow(query string, args ...any) *sql.Row
}

// plan-043 §8 — the single consumption-tax pricing engine, ported to Go from
// backend/app/Services/Customer/OrderPricingCalculator.php::priceGroups. The
// workstation MUST agree with Cloud PHP to the yen (parity requirement); the
// shared fixture table in pricing_test.go asserts this.
//
// Given per-rate taxable subtotals it: groups by snapshot rate, allocates the
// coupon discount pro-rata per group, taxes the service charge with its own
// rate (whose tax joins the breakdown group of the same rate), and rounds tax
// ONCE per rate group (端数処理は税率ごとに1回, インボイス) with a single
// half-up-to-currency-step rule. Handles tax-excluded (add-on) and tax-included
// (総額表示 extraction) modes.

// roundHalfUpToStep rounds a value to the nearest currency step, half-up.
// Mirrors RoundingMode::roundHalfUpToStep (PHP) EXACTLY, including two details
// that the first port dropped (#2082):
//
//  1. `step <= 0` returns the value UNCHANGED (mode "none"), not 0.01. The old
//     substitution silently re-rounded values the caller asked to leave alone,
//     and it disagreed with `roundUpToStep` right below, which already did the
//     PHP thing.
//
//  2. The quotient is normalised to 9 decimals BEFORE the .5 decision. Without
//     it, binary representation error eats genuine half-way boundaries:
//     0.145/0.01 evaluates to 14.499999999999998, so a real .xx5 tax boundary
//     rounds DOWN and UNDER-COLLECTS (0.145 → 0.14 instead of 0.15). Cloud
//     fixed this in e8275ad97 (#821 E1); the Go port never heard about it, and
//     nothing existed to make it hear.
//
// Harmless at JPY/VND (step 1). Bites every currency with a minor unit.
func roundHalfUpToStep(value, step float64) float64 {
	if step <= 0 {
		return value
	}
	quotient := toSignificantDigits(value/step, 15)

	return math.Floor(quotient+0.5) * step
}

// toSignificantDigits snaps a float64 to the nearest value carrying `digits`
// significant decimal digits — the noise filter that makes the `.5` decision
// above meaningful.
//
// # Vì sao KHÔNG phải roundTo(x, 9) (#2082)
//
// Trước đây đúng là như vậy, và nó làm CHÍNH LUẬT LÀM TRÒN không khả chuyển.
// `round()` của PHP không phải phép làm tròn IEEE: nó âm thầm làm tròn lại về
// khoảng 15 chữ số có nghĩa, nên nó DỊCH cả những giá trị vốn đã là số nguyên
// chính xác — `round(9294522499999998.0)` trả `9294522500000000.0`. `math.Round`
// của Go làm đúng về mặt toán học và trả lại nguyên đầu vào.
//
// Máy trạm chạy `math.Round(v*1e9)/1e9`, nên nó lệch với Cloud từ
// `|value/step| ≳ 4.5×10⁶` trở lên — ₫4,5 triệu là hoá đơn một bàn ăn bình
// thường — và luôn lệch VỀ PHÍA 0 so với Cloud, tức thu thiếu thuế và trả thiếu
// tiền hoàn.
//
// Đáng chú ý là phía nào đúng về Ý ĐỊNH: `9294.5225 / 0.001` là biên `.5` thật
// trong hệ thập phân, và chỉ cái quirk của PHP mới khôi phục được nó. Nên bản
// sửa KHÔNG phải "theo IEEE" — mà là nói rõ ý định ấy ra, bằng một phép toán
// khai được ở cả hai ngôn ngữ.
//
// `%.14e` là phép chuyển đổi thập phân làm-tròn-đúng ở cả hai bên, nên đây là
// phép ĐỒNG NHẤT chứ không phải bản xấp xỉ một hàm nội bộ của PHP. Đo trên
// 54.000 thương số ngẫu nhiên khắp dải độ lớn thực tế: `sprintf('%.14e')` bên
// PHP và `strconv.FormatFloat(v, 'e', 14, 64)` ở đây round-trip ra CÙNG một
// double 64-bit trong mọi ca. Cặp này được ghim bởi `rounding_golden.json`.
func toSignificantDigits(value float64, digits int) float64 {
	if value == 0 || math.IsInf(value, 0) || math.IsNaN(value) {
		return value
	}

	snapped, err := strconv.ParseFloat(strconv.FormatFloat(value, 'e', digits-1, 64), 64)
	if err != nil {
		return value
	}

	return snapped
}

// roundUpToStep rounds a value UP to the nearest step (切り上げ) — the plan-045
// "ceil" tax mode. Mirrors RoundingMode::roundUpToStep (PHP):
// ceil(v/step)*step. A step ≤ 0 passes the value through (mode "none" parity).
func roundUpToStep(value, step float64) float64 {
	if step <= 0 {
		return value
	}
	return math.Ceil(value/step) * step
}

// roundDownToStep rounds a value DOWN to the nearest step (切り捨て) — the plan-045
// "floor" tax mode. Mirrors RoundingMode::roundDownToStep (PHP):
// floor(v/step)*step. A step ≤ 0 passes the value through (mode "none" parity).
func roundDownToStep(value, step float64) float64 {
	if step <= 0 {
		return value
	}
	return math.Floor(value/step) * step
}

// roundToStep dispatches a value to the requested rounding mode at a precomputed
// step — the port of RoundingMode::roundToStep (PHP). Accepts the canonical
// plan-045 rev-B names (round/ceil/floor) AND the legacy aliases (half_up/
// round_up/round_down) so an order snapshotted before the rename prices
// identically. Unknown/blank → round (round half up).
func roundToStep(value, step float64, mode string) float64 {
	switch mode {
	case "ceil", "round_up":
		return roundUpToStep(value, step)
	case "floor", "round_down":
		return roundDownToStep(value, step)
	default:
		return roundHalfUpToStep(value, step)
	}
}

// taxStep resolves the tax rounding step from an order's snapshot — the port of
// RoundingMode::taxStep (PHP). Nil decimals falls back to the currency's minor
// unit (mode "auto", pre-plan-045 behaviour); otherwise the step is 10^-decimals
// (0 → 1, 1 → 0.1, 2 → 0.01, 3 → 0.001).
// taxStep resolves the tax rounding step from an order's snapshot decimals.
// rev-B option-B — `decimals` sets the step to 10^-decimals even when finer than
// the currency unit (0.01 on JPY): the tax carries that sub-unit precision for
// DISPLAY (消費税 93.50). The grand TOTAL is still rounded to the currency unit
// (int) so the payable amount stays whole; the difference shows as 端数調整.
func taxStep(decimals *int, currencyCode string) float64 {
	return taxStepFrom(decimals, currencyStep(currencyCode))
}

// taxStepFrom is taxStep for call sites that already resolved the currency's
// minor unit (`step`). Nil decimals → the currency step; else 10^-decimals.
func taxStepFrom(decimals *int, currencyStep float64) float64 {
	if decimals == nil {
		return currencyStep
	}
	// KẸP theo đơn vị tiền tệ — `min(10^-decimals, currencyStep)` (#2082).
	//
	// Thiếu bước này thì `tax_rounding_decimals = 0` (MẶC ĐỊNH của DB) cho ra
	// bước 1,0 ở một tiền tệ có phần lẻ: dòng $1.45 @10% thu $0.15 trên Cloud
	// và $0.00 trên máy trạm. Vì là giá trị mặc định nên nó chạm MỌI đơn ở
	// những shop dùng tiền tệ có xu.
	//
	// Cloud kẹp ở 790e007e4 (#821 E1f). Bản port Go không nhận được, và cũng
	// không có cơ chế nào để nhận — xem #2089.
	return math.Min(math.Pow(10, float64(-*decimals)), currencyStep)
}

// currencyStep resolves the rounding step from a currency code (mode "auto"),
// mirroring RoundingMode::step / autoStep (PHP). Zero-fraction currencies → 1,
// three-decimal → 0.001, everything else → 0.01. Empty currency defaults to VND
// (project default → step 1) to match the PHP side (`?? 'VND'`).
func currencyStep(currencyCode string) float64 {
	// Viết hoa như PHP (`strtoupper($currencyCode ?: 'VND')`) — không có bước
	// này thì "jpy" rơi vào nhánh mặc định và ra bước 0,01 thay vì 1 (#2082).
	//
	// CỐ Ý KHÔNG `TrimSpace`: PHP cũng không. Mã có khoảng trắng thừa rơi vào
	// nhánh mặc định ở CẢ HAI phía (bước 0,01) — hình dạng đáng ngờ, nhưng nó
	// là hành vi CHUNG, và fixture ghim nó lại. Thêm trim chỉ một bên là tạo
	// đúng loại lệch mà cổng này sinh ra để chặn (tôi đã suýt làm ở lượt sửa
	// đầu, và chính cổng bắt được).
	c := strings.ToUpper(currencyCode)
	if c == "" {
		c = "VND"
	}
	switch c {
	// `ZeroDecimalCurrency::CODES` + `RoundingMode::WHOLE_UNIT_EXTRA` bên PHP.
	// DJF và MGA từng thiếu ở đây (#2082) ⇒ PHP bước 1, Go bước 0,01.
	case "JPY", "VND", "KRW", "IDR", "LAK", "MMK", "UGX", "RWF", "BIF", "KMF",
		"PYG", "CLP", "COP", "GNF", "VUV", "XOF", "XAF", "XPF", "DJF", "MGA":
		return 1.0
	case "KWD", "BHD", "OMR", "JOD", "TND", "IQD":
		return 0.001
	default:
		return 0.01
	}
}

// GroupTaxFor is the once-per-group consumption tax for one rate group's net
// base (端数処理は税率ごとに1回, インボイス) — the exact port of
// OrderPricingCalculator::groupTaxFor. Excluded mode adds tax on top; included
// mode (総額表示) extracts the 内税. Shared by priceGroups (order totals) and the
// LAN Z-report, so the per-rate breakdown recomputes group-once instead of
// summing the per-line tax_amount snapshots (which are per-line rounded).
//
// plan-045 — tax rounds with the order's snapshot rule (taxStep + taxMode); the
// snapshot-less call sites keep byte-identical behaviour by passing the currency
// step + "round" (see GroupTaxHalfUp).
//
// #2480 — the rounding direction applies to the TAX in both modes. The previous
// included-mode form was `gross - round(gross/(1+r))`, which rounded the BASE
// and left tax as the remainder — so it inverted: `floor` ("round down")
// produced a HIGHER tax, `ceil` a lower one, in the one mode every branch uses.
// It was also wrong at the edge: a ¥1 item at 8% 内税 with `floor` reported
// `1 - floor(0.926) = 1`, i.e. the whole price as tax.
//
// This MUST stay identical to OrderPricingCalculator::groupTaxFor. Cloud
// re-prices offline orders from the immutable snapshot before it trusts the
// money (#1092), so a formula that differs by one yen here surfaces as a
// verification failure on a sale that was actually correct.
func GroupTaxFor(netGroup, rate float64, pricesIncludeTax bool, taxStep float64, taxMode string) float64 {
	if pricesIncludeTax {
		return roundToStep(netGroup*rate/(100.0+rate), taxStep, taxMode)
	}
	return roundToStep(netGroup*rate/100.0, taxStep, taxMode)
}

// GroupTaxHalfUp is the half-up-to-currency-step form for call sites that have
// no order snapshot (e.g. the LAN Z-report breakdown, which
// recomputes group-once from the currency step). Byte-identical to the old
// GroupTaxFor(net, rate, include, step) — round ≡ the old half_up.
func GroupTaxHalfUp(netGroup, rate float64, pricesIncludeTax bool, step float64) float64 {
	return GroupTaxFor(netGroup, rate, pricesIncludeTax, step, "round")
}

// CurrencyStep is the exported form of currencyStep (mode "auto") for output
// surfaces in the handler package (e.g. the LAN Z-report breakdown).
func CurrencyStep(currencyCode string) float64 { return currencyStep(currencyCode) }

type rateAgg struct{ taxable, tax int }

// paidOrderIDsSQL là vị ngữ "đơn đã thanh toán thuộc ca này", dùng chung cho cả
// đường sổ lẫn đường cột — hai đường PHẢI mô tả cùng một tập đơn, nếu không
// bảng phân rã và tổng lại nói về hai phiếu khác nhau.
//
// ATTRIBUTION-FIRST: một khoản thanh toán đã gán vào ca này thì tính, dù nó rơi
// vào lúc nào; đã gán ca khác thì không bao giờ tính ở đây. Chỉ dòng KHÔNG mang
// gán mới rơi về cửa sổ [lower, upper]. Giống hệt `paidPaymentsPredicate` bên
// handler — giữ hai chỗ khớp nhau là thứ làm 消費税内訳 in ra và ảnh chụp đối
// soát mô tả cùng một tập đơn.
//
// #2580 — nửa trạng thái nay là quy tắc của Cloud (`TillSessionService::reconcile`):
// hàng GỐC (`refund_of_id IS NULL`) tính cả `refunded`, và hàng REFUND ký hiệu âm
// (`refund_of_id IS NOT NULL`, `status='succeeded'`) cũng tính. Doanh thu được ghi
// nhận ở ca BÁN và ở lại đó — một đơn bị hoàn tiền không biến mất khỏi 消費税内訳
// của ca đã bán nó, đúng như Cloud. Hàng refund trỏ về cùng `order_id` nên nó
// không kéo thêm đơn nào vào tập; nó chỉ ngăn tập bị thu hẹp.
//
// Đây là bản sao của `paidPaymentsPredicate` bên handler — hai chỗ PHẢI đổi cùng
// lượt, lệch nhau là đúng cái lỗi mà cả hai docstring đang cảnh báo.
//
// #2736 — `replace(created_at,' ','T')` canonical hoá DẤU PHÂN CÁCH trước khi so,
// y hệt bản bên handler. Bound tới đây đã normalize, nên nếu vế cột còn thô thì
// một hàng lưu dạng DATETIME có dấu cách thua ở ký tự 11 (' ' 0x20 < 'T' 0x54).
//
// Lệch NỬA VỜI còn tệ hơn cùng sai: sửa bản handler mà bỏ bản này thì trên CÙNG
// một phiếu 精算, bảng 支払方法 tính payment đó còn 消費税内訳 loại đơn của nó —
// và độ lệch bị đóng băng vào `settlement_snapshot`, rồi `lan_chain_report.go`
// suy phí phục vụ bằng PHẦN DƯ nên nó được IN RA thành サービス料消費税 (#2155).
const paidOrderIDsSQL = `
			SELECT DISTINCT order_id FROM payments
			WHERE ((refund_of_id IS NULL AND status IN ('pending','confirmed','succeeded','refunded'))
			       OR (refund_of_id IS NOT NULL AND status = 'succeeded'))
			  AND (till_session_id = ?
			       OR ((till_session_id IS NULL OR till_session_id = '')
			           AND substr(replace(created_at,' ','T'),1,19) >= ?
			           AND substr(replace(created_at,' ','T'),1,19) <= ?))`

// PerRateTaxBuckets rolls the paid orders in the payment window [lower, upper]
// into per-rate 課税売上 (taxable) / 消費税 (tax) rows (plan-046 T5.4, P5-6).
// EXTRACTED from buildShiftReport so the Z-report AND the settlement snapshot
// (buildLocalSettlementSnapshot) share ONE computation. Tax is recomputed once
// per (order, rate) group via GroupTaxHalfUp (端数処理は税率ごとに1回, インボイス),
// NEVER summed from per-line snapshots. Only non-null tax_rate lines contribute;
// returns nil on a query error (caller falls back to a single figure).
//
// The payment predicate is identical to the handler's paidPaymentsPredicate:
// ATTRIBUTION-FIRST (a payment bound to this shift counts wherever it fell in
// time; one bound to another shift never counts here), falling back to the
// [lower, upper] window only for rows carrying no attribution. Keeping the two
// in lockstep is what makes the printed 消費税内訳 and the settlement snapshot
// describe the same set of orders.
// #2155 — sổ `order_conditions` là nguồn, cột chỉ là dự phòng.
//
// Tổng thuế của phiếu 精算 đã đọc sổ từ #2140, nhưng bảng phân rã 消費税内訳 vẫn
// đọc CỘT. Cùng một phiếu, hai nguồn — và độ lệch bị ĐÓNG BĂNG vào
// `settlement_snapshot` (`revenue.tax` từ sổ, `tax_breakdown[].tax` từ cột).
//
// Nó không dừng ở chỗ hiển thị mâu thuẫn: `lan_chain_report.go` suy phí phục vụ
// của phiếu chuỗi bằng PHẦN DƯ (`TaxTotal - sumRateTax`), nên chênh lệch giữa
// hai nguồn được IN RA thành サービス料消費税 mà không ai thu.
//
// Đã đo (`pricing.go`, gap #7): `priceGroups` GỘP thuế phí phục vụ vào chính
// nhóm mức cùng tỉ lệ, nên `Σ type='tax'` trong sổ đã bao gồm nó. Nghĩa là phần
// dư ấy chưa bao giờ đo phí phục vụ — nó đo độ lệch. Đọc cùng một nguồn thì nó
// tự về 0, và con số ma biến mất mà không cần chạm `lan_chain_report`.
//
// Quy ước chọn nguồn giống hệt `orderLedgerJoinSQL` bên handler: **CÓ DÒNG**
// (`rows_seen`), không phải "tổng khác 0" — một đơn hợp lệ có thể có tổng thuế
// đúng bằng 0 (toàn 非課税), và đọc 0 thành "chưa có sổ" chính là lỗi #2074.
func PerRateTaxBuckets(q rowQueryer, step float64, sessionID, lower, upper string) []ShiftTaxRateLine {
	return perRateTaxBuckets(q, step, paidOrderIDsSQL, []any{sessionID, lower, upper})
}

// PerRateTaxBucketsForOrders computes the same tax buckets for an explicit
// report scope. Shift revenue uses this form because #2934 adds Cloud-only
// orders by customer_orders.till_session_id while deliberately keeping their
// payments out of drawer reconciliation.
func PerRateTaxBucketsForOrders(q rowQueryer, step float64, orderIDs []string) []ShiftTaxRateLine {
	if len(orderIDs) == 0 {
		return nil
	}
	marks := make([]string, len(orderIDs))
	args := make([]any, len(orderIDs))
	for i, id := range orderIDs {
		marks[i] = "?"
		args[i] = id
	}
	return perRateTaxBuckets(q, step, strings.Join(marks, ","), args)
}

func perRateTaxBuckets(q rowQueryer, step float64, orderSetSQL string, orderArgs []any) []ShiftTaxRateLine {
	byRate := map[float64]*rateAgg{}
	var rateOrder []float64

	add := func(rate float64, taxable, tax int) {
		a := byRate[rate]
		if a == nil {
			a = &rateAgg{}
			byRate[rate] = a
			rateOrder = append(rateOrder, rate)
		}
		a.taxable += taxable
		a.tax += tax
	}

	// (1) SỔ — nguồn chính. Một dòng mỗi (đơn, mức); `amount` là thuế đã chốt,
	// `taxable_base` là 税率ごとに区分した対価の額 (078).
	ledgerRows, err := q.Query(`
		SELECT oc.rate,
		       COALESCE(SUM(oc.amount), 0),
		       COALESCE(SUM(COALESCE(oc.taxable_base, 0)), 0)
		FROM order_conditions oc
		WHERE oc.conditionable_type = 'order'
		  AND oc.type = 'tax'
		  AND oc.rate IS NOT NULL
		  AND oc.conditionable_id IN (`+orderSetSQL+`)
		GROUP BY oc.rate
		ORDER BY oc.rate ASC`, orderArgs...)
	if err != nil {
		return nil
	}
	for ledgerRows.Next() {
		var rate, tax, taxable float64
		if err := ledgerRows.Scan(&rate, &tax, &taxable); err != nil {
			continue
		}
		add(rate, int(math.Round(taxable)), int(math.Round(tax)))
	}
	ledgerRows.Close()

	// (2) CỘT — chỉ cho đơn CHƯA CÓ DÒNG SỔ NÀO. Đơn máy trạm cũ (trước
	// plan-045) không có sổ, và bỏ chúng đi thì phiếu 精算 mất doanh thu thật.
	rows, err := q.Query(`
		SELECT o.is_tax_included,
		       CAST(COALESCE(o.discount_amount, 0) AS REAL),
		       CAST(COALESCE(o.subtotal, 0) AS REAL),
		       oi.tax_rate,
		       COALESCE(SUM(oi.subtotal), 0)
		FROM order_items oi
		JOIN orders o ON o.id = oi.customer_order_id
		WHERE oi.status != 'voided' AND oi.tax_rate IS NOT NULL
		  AND oi.customer_order_id IN (`+orderSetSQL+`)
		  AND NOT EXISTS (
			SELECT 1 FROM order_conditions oc
			WHERE oc.conditionable_type = 'order'
			  AND oc.conditionable_id = o.id
			  AND oc.type IN ('tax','discount','service_charge')
		)
		GROUP BY o.id, oi.tax_rate
		ORDER BY oi.tax_rate ASC`, orderArgs...)
	if err != nil {
		return nil
	}
	defer rows.Close()

	for rows.Next() {
		var incl int
		var discount, orderSub, rate, rateSub float64
		if err := rows.Scan(&incl, &discount, &orderSub, &rate, &rateSub); err != nil {
			continue
		}
		taxable, groupTax := perRateBucket(incl, discount, orderSub, rate, rateSub, step)
		add(rate, taxable, groupTax)
	}
	sort.Float64s(rateOrder)

	var lines []ShiftTaxRateLine
	for _, rate := range rateOrder {
		a := byRate[rate]
		lines = append(lines, ShiftTaxRateLine{
			Rate:         rate,
			TaxableSales: a.taxable,
			Tax:          a.tax,
		})
	}
	return lines
}

// perRateBucket prices ONE (order, rate) group for the 精算 / 引き継ぎ slip's
// 売上内訳 + 消費税内訳 pair, mirroring OrderPricingCalculator::priceGroups:
// pro-rata discount across the group, then tax rounded ONCE for the group
// (端数処理は税率ごとに1回, インボイス).
//
// Both returned figures describe the SAME base. 課税売上 used to be reported as
// the raw Σ line subtotal, which is a different number whenever a discount
// applies (the tax was computed on the discounted base, so the printed pair
// contradicted itself — "10%対象 ¥10,000" beside "消費税 ¥800") or when prices
// include tax (the taxable line carried the 内税 inside it). An auditor reads
// the per-rate base and its tax together, so they must correspond.
//
// `incl` is the order's is_tax_included flag as stored (1 = 総額表示).
func perRateBucket(incl int, discount, orderSub, rate, rateSub, step float64) (taxable, tax int) {
	if discount < 0 {
		discount = 0
	}
	if orderSub > 0 && discount > orderSub {
		discount = orderSub
	}
	netGroup := rateSub
	if orderSub > 0 {
		netGroup = rateSub - discount*rateSub/orderSub
	}
	if netGroup < 0 {
		netGroup = 0
	}

	groupTax := GroupTaxHalfUp(netGroup, rate, incl == 1, step)
	base := netGroup
	if incl == 1 {
		base = netGroup - groupTax
	}
	return int(math.Round(base)), int(math.Round(groupTax))
}

// LineTaxIdeal is one line's EXACT (unrounded) tax share for the largest-
// remainder allocation — the port of OrderPricingCalculator::lineTaxIdeal.
func LineTaxIdeal(netLine, rate float64, pricesIncludeTax bool) float64 {
	if pricesIncludeTax {
		return netLine - netLine/(1+rate/100.0)
	}
	return netLine * rate / 100.0
}

// AllocateGroupTax distributes a rate group's once-rounded tax (GroupTaxFor)
// across its lines so the parts sum EXACTLY to groupTax (largest-remainder /
// Hamilton apportionment) — the port of OrderPricingCalculator::allocateGroupTax.
// Each line lands within one currency step of its LineTaxIdeal share; the whole
// steps go to the largest fractional remainders (deterministic tie-break by
// input order). This is what lets the per-line tax_amount snapshots sum to the
// group tax the order total uses, so surfaces that sum them reconcile and never
// re-introduce per-line rounding. Values are non-negative.
func AllocateGroupTax(ideals []float64, groupTax, step float64) []float64 {
	n := len(ideals)
	if n == 0 {
		return nil
	}
	out := make([]float64, n)
	if step <= 0 {
		sum := 0.0
		for i, v := range ideals {
			if v < 0 {
				v = 0
			}
			out[i] = v
			sum += v
		}
		out[n-1] += groupTax - sum
		return out
	}

	eps := step * 1e-9
	frac := make([]float64, n)
	sumBase := 0.0
	for i, v := range ideals {
		if v < 0 {
			v = 0
		}
		base := math.Floor(v/step+1e-9) * step // step-multiple ≤ ideal
		out[i] = base
		frac[i] = v - base // remainder in [0, step)
		sumBase += base
	}

	// Line indices ordered by fractional remainder, largest first; SliceStable
	// keeps ties in input order so re-runs are deterministic.
	byFracDesc := make([]int, n)
	for i := range byFracDesc {
		byFracDesc[i] = i
	}
	sort.SliceStable(byFracDesc, func(a, b int) bool { return frac[byFracDesc[a]] > frac[byFracDesc[b]] })

	deficit := groupTax - sumBase
	if deficit >= -eps {
		wholeSteps := max(0, min(int(math.Floor(deficit/step+1e-9)), n))
		for k := range wholeSteps {
			out[byFracDesc[k]] += step
		}
		sum := 0.0
		for _, v := range out {
			sum += v
		}
		if residual := groupTax - sum; math.Abs(residual) > eps {
			out[byFracDesc[min(wholeSteps, n-1)]] += residual
		}
	} else {
		// Rare 内税 case: exact group tax sits just below the floored sum. Remove
		// the tiny (< step) shortfall from the line with the most room so no line
		// can go negative (Σ base > |deficit| here).
		byBaseDesc := make([]int, n)
		for i := range byBaseDesc {
			byBaseDesc[i] = i
		}
		sort.SliceStable(byBaseDesc, func(a, b int) bool { return out[byBaseDesc[a]] > out[byBaseDesc[b]] })
		out[byBaseDesc[0]] += deficit // deficit < 0
	}

	for i := range out {
		if out[i] < 0 && out[i] > -eps {
			out[i] = 0 // scrub fp dust
		}
	}
	return out
}

// TaxGroup is one per-rate breakdown row (mirror of PHP TaxGroup).
type TaxGroup struct {
	Rate    float64
	Taxable float64
	Tax     float64
}

// PricingResult is the engine output (mirror of PHP PricingResult).
type PricingResult struct {
	Subtotal         float64
	Discount         float64
	TaxableBase      float64
	Groups           []TaxGroup
	ServiceCharge    float64
	ServiceChargeTax float64
	TaxAmount        float64
	TotalAmount      float64
	PricesIncludeTax bool
}

// rateKey formats a rate as a stable map key the same way PHP's (string) cast
// does — an integer-valued float renders without a decimal point ("8", "10"),
// matching `(string) $rate` so the sc-tax group merge finds the same key on
// both sides.
func rateKey(rate float64) string {
	return strconv.FormatFloat(rate, 'f', -1, 64)
}

// priceGroups is the exact port of OrderPricingCalculator::priceGroups.
//
// rateSubtotals maps rateKey(rate) → Σ line subtotal for that rate. Iteration
// order over a Go map is random, but the algorithm is order-independent for the
// group taxes (each group is computed from its own subtotal + the pro-rata
// share of the global discount) and the final Groups slice is sorted by rate,
// so the result is deterministic and matches PHP's ksort output.
func priceGroups(
	rateSubtotals map[string]float64,
	discount float64,
	serviceChargeRate float64,
	serviceChargeTaxRate float64,
	pricesIncludeTax bool,
	step float64,
	taxStep float64,
	taxMode string,
	discountWeights map[string]float64,
) PricingResult {
	// plan-045 — TAX figures round with the order's snapshot rule (taxStep +
	// taxMode); the service-charge BASE and the grand TOTAL still round to the
	// currency minor unit (step). Passing taxStep = step + "round" keeps the
	// pre-plan-045 behaviour byte-for-byte (round ≡ the old half_up).
	subtotal := 0.0
	for _, groupSubtotal := range rateSubtotals {
		subtotal += groupSubtotal
	}
	subtotal = math.Max(0.0, subtotal)
	discount = math.Max(0.0, math.Min(discount, subtotal))
	taxableBase := math.Max(0.0, subtotal-discount)

	// Step 3 — service charge on (subtotal − discount). In included mode that
	// base is gross (Q12). sc tax is its own rate: added on top when excluded,
	// extracted when included.
	serviceCharge := roundHalfUpToStep(taxableBase*serviceChargeRate/100.0, step)
	var serviceChargeTax float64
	if pricesIncludeTax {
		// #2480 — same shape as GroupTaxFor: round the TAX, not the base.
		serviceChargeTax = roundToStep(serviceCharge*serviceChargeTaxRate/(100.0+serviceChargeTaxRate), taxStep, taxMode)
	} else {
		serviceChargeTax = roundToStep(serviceCharge*serviceChargeTaxRate/100.0, taxStep, taxMode)
	}

	// Steps 1+2+4 — per-rate groups with pro-rata discount + once-per-group tax.
	groups := map[string]TaxGroup{}
	totalGroupTax := 0.0
	sumNet := 0.0 // Σ taxable (excluded) or Σ gross (included)

	// #2253 / #2240 — optional pro-rata denominator: default is gross THÔ per
	// group (legacy), but partial-refund orders pass surviving gross by rate so
	// coupon share migrates off emptied groups onto what the guest still holds.
	weightSum := 0.0
	if discountWeights != nil {
		for _, w := range discountWeights {
			weightSum += math.Max(0.0, w)
		}
	}

	for rk, groupSubtotal := range rateSubtotals {
		rate, _ := strconv.ParseFloat(rk, 64)
		var discountGroup float64
		if discountWeights != nil {
			w := math.Max(0.0, discountWeights[rk])
			if weightSum > 0 {
				discountGroup = discount * w / weightSum
			}
		} else if subtotal > 0 {
			discountGroup = discount * groupSubtotal / subtotal
		}
		netGroup := math.Max(0.0, groupSubtotal-discountGroup)

		// Round tax ONCE per rate group. The SAME GroupTaxFor figure is reused
		// by the LAN Z-report so its per-rate breakdown rounds once per group
		// (端数処理は税率ごとに1回) instead of summing the per-line snapshots.
		groupTax := GroupTaxFor(netGroup, rate, pricesIncludeTax, taxStep, taxMode)
		taxable := netGroup
		if pricesIncludeTax {
			taxable = netGroup - groupTax
		}

		groups[rateKey(rate)] = TaxGroup{Rate: rate, Taxable: taxable, Tax: groupTax}
		totalGroupTax += groupTax
		sumNet += netGroup
	}

	// gap #7 — service-charge tax joins the breakdown group of the SAME rate
	// (or forms one if none exists). Its taxable share is added too.
	if serviceCharge > 0 && serviceChargeTaxRate > 0 {
		scRateKey := rateKey(serviceChargeTaxRate)
		scNet := serviceCharge
		if pricesIncludeTax {
			scNet = serviceCharge - serviceChargeTax
		}
		existing := groups[scRateKey] // zero value when absent
		groups[scRateKey] = TaxGroup{
			Rate:    serviceChargeTaxRate,
			Taxable: existing.Taxable + scNet,
			Tax:     existing.Tax + serviceChargeTax,
		}
	}

	// Stable display order: by rate ascending (mirror of PHP ksort SORT_NUMERIC).
	ordered := make([]TaxGroup, 0, len(groups))
	for _, g := range groups {
		ordered = append(ordered, g)
	}
	sort.Slice(ordered, func(i, j int) bool { return ordered[i].Rate < ordered[j].Rate })

	taxAmount := totalGroupTax + serviceChargeTax

	var totalAmount float64
	if pricesIncludeTax {
		// Prices already include tax → do NOT add group taxes again.
		// total = Σ gross groups + service charge (gross, its tax inside).
		totalAmount = roundHalfUpToStep(sumNet+serviceCharge, step)
	} else {
		totalAmount = roundHalfUpToStep(
			taxableBase+totalGroupTax+serviceCharge+serviceChargeTax,
			step,
		)
	}

	return PricingResult{
		Subtotal:         subtotal,
		Discount:         discount,
		TaxableBase:      taxableBase,
		Groups:           ordered,
		ServiceCharge:    serviceCharge,
		ServiceChargeTax: serviceChargeTax,
		TaxAmount:        taxAmount,
		TotalAmount:      totalAmount,
		PricesIncludeTax: pricesIncludeTax,
	}
}

// RefundLine is one appended refund order_item's negated snapshot as the pricing
// engine sees it — the minimal input applyRefundLines needs. subtotal + tax are
// the STORED (already negative) figures; rate is the copied tax_rate. Voided
// refund lines are filtered out by the caller before this slice is built.
type RefundLine struct {
	Subtotal  float64 // negative
	TaxAmount float64 // negative (≤ 0)
	Rate      float64
}

// applyRefundLines folds appended REFUND lines (negative qty, refund_of_item_id
// set, copied+negated tax snapshot) into a base result computed from the POSITIVE
// lines — the port of OrderPricingCalculator::applyRefundLines (PHP). Refund
// lines NEVER go through group-once rounding; their stored (negated) subtotal +
// tax_amount are added DIRECTLY so the reversal exactly cancels the original
// line's tax (Stripe reversal semantics). Each matching rate group's tax +
// taxable is reduced by the refund share; a refund at a rate whose positive
// group is gone (fully refunded) still gets its own row so Σ groups.tax ==
// tax_amount.
func applyRefundLines(base PricingResult, refunds []RefundLine) PricingResult {
	if len(refunds) == 0 {
		return base
	}

	refundSubtotal := 0.0
	refundTax := 0.0
	groupTaxDelta := map[string]float64{} // rate(string) → Σ refund tax (negative)
	groupNetDelta := map[string]float64{} // rate(string) → Σ refund taxable (negative)

	for _, r := range refunds {
		refundSubtotal += r.Subtotal
		refundTax += r.TaxAmount
		key := rateKey(r.Rate)
		groupTaxDelta[key] += r.TaxAmount
		// taxable base of the refund: excluded → net = subtotal; included →
		// net = gross − internal tax = subtotal − tax.
		net := r.Subtotal
		if base.PricesIncludeTax {
			net = r.Subtotal - r.TaxAmount
		}
		groupNetDelta[key] += net
	}

	groups := make([]TaxGroup, 0, len(base.Groups)+len(groupTaxDelta))
	seen := map[string]bool{}
	for _, g := range base.Groups {
		key := rateKey(g.Rate)
		seen[key] = true
		groups = append(groups, TaxGroup{
			Rate:    g.Rate,
			Taxable: g.Taxable + groupNetDelta[key],
			Tax:     g.Tax + groupTaxDelta[key],
		})
	}
	// A refund at a rate whose positive group is already gone (fully refunded)
	// still needs its own row so Σ groups.tax == tax_amount.
	for key, taxDelta := range groupTaxDelta {
		if seen[key] {
			continue
		}
		rate, _ := strconv.ParseFloat(key, 64)
		groups = append(groups, TaxGroup{Rate: rate, Taxable: groupNetDelta[key], Tax: taxDelta})
	}
	sort.Slice(groups, func(i, j int) bool { return groups[i].Rate < groups[j].Rate })

	totalDelta := refundSubtotal
	if !base.PricesIncludeTax {
		totalDelta = refundSubtotal + refundTax
	}

	return PricingResult{
		Subtotal:         base.Subtotal + refundSubtotal,
		Discount:         base.Discount,
		TaxableBase:      base.TaxableBase,
		Groups:           groups,
		ServiceCharge:    base.ServiceCharge,
		ServiceChargeTax: base.ServiceChargeTax,
		TaxAmount:        base.TaxAmount + refundTax,
		TotalAmount:      base.TotalAmount + totalDelta,
		PricesIncludeTax: base.PricesIncludeTax,
	}
}
