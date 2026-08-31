package service

import (
	"context"
	"database/sql"
	"encoding/json"
	stdsync "sync"
	"time"
)

// T2 (#2879) + T5 (#2882) — đẩy hai sổ quan sát máy 釣銭機 lên Cloud.
//
// Cùng hợp đồng fail-open với `PushCashDeviceTransactionsUp` (#2878) và đi qua
// cùng `sideChannelPost` — đường phụ TÔN TRỌNG cooldown nhưng không TẠO ra nó.
//
// Một vòng cho CẢ HAI sổ, không phải hai vòng: chúng cùng nhịp, cùng ngưỡng lô,
// cùng luật fail-open. Hai vòng sẽ là hai chỗ phải sửa mỗi khi luật đổi.

const (
	cashInventoryPushPath = "/api/v1/workstation/cash-device-inventory"
	cashErrorPushPath     = "/api/v1/workstation/cash-device-errors"

	cashObservationBatchSize = 50
	cashObservationInterval  = time.Minute
	cashObservationTimeout   = 10 * time.Second
)

type cashObservationPushState struct {
	mu         stdsync.Mutex
	lastPushAt time.Time
}

// maybePushCashObservationsUp là điểm gọi từ vòng tick — tiết chế nhịp riêng.
func (e *SyncEngine) maybePushCashObservationsUp() {
	if e == nil {
		return
	}

	now := time.Now()
	e.cashObservationPush.mu.Lock()
	if !e.cashObservationPush.lastPushAt.IsZero() && now.Sub(e.cashObservationPush.lastPushAt) < cashObservationInterval {
		e.cashObservationPush.mu.Unlock()

		return
	}
	e.cashObservationPush.lastPushAt = now
	e.cashObservationPush.mu.Unlock()

	ctx, cancel := context.WithTimeout(context.Background(), cashObservationTimeout)
	defer cancel()

	e.PushCashObservationsUp(ctx)
}

// PushCashObservationsUp đẩy 在高 và sự cố. KHÔNG trả lỗi — fail-open.
func (e *SyncEngine) PushCashObservationsUp(ctx context.Context) {
	if e == nil || e.db == nil {
		return
	}

	defer func() {
		if r := recover(); r != nil {
			e.noteAlertPushFailed("panic khi đẩy sổ quan sát máy thu tiền")
		}
	}()

	if e.deviceToken() == "" || e.inCooldown() {
		return
	}

	e.pushInventorySnapshots(ctx)
	e.pushErrorEvents(ctx)
}

func (e *SyncEngine) pushInventorySnapshots(ctx context.Context) {
	rows, err := e.db.Query(
		`SELECT id, peripheral_device_id, till_session_id, count_phase,
		        denominations, uncertain_denominations, bill_reject_count,
		        machine_seq_no, captured_at
		 FROM cash_device_inventory_snapshots
		 WHERE synced_at IS NULL
		 ORDER BY captured_at
		 LIMIT ?`, cashObservationBatchSize)
	if err != nil {
		return
	}
	defer rows.Close()

	batch := make([]map[string]any, 0, cashObservationBatchSize)
	ids := make([]string, 0, cashObservationBatchSize)

	for rows.Next() {
		var (
			id, deviceID, sessionID, phase string
			denomJSON, uncertainJSON       string
			billReject                     int
			seqNo                          sql.NullInt64
			capturedAt                     string
		)
		if err := rows.Scan(&id, &deviceID, &sessionID, &phase, &denomJSON,
			&uncertainJSON, &billReject, &seqNo, &capturedAt); err != nil {
			continue
		}

		var denominations map[string]int
		if err := json.Unmarshal([]byte(denomJSON), &denominations); err != nil {
			continue
		}

		var uncertain []string
		_ = json.Unmarshal([]byte(uncertainJSON), &uncertain)

		body := map[string]any{
			"peripheral_device_id": deviceID,
			"till_session_id":      sessionID,
			"count_phase":          phase,
			"denominations":        denominations,
			"bill_reject_count":    billReject,
			"captured_at":          capturedAt,
		}

		// `uncertain_denominations` gửi kể cả khi RỖNG là thừa; nhưng gửi
		// THIẾU khi có thì Cloud sẽ cộng cả mệnh giá máy không chắc vào tổng.
		// Nên có thì phải gửi.
		if len(uncertain) > 0 {
			body["uncertain_denominations"] = uncertain
		}
		if seqNo.Valid {
			body["machine_seq_no"] = seqNo.Int64
		}

		batch = append(batch, body)
		ids = append(ids, id)
	}

	if len(batch) == 0 {
		return
	}

	if err := e.sideChannelPost(ctx, cashInventoryPushPath, map[string]any{"snapshots": batch}); err != nil {
		return
	}

	e.markObservationsSynced("cash_device_inventory_snapshots", ids)
}

func (e *SyncEngine) pushErrorEvents(ctx context.Context) {
	rows, err := e.db.Query(
		`SELECT id, peripheral_device_id, error_title, error_group,
		        occurred_at, cleared_at, glory_transaction_id, till_session_id
		 FROM cash_device_error_events
		 WHERE synced_at IS NULL
		 ORDER BY occurred_at
		 LIMIT ?`, cashObservationBatchSize)
	if err != nil {
		return
	}
	defer rows.Close()

	batch := make([]map[string]any, 0, cashObservationBatchSize)
	ids := make([]string, 0, cashObservationBatchSize)

	for rows.Next() {
		var (
			id, deviceID, title, group, occurredAt string
			clearedAt                              sql.NullString
			txnID, sessionID                       string
		)
		if err := rows.Scan(&id, &deviceID, &title, &group, &occurredAt,
			&clearedAt, &txnID, &sessionID); err != nil {
			continue
		}

		body := map[string]any{
			"peripheral_device_id": deviceID,
			"error_title":          title,
			"error_group":          group,
			"occurred_at":          occurredAt,
		}

		if clearedAt.Valid && clearedAt.String != "" {
			body["cleared_at"] = clearedAt.String
		}
		if txnID != "" {
			body["glory_transaction_id"] = txnID
		}
		if sessionID != "" {
			body["till_session_id"] = sessionID
		}

		batch = append(batch, body)
		ids = append(ids, id)
	}

	if len(batch) == 0 {
		return
	}

	if err := e.sideChannelPost(ctx, cashErrorPushPath, map[string]any{"events": batch}); err != nil {
		return
	}

	e.markObservationsSynced("cash_device_error_events", ids)
}

// markObservationsSynced đóng dấu đã đẩy.
//
// Lỗi ở đây KHÔNG lan ra: hàng đã tới Cloud, và lượt đẩy lại sau đó vô hại nhờ
// khoá idempotent. Tên bảng là hằng nội bộ, không phải giá trị từ ngoài.
func (e *SyncEngine) markObservationsSynced(table string, ids []string) {
	stamp := time.Now().UTC().Format(time.RFC3339)

	for _, id := range ids {
		_, _ = e.db.Exec(`UPDATE `+table+` SET synced_at = ? WHERE id = ?`, stamp, id)
	}
}
