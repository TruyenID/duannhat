package service

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// #2885 — đẩy bằng chứng lệch tiền (`order_money_overwrites`) lên Cloud.
//
// Đây là bằng chứng đối soát TIỀN trên sản phẩm đã release, chạy ở quán thật,
// nên bộ bài dưới đây đo theo thứ tự sức nặng của cái có thể mất:
//
//  1. một lượt đẩy hỏng KHÔNG được chạm backpressure dùng chung — bài học
//     #2695: một endpoint phụ hỏng từng chặn đường đẩy TIỀN tới 5 phút;
//  2. chỉ đánh dấu `synced_at` khi CHẮC CHẮN Cloud đã nhận (2xx) — thà gửi
//     trùng (Cloud khử trùng theo `(device_id, local_id)`) còn hơn mất một
//     dòng bằng chứng, vì bằng chứng mất thì không dựng lại được;
//  3. backend deploy TRƯỚC, nên 404 là trạng thái BÌNH THƯỜNG trong cửa sổ
//     giữa hai lần deploy — im lặng, thử lại, không dựng báo động giả;
//  4. đẩy MỘT lần rồi thôi — khác đường alert, vốn đẩy lại mọi alert đang mở
//     mỗi phút (4.721 dòng log/ngày trên production).

// ─── Khung dựng ──────────────────────────────────────────────────────────────

// newMoneyOverwritePushEngine dựng một engine ĐÃ ghép cặp (có device_token) —
// điều kiện của đường đẩy thật.
func newMoneyOverwritePushEngine(t *testing.T, cloudURL string) (*SyncEngine, *store.DB) {
	t.Helper()

	e, db := newSyncTestEngine(t, cloudURL)
	// Template DB đã có sẵn hàng `device_token` rỗng — upsert chứ không insert.
	if _, err := db.Exec(`INSERT INTO settings (key, value) VALUES ('device_token', 'dev-token-2885')
		ON CONFLICT (key) DO UPDATE SET value = excluded.value`); err != nil {
		t.Fatalf("seed device token: %v", err)
	}

	return e, db
}

// moneyOverwriteRecorder đếm request tới ĐÚNG endpoint bằng chứng và giữ lại
// mọi body đã nhận (không chỉ body cuối) — trần lô và lượt kế phải kiểm được
// từng lô một.
type moneyOverwriteRecorder struct {
	calls  int32
	other  int32
	status int32

	mu     chan struct{} // mutex đơn giản, tránh kéo thêm import
	bodies []map[string]any
}

func (r *moneyOverwriteRecorder) record(body map[string]any) {
	r.mu <- struct{}{}
	r.bodies = append(r.bodies, body)
	<-r.mu
}

func (r *moneyOverwriteRecorder) batches(t *testing.T) [][]any {
	t.Helper()

	r.mu <- struct{}{}
	defer func() { <-r.mu }()

	out := make([][]any, 0, len(r.bodies))
	for i, b := range r.bodies {
		list, ok := b["overwrites"].([]any)
		if !ok {
			t.Fatalf("lô %d: body.overwrites = %#v, muốn một mảng", i, b["overwrites"])
		}
		out = append(out, list)
	}

	return out
}

// setStatus đổi mã trả về giữa chừng — dùng cho các bài "lượt sau thử lại".
func (r *moneyOverwriteRecorder) setStatus(status int) {
	atomic.StoreInt32(&r.status, int32(status))
}

func newMoneyOverwriteCloud(t *testing.T, status int) (*httptest.Server, *moneyOverwriteRecorder) {
	t.Helper()

	rec := &moneyOverwriteRecorder{mu: make(chan struct{}, 1), status: int32(status)}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != moneyOverwritePushPath {
			atomic.AddInt32(&rec.other, 1)
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"data":{"id":"cloud-1"}}`))

			return
		}

		atomic.AddInt32(&rec.calls, 1)
		raw, _ := io.ReadAll(r.Body)
		var parsed map[string]any
		_ = json.Unmarshal(raw, &parsed)
		rec.record(parsed)

		code := int(atomic.LoadInt32(&rec.status))
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(code)
		switch {
		case code < 300:
			_, _ = w.Write([]byte(`{"accepted":1,"duplicates":0}`))
		case code == http.StatusNotFound:
			// Đúng thân Laravel trả khi route chưa tồn tại — cửa sổ giữa hai
			// lần deploy.
			_, _ = w.Write([]byte(`{"message":"The route api/v1/workstation/money-overwrites could not be found."}`))
		default:
			_, _ = w.Write([]byte(`{"message":"boom"}`))
		}
	}))
	t.Cleanup(srv.Close)

	return srv, rec
}

// overwriteSeed mô tả một dòng bằng chứng để chèn thẳng vào SQLite.
type overwriteSeed struct {
	orderID   string
	createdAt string
	paid      int

	totalLocal, totalCloud       int
	subtotalLocal, subtotalCloud int
	taxLocal, taxCloud           int
	serviceLocal, serviceCloud   int
	discountLocal, discountCloud int
}

func seedOverwrite(t *testing.T, db *store.DB, s overwriteSeed) int64 {
	t.Helper()

	res, err := db.Exec(`INSERT INTO order_money_overwrites (
		order_id,
		total_amount_local, total_amount_cloud,
		subtotal_local, subtotal_cloud,
		tax_amount_local, tax_amount_cloud,
		service_charge_local, service_charge_cloud,
		discount_amount_local, discount_amount_cloud,
		paid_locally, created_at
	) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		s.orderID,
		s.totalLocal, s.totalCloud,
		s.subtotalLocal, s.subtotalCloud,
		s.taxLocal, s.taxCloud,
		s.serviceLocal, s.serviceCloud,
		s.discountLocal, s.discountCloud,
		s.paid, s.createdAt)
	if err != nil {
		t.Fatalf("seed order_money_overwrites: %v", err)
	}
	id, err := res.LastInsertId()
	if err != nil {
		t.Fatalf("last insert id: %v", err)
	}

	return id
}

// pendingIDs trả các id CHƯA đánh dấu, theo thứ tự.
func pendingIDs(t *testing.T, db *store.DB) []int64 {
	t.Helper()

	rows, err := db.Query(`SELECT id FROM order_money_overwrites WHERE synced_at IS NULL ORDER BY id`)
	if err != nil {
		t.Fatalf("read pending: %v", err)
	}
	defer rows.Close()

	var out []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			t.Fatalf("scan: %v", err)
		}
		out = append(out, id)
	}

	return out
}

func syncedStamp(t *testing.T, db *store.DB, id int64) string {
	t.Helper()

	var stamp string
	if err := db.QueryRow(`SELECT COALESCE(synced_at, '') FROM order_money_overwrites WHERE id = ?`, id).
		Scan(&stamp); err != nil {
		t.Fatalf("read synced_at của id=%d: %v", id, err)
	}

	return stamp
}

// wireField đọc một trường số từ một phần tử payload đã giải mã.
func wireField(t *testing.T, item map[string]any, key string) float64 {
	t.Helper()

	raw, ok := item[key]
	if !ok {
		t.Fatalf("payload thiếu hẳn trường %q — hợp đồng #2885 bắt buộc cả 10 trường tiền: %#v", key, item)
	}
	n, ok := raw.(float64)
	if !ok {
		t.Fatalf("trường %q = %#v, muốn một SỐ NGUYÊN (đơn vị tiền nhỏ nhất)", key, raw)
	}

	return n
}

// ─── 1. Bất biến nặng nhất: hỏng KHÔNG được chạm backpressure dùng chung ─────

// #2695 đo được: `cloudPost` gọi `noteThrottle` trên mọi 5xx, `noteThrottle` đặt
// `cooldownUntil` TOÀN CỤC, và `processQueue` bỏ NGUYÊN vòng drain khi
// `inCooldown()`. Một endpoint phụ hỏng chặn đường đẩy TIỀN tới 5 phút. Bài này
// khẳng định THẲNG lên trạng thái ấy, không suy ra từ hành vi.
func TestMoneyOverwritePushUp_FailureNeverTouchesSharedBackpressure(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusInternalServerError)
	e, db := newMoneyOverwritePushEngine(t, cloud.URL)

	seedOverwrite(t, db, overwriteSeed{
		orderID: "order-2885", createdAt: "2026-08-13T07:01:33Z", paid: 297,
		totalLocal: 1190, totalCloud: 297, subtotalLocal: 1190, subtotalCloud: 297,
	})

	// Ảnh chụp trạng thái backpressure TRƯỚC lượt đẩy.
	e.rlMu.Lock()
	beforeCooldown := e.cooldownUntil
	beforeThrottles := e.consecutiveThrottles
	e.rlMu.Unlock()

	// Một hàng đợi TIỀN thật đứng SAU lượt đẩy bằng chứng trong cùng nhịp tick.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key)
		VALUES ('local-pay-2885', 'order-2885', 'cash', 1000, 'pending', 'idem-2885')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}
	if err := e.Enqueue("payment", "local-pay-2885", "create", map[string]any{
		"bearer_token":    "tok",
		"idempotency_key": "idem-2885",
		"order_id":        "order-2885",
		"payment_method":  "cash",
		"amount":          1000,
	}, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	// MỘT nhịp đồng bộ đầy đủ — fail-open là hợp đồng, tick không trả lỗi.
	e.tick()

	if got := atomic.LoadInt32(&rec.calls); got != 1 {
		t.Fatalf("POST tới %s = %d, muốn 1", moneyOverwritePushPath, got)
	}

	e.rlMu.Lock()
	afterCooldown := e.cooldownUntil
	afterThrottles := e.consecutiveThrottles
	e.rlMu.Unlock()

	if !afterCooldown.Equal(beforeCooldown) {
		t.Errorf("cooldownUntil đổi %v → %v sau một 5xx từ endpoint BẰNG CHỨNG — nó sẽ chặn đường đẩy TIỀN",
			beforeCooldown, afterCooldown)
	}
	if afterThrottles != beforeThrottles {
		t.Errorf("consecutiveThrottles đổi %d → %d — lần hỏng kế tiếp của đường TIỀN sẽ backoff dài hơn nó đáng phải chịu",
			beforeThrottles, afterThrottles)
	}
	if e.inCooldown() {
		t.Error("một 5xx từ endpoint bằng chứng đã bật cooldown toàn cục")
	}

	// Và vòng đồng bộ ĐI TIẾP: hàng đợi tiền vẫn được drain sau lượt đẩy hỏng.
	var cloudID string
	_ = db.QueryRow(`SELECT COALESCE(cloud_id, '') FROM payments WHERE id = 'local-pay-2885'`).Scan(&cloudID)
	if cloudID == "" {
		t.Fatal("hàng đợi TIỀN không được drain sau khi đẩy bằng chứng hỏng — fail-open đã vỡ")
	}
	if atomic.LoadInt32(&rec.other) == 0 {
		t.Error("không có request nào khác tới Cloud — vòng sync dừng ở lượt đẩy bằng chứng")
	}
}

// Chiều còn lại của cùng quan hệ: đường này TÔN TRỌNG cooldown do đường tiền
// tạo ra. Tôn trọng mà không tạo ra — hai vế phải cùng đúng.
func TestMoneyOverwritePushUp_RespectsCooldownItMustNeverCreate(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusAccepted)
	e, db := newMoneyOverwritePushEngine(t, cloud.URL)

	id := seedOverwrite(t, db, overwriteSeed{
		orderID: "order-cool", createdAt: "2026-08-13T07:01:33Z", totalLocal: 100, totalCloud: 99,
	})

	e.rlMu.Lock()
	e.cooldownUntil = time.Now().Add(time.Minute)
	e.rlMu.Unlock()

	e.PushMoneyOverwritesUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 0 {
		t.Fatalf("POST = %d, muốn 0 khi Cloud đang backpressure", got)
	}
	if stamp := syncedStamp(t, db, id); stamp != "" {
		t.Errorf("synced_at = %q, muốn rỗng — không gửi thì không được đánh dấu", stamp)
	}
	if stats := e.MoneyOverwritePushStats(); stats.Skipped != 1 || stats.Failed != 0 {
		t.Errorf("stats = %+v, muốn skipped=1 failed=0", stats)
	}
}

// ─── 2. Chỉ đánh dấu khi CHẮC CHẮN Cloud đã nhận ────────────────────────────

func TestMoneyOverwritePushUp_SuccessStampsAndNeverResends(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusAccepted)
	e, db := newMoneyOverwritePushEngine(t, cloud.URL)

	id := seedOverwrite(t, db, overwriteSeed{
		orderID: "019feb37-0000-7000-8000-000000000001", createdAt: "2026-08-13T07:01:33Z", paid: 297,
		totalLocal: 1190, totalCloud: 297, subtotalLocal: 1190, subtotalCloud: 297,
	})

	e.PushMoneyOverwritesUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 1 {
		t.Fatalf("POST = %d, muốn 1", got)
	}
	stamp := syncedStamp(t, db, id)
	if stamp == "" {
		t.Fatal("Cloud trả 202 mà `synced_at` vẫn rỗng — lượt sau sẽ gửi lại mãi mãi")
	}
	if _, err := time.Parse(time.RFC3339, stamp); err != nil {
		t.Errorf("synced_at = %q, không phải RFC3339: %v", stamp, err)
	}

	// Lượt sau: KHÔNG gửi lại. Đây là điểm khác đường alert (#2695 đẩy lại mọi
	// alert đang mở mỗi phút — 4.721 dòng log/ngày). Bảng này là LỊCH SỬ.
	e.PushMoneyOverwritesUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 1 {
		t.Fatalf("POST = %d sau lượt hai, muốn vẫn 1 — bằng chứng đã gửi bị đẩy lại", got)
	}
	if stats := e.MoneyOverwritePushStats(); stats.OK != 1 || stats.RowsSent != 1 || stats.Skipped != 1 {
		t.Errorf("stats = %+v, muốn ok=1 rows_sent=1 skipped=1 (lượt hai không có gì để gửi)", stats)
	}
}

// 5xx: để nguyên, thử lại. Thà Cloud nhận trùng (khử trùng theo
// `(device_id, local_id)`) còn hơn mất một dòng bằng chứng.
func TestMoneyOverwritePushUp_ServerErrorLeavesEvidencePendingAndRetriesNextCycle(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusInternalServerError)
	e, db := newMoneyOverwritePushEngine(t, cloud.URL)

	id := seedOverwrite(t, db, overwriteSeed{
		orderID: "order-5xx", createdAt: "2026-08-13T07:01:33Z", totalLocal: 1190, totalCloud: 297,
	})

	e.PushMoneyOverwritesUp(context.Background())

	if stamp := syncedStamp(t, db, id); stamp != "" {
		t.Fatalf("synced_at = %q sau một 500 — bằng chứng bị đánh dấu là đã tới HQ trong khi HQ không có nó", stamp)
	}
	stats := e.MoneyOverwritePushStats()
	if stats.Failed != 1 || stats.OK != 0 || stats.RowsSent != 0 {
		t.Errorf("stats = %+v, muốn failed=1 ok=0 rows_sent=0", stats)
	}
	if stats.LastError == "" {
		t.Error("LastError rỗng — không nói được vì sao hỏng")
	}

	// Lượt sau, Cloud khoẻ lại: đúng dòng đó đi.
	rec.setStatus(http.StatusAccepted)
	e.PushMoneyOverwritesUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 2 {
		t.Fatalf("POST = %d, muốn 2 — lượt sau phải thử lại", got)
	}
	if syncedStamp(t, db, id) == "" {
		t.Error("lượt thử lại thành công mà vẫn không đánh dấu")
	}
	batches := rec.batches(t)
	if len(batches) != 2 || len(batches[1]) != 1 {
		t.Fatalf("lô thử lại = %#v, muốn đúng 1 dòng", batches)
	}
	if got := wireField(t, batches[1][0].(map[string]any), "local_id"); int64(got) != id {
		t.Errorf("local_id lúc thử lại = %v, muốn %d — khoá idempotency phải giữ nguyên qua các lượt", got, id)
	}
}

// Timeout trông khác 5xx trên dây nhưng phải cho cùng kết quả: không đánh dấu,
// không ném, không treo vòng sync.
func TestMoneyOverwritePushUp_TimeoutLeavesEvidencePending(t *testing.T) {
	hang := make(chan struct{})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		<-hang
	}))
	defer srv.Close()
	defer close(hang)

	e, db := newMoneyOverwritePushEngine(t, srv.URL)
	id := seedOverwrite(t, db, overwriteSeed{
		orderID: "order-timeout", createdAt: "2026-08-13T07:01:33Z", totalLocal: 1190, totalCloud: 297,
	})

	ctx, cancel := context.WithTimeout(context.Background(), 150*time.Millisecond)
	defer cancel()

	done := make(chan struct{})
	go func() {
		defer close(done)
		e.PushMoneyOverwritesUp(ctx)
	}()

	select {
	case <-done:
	case <-time.After(10 * time.Second):
		t.Fatal("PushMoneyOverwritesUp treo quá ctx timeout")
	}

	if stamp := syncedStamp(t, db, id); stamp != "" {
		t.Errorf("synced_at = %q sau timeout — không có 2xx nào chứng minh Cloud đã nhận", stamp)
	}
	if stats := e.MoneyOverwritePushStats(); stats.Failed != 1 || stats.OK != 0 {
		t.Errorf("stats = %+v, muốn failed=1 ok=0", stats)
	}
	if e.inCooldown() {
		t.Error("timeout ở endpoint bằng chứng đã bật cooldown toàn cục")
	}
}

// ─── 3. Backend deploy TRƯỚC — 404 là trạng thái bình thường ────────────────

func TestMoneyOverwritePushUp_CloudNotDeployedIsSilentAndRetried(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusNotFound)
	e, db := newMoneyOverwritePushEngine(t, cloud.URL)

	// Alert centre có nối — để chứng minh KHÔNG có alert nào được dựng.
	em := NewAlertEmitter(NewAlertStore(db), nil)
	e.SetAlerts(em)

	id := seedOverwrite(t, db, overwriteSeed{
		orderID: "order-404", createdAt: "2026-08-13T07:01:33Z", totalLocal: 1190, totalCloud: 297,
	})

	e.rlMu.Lock()
	beforeCooldown := e.cooldownUntil
	beforeThrottles := e.consecutiveThrottles
	e.rlMu.Unlock()

	e.PushMoneyOverwritesUp(context.Background())

	if stamp := syncedStamp(t, db, id); stamp != "" {
		t.Fatalf("synced_at = %q sau 404 — máy trạm lên trước backend sẽ vứt sạch bằng chứng của cửa sổ đó", stamp)
	}

	stats := e.MoneyOverwritePushStats()
	if stats.NotDeployed != 1 {
		t.Errorf("stats.NotDeployed = %d, muốn 1", stats.NotDeployed)
	}
	if stats.Failed != 0 {
		t.Errorf("stats.Failed = %d, muốn 0 — 404 là cửa sổ deploy đã lường trước, không phải sự cố", stats.Failed)
	}
	if stats.OK != 0 || stats.RowsSent != 0 {
		t.Errorf("stats = %+v, muốn ok=0 rows_sent=0", stats)
	}

	// KHÔNG dựng alert: một báo động ở MỌI quán, mỗi phút, cho tới khi backend
	// lên, là cách panel chết.
	open, err := em.ListOpen()
	if err != nil {
		t.Fatalf("ListOpen: %v", err)
	}
	if len(open) != 0 {
		t.Errorf("404 dựng %d alert, muốn 0: %+v", len(open), open)
	}

	// KHÔNG đụng backpressure.
	e.rlMu.Lock()
	afterCooldown := e.cooldownUntil
	afterThrottles := e.consecutiveThrottles
	e.rlMu.Unlock()
	if !afterCooldown.Equal(beforeCooldown) || afterThrottles != beforeThrottles {
		t.Errorf("404 đã chạm backpressure: cooldown %v→%v, throttles %d→%d",
			beforeCooldown, afterCooldown, beforeThrottles, afterThrottles)
	}

	// Backend lên: đúng dòng đó đi ở lượt sau.
	rec.setStatus(http.StatusAccepted)
	e.PushMoneyOverwritesUp(context.Background())

	if syncedStamp(t, db, id) == "" {
		t.Error("sau khi backend lên, lượt kế vẫn không đẩy được dòng đang chờ")
	}
	if stats := e.MoneyOverwritePushStats(); stats.OK != 1 || stats.RowsSent != 1 {
		t.Errorf("stats = %+v, muốn ok=1 rows_sent=1", stats)
	}
}

// ─── 4. Trần lô 50 + đánh dấu ĐÚNG những dòng đã gửi ────────────────────────

func TestMoneyOverwritePushUp_BatchCapsAtFiftyAndNextCycleTakesTheRest(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusAccepted)
	e, db := newMoneyOverwritePushEngine(t, cloud.URL)

	const total = 63 // > 50, và phần dư đủ lớn để không nhầm với lỗi off-by-one
	ids := make([]int64, 0, total)
	for i := range total {
		ids = append(ids, seedOverwrite(t, db, overwriteSeed{
			orderID:   fmt.Sprintf("order-%02d", i),
			createdAt: "2026-08-13T07:01:33Z",
			// Số khác nhau từng dòng để lô hai không thể là bản sao của lô một.
			totalLocal: 1000 + i, totalCloud: 900 + i,
		}))
	}

	e.PushMoneyOverwritesUp(context.Background())

	batches := rec.batches(t)
	if len(batches) != 1 {
		t.Fatalf("số lô = %d, muốn 1", len(batches))
	}
	if len(batches[0]) != moneyOverwritePushBatchSize {
		t.Fatalf("lô 1 mang %d dòng, muốn đúng %d — vượt `max:50` thì Cloud 422 và CẢ LÔ rơi",
			len(batches[0]), moneyOverwritePushBatchSize)
	}

	// Đánh dấu ĐÚNG 50 dòng đã gửi, và KHÔNG đánh dấu 13 dòng ngoài lô.
	pending := pendingIDs(t, db)
	if len(pending) != total-moneyOverwritePushBatchSize {
		t.Fatalf("còn %d dòng chưa đánh dấu, muốn %d", len(pending), total-moneyOverwritePushBatchSize)
	}
	for i, id := range ids[:moneyOverwritePushBatchSize] {
		if syncedStamp(t, db, id) == "" {
			t.Fatalf("dòng thứ %d (id=%d) đã gửi mà không được đánh dấu", i, id)
		}
	}
	for i, id := range ids[moneyOverwritePushBatchSize:] {
		if stamp := syncedStamp(t, db, id); stamp != "" {
			t.Fatalf("dòng NGOÀI lô thứ %d (id=%d) bị đánh dấu %q — HQ không hề nhận nó",
				i, id, stamp)
		}
	}

	// Lượt kế lấy đúng phần còn lại, cũ nhất trước.
	e.PushMoneyOverwritesUp(context.Background())

	batches = rec.batches(t)
	if len(batches) != 2 {
		t.Fatalf("số lô = %d, muốn 2", len(batches))
	}
	if len(batches[1]) != total-moneyOverwritePushBatchSize {
		t.Fatalf("lô 2 mang %d dòng, muốn %d", len(batches[1]), total-moneyOverwritePushBatchSize)
	}
	if got := pendingIDs(t, db); len(got) != 0 {
		t.Errorf("còn %d dòng chưa gửi sau hai lượt: %v", len(got), got)
	}

	// Lô 2 phải là phần CÒN LẠI, không phải 13 dòng đầu gửi lại.
	first := batches[1][0].(map[string]any)
	if got := int64(wireField(t, first, "local_id")); got != ids[moneyOverwritePushBatchSize] {
		t.Errorf("dòng đầu của lô 2 có local_id = %d, muốn %d — lô sau không nối tiếp lô trước",
			got, ids[moneyOverwritePushBatchSize])
	}

	if stats := e.MoneyOverwritePushStats(); stats.OK != 2 || stats.RowsSent != total {
		t.Errorf("stats = %+v, muốn ok=2 rows_sent=%d", stats, total)
	}
}

// ─── 5. Không có gì chờ ⇒ không đánh thức Cloud ─────────────────────────────

func TestMoneyOverwritePushUp_NothingPendingMakesNoHTTPCallAtAll(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusAccepted)
	e, _ := newMoneyOverwritePushEngine(t, cloud.URL)

	e.PushMoneyOverwritesUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 0 {
		t.Fatalf("POST = %d, muốn 0 — đây là trạng thái của HẦU HẾT các ngày ở một quán khoẻ mạnh", got)
	}
	if got := atomic.LoadInt32(&rec.other); got != 0 {
		t.Fatalf("request tới đường khác = %d, muốn 0", got)
	}
	stats := e.MoneyOverwritePushStats()
	if stats.Skipped != 1 || stats.OK != 0 || stats.Failed != 0 || stats.NotDeployed != 0 {
		t.Errorf("stats = %+v, muốn skipped=1, mọi thứ khác 0", stats)
	}
	if stats.LastSkipReason == "" {
		t.Error("LastSkipReason rỗng — không phân biệt được 'không có gì' với 'chưa ghép cặp'")
	}
}

// Chưa ghép cặp thì mọi request sẽ 401; đếm nó là `Failed` sẽ biến một máy đang
// chờ ghép cặp thành một báo động đường truyền giả.
func TestMoneyOverwritePushUp_UnpairedDeviceSkipsInsteadOfFailing(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusAccepted)
	e, db := newSyncTestEngine(t, cloud.URL) // KHÔNG seed device_token

	id := seedOverwrite(t, db, overwriteSeed{
		orderID: "order-unpaired", createdAt: "2026-08-13T07:01:33Z", totalLocal: 100, totalCloud: 99,
	})

	e.PushMoneyOverwritesUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 0 {
		t.Fatalf("POST = %d, muốn 0 khi chưa có device token", got)
	}
	if stamp := syncedStamp(t, db, id); stamp != "" {
		t.Errorf("synced_at = %q — chưa gửi mà đã đánh dấu", stamp)
	}
	if stats := e.MoneyOverwritePushStats(); stats.Skipped != 1 || stats.Failed != 0 {
		t.Errorf("stats = %+v, muốn skipped=1 failed=0", stats)
	}
}

// ─── 6. Payload đúng hợp đồng wire ──────────────────────────────────────────

// Cả 10 trường tiền BẮT BUỘC, kể cả khi bằng 0 — một dòng phải tự đứng được mà
// không join ngược về `orders` (bảng đó đã bị ghi đè rồi). Tiền cho phép ÂM.
func TestMoneyOverwritePushUp_PayloadCarriesTheFullWireContract(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusAccepted)
	e, db := newMoneyOverwritePushEngine(t, cloud.URL)

	id := seedOverwrite(t, db, overwriteSeed{
		orderID:   "019feb37-1111-7000-8000-000000000abc",
		createdAt: "2026-08-13T07:01:33Z",
		paid:      297,
		// Bốn cặp bằng 0 (Cloud đồng ý) + một cặp ÂM (giảm giá).
		totalLocal: 1190, totalCloud: 297,
		subtotalLocal: 0, subtotalCloud: 0,
		taxLocal: 0, taxCloud: 0,
		serviceLocal: 0, serviceCloud: 0,
		discountLocal: -150, discountCloud: -151,
	})

	e.PushMoneyOverwritesUp(context.Background())

	batches := rec.batches(t)
	if len(batches) != 1 || len(batches[0]) != 1 {
		t.Fatalf("payload = %#v, muốn đúng 1 lô 1 dòng", batches)
	}
	item, ok := batches[0][0].(map[string]any)
	if !ok {
		t.Fatalf("phần tử = %#v, muốn một object", batches[0][0])
	}

	// Khoá idempotency phía Cloud là `(device_id, local_id)` — local_id PHẢI là
	// id autoincrement cục bộ, không phải một id mới sinh.
	if got := int64(wireField(t, item, "local_id")); got != id {
		t.Errorf("local_id = %d, muốn %d (id cục bộ)", got, id)
	}
	if item["order_id"] != "019feb37-1111-7000-8000-000000000abc" {
		t.Errorf("order_id = %v", item["order_id"])
	}
	if item["occurred_at"] != "2026-08-13T07:01:33Z" {
		t.Errorf("occurred_at = %v, muốn 2026-08-13T07:01:33Z (RFC3339 UTC)", item["occurred_at"])
	}

	// ĐỦ 10 trường tiền + paid_locally, kể cả những trường bằng 0. Một trường 0
	// bị nuốt biến "Cloud đồng ý, cả hai đều 0" thành "không biết".
	want := map[string]float64{
		"paid_locally":          297,
		"total_amount_local":    1190,
		"total_amount_cloud":    297,
		"subtotal_local":        0,
		"subtotal_cloud":        0,
		"tax_amount_local":      0,
		"tax_amount_cloud":      0,
		"service_charge_local":  0,
		"service_charge_cloud":  0,
		"discount_amount_local": -150,
		"discount_amount_cloud": -151,
	}
	for key, exp := range want {
		if got := wireField(t, item, key); got != exp {
			t.Errorf("%s = %v, muốn %v", key, got, exp)
		}
	}
	if len(item) != len(want)+3 { // + local_id, order_id, occurred_at
		t.Errorf("payload mang %d trường, muốn %d — thừa/thiếu trường so với hợp đồng: %#v",
			len(item), len(want)+3, item)
	}

	// Thân request bọc trong khoá `overwrites`, không phải mảng trần.
	rec.mu <- struct{}{}
	body := rec.bodies[0]
	<-rec.mu
	if _, ok := body["overwrites"]; !ok {
		t.Errorf("thân request thiếu khoá `overwrites`: %#v", body)
	}
}

// `occurred_at` phải là UTC. Một offset địa phương lọt lên sẽ làm HQ xếp sai
// thứ tự các lần ghi đè giữa quán VN (UTC+7) và quán JP (UTC+9) — đúng loại lỗi
// #1091 tồn tại để chặn.
func TestMoneyOverwriteOccurredAt_NormalisesEverythingToUTC(t *testing.T) {
	cases := []struct {
		in   string
		want string
		ok   bool
	}{
		{"2026-08-13T07:01:33Z", "2026-08-13T07:01:33Z", true},
		{"2026-08-13T16:01:33+09:00", "2026-08-13T07:01:33Z", true}, // quán JP
		{"2026-08-13T14:01:33+07:00", "2026-08-13T07:01:33Z", true}, // quán VN
		{"2026-08-13T07:01:33.482Z", "2026-08-13T07:01:33Z", true},
		{"2026-08-13 07:01:33", "2026-08-13T07:01:33Z", true},
		{"  2026-08-13T07:01:33Z  ", "2026-08-13T07:01:33Z", true},
		{"", "", false},
		{"hôm qua", "", false},
		{"1755064893", "", false}, // epoch giây: KHÔNG đoán
	}

	for _, c := range cases {
		got, ok := moneyOverwriteOccurredAt(c.in)
		if ok != c.ok {
			t.Errorf("moneyOverwriteOccurredAt(%q) ok = %v, muốn %v", c.in, ok, c.ok)

			continue
		}
		if got != c.want {
			t.Errorf("moneyOverwriteOccurredAt(%q) = %q, muốn %q", c.in, got, c.want)
		}
	}
}

// KHÔNG bịa thời điểm khi đọc không được: `time.Now()` sẽ dán nhãn "vừa xảy ra"
// lên một khoảng lệch của tháng trước, và một bằng chứng bịa tệ hơn không có
// bằng chứng vì nó sẽ được tin.
func TestMoneyOverwritePushUp_UnreadableTimestampIsDroppedNotInvented(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusAccepted)
	e, db := newMoneyOverwritePushEngine(t, cloud.URL)

	bad := seedOverwrite(t, db, overwriteSeed{
		orderID: "order-bad-time", createdAt: "hôm qua", totalLocal: 100, totalCloud: 99,
	})
	good := seedOverwrite(t, db, overwriteSeed{
		orderID: "order-good-time", createdAt: "2026-08-13T07:01:33Z", totalLocal: 100, totalCloud: 99,
	})

	e.PushMoneyOverwritesUp(context.Background())

	batches := rec.batches(t)
	if len(batches) != 1 || len(batches[0]) != 1 {
		t.Fatalf("lô = %#v, muốn đúng 1 dòng (dòng hỏng bị loại, không kéo cả lô xuống)", batches)
	}
	if got := int64(wireField(t, batches[0][0].(map[string]any), "local_id")); got != good {
		t.Errorf("dòng đi lên có local_id = %d, muốn %d", got, good)
	}
	if syncedStamp(t, db, bad) != "" {
		t.Error("dòng không gửi được bị đánh dấu synced — bằng chứng biến mất trong im lặng")
	}
	if syncedStamp(t, db, good) == "" {
		t.Error("một dòng hỏng đã kéo theo cả lô — dòng hợp lệ cũng không tới nơi")
	}
	if stats := e.MoneyOverwritePushStats(); stats.Malformed != 1 {
		t.Errorf("stats.Malformed = %d, muốn 1", stats.Malformed)
	}
}

// Dòng hỏng nằm ở id thấp sẽ chiếm chỗ trong lô 50 MÃI MÃI. Đủ 50 dòng như thế
// thì mọi bằng chứng mới bị chặn đứng — và bằng chứng mới chính là thứ đang có
// giá trị nhất.
func TestMoneyOverwritePushUp_MalformedRowsNeverStarveTheBatch(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusAccepted)
	e, db := newMoneyOverwritePushEngine(t, cloud.URL)

	for i := range moneyOverwritePushBatchSize {
		seedOverwrite(t, db, overwriteSeed{
			orderID: fmt.Sprintf("order-rotten-%02d", i), createdAt: "không đọc được",
			totalLocal: 100, totalCloud: 99,
		})
	}
	fresh := make([]int64, 0, 3)
	for i := range 3 {
		fresh = append(fresh, seedOverwrite(t, db, overwriteSeed{
			orderID: fmt.Sprintf("order-fresh-%02d", i), createdAt: "2026-08-13T07:01:33Z",
			totalLocal: 1190, totalCloud: 297,
		}))
	}

	// Lượt 1: cửa sổ đọc 50 chỗ trúng trọn ổ dòng hỏng — không có gì gửi được,
	// nhưng engine PHẢI nhớ chúng.
	e.PushMoneyOverwritesUp(context.Background())
	if got := atomic.LoadInt32(&rec.calls); got != 0 {
		t.Fatalf("POST = %d ở lượt 1, muốn 0", got)
	}
	if got := e.MoneyOverwritePushStats().Malformed; got != int64(moneyOverwritePushBatchSize) {
		t.Fatalf("stats.Malformed = %d, muốn %d", got, moneyOverwritePushBatchSize)
	}

	// Lượt 2: cửa sổ đọc nới đúng bằng số dòng hỏng đã biết, nên bằng chứng
	// mới đi được.
	e.PushMoneyOverwritesUp(context.Background())

	batches := rec.batches(t)
	if len(batches) != 1 || len(batches[0]) != len(fresh) {
		t.Fatalf("lô = %#v, muốn 1 lô %d dòng — dòng hỏng đang bỏ đói bằng chứng mới",
			batches, len(fresh))
	}
	for i, id := range fresh {
		if syncedStamp(t, db, id) == "" {
			t.Errorf("dòng mới thứ %d (id=%d) vẫn chưa tới HQ", i, id)
		}
	}
}

// Cửa sổ đọc được NỚI theo số dòng hỏng đã biết, nên khi những dòng ấy được
// sửa lại (khôi phục database, sửa tay) câu truy vấn trả về nhiều hơn 50 dòng
// GỬI ĐƯỢC trong một lượt. Trần lô phải chặn đúng ở đó — đây là ca duy nhất mà
// `LIMIT` của SQL không tự chặn hộ, và vượt `max:50` thì Cloud 422 CẢ LÔ.
func TestMoneyOverwritePushUp_WidenedWindowStillNeverExceedsFifty(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusAccepted)
	e, db := newMoneyOverwritePushEngine(t, cloud.URL)

	const total = 60
	for i := range total {
		seedOverwrite(t, db, overwriteSeed{
			orderID: fmt.Sprintf("order-broken-%02d", i), createdAt: "không đọc được",
			totalLocal: 1000 + i, totalCloud: 900 + i,
		})
	}

	// Lượt 1 nhét đủ 50 id vào tập "hỏng" ⇒ lượt sau cửa sổ đọc nới lên 100.
	e.PushMoneyOverwritesUp(context.Background())
	if got := e.MoneyOverwritePushStats().Malformed; got != int64(moneyOverwritePushBatchSize) {
		t.Fatalf("stats.Malformed = %d, muốn %d — tiền đề của bài này chưa dựng được",
			got, moneyOverwritePushBatchSize)
	}

	// Ai đó sửa lại dấu thời gian (khôi phục / sửa tay): 60 dòng nay gửi được.
	if _, err := db.Exec(`UPDATE order_money_overwrites SET created_at = '2026-08-13T07:01:33Z'`); err != nil {
		t.Fatalf("sửa created_at: %v", err)
	}

	e.PushMoneyOverwritesUp(context.Background())

	batches := rec.batches(t)
	if len(batches) != 1 {
		t.Fatalf("số lô = %d, muốn 1", len(batches))
	}
	if len(batches[0]) != moneyOverwritePushBatchSize {
		t.Fatalf("lô mang %d dòng, muốn đúng %d — cửa sổ đọc đã nới lên %d và trần lô không chặn lại",
			len(batches[0]), moneyOverwritePushBatchSize, moneyOverwritePushBatchSize+total)
	}
	if got := pendingIDs(t, db); len(got) != total-moneyOverwritePushBatchSize {
		t.Errorf("còn %d dòng chưa gửi, muốn %d", len(got), total-moneyOverwritePushBatchSize)
	}
}

// ─── 7. Nhịp + đường đi thật ────────────────────────────────────────────────

// Vòng tick không được đẩy mỗi 5 giây: một quán thật sinh vài dòng mỗi ngày,
// nên nhịp dày chỉ đốt ngân sách request mà đường đẩy TIỀN đang dùng chung.
func TestMoneyOverwritePushUp_TickThrottlesToOnePushPerInterval(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusAccepted)
	e, db := newMoneyOverwritePushEngine(t, cloud.URL)

	seedOverwrite(t, db, overwriteSeed{
		orderID: "order-throttle", createdAt: "2026-08-13T07:01:33Z", totalLocal: 100, totalCloud: 99,
	})

	e.maybePushMoneyOverwritesUp()
	e.maybePushMoneyOverwritesUp()
	e.maybePushMoneyOverwritesUp()

	if got := atomic.LoadInt32(&rec.calls); got != 1 {
		t.Fatalf("POST = %d, muốn 1 — ba nhịp liên tiếp phải bị tiết chế", got)
	}
}

// Đường đi ĐẦY ĐỦ: Cloud ghi đè tiền của một đơn ⇒ `recordMoneyOverwrite` ghi
// dòng bằng chứng ⇒ vòng đẩy đưa nó tới HQ. Thiếu bài này thì hai nửa có thể
// cùng xanh mà không nối được với nhau.
func TestMoneyOverwritePushUp_RecordedOverwriteReachesCloudEndToEnd(t *testing.T) {
	cloud, rec := newMoneyOverwriteCloud(t, http.StatusAccepted)
	e, db := newMoneyOverwritePushEngine(t, cloud.URL)
	seedMoneyOrder(t, e, 1100) // tiền ĐÃ vào két — khoảng lệch là 過不足 thật
	wireAlerts(t, e)

	// Cloud ghi đè: thuế 100 → 91, giảm giá 0 → 9.
	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1100.00",
		"subtotal":        "1000.00",
		"tax_amount":      "91.00",
		"service_charge":  "0.00",
		"discount_amount": "9.00",
	})

	pending := pendingIDs(t, db)
	if len(pending) != 1 {
		t.Fatalf("có %d dòng bằng chứng chờ đẩy, muốn 1", len(pending))
	}
	// Dòng sinh ra ở đường thật phải RA ĐỜI ở trạng thái CHƯA GỬI — nếu không
	// thì migration 088 đặt default sai và mọi bằng chứng lịch sử bị bỏ qua.
	if stamp := syncedStamp(t, db, pending[0]); stamp != "" {
		t.Fatalf("dòng vừa ghi có synced_at = %q, muốn NULL", stamp)
	}

	e.PushMoneyOverwritesUp(context.Background())

	batches := rec.batches(t)
	if len(batches) != 1 || len(batches[0]) != 1 {
		t.Fatalf("lô = %#v, muốn 1 lô 1 dòng", batches)
	}
	item := batches[0][0].(map[string]any)
	if item["order_id"] != "o1" {
		t.Errorf("order_id = %v, muốn o1", item["order_id"])
	}
	if got := wireField(t, item, "tax_amount_local"); got != 100 {
		t.Errorf("tax_amount_local = %v, muốn 100", got)
	}
	if got := wireField(t, item, "tax_amount_cloud"); got != 91 {
		t.Errorf("tax_amount_cloud = %v, muốn 91", got)
	}
	if got := wireField(t, item, "discount_amount_cloud"); got != 9 {
		t.Errorf("discount_amount_cloud = %v, muốn 9", got)
	}
	if got := wireField(t, item, "paid_locally"); got != 1100 {
		t.Errorf("paid_locally = %v, muốn 1100 — snapshet lúc ghi đè quyết định đây là 過不足 thật", got)
	}
	// `occurred_at` = `created_at` cục bộ, và `recordMoneyOverwrite` ghi RFC3339
	// UTC — nên nó phải đi lên NGUYÊN VĂN, không lệch múi giờ.
	occurred, _ := item["occurred_at"].(string)
	if !strings.HasSuffix(occurred, "Z") {
		t.Errorf("occurred_at = %q, muốn RFC3339 UTC (kết thúc bằng Z)", occurred)
	}
	if _, err := time.Parse(time.RFC3339, occurred); err != nil {
		t.Errorf("occurred_at = %q không phải RFC3339: %v", occurred, err)
	}

	if got := pendingIDs(t, db); len(got) != 0 {
		t.Errorf("còn %d dòng chưa đánh dấu sau khi Cloud nhận: %v", len(got), got)
	}
}

// Đóng dấu lần hai (lô trùng sau khi mất dấu giữa 2xx và UPDATE) KHÔNG được ghi
// đè thời điểm của lần đầu: bảng này append-only và bất biến.
func TestMoneyOverwritePushUp_StampIsWrittenOnceAndNeverRewritten(t *testing.T) {
	e, db := newMoneyOverwritePushEngine(t, "")

	id := seedOverwrite(t, db, overwriteSeed{
		orderID: "order-stamp", createdAt: "2026-08-13T07:01:33Z", totalLocal: 100, totalCloud: 99,
	})

	e.markMoneyOverwritesSynced([]int64{id})
	first := syncedStamp(t, db, id)
	if first == "" {
		t.Fatal("lần đóng dấu đầu không ghi được gì")
	}

	// Ép một giá trị khác biệt rồi đóng dấu lại — dấu đầu phải thắng.
	if _, err := db.Exec(`UPDATE order_money_overwrites SET synced_at = '2000-01-01T00:00:00Z' WHERE id = ?`, id); err != nil {
		t.Fatalf("ép synced_at: %v", err)
	}
	e.markMoneyOverwritesSynced([]int64{id})

	if got := syncedStamp(t, db, id); got != "2000-01-01T00:00:00Z" {
		t.Errorf("synced_at = %q, muốn giữ nguyên 2000-01-01T00:00:00Z — dấu đầu tiên phải thắng", got)
	}
}
