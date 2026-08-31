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

	"github.com/dxs-platform/workstation-app/internal/device/glory"
	"github.com/dxs-platform/workstation-app/internal/store"
)

// T1 của #2876 (#2878) — sổ lượt thu tiền 釣銭機 đi lên Cloud.
//
// Bộ bài chia hai nửa: nửa PHẢI GỬI và nửa PHẢI IM. Nửa thứ hai mới là nửa
// hay bị bỏ — một đường đẩy chỉ biết gửi sẽ gửi cả những hàng chưa ngã ngũ, và
// một hàng chưa ngã ngũ đẩy lên Cloud là một khẳng định bịa về tiền.

type cashDeviceRecorder struct {
	calls int32
	body  atomic.Value // map[string]any
}

func newCashDeviceCloud(t *testing.T, status int) (*httptest.Server, *cashDeviceRecorder) {
	t.Helper()

	rec := &cashDeviceRecorder{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/cash-device-transactions" {
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
			_, _ = w.Write([]byte(`{"accepted":1}`))
		} else {
			_, _ = w.Write([]byte(`{"message":"boom"}`))
		}
	}))
	t.Cleanup(srv.Close)

	return srv, rec
}

func newCashDeviceEngine(t *testing.T, cloudURL string) (*SyncEngine, *store.DB) {
	t.Helper()

	e, db := newSyncTestEngine(t, cloudURL)
	if _, err := db.Exec(`INSERT INTO settings (key, value) VALUES ('device_token', 'dev-token-2878')
		ON CONFLICT (key) DO UPDATE SET value = excluded.value`); err != nil {
		t.Fatalf("seed device token: %v", err)
	}

	return e, db
}

// seedCashSession chèn một hàng phiên. `machineOutcome` rỗng = máy chưa ngã ngũ.
func seedCashSession(t *testing.T, db *store.DB, id, deviceID, txnID, machineOutcome string, deposited int) {
	t.Helper()

	_, err := db.Exec(
		`INSERT INTO cash_changer_sessions
		   (id, order_id, amount, glory_transaction_id, started_at,
		    peripheral_device_id, machine_outcome, deposited, change_due, dispensed)
		 VALUES (?, 'order-1', 1000, ?, ?, ?, ?, ?, 0, 0)`,
		id, txnID, time.Now().UTC().Format(time.RFC3339),
		deviceID, machineOutcome, deposited,
	)
	if err != nil {
		t.Fatalf("seed phiên: %v", err)
	}
}

func syncedAt(t *testing.T, db *store.DB, id string) any {
	t.Helper()

	var v any
	if err := db.QueryRow(`SELECT synced_at FROM cash_changer_sessions WHERE id = ?`, id).Scan(&v); err != nil {
		t.Fatalf("đọc synced_at: %v", err)
	}

	return v
}

// ─────────────────────────────────────────────────────────────────────────────
// PHẢI GỬI
// ─────────────────────────────────────────────────────────────────────────────

func TestCashDevicePush_SendsResolvedRowAndStampsSynced(t *testing.T) {
	srv, rec := newCashDeviceCloud(t, 202)
	e, db := newCashDeviceEngine(t, srv.URL)

	seedCashSession(t, db, "sess-1", "dev-uuid-1", "T-001", string(glory.StatusTimeout), 900)

	e.PushCashDeviceTransactionsUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 1 {
		t.Fatalf("số lượt POST = %d, muốn 1", got)
	}

	body, _ := rec.body.Load().(map[string]any)
	txns, _ := body["transactions"].([]any)
	if len(txns) != 1 {
		t.Fatalf("số hàng trong lô = %d, muốn 1", len(txns))
	}

	row, _ := txns[0].(map[string]any)
	if row["glory_transaction_id"] != "T-001" {
		t.Errorf("glory_transaction_id = %v, muốn T-001", row["glory_transaction_id"])
	}
	// Kết cục phải là từ vựng MÁY, không phải từ vựng phục hồi cục bộ
	// ('recorded'/'returned'/'retained'/'unknown').
	if row["outcome"] != "timeout" {
		t.Errorf("outcome = %v, muốn timeout (từ vựng máy)", row["outcome"])
	}
	// Con số quan trọng nhất của lượt timeout: tiền máy đang giữ.
	if row["deposited_minor"] != float64(900) {
		t.Errorf("deposited_minor = %v, muốn 900", row["deposited_minor"])
	}

	if syncedAt(t, db, "sess-1") == nil {
		t.Error("synced_at vẫn NULL sau khi đẩy thành công")
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// PHẢI IM
// ─────────────────────────────────────────────────────────────────────────────

func TestCashDevicePush_SilentWhenMachineNeverSettled(t *testing.T) {
	srv, rec := newCashDeviceCloud(t, 202)
	e, db := newCashDeviceEngine(t, srv.URL)

	// Phiên đã đóng lại phía ta nhưng CHƯA hỏi được máy. Đẩy hàng này lên là
	// đẩy một khẳng định bịa — đây là ca đắt nhất trong cả file.
	seedCashSession(t, db, "sess-2", "dev-uuid-1", "T-002", "", 0)

	e.PushCashDeviceTransactionsUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 0 {
		t.Fatalf("số lượt POST = %d, muốn 0 — máy chưa ngã ngũ thì không được đẩy", got)
	}
}

func TestCashDevicePush_SilentWithoutDeviceID(t *testing.T) {
	srv, rec := newCashDeviceCloud(t, 202)
	e, db := newCashDeviceEngine(t, srv.URL)

	// Quán chạy env fallback, chưa đăng ký máy trong registry. Cloud khoá theo
	// thiết bị nên hàng này chắc chắn bị từ chối — đẩy lên chỉ để đốt request.
	seedCashSession(t, db, "sess-3", "", "T-003", string(glory.StatusFinish), 1000)

	e.PushCashDeviceTransactionsUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 0 {
		t.Fatalf("số lượt POST = %d, muốn 0 — chưa biết máy nào", got)
	}
}

func TestCashDevicePush_DoesNotResendAfterSuccess(t *testing.T) {
	srv, rec := newCashDeviceCloud(t, 202)
	e, db := newCashDeviceEngine(t, srv.URL)

	seedCashSession(t, db, "sess-4", "dev-uuid-1", "T-004", string(glory.StatusFinish), 1000)

	e.PushCashDeviceTransactionsUp(context.Background())
	// Nhịp tiết chế nằm ở `maybePush...`; gọi thẳng để đo đúng phép khử trùng
	// của `synced_at` chứ không đo nhịp.
	e.PushCashDeviceTransactionsUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 1 {
		t.Fatalf("số lượt POST = %d, muốn 1 — hàng đã đẩy không được đẩy lại", got)
	}
}

func TestCashDevicePush_KeepsRowUnsyncedWhenCloudRejects(t *testing.T) {
	srv, rec := newCashDeviceCloud(t, 500)
	e, db := newCashDeviceEngine(t, srv.URL)

	seedCashSession(t, db, "sess-5", "dev-uuid-1", "T-005", string(glory.StatusFinish), 1000)

	e.PushCashDeviceTransactionsUp(context.Background())

	if got := atomic.LoadInt32(&rec.calls); got != 1 {
		t.Fatalf("số lượt POST = %d, muốn 1", got)
	}
	// Đóng dấu synced_at khi Cloud từ chối là mất hàng vĩnh viễn — không lượt
	// đẩy nào sau đó nhìn thấy nó nữa.
	if syncedAt(t, db, "sess-5") != nil {
		t.Error("synced_at bị đóng dấu dù Cloud trả 500")
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Hàm thuần
// ─────────────────────────────────────────────────────────────────────────────

func TestChangeDue_NeverNegative(t *testing.T) {
	// Lượt timeout: khách bỏ vào CHƯA đủ. Một số âm ở đây đi thẳng lên Cloud
	// và làm hỏng vế "kỳ vọng máy" của đối soát T2 (#2879), vì vế đó cộng dồn
	// cột này.
	if got := changeDue(900, 1000); got != 0 {
		t.Errorf("changeDue(900, 1000) = %d, muốn 0", got)
	}
	if got := changeDue(1000, 1000); got != 0 {
		t.Errorf("changeDue(1000, 1000) = %d, muốn 0", got)
	}
	if got := changeDue(1500, 1000); got != 500 {
		t.Errorf("changeDue(1500, 1000) = %d, muốn 500", got)
	}
}

func TestGloryErrorTitle_OnlyAdapterVocabulary(t *testing.T) {
	if got := gloryErrorTitle(&glory.Error{Title: "empty"}); got != "empty" {
		t.Errorf("title lỗi adapter = %q, muốn empty", got)
	}
	if got := gloryErrorTitle(nil); got != "" {
		t.Errorf("err nil phải cho chuỗi rỗng, được %q", got)
	}
	// Lỗi Go KHÔNG được nhét vào cột mang từ vựng máy: báo cáo sự cố của T5
	// (#2882) đếm theo nhóm, và hai thứ khác loại vào cùng nhóm là đếm sai.
	if got := gloryErrorTitle(context.DeadlineExceeded); got != "" {
		t.Errorf("lỗi ngoài adapter phải cho chuỗi rỗng, được %q", got)
	}
}
