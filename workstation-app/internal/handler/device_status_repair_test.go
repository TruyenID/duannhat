package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

func setRepairSetting(t *testing.T, s *Server, key, value string) {
	t.Helper()
	if _, err := s.db.Exec(
		"INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
		key, value,
	); err != nil {
		t.Fatalf("set setting %s: %v", key, err)
	}
}

func repairDeviceStatus(t *testing.T, s *Server) map[string]any {
	t.Helper()
	rec := httptest.NewRecorder()
	s.handleDeviceStatus(rec, httptest.NewRequest("GET", "/api/device/status", nil))
	if rec.Code != http.StatusOK {
		t.Fatalf("device status: expected 200, got %d", rec.Code)
	}
	var resp map[string]any
	if err := json.NewDecoder(rec.Body).Decode(&resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	return resp
}

// A cloud 401 mid-session must surface a re-pair signal instead of silently
// dropping the workstation into a zombie state (LAN server up, sync dead).
// See issue #437.
func TestDeviceStatus_NeedsRepairAfterCloud401(t *testing.T) {
	s, _ := newServerWithAuth(t, "")

	setRepairSetting(t, s, "device_token", "tok-123")
	setRepairSetting(t, s, "device_name", "Workstation-POS")

	if got := repairDeviceStatus(t, s); got["paired"] != true || got["needs_repair"] != false {
		t.Fatalf("paired workstation: expected paired=true needs_repair=false, got %+v", got)
	}

	// Cloud rejects the token → clearDeviceToken wipes the credential + sets
	// the auth-lost flag, but keeps device_name for the banner.
	s.clearDeviceToken()

	got := repairDeviceStatus(t, s)
	if got["paired"] != false {
		t.Errorf("after 401: expected paired=false, got %v", got["paired"])
	}
	if got["needs_repair"] != true {
		t.Errorf("after 401: expected needs_repair=true, got %v", got["needs_repair"])
	}
	if got["device_name"] != "Workstation-POS" {
		t.Errorf("after 401: expected device_name preserved for banner, got %v", got["device_name"])
	}

	// Re-pairing restores the token and clears the auth-lost flag → banner gone.
	setRepairSetting(t, s, "device_token", "tok-new")
	setRepairSetting(t, s, "sync.auth_lost", "0")

	if got := repairDeviceStatus(t, s); got["paired"] != true || got["needs_repair"] != false {
		t.Fatalf("after re-pair: expected paired=true needs_repair=false, got %+v", got)
	}
}
