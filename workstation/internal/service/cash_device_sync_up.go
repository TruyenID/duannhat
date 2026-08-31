package service

import (
	"context"
	"database/sql"
	stdsync "sync"
	"time"
)

// T1 của #2876 (#2878) — đẩy sổ lượt thu tiền 釣銭機 lên Cloud.
//
// Cùng hợp đồng fail-open với `PushAlertsUp` (#2695) và vì cùng một lý do: hàng
// chưa đẩy được vẫn nằm trong SQLite của quán, còn một vòng sync chết thì kéo
// theo cả đơn hàng và thanh toán. Khác một điểm: alert đẩy **ảnh chụp trạng
// thái đang mở**, còn đây đẩy **sự kiện đã xảy ra** — nên nó có `synced_at`,
// và một hàng đã đẩy thì thôi.
//
// `synced_at` KHÔNG phải cơ chế đảm bảo đúng đắn. Cloud idempotent theo
// (peripheral_device_id, glory_transaction_id), nên đẩy lại là vô hại; cột này
// chỉ để khỏi đẩy lại. Nhầm hai vai đó sẽ dẫn tới việc dựng một hàng đợi thứ
// hai để "bảo đảm" thứ vốn đã được bảo đảm ở đầu kia.

const (
	cashDevicePushPath = "/api/v1/workstation/cash-device-transactions"

	// Khớp `max:50` phía Cloud. Vượt ngưỡng ⇒ 422 và CẢ LÔ rơi, kể cả hàng
	// quan trọng nhất — nên cắt ở đây, không trông vào Cloud cắt hộ.
	cashDevicePushBatchSize = 50

	// Khoảng cách tối thiểu giữa hai lượt đẩy. Sổ này không cấp bách như đơn
	// hàng — một lượt thu tới Cloud chậm một phút không đổi gì cho ai.
	cashDevicePushInterval = time.Minute

	// Đẩy sổ không được giữ vòng sync lâu hơn một lượt tick.
	cashDevicePushTimeout = 10 * time.Second
)

// cashDevicePushState giữ nhịp RIÊNG, không dùng chung với đường alert.
type cashDevicePushState struct {
	mu         stdsync.Mutex
	lastPushAt time.Time
}

// cashDeviceRow là một hàng sổ chờ đẩy.
type cashDeviceRow struct {
	DeviceID    string
	TxnID       string
	Outcome     string
	Requested   int
	Deposited   int
	ChangeDue   int
	Dispensed   int
	ErrorTitle  string
	OrderID     string
	StartedAt   string
	FinishedAt  sql.NullString
	SessionRowI string
}

// PushCashDeviceTransactionsUp đẩy các lượt thu đã ngã ngũ ở MÁY lên Cloud.
//
// KHÔNG trả lỗi — xem khối chú thích đầu file.
func (e *SyncEngine) PushCashDeviceTransactionsUp(ctx context.Context) {
	if e == nil || e.db == nil {
		return
	}

	defer func() {
		if r := recover(); r != nil {
			e.noteAlertPushFailed("panic khi đẩy sổ máy thu tiền")
		}
	}()

	if e.deviceToken() == "" {
		// Chưa ghép cặp ⇒ mọi request sẽ 401. Đây là trạng thái chờ, không
		// phải hỏng đường truyền.
		return
	}

	if e.inCooldown() {
		// Cloud đang bảo lùi. Đường đẩy này TÔN TRỌNG cooldown nhưng không
		// được phép TẠO ra nó — xem `alertCloudPost`.
		return
	}

	rows, err := e.unsyncedCashDeviceRows()
	if err != nil || len(rows) == 0 {
		return
	}

	batch := make([]map[string]any, 0, len(rows))
	ids := make([]string, 0, len(rows))

	for _, r := range rows {
		// Chưa biết máy nào thì KHÔNG đẩy. Cloud khoá theo thiết bị, và một
		// hàng không có thiết bị sẽ bị từ chối ở đó — đẩy nó lên chỉ để nhận
		// `rejected` là đốt một lượt request và làm nhiễu bộ đếm.
		if r.DeviceID == "" {
			continue
		}

		body := map[string]any{
			"peripheral_device_id": r.DeviceID,
			"glory_transaction_id": r.TxnID,
			"outcome":              r.Outcome,
			"requested_minor":      r.Requested,
			"deposited_minor":      r.Deposited,
			"change_minor":         r.ChangeDue,
			"dispensed_minor":      r.Dispensed,
			"started_at":           r.StartedAt,
		}

		if r.ErrorTitle != "" {
			body["error_title"] = r.ErrorTitle
		}

		if r.FinishedAt.Valid && r.FinishedAt.String != "" {
			body["finished_at"] = r.FinishedAt.String
		}

		// `order_id` cục bộ chỉ gửi khi nó là id Cloud đã biết. Máy trạm dùng
		// id do chính nó sinh cho đơn bán offline, và Cloud kiểm phạm vi chi
		// nhánh rồi BỎ giá trị lạ — nên gửi bừa không hỏng gì, nhưng cũng
		// không giúp gì.
		if r.OrderID != "" {
			body["customer_order_id"] = r.OrderID
		}

		batch = append(batch, body)
		ids = append(ids, r.SessionRowI)

		if len(batch) == cashDevicePushBatchSize {
			break
		}
	}

	if len(batch) == 0 {
		return
	}

	if err := e.sideChannelPost(ctx, cashDevicePushPath, map[string]any{"transactions": batch}); err != nil {
		return
	}

	e.markCashDeviceRowsSynced(ids)
}

// unsyncedCashDeviceRows đọc các hàng đã có kết cục MÁY mà chưa đẩy.
//
// Điều kiện là `machine_outcome <> ”`, KHÔNG phải `resolved_at IS NOT NULL`:
// một phiên có thể bị đóng lại vì hết giờ mà chưa bao giờ hỏi được máy, và đẩy
// một hàng không có kết cục máy lên Cloud là đẩy một khẳng định bịa.
//
// `glory_transaction_id <> ”` vì nó là nửa còn lại của khoá idempotent — hàng
// không có mã giao dịch thì Cloud không khử trùng được, và mỗi lượt đẩy sẽ đẻ
// một hàng mới.
func (e *SyncEngine) unsyncedCashDeviceRows() ([]cashDeviceRow, error) {
	rows, err := e.db.Query(
		`SELECT id, peripheral_device_id, glory_transaction_id, machine_outcome,
		        amount, deposited, change_due, dispensed, error_title,
		        order_id, started_at, finished_at
		 FROM cash_changer_sessions
		 WHERE synced_at IS NULL
		   AND machine_outcome <> ''
		   AND glory_transaction_id <> ''
		 ORDER BY started_at
		 LIMIT ?`,
		cashDevicePushBatchSize,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	out := make([]cashDeviceRow, 0, cashDevicePushBatchSize)

	for rows.Next() {
		var r cashDeviceRow
		if err := rows.Scan(
			&r.SessionRowI, &r.DeviceID, &r.TxnID, &r.Outcome,
			&r.Requested, &r.Deposited, &r.ChangeDue, &r.Dispensed, &r.ErrorTitle,
			&r.OrderID, &r.StartedAt, &r.FinishedAt,
		); err != nil {
			// Một hàng hỏng không được làm mất cả lô.
			continue
		}

		out = append(out, r)
	}

	return out, rows.Err()
}

// markCashDeviceRowsSynced đóng dấu đã đẩy.
//
// Lỗi ở đây KHÔNG được lan ra: hàng đã tới Cloud rồi, và lượt đẩy lại sau đó
// là vô hại nhờ khoá idempotent. Trả lỗi lên sẽ biến một sự cố ghi cục bộ
// thành một báo động đường truyền.
func (e *SyncEngine) markCashDeviceRowsSynced(ids []string) {
	stamp := time.Now().UTC().Format(time.RFC3339)

	for _, id := range ids {
		_, _ = e.db.Exec(
			`UPDATE cash_changer_sessions SET synced_at = ? WHERE id = ?`,
			stamp, id,
		)
	}
}

// maybePushCashDeviceTransactionsUp là điểm gọi từ vòng tick — tiết chế nhịp.
//
// Dùng CHUNG bộ đếm nhịp với đường alert (`alertPush.lastPushAt`) là sai: hai
// đường có hai lý do tồn tại và một cái im lặng sẽ che nhịp của cái kia. Nhịp
// riêng, cùng khoảng cách một phút.
func (e *SyncEngine) maybePushCashDeviceTransactionsUp() {
	if e == nil {
		return
	}

	now := time.Now()
	e.cashDevicePush.mu.Lock()
	if !e.cashDevicePush.lastPushAt.IsZero() && now.Sub(e.cashDevicePush.lastPushAt) < cashDevicePushInterval {
		e.cashDevicePush.mu.Unlock()

		return
	}
	e.cashDevicePush.lastPushAt = now
	e.cashDevicePush.mu.Unlock()

	ctx, cancel := context.WithTimeout(context.Background(), cashDevicePushTimeout)
	defer cancel()

	e.PushCashDeviceTransactionsUp(ctx)
}
