package handler

import (
	"context"
	"database/sql"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/config"
	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/update"
)

func TestLANHealthIsPublicAndReturnsStatus(t *testing.T) {
	s, _ := newServerWithAuth(t, "") // no Cloud configured — health doesn't need it

	mux := http.NewServeMux()
	mux.HandleFunc("GET /api/lan/health", s.handleLANHealth)

	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, httptest.NewRequest("GET", "/api/lan/health", nil))

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", rec.Code)
	}

	var resp map[string]any
	if err := json.NewDecoder(rec.Body).Decode(&resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if resp["status"] != "ok" {
		t.Errorf("expected status=ok, got %v", resp["status"])
	}
	if _, ok := resp["server_time"]; !ok {
		t.Error("expected server_time field")
	}
	if resp["readiness"] != "ready" {
		t.Errorf("expected readiness=ready, got %v", resp["readiness"])
	}
	if _, ok := resp["database"].(map[string]any); !ok {
		t.Errorf("expected PII-free database diagnostics, got %#v", resp["database"])
	}
}

func TestLANHealth_RemainsLiveWhenDatabasePoolIsExhausted(t *testing.T) {
	s, db := newServerWithAuth(t, "")
	queriesBefore := db.Diagnostics().QueryCount

	// Reserve every pool connection without returning it. A health handler that
	// performs even one SELECT will now wait for the SQLite/client timeout.
	var held []*sql.Conn
	for i := 0; i < db.Conn().Stats().MaxOpenConnections; i++ {
		conn, err := db.Conn().Conn(context.Background())
		if err != nil {
			t.Fatalf("reserve connection %d: %v", i, err)
		}
		held = append(held, conn)
	}
	t.Cleanup(func() {
		for _, conn := range held {
			_ = conn.Close()
		}
	})

	started := time.Now()
	resp := lanHealthPayload(t, s)
	if elapsed := time.Since(started); elapsed > 100*time.Millisecond {
		t.Fatalf("liveness waited on exhausted DB pool: %v", elapsed)
	}
	if resp["status"] != "ok" {
		t.Errorf("liveness status = %v, want ok", resp["status"])
	}
	if resp["readiness"] != "degraded" {
		t.Errorf("readiness = %v, want degraded", resp["readiness"])
	}
	database, ok := resp["database"].(map[string]any)
	if !ok {
		t.Fatalf("database diagnostics shape: %#v", resp["database"])
	}
	if got, want := int(database["in_use"].(float64)), db.Conn().Stats().MaxOpenConnections; got != want {
		t.Errorf("database.in_use = %d, want %d", got, want)
	}
	if resp["branch_id"] != "branch-A" {
		t.Errorf("cached branch_id = %v, want branch-A", resp["branch_id"])
	}
	if got := db.Diagnostics().QueryCount - queriesBefore; got != 0 {
		t.Fatalf("liveness executed %d SQLite queries, want 0", got)
	}
}

// --- #2633: read-only "a newer build exists" hint on /api/lan/health ---------

// pinCurrentVersion makes the running-version half of the comparison
// deterministic. config.Version is "dev" in tests, and the planner treats a dev
// build specially, so a test that left it alone would be measuring the dev-build
// branch rather than the shop case it claims to cover.
func pinCurrentVersion(t *testing.T, v string) {
	t.Helper()
	prev := config.Version
	config.Version = v
	t.Cleanup(func() { config.Version = prev })
}

func lanHealthPayload(t *testing.T, s *Server) map[string]any {
	t.Helper()
	rec := httptest.NewRecorder()
	http.HandlerFunc(s.handleLANHealth).ServeHTTP(rec, httptest.NewRequest("GET", "/api/lan/health", nil))
	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d (body %s)", rec.Code, rec.Body.String())
	}
	var resp map[string]any
	if err := json.NewDecoder(rec.Body).Decode(&resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	return resp
}

func serverWithExpectedVersion(t *testing.T, expected string) *Server {
	t.Helper()
	s, _ := newServerWithAuth(t, "")
	s.updater = update.NewPlanner(t.TempDir())
	if expected != "" {
		// autoApply=false: helper này chỉ dựng trạng thái "có bản mong đợi" cho
		// test của /api/lan/health. Nó không liên quan tới tự-cài lúc 2h sáng
		// (#2635), và false là mặc định ở mọi nơi khác.
		s.updater.SetExpected(expected, "test", nil, false)
	}
	return s
}

func assertUpdateHint(t *testing.T, resp map[string]any, wantExpected string, wantAvailable bool) {
	t.Helper()
	if got := resp["expected_version"]; got != any(wantExpected) {
		t.Errorf("expected_version = %#v, want %q", got, wantExpected)
	}
	if got := resp["update_available"]; got != any(wantAvailable) {
		t.Errorf("update_available = %#v, want %v", got, wantAvailable)
	}
}

// The planner only exists when the app has a config dir. Health is the probe POS
// uses to decide the workstation is reachable at all — and since #2632 it also
// carries the version card — so a nil updater must degrade to "no hint", never
// take the endpoint down with it.
func TestLANHealth_NilUpdaterServesNoHintAndDoesNotPanic(t *testing.T) {
	s, _ := newServerWithAuth(t, "")
	if s.updater != nil {
		t.Fatal("harness precondition: expected a nil updater")
	}
	resp := lanHealthPayload(t, s)
	if resp["status"] != "ok" {
		t.Errorf("expected status=ok, got %v", resp["status"])
	}
	assertUpdateHint(t, resp, "", false)
}

// HQ has not declared a version (the manifest ships empty by default). Silence
// is the fail-safe answer, not "up to date".
func TestLANHealth_NoExpectedVersionMeansNoUpdate(t *testing.T) {
	pinCurrentVersion(t, "1.4.0")
	resp := lanHealthPayload(t, serverWithExpectedVersion(t, ""))
	assertUpdateHint(t, resp, "", false)
}

func TestLANHealth_ExpectedEqualsCurrentMeansNoUpdate(t *testing.T) {
	pinCurrentVersion(t, "1.4.0")
	resp := lanHealthPayload(t, serverWithExpectedVersion(t, "1.4.0"))
	assertUpdateHint(t, resp, "1.4.0", false)
}

func TestLANHealth_ExpectedDiffersFromCurrentMeansUpdateAvailable(t *testing.T) {
	pinCurrentVersion(t, "1.4.0")
	resp := lanHealthPayload(t, serverWithExpectedVersion(t, "1.5.0"))
	assertUpdateHint(t, resp, "1.5.0", true)
}

// /api/lan/health is unauthenticated and CORS-open, so every device on the shop
// LAN reads this body. update.Status carries a filesystem path, a download URL,
// a block reason and the cashier-shift flag; none of that is LAN business.
//
// The realistic way this breaks is someone replacing the two hand-mapped keys
// with the whole struct. This scenario makes that visible: expected != current
// with no package sets BlockReason="package_unavailable", and can_apply /
// shift_open / state / progress_percent have no omitempty, so a spread would
// surface them here every time.
func TestLANHealth_DoesNotLeakUpdaterInternalsToTheLAN(t *testing.T) {
	pinCurrentVersion(t, "1.4.0")
	resp := lanHealthPayload(t, serverWithExpectedVersion(t, "1.5.0"))

	for _, k := range []string{
		"staged_path", "block_reason", "shift_open", "manual_download_url",
		"can_apply", "state", "progress_percent", "package_available",
		"platform_id", "current_version", "reason", "error",
	} {
		if _, present := resp[k]; present {
			t.Errorf("key %q must not be exposed on /api/lan/health (value %#v)", k, resp[k])
		}
	}
}

func TestLANPrintReceiptRequiresAuth(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// No Authorization header
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, httptest.NewRequest("POST", "/api/lan/print/payment-receipt", nil))

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("expected 401, got %d", rec.Code)
	}
}

func TestLANPrintReceiptRequiresOrderID(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("POST", "/api/lan/print/payment-receipt", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusBadRequest {
		t.Errorf("expected 400 (missing order_id), got %d", rec.Code)
	}
}

func TestLANPrintReceipt_NoReceiptPrinterIs503AndBurnsNothing(t *testing.T) {
	// Bài này TỪNG khẳng định điều ngược lại ("best-effort no-op 200"), và đó
	// chính là lỗ hổng #2593 vòng 2: `beginMoneyPrint` đốt số copy + tạo hàng
	// ledger TRƯỚC mọi nil-check, `printPaymentReceipt` với `p == nil` trả
	// `("", nil)` — không lỗi — nên `finishMoneyPrint` đóng dấu hàng đó là ĐÃ IN
	// và client nhận `200 {slips_printed:1}`.
	//
	// Kết cục ở quán hall-only: pos-web báo thành công, sổ money-audit ghi một
	// biên lai đã in, bộ đếm copy tăng nên tờ giấy THẬT đầu tiên sau khi sửa
	// config in 「BẢN IN #2」, và không có tờ nào tồn tại.
	//
	// Hợp đồng đã ghi sẵn trong `workstation/CLAUDE.md` ("endpoint LAN trả 503
	// no_printer") — code mới là thứ đi sau doc, không phải ngược lại.
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.idempotency = service.NewIdempotencyStore(db)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("POST", "/api/lan/print/payment-receipt",
		strings.NewReader(`{"order_id":"o-1"}`))
	req.Header.Set("Authorization", "Bearer kiosk-token")
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("code = %d, want 503 — chứng từ tiền không in được thì phải KÊU TO, không báo thành công (body=%s)",
			rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "no_printer") {
		t.Errorf("body = %s, want status no_printer", rec.Body.String())
	}

	// Và quan trọng không kém: KHÔNG đốt gì. Một hàng ledger hoặc một số copy
	// bị tiêu ở đây là thứ không lấy lại được.
	var jobs int
	if err := db.QueryRow(`SELECT COUNT(*) FROM print_jobs`).Scan(&jobs); err != nil {
		t.Fatal(err)
	}
	if jobs != 0 {
		t.Fatalf("print_jobs = %d, want 0 — cổng chặn rồi mà vẫn tạo hàng ledger", jobs)
	}
}

// #2593 — biên lai nay TỪ CHỐI `503 no_printer` khi không có máy mang role
// `receipt_printer`, và cổng đó chạy TRƯỚC `beginMoneyPrint`. Các bài dưới đây
// đo LOGIC SỐ TIỀN (đơn nào, khoản nào, tổng bao nhiêu) chứ không đo cổng, nên
// chúng cần một máy thật để đi qua được.
//
// Máy là một TCP listener vứt đi (`listenForSlip`), nên lượt in thành công thật
// — không mock ở tầng nào — và mã trả về vẫn là 200 như trước bản sửa.
func seedReceiptPrinter(t *testing.T, s *Server, db *store.DB) {
	t.Helper()
	if s.devices == nil {
		s.devices = printer.NewManager(db)
	}
	ln := listenForSlip(t)
	if _, err := s.devices.AddPrinter("receipt", []printer.DeviceType{printer.TypeReceiptPrinter},
		printer.ConnNetwork, ln.addr, printer.PrinterConfig{PaperWidth: 80}); err != nil {
		t.Fatalf("add receipt printer: %v", err)
	}
}
