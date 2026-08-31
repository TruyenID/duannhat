package service

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
	"github.com/dxs-platform/workstation-app/internal/store"
)

// T2 (#2879) + T5 (#2882) — đẩy 在高 và sự cố lên Cloud.

type obsRecorder struct {
	invCalls int32
	errCalls int32
	invBody  atomic.Value
	errBody  atomic.Value
}

func newObsCloud(t *testing.T) (*httptest.Server, *obsRecorder) {
	t.Helper()

	rec := &obsRecorder{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		var parsed map[string]any
		_ = json.Unmarshal(raw, &parsed)

		switch r.URL.Path {
		case "/api/v1/workstation/cash-device-inventory":
			atomic.AddInt32(&rec.invCalls, 1)
			rec.invBody.Store(parsed)
		case "/api/v1/workstation/cash-device-errors":
			atomic.AddInt32(&rec.errCalls, 1)
			rec.errBody.Store(parsed)
		}

		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(202)
		_, _ = w.Write([]byte(`{"accepted":1}`))
	}))
	t.Cleanup(srv.Close)

	return srv, rec
}

func newObsEngine(t *testing.T, url string) (*SyncEngine, *store.DB) {
	t.Helper()

	e, db := newSyncTestEngine(t, url)
	if _, err := db.Exec(`INSERT INTO settings (key, value) VALUES ('device_token', 'dev-obs')
		ON CONFLICT (key) DO UPDATE SET value = excluded.value`); err != nil {
		t.Fatalf("seed token: %v", err)
	}

	return e, db
}

// PHẢI GỬI — 在高 đi lên kèm tập mệnh giá BẤT ĐỊNH.
func TestPushObservations_SendsInventoryWithUncertainDenominations(t *testing.T) {
	srv, rec := newObsCloud(t)
	e, db := newObsEngine(t, srv.URL)

	if _, err := db.Exec(
		`INSERT INTO cash_device_inventory_snapshots
		   (id, peripheral_device_id, till_session_id, count_phase, denominations,
		    uncertain_denominations, bill_reject_count, captured_at)
		 VALUES ('s1','dev-1','sess-1','closing','{"10000":2}','["10000"]',3,'2026-08-15T00:00:00Z')`,
	); err != nil {
		t.Fatalf("seed: %v", err)
	}

	e.PushCashObservationsUp(context.Background())

	if got := atomic.LoadInt32(&rec.invCalls); got != 1 {
		t.Fatalf("số POST 在高 = %d, muốn 1", got)
	}

	body, _ := rec.invBody.Load().(map[string]any)
	snaps, _ := body["snapshots"].([]any)
	row, _ := snaps[0].(map[string]any)

	// Thiếu trường này thì Cloud cộng cả mệnh giá máy KHÔNG CHẮC vào tổng —
	// đúng cái sai mà cả T2 sinh ra để chặn.
	unc, ok := row["uncertain_denominations"].([]any)
	if !ok || len(unc) != 1 || unc[0] != "10000" {
		t.Errorf("uncertain_denominations = %v, muốn [10000]", row["uncertain_denominations"])
	}
	if row["bill_reject_count"] != float64(3) {
		t.Errorf("bill_reject_count = %v, muốn 3", row["bill_reject_count"])
	}
}

// PHẢI GỬI — sự cố đi lên kèm `cleared_at` khi đã đóng.
func TestPushObservations_SendsClearedAtSoDurationIsComputable(t *testing.T) {
	srv, rec := newObsCloud(t)
	e, db := newObsEngine(t, srv.URL)

	if _, err := db.Exec(
		`INSERT INTO cash_device_error_events
		   (id, peripheral_device_id, error_title, error_group, occurred_at, cleared_at)
		 VALUES ('e1','dev-1','empty','change_shortage','2026-08-15T03:00:00Z','2026-08-15T03:04:00Z')`,
	); err != nil {
		t.Fatalf("seed: %v", err)
	}

	e.PushCashObservationsUp(context.Background())

	body, _ := rec.errBody.Load().(map[string]any)
	events, _ := body["events"].([]any)
	row, _ := events[0].(map[string]any)

	// Không có `cleared_at` thì không tính được "chặn mất bao nhiêu phút" — mà
	// đó chính là con số quy ra tiền.
	if row["cleared_at"] != "2026-08-15T03:04:00Z" {
		t.Errorf("cleared_at = %v", row["cleared_at"])
	}
}

// PHẢI IM — hàng đã đẩy không đẩy lại.
func TestPushObservations_DoesNotResend(t *testing.T) {
	srv, rec := newObsCloud(t)
	e, db := newObsEngine(t, srv.URL)

	if _, err := db.Exec(
		`INSERT INTO cash_device_inventory_snapshots
		   (id, peripheral_device_id, till_session_id, count_phase, denominations, captured_at)
		 VALUES ('s1','dev-1','sess-1','opening','{"1000":1}','2026-08-15T00:00:00Z')`,
	); err != nil {
		t.Fatalf("seed: %v", err)
	}

	e.PushCashObservationsUp(context.Background())
	e.PushCashObservationsUp(context.Background())

	if got := atomic.LoadInt32(&rec.invCalls); got != 1 {
		t.Fatalf("số POST = %d, muốn 1 — hàng đã đẩy không được đẩy lại", got)
	}
}

// PHẢI IM — không có gì chờ đẩy thì không phát request nào.
func TestPushObservations_SilentWhenNothingPending(t *testing.T) {
	srv, rec := newObsCloud(t)
	e, _ := newObsEngine(t, srv.URL)

	e.PushCashObservationsUp(context.Background())

	if atomic.LoadInt32(&rec.invCalls)+atomic.LoadInt32(&rec.errCalls) != 0 {
		t.Error("phát request dù không có hàng nào chờ")
	}
}

// Phân nhóm lỗi: chỉ bốn nhóm vào sổ, phần còn lại là nhịp giao thức.
func TestErrorGroupFor_OnlyFourGroupsReachTheLedger(t *testing.T) {
	cases := map[string]string{
		"empty":          "change_shortage",
		"billRejectFull": "needs_operator",
		"needPullOut":    "needs_operator",
		"ifError":        "connectivity",
		"notReady":       "connectivity",
		"forbidden":      "forbidden",
		// Nhịp bình thường của giao thức — ghi vào sẽ chôn lấp bốn nhóm thật.
		"busy":       "",
		"processing": "",
		"notFound":   "",
		"notEnough":  "",
	}

	for title, want := range cases {
		if got := ErrorGroupFor(&glory.Error{Title: title}); got != want {
			t.Errorf("ErrorGroupFor(%q) = %q, muốn %q", title, got, want)
		}
	}

	// Lỗi ngoài adapter KHÔNG vào sổ: cột error_group mang từ vựng của MÁY.
	if got := ErrorGroupFor(context.DeadlineExceeded); got != "" {
		t.Errorf("lỗi ngoài adapter = %q, muốn rỗng", got)
	}
	if got := ErrorGroupFor(nil); got != "" {
		t.Errorf("nil = %q, muốn rỗng", got)
	}
}
