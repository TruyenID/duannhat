package service

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// newAlertPushEngine dựng một engine ĐÃ nối alert centre trên CÙNG một DB, và
// đã ghép cặp (có device_token) — ba điều kiện của đường đẩy thật.
func newAlertPushEngine(t *testing.T, cloudURL string) (*SyncEngine, *AlertEmitter, *store.DB) {
	t.Helper()

	e, db := newSyncTestEngine(t, cloudURL)
	// Template DB đã có sẵn hàng `device_token` rỗng — upsert chứ không insert.
	if _, err := db.Exec(`INSERT INTO settings (key, value) VALUES ('device_token', 'dev-token-2695')
		ON CONFLICT (key) DO UPDATE SET value = excluded.value`); err != nil {
		t.Fatalf("seed device token: %v", err)
	}
	emitter := NewAlertEmitter(NewAlertStore(db), nil)
	e.SetAlerts(emitter)

	return e, emitter, db
}

// alertPushRecorder đếm request tới ĐÚNG endpoint alert và giữ lại body.
type alertPushRecorder struct {
	calls int32
	other int32
	body  atomic.Value // map[string]any
}

func newAlertCloud(t *testing.T, status int) (*httptest.Server, *alertPushRecorder) {
	t.Helper()

	rec := &alertPushRecorder{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/alerts" {
			atomic.AddInt32(&rec.other, 1)
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"data":{"id":"cloud-1"}}`))

			return
		}

		atomic.AddInt32(&rec.calls, 1)
		raw, _ := io.ReadAll(r.Body)
		var parsed map[string]any
		_ = json.Unmarshal(raw, &parsed)
		rec.body.Store(parsed)

		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		if status < 300 {
			_, _ = w.Write([]byte(`{"data":{"accepted":1}}`))
		} else {
			_, _ = w.Write([]byte(`{"message":"boom"}`))
		}
	}))
	t.Cleanup(srv.Close)

	return srv, rec
}

// PHẢI GỬI — một kind thuộc allowlist sync-UP sinh ra ĐÚNG MỘT POST, và body
// mang kind + phần thân của alert.
//
// Đây là vế mà production thiếu: cờ `SyncUp: true` đã khai từ #1806 S3 nhưng
// không dòng Go nào gọi endpoint, nên `notifications` có 0 hàng
// `workstation.alert` trên 363 thông báo (#2694 lỗ 1).
func TestAlertPushUp_AllowlistedKindReachesCloud(t *testing.T) {
	cloud, rec := newAlertCloud(t, http.StatusAccepted)
	e, emitter, _ := newAlertPushEngine(t, cloud.URL)

	emitter.Raise(KindCashRetained, "glory-txn-77", "Tiền còn kẹt trong máy",
		map[string]any{"amount": 1000})

	e.PushAlertsUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 1 {
		t.Fatalf("POST tới /workstation/alerts = %d, muốn đúng 1", got)
	}

	body, _ := rec.body.Load().(map[string]any)
	list, ok := body["alerts"].([]any)
	if !ok || len(list) != 1 {
		t.Fatalf("body.alerts = %#v, muốn đúng 1 phần tử", body["alerts"])
	}
	item, _ := list[0].(map[string]any)
	if item["kind"] != string(KindCashRetained) {
		t.Errorf("kind = %v, muốn %s", item["kind"], KindCashRetained)
	}
	if item["subject"] != "glory-txn-77" {
		t.Errorf("subject = %v, muốn glory-txn-77", item["subject"])
	}
	if item["severity"] != string(SeverityCritical) {
		t.Errorf("severity = %v, muốn critical", item["severity"])
	}
	if item["title"] != "Tiền còn kẹt trong máy" {
		t.Errorf("title = %v", item["title"])
	}
	if item["count"] == nil || item["first_seen_at"] == nil {
		t.Errorf("thiếu count/first_seen_at: %#v", item)
	}

	stats := e.AlertPushStats()
	if stats.OK != 1 || stats.Failed != 0 || stats.AlertsSent != 1 {
		t.Errorf("stats = %+v, muốn ok=1 failed=0 alerts_sent=1", stats)
	}
}

// PHẢI IM — Cloud CỐ Ý không lọc lần hai, nên allowlist phía máy trạm là cổng
// duy nhất. Một kind khai `SyncUp: false` (LAN client rớt: chuyện của quán,
// người ở HQ không cầm được sợi dây mạng đó) mà lọt qua đây là rò thẳng vào
// nền tảng thông báo.
func TestAlertPushUp_NonAllowlistedKindNeverLeavesTheShop(t *testing.T) {
	cloud, rec := newAlertCloud(t, http.StatusAccepted)
	e, emitter, _ := newAlertPushEngine(t, cloud.URL)

	// Ba kind ĐÃ ĐĂNG KÝ (dựng được, hiện trên panel tại chỗ) nhưng KHÔNG
	// thuộc allowlist sync-UP.
	emitter.Raise(KindRealtimeClientDropped, "ws_client", "Client LAN bị ngắt", nil)
	emitter.Raise(KindStaleBuild, "build", "Bản cũ hơn HQ mong đợi", nil)
	emitter.Raise(KindNotPaired, "pairing", "Chưa ghép cặp", nil)

	e.PushAlertsUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 0 {
		t.Fatalf("POST tới /workstation/alerts = %d, muốn 0 — allowlist bị rò", got)
	}

	stats := e.AlertPushStats()
	if stats.OK != 0 || stats.Failed != 0 || stats.Skipped != 1 {
		t.Errorf("stats = %+v, muốn ok=0 failed=0 skipped=1", stats)
	}

	// Và chúng vẫn còn nguyên tại chỗ — không đẩy lên KHÔNG có nghĩa là bỏ.
	open, err := emitter.ListOpen()
	if err != nil || len(open) != 3 {
		t.Errorf("alert tại chỗ = %d (err=%v), muốn 3", len(open), err)
	}
}

// Một alert thuộc allowlist đứng CẠNH ba alert không thuộc: chỉ cái đầu đi.
func TestAlertPushUp_MixedBatchCarriesOnlyAllowlistedKinds(t *testing.T) {
	cloud, rec := newAlertCloud(t, http.StatusAccepted)
	e, emitter, _ := newAlertPushEngine(t, cloud.URL)

	emitter.Raise(KindRealtimeClientDropped, "ws_client", "Client LAN bị ngắt", nil)
	emitter.Raise(KindNoPrinter, "receipt_printer", "Chưa gán máy in hoá đơn", nil)
	emitter.Raise(KindStaleBuild, "build", "Bản cũ hơn HQ mong đợi", nil)

	e.PushAlertsUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 1 {
		t.Fatalf("POST = %d, muốn 1", got)
	}
	body, _ := rec.body.Load().(map[string]any)
	list, _ := body["alerts"].([]any)
	if len(list) != 1 {
		t.Fatalf("lô mang %d alert, muốn đúng 1 (chỉ no_printer)", len(list))
	}
	item, _ := list[0].(map[string]any)
	if item["kind"] != string(KindNoPrinter) {
		t.Fatalf("kind = %v, muốn %s", item["kind"], KindNoPrinter)
	}
}

// FAIL-OPEN — Cloud trả 5xx: vòng đồng bộ vẫn chạy tiếp (hàng đợi vẫn được
// drain), không panic, không lỗi nào trồi lên caller; nhưng bộ đếm thất bại
// PHẢI tăng. Bộ đếm là thứ duy nhất phân biệt "gọi mà hỏng" với "chưa gọi" —
// endpoint fail-open làm hai trạng thái đó trông y hệt nhau từ ngoài.
func TestAlertPushUp_CloudFailureIsFailOpenAndCounted(t *testing.T) {
	cloud, rec := newAlertCloud(t, http.StatusInternalServerError)
	e, emitter, db := newAlertPushEngine(t, cloud.URL)

	emitter.Raise(KindCashRetained, "glory-txn-88", "Tiền còn kẹt trong máy", nil)

	// Một hàng đợi thật đứng SAU lượt đẩy alert trong cùng một nhịp tick.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key)
		VALUES ('local-pay-2695', 'order-2695', 'cash', 1000, 'pending', 'idem-2695')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}
	if err := e.Enqueue("payment", "local-pay-2695", "create", map[string]any{
		"bearer_token":    "tok",
		"idempotency_key": "idem-2695",
		"order_id":        "order-2695",
		"payment_method":  "cash",
		"amount":          1000,
	}, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	// MỘT nhịp đồng bộ đầy đủ — không panic, không trả lỗi (tick không có giá
	// trị trả về: fail-open là hợp đồng của đường này).
	e.tick()

	if got := atomic.LoadInt32(&rec.calls); got != 1 {
		t.Fatalf("POST alert = %d, muốn 1", got)
	}

	stats := e.AlertPushStats()
	if stats.Failed != 1 {
		t.Errorf("stats.Failed = %d, muốn 1 — thất bại phải ĐẾM được", stats.Failed)
	}
	if stats.OK != 0 {
		t.Errorf("stats.OK = %d, muốn 0", stats.OK)
	}
	if stats.LastError == "" {
		t.Error("stats.LastError rỗng — không nói được vì sao hỏng")
	}

	// Và nó KHÔNG được để lại tác dụng phụ lên đường chính: `cloudPost` gọi
	// `noteThrottle` trên mọi 5xx, mà cooldown đó gác NGUYÊN vòng drain.
	if e.inCooldown() {
		t.Error("một 5xx từ endpoint ALERT đã bật cooldown toàn cục — nó sẽ chặn đường đẩy TIỀN")
	}

	// Vòng đồng bộ ĐI TIẾP: hàng đợi vẫn được drain sau lượt đẩy hỏng.
	var cloudID string
	_ = db.QueryRow(`SELECT COALESCE(cloud_id, '') FROM payments WHERE id = 'local-pay-2695'`).Scan(&cloudID)
	if cloudID == "" {
		t.Fatalf("hàng đợi KHÔNG được drain sau khi đẩy alert hỏng — fail-open đã vỡ")
	}
	if atomic.LoadInt32(&rec.other) == 0 {
		t.Error("không có request nào khác tới Cloud — vòng sync dừng ở lượt đẩy alert")
	}
}

// Timeout trông khác 5xx trên dây nhưng phải cho cùng một kết quả: đếm, không
// ném, không treo vòng sync.
func TestAlertPushUp_TimeoutIsCountedNotPropagated(t *testing.T) {
	hang := make(chan struct{})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		<-hang
	}))
	defer srv.Close()
	defer close(hang)

	e, emitter, _ := newAlertPushEngine(t, srv.URL)
	emitter.Raise(KindCloudMoneyOverwrite, "order-9", "Cloud ghi đè số tiền", nil)

	ctx, cancel := context.WithTimeout(context.Background(), 150*time.Millisecond)
	defer cancel()

	done := make(chan struct{})
	go func() {
		defer close(done)
		e.PushAlertsUp(ctx)
	}()

	select {
	case <-done:
	case <-time.After(10 * time.Second):
		t.Fatal("PushAlertsUp treo quá ctx timeout")
	}

	if stats := e.AlertPushStats(); stats.Failed != 1 || stats.OK != 0 {
		t.Errorf("stats = %+v, muốn failed=1 ok=0", stats)
	}
}

// Ba trạng thái phải phân biệt được, không phải hai. Đây là chính cái mà #2695
// tồn tại để sửa: một endpoint fail-open làm "chưa bao giờ gọi" và "gọi mà
// hỏng" trông giống hệt nhau, và sự giống nhau đó giấu đường đứt nhiều tháng.
func TestAlertPushStats_TellsNeverCalledApartFromFailed(t *testing.T) {
	cloud, _ := newAlertCloud(t, http.StatusInternalServerError)
	e, emitter, _ := newAlertPushEngine(t, cloud.URL)

	// 1. CHƯA GỌI — engine vừa dựng.
	if stats := e.AlertPushStats(); stats.OK != 0 || stats.Failed != 0 || stats.Skipped != 0 {
		t.Fatalf("engine mới: stats = %+v, muốn tất cả 0", stats)
	}

	// 2. CÓ CHẠY, KHÔNG CÓ GÌ ĐỂ GỬI — khác hẳn "chưa gọi".
	e.PushAlertsUp(context.Background())
	stats := e.AlertPushStats()
	if stats.Skipped != 1 || stats.OK != 0 || stats.Failed != 0 {
		t.Fatalf("vòng rỗng: stats = %+v, muốn skipped=1", stats)
	}
	if stats.LastSkipReason == "" {
		t.Error("LastSkipReason rỗng — không phân biệt được 'không có alert' với 'chưa ghép cặp'")
	}

	// 3. GỌI MÀ HỎNG.
	emitter.Raise(KindAutoUpdateRollback, "update", "Bản tự cài đã quay lui", nil)
	e.PushAlertsUp(context.Background())
	if stats := e.AlertPushStats(); stats.Failed != 1 || stats.OK != 0 {
		t.Fatalf("vòng hỏng: stats = %+v, muốn failed=1", stats)
	}
}

// Chưa ghép cặp thì KHÔNG POST — mọi request sẽ 401, và đếm nó là `failed` sẽ
// biến một máy đang chờ ghép cặp thành một báo động đường truyền giả.
func TestAlertPushUp_UnpairedDeviceSkipsInsteadOfFailing(t *testing.T) {
	cloud, rec := newAlertCloud(t, http.StatusAccepted)
	e, db := newSyncTestEngine(t, cloud.URL)
	emitter := NewAlertEmitter(NewAlertStore(db), nil)
	e.SetAlerts(emitter)

	emitter.Raise(KindCashRetained, "glory-txn-99", "Tiền còn kẹt trong máy", nil)

	e.PushAlertsUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 0 {
		t.Fatalf("POST = %d, muốn 0 khi chưa có device token", got)
	}
	if stats := e.AlertPushStats(); stats.Skipped != 1 || stats.Failed != 0 {
		t.Errorf("stats = %+v, muốn skipped=1 failed=0", stats)
	}
}

// Subject rỗng là có thật (`cash_retained` dựng từ lượt thu Glory hỏng trước
// khi có mã giao dịch). Cloud validate `required` trên TỪNG phần tử và một
// phần tử hỏng làm CẢ LÔ 422 — nên chuẩn hoá tại đây, không phó mặc validator.
func TestAlertPushUp_EmptySubjectStillPassesCloudValidation(t *testing.T) {
	cloud, rec := newAlertCloud(t, http.StatusAccepted)
	e, emitter, _ := newAlertPushEngine(t, cloud.URL)

	emitter.Raise(KindCashRetained, "", "Tiền còn kẹt trong máy", nil)

	e.PushAlertsUp(context.Background())

	body, _ := rec.body.Load().(map[string]any)
	list, _ := body["alerts"].([]any)
	if len(list) != 1 {
		t.Fatalf("lô mang %d alert, muốn 1", len(list))
	}
	item, _ := list[0].(map[string]any)
	if item["subject"] == "" || item["subject"] == nil {
		t.Fatalf("subject rỗng đi lên dây — Cloud sẽ 422 cả lô: %#v", item)
	}
}

// Nhịp: vòng tick không được đẩy mỗi 5 giây. Cloud khử trùng theo NGÀY nghiệp
// vụ, nên đẩy dày hơn không tạo thêm thông báo nào — chỉ đốt ngân sách request
// của thiết bị.
func TestAlertPushUp_TickThrottlesToOnePushPerInterval(t *testing.T) {
	cloud, rec := newAlertCloud(t, http.StatusAccepted)
	e, emitter, _ := newAlertPushEngine(t, cloud.URL)

	emitter.Raise(KindNoPrinter, "receipt_printer", "Chưa gán máy in hoá đơn", nil)

	e.maybePushAlertsUp()
	e.maybePushAlertsUp()
	e.maybePushAlertsUp()

	if got := atomic.LoadInt32(&rec.calls); got != 1 {
		t.Fatalf("POST = %d, muốn 1 — ba nhịp liên tiếp phải bị tiết chế", got)
	}
}
