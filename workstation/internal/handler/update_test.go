package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/update"
)

func TestUpdateApply_BlockedWhenShiftOpen(t *testing.T) {
	s := newFireTestServer(t)
	seedOpenShift(t, s)
	s.updater = update.NewPlanner(t.TempDir())

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/update/apply", nil)
	s.handleUpdateApply(w, req)

	if w.Code != http.StatusConflict {
		t.Fatalf("status = %d, want 409; body=%s", w.Code, w.Body.String())
	}
	var body struct {
		Code string `json:"code"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
		t.Fatal(err)
	}
	if body.Code != "shift_in_progress" {
		t.Fatalf("code = %q, want shift_in_progress", body.Code)
	}
}

func TestUpdateStatus_ReportsShiftOpen(t *testing.T) {
	s := newFireTestServer(t)
	seedOpenShift(t, s)
	s.updater = update.NewPlanner(t.TempDir())

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/update/status", nil)
	s.handleUpdateStatus(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("status = %d", w.Code)
	}
	var st update.Status
	if err := json.Unmarshal(w.Body.Bytes(), &st); err != nil {
		t.Fatal(err)
	}
	if !st.ShiftOpen {
		t.Fatal("expected shift_open=true")
	}
	// A shift on the floor must never leave an armed install button, whatever
	// the planner thinks — the handler is the last gate before the swap.
	if st.CanApply {
		t.Fatal("can_apply must be false while a shift is open")
	}
}

// The shift gate is not the only gate: with no shift at all, an updater that
// has nothing staged must still refuse rather than "apply" nothing.
func TestUpdateApply_RefusesWhenNothingStaged(t *testing.T) {
	s := newFireTestServer(t)
	s.updater = update.NewPlanner(t.TempDir())

	w := httptest.NewRecorder()
	s.handleUpdateApply(w, httptest.NewRequest(http.MethodPost, "/api/update/apply", nil))

	if w.Code != http.StatusConflict {
		t.Fatalf("status = %d, want 409; body=%s", w.Code, w.Body.String())
	}
}

// A build with no updater wired (cmd variants, tests) answers instead of
// panicking, and never claims an install is possible.
func TestUpdateEndpoints_NilUpdaterAreSafe(t *testing.T) {
	s := newFireTestServer(t)
	s.updater = nil

	w := httptest.NewRecorder()
	s.handleUpdateStatus(w, httptest.NewRequest(http.MethodGet, "/api/update/status", nil))
	if w.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", w.Code)
	}
	var st update.Status
	if err := json.Unmarshal(w.Body.Bytes(), &st); err != nil {
		t.Fatal(err)
	}
	if st.CanApply || st.BlockReason != "updater_unavailable" {
		t.Fatalf("status = %+v", st)
	}

	for _, path := range []string{"/api/update/download", "/api/update/apply"} {
		w := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodPost, path, nil)
		if path == "/api/update/download" {
			s.handleUpdateDownload(w, req)
		} else {
			s.handleUpdateApply(w, req)
		}
		if w.Code != http.StatusServiceUnavailable {
			t.Errorf("%s: status = %d, want 503", path, w.Code)
		}
	}
}

// Loopback-only: a LAN tablet (pos-web is served by this very process on the
// LAN) must not be able to restart the workstation binary.
func TestUpdateRoutes_RejectNonLoopback(t *testing.T) {
	handler := localOnly(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("handler must not be reached from a LAN address")
	}))
	for _, remote := range []string{"192.168.1.42:5555", "10.0.0.9:1234", "[fd00::1]:80"} {
		w := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodPost, "/api/update/apply", nil)
		req.RemoteAddr = remote
		handler.ServeHTTP(w, req)
		if w.Code != http.StatusForbidden {
			t.Errorf("%s: status = %d, want 403", remote, w.Code)
		}
	}
}
