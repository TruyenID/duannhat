package service

import (
	"context"
	"errors"
	"log/slog"
	"strings"
	"sync"
	"time"
)

// #2885 — đẩy bằng chứng lệch tiền (`order_money_overwrites`) lên Cloud.
//
// # Vì sao file này phải tồn tại
//
// Khi Cloud tính lại tiền của một đơn và ra số khác máy trạm, máy trạm ghi một
// dòng append-only vào `order_money_overwrites` (#2162, migration 080): ảnh
// chụp trước/sau đủ 5 trường tiền + `paid_locally` tại đúng thời điểm ấy. Dòng
// đó CHƯA BAO GIỜ rời khỏi máy.
//
// Đo được ngày 2026-08-15: để trả lời "ba cảnh báo `cloud_money_overwrite`
// đang treo là những đơn nào" phải (1) lần ngược `audit_logs` của Cloud bằng
// tay, (2) tra Stripe API, và (3) cuối cùng vẫn không phân định được — phải hỏi
// trí nhớ người. Quét sổ Cloud thì sạch: 464 đơn kết thúc, đúng 1 đơn lệch
// (ORD-2026-0018, đã giải thích). Nhưng **"sổ Cloud cân" không chứng minh máy
// trạm đồng ý** — cảnh báo đo khoảng lệch giữa HAI hệ, và một nửa dữ liệu nằm
// ngoài tầm với của HQ.
//
// # Đây KHÔNG phải đường tiền, và nó phải cư xử đúng như thế
//
// Cloud vẫn là sổ cái, vẫn tự tính lại giá từ ảnh chụp catalog, thiết bị vẫn
// không bao giờ tự khai giá (#1092). File này chỉ chuyển VẾT của khoảng lệch,
// không chuyển quyền quyết định. Hệ quả là mọi đánh đổi ở đây nghiêng về phía
// "thà mất lượt đẩy còn hơn động vào đường tiền".
//
// # Khác đường đẩy alert ở đúng một điểm: KHÔNG đẩy lại mãi
//
// `PushAlertsUp` đẩy lại MỌI alert đang mở mỗi phút — đó là ảnh chụp trạng
// thái, đẩy lại là đúng, và trên production nó là 4.721 dòng log/ngày. Bảng
// này là LỊCH SỬ: mỗi dòng đúng một lần, gửi xong đánh dấu `synced_at`, lượt
// sau không nhìn tới nữa (migration 088).
//
// # Ba trạng thái, không phải hai — và ở đây là BỐN
//
// Bài học đếm của #2695: một endpoint fail-open làm "chưa bao giờ gọi" và "gọi
// rồi nhưng hỏng" trông y hệt nhau từ ngoài, và chính sự giống nhau đó giấu
// một đường đứt suốt nhiều tháng. Bộ đếm dưới đây tách thêm một trạng thái nữa
// mà đường alert không có: **backend chưa deploy** (404). Backend #2885 lên
// TRƯỚC theo thiết kế, nên trong cửa sổ giữa hai lần deploy, 404 là trạng thái
// BÌNH THƯỜNG — đếm nó vào `Failed` sẽ dựng một báo động giả ở mọi quán, mỗi
// phút, cho tới khi backend lên.
const (
	moneyOverwritePushPath = "/api/v1/workstation/money-overwrites"

	// moneyOverwritePushBatchSize khớp `max:50` của hợp đồng wire. Vượt ngưỡng
	// thì Cloud trả 422 và CẢ LÔ rơi — kể cả dòng bằng chứng quan trọng nhất
	// trong đó — nên cắt tại đây, không phó mặc validator ở đầu kia.
	moneyOverwritePushBatchSize = 50

	// moneyOverwritePushInterval là khoảng cách tối thiểu giữa hai lượt đẩy.
	//
	// KHÔNG đẩy theo nhịp 5s của vòng sync. Một quán thật sinh vài dòng ghi đè
	// mỗi ngày (production 2026-08-15: 1 đơn lệch trên 464 đơn kết thúc), nên
	// nhịp dày chỉ đốt ngân sách 250 request/phút của thiết bị — ngân sách mà
	// đường đẩy TIỀN đang dùng chung.
	moneyOverwritePushInterval = time.Minute

	// moneyOverwritePushTimeout — một lượt đẩy bằng chứng không được giữ vòng
	// sync lâu hơn một nhịp tick. Ngắn hơn timeout 15s của httpClient là cố ý:
	// ctx thắng trước.
	moneyOverwritePushTimeout = 10 * time.Second

	// moneyOverwriteRouteMissingReason là `reason` mà `classifyDataConflict`
	// gắn cho một 404.
	//
	// Tên nó đọc lệch ở đây ("order gone") vì nó được đặt cho các đường đẩy
	// trỏ tới MỘT tài nguyên Cloud đã có sẵn. Endpoint này là COLLECTION —
	// không có id nào trong URL để mà "gone" — nên với riêng đường này, 404
	// chỉ có thể nghĩa là ROUTE CHƯA TỒN TẠI, tức backend chưa deploy.
	moneyOverwriteRouteMissingReason = "cloud_404_order_gone"
)

// MoneyOverwritePushStats là phép đo phân biệt bốn trạng thái của đường đẩy.
type MoneyOverwritePushStats struct {
	// OK/Failed/NotDeployed đếm LƯỢT POST, không đếm dòng: một lô 50 dòng hỏng
	// là MỘT lần đường lên hỏng, không phải năm mươi.
	OK     int64 `json:"ok"`
	Failed int64 `json:"failed"`
	// NotDeployed là số lượt Cloud trả 404 — backend #2885 chưa lên. Tách khỏi
	// `Failed` vì đây là trạng thái ĐÃ ĐƯỢC THIẾT KẾ, không phải sự cố.
	NotDeployed int64 `json:"not_deployed"`
	// Skipped là những vòng KHÔNG phát sinh POST (không có dòng chờ, chưa ghép
	// cặp, Cloud đang backpressure).
	Skipped int64 `json:"skipped"`
	// RowsSent là tổng số DÒNG đã được Cloud xác nhận nhận (2xx). Cái này đếm
	// dòng, và nó là con số duy nhất trả lời được "HQ đang giữ bao nhiêu bằng
	// chứng của máy này".
	RowsSent int64 `json:"rows_sent"`
	// Malformed đếm những dòng bị loại khỏi lô vì `created_at` không đọc được.
	// Phải > 0 mới đáng đi tìm — xem `moneyOverwriteOccurredAt`.
	Malformed int64 `json:"malformed"`

	LastOKAt       string `json:"last_ok_at,omitempty"`
	LastFailedAt   string `json:"last_failed_at,omitempty"`
	LastError      string `json:"last_error,omitempty"`
	LastSkipReason string `json:"last_skip_reason,omitempty"`
}

// moneyOverwritePushCounters giữ bộ đếm + nhịp + tập dòng hỏng. Nằm trong
// SyncEngine dưới dạng giá trị (không con trỏ) vì engine luôn dùng qua con trỏ.
type moneyOverwritePushCounters struct {
	mu         sync.Mutex
	stats      MoneyOverwritePushStats
	lastPushAt time.Time
	// malformed là các id đã xác định không dựng được payload hợp lệ.
	//
	// Nó tồn tại để chống ĐÓI, không phải để nhớ lỗi: một dòng hỏng nằm ở id
	// thấp sẽ chiếm một chỗ trong lô 50 mãi mãi, và 50 dòng như thế sẽ chặn
	// đứng mọi bằng chứng mới. Biết trước bao nhiêu dòng sẽ bị loại thì nới
	// đúng bấy nhiêu chỗ khi đọc, nên lô gửi đi vẫn đủ 50 dòng gửi được.
	malformed map[int64]struct{}
}

// pendingMoneyOverwrite là một dòng bằng chứng chưa gửi, đọc nguyên từ SQLite.
//
// Tiền là SỐ NGUYÊN đơn vị nhỏ nhất (JPY: yên) và CHO PHÉP ÂM — giảm giá là số
// âm hợp lệ, không phải dữ liệu hỏng. Không có phép làm tròn nào ở tầng này:
// làm tròn đã xảy ra lúc ghi (#2162), và làm tròn lần hai lên một bằng chứng là
// sửa bằng chứng.
type pendingMoneyOverwrite struct {
	id          int64
	orderID     string
	createdAt   string
	paidLocally int

	totalLocal, totalCloud       int
	subtotalLocal, subtotalCloud int
	taxLocal, taxCloud           int
	serviceLocal, serviceCloud   int
	discountLocal, discountCloud int
}

// MoneyOverwritePushStats trả ảnh chụp bộ đếm cho panel. Nil-safe: nhiều test
// dựng Server không có sync engine.
func (e *SyncEngine) MoneyOverwritePushStats() MoneyOverwritePushStats {
	if e == nil {
		return MoneyOverwritePushStats{}
	}
	e.moneyOverwritePush.mu.Lock()
	defer e.moneyOverwritePush.mu.Unlock()

	return e.moneyOverwritePush.stats
}

func (e *SyncEngine) noteMoneyOverwritePushSkipped(reason string) {
	e.moneyOverwritePush.mu.Lock()
	defer e.moneyOverwritePush.mu.Unlock()

	e.moneyOverwritePush.stats.Skipped++
	e.moneyOverwritePush.stats.LastSkipReason = reason
}

func (e *SyncEngine) noteMoneyOverwritePushOK(sent int) {
	e.moneyOverwritePush.mu.Lock()
	defer e.moneyOverwritePush.mu.Unlock()

	e.moneyOverwritePush.stats.OK++
	e.moneyOverwritePush.stats.RowsSent += int64(sent)
	e.moneyOverwritePush.stats.LastOKAt = time.Now().UTC().Format(time.RFC3339)
	e.moneyOverwritePush.stats.LastError = ""
}

func (e *SyncEngine) noteMoneyOverwritePushFailed(reason string) {
	e.moneyOverwritePush.mu.Lock()
	defer e.moneyOverwritePush.mu.Unlock()

	e.moneyOverwritePush.stats.Failed++
	e.moneyOverwritePush.stats.LastFailedAt = time.Now().UTC().Format(time.RFC3339)
	e.moneyOverwritePush.stats.LastError = reason
}

// noteMoneyOverwritePushNotDeployed ghi nhận một lượt 404 — backend chưa lên.
//
// KHÔNG chạm `Failed`, KHÔNG dựng alert, KHÔNG log ở mức Error: hợp đồng #2885
// nói backend deploy TRƯỚC, nên cửa sổ 404 là trạng thái đã lường trước. Vẫn
// đếm, vì "im lặng" khác "vô hình": nếu con số này còn tăng nhiều ngày sau khi
// backend đã lên thì route đang sai, và đó là thứ phải nhìn thấy được.
func (e *SyncEngine) noteMoneyOverwritePushNotDeployed() {
	e.moneyOverwritePush.mu.Lock()
	defer e.moneyOverwritePush.mu.Unlock()

	e.moneyOverwritePush.stats.NotDeployed++
	e.moneyOverwritePush.stats.LastSkipReason = "Cloud chưa có endpoint (404) — backend #2885 chưa deploy"
}

// noteMoneyOverwriteMalformed nhớ một dòng không dựng được payload hợp lệ.
func (e *SyncEngine) noteMoneyOverwriteMalformed(id int64) {
	e.moneyOverwritePush.mu.Lock()
	defer e.moneyOverwritePush.mu.Unlock()

	if e.moneyOverwritePush.malformed == nil {
		e.moneyOverwritePush.malformed = make(map[int64]struct{})
	}
	if _, seen := e.moneyOverwritePush.malformed[id]; seen {
		return
	}
	e.moneyOverwritePush.malformed[id] = struct{}{}
	e.moneyOverwritePush.stats.Malformed++
}

func (e *SyncEngine) moneyOverwriteMalformedCount() int {
	e.moneyOverwritePush.mu.Lock()
	defer e.moneyOverwritePush.mu.Unlock()

	return len(e.moneyOverwritePush.malformed)
}

// maybePushMoneyOverwritesUp là điểm gọi từ vòng tick — có tiết chế nhịp.
func (e *SyncEngine) maybePushMoneyOverwritesUp() {
	if e == nil {
		return
	}

	now := time.Now()
	e.moneyOverwritePush.mu.Lock()
	if !e.moneyOverwritePush.lastPushAt.IsZero() &&
		now.Sub(e.moneyOverwritePush.lastPushAt) < moneyOverwritePushInterval {
		e.moneyOverwritePush.mu.Unlock()

		return
	}
	e.moneyOverwritePush.lastPushAt = now
	e.moneyOverwritePush.mu.Unlock()

	ctx, cancel := context.WithTimeout(context.Background(), moneyOverwritePushTimeout)
	defer cancel()

	e.PushMoneyOverwritesUp(ctx)
}

// PushMoneyOverwritesUp gửi một lô bằng chứng chưa đồng bộ lên Cloud.
//
// KHÔNG trả lỗi, và đó là hợp đồng chứ không phải cẩu thả: một dòng không đẩy
// lên được vẫn nằm nguyên trong SQLite với `synced_at IS NULL` và sẽ đi ở lượt
// sau. Để nó làm hỏng vòng đồng bộ là đánh đổi sai chiều — bằng chứng về một
// khoảng lệch tiền không được phép chặn chính đường đẩy tiền.
func (e *SyncEngine) PushMoneyOverwritesUp(ctx context.Context) {
	if e == nil {
		return
	}

	// Fail-open tới tận cùng: một panic ở đây (kiểu dữ liệu lạ trong một cột,
	// con trỏ nil trong nhánh chưa lường) không được giết goroutine của vòng
	// sync — nó chỉ được mất đúng lượt đẩy này.
	defer func() {
		if r := recover(); r != nil {
			e.noteMoneyOverwritePushFailed("panic khi đẩy bằng chứng lệch tiền")
		}
	}()

	if e.db == nil {
		e.noteMoneyOverwritePushSkipped("chưa có store cục bộ")

		return
	}

	rows, err := e.pendingMoneyOverwrites()
	if err != nil {
		// Đọc store cục bộ hỏng — không phải đường lên hỏng. Đếm vào `Failed`
		// sẽ chỉ nhầm chỗ người đi tìm.
		e.noteMoneyOverwritePushSkipped("không đọc được bằng chứng cục bộ: " + err.Error())

		return
	}

	batch := make([]map[string]any, 0, moneyOverwritePushBatchSize)
	ids := make([]int64, 0, moneyOverwritePushBatchSize)
	for _, row := range rows {
		body, ok := moneyOverwritePushBody(row)
		if !ok {
			// Một phần tử hỏng làm CẢ LÔ 422 phía Cloud — kể cả dòng bằng
			// chứng nặng nhất trong đó. Loại tại đây, và nhớ lại để lượt sau
			// nới chỗ đọc (xem `malformed`).
			e.noteMoneyOverwriteMalformed(row.id)
			slog.Error("money overwrite evidence row has an unreadable created_at and cannot be shipped to HQ — it stays local forever until someone fixes the row",
				"local_id", row.id, "order", row.orderID, "created_at", row.createdAt)

			continue
		}

		batch = append(batch, body)
		ids = append(ids, row.id)
		// Trần lô LÀM VIỆC THẬT ở đây, không thừa so với `LIMIT` của câu truy
		// vấn: cửa sổ đọc được NỚI theo số dòng hỏng đã biết (xem
		// `pendingMoneyOverwrites`), nên khi những dòng ấy được sửa lại thì câu
		// truy vấn trả về NHIỀU HƠN 50 dòng gửi được. Không có `break` này thì
		// đúng lượt đó vượt `max:50` và Cloud trả 422 cho CẢ LÔ.
		if len(batch) == moneyOverwritePushBatchSize {
			break
		}
	}

	if len(batch) == 0 {
		// Đường im lặng của một quán khoẻ mạnh: hầu hết các ngày KHÔNG có dòng
		// nào. Không POST — đừng đánh thức Cloud để nói "tôi không có gì".
		e.noteMoneyOverwritePushSkipped("không có bằng chứng nào chờ đẩy")

		return
	}

	// Chưa ghép cặp thì không có device token và mọi request sẽ 401. Đếm nó là
	// `Failed` sẽ biến một máy đang chờ ghép cặp thành một báo động đường truyền.
	if e.deviceToken() == "" {
		e.noteMoneyOverwritePushSkipped("chưa ghép cặp — không có device token")

		return
	}

	// Cloud đang bảo lùi thì đừng chồng thêm một request nữa. Đây là chiều
	// ĐÚNG của quan hệ với backpressure: đường đẩy này TÔN TRỌNG cooldown,
	// nhưng không bao giờ được phép TẠO ra nó — xem `moneyOverwriteCloudPost`.
	if e.inCooldown() {
		e.noteMoneyOverwritePushSkipped("Cloud đang backpressure — hoãn lượt đẩy")

		return
	}

	if err := e.moneyOverwriteCloudPost(ctx, batch); err != nil {
		// 404 = backend #2885 chưa deploy. Hợp đồng nói backend lên TRƯỚC, nên
		// đây là cửa sổ đã lường: im lặng, giữ nguyên `synced_at IS NULL`, thử
		// lại lượt sau. KHÔNG đánh dấu, KHÔNG alert, KHÔNG coi là sự cố.
		var conflict *dataConflictError
		if errors.As(err, &conflict) && conflict.reason == moneyOverwriteRouteMissingReason {
			e.noteMoneyOverwritePushNotDeployed()

			return
		}

		e.noteMoneyOverwritePushFailed(err.Error())

		return
	}

	// CHỈ đánh dấu sau một 2xx. Timeout/5xx/lỗi mạng để nguyên — thà Cloud
	// nhận trùng (khử trùng theo `(device_id, local_id)`) còn hơn mất một dòng
	// bằng chứng, vì bằng chứng mất thì không có đường nào dựng lại được.
	e.markMoneyOverwritesSynced(ids)
	e.noteMoneyOverwritePushOK(len(batch))
}

// pendingMoneyOverwrites đọc các dòng `synced_at IS NULL`, cũ nhất trước.
//
// Nới hạn đọc theo số dòng đã biết là hỏng: chúng vẫn khớp vị ngữ và vẫn chiếm
// chỗ trong cửa sổ đọc, nên nếu không nới thì một dòng hỏng ở id thấp sẽ đứng
// mãi trong mọi lô và 50 dòng như thế chặn đứng mọi bằng chứng mới.
func (e *SyncEngine) pendingMoneyOverwrites() ([]pendingMoneyOverwrite, error) {
	limit := moneyOverwritePushBatchSize + e.moneyOverwriteMalformedCount()

	rows, err := e.db.Query(`SELECT
			id, order_id, created_at, paid_locally,
			total_amount_local, total_amount_cloud,
			subtotal_local, subtotal_cloud,
			tax_amount_local, tax_amount_cloud,
			service_charge_local, service_charge_cloud,
			discount_amount_local, discount_amount_cloud
		FROM order_money_overwrites
		WHERE synced_at IS NULL
		ORDER BY id
		LIMIT ?`, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	out := make([]pendingMoneyOverwrite, 0, limit)
	for rows.Next() {
		var r pendingMoneyOverwrite
		if err := rows.Scan(
			&r.id, &r.orderID, &r.createdAt, &r.paidLocally,
			&r.totalLocal, &r.totalCloud,
			&r.subtotalLocal, &r.subtotalCloud,
			&r.taxLocal, &r.taxCloud,
			&r.serviceLocal, &r.serviceCloud,
			&r.discountLocal, &r.discountCloud,
		); err != nil {
			return nil, err
		}
		out = append(out, r)
	}

	return out, rows.Err()
}

// moneyOverwritePushBody dựng ĐÚNG hình dạng hợp đồng wire (#2885).
//
// Cả 10 trường tiền LUÔN có mặt, kể cả khi bằng 0 và kể cả khi Cloud không đổi
// chúng: một dòng bằng chứng phải tự đứng được mà không join ngược về `orders`
// — bảng ấy đã bị ghi đè rồi, đó là toàn bộ lý do bảng này tồn tại. Vì thế
// KHÔNG có `omitempty` ở bất cứ đâu trong map này: một trường 0 bị nuốt biến
// "Cloud đồng ý, cả hai đều 0" thành "không biết", và người đọc sẽ tự điền vào
// chỗ trống bằng phỏng đoán.
//
// `local_id` là `id` autoincrement CỤC BỘ, gửi nguyên — đó là khoá idempotency
// phía Cloud, cặp với device id. Sinh một id mới ở đây sẽ vô hiệu hoá khử
// trùng, và mỗi lần gửi lại (mất điện giữa 2xx và UPDATE) sẽ đẻ một bản sao mà
// HQ đọc thành hai lần lệch tiền khác nhau.
func moneyOverwritePushBody(r pendingMoneyOverwrite) (map[string]any, bool) {
	occurredAt, ok := moneyOverwriteOccurredAt(r.createdAt)
	if !ok {
		return nil, false
	}

	return map[string]any{
		"local_id":    r.id,
		"order_id":    r.orderID,
		"occurred_at": occurredAt,
		// Snapshot tiền đã vào két LÚC ghi đè — nó quyết định khoảng lệch là
		// 過不足 thật hay mới chỉ là một tờ phiếu sắp in sai. Đọc lại từ
		// payments lúc đối soát cho số KHÁC, vì số đó đổi theo thời gian.
		"paid_locally": r.paidLocally,

		"total_amount_local":    r.totalLocal,
		"total_amount_cloud":    r.totalCloud,
		"subtotal_local":        r.subtotalLocal,
		"subtotal_cloud":        r.subtotalCloud,
		"tax_amount_local":      r.taxLocal,
		"tax_amount_cloud":      r.taxCloud,
		"service_charge_local":  r.serviceLocal,
		"service_charge_cloud":  r.serviceCloud,
		"discount_amount_local": r.discountLocal,
		"discount_amount_cloud": r.discountCloud,
	}, true
}

// moneyOverwriteOccurredAt chuẩn hoá `created_at` cục bộ về RFC3339 UTC.
//
// `recordMoneyOverwrite` ghi đúng RFC3339 UTC nên nhánh đầu tiên trúng ở mọi
// dòng thật; các layout còn lại hứng những database đã đi qua sửa tay hoặc một
// công cụ khôi phục. Đọc được thì ép về UTC, vì hợp đồng wire nói UTC và một
// offset địa phương lọt lên sẽ làm HQ xếp sai thứ tự các lần ghi đè giữa quán
// VN (UTC+7) và quán JP (UTC+9) — đúng loại lỗi mà #1091 tồn tại để chặn.
//
// KHÔNG bịa thời điểm khi đọc không được. `time.Now()` ở đây sẽ dán nhãn "vừa
// xảy ra" lên một khoảng lệch của tháng trước, và một bằng chứng bịa tệ hơn
// không có bằng chứng vì nó sẽ được tin.
func moneyOverwriteOccurredAt(raw string) (string, bool) {
	s := strings.TrimSpace(raw)
	if s == "" {
		return "", false
	}

	for _, layout := range []string{time.RFC3339Nano, time.RFC3339} {
		if t, err := time.Parse(layout, s); err == nil {
			return t.UTC().Format(time.RFC3339), true
		}
	}
	// Không mang múi giờ: SQLite `CURRENT_TIMESTAMP` và phần lớn công cụ sửa
	// tay viết giờ UTC ở dạng này, nên đọc theo UTC là phép đoán ít sai nhất —
	// và nó chỉ áp cho những dòng mà đường ghi thật không bao giờ sinh ra.
	for _, layout := range []string{"2006-01-02T15:04:05", "2006-01-02 15:04:05"} {
		if t, err := time.ParseInLocation(layout, s, time.UTC); err == nil {
			return t.UTC().Format(time.RFC3339), true
		}
	}

	return "", false
}

// moneyOverwriteCloudPost gọi Cloud qua ĐÚNG `cloudPost` như mọi đường đẩy
// khác, nhưng KHÔNG cho một lượt hỏng ở đây đổi trạng thái backpressure dùng
// chung.
//
// Đây là bất biến đắt nhất của file này, và nó đã có giá đo được ở #2695:
// `cloudPost` gọi `noteThrottle` trên mọi 5xx/429, `noteThrottle` đặt
// `cooldownUntil` TOÀN CỤC, và `processQueue` bỏ NGUYÊN vòng drain khi
// `inCooldown()`. Nghĩa là một endpoint phụ hỏng sẽ chặn đường đẩy TIỀN tới 5
// phút (backoff luỹ thừa, escalate theo số lần liên tiếp). Fail-open không chỉ
// là "không ném lỗi" — nó là không để lại tác dụng phụ nào lên đường chính.
//
// Thành công thì GIỮ NGUYÊN `noteCloudSuccess` bên trong `cloudPost`: một 2xx
// là bằng chứng thật rằng Cloud đang sống, bất kể nó tới từ endpoint nào.
func (e *SyncEngine) moneyOverwriteCloudPost(ctx context.Context, batch []map[string]any) error {
	e.rlMu.Lock()
	savedCooldown := e.cooldownUntil
	savedThrottles := e.consecutiveThrottles
	e.rlMu.Unlock()

	// bearer rỗng là cố ý: `cloudPost` tự dập device token hiện hành lên mọi
	// route `/api/v1/workstation/*`, nên token không bao giờ cũ sau một lần
	// ghép cặp lại.
	//
	// Idempotency-Key cũng rỗng: khử trùng của đường này nằm ở Cloud theo
	// `(device_id, local_id)` — một khoá do máy trạm sinh cho CẢ LÔ sẽ khử
	// trùng sai đơn vị (lô, không phải dòng), và một lô khác thành phần sẽ
	// trượt qua nó.
	_, _, err := e.cloudPost(ctx, moneyOverwritePushPath, "", "", map[string]any{"overwrites": batch})
	if err == nil {
		return nil
	}

	e.rlMu.Lock()
	e.cooldownUntil = savedCooldown
	e.consecutiveThrottles = savedThrottles
	e.rlMu.Unlock()

	return err
}

// markMoneyOverwritesSynced đóng dấu ĐÚNG những dòng vừa được Cloud xác nhận.
//
// `AND synced_at IS NULL` giữ dấu đầu tiên: bảng này append-only và bất biến,
// nên một lần đóng dấu thứ hai (lô trùng sau khi mất dấu) không được ghi đè
// thời điểm của lần đầu.
//
// Ghi hỏng ở đây KHÔNG được làm gãy vòng sync và cũng không được đảo ngược
// lượt đẩy: Cloud ĐÃ nhận, và hậu quả duy nhất là lượt sau gửi trùng — thứ mà
// khử trùng `(device_id, local_id)` nuốt gọn.
func (e *SyncEngine) markMoneyOverwritesSynced(ids []int64) {
	if len(ids) == 0 {
		return
	}

	args := make([]any, 0, len(ids)+1)
	args = append(args, time.Now().UTC().Format(time.RFC3339))
	placeholders := make([]string, len(ids))
	for i, id := range ids {
		placeholders[i] = "?"
		args = append(args, id)
	}

	if _, err := e.db.Exec(`UPDATE order_money_overwrites SET synced_at = ?
		WHERE id IN (`+strings.Join(placeholders, ",")+`) AND synced_at IS NULL`, args...); err != nil {
		slog.Error("cloud accepted the money-overwrite evidence but the local synced_at stamp failed — the next cycle will re-send it (cloud de-duplicates on (device_id, local_id))",
			"rows", len(ids), "err", err)
	}
}
