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

// The WS App print-language picker must be able to READ BACK both values it
// shows. Neither key was allowlisted, so both reads 403'd while the write side
// (no allowlist at all) kept saving happily: an operator who once picked "vi"
// saw an empty "theo Cloud" selector forever, while printLabelLocale() — which
// reads SQLite directly, not this endpoint — went on printing Vietnamese. The
// cause of the slips was invisible in the only UI that could undo it.
func TestHandleSetting_PrintLanguageKeysAreReadable(t *testing.T) {
	db := newTestDB(t)
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value)
		VALUES ('print_locale_override', 'vi')`); err != nil {
		t.Fatalf("seed override: %v", err)
	}
	// The Cloud-resolved value lives in shop_settings (PullBranch flattens the
	// branch feed there), NOT in settings — reading the wrong table returned
	// empty even once the key was allowlisted.
	if _, err := db.Exec(`INSERT OR REPLACE INTO shop_settings (key, value)
		VALUES ('print_label_locale', 'ja')`); err != nil {
		t.Fatalf("seed cloud value: %v", err)
	}
	s := &Server{db: db}

	get := func(key string) (int, string) {
		t.Helper()
		req := httptest.NewRequest("GET", "/api/settings/"+key, nil)
		req.SetPathValue("key", key)
		rec := httptest.NewRecorder()
		s.handleGetSetting(rec, req)
		var out struct {
			Value string `json:"value"`
		}
		_ = json.Unmarshal(rec.Body.Bytes(), &out)
		return rec.Code, out.Value
	}

	if code, v := get("print_locale_override"); code != 200 || v != "vi" {
		t.Errorf("print_locale_override: want 200/vi, got %d/%q", code, v)
	}
	if code, v := get("print_label_locale"); code != 200 || v != "ja" {
		t.Errorf("print_label_locale: want 200/ja from shop_settings, got %d/%q", code, v)
	}

	// Cloud owns shop_settings — PullBranch overwrites it every tick. A PUT here
	// would silently land in the local `settings` table, where nothing reads it,
	// so it is refused rather than answered 200 with no effect.
	req := httptest.NewRequest("PUT", "/api/settings/print_label_locale",
		strings.NewReader(`{"value":"en"}`))
	req.SetPathValue("key", "print_label_locale")
	rec := httptest.NewRecorder()
	s.handleSetSetting(rec, req)
	if rec.Code != 403 {
		t.Fatalf("PUT print_label_locale: want 403 (cloud-owned), got %d", rec.Code)
	}
	if code, v := get("print_label_locale"); code != 200 || v != "ja" {
		t.Errorf("cloud value must be untouched by the refused write, got %d/%q", code, v)
	}
}

// #2017 — công tắc "in bằng template đã publish" đọc được qua HTTP.
//
// Không đọc được thì trang Settings của app desktop hiện SAI: toggle luôn hiện
// TẮT dù máy đang bật, nên người vận hành không thấy — và do đó không tắt được
// — cái cờ đang quyết định mọi tờ giấy in ra. Đúng lỗi đã xảy ra với
// `print_locale_override` ở ca trên: ghi được mà đọc 403, panel trắng.
//
// Khoá này CỐ Ý nằm ở bảng `settings` local chứ không phải `shop_settings`:
// nó đòi một người đứng cạnh máy in xem tờ giấy đầu tiên. Ca dưới ghim luôn
// điều đó — đọc nhầm sang bảng Cloud sẽ làm nó trở thành cờ bật từ xa.
func TestHandleSetting_UsePublishedTemplatesIsReadable(t *testing.T) {
	db := newTestDB(t)
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value)
		VALUES ('print_template_use_published_templates', 'true')`); err != nil {
		t.Fatalf("seed: %v", err)
	}
	s := &Server{db: db}

	get := func(key string) (int, string) {
		t.Helper()
		req := httptest.NewRequest("GET", "/api/settings/"+key, nil)
		req.SetPathValue("key", key)
		rec := httptest.NewRecorder()
		s.handleGetSetting(rec, req)
		var out struct {
			Value string `json:"value"`
		}
		_ = json.Unmarshal(rec.Body.Bytes(), &out)

		return rec.Code, out.Value
	}

	if code, v := get("print_template_use_published_templates"); code != 200 || v != "true" {
		t.Errorf("muốn 200/true, nhận %d/%q", code, v)
	}

	// Và seam phải đọc ra CÙNG giá trị — nếu không thì UI nói một đằng, máy in
	// làm một nẻo.
	if !s.templateUsePublishedTemplates() {
		t.Error("seam không thấy cờ mà UI vừa đọc được")
	}
}

// Rào: khoá credential vẫn KHÔNG đọc được. Allow-list chỉ được nới có chủ đích.
func TestHandleSetting_DeviceTokenStaysUnreadable(t *testing.T) {
	db := newTestDB(t)
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value)
		VALUES ('device_token', 'SECRET')`); err != nil {
		t.Fatalf("seed: %v", err)
	}
	s := &Server{db: db}

	req := httptest.NewRequest("GET", "/api/settings/device_token", nil)
	req.SetPathValue("key", "device_token")
	rec := httptest.NewRecorder()
	s.handleGetSetting(rec, req)

	if rec.Code == 200 && strings.Contains(rec.Body.String(), "SECRET") {
		t.Fatal("device_token đọc được qua HTTP — allow-list bị nới quá tay")
	}
}
