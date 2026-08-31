package service

import (
	"math"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

// #2155 — bảng phân rã 消費税内訳 phải đọc CÙNG NGUỒN với tổng thuế.
//
// Sau #2140 tổng đọc sổ `order_conditions`, còn `PerRateTaxBuckets` vẫn đọc cột.
// Cùng một phiếu, hai nguồn — và độ lệch bị đóng băng vào `settlement_snapshot`.
//
// Nó không dừng ở chỗ hiển thị mâu thuẫn: `lan_chain_report.go` suy phí phục vụ
// của phiếu chuỗi bằng PHẦN DƯ (`TaxTotal - Σ rateTax`), nên chênh lệch được IN
// RA thành サービス料消費税 mà không ai thu.
//
// ## Fixture PHẢI có phí phục vụ, nếu không bài test không phát biểu gì
//
// Đây là chỗ bản đầu của file này hỏng (review #2155): fixture không có phí phục
// vụ và không có giảm giá, mà khi ấy đường SỔ và đường CỘT cho **cùng một con
// số** — bài test xanh y hệt trên bản chưa sửa.
//
// Nguồn phân kỳ THẬT nằm ở `priceGroups` (gap #7): thuế của phí phục vụ được
// GỘP vào chính nhóm mức cùng tỉ lệ, nên nó có mặt trong `Σ type='tax'` của sổ
// nhưng KHÔNG thể dựng lại từ dòng hàng (`order_items`) — phí phục vụ không phải
// một dòng hàng. Bỏ phí phục vụ khỏi fixture là bỏ luôn thứ đang đo.
//
// Lưu ý bất biến ĐÚNG là `TaxTotal == Σ breakdown`, KHÔNG phải
// `== Σ breakdown + ServiceChargeTax` như issue đề nghị: thuế phí phục vụ đã
// nằm trong các nhóm rồi. Cộng thêm một lần nữa là đếm đúp.

func bucketsPaidOrder(t *testing.T, db *store.DB, sessionID string) string {
	t.Helper()
	orderID := condTestOrder(t, db, 0)
	now := time.Now().UTC().Format(time.RFC3339)

	if _, err := db.Exec(`
		INSERT INTO payments (id, order_id, amount, payment_method, status, till_session_id, created_at, updated_at)
		VALUES (?, ?, 0, 'cash', 'confirmed', ?, ?, ?)`,
		uuid.NewString(), orderID, sessionID, now, now); err != nil {
		t.Fatalf("insert payment: %v", err)
	}
	return orderID
}

// seedServiceCharge bật phí phục vụ cho quán. `chargeRate` là tỉ lệ TÍNH phí,
// `chargeTaxRate` là mức THUẾ khoản phí ấy chịu — hai con số khác nhau, và chính
// cái thứ hai quyết định phí phục vụ nhập vào nhóm mức nào (gap #7).
func seedServiceCharge(t *testing.T, db *store.DB, chargeRate, chargeTaxRate string) {
	t.Helper()
	if _, err := db.Exec(`
		INSERT INTO shop_settings (key, value) VALUES
		  ('tax_rate', '10.00'),
		  ('service_charge_rate', ?),
		  ('service_charge_tax_rate', ?)
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
		chargeRate, chargeTaxRate); err != nil {
		t.Fatalf("seed service charge: %v", err)
	}
}

func ledgerTaxTotal(t *testing.T, db *store.DB) float64 {
	t.Helper()
	var total float64
	if err := db.QueryRow(`
		SELECT COALESCE(SUM(amount), 0) FROM order_conditions
		WHERE conditionable_type = 'order' AND type = 'tax'`).Scan(&total); err != nil {
		t.Fatalf("đọc tổng thuế trên sổ: %v", err)
	}
	return total
}

// ledgerRowCount đếm dòng sổ của một đơn — tiêu chí "đã có sổ" mà cả
// `PerRateTaxBuckets` lẫn `orderLedgerJoinSQL` dùng (ĐẾM DÒNG, không phải tổng
// khác 0 — bẫy #2074).
func ledgerRowCount(t *testing.T, db *store.DB, orderID string) int {
	t.Helper()
	var n int
	if err := db.QueryRow(`SELECT COUNT(*) FROM order_conditions
		WHERE conditionable_type = 'order' AND conditionable_id = ?`, orderID).Scan(&n); err != nil {
		t.Fatalf("đếm dòng sổ: %v", err)
	}
	return n
}

// shiftTaxTotal là con số 消費税 mà phiếu 精算 IN RA cho đúng tập đơn mà
// `PerRateTaxBuckets` cuộn — bản sao nguyên văn quy ước của `orderLedgerJoinSQL`
// bên handler: đơn CÓ DÒNG SỔ thì lấy sổ, đơn KHÔNG có dòng nào thì lấy cột
// `orders.tax_amount`.
//
// Đây là vế phải của bất biến. Vị ngữ "đơn đã thanh toán thuộc ca này" được
// CHÉP RA ĐÂY thay vì dùng lại hằng `paidOrderIDsSQL` của code sản phẩm — cố ý,
// và vì hai lý do:
//
//  1. nếu hằng ấy trôi khỏi vị ngữ mà tổng thuế dùng, bài test phải ĐỎ chứ
//     không phải trôi theo — dùng chung hằng thì hai vế không bao giờ cãi nhau
//     được, và ca HỖN HỢP mất hết ý nghĩa;
//  2. bài test phải BIÊN DỊCH ĐƯỢC trên bản TRƯỚC bản sửa (hằng ấy chưa tồn
//     tại) — nếu không, "chiều ngược" chỉ chứng minh có một hằng mới ra đời,
//     chứ không chứng minh hành vi khác đi.
func shiftTaxTotal(t *testing.T, db *store.DB, sessionID, lower, upper string) int {
	t.Helper()
	var total float64
	if err := db.QueryRow(`
		SELECT COALESCE(SUM(CASE WHEN COALESCE(lc.rows_seen, 0) > 0
		                         THEN COALESCE(lc.tax, 0)
		                         ELSE COALESCE(o.tax_amount, 0) END), 0)
		FROM orders o
		LEFT JOIN (
			SELECT conditionable_id AS order_id,
			       SUM(CASE WHEN type = 'tax' THEN amount ELSE 0 END) AS tax,
			       COUNT(*) AS rows_seen
			FROM order_conditions
			WHERE conditionable_type = 'order'
			  AND type IN ('tax','discount','service_charge')
			GROUP BY conditionable_id
		) lc ON lc.order_id = o.id
		WHERE o.id IN (
			SELECT DISTINCT order_id FROM payments
			WHERE status IN ('pending','confirmed','succeeded')
			  AND (till_session_id = ?
			       OR ((till_session_id IS NULL OR till_session_id = '')
			           AND substr(created_at,1,19) >= ? AND substr(created_at,1,19) <= ?))
		)`, sessionID, lower, upper).Scan(&total); err != nil {
		t.Fatalf("đọc tổng thuế của ca: %v", err)
	}
	return int(math.Round(total))
}

// sumBucketTax cộng cột 消費税 của bảng phân rã.
func sumBucketTax(buckets []ShiftTaxRateLine) int {
	var sum int
	for _, b := range buckets {
		sum += b.Tax
	}
	return sum
}

// serviceChargeOrder dựng ĐÚNG hình dạng mà bài này tồn tại vì: đơn có phí phục
// vụ, và thuế của phí ấy rơi vào cùng mức với một nhóm hàng đã có.
//
//	hàng:  1 × ¥1.000 @ 8%   +   1 × ¥1.000 @ 10%      (subtotal ¥2.000)
//	phí:   10% × ¥2.000 = ¥200, chịu thuế 10% → ¥20
//
// SỔ (gap #7 gộp thuế phí vào nhóm 10%):
//
//	8%  → nền ¥1.000, thuế ¥80
//	10% → nền ¥1.200 (1.000 hàng + 200 phí), thuế ¥120 (100 hàng + 20 phí)
//	Σ thuế = ¥200 — khớp `orders.tax_amount`.
//
// CỘT (dựng lại từ `order_items`, đường dự phòng): 8% → ¥80, 10% → ¥100,
// Σ = ¥180. Thiếu đúng ¥20 thuế phí phục vụ, vì phí phục vụ không phải dòng
// hàng nên không đường nào dựng lại nó từ `order_items` được.
//
// ¥20 ấy chính là con số bị IN RA thành サービス料消費税 ở phiếu chuỗi.
func serviceChargeOrder(t *testing.T, db *store.DB, e *OrderEngine, sessionID string) string {
	t.Helper()
	orderID := bucketsPaidOrder(t, db, sessionID)
	condTestItem(t, db, orderID, 1, 1000, 8)
	condTestItem(t, db, orderID, 1, 1000, 10)
	if err := e.RecalcOrderTotals(orderID); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	// Tiền đề của fixture, không phải thứ đang đo: nếu phí phục vụ KHÔNG được
	// ghi thì hai đường hết phân kỳ và bài test tự rỗng nghĩa. Đã xảy ra một
	// lần (bản đầu của file này), nên nó được khẳng định thành tiếng.
	var sc float64
	if err := db.QueryRow(`SELECT COALESCE(SUM(amount), 0) FROM order_conditions
		WHERE conditionable_type = 'order' AND conditionable_id = ? AND type = 'service_charge'`,
		orderID).Scan(&sc); err != nil {
		t.Fatalf("đọc phí phục vụ trên sổ: %v", err)
	}
	if sc != 200 {
		t.Fatalf("tiền đề hỏng: fixture phải sinh phí phục vụ ¥200, có %.0f — "+
			"không có phí phục vụ thì sổ và cột cho CÙNG con số và bài này không "+
			"phân biệt được bản cũ với bản mới", sc)
	}
	return orderID
}

// Bất biến: `Σ 消費税内訳 == 消費税` trên CÙNG một tờ phiếu, với đơn có phí phục
// vụ — hình dạng duy nhất mà hai nguồn thật sự nói hai con số khác nhau.
func TestPerRateTaxBuckets_SumMatchesLedgerTaxTotal(t *testing.T) {
	db := newPromoTestDB(t)
	seedServiceCharge(t, db, "10.00", "10.00")
	e := NewOrderEngine(db)
	session := uuid.NewString()

	orderID := serviceChargeOrder(t, db, e, session)

	buckets := PerRateTaxBuckets(db, 1, session, "0000", "9999")
	if len(buckets) != 2 {
		t.Fatalf("muốn 2 nhóm mức (8%% và 10%%), có %+v", buckets)
	}

	// Nhóm 10% phải MANG thuế phí phục vụ: ¥100 (hàng) + ¥20 (phí) = ¥120.
	// Đường cột cho ¥100 ở đây — đó là toàn bộ khác biệt giữa hai bản.
	if buckets[0].Rate != 8 || buckets[0].Tax != 80 || buckets[0].TaxableSales != 1000 {
		t.Errorf("nhóm 8%%: muốn nền 1000 / thuế 80, có %+v", buckets[0])
	}
	if buckets[1].Rate != 10 || buckets[1].Tax != 120 || buckets[1].TaxableSales != 1200 {
		t.Errorf("nhóm 10%%: muốn nền 1200 / thuế 120 (đã gồm ¥200 nền + ¥20 thuế "+
			"phí phục vụ), có %+v — ¥100 nghĩa là đang dựng lại từ CỘT `order_items`, "+
			"nơi phí phục vụ không tồn tại (#2155)", buckets[1])
	}

	sum := sumBucketTax(buckets)
	if want := int(ledgerTaxTotal(t, db)); sum != want {
		t.Errorf("Σ 消費税内訳 = %d nhưng tổng thuế trên SỔ = %d.\n"+
			"Hai con số này in trên CÙNG một tờ phiếu; lệch nhau thì phiếu chuỗi "+
			"suy phần dư ra thành サービス料消費税 không ai thu (#2155).", sum, want)
	}
	// Và phải khớp cả con số mà phiếu thật in ra (sổ-thắng-cột, y như handler).
	if want := shiftTaxTotal(t, db, session, "0000", "9999"); sum != want {
		t.Errorf("Σ 消費税内訳 = %d nhưng 消費税 của phiếu = %d (đơn %s)", sum, want, orderID[:8])
	}
}

// Lượt chạy HỖN HỢP — ca có CẢ đơn đã ghi sổ LẪN đơn chưa có sổ nào.
//
// Đây là ca duy nhất lộ ra nếu `paidOrderIDsSQL` và vị ngữ của tổng thuế mô tả
// hai tập đơn khác nhau: bảng phân rã cuộn hai nguồn trong cùng một lượt, còn
// tổng thì chọn nguồn theo TỪNG ĐƠN. Chỉ cần một trong hai bên bỏ sót hoặc đếm
// thừa một đơn là hai vế lệch, dù mỗi đường tự nó vẫn đúng.
func TestPerRateTaxBuckets_MixedLedgerAndColumnOrdersReconcile(t *testing.T) {
	db := newPromoTestDB(t)
	seedServiceCharge(t, db, "10.00", "10.00")
	e := NewOrderEngine(db)
	session := uuid.NewString()

	// (A) đơn hiện đại: có sổ, có phí phục vụ ⇒ sổ ¥200, cột-dựng-lại ¥180.
	ledgerOrder := serviceChargeOrder(t, db, e, session)

	// (B) đơn máy trạm CŨ (trước plan-045): không dòng sổ nào, chỉ có cột.
	legacyOrder := bucketsPaidOrder(t, db, session)
	condTestItem(t, db, legacyOrder, 1, 1000, 10)
	if _, err := db.Exec(
		`UPDATE orders SET subtotal = 1000, tax_amount = 100 WHERE id = ?`, legacyOrder); err != nil {
		t.Fatalf("set cột cho đơn cũ: %v", err)
	}

	// Tiền đề: đúng một đơn có sổ, đúng một đơn không.
	if n := ledgerRowCount(t, db, ledgerOrder); n == 0 {
		t.Fatalf("tiền đề hỏng: đơn (A) phải CÓ sổ, có %d dòng", n)
	}
	if n := ledgerRowCount(t, db, legacyOrder); n != 0 {
		t.Fatalf("tiền đề hỏng: đơn (B) phải KHÔNG có sổ, có %d dòng", n)
	}

	buckets := PerRateTaxBuckets(db, 1, session, "0000", "9999")
	sum := sumBucketTax(buckets)
	want := shiftTaxTotal(t, db, session, "0000", "9999")

	// Con số tường minh, để lỗi đọc được mà không phải chạy lại đầu óc:
	// sổ(A) 80+120 = 200, cột(B) 100 ⇒ 300. Bản đọc-cột-cho-mọi-đơn ra 280.
	if want != 300 {
		t.Fatalf("tiền đề hỏng: 消費税 của ca phải là 300 (sổ A 200 + cột B 100), có %d", want)
	}
	if sum != want {
		t.Errorf("lượt HỖN HỢP: Σ 消費税内訳 = %d nhưng 消費税 = %d (buckets = %+v).\n"+
			"Đơn (A) có sổ phải đọc SỔ (¥200, đã gồm ¥20 thuế phí phục vụ), đơn (B) "+
			"chưa có sổ phải rơi về CỘT (¥100). Đọc cột cho cả hai ra 280 — và ¥20 "+
			"chênh lệch ấy bị in thành サービス料消費税 không ai thu (#2155).", sum, want, buckets)
	}

	// Bảng phân rã phải gộp hai nguồn vào cùng nhóm mức, không tách đôi.
	if len(buckets) != 2 {
		t.Fatalf("muốn 2 nhóm mức (8%% và 10%%), có %+v", buckets)
	}
	if buckets[1].Rate != 10 || buckets[1].Tax != 220 {
		t.Errorf("nhóm 10%% phải gộp SỔ(120) + CỘT(100) = 220, có %+v", buckets[1])
	}
}

// Đơn CHƯA CÓ SỔ (máy trạm cũ, trước plan-045) vẫn phải xuất hiện trong bảng
// phân rã — bỏ chúng đi thì phiếu 精算 mất doanh thu thật, và triệu chứng lúc đó
// (thiếu tiền) khó truy hơn hẳn cái đang chữa.
//
// Ca này KHÔNG phải "chiều ngược" của #2155 — bản cũ đọc cột cho MỌI đơn nên nó
// xanh ở cả hai bản. Nó ở lại vì là rào chống hồi quy cho đường dự phòng: bản
// sửa rất dễ chỉ đọc sổ và im lặng đánh rơi đám đơn cũ.
func TestPerRateTaxBuckets_OrderWithoutLedgerStillCounted(t *testing.T) {
	db := newPromoTestDB(t)
	session := uuid.NewString()

	orderID := bucketsPaidOrder(t, db, session)
	condTestItem(t, db, orderID, 1, 1000, 10)
	// KHÔNG gọi RecalcOrderTotals ⇒ không có dòng sổ nào cho đơn này.
	if _, err := db.Exec(`UPDATE orders SET subtotal = 1000 WHERE id = ?`, orderID); err != nil {
		t.Fatalf("set subtotal: %v", err)
	}

	if n := ledgerRowCount(t, db, orderID); n != 0 {
		t.Fatalf("tiền đề hỏng: đơn này lẽ ra chưa có sổ, nhưng có %d dòng", n)
	}

	buckets := PerRateTaxBuckets(db, 1, session, "0000", "9999")
	if len(buckets) != 1 || buckets[0].Tax != 100 {
		t.Errorf("đơn chưa có sổ phải rơi về đường CỘT: muốn 1 nhóm thuế 100, có %+v", buckets)
	}
}

// #2736 — `paidOrderIDsSQL` là BẢN SAO lockstep của `paidPaymentsPredicate` bên
// handler, và docstring của chính nó nói hai chỗ phải đổi cùng lượt.
//
// Vòng 1 của #2736 sửa bản handler và bỏ quên bản này. Hậu quả KHÔNG phải "vẫn
// sai như cũ" — trước đó hai bên cùng sai nên còn KHỚP; sau khi sửa một bên,
// trên CÙNG một phiếu 精算 bảng 支払方法 tính payment space-form còn 消費税内訳
// loại đơn của nó, rồi độ lệch bị đóng băng vào `settlement_snapshot` và
// `lan_chain_report.go` in phần dư ra thành サービス料消費税 (#2155).
//
// Fixture: payment KHÔNG gán ca (`till_session_id` NULL) — nhánh attribution
// che mọi hàng đã gán, seed sai là bài test xanh oan.
func TestPerRateTaxBuckets_SpaceFormattedUnattributedPaymentIsInTheWindow(t *testing.T) {
	db := newPromoTestDB(t)
	e := NewOrderEngine(db)
	session := uuid.NewString()

	orderID := condTestOrder(t, db, 0)
	condTestItem(t, db, orderID, 1, 1000, 10)
	if err := e.RecalcOrderTotals(orderID); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	// NULL-attributed, in-window, stored with a SPACE separator.
	if _, err := db.Exec(`
		INSERT INTO payments (id, order_id, amount, payment_method, status, till_session_id, created_at, updated_at)
		VALUES (?, ?, 1100, 'cash', 'confirmed', NULL, '2026-07-06 09:00:00', '2026-07-06 09:00:00')`,
		uuid.NewString(), orderID); err != nil {
		t.Fatalf("insert space-form payment: %v", err)
	}

	// Window 08:00→10:00 in normalized (T) form — the shape callers pass.
	buckets := PerRateTaxBuckets(db, 1, session, "2026-07-06T08:00:00", "2026-07-06T10:00:00")
	if len(buckets) == 0 {
		t.Fatalf("消費税内訳 rỗng — đơn của payment space-form bị loại khỏi cửa sổ; " +
			"đây chính là lệch nội bộ với 支払方法 mà #2736 phải chặn")
	}

	// Control: cùng payment đó nhưng cửa sổ NGOÀI khoảng — phải vắng, nếu không
	// thì một "bản sửa" bỏ luôn bộ lọc cũng xanh.
	out := PerRateTaxBuckets(db, 1, session, "2026-07-06T11:00:00", "2026-07-06T12:00:00")
	if len(out) != 0 {
		t.Fatalf("cửa sổ 11:00→12:00 không được chứa payment 09:00, có %+v", out)
	}
}
