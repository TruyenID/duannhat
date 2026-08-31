package service

import (
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

// #2032 — máy trạm phải TỰ ghi sổ `order_conditions`, không đợi sync UP.
//
// Trước bài này nó chỉ ghi dòng `refund`; `tax` và `discount` chỉ xuất hiện sau
// khi đơn lên Cloud và Cloud tính lại. Hệ quả trên máy: `loadOrderConditions`
// phơi `conditions[]` RỖNG cho POS/KDS, nên giao diện nói "không thuế, không
// giảm giá" trong khi tờ giấy đã in nói ngược lại — và nếu máy hỏng trước khi
// sync thì sổ không bao giờ tồn tại.
//
// Máy trạm vốn đã có đủ số: `priceGroups` là bản port chính xác của
// `OrderPricingCalculator` và có cổng parity trên fixture dùng chung. Nó chỉ là
// không ghi.

func condTestOrder(t *testing.T, db *store.DB, discount int) string {
	t.Helper()
	id := uuid.NewString()
	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, discount_amount, total_amount, paid_amount, is_tax_included,
		    tax_rounding_mode, created_at, updated_at)
		VALUES (?, ?, 'spot', 'open', ?, 0, ?, 0, 0, 0, 'round', ?, ?)`,
		id, "C-"+id[:8], now, discount, now, now); err != nil {
		t.Fatalf("insert order: %v", err)
	}
	return id
}

func condTestItem(t *testing.T, db *store.DB, orderID string, qty int, unitPrice, rate float64) string {
	t.Helper()
	id := uuid.NewString()
	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := db.Exec(`
		INSERT INTO order_items (id, customer_order_id, quantity, unit_price,
		    topping_subtotal, subtotal, tax_rate, tax_amount, status, created_at, updated_at)
		VALUES (?, ?, ?, ?, 0, ?, ?, 0, 'served', ?, ?)`,
		id, orderID, qty, unitPrice, float64(qty)*unitPrice, rate, now, now); err != nil {
		t.Fatalf("insert item: %v", err)
	}
	return id
}

type condRow struct {
	Type        string
	Source      string
	Rate        *float64
	Amount      float64
	TaxableBase *float64
}

func readConds(t *testing.T, db *store.DB, orderID, condType string) []condRow {
	t.Helper()
	rows, err := db.Query(`
		SELECT type, COALESCE(source, ''), rate, amount, taxable_base
		FROM order_conditions
		WHERE conditionable_type = 'order' AND conditionable_id = ? AND type = ?
		ORDER BY rate`, orderID, condType)
	if err != nil {
		t.Fatalf("read conditions: %v", err)
	}
	defer rows.Close()
	var out []condRow
	for rows.Next() {
		var r condRow
		if err := rows.Scan(&r.Type, &r.Source, &r.Rate, &r.Amount, &r.TaxableBase); err != nil {
			t.Fatalf("scan: %v", err)
		}
		out = append(out, r)
	}
	return out
}

func TestConditions_TaxRowPerRateWithTaxableBase(t *testing.T) {
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	orderID := condTestOrder(t, db, 0)
	condTestItem(t, db, orderID, 1, 1000, 8)
	condTestItem(t, db, orderID, 1, 1000, 10)

	if err := e.RecalcOrderTotals(orderID); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	tax := readConds(t, db, orderID, "tax")
	if len(tax) != 2 {
		t.Fatalf("muốn 2 dòng thuế (8%% và 10%%), có %d", len(tax))
	}
	for _, r := range tax {
		if r.Rate == nil {
			t.Fatalf("dòng thuế thiếu rate")
		}
		// `taxable_base` là 税率ごとに区分した対価の額 — trường BẮT BUỘC của
		// 適格請求書 và là con số khách cầm trên tay. Thiếu nó thì hoá đơn phải
		// suy lại nền chịu thuế ở đường đọc, đúng cách #2031 đã sinh ra lỗi.
		if r.TaxableBase == nil {
			t.Fatalf("mức %.0f%%: taxable_base NULL", *r.Rate)
		}
		// Mỗi dòng phải tự cân ở chính thuế suất nó khai.
		if want := *r.TaxableBase * *r.Rate / 100.0; r.Amount < want-1 || r.Amount > want+1 {
			t.Errorf("mức %.0f%%: nền %.2f × %.0f%% = %.2f, nhưng thuế ghi %.2f",
				*r.Rate, *r.TaxableBase, *r.Rate, want, r.Amount)
		}
	}
}

func TestConditions_DiscountRowPerRateSumsExactly(t *testing.T) {
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	orderID := condTestOrder(t, db, 300)
	condTestItem(t, db, orderID, 1, 1000, 8)
	condTestItem(t, db, orderID, 1, 1000, 10)

	if err := e.RecalcOrderTotals(orderID); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	disc := readConds(t, db, orderID, "discount")
	if len(disc) == 0 {
		t.Fatalf("không có dòng giảm giá nào")
	}
	sum := 0.0
	for _, r := range disc {
		if r.Amount >= 0 {
			t.Errorf("giảm giá phải ÂM, có %.2f", r.Amount)
		}
		sum += r.Amount
	}
	// Bất biến, không phải xấp xỉ: phần dư của phép pro-rata được đặt vào mức
	// cuối chính là để tổng khớp tuyệt đối với cột.
	if sum != -300 {
		t.Errorf("Σ(discount) = %.2f, muốn -300 đúng bằng discount_amount", sum)
	}
}

func TestConditions_RecomputeIsIdempotent(t *testing.T) {
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	orderID := condTestOrder(t, db, 100)
	condTestItem(t, db, orderID, 2, 500, 10)

	for i := 0; i < 3; i++ {
		if err := e.RecalcOrderTotals(orderID); err != nil {
			t.Fatalf("recalc %d: %v", i, err)
		}
	}

	// Sổ này tái sinh (xoá rồi ghi lại), không append. Quên xoá thì mỗi lần
	// chạm đơn lại sinh thêm một bộ dòng, và tổng sổ phình lên trong khi cột
	// đứng yên.
	if got := len(readConds(t, db, orderID, "tax")); got != 1 {
		t.Errorf("sau 3 lần tính lại: %d dòng thuế, muốn 1", got)
	}
	if got := len(readConds(t, db, orderID, "discount")); got != 1 {
		t.Errorf("sau 3 lần tính lại: %d dòng giảm giá, muốn 1", got)
	}
}

func TestConditions_RecomputeKeepsRefundRows(t *testing.T) {
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	orderID := condTestOrder(t, db, 0)
	condTestItem(t, db, orderID, 1, 1000, 10)

	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := db.Exec(`
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id,
		    type, source, label, rate, amount, currency_code, created_at, updated_at)
		VALUES (?, 'order', ?, 'refund', 'manual', 'Refund', 10, -500, 'JPY', ?, ?)`,
		uuid.NewString(), orderID, now, now); err != nil {
		t.Fatalf("seed refund: %v", err)
	}

	if err := e.RecalcOrderTotals(orderID); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	// `refund` là append-only: nó ghi một SỰ KIỆN đã xảy ra, không phải một giá
	// trị dẫn xuất. Cuốn nó vào lượt xoá-ghi-lại là xoá mất lịch sử hoàn tiền.
	if got := len(readConds(t, db, orderID, "refund")); got != 1 {
		t.Errorf("dòng refund bị lượt tính lại xoá mất (còn %d)", got)
	}
}

func TestConditions_NoDiscountRowWhenNoDiscount(t *testing.T) {
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	orderID := condTestOrder(t, db, 0)
	condTestItem(t, db, orderID, 1, 1000, 10)

	if err := e.RecalcOrderTotals(orderID); err != nil {
		t.Fatalf("recalc: %v", err)
	}
	if got := len(readConds(t, db, orderID, "discount")); got != 0 {
		t.Errorf("không có giảm giá mà vẫn ghi %d dòng", got)
	}
}

// ── Biên (#2041 — gương lại bộ biên phía Cloud) ─────────────────────────────

func condRows(t *testing.T, db *store.DB, orderID, condType string) []condRow {
	t.Helper()
	return readConds(t, db, orderID, condType)
}

func condSum(t *testing.T, db *store.DB, orderID, condType string) float64 {
	t.Helper()
	sum := 0.0
	for _, r := range condRows(t, db, orderID, condType) {
		sum += r.Amount
	}
	return sum
}

func TestConditions_TaxableBaseIsNetInIncludedMode(t *testing.T) {
	// ¥1.100 đã gồm 10% ⇒ nền 1.000, thuế 100. Lưu giá niêm yết 1.100 vào
	// `taxable_base` là sai kiểu rất dễ lọt: tổng đơn vẫn đúng, chỉ tờ hoá đơn
	// nói dối. Cloud ghim đúng ca này; phía Go phải khớp, nếu không bản sao LAN
	// và Cloud in ra hai con số khác nhau cho cùng một đơn.
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	id := uuid.NewString()
	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, discount_amount, total_amount, paid_amount, is_tax_included,
		    tax_rounding_mode, created_at, updated_at)
		VALUES (?, ?, 'spot', 'open', ?, 0, 0, 0, 0, 1, 'round', ?, ?)`,
		id, "I-"+id[:8], now, now, now); err != nil {
		t.Fatalf("insert order: %v", err)
	}
	condTestItem(t, db, id, 1, 1100, 10)

	if err := e.RecalcOrderTotals(id); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	rows := condRows(t, db, id, "tax")
	if len(rows) != 1 {
		t.Fatalf("muốn 1 dòng thuế, có %d", len(rows))
	}
	if rows[0].TaxableBase == nil {
		t.Fatal("taxable_base NULL ở chế độ giá đã gồm thuế")
	}
	if got := *rows[0].TaxableBase; got != 1000 {
		t.Errorf("taxable_base = %.2f, muốn 1000 (nền CHƯA gồm thuế)", got)
	}
	if rows[0].Amount != 100 {
		t.Errorf("thuế = %.2f, muốn 100", rows[0].Amount)
	}
}

func TestConditions_DiscountSplitsPerRate(t *testing.T) {
	// Lỗ mà kiểm đột biến phía Cloud tìm ra: nếu phép chia theo mức hỏng và rơi
	// về một dòng `rate=null` mang cả khoản giảm, thì Σ vẫn khớp và mọi bất
	// biến vẫn xanh — trong khi hoá đơn mất khả năng nói mức 8% được giảm bao
	// nhiêu. Ghim ở cả hai phía.
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	id := condTestOrder(t, db, 300)
	condTestItem(t, db, id, 1, 1000, 8)
	condTestItem(t, db, id, 1, 1000, 10)

	if err := e.RecalcOrderTotals(id); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	rows := condRows(t, db, id, "discount")
	if len(rows) != 2 {
		t.Fatalf("muốn 2 dòng giảm giá (một mỗi mức), có %d", len(rows))
	}
	for _, r := range rows {
		if r.Rate == nil {
			t.Error("dòng giảm giá thiếu rate — đã rơi về nhánh dự phòng")
		}
	}
	if got := condSum(t, db, id, "discount"); got != -300 {
		t.Errorf("Σ(discount) = %.2f, muốn -300 đúng tuyệt đối", got)
	}
}

func TestConditions_DiscountProRataUsesGrossNotNet(t *testing.T) {
	// Mẫu số là tiền món GỘP (chưa trừ giảm giá). Lấy nền SAU giảm thì tỉ lệ
	// khác tỉ lệ đã dùng lúc tính thuế và phần dư đi lạc — sai lệch nhỏ, chỉ
	// lộ trên đơn nhiều mức có tỉ trọng lệch nhau.
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	id := condTestOrder(t, db, 400)
	condTestItem(t, db, id, 1, 1000, 8)
	condTestItem(t, db, id, 1, 3000, 10)

	if err := e.RecalcOrderTotals(id); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	got := map[float64]float64{}
	for _, r := range condRows(t, db, id, "discount") {
		if r.Rate != nil {
			got[*r.Rate] = r.Amount
		}
	}
	if got[8] != -100 {
		t.Errorf("mức 8%%: %.2f, muốn -100 (1000/4000 × 400)", got[8])
	}
	if got[10] != -300 {
		t.Errorf("mức 10%%: %.2f, muốn -300 (3000/4000 × 400)", got[10])
	}
}

func TestConditions_DiscountSplitUsesTaxStepNotCurrencyStep(t *testing.T) {
	// #2100 D7 — Cloud phân bổ giảm giá theo RoundingMode::taxStep(
	// tax_rounding_decimals, currency); Go từng dùng currencyStep trơn. Hai
	// step chỉ khác nhau khi đơn mang decimals MỊN hơn đơn vị tiền tệ
	// (taxStep = min(10^-decimals, currencyStep)), nên ghim đúng ca đó:
	// step tiền tệ 1, decimals 2 ⇒ share phải giữ phần xu, không tròn về đồng.
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	id := uuid.NewString()
	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, discount_amount, total_amount, paid_amount, is_tax_included,
		    tax_rounding_mode, tax_rounding_decimals, created_at, updated_at)
		VALUES (?, ?, 'spot', 'open', ?, 0, 401, 0, 0, 0, 'round', 2, ?, ?)`,
		id, "D-"+id[:8], now, now, now); err != nil {
		t.Fatalf("insert order: %v", err)
	}
	condTestItem(t, db, id, 1, 1000, 8)
	condTestItem(t, db, id, 1, 3000, 10)

	if err := e.RecalcOrderTotals(id); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	got := map[float64]float64{}
	for _, r := range condRows(t, db, id, "discount") {
		if r.Rate != nil {
			got[*r.Rate] = r.Amount
		}
	}
	// 401 × 1000/4000 = 100.25 — step 0.01 giữ nguyên; currencyStep(=1) sẽ
	// tròn thành 100 và dồn lệch vào mức cuối.
	if got[8] != -100.25 {
		t.Errorf("mức 8%%: %.2f, muốn -100.25 (taxStep 0.01, không phải step tiền tệ 1)", got[8])
	}
	if got[10] != -300.75 {
		t.Errorf("mức 10%%: %.2f, muốn -300.75 (phần dư sau share 8%%)", got[10])
	}
	if sum := condSum(t, db, id, "discount"); sum != -401 {
		t.Errorf("Σ(discount) = %.2f, muốn -401 tuyệt đối", sum)
	}
}

// condMeta đọc cột meta thô của các dòng sổ một loại (NULL → chuỗi rỗng).
func condMeta(t *testing.T, db *store.DB, orderID, condType string) []string {
	t.Helper()
	rows, err := db.Query(`
		SELECT COALESCE(meta, '') FROM order_conditions
		WHERE type = ? AND conditionable_type = 'order' AND conditionable_id = ?
		ORDER BY rate`, condType, orderID)
	if err != nil {
		t.Fatalf("query meta: %v", err)
	}
	defer rows.Close()
	var out []string
	for rows.Next() {
		var m string
		if err := rows.Scan(&m); err != nil {
			t.Fatalf("scan meta: %v", err)
		}
		out = append(out, m)
	}
	return out
}

func TestConditions_TaxRowCarriesRateGroupMeta(t *testing.T) {
	// #2100 D9 — Cloud ghi meta.rate_group trên dòng thuế; Go từng truyền nil
	// nên bản sao LAN mất chìa khoá đối chiếu nhóm.
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	id := condTestOrder(t, db, 0)
	condTestItem(t, db, id, 1, 1000, 10)

	if err := e.RecalcOrderTotals(id); err != nil {
		t.Fatalf("recalc: %v", err)
	}
	metas := condMeta(t, db, id, "tax")
	if len(metas) != 1 {
		t.Fatalf("muốn 1 dòng thuế, có %d", len(metas))
	}
	if want := `"rate_group":"10"`; !strings.Contains(metas[0], want) {
		t.Errorf("meta dòng thuế = %q, thiếu %s", metas[0], want)
	}
}

func TestConditions_CouponDiscountCarriesCouponIDMeta(t *testing.T) {
	// #2100 D9 — đơn coupon: Cloud cộng coupon_id vào meta MỌI dòng giảm giá.
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	id := condTestOrder(t, db, 300)
	if _, err := db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value)
		VALUES ('cp-123', 'SUMMER', 'Summer', 'flat', 300)`); err != nil {
		t.Fatalf("seed coupon: %v", err)
	}
	if _, err := db.Exec(`
		INSERT INTO order_coupons (id, order_id, coupon_id, coupon_code, discount_applied)
		VALUES ('oc-1', ?, 'cp-123', 'SUMMER', 300)`, id); err != nil {
		t.Fatalf("bind coupon: %v", err)
	}
	condTestItem(t, db, id, 1, 1000, 8)
	condTestItem(t, db, id, 1, 1000, 10)

	if err := e.RecalcOrderTotals(id); err != nil {
		t.Fatalf("recalc: %v", err)
	}
	metas := condMeta(t, db, id, "discount")
	if len(metas) != 2 {
		t.Fatalf("muốn 2 dòng giảm giá, có %d", len(metas))
	}
	for i, m := range metas {
		if !strings.Contains(m, `"coupon_id":"cp-123"`) {
			t.Errorf("dòng giảm giá %d meta = %q, thiếu coupon_id", i, m)
		}
	}
}

func TestConditions_ServiceChargeMetaEchoesSettingVerbatim(t *testing.T) {
	// #2100 D9 — Cloud ghi (string)$settings->service_charge_rate → "5.00".
	// Go từng FormatFloat lại thành "5"; hai sổ lệch byte trên cùng cấu hình.
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)
	setShopSetting(t, e, "service_charge_rate", "5.00")

	id := condTestOrder(t, db, 0)
	condTestItem(t, db, id, 1, 1000, 10)

	if err := e.RecalcOrderTotals(id); err != nil {
		t.Fatalf("recalc: %v", err)
	}
	metas := condMeta(t, db, id, "service_charge")
	if len(metas) != 1 {
		t.Fatalf("muốn 1 dòng phí phục vụ, có %d", len(metas))
	}
	if want := `"charge_rate":"5.00"`; !strings.Contains(metas[0], want) {
		t.Errorf("meta phí phục vụ = %q, muốn chứa %s (nguyên văn setting, không FormatFloat)", metas[0], want)
	}
}

func TestConditions_VoidedLineLeavesTaxableBase(t *testing.T) {
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	id := condTestOrder(t, db, 0)
	condTestItem(t, db, id, 1, 1000, 10)
	voided := condTestItem(t, db, id, 1, 5000, 10)
	if _, err := db.Exec(`UPDATE order_items SET status='voided' WHERE id=?`, voided); err != nil {
		t.Fatalf("void: %v", err)
	}

	if err := e.RecalcOrderTotals(id); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	rows := condRows(t, db, id, "tax")
	if len(rows) != 1 || rows[0].TaxableBase == nil {
		t.Fatalf("muốn 1 dòng thuế có nền, có %d", len(rows))
	}
	if got := *rows[0].TaxableBase; got != 1000 {
		t.Errorf("nền = %.2f, muốn 1000 — món voided đã lọt vào", got)
	}
}

func TestConditions_RateLabelMatchesCloudFormat(t *testing.T) {
	// Nhãn được ĐÓNG BĂNG vào sổ rồi in ra giấy, và Cloud sinh "10%" chứ không
	// phải "10.00%". Hai phía lệch nhãn thì cùng một đơn in ra hai kiểu tuỳ
	// nó được tính ở đâu.
	for _, c := range []struct {
		rate float64
		want string
	}{
		{10, "10%"}, {8, "8%"}, {8.5, "8.5%"}, {0, "0%"},
	} {
		if got := formatRateLabel(c.rate); got != c.want {
			t.Errorf("formatRateLabel(%v) = %q, muốn %q", c.rate, got, c.want)
		}
	}
}

func TestConditions_ZeroRateGroupStillGetsARow(t *testing.T) {
	// Peppol BR-Z-08 / BR-E-08 + 非課税 ở Nhật: nhóm 0% phải có mặt trong bảng
	// thuế kèm NỀN của nó. Điều kiện cũ `g.Tax == 0` nuốt mất nó, nên đơn trộn
	// 非課税 với 課税 có tổng nền KHÔNG bằng subtotal — phần 非課税 biến mất khỏi
	// hoá đơn. Cloud ghim đúng ca này; hai phía lệch thì bản sao LAN in thiếu
	// một dòng bắt buộc.
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	id := condTestOrder(t, db, 0)
	condTestItem(t, db, id, 1, 1000, 0)
	condTestItem(t, db, id, 1, 1000, 10)

	if err := e.RecalcOrderTotals(id); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	rows := condRows(t, db, id, "tax")
	if len(rows) != 2 {
		t.Fatalf("muốn 2 dòng thuế (0%% và 10%%), có %d — nhóm 0%% bị nuốt", len(rows))
	}

	base := 0.0
	for _, r := range rows {
		if r.TaxableBase == nil {
			t.Fatalf("dòng mức %v thiếu taxable_base", r.Rate)
		}
		base += *r.TaxableBase
	}
	if base != 2000 {
		t.Errorf("Σ nền = %.2f, muốn 2000 (= subtotal)", base)
	}
}

func TestConditions_TrulyEmptyGroupWritesNoRow(t *testing.T) {
	// Mặt kia của cùng điều kiện: nhóm KHÔNG có gì (nền 0 và thuế 0) thì không
	// được ghi dòng rỗng. Nới `g.Tax == 0` thành "luôn ghi" sẽ sinh rác.
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)

	id := condTestOrder(t, db, 0)
	condTestItem(t, db, id, 1, 0, 10)

	if err := e.RecalcOrderTotals(id); err != nil {
		t.Fatalf("recalc: %v", err)
	}
	if got := len(condRows(t, db, id, "tax")); got != 0 {
		t.Errorf("nhóm rỗng vẫn ghi %d dòng", got)
	}
}
