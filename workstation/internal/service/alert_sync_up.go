package service

import (
	"context"
	"sync"
	"time"
)

// #2695 (#2694 lỗ 1) — đẩy alert đang mở lên HQ.
//
// # Vì sao file này phải tồn tại
//
// Sổ đăng ký alert (`alertRegistry`) đã khai `SyncUp` từ #1806 S3, và Cloud đã
// có endpoint nhận (`POST /api/v1/workstation/alerts` → `AlertController`).
// Nhưng phía Go **chưa bao giờ cài đường gọi**: `git grep "/alerts"` trong
// `internal/` chỉ ra ba route CỤC BỘ (list · ack · ack-kind). Đo trên
// production 2026-08-13: **0** hàng `workstation.alert` trong `notifications`
// trên tổng 363 thông báo. Cờ `SyncUp: true` của bốn kind là một lời hứa chưa
// ai thực hiện.
//
// # Allowlist ở ĐÂY là cổng duy nhất
//
// Cloud **cố ý không lọc lần hai** (docblock của `AlertController`: hai danh
// sách cho cùng một câu hỏi thì chúng sẽ lệch nhau). Nên mọi kind lọt qua
// `AlertSyncsUp` đi thẳng vào nền tảng thông báo của HQ. Rò ở đây không dừng
// lại ở đây.
//
// # Vì sao đếm, và đếm ba loại chứ không hai
//
// Endpoint fail-open theo thiết kế — hỏng không được chặn vòng đồng bộ. Hệ quả
// là **"chưa bao giờ gọi" và "gọi rồi nhưng hỏng" trông y hệt nhau từ ngoài**,
// và chính sự giống nhau đó giấu con số 0 ở trên suốt nhiều tháng. Bộ đếm phân
// biệt được ba trạng thái, không phải hai:
//
//   - `ok == 0 && failed == 0 && skipped == 0` → vòng đẩy **chưa từng chạy**
//     (chưa nối, hoặc build cũ). Đây là trạng thái đã tồn tại trên production.
//   - `ok == 0 && failed > 0` → **có gọi, Cloud từ chối/không tới được**;
//     `last_error` nói vì sao.
//   - `skipped > 0` mà `ok+failed == 0` → vòng CÓ chạy nhưng không có gì để
//     gửi (không alert nào thuộc allowlist, hoặc máy chưa ghép cặp).
//     `last_skip_reason` phân biệt hai vế đó.
//
// Gộp "không có gì để gửi" vào `failed` sẽ làm bộ đếm kêu oan mỗi phút ở một
// quán hoàn toàn khoẻ mạnh — và một bộ đếm kêu oan thì bị tắt, không bị tranh
// luận.
const (
	alertPushPath = "/api/v1/workstation/alerts"

	// alertPushBatchSize khớp `max:50` phía Cloud. Vượt ngưỡng thì Cloud trả
	// 422 và **cả lô** rơi, kể cả alert quan trọng nhất — nên cắt ở đây.
	alertPushBatchSize = 50

	// alertPushInterval là khoảng cách tối thiểu giữa hai lượt đẩy.
	//
	// KHÔNG đẩy theo nhịp 5s của vòng sync: đây là ảnh chụp trạng thái, không
	// phải hàng đợi sự kiện — một máy in offline suốt buổi tối sẽ đốt 720
	// request/giờ trong ngân sách 250 req/phút của thiết bị mà không nói thêm
	// điều gì mới. Cloud khử trùng theo NGÀY NGHIỆP VỤ, nên đẩy dày hơn cũng
	// không tạo thêm thông báo nào.
	alertPushInterval = time.Minute

	// alertPushTimeout — đẩy alert không được giữ vòng sync lâu hơn một lượt
	// tick. Ngắn hơn timeout 15s của httpClient là cố ý: ctx thắng trước.
	alertPushTimeout = 10 * time.Second
)

// AlertPushStats là phép đo phân biệt "chưa gọi" với "gọi mà hỏng".
type AlertPushStats struct {
	// OK/Failed đếm LƯỢT POST, không đếm alert: một lô 5 alert hỏng là MỘT
	// lần đường lên hỏng, không phải năm.
	OK     int64 `json:"ok"`
	Failed int64 `json:"failed"`
	// Skipped là những vòng KHÔNG phát sinh POST (không có alert thuộc
	// allowlist, chưa ghép cặp, chưa nối alert centre).
	Skipped int64 `json:"skipped"`
	// AlertsSent là tổng số alert đã đi trong các lượt OK — cái này đếm alert.
	AlertsSent int64 `json:"alerts_sent"`

	LastOKAt       string `json:"last_ok_at,omitempty"`
	LastFailedAt   string `json:"last_failed_at,omitempty"`
	LastError      string `json:"last_error,omitempty"`
	LastSkipReason string `json:"last_skip_reason,omitempty"`
}

// alertPushCounters giữ trạng thái đếm + nhịp. Nằm trong SyncEngine (giá trị,
// không con trỏ) vì engine luôn dùng qua con trỏ.
type alertPushCounters struct {
	mu         sync.Mutex
	stats      AlertPushStats
	lastPushAt time.Time
}

// AlertPushStats trả ảnh chụp bộ đếm cho panel. Nil-safe: nhiều test dựng
// Server không có sync engine.
func (e *SyncEngine) AlertPushStats() AlertPushStats {
	if e == nil {
		return AlertPushStats{}
	}
	e.alertPush.mu.Lock()
	defer e.alertPush.mu.Unlock()

	return e.alertPush.stats
}

func (e *SyncEngine) noteAlertPushSkipped(reason string) {
	e.alertPush.mu.Lock()
	defer e.alertPush.mu.Unlock()

	e.alertPush.stats.Skipped++
	e.alertPush.stats.LastSkipReason = reason
}

func (e *SyncEngine) noteAlertPushOK(sent int) {
	e.alertPush.mu.Lock()
	defer e.alertPush.mu.Unlock()

	e.alertPush.stats.OK++
	e.alertPush.stats.AlertsSent += int64(sent)
	e.alertPush.stats.LastOKAt = time.Now().UTC().Format(time.RFC3339)
	e.alertPush.stats.LastError = ""
}

func (e *SyncEngine) noteAlertPushFailed(reason string) {
	e.alertPush.mu.Lock()
	defer e.alertPush.mu.Unlock()

	e.alertPush.stats.Failed++
	e.alertPush.stats.LastFailedAt = time.Now().UTC().Format(time.RFC3339)
	e.alertPush.stats.LastError = reason
}

// maybePushAlertsUp là điểm gọi từ vòng tick — có tiết chế nhịp.
func (e *SyncEngine) maybePushAlertsUp() {
	if e == nil {
		return
	}

	now := time.Now()
	e.alertPush.mu.Lock()
	if !e.alertPush.lastPushAt.IsZero() && now.Sub(e.alertPush.lastPushAt) < alertPushInterval {
		e.alertPush.mu.Unlock()

		return
	}
	e.alertPush.lastPushAt = now
	e.alertPush.mu.Unlock()

	ctx, cancel := context.WithTimeout(context.Background(), alertPushTimeout)
	defer cancel()

	e.PushAlertsUp(ctx)
}

// PushAlertsUp đẩy ảnh chụp alert đang mở (thuộc allowlist sync-UP) lên HQ.
//
// KHÔNG trả lỗi, và đó là hợp đồng chứ không phải cẩu thả: một alert không đẩy
// lên được vẫn nằm nguyên trong SQLite và vẫn hiện trên panel tại quán; để nó
// làm hỏng vòng đồng bộ là đánh đổi sai chiều. Thứ duy nhất một lần hỏng để
// lại là bộ đếm `failed` — và bộ đếm đó chính là lý do #2695 tồn tại.
func (e *SyncEngine) PushAlertsUp(ctx context.Context) {
	if e == nil {
		return
	}

	// Fail-open tới tận cùng: một panic ở đây (JSON lạ, con trỏ nil trong một
	// nhánh chưa lường) không được giết goroutine của vòng sync — nó chỉ được
	// mất đúng lượt đẩy này, và để lại vết ở bộ đếm.
	defer func() {
		if r := recover(); r != nil {
			e.noteAlertPushFailed("panic khi đẩy alert")
		}
	}()

	if e.alerts == nil {
		e.noteAlertPushSkipped("alert centre chưa nối")

		return
	}

	open, err := e.alerts.ListOpen()
	if err != nil {
		// Đọc store cục bộ hỏng — không phải đường lên hỏng. Đếm vào `failed`
		// sẽ chỉ nhầm chỗ người đi tìm.
		e.noteAlertPushSkipped("không đọc được alert cục bộ: " + err.Error())

		return
	}

	batch := make([]map[string]any, 0, alertPushBatchSize)
	for _, a := range open {
		// CỔNG DUY NHẤT. Cloud không lọc lần hai.
		if !AlertSyncsUp(a.Kind) {
			continue
		}

		batch = append(batch, alertPushBody(a))
		if len(batch) == alertPushBatchSize {
			break
		}
	}

	if len(batch) == 0 {
		e.noteAlertPushSkipped("không có alert nào thuộc allowlist sync-UP")

		return
	}

	// Chưa ghép cặp thì không có device token, và mọi request sẽ 401. Đây là
	// cùng lý do `not_paired` khai `SyncUp: false` — đếm nó là `failed` sẽ biến
	// một máy đang chờ ghép cặp thành một báo động đường truyền.
	if e.deviceToken() == "" {
		e.noteAlertPushSkipped("chưa ghép cặp — không có device token")

		return
	}

	// Cloud đang bảo lùi thì đừng chồng thêm một request nữa. Đây là chiều
	// ĐÚNG của quan hệ với backpressure: đường đẩy alert TÔN TRỌNG cooldown,
	// nhưng không được phép TẠO ra nó — xem `alertCloudPost`.
	if e.inCooldown() {
		e.noteAlertPushSkipped("Cloud đang backpressure — hoãn lượt đẩy")

		return
	}

	if err := e.alertCloudPost(ctx, batch); err != nil {
		e.noteAlertPushFailed(err.Error())

		return
	}

	e.noteAlertPushOK(len(batch))
}

// alertCloudPost gọi Cloud qua ĐÚNG `cloudPost` như mọi đường đẩy khác, nhưng
// KHÔNG cho một lượt hỏng ở đây đổi trạng thái backpressure dùng chung.
//
// Đo được ở #2695 khi viết test fail-open: `cloudPost` gọi `noteThrottle` trên
// mọi 5xx, và `noteThrottle` đặt `cooldownUntil` toàn cục → `processQueue` bỏ
// NGUYÊN vòng drain. Nghĩa là một endpoint **cảnh báo** hỏng sẽ chặn đường đẩy
// **tiền** tới 5 phút (backoff luỹ thừa, escalate theo số lần liên tiếp). Đó
// đúng là điều docblock của `AlertController` cấm: "hỏng ở đây không được chặn
// vòng đồng bộ". Fail-open không chỉ là "không ném lỗi" — nó là không để lại
// tác dụng phụ nào lên đường chính.
//
// Thành công thì GIỮ NGUYÊN `noteCloudSuccess`: một 2xx từ Cloud là bằng chứng
// thật rằng Cloud đang sống, bất kể nó tới từ endpoint nào.
func (e *SyncEngine) alertCloudPost(ctx context.Context, batch []map[string]any) error {
	return e.sideChannelPost(ctx, alertPushPath, map[string]any{"alerts": batch})
}

// sideChannelPost là `cloudPost` cho các đường đẩy PHỤ — alert (#2695), sổ máy
// thu tiền (#2878) — với đúng một khác biệt: một lượt hỏng ở đây KHÔNG được
// đổi trạng thái backpressure dùng chung.
//
// Đo được ở #2695: `cloudPost` gọi `noteThrottle` trên mọi 5xx, và `noteThrottle`
// đặt `cooldownUntil` toàn cục → `processQueue` bỏ NGUYÊN vòng drain. Nghĩa là
// một endpoint phụ hỏng sẽ chặn đường đẩy ĐƠN HÀNG và THANH TOÁN. Chiều đúng
// của quan hệ là: các đường phụ TÔN TRỌNG cooldown, nhưng không TẠO ra nó.
//
// Một hiện thực, không phải hai — luật này chép đôi thì hai bản sẽ lệch nhau.
func (e *SyncEngine) sideChannelPost(ctx context.Context, path string, payload map[string]any) error {
	e.rlMu.Lock()
	savedCooldown := e.cooldownUntil
	savedThrottles := e.consecutiveThrottles
	e.rlMu.Unlock()

	// bearer rỗng là cố ý: `cloudPost` tự dập device token hiện hành lên mọi
	// route `/api/v1/workstation/*` (xem ghi chú ở đó), nên token không bao giờ
	// cũ sau một lần ghép cặp lại.
	//
	// Idempotency-Key cũng rỗng: khử trùng của đường này nằm ở Cloud, theo
	// (branch, kind, subject, NGÀY NGHIỆP VỤ) — một khoá do máy trạm sinh sẽ
	// vô hiệu hoá nó và HQ nhận đúp mỗi lượt đẩy.
	_, _, err := e.cloudPost(ctx, path, "", "", payload)
	if err == nil {
		return nil
	}

	e.rlMu.Lock()
	e.cooldownUntil = savedCooldown
	e.consecutiveThrottles = savedThrottles
	e.rlMu.Unlock()

	return err
}

// alertPushBody dựng đúng những trường Cloud validate.
//
// KHÔNG gửi `detail`: `$request->validate()` strip mọi key không có rule
// (#2622), nên nó sẽ đi hết đường dây rồi biến mất — một trường trông như đã
// gửi mà không tới nơi tệ hơn một trường không gửi. `count`/`first_seen_at` là
// ảnh chụp lúc đẩy, không phải toàn bộ lịch sử: lịch sử ở lại máy trạm (quyết
// định đã ghi trong docblock của `AlertController`).
func alertPushBody(a Alert) map[string]any {
	count := a.Count
	if count < 1 {
		// Cloud: `min:1`. Một hàng count=0 không tồn tại trong `alerts` (Raise
		// chèn 1), nhưng một số 0 lọt lên sẽ đánh rơi CẢ LÔ.
		count = 1
	}

	return map[string]any{
		"kind":          clampAlertField(string(a.Kind), 64),
		"subject":       clampAlertField(orUnknownSubject(a.Subject), 255),
		"severity":      string(a.Severity),
		"title":         clampAlertField(orUnknownTitle(a.Title, a.Kind), 255),
		"count":         count,
		"first_seen_at": a.FirstSeenAt.UTC().Format(time.RFC3339),
	}
}

// Cloud validate `required` + `max:` trên TỪNG phần tử, và một phần tử hỏng làm
// **cả lô** 422 — kể cả alert nặng nhất trong đó. Máy trạm có thật những alert
// subject rỗng: `cash_retained` dựng từ một lượt thu Glory hỏng TRƯỚC khi có mã
// giao dịch (`out.TransactionID == ""`). Đó chính là loại alert không được phép
// rơi, nên chuẩn hoá tại đây thay vì phó mặc cho validator ở đầu kia.
func orUnknownSubject(subject string) string {
	if subject == "" {
		// Một giá trị CỐ ĐỊNH, không phải uuid ngẫu nhiên: khoá khử trùng của
		// Cloud gồm subject, nên một chuỗi mới mỗi lượt đẩy sẽ biến một sự cố
		// thành một thông báo mỗi phút.
		return "unknown"
	}

	return subject
}

func orUnknownTitle(title string, kind AlertKind) string {
	if title == "" {
		return string(kind)
	}

	return title
}

// clampAlertField cắt theo RUNE, không theo byte: tiêu đề ở đây là tiếng Nhật
// và tiếng Việt, và cắt giữa một rune sinh ra chuỗi UTF-8 hỏng mà Laravel từ
// chối — lại đánh rơi cả lô.
func clampAlertField(s string, max int) string {
	r := []rune(s)
	if len(r) <= max {
		return s
	}

	return string(r[:max])
}
