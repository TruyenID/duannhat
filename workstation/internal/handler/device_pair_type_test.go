package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// mockPairCloud fakes Cloud's POST /api/v1/devices/pair, returning a device of
// the given type/token/branch. Lets pairing tests exercise the workstation-only
// type guard.
func mockPairCloud(t *testing.T, deviceType, token, branchID string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/devices/pair" {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_ = json.NewEncoder(w).Encode(map[string]any{
			"device_token": token,
			"device": map[string]any{
				"id":        "dev-x",
				"name":      "WS-1",
				"type":      deviceType,
				"status":    "active",
				"branch_id": branchID,
			},
		})
	}))
	t.Cleanup(srv.Close)
	return srv
}

func doPair(t *testing.T, srv *Server, code string) *httptest.ResponseRecorder {
	t.Helper()
	body, _ := json.Marshal(map[string]string{"pairing_code": code})
	req := httptest.NewRequest("POST", "/api/device/pair", bytes.NewReader(body))
	w := httptest.NewRecorder()
	srv.handleDevicePair(w, req)
	return w
}

// A pairing code that resolves (on Cloud) to a non-workstation device type must
// be rejected with 422 and persist NOTHING — otherwise the workstation would
// run as a POS/kiosk device with a mis-scoped token.
func TestPair_RejectsNonWorkstationType(t *testing.T) {
	cloud := mockPairCloud(t, "pos", "pos-token", "branch-A")
	srv, _ := newServerWithAuth(t, cloud.URL)

	w := doPair(t, srv, "ABC123")
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("want 422, got %d body=%s", w.Code, w.Body.String())
	}
	var body struct {
		Error string `json:"error"`
		Got   string `json:"got"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
		t.Fatal(err)
	}
	if body.Error != "device_type_mismatch" || body.Got != "pos" {
		t.Fatalf("bad 422 body: %+v", body)
	}
	// Nothing persisted.
	if got := settingVal(t, srv, "device_token"); got != "" {
		t.Errorf("device_token must not be written on rejected pair, got %q", got)
	}
	if got := settingVal(t, srv, "device_type"); got != "" {
		t.Errorf("device_type must not be written, got %q", got)
	}
}

func TestPair_AcceptsWorkstationType(t *testing.T) {
	cloud := mockPairCloud(t, "workstation", "ws-token", "branch-A")
	srv, _ := newServerWithAuth(t, cloud.URL)

	w := doPair(t, srv, "WS0001")
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	if got := settingVal(t, srv, "device_token"); got != "ws-token" {
		t.Errorf("device_token should be persisted, got %q", got)
	}
	if got := settingVal(t, srv, "device_type"); got != "workstation" {
		t.Errorf("device_type should be workstation, got %q", got)
	}
}

// A 2xx from Cloud with an empty token (or branch) is a malformed success and
// must not become a "paired" state.
func TestPair_RejectsMalformedSuccess(t *testing.T) {
	cloud := mockPairCloud(t, "workstation", "", "branch-A") // empty token
	srv, _ := newServerWithAuth(t, cloud.URL)

	w := doPair(t, srv, "WS0001")
	if w.Code != http.StatusBadGateway {
		t.Fatalf("want 502, got %d body=%s", w.Code, w.Body.String())
	}
	if got := settingVal(t, srv, "device_token"); got != "" {
		t.Errorf("device_token must not be written, got %q", got)
	}
}
