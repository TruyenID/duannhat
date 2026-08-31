package service

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// #2901 — vòng đẩy log THEO YÊU CẦU.
//
// Bộ bài xếp theo sức nặng của cái có thể mất:
//
//  1. một lượt đẩy hỏng KHÔNG được chạm backpressure dùng chung — bài học
//     #2695: một endpoint phụ hỏng từng chặn đường đẩy TIỀN tới 5 phút. Đây là
//     cái sai đắt nhất có thể mắc ở file này;
//  2. **không có yêu cầu treo ⇒ 0 request đẩy** — ca thường ngày là MỌI ngày ở
//     MỌI quán, và một đường phụ nói suốt sẽ đốt ngân sách 250 req/phút mà
//     đường đẩy tiền đang dùng chung;
//  3. 404 im lặng — hợp đồng nói backend deploy TRƯỚC, nên cửa sổ giữa hai lần
//     deploy là trạng thái đã thiết kế, không phải sự cố;
//  4. chỉ ghi tiến độ khi 2xx — timeout/5xx để nguyên, thử lại;
//  5. lô cắt ở 500, lượt kế lấy phần còn lại, lô cuối `final: true`.

// ─── Khung dựng ──────────────────────────────────────────────────────────────

// logCloud giả lập cả HAI endpoint của hợp đồng wire trên cùng một server.
type logCloud struct {
	requestCalls int32
	pushCalls    int32
	otherCalls   int32

	requestStatus int32
	pushStatus    int32

	mu       chan struct{}
	requests []cloudLogRequest
	bodies   []map[string]any
}

func (c *logCloud) setRequests(reqs ...cloudLogRequest) {
	c.mu <- struct{}{}
	c.requests = reqs
	<-c.mu
}

func (c *logCloud) snapshotBodies(t *testing.T) []map[string]any {
	t.Helper()

	c.mu <- struct{}{}
	defer func() { <-c.mu }()

	out := make([]map[string]any, len(c.bodies))
	copy(out, c.bodies)

	return out
}

func newLogCloud(t *testing.T) (*httptest.Server, *logCloud) {
	t.Helper()

	c := &logCloud{
		mu:            make(chan struct{}, 1),
		requestStatus: http.StatusOK,
		pushStatus:    http.StatusAccepted,
	}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case logRequestsPath:
			atomic.AddInt32(&c.requestCalls, 1)
			code := int(atomic.LoadInt32(&c.requestStatus))
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(code)
			if code != http.StatusOK {
				// Đúng thân Laravel trả khi route chưa tồn tại.
				_, _ = w.Write([]byte(`{"message":"The route api/v1/workstation/log-requests could not be found."}`))

				return
			}
			c.mu <- struct{}{}
			payload, _ := json.Marshal(map[string]any{"requests": c.requests})
			<-c.mu
			_, _ = w.Write(payload)

		case logRecordsPath:
			atomic.AddInt32(&c.pushCalls, 1)
			raw, _ := io.ReadAll(r.Body)
			var parsed map[string]any
			_ = json.Unmarshal(raw, &parsed)
			c.mu <- struct{}{}
			c.bodies = append(c.bodies, parsed)
			<-c.mu

			code := int(atomic.LoadInt32(&c.pushStatus))
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(code)
			switch {
			case code < 300:
				_, _ = w.Write([]byte(`{"accepted":1,"duplicates":0}`))
			case code == http.StatusNotFound:
				_, _ = w.Write([]byte(`{"message":"log request not found"}`))
			default:
				_, _ = w.Write([]byte(`{"message":"boom"}`))
			}

		default:
			atomic.AddInt32(&c.otherCalls, 1)
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"data":{"id":"cloud-1"}}`))
		}
	}))
	t.Cleanup(srv.Close)

	return srv, c
}

// newLogShipEngine dựng một engine ĐÃ ghép cặp — điều kiện của đường đẩy thật.
func newLogShipEngine(t *testing.T, cloudURL string) (*SyncEngine, *store.DB) {
	t.Helper()

	e, db := newSyncTestEngine(t, cloudURL)
	if _, err := db.Exec(`INSERT INTO settings (key, value) VALUES ('device_token', 'dev-token-2901')
		ON CONFLICT (key) DO UPDATE SET value = excluded.value`); err != nil {
		t.Fatalf("seed device token: %v", err)
	}

	return e, db
}

// seedLogRecords chèn `n` dòng log đã lọc vào vòng đệm.
func seedLogRecords(t *testing.T, db *store.DB, n int) {
	t.Helper()

	if _, err := db.Exec(`
		WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM c WHERE x < ?)
		INSERT INTO log_records (logged_at, level, message, attrs)
		SELECT '2026-08-16T03:14:43Z', 'info', 'sync_queue purged', '{"rows":1}' FROM c`, n,
	); err != nil {
		t.Fatalf("seed log_records: %v", err)
	}
}

func openLogWindow(id string, maxRecords int) cloudLogRequest {
	return cloudLogRequest{
		ID:         id,
		From:       "2026-08-16T00:00:00Z",
		To:         "2026-08-16T23:59:59Z",
		MaxRecords: maxRecords,
	}
}

func logProgressRow(t *testing.T, db *store.DB, requestID string) (lastID int64, sent int, closed string) {
	t.Helper()

	err := db.QueryRow(
		`SELECT last_local_id, sent_count, COALESCE(closed_at, '') FROM log_request_progress WHERE request_id = ?`,
		requestID,
	).Scan(&lastID, &sent, &closed)
	if err != nil {
		return 0, 0, ""
	}

	return lastID, sent, closed
}

func batchRecords(t *testing.T, body map[string]any) []any {
	t.Helper()

	list, ok := body["records"].([]any)
	if !ok {
		t.Fatalf("body.records = %#v, muốn một mảng", body["records"])
	}

	return list
}

// ─── 1. Bất biến nặng nhất: hỏng KHÔNG được chạm backpressure dùng chung ─────

// #2695 đo được: `cloudPost` gọi `noteThrottle` trên mọi 5xx, `noteThrottle` đặt
// `cooldownUntil` TOÀN CỤC, và `processQueue` bỏ NGUYÊN vòng drain khi
// `inCooldown()`. Một endpoint phụ hỏng chặn đường đẩy TIỀN tới 5 phút. Bài này
// khẳng định THẲNG lên trạng thái ấy, không suy ra từ hành vi.
func TestLogShipUp_FailureNeverTouchesSharedBackpressure(t *testing.T) {
	cloud, rec := newLogCloud(t)
	rec.setRequests(openLogWindow("req-boom", 2000))
	atomic.StoreInt32(&rec.pushStatus, http.StatusInternalServerError)

	e, db := newLogShipEngine(t, cloud.URL)
	seedLogRecords(t, db, 3)

	e.rlMu.Lock()
	beforeCooldown := e.cooldownUntil
	beforeThrottles := e.consecutiveThrottles
	e.rlMu.Unlock()

	// Một hàng đợi TIỀN thật đứng SAU lượt đẩy log trong cùng nhịp tick.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key)
		VALUES ('local-pay-2901', 'order-2901', 'cash', 1000, 'pending', 'idem-2901')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}
	if err := e.Enqueue("payment", "local-pay-2901", "create", map[string]any{
		"bearer_token":    "tok",
		"idempotency_key": "idem-2901",
		"order_id":        "order-2901",
		"payment_method":  "cash",
		"amount":          1000,
	}, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	// MỘT nhịp đồng bộ đầy đủ — fail-open là hợp đồng, tick không trả lỗi.
	e.tick()

	if got := atomic.LoadInt32(&rec.pushCalls); got != 1 {
		t.Fatalf("POST tới %s = %d, muốn 1", logRecordsPath, got)
	}

	e.rlMu.Lock()
	afterCooldown := e.cooldownUntil
	afterThrottles := e.consecutiveThrottles
	e.rlMu.Unlock()

	if !afterCooldown.Equal(beforeCooldown) {
		t.Errorf("cooldownUntil đổi %v → %v sau một 5xx từ endpoint LOG — nó sẽ chặn đường đẩy TIỀN",
			beforeCooldown, afterCooldown)
	}
	if afterThrottles != beforeThrottles {
		t.Errorf("consecutiveThrottles đổi %d → %d — lần hỏng kế tiếp của đường TIỀN sẽ backoff dài hơn nó đáng phải chịu",
			beforeThrottles, afterThrottles)
	}
	if e.inCooldown() {
		t.Error("một 5xx từ endpoint log đã bật cooldown toàn cục")
	}

	// Và vòng đồng bộ ĐI TIẾP: hàng đợi tiền vẫn được drain sau lượt đẩy hỏng.
	var cloudID string
	_ = db.QueryRow(`SELECT COALESCE(cloud_id, '') FROM payments WHERE id = 'local-pay-2901'`).Scan(&cloudID)
	if cloudID == "" {
		t.Fatal("hàng đợi TIỀN không được drain sau khi đẩy log hỏng — fail-open đã vỡ")
	}
}

// Chiều còn lại của cùng quan hệ: đường này TÔN TRỌNG cooldown do đường tiền
// tạo ra. Tôn trọng mà không tạo ra — hai vế phải cùng đúng.
func TestLogShipUp_RespectsCooldownItMustNeverCreate(t *testing.T) {
	cloud, rec := newLogCloud(t)
	rec.setRequests(openLogWindow("req-cool", 2000))

	e, db := newLogShipEngine(t, cloud.URL)
	seedLogRecords(t, db, 3)

	e.rlMu.Lock()
	e.cooldownUntil = time.Now().Add(time.Minute)
	e.rlMu.Unlock()

	e.ShipLogsUp(context.Background())

	if got := atomic.LoadInt32(&rec.requestCalls); got != 0 {
		t.Errorf("GET = %d, muốn 0 khi Cloud đang backpressure — kể cả một GET rẻ", got)
	}
	if got := atomic.LoadInt32(&rec.pushCalls); got != 0 {
		t.Fatalf("POST = %d, muốn 0 khi Cloud đang backpressure", got)
	}
	if stats := e.LogShipStats(); stats.Skipped != 1 || stats.Failed != 0 {
		t.Errorf("stats = %+v, muốn skipped=1 failed=0", stats)
	}
}

// ─── 2. Ca THƯỜNG NGÀY: không yêu cầu treo ⇒ 0 request đẩy ───────────────────

func TestLogShipUp_NoPendingRequestSendsNothing(t *testing.T) {
	cloud, rec := newLogCloud(t)
	// Cloud trả `{"requests":[]}` — đây là ca thường ngày, không phải ca lỗi.

	e, db := newLogShipEngine(t, cloud.URL)
	seedLogRecords(t, db, 900) // có ĐẦY log chờ, và vẫn không được gửi gì

	e.ShipLogsUp(context.Background())

	if got := atomic.LoadInt32(&rec.pushCalls); got != 0 {
		t.Fatalf("POST tới %s = %d, muốn 0 — log phải Ở LẠI QUÁN cho tới khi có người yêu cầu", logRecordsPath, got)
	}
	if got := atomic.LoadInt32(&rec.requestCalls); got != 1 {
		t.Errorf("GET = %d, muốn 1 — máy trạm vẫn phải hỏi, chỉ là không gửi", got)
	}
	stats := e.LogShipStats()
	if stats.Skipped != 1 || stats.Failed != 0 || stats.NotDeployed != 0 {
		t.Errorf("stats = %+v, muốn skipped=1 failed=0 not_deployed=0", stats)
	}
	if stats.LastSkipReason == "" {
		t.Error("LastSkipReason rỗng — bộ đếm không phân biệt được `chưa từng chạy` với `chạy rồi, không có gì`")
	}
}

// ─── 3. 404 = backend chưa deploy ⇒ im lặng ─────────────────────────────────

func TestLogShipUp_RouteMissingIsSilentAndNotAFailure(t *testing.T) {
	cloud, rec := newLogCloud(t)
	atomic.StoreInt32(&rec.requestStatus, http.StatusNotFound)

	e, db := newLogShipEngine(t, cloud.URL)
	seedLogRecords(t, db, 5)

	e.ShipLogsUp(context.Background())

	stats := e.LogShipStats()
	if stats.NotDeployed != 1 {
		t.Fatalf("stats = %+v, muốn not_deployed=1 — hợp đồng nói backend deploy TRƯỚC", stats)
	}
	if stats.Failed != 0 {
		t.Errorf("stats.Failed = %d, muốn 0 — cửa sổ giữa hai lần deploy sẽ dựng một báo động giả ở MỌI quán, MỖI phút",
			stats.Failed)
	}
	if got := atomic.LoadInt32(&rec.pushCalls); got != 0 {
		t.Errorf("POST = %d sau một 404 ở đường hỏi, muốn 0", got)
	}
}

// 404 ở đường ĐẨY nghĩa khác: route đã tồn tại, nên yêu cầu đã đóng/hết hạn.
// Máy trạm coi là "thôi, bỏ qua" — và ĐÓNG cục bộ để không hỏi lại mỗi phút.
func TestLogShipUp_RequestGoneIs404AndClosesQuietly(t *testing.T) {
	cloud, rec := newLogCloud(t)
	rec.setRequests(openLogWindow("req-gone", 2000))
	atomic.StoreInt32(&rec.pushStatus, http.StatusNotFound)

	e, db := newLogShipEngine(t, cloud.URL)
	seedLogRecords(t, db, 5)

	e.ShipLogsUp(context.Background())

	if stats := e.LogShipStats(); stats.Failed != 0 {
		t.Errorf("stats = %+v, muốn failed=0 — yêu cầu đã đóng/hết hạn không phải sự cố", stats)
	}
	if _, _, closed := logProgressRow(t, db, "req-gone"); closed == "" {
		t.Fatal("yêu cầu không được đóng cục bộ — máy trạm sẽ gửi lại 500 dòng mỗi phút cho một yêu cầu không còn tồn tại")
	}

	// Lượt sau: KHÔNG POST nữa, dù Cloud vẫn liệt kê yêu cầu đó.
	e.ShipLogsUp(context.Background())
	if got := atomic.LoadInt32(&rec.pushCalls); got != 1 {
		t.Errorf("POST = %d ở lượt hai, muốn vẫn 1", got)
	}
}

// ─── 4. Chỉ ghi tiến độ khi 2xx ─────────────────────────────────────────────

func TestLogShipUp_ServerErrorKeepsProgressAndRetriesNextCycle(t *testing.T) {
	cloud, rec := newLogCloud(t)
	rec.setRequests(openLogWindow("req-5xx", 2000))
	atomic.StoreInt32(&rec.pushStatus, http.StatusInternalServerError)

	e, db := newLogShipEngine(t, cloud.URL)
	seedLogRecords(t, db, 4)

	e.ShipLogsUp(context.Background())

	if lastID, sent, closed := logProgressRow(t, db, "req-5xx"); lastID != 0 || sent != 0 || closed != "" {
		t.Fatalf("tiến độ = (last=%d sent=%d closed=%q) sau một 500 — lô bị đánh dấu đã tới HQ trong khi HQ không có nó",
			lastID, sent, closed)
	}
	stats := e.LogShipStats()
	if stats.Failed != 1 || stats.OK != 0 || stats.RecordsSent != 0 {
		t.Errorf("stats = %+v, muốn failed=1 ok=0 records_sent=0", stats)
	}
	if stats.LastError == "" {
		t.Error("LastError rỗng — không nói được vì sao hỏng")
	}

	// Lượt sau, Cloud khoẻ lại: đúng lô đó đi, và con trỏ mới tiến.
	atomic.StoreInt32(&rec.pushStatus, http.StatusAccepted)
	e.ShipLogsUp(context.Background())

	if got := atomic.LoadInt32(&rec.pushCalls); got != 2 {
		t.Fatalf("POST = %d, muốn 2 — lượt sau phải thử lại", got)
	}
	lastID, sent, closed := logProgressRow(t, db, "req-5xx")
	if lastID != 4 || sent != 4 || closed == "" {
		t.Errorf("tiến độ = (last=%d sent=%d closed=%q), muốn (4, 4, đã đóng)", lastID, sent, closed)
	}

	bodies := rec.snapshotBodies(t)
	if len(bodies) != 2 || len(batchRecords(t, bodies[1])) != 4 {
		t.Fatalf("lô thử lại = %#v, muốn đúng 4 dòng", bodies)
	}
	first, _ := batchRecords(t, bodies[1])[0].(map[string]any)
	if first["local_id"] != float64(1) {
		t.Errorf("local_id lô thử lại = %v, muốn 1 — khoá idempotency phải giữ nguyên qua các lượt", first["local_id"])
	}
}

// Timeout trông khác 5xx trên dây nhưng phải cho cùng kết quả: không ghi tiến
// độ, không ném, không treo vòng sync.
func TestLogShipUp_TimeoutKeepsProgress(t *testing.T) {
	hang := make(chan struct{})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == logRequestsPath {
			w.Header().Set("Content-Type", "application/json")
			_, _ = fmt.Fprintf(w, `{"requests":[{"id":"req-hang","from":"2026-08-16T00:00:00Z","to":"2026-08-16T23:59:59Z","max_records":2000}]}`)

			return
		}
		<-hang
	}))
	defer srv.Close()
	defer close(hang)

	e, db := newLogShipEngine(t, srv.URL)
	seedLogRecords(t, db, 3)

	ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancel()

	done := make(chan struct{})
	go func() {
		defer close(done)
		e.ShipLogsUp(ctx)
	}()

	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("ShipLogsUp không trả về sau khi ctx hết hạn — nó đang giữ vòng sync")
	}

	if lastID, sent, closed := logProgressRow(t, db, "req-hang"); lastID != 0 || sent != 0 || closed != "" {
		t.Errorf("tiến độ = (last=%d sent=%d closed=%q) sau timeout — không gửi tới nơi thì không được đánh dấu",
			lastID, sent, closed)
	}
	if stats := e.LogShipStats(); stats.Failed != 1 || stats.OK != 0 {
		t.Errorf("stats = %+v, muốn failed=1 ok=0", stats)
	}
}

// ─── 5. Trần lô 500, lượt kế lấy phần còn lại, lô cuối `final: true` ─────────

func TestLogShipUp_BatchCapsAtFiveHundredAndOnlyTheLastIsFinal(t *testing.T) {
	cloud, rec := newLogCloud(t)
	rec.setRequests(openLogWindow("req-big", 2000))

	e, db := newLogShipEngine(t, cloud.URL)
	seedLogRecords(t, db, logPushBatchSize+7)

	e.ShipLogsUp(context.Background())

	bodies := rec.snapshotBodies(t)
	if len(bodies) != 1 {
		t.Fatalf("POST = %d ở lượt một, muốn 1 — một lô mỗi lượt", len(bodies))
	}
	if n := len(batchRecords(t, bodies[0])); n != logPushBatchSize {
		t.Fatalf("lô một = %d dòng, muốn %d — vượt trần thì Cloud trả 422 và CẢ LÔ rơi", n, logPushBatchSize)
	}
	if bodies[0]["final"] != false {
		t.Errorf("lô một `final` = %v, muốn false — còn dòng chưa gửi mà Cloud đã đóng yêu cầu thì HQ đọc một danh sách cụt tưởng là đủ",
			bodies[0]["final"])
	}
	if bodies[0]["request_id"] != "req-big" {
		t.Errorf("request_id = %v, muốn \"req-big\"", bodies[0]["request_id"])
	}
	if lastID, sent, closed := logProgressRow(t, db, "req-big"); lastID != int64(logPushBatchSize) || sent != logPushBatchSize || closed != "" {
		t.Errorf("tiến độ sau lô một = (last=%d sent=%d closed=%q), muốn (%d, %d, chưa đóng)",
			lastID, sent, closed, logPushBatchSize, logPushBatchSize)
	}

	// Lượt kế: phần còn lại, và ĐÂY mới là lô cuối.
	e.ShipLogsUp(context.Background())

	bodies = rec.snapshotBodies(t)
	if len(bodies) != 2 {
		t.Fatalf("POST = %d ở lượt hai, muốn 2", len(bodies))
	}
	if n := len(batchRecords(t, bodies[1])); n != 7 {
		t.Fatalf("lô hai = %d dòng, muốn 7 (phần còn lại)", n)
	}
	if bodies[1]["final"] != true {
		t.Errorf("lô hai `final` = %v, muốn true — không có lô cuối thì yêu cầu treo vĩnh viễn ở HQ", bodies[1]["final"])
	}
	if _, _, closed := logProgressRow(t, db, "req-big"); closed == "" {
		t.Error("yêu cầu chưa đóng cục bộ sau lô `final`")
	}

	// Không gửi lại: lượt ba im lặng.
	e.ShipLogsUp(context.Background())
	if got := atomic.LoadInt32(&rec.pushCalls); got != 2 {
		t.Errorf("POST = %d ở lượt ba, muốn vẫn 2 — yêu cầu đã phục vụ xong bị đẩy lại", got)
	}
}

// `max_records` của Cloud chỉ được làm HẸP phạm vi, và lô chạm trần phải mang
// `final: true` — nếu không, yêu cầu treo vĩnh viễn ở HQ.
func TestLogShipUp_MaxRecordsCapsTheRequestAndClosesIt(t *testing.T) {
	cloud, rec := newLogCloud(t)
	rec.setRequests(openLogWindow("req-cap", 3))

	e, db := newLogShipEngine(t, cloud.URL)
	seedLogRecords(t, db, 40)

	e.ShipLogsUp(context.Background())

	bodies := rec.snapshotBodies(t)
	if len(bodies) != 1 {
		t.Fatalf("POST = %d, muốn 1", len(bodies))
	}
	if n := len(batchRecords(t, bodies[0])); n != 3 {
		t.Fatalf("lô = %d dòng, muốn 3 — `max_records` phải được tôn trọng", n)
	}
	if bodies[0]["final"] != true {
		t.Errorf("`final` = %v, muốn true khi đã chạm `max_records`", bodies[0]["final"])
	}
}

// ─── 6. Hình dạng dây + phạm vi cố định ─────────────────────────────────────

// Cửa sổ thời gian là RÀNG BUỘC, không phải gợi ý: một yêu cầu không được kéo
// theo dòng nằm ngoài khoảng nó hỏi.
func TestLogShipUp_OnlyRecordsInsideTheRequestedWindowLeave(t *testing.T) {
	cloud, rec := newLogCloud(t)
	rec.setRequests(cloudLogRequest{
		ID:         "req-window",
		From:       "2026-08-16T03:00:00Z",
		To:         "2026-08-16T04:00:00Z",
		MaxRecords: 2000,
	})

	e, db := newLogShipEngine(t, cloud.URL)
	for _, stamp := range []string{
		"2026-08-15T23:59:59Z", // trước cửa sổ
		"2026-08-16T03:14:43Z", // trong
		"2026-08-16T05:00:00Z", // sau
	} {
		if _, err := db.Exec(
			`INSERT INTO log_records (logged_at, level, message, attrs) VALUES (?, 'info', 'sync_queue purged', '{"rows":1}')`,
			stamp,
		); err != nil {
			t.Fatalf("seed %s: %v", stamp, err)
		}
	}

	e.ShipLogsUp(context.Background())

	bodies := rec.snapshotBodies(t)
	if len(bodies) != 1 {
		t.Fatalf("POST = %d, muốn 1", len(bodies))
	}
	records := batchRecords(t, bodies[0])
	if len(records) != 1 {
		t.Fatalf("lô = %d dòng, muốn 1 — chỉ dòng trong cửa sổ mới được rời quán: %#v", len(records), records)
	}
	item, _ := records[0].(map[string]any)
	if item["logged_at"] != "2026-08-16T03:14:43Z" {
		t.Errorf("logged_at = %v, muốn dòng trong cửa sổ", item["logged_at"])
	}
	for _, key := range []string{"local_id", "logged_at", "level", "message", "attrs"} {
		if _, ok := item[key]; !ok {
			t.Errorf("payload thiếu trường %q — hợp đồng wire #2901 bắt buộc cả năm: %#v", key, item)
		}
	}
	if item["level"] != "info" {
		t.Errorf("level = %v, muốn \"info\"", item["level"])
	}
}

// Một yêu cầu có cửa sổ không đọc được KHÔNG được phục vụ: máy trạm tự chọn
// phạm vi là đúng thứ ràng buộc "phạm vi cố định" cấm.
func TestLogShipUp_UnreadableWindowIsRefusedNotGuessed(t *testing.T) {
	cloud, rec := newLogCloud(t)
	rec.setRequests(cloudLogRequest{ID: "req-bad", From: "hôm qua", To: "bây giờ", MaxRecords: 2000})

	e, db := newLogShipEngine(t, cloud.URL)
	seedLogRecords(t, db, 5)

	e.ShipLogsUp(context.Background())

	if got := atomic.LoadInt32(&rec.pushCalls); got != 0 {
		t.Fatalf("POST = %d, muốn 0 — cửa sổ không đọc được thì không có phạm vi nào để phục vụ", got)
	}
	if _, _, closed := logProgressRow(t, db, "req-bad"); closed == "" {
		t.Error("yêu cầu hỏng không được đóng — nó sẽ được thử lại mỗi phút mãi mãi")
	}
}

// `max_records` do Cloud gửi chỉ làm HẸP; trần CỨNG là của máy trạm. Một yêu
// cầu đòi một triệu bản ghi không được biến đường này thành cửa hậu hút sạch
// database của quán.
func TestLogShipUp_CloudCannotRaiseTheWorkstationCeiling(t *testing.T) {
	cloud, rec := newLogCloud(t)
	rec.setRequests(openLogWindow("req-greedy", logMaxRecordsCeiling*100))

	e, db := newLogShipEngine(t, cloud.URL)
	seedLogRecords(t, db, 20)

	e.ShipLogsUp(context.Background())

	bodies := rec.snapshotBodies(t)
	if len(bodies) != 1 {
		t.Fatalf("POST = %d, muốn 1", len(bodies))
	}
	if n := len(batchRecords(t, bodies[0])); n > logPushBatchSize {
		t.Fatalf("lô = %d dòng, vượt trần lô %d", n, logPushBatchSize)
	}
	// Trần cứng vẫn là của máy trạm, dù Cloud xin nhiều hơn.
	if _, sent, _ := logProgressRow(t, db, "req-greedy"); sent > logMaxRecordsCeiling {
		t.Errorf("đã gửi %d bản ghi, vượt trần cứng %d của máy trạm", sent, logMaxRecordsCeiling)
	}
}

// Chưa ghép cặp thì không có device token và mọi request sẽ 401. Đếm nó là
// `Failed` sẽ biến một máy đang chờ ghép cặp thành một báo động đường truyền.
func TestLogShipUp_UnpairedDeviceSkipsQuietly(t *testing.T) {
	cloud, rec := newLogCloud(t)
	rec.setRequests(openLogWindow("req-unpaired", 2000))

	e, db := newSyncTestEngine(t, cloud.URL)
	seedLogRecords(t, db, 3)

	e.ShipLogsUp(context.Background())

	if got := atomic.LoadInt32(&rec.requestCalls) + atomic.LoadInt32(&rec.pushCalls); got != 0 {
		t.Fatalf("%d request tới Cloud khi chưa ghép cặp, muốn 0", got)
	}
	if stats := e.LogShipStats(); stats.Skipped != 1 || stats.Failed != 0 {
		t.Errorf("stats = %+v, muốn skipped=1 failed=0", stats)
	}
}
