package handler

import (
	"encoding/json"
	"net/http/httptest"
	"strings"
	"testing"
)

// TestHandleGetSetting_BlocksCredentialKeys locks the #84 fix: the Cloud
// bearer token lives in the settings table under device_token, so the GET
// handler must refuse it (and any non-allowlisted key) even though the route
// is already loopback-only.
func TestHandleGetSetting_BlocksCredentialKeys(t *testing.T) {
	db := newTestDB(t)
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES
		('device_token', 'SECRET-CLOUD-BEARER'),
		('store_name', 'Demo Shop')`); err != nil {
		t.Fatalf("seed settings: %v", err)
	}
	s := &Server{db: db}

	// device_token → 403, and the secret must never appear in the body.
	req := httptest.NewRequest("GET", "/api/settings/device_token", nil)
	req.SetPathValue("key", "device_token")
	rec := httptest.NewRecorder()
	s.handleGetSetting(rec, req)
	if rec.Code != 403 {
		t.Fatalf("device_token: want 403, got %d", rec.Code)
	}
	if body := rec.Body.String(); strings.Contains(body, "SECRET-CLOUD-BEARER") {
		t.Fatalf("device_token value leaked in body: %s", body)
	}

	// An allowlisted key still works.
	req2 := httptest.NewRequest("GET", "/api/settings/store_name", nil)
	req2.SetPathValue("key", "store_name")
	rec2 := httptest.NewRecorder()
	s.handleGetSetting(rec2, req2)
	if rec2.Code != 200 {
		t.Fatalf("store_name: want 200, got %d", rec2.Code)
	}
	var out struct {
		Value string `json:"value"`
	}
	if err := json.Unmarshal(rec2.Body.Bytes(), &out); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if out.Value != "Demo Shop" {
		t.Fatalf("store_name: want %q, got %q", "Demo Shop", out.Value)
	}

	// A non-allowlisted arbitrary key → 403 (not 200-with-empty).
	req3 := httptest.NewRequest("GET", "/api/settings/cloud_api_secret", nil)
	req3.SetPathValue("key", "cloud_api_secret")
	rec3 := httptest.NewRecorder()
	s.handleGetSetting(rec3, req3)
	if rec3.Code != 403 {
		t.Fatalf("arbitrary key: want 403, got %d", rec3.Code)
	}
}
