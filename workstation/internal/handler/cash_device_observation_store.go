package handler

import (
	"encoding/json"
	"fmt"
	"time"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// T2 (#2879) + T5 (#2882) — ghi hai sổ quan sát máy 釣銭機 vào SQLite.
//
// Cùng khuôn với `cash_changer_session_store.go`: service giữ luật, handler giữ
// `*sql.DB`.

var _ service.CashDeviceObserver = (*Server)(nil)

// CaptureInventory hỏi máy "trong mày đang có bao nhiêu tiền" và ghi lại.
//
// Gọi ở RANH CA (mở · chốt), KHÔNG gọi giữa lượt bán. `GetInventory` an toàn
// khi máy đang chạy giao dịch (adapter nói rõ vậy), nhưng một lượt hỏi chen vào
// giữa lúc `CashChangerService` giữ mutex thu tiền là thêm một biến vào chỗ
// không cần biến.
//
// Lỗi ở đây KHÔNG chặn mở/chốt ca — cùng luật với `BeginSession`: mất khả năng
// đối soát vẫn tốt hơn mất khả năng đóng cửa.
func (s *Server) CaptureInventory(inv glory.Inventory, tillSessionID, phase string) error {
	deviceID := s.cashChangerDeviceID()

	// Không biết máy nào thì ảnh chụp này không quy được về đâu, và Cloud khoá
	// theo thiết bị nên nó sẽ bị từ chối. Ghi vào chỉ để rồi bỏ đi là ghi rác.
	if deviceID == "" || tillSessionID == "" {
		return nil
	}

	denominations, err := json.Marshal(inv.CashCount.Cash)
	if err != nil {
		return err
	}

	uncertain, err := json.Marshal(uncertainDenominations(inv))
	if err != nil {
		return err
	}

	_, err = s.db.Exec(
		`INSERT INTO cash_device_inventory_snapshots
		   (id, peripheral_device_id, till_session_id, count_phase,
		    denominations, uncertain_denominations, bill_reject_count,
		    machine_seq_no, captured_at, synced_at)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
		 ON CONFLICT (peripheral_device_id, till_session_id, count_phase) DO UPDATE SET
		   denominations = excluded.denominations,
		   uncertain_denominations = excluded.uncertain_denominations,
		   bill_reject_count = excluded.bill_reject_count,
		   machine_seq_no = excluded.machine_seq_no,
		   captured_at = excluded.captured_at,
		   -- Chụp lại cùng mốc ⇒ phải đẩy lại. Cloud idempotent nên vô hại.
		   synced_at = NULL`,
		fmt.Sprintf("%s:%s:%s", deviceID, tillSessionID, phase),
		deviceID, tillSessionID, phase,
		string(denominations), string(uncertain), inv.BillRejectCount,
		inv.SeqNo, time.Now().UTC().Format(time.RFC3339),
	)

	return err
}

// uncertainDenominations rút tập mệnh giá MÁY TỰ KHAI là 在高不確定.
//
// Đây là dữ kiện quan trọng nhất của cả T2 và nó dễ bị bỏ qua: `CashCount.Cash`
// trông như một con số chắc chắn, còn cờ bất định nằm ở một struct KHÁC
// (`CashErrorStatus.Cash`). Đọc cái đầu mà quên cái sau là dựng một phép đối
// soát trên số liệu máy không bảo đảm.
func uncertainDenominations(inv glory.Inventory) []string {
	out := make([]string, 0, len(inv.CashErrorStatus.Cash))

	for denom, uncertain := range inv.CashErrorStatus.Cash {
		if uncertain {
			out = append(out, denom)
		}
	}

	return out
}

// RaiseDeviceError mở một sự cố, hoặc GIỮ NGUYÊN nếu nó đang mở.
//
// MỘT LẦN XẢY RA = MỘT HÀNG. Collector poll theo `pollInterval`, nên một sự cố
// kéo dài hai phút đi qua đây hàng trăm lần — `INSERT ... ON CONFLICT DO NOTHING`
// trên cặp (máy, lỗi, thời điểm mở) là thứ giữ cho sổ không thành rác.
//
// `occurred_at` lấy từ hàng ĐANG MỞ nếu có, chứ không phải `now()`: nếu không,
// mỗi lượt poll sinh một thời điểm mới và khoá idempotent mất tác dụng.
func (s *Server) RaiseDeviceError(errorTitle, errorGroup, gloryTransactionID, tillSessionID string) error {
	deviceID := s.cashChangerDeviceID()
	if deviceID == "" || errorTitle == "" {
		return nil
	}

	var openedAt string
	err := s.db.QueryRow(
		`SELECT occurred_at FROM cash_device_error_events
		 WHERE peripheral_device_id = ? AND error_title = ? AND cleared_at IS NULL`,
		deviceID, errorTitle,
	).Scan(&openedAt)

	if err == nil && openedAt != "" {
		// Sự cố đã mở — không đẻ hàng mới, không đổi gì.
		return nil
	}

	occurredAt := time.Now().UTC().Format(time.RFC3339)

	_, err = s.db.Exec(
		`INSERT INTO cash_device_error_events
		   (id, peripheral_device_id, error_title, error_group, occurred_at,
		    glory_transaction_id, till_session_id, synced_at)
		 VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
		 ON CONFLICT (peripheral_device_id, error_title, occurred_at) DO NOTHING`,
		fmt.Sprintf("%s:%s:%s", deviceID, errorTitle, occurredAt),
		deviceID, errorTitle, errorGroup, occurredAt,
		gloryTransactionID, tillSessionID,
	)

	return err
}

// ClearDeviceError đóng sự cố đang mở — đây là nửa cho phép tính THỜI LƯỢNG.
//
// Không có nó thì không trả lời được "chặn mất bao nhiêu phút", mà đó chính là
// con số quy ra tiền và là lý do sổ này khác một dòng log.
//
// `synced_at = NULL` để hàng đã đẩy được đẩy LẠI kèm `cleared_at`.
func (s *Server) ClearDeviceError(errorTitle string) error {
	deviceID := s.cashChangerDeviceID()
	if deviceID == "" || errorTitle == "" {
		return nil
	}

	_, err := s.db.Exec(
		`UPDATE cash_device_error_events
		 SET cleared_at = ?, synced_at = NULL
		 WHERE peripheral_device_id = ? AND error_title = ? AND cleared_at IS NULL`,
		time.Now().UTC().Format(time.RFC3339), deviceID, errorTitle,
	)

	return err
}
