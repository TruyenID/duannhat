package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// #1481 — pos-web served at /pos pairs SAME-ORIGIN through the workstation,
// which must relay POST /api/v1/devices/pair to Cloud. Unlike /api/v1/pos/*,
// the /api/v1/devices/ namespace has NO catch-all, so without an explicit route
// this POST would 404 on the workstation and pairing would fail. This asserts
// the relay exists and forwards the request to Cloud verbatim.
func TestDevicePairProxy_ForwardsToCloud(t *testing.T) {
	cloud := mockSSOCloudWithProxy(t)
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	body := strings.NewReader(`{"pairing_code":"ABC123","device_info":{"user_agent":"pos-web","app_version":"1.0"}}`)
	req := httptest.NewRequest("POST", "/api/v1/devices/pair", body)
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	// 200 (not 404): the request reached the mock Cloud through the relay.
	if w.Code != http.StatusOK {
		t.Fatalf("expected 200 proxied to Cloud, got %d body=%s", w.Code, w.Body.String())
	}
	var resp map[string]string
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v body=%s", err, w.Body.String())
	}
	if resp["path"] != "/api/v1/devices/pair" {
		t.Errorf("path not preserved to Cloud: %q", resp["path"])
	}
	if resp["method"] != "POST" {
		t.Errorf("method not preserved to Cloud: %q", resp["method"])
	}
}
