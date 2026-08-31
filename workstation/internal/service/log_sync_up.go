package service

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	stdsync "sync"
	"time"
)

// #2901 — đẩy log ĐÃ LỌC lên Cloud, CHỈ khi HQ có yêu cầu treo.
//
// # Vì sao "kéo" lại cài thành "đẩy"
//
// Chủ dự án chốt cơ chế **kéo theo yêu cầu** (2026-08-15). Nhưng Cloud KHÔNG
// gọi ngược vào máy trạm được: `http.Server` của nó nằm trên LAN của quán, sau
// NAT, không có địa chỉ công khai — mọi liên lạc hôm nay đều do máy trạm chủ
// động gọi ra. Nên "kéo" thành **yêu cầu treo, máy trạm tự nhận**:
//
//	1. người điều tra bấm ở HQ      → Cloud ghi một yêu cầu `pending`
//	2. máy trạm hỏi ở nhịp kế tiếp  → thấy yêu cầu
//	3. máy trạm LỌC tại chỗ rồi ĐẨY → Cloud lưu, đóng yêu cầu
//
// Tinh thần giữ nguyên và đó mới là điều quan trọng: **log không rời quán cho
// tới khi có người yêu cầu**. Hai đánh đổi phải nói thẳng, không lờ đi — trễ
// một nhịp, và **máy chết thì không lấy được**, đúng lúc cần nhất.
//
// # Ca THƯỜNG NGÀY phải im lặng
//
// Không có yêu cầu treo ⇒ **không một request đẩy nào**. Đây là bất biến, không
// phải tối ưu: mọi ngày ở mọi quán đều là ngày không ai điều tra, và một đường
// phụ nói suốt trong ngân sách 250 req/phút mà đường đẩy TIỀN đang dùng chung
// là cách biến một tính năng quan sát thành một sự cố bán hàng.
//
// # KHÔNG BAO GIỜ chạm backpressure dùng chung
//
// Bài học #2695, đo được: `cloudPost` gọi `noteThrottle` trên mọi 5xx,
// `noteThrottle` đặt `cooldownUntil` TOÀN CỤC, `processQueue` bỏ NGUYÊN vòng
// drain khi `inCooldown()` — nên một endpoint phụ hỏng từng chặn đường đẩy TIỀN
// tới 5 phút. Đường này đi qua `sideChannelPost` (chiều POST) và một client
// thô không đụng tới bộ đếm (chiều GET): **tôn trọng** cooldown, không bao giờ
// **tạo ra** nó.

const (
	logRequestsPath = "/api/v1/workstation/log-requests"
	logRecordsPath  = "/api/v1/workstation/log-records"

	// logPushBatchSize khớp trần 500 của hợp đồng wire. Vượt ngưỡng thì Cloud
	// trả 422 và CẢ LÔ rơi, nên cắt tại đây chứ không phó mặc validator đầu kia.
	logPushBatchSize = 500

	// logMaxRecordsCeiling là trần CỨNG của máy trạm cho `max_records`.
	//
	// Yêu cầu tới từ Cloud, và một đường nhận yêu cầu **không được** biến thành
	// cửa hậu: phạm vi cố định là cửa sổ thời gian + trần số bản ghi, và trần ấy
	// do MÁY TRẠM quyết, không do người gửi yêu cầu. `max_records` của Cloud chỉ
	// có thể làm hẹp thêm.
	logMaxRecordsCeiling = 20000

	// logShipInterval — cùng nhịp một phút với ba đường phụ đã có (alert #2695,
	// sổ máy thu tiền #2878, bằng chứng lệch tiền #2885).
	//
	// KHÔNG hỏi theo nhịp 5s của vòng sync: đó là 17.280 request/ngày/máy để
	// phục vụ một thao tác xảy ra vài lần một năm. Cái giá của nhịp một phút là
	// tối đa 60 giây trễ trước khi máy trạm thấy yêu cầu — không đáng kể so với
	// vòng lặp con người ở đầu kia.
	logShipInterval = time.Minute

	// logShipTimeout — một lượt đẩy log không được giữ vòng sync lâu hơn một
	// nhịp tick. Ngắn hơn timeout 15s của httpClient là cố ý: ctx thắng trước.
	logShipTimeout = 10 * time.Second

	// logCloud404Reason là `reason` mà `classifyDataConflict` gắn cho một 404.
	//
	// Tên nó đọc lệch ("order gone") vì nó được đặt cho các đường đẩy trỏ tới
	// MỘT tài nguyên Cloud có sẵn. Ở đây nghĩa của 404 phụ thuộc vào đường nào
	// gặp nó — xem `postLogBatch`.
	logCloud404Reason = "cloud_404_order_gone"
)

// LogShipStats phân biệt BỐN trạng thái, không phải hai.
//
// Cùng lý do đã ghi ở #2695 và #2885: một endpoint fail-open làm "chưa bao giờ
// gọi" và "gọi rồi nhưng hỏng" trông y hệt nhau từ ngoài, và chính sự giống
// nhau đó giấu một đường đứt suốt nhiều tháng (0 hàng `workstation.alert` trên
// production).
type LogShipStats struct {
	// OK/Failed đếm LƯỢT POST, không đếm bản ghi.
	OK     int64 `json:"ok"`
	Failed int64 `json:"failed"`
	// NotDeployed là số lượt Cloud trả 404 cho đường HỎI — backend #2901 chưa
	// lên. Tách khỏi `Failed` vì hợp đồng nói backend deploy TRƯỚC, nên cửa sổ
	// giữa hai lần deploy là trạng thái ĐÃ THIẾT KẾ, không phải sự cố.
	NotDeployed int64 `json:"not_deployed"`
	// Skipped là những vòng KHÔNG phát sinh POST — gồm cả ca THƯỜNG NGÀY: không
	// có yêu cầu treo.
	Skipped int64 `json:"skipped"`
	// RecordsSent đếm BẢN GHI đã được Cloud xác nhận (2xx).
	RecordsSent int64 `json:"records_sent"`
	// RequestsClosed là số yêu cầu đã phục vụ xong (gửi lô `final`, hoặc Cloud
	// nói nó không còn nữa).
	RequestsClosed int64 `json:"requests_closed"`

	LastOKAt       string `json:"last_ok_at,omitempty"`
	LastFailedAt   string `json:"last_failed_at,omitempty"`
	LastError      string `json:"last_error,omitempty"`
	LastSkipReason string `json:"last_skip_reason,omitempty"`
}

type logShipCounters struct {
	mu         stdsync.Mutex
	stats      LogShipStats
	lastShipAt time.Time
}

// cloudLogRequest là một yêu cầu điều tra do HQ mở.
//
// Chỉ có ba chiều tự do, và đó là toàn bộ điểm: cửa sổ thời gian + trần số bản
// ghi. Không có tên bảng, không có câu truy vấn, không có đường dẫn file —
// không có chỗ nào để một yêu cầu bảo máy trạm làm bất cứ việc gì khác.
type cloudLogRequest struct {
	ID         string `json:"id"`
	From       string `json:"from"`
	To         string `json:"to"`
	MaxRecords int    `json:"max_records"`
}

// LogShipStats trả ảnh chụp bộ đếm cho panel. Nil-safe: nhiều test dựng Server
// không có sync engine.
func (e *SyncEngine) LogShipStats() LogShipStats {
	if e == nil {
		return LogShipStats{}
	}
	e.logShip.mu.Lock()
	defer e.logShip.mu.Unlock()

	return e.logShip.stats
}

func (e *SyncEngine) noteLogShipSkipped(reason string) {
	e.logShip.mu.Lock()
	defer e.logShip.mu.Unlock()

	e.logShip.stats.Skipped++
	e.logShip.stats.LastSkipReason = reason
}

func (e *SyncEngine) noteLogShipOK(sent int, closed bool) {
	e.logShip.mu.Lock()
	defer e.logShip.mu.Unlock()

	e.logShip.stats.OK++
	e.logShip.stats.RecordsSent += int64(sent)
	if closed {
		e.logShip.stats.RequestsClosed++
	}
	e.logShip.stats.LastOKAt = time.Now().UTC().Format(time.RFC3339)
	e.logShip.stats.LastError = ""
}

func (e *SyncEngine) noteLogShipFailed(reason string) {
	e.logShip.mu.Lock()
	defer e.logShip.mu.Unlock()

	e.logShip.stats.Failed++
	e.logShip.stats.LastFailedAt = time.Now().UTC().Format(time.RFC3339)
	e.logShip.stats.LastError = reason
}

// noteLogShipNotDeployed ghi nhận 404 — backend #2901 chưa lên.
//
// KHÔNG chạm `Failed`, KHÔNG dựng alert, KHÔNG log ở mức Error: hợp đồng nói
// backend deploy TRƯỚC, nên cửa sổ 404 đã được lường. Vẫn ĐẾM, vì "im lặng"
// khác "vô hình": con số này còn tăng nhiều ngày sau khi backend đã lên nghĩa
// là route đang sai, và đó là thứ phải nhìn thấy được.
func (e *SyncEngine) noteLogShipNotDeployed() {
	e.logShip.mu.Lock()
	defer e.logShip.mu.Unlock()

	e.logShip.stats.NotDeployed++
	e.logShip.stats.LastSkipReason = "Cloud chưa có endpoint (404) — backend #2901 chưa deploy"
}

// maybeShipLogsUp là điểm gọi từ vòng tick — có tiết chế nhịp.
func (e *SyncEngine) maybeShipLogsUp() {
	if e == nil {
		return
	}

	now := time.Now()
	e.logShip.mu.Lock()
	if !e.logShip.lastShipAt.IsZero() && now.Sub(e.logShip.lastShipAt) < logShipInterval {
		e.logShip.mu.Unlock()

		return
	}
	e.logShip.lastShipAt = now
	e.logShip.mu.Unlock()

	ctx, cancel := context.WithTimeout(context.Background(), logShipTimeout)
	defer cancel()

	e.ShipLogsUp(ctx)
}

// ShipLogsUp hỏi Cloud có yêu cầu treo không, và nếu có thì đẩy ĐÚNG MỘT LÔ.
//
// KHÔNG trả lỗi, và đó là hợp đồng chứ không phải cẩu thả: một lô log không đi
// được vẫn nằm nguyên trong SQLite và sẽ đi ở lượt sau. Để nó làm hỏng vòng
// đồng bộ là đánh đổi sai chiều — không có câu hỏi vận hành nào đáng giá bằng
// một đường đẩy tiền đang chạy.
//
// Một lô mỗi lượt, không phải vòng lặp cho tới hết: 500 dòng là một request
// nặng, và ba mươi lượt liên tiếp trong một nhịp tick sẽ đẩy chính máy trạm vào
// throttle của Cloud — đúng backpressure mà cả file này tồn tại để tránh.
func (e *SyncEngine) ShipLogsUp(ctx context.Context) {
	if e == nil {
		return
	}

	// Fail-open tới tận cùng: một panic ở đây (JSON lạ, kiểu dữ liệu lạ trong
	// một cột) không được giết goroutine của vòng sync.
	defer func() {
		if r := recover(); r != nil {
			e.noteLogShipFailed("panic khi đẩy log")
		}
	}()

	if e.db == nil {
		e.noteLogShipSkipped("chưa có store cục bộ")

		return
	}

	// Chưa ghép cặp thì không có device token và mọi request sẽ 401. Đếm nó là
	// `Failed` sẽ biến một máy đang chờ ghép cặp thành một báo động đường truyền.
	if e.deviceToken() == "" {
		e.noteLogShipSkipped("chưa ghép cặp — không có device token")

		return
	}

	// Cloud đang bảo lùi thì đừng chồng thêm request nào — kể cả một GET rẻ.
	if e.inCooldown() {
		e.noteLogShipSkipped("Cloud đang backpressure — hoãn lượt hỏi")

		return
	}

	requests, status, err := e.fetchLogRequests(ctx)
	switch {
	case status == http.StatusNotFound:
		e.noteLogShipNotDeployed()

		return
	case err != nil:
		e.noteLogShipFailed("không hỏi được yêu cầu log: " + err.Error())

		return
	}

	req, ok := e.nextOpenLogRequest(requests)
	if !ok {
		// ĐƯỜNG THƯỜNG NGÀY. Không POST gì cả.
		e.noteLogShipSkipped("không có yêu cầu log nào đang treo")

		return
	}

	e.shipOneLogBatch(ctx, req)
}

// fetchLogRequests hỏi Cloud bằng một client THÔ.
//
// Cố ý không đi qua `cloudPost`/`cloudGet` của puller: cả hai đều có tác dụng
// phụ lên trạng thái dùng chung (`noteThrottle`, `noteCloudSuccess`,
// `onUnauthorized` xoá device token). Một endpoint chẩn đoán không được phép
// làm bất cứ điều gì trong số đó — đặc biệt không được **xoá token của quán**
// vì một route chưa deploy trả 401/404.
func (e *SyncEngine) fetchLogRequests(ctx context.Context) ([]cloudLogRequest, int, error) {
	baseURL := e.resolveCloudURL()
	if baseURL == "" {
		return nil, 0, errors.New("cloud URL chưa cấu hình")
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, baseURL+logRequestsPath, nil)
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+e.deviceToken())

	resp, err := e.httpClient.Do(req)
	if err != nil {
		return nil, 0, err
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, resp.StatusCode, fmt.Errorf("cloud %d trên %s: %s", resp.StatusCode, logRequestsPath, string(body))
	}

	var parsed struct {
		Requests []cloudLogRequest `json:"requests"`
	}
	if err := json.Unmarshal(body, &parsed); err != nil {
		return nil, resp.StatusCode, err
	}

	return parsed.Requests, resp.StatusCode, nil
}

// nextOpenLogRequest chọn yêu cầu ĐẦU TIÊN còn phục vụ được.
//
// Bỏ qua những yêu cầu máy trạm đã tự đóng: Cloud có thể còn liệt kê một yêu
// cầu vài nhịp sau lô `final` (đóng ở đầu kia không tức thời), và gửi lại lô
// cuối mỗi phút cho tới khi Cloud kịp cập nhật là đốt băng thông của quán để
// nói một điều đã nói xong.
func (e *SyncEngine) nextOpenLogRequest(requests []cloudLogRequest) (cloudLogRequest, bool) {
	for _, r := range requests {
		if r.ID == "" {
			continue
		}
		if _, _, closed := e.logRequestProgress(r.ID); closed {
			continue
		}

		return r, true
	}

	return cloudLogRequest{}, false
}

// logRequestProgress đọc tiến độ của một yêu cầu.
func (e *SyncEngine) logRequestProgress(requestID string) (lastID int64, sent int, closed bool) {
	var closedAt string
	err := e.db.QueryRow(
		`SELECT last_local_id, sent_count, COALESCE(closed_at, '') FROM log_request_progress WHERE request_id = ?`,
		requestID,
	).Scan(&lastID, &sent, &closedAt)
	if err != nil {
		return 0, 0, false
	}

	return lastID, sent, closedAt != ""
}

// shipOneLogBatch gửi đúng MỘT lô cho một yêu cầu.
func (e *SyncEngine) shipOneLogBatch(ctx context.Context, req cloudLogRequest) {
	from, okFrom := logWindowBound(req.From)
	to, okTo := logWindowBound(req.To)
	if !okFrom || !okTo {
		// Không đoán cửa sổ. Một yêu cầu có `from`/`to` không đọc được mà vẫn
		// được phục vụ nghĩa là máy trạm tự chọn phạm vi — đúng thứ ràng buộc
		// "phạm vi cố định" cấm.
		e.closeLogRequest(req.ID)
		e.noteLogShipFailed("yêu cầu " + req.ID + " có cửa sổ thời gian không đọc được")

		return
	}

	lastID, sent, _ := e.logRequestProgress(req.ID)

	budget := logMaxRecordsCeiling
	if req.MaxRecords > 0 && req.MaxRecords < budget {
		budget = req.MaxRecords
	}
	remaining := budget - sent
	if remaining <= 0 {
		// Đã đủ trần: vẫn phải gửi một lô `final` để Cloud ĐÓNG yêu cầu. Bỏ dở
		// ở đây để nó treo vĩnh viễn ở HQ là đúng bẫy "mẫu số bằng không" —
		// người điều tra nhìn một danh sách cụt mà không có gì nói là nó cụt.
		e.postLogBatch(ctx, req.ID, nil, lastID, true)

		return
	}

	limit := min(logPushBatchSize, remaining)

	// Đọc THÊM một hàng để biết còn nữa hay không, mà không phải chạy một câu
	// `COUNT` thứ hai trên cùng cửa sổ.
	rows, err := e.db.Query(
		`SELECT id, logged_at, level, message, attrs
		   FROM log_records
		  WHERE id > ? AND logged_at >= ? AND logged_at <= ?
		  ORDER BY id
		  LIMIT ?`,
		lastID, from, to, limit+1,
	)
	if err != nil {
		e.noteLogShipFailed("không đọc được vòng đệm log: " + err.Error())

		return
	}

	type pendingLogRow struct {
		id                            int64
		loggedAt, level, message, raw string
	}
	batch := make([]pendingLogRow, 0, limit+1)
	for rows.Next() {
		var r pendingLogRow
		if err := rows.Scan(&r.id, &r.loggedAt, &r.level, &r.message, &r.raw); err != nil {
			continue
		}
		batch = append(batch, r)
	}
	rows.Close()

	hasMore := len(batch) > limit
	if hasMore {
		batch = batch[:limit]
	}

	wire := make([]map[string]any, 0, len(batch))
	var highestID int64 = lastID
	for _, r := range batch {
		// Con trỏ tiến qua MỌI hàng đã đọc, kể cả hàng bị loại. Không thế thì
		// một hàng hỏng ở id thấp chiếm chỗ trong mọi lô mãi mãi và chặn đứng
		// phần còn lại — đúng ca đói mà #2885 đã phải nới cửa sổ đọc để tránh.
		highestID = r.id

		body, ok := logRecordWire(r.id, r.loggedAt, r.level, r.message, r.raw)
		if !ok {
			continue
		}
		wire = append(wire, body)
	}

	final := !hasMore || sent+len(batch) >= budget
	e.postLogBatch(ctx, req.ID, wire, highestID, final)
}

// postLogBatch gửi lô rồi ghi tiến độ CHỈ KHI Cloud trả 2xx.
func (e *SyncEngine) postLogBatch(ctx context.Context, requestID string, records []map[string]any, highestID int64, final bool) {
	if records == nil {
		records = []map[string]any{}
	}

	err := e.sideChannelPost(ctx, logRecordsPath, map[string]any{
		"request_id": requestID,
		"final":      final,
		"records":    records,
	})
	if err == nil {
		e.saveLogRequestProgress(requestID, highestID, len(records), final)
		e.noteLogShipOK(len(records), final)

		return
	}

	// 404 ở ĐÂY khác 404 ở đường hỏi: route đã tồn tại (nếu không thì lượt GET
	// phía trên đã trả về rồi), nên nó chỉ có thể nghĩa là **yêu cầu không còn**
	// — không tồn tại, đã đóng, hoặc đã hết hạn. Hợp đồng nói máy trạm coi đó là
	// "thôi, bỏ qua": KHÔNG alert, KHÔNG coi là sự cố. Đóng cục bộ để không hỏi
	// lại mỗi phút mãi mãi.
	var conflict *dataConflictError
	if errors.As(err, &conflict) && conflict.reason == logCloud404Reason {
		e.closeLogRequest(requestID)
		e.noteLogShipSkipped("yêu cầu log đã đóng/hết hạn ở Cloud — bỏ qua")

		return
	}

	// Mọi thứ còn lại — timeout, 5xx, lỗi mạng, 422 — KHÔNG ghi tiến độ, KHÔNG
	// đóng. Lượt sau gửi lại đúng lô đó: Cloud khử trùng theo
	// `(device_id, local_id)` nên gửi trùng vô hại, còn tiến quá một lô chưa
	// tới nơi thì tạo một lỗ hổng im lặng trong bằng chứng.
	//
	// 422 (payload sai hình dạng · thiết bị chưa gắn chi nhánh · `request_id`
	// không thuộc thiết bị này) CỐ Ý không có nhánh riêng, dù thử lại không sửa
	// được nó. Lý do: yêu cầu ở Cloud có hạn, nên một 422 dai dẳng tự kết thúc
	// bằng 404 khi yêu cầu hết hạn — và nhánh 404 ngay trên đóng nó. Thêm một
	// cơ chế bỏ cuộc thứ hai ở đây là thêm một chỗ nữa để lệch với đầu kia.
	e.noteLogShipFailed(err.Error())
}

// saveLogRequestProgress ghi con trỏ + số đã gửi, và đóng khi đó là lô cuối.
//
// Ghi hỏng ở đây KHÔNG được làm gãy vòng sync và cũng không đảo ngược lượt gửi:
// Cloud ĐÃ nhận, và hậu quả duy nhất là lượt sau gửi trùng — thứ mà khử trùng
// `(device_id, local_id)` nuốt gọn.
func (e *SyncEngine) saveLogRequestProgress(requestID string, lastID int64, sent int, final bool) {
	now := time.Now().UTC().Format(time.RFC3339)
	closedAt := any(nil)
	if final {
		closedAt = now
	}

	_, _ = e.db.Exec(
		`INSERT INTO log_request_progress (request_id, last_local_id, sent_count, closed_at, updated_at)
		 VALUES (?, ?, ?, ?, ?)
		 ON CONFLICT (request_id) DO UPDATE SET
		     last_local_id = MAX(excluded.last_local_id, log_request_progress.last_local_id),
		     sent_count    = log_request_progress.sent_count + excluded.sent_count,
		     closed_at     = COALESCE(log_request_progress.closed_at, excluded.closed_at),
		     updated_at    = excluded.updated_at`,
		requestID, lastID, sent, closedAt, now,
	)
}

// closeLogRequest đóng một yêu cầu tại máy trạm mà không gửi thêm gì.
func (e *SyncEngine) closeLogRequest(requestID string) {
	now := time.Now().UTC().Format(time.RFC3339)
	_, _ = e.db.Exec(
		`INSERT INTO log_request_progress (request_id, last_local_id, sent_count, closed_at, updated_at)
		 VALUES (?, 0, 0, ?, ?)
		 ON CONFLICT (request_id) DO UPDATE SET
		     closed_at  = COALESCE(log_request_progress.closed_at, excluded.closed_at),
		     updated_at = excluded.updated_at`,
		requestID, now, now,
	)
}

// logRecordWire dựng ĐÚNG hình dạng hợp đồng wire cho một bản ghi.
//
// Ba phép kiểm lặp lại chính những gì handler đã cưỡng chế lúc GHI, và sự lặp
// đó là cố ý: giữa lúc ghi và lúc gửi có thể có một bản cũ của handler, một lần
// sửa tay database, hay một công cụ khôi phục. Cloud cũng kiểm lần ba theo cùng
// bảng — hai lớp cùng luật, vì Cloud không được tin máy trạm đã lọc đúng.
func logRecordWire(id int64, loggedAt, level, message, rawAttrs string) (map[string]any, bool) {
	switch level {
	case "info", "warn", "error":
	default:
		// `debug` KHÔNG được gửi; Cloud trả 422 cho nó, và một dòng làm rơi cả
		// lô là thứ hợp đồng cố ý tránh.
		return nil, false
	}

	allowed, ok := logAllowedAttrs(message)
	if !ok {
		return nil, false
	}

	stamp, ok := logWindowBound(loggedAt)
	if !ok {
		return nil, false
	}

	attrs := map[string]any{}
	if rawAttrs != "" {
		var parsed map[string]any
		if err := json.Unmarshal([]byte(rawAttrs), &parsed); err == nil {
			for k, v := range parsed {
				if _, ok := allowed[k]; ok {
					attrs[k] = v
				}
			}
		}
	}

	return map[string]any{
		// `local_id` là `id` autoincrement CỤC BỘ, gửi nguyên — đó là khoá
		// idempotency phía Cloud, cặp với device id. Sinh một id mới ở đây sẽ vô
		// hiệu hoá khử trùng và mỗi lần gửi lại đẻ một bản sao.
		"local_id":  id,
		"logged_at": stamp,
		"level":     level,
		"message":   message,
		"attrs":     attrs,
	}, true
}

// logWindowBound chuẩn hoá một dấu thời gian về RFC3339 **UTC**.
//
// Hợp đồng nói UTC (`Z`) và một offset địa phương lọt lên sẽ làm HQ xếp sai thứ
// tự các dòng giữa quán VN (UTC+7) và quán JP (UTC+9) — đúng loại lỗi mà #1091
// tồn tại để chặn. Đọc không được thì TỪ CHỐI, không bịa: một dấu thời gian bịa
// tệ hơn không có, vì nó sẽ được tin.
func logWindowBound(raw string) (string, bool) {
	if raw == "" {
		return "", false
	}
	for _, layout := range []string{time.RFC3339Nano, time.RFC3339} {
		if t, err := time.Parse(layout, raw); err == nil {
			return t.UTC().Format(time.RFC3339), true
		}
	}

	return "", false
}
