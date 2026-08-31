package service

import (
	"context"
	"fmt"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// #2087 — Cloud tính lại và ghi đè số tiền của máy trạm. "Cloud thắng" là quyết
// định ĐÚNG (Cloud là sổ kế toán); vấn đề là nó thắng trong IM LẶNG.
//
// Cảnh báo cũ gác sau hai điều kiện, mỗi cái tự nó đủ nuốt phần lớn ca thật:
// `paidSum > 0` (đơn chưa thu tiền ⇒ im) và chỉ so `total_amount` (cơ cấu đổi
// mà tổng giữ nguyên ⇒ im).

// capturedLogs thay logger mặc định trong phạm vi một test và trả về bộ đếm.
type capturedLogs struct {
	records []slog.Record
}

func (c *capturedLogs) Enabled(context.Context, slog.Level) bool { return true }
func (c *capturedLogs) WithAttrs([]slog.Attr) slog.Handler       { return c }
func (c *capturedLogs) WithGroup(string) slog.Handler            { return c }

func (c *capturedLogs) Handle(_ context.Context, r slog.Record) error {
	c.records = append(c.records, r)

	return nil
}

// at trả bản ghi đầu tiên có thông điệp chứa `needle`, kèm các thuộc tính của nó.
func (c *capturedLogs) at(needle string) (slog.Record, map[string]string, bool) {
	for _, r := range c.records {
		if !strings.Contains(r.Message, needle) {
			continue
		}
		attrs := map[string]string{}
		r.Attrs(func(a slog.Attr) bool {
			attrs[a.Key] = a.Value.String()

			return true
		})

		return r, attrs, true
	}

	return slog.Record{}, nil, false
}

func captureLogs(t *testing.T) *capturedLogs {
	t.Helper()

	cap := &capturedLogs{}
	prev := slog.Default()
	slog.SetDefault(slog.New(cap))
	t.Cleanup(func() { slog.SetDefault(prev) })

	return cap
}

func seedMoneyOrder(t *testing.T, e *SyncEngine, paid int) {
	t.Helper()

	if _, err := e.db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status, opened_at,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, cloud_id, created_at, updated_at
	) VALUES ('o1', 'ORD-1', 1, 'spot', 'open', datetime('now'),
		1000, 0, 0, 100, 0, 1100, 0, '','','', 'cloud-o1', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	if paid <= 0 {
		return
	}

	if _, err := e.db.Exec(`INSERT INTO payments (id, order_id, amount, status, payment_method, created_at, updated_at)
		VALUES ('p1', 'o1', ?, 'confirmed', 'cash', datetime('now'), datetime('now'))`, paid); err != nil {
		t.Fatalf("seed payment: %v", err)
	}
}

func TestCloudMoneyOverwrite_UnpaidOrderIsNoLongerSilent(t *testing.T) {
	// Đây là ca mà bản cũ BỎ QUA HOÀN TOÀN, và nó là trạng thái của gần như mọi
	// đơn lúc `order.item_add` chạy: món thêm trước, tiền thu sau.
	e, _ := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 0)
	logs := captureLogs(t)

	// Cloud giữ nguyên TỔNG nhưng đổi cơ cấu: thuế 100 → 91, giảm giá 0 → 9.
	// Bản cũ im hai lần: chưa thu tiền, và `total_amount` không đổi.
	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1100.00",
		"subtotal":        "1000.00",
		"tax_amount":      "91.00",
		"service_charge":  "0.00",
		"discount_amount": "9.00",
	})

	rec, attrs, ok := logs.at("cloud recompute changed this order's money")
	if !ok {
		t.Fatal("Cloud ghi đè thuế + giảm giá của một đơn chưa thu tiền mà KHÔNG có cảnh báo nào")
	}
	if rec.Level != slog.LevelWarn {
		t.Errorf("mức = %v, muốn WARN — chưa thu tiền thì mới là hoá đơn sắp in sai, chưa phải 過不足", rec.Level)
	}
	if attrs["fields_changed"] != "2" {
		t.Errorf("fields_changed = %q, muốn 2 (tax_amount + discount_amount)", attrs["fields_changed"])
	}
	// Thông điệp phải mang ĐỦ ba số để đối soát được mà không cần mở DB.
	for _, k := range []string{"tax_amount_local", "tax_amount_cloud", "tax_amount_delta"} {
		if _, has := attrs[k]; !has {
			t.Errorf("thiếu %q — cảnh báo không đối soát được thì chỉ là tiếng ồn", k)
		}
	}
	if attrs["tax_amount_delta"] != "-9" {
		t.Errorf("tax_amount_delta = %q, muốn -9", attrs["tax_amount_delta"])
	}
}

func TestCloudMoneyOverwrite_PaidOrderStillScreams(t *testing.T) {
	// Mặt kia: tiền đã vào két thì khoảng lệch là 過不足 THẬT. `paidSum` thôi làm
	// điều kiện nổ, nhưng vẫn phải nâng mức — cùng sự kiện, hai mức khẩn cấp.
	e, _ := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 1100)
	logs := captureLogs(t)

	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1101.00",
		"subtotal":        "1000.00",
		"tax_amount":      "101.00",
		"service_charge":  "0.00",
		"discount_amount": "0.00",
	})

	rec, attrs, ok := logs.at("LOCALLY-PAID")
	if !ok {
		t.Fatal("đơn ĐÃ THU TIỀN bị Cloud đổi tổng mà không có cảnh báo mức cao")
	}
	if rec.Level != slog.LevelError {
		t.Errorf("mức = %v, muốn ERROR", rec.Level)
	}
	if attrs["paid_locally"] != "1100" {
		t.Errorf("paid_locally = %q, muốn 1100 — người đối soát cần biết đã thu bao nhiêu", attrs["paid_locally"])
	}
}

func TestCloudMoneyOverwrite_SilentWhenNothingChanged(t *testing.T) {
	// Vế cần thiết: nếu thiếu, một hàm luôn-luôn-kêu cũng qua được hai test trên,
	// và tiếng ồn ở mọi lượt reconcile sẽ dạy người ta bỏ qua đúng cảnh báo này.
	e, _ := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 1100)
	logs := captureLogs(t)

	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1100.00",
		"subtotal":        "1000.00",
		"tax_amount":      "100.00",
		"service_charge":  "0.00",
		"discount_amount": "0.00",
	})

	if _, _, ok := logs.at("cloud recompute changed"); ok {
		t.Error("Cloud trả về ĐÚNG số của máy trạm mà vẫn kêu")
	}
}

func TestOrderApplyCoupon_AdoptsCloudMoney(t *testing.T) {
	// Vòng 2 của #2087. Bản đầu THÊM `reconcileOrderFromCloud` vào đường coupon
	// nhưng không ghim nó — review chạy chiều ngược và cả gói vẫn `ok` khi gỡ
	// dòng ấy đi.
	//
	// Đáng ghim vì chính lập luận của bản sửa: đường coupon là đường LỆCH NHIỀU
	// NHẤT (#2083 — 15% trên ¥1.005 ra -150 ở máy trạm, -151 ở Cloud). Một dòng
	// không ai canh, nằm trên đường lệch nhiều nhất, đúng là thứ sẽ trôi mất —
	// cùng loại trôi mà PR này sinh ra để chặn.
	var hit string
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hit = r.URL.Path
		// Cloud tính lại: giảm giá 151 (không phải 150), thuế + tổng theo đó.
		w.Write([]byte(`{"data":{
			"total_amount":"854.00","subtotal":"1005.00","tax_amount":"0.00",
			"service_charge":"0.00","discount_amount":"151.00"
		}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	if _, err := db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status, opened_at,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, cloud_id, created_at, updated_at
	) VALUES ('o1', 'ORD-1', 1, 'spot', 'open', datetime('now'),
		1005, 150, 0, 0, 0, 855, 0, '','','', 'cloud-o1', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	if _, _, err := e.handleOrderApplyCoupon(context.Background(), "o1", map[string]any{
		"coupon_code":  "SALE15",
		"bearer_token": "WS-TOKEN",
	}); err != nil {
		t.Fatalf("handleOrderApplyCoupon: %v", err)
	}

	if hit == "" {
		t.Fatal("không gọi Cloud — test đang đo nhầm thứ khác")
	}

	var discount, total int
	if err := db.QueryRow(`SELECT discount_amount, total_amount FROM orders WHERE id='o1'`).
		Scan(&discount, &total); err != nil {
		t.Fatal(err)
	}

	// Không reconcile ⇒ hai số này giữ nguyên 150/855 tới tận lượt pull kế tiếp,
	// và tờ giấy in ra ở quầy mang con số máy trạm tự tính.
	if discount != 151 {
		t.Errorf("discount_amount = %d, muốn 151 — đường coupon KHÔNG adopt số của Cloud", discount)
	}
	if total != 854 {
		t.Errorf("total_amount = %d, muốn 854", total)
	}
}

// ---------------------------------------------------------------------------
// #2127 A — cảnh báo phải vào ALERT STORE, không chỉ `slog`.
//
// Ba hệ quả của việc chỉ có log, và cả ba đều không đo được bằng các test ở
// trên: không lên được màn hình quản lý, biến mất khi log xoay vòng, không có
// đường đẩy lên HQ. Nên phần dưới đo bảng `alerts`, không đo log.
// ---------------------------------------------------------------------------

// wireAlerts nối một alert centre THẬT (SQLite của chính engine) vào engine.
//
// Không mock store: thứ đang được kiểm là "alert có tồn tại sau khi Cloud ghi
// đè không", và một mock chỉ chứng minh rằng `Raise` được gọi — nó không nói gì
// về việc dòng ấy có sống qua khoá gộp `(kind, subject)` hay không, mà đó chính
// là hành vi khiến `subject` được chọn là order id.
func wireAlerts(t *testing.T, e *SyncEngine) *AlertEmitter {
	t.Helper()

	em := NewAlertEmitter(NewAlertStore(e.db), slog.Default())
	e.SetAlerts(em)

	return em
}

func openAlertFor(t *testing.T, em *AlertEmitter, kind AlertKind, subject string) (Alert, bool) {
	t.Helper()

	open, err := em.ListOpen()
	if err != nil {
		t.Fatalf("ListOpen: %v", err)
	}
	for _, a := range open {
		if a.Kind == kind && a.Subject == subject {
			return a, true
		}
	}

	return Alert{}, false
}

func TestCloudMoneyOverwrite_RaisesAlertNotJustLog(t *testing.T) {
	e, _ := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 0)
	em := wireAlerts(t, e)

	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1100.00",
		"subtotal":        "1000.00",
		"tax_amount":      "91.00",
		"service_charge":  "0.00",
		"discount_amount": "9.00",
	})

	a, ok := openAlertFor(t, em, KindCloudMoneyOverwrite, "o1")
	if !ok {
		t.Fatal("Cloud ghi đè tiền mà KHÔNG có alert nào — nó vẫn chỉ là một dòng log sẽ bị xoay vòng")
	}

	// `subject` phải là order id: khoá gộp của bảng là `(kind, subject)`, nên
	// một subject cố định kiểu "sync" sẽ trộn mọi đơn vào một dòng và người đối
	// soát không biết phải mở đơn nào.
	if a.Subject != "o1" {
		t.Errorf("subject = %q, muốn order id", a.Subject)
	}

	// Chính sách lấy từ registry, không lấy từ chỗ gọi — cùng một điều kiện
	// không được phép là `warning` ở file này và `critical` ở file khác.
	if a.Severity != SeverityWarning || a.Audience != AudienceShop {
		t.Errorf("severity/audience = %s/%s, muốn warning/shop", a.Severity, a.Audience)
	}

	// Alert phải đối soát được MÀ KHÔNG cần mở DB hay đọc log — nếu không, nó
	// chỉ báo rằng "có chuyện gì đó", và người đọc vẫn phải đi tìm ở chỗ khác.
	for _, k := range []string{"tax_amount_local", "tax_amount_cloud", "tax_amount_delta", "paid_locally"} {
		if _, has := a.Detail[k]; !has {
			t.Errorf("detail thiếu %q", k)
		}
	}
	if got := a.Detail["tax_amount_delta"]; fmt.Sprint(got) != "-9" {
		t.Errorf("tax_amount_delta = %v, muốn -9", got)
	}
}

func TestCloudMoneyOverwrite_AlertRaisedOnPaidBranchToo(t *testing.T) {
	// Đây là vế giữ ĐÚNG CHỖ ĐẶT, không phải giữ hành vi: hàm này rẽ hai nhánh
	// theo `paidSum` để chọn MỨC LOG. Đặt `Raise` vào một nhánh thì nửa số ca
	// mất alert — đúng hình dạng lỗi #2087 (một điều kiện phụ nuốt ca thật), và
	// nửa bị mất lại là nửa NẶNG HƠN.
	e, _ := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 1100)
	em := wireAlerts(t, e)

	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1101.00",
		"subtotal":        "1000.00",
		"tax_amount":      "101.00",
		"service_charge":  "0.00",
		"discount_amount": "0.00",
	})

	a, ok := openAlertFor(t, em, KindCloudMoneyOverwrite, "o1")
	if !ok {
		t.Fatal("đơn ĐÃ THU bị ghi đè mà không có alert — nhánh nặng hơn lại là nhánh mất cảnh báo")
	}
	if fmt.Sprint(a.Detail["paid_locally"]) != "1100" {
		t.Errorf("paid_locally = %v, muốn 1100", a.Detail["paid_locally"])
	}
}

func TestCloudMoneyOverwrite_SameOrderGroupsIntoOneAlert(t *testing.T) {
	// `order.item_add` chạy lại mỗi lần thêm món, nên cùng một đơn có thể bị ghi
	// đè nhiều lần trong một bữa. Không gộp thì màn hình quản lý đầy dòng trùng
	// và người ta tắt nó đi — chính cái mà `alertRegistry` được viết ra để tránh.
	e, _ := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 0)
	em := wireAlerts(t, e)

	// Cả hai lần lệch VƯỢT deadband ±1 (#2167): 100→91 rồi 91→94. Lần hai mà
	// chỉ nhích 1 thì bị deadband nuốt đúng thiết kế — nhưng bài này đo phép
	// GỘP, nên cả hai lần phải là lệch thật.
	for _, tax := range []string{"91.00", "94.00"} {
		e.reconcileOrderFromCloud("o1", map[string]any{
			"total_amount":    "1100.00",
			"subtotal":        "1000.00",
			"tax_amount":      tax,
			"service_charge":  "0.00",
			"discount_amount": "9.00",
		})
	}

	open, err := em.ListOpen()
	if err != nil {
		t.Fatalf("ListOpen: %v", err)
	}
	n := 0
	for _, a := range open {
		if a.Kind == KindCloudMoneyOverwrite {
			n++
			if a.Count < 2 {
				t.Errorf("count = %d, muốn ≥2 — gộp phải ĐẾM, không phải nuốt lần sau", a.Count)
			}
		}
	}
	if n != 1 {
		t.Errorf("có %d alert cho cùng một đơn, muốn 1 (gộp theo (kind, subject))", n)
	}
}

func TestCloudMoneyOverwrite_NoAlertWhenNothingChanged(t *testing.T) {
	// Vế cần thiết, cùng lý do như bản log: thiếu nó thì một hàm luôn-luôn-Raise
	// vẫn qua được ba test trên, và mọi lượt reconcile sẽ dựng một alert.
	e, _ := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 1100)
	em := wireAlerts(t, e)

	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1100.00",
		"subtotal":        "1000.00",
		"tax_amount":      "100.00",
		"service_charge":  "0.00",
		"discount_amount": "0.00",
	})

	if _, ok := openAlertFor(t, em, KindCloudMoneyOverwrite, "o1"); ok {
		t.Error("Cloud trả về ĐÚNG số của máy trạm mà vẫn dựng alert")
	}
}

func TestCloudMoneyOverwrite_UnwiredEngineDoesNotPanic(t *testing.T) {
	// Nhiều test và `cmd/` dựng engine mà không nối alert centre. Một alert
	// KHÔNG được phép là lý do làm chết đường sync tiền — cùng hợp đồng nil-safe
	// mà `AlertEmitter` đã tự giữ, nhưng phải được ghim từ phía engine, vì đây
	// là chỗ quyết định có kiểm nil trước khi gọi hay không.
	defer func() {
		if r := recover(); r != nil {
			t.Fatalf("engine chưa nối alerts gây panic: %v", r)
		}
	}()

	e, _ := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 0)

	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1200.00",
		"subtotal":        "1000.00",
		"tax_amount":      "200.00",
		"service_charge":  "0.00",
		"discount_amount": "0.00",
	})
}

// #2167 — lệch ±1 đơn vị tiền là làm tròn ĐÃ BIẾT (#2083: coupon 15% trên
// ¥1.005 ra -150/-151). 60 alert/ngày cho nó là cách panel chết. Vẫn log đủ số,
// chỉ không dựng alert.
func TestCloudMoneyOverwrite_RoundingDeadbandLogsButDoesNotAlert(t *testing.T) {
	e, _ := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 0)
	em := wireAlerts(t, e)
	logs := captureLogs(t)

	// Mọi field lệch đúng 1: discount -150 kiểu máy trạm vs -151 kiểu Cloud.
	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1099.00",
		"subtotal":        "1000.00",
		"tax_amount":      "100.00",
		"service_charge":  "0.00",
		"discount_amount": "1.00",
	})

	if _, ok := openAlertFor(t, em, KindCloudMoneyOverwrite, "o1"); ok {
		t.Fatal("lệch ±1 do làm tròn vẫn dựng alert — deadband #2167 không chạy")
	}
	rec, attrs, ok := logs.at("within the rounding deadband")
	if !ok {
		t.Fatal("deadband nuốt luôn cả LOG — người điều tra mất vết")
	}
	if rec.Level != slog.LevelInfo {
		t.Errorf("mức = %v, muốn INFO", rec.Level)
	}
	if attrs["total_amount_delta"] != "-1" {
		t.Errorf("total_amount_delta = %q, muốn -1 — log deadband phải mang đủ số", attrs["total_amount_delta"])
	}
}

// Deadband theo TỪNG field, không theo tổng đại số: tax +2 và discount -1 cho
// net +1, nhưng lệch 2 ở một field là lệch thật — phải alert.
func TestCloudMoneyOverwrite_DeadbandIsPerFieldNotNetSum(t *testing.T) {
	e, _ := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 0)
	em := wireAlerts(t, e)

	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1101.00", // +1
		"subtotal":        "1000.00",
		"tax_amount":      "102.00", // +2 — vượt deadband
		"service_charge":  "0.00",
		"discount_amount": "1.00", // +1
	})

	if _, ok := openAlertFor(t, em, KindCloudMoneyOverwrite, "o1"); !ok {
		t.Fatal("field lệch 2 mà không alert — deadband đang cộng trừ bù nhau thay vì lấy max theo field")
	}
}
