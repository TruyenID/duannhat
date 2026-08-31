package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// GET /api/v1/pos/settings/order surfaces the shop_settings rows the
// PullBranch loop synced from Cloud. Pos-web reads `enable_quick_order`
// to flip the order-create dialog into the one-tap "Tạo nhanh" button.
// Pre-fix to this verification: workstation already read both keys via
// `shopSettingString`, but no end-to-end test pinned that the HTTP
// response surfaced them with the right type (bool, not string).
func TestHandleLocalPosOrderSettings_SurfacesSyncedFlags(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	mustExec(t, srv.db, `INSERT INTO shop_settings (key, value) VALUES
		('enable_quick_order','true'),
		('default_order_item_status','ready'),
		('service_charge_rate','5.00'),
		('service_charge_tax_rate','8.00'),
		('prices_include_tax','true'),
		('default_tax_type_id','tt-123'),
		('close_report_tax_breakdown','false'),
		('currency_code','VND')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)

	req := httptest.NewRequest("GET", "/api/v1/pos/settings/order", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosOrderSettings(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}

	var resp struct {
		Data struct {
			EnableQuickOrder        bool   `json:"enable_quick_order"`
			DefaultOrderItemStatus  any    `json:"default_order_item_status"`
			ServiceChargeRate       string `json:"service_charge_rate"`
			ServiceChargeTaxRate    string `json:"service_charge_tax_rate"`
			PricesIncludeTax        bool   `json:"prices_include_tax"`
			DefaultTaxTypeID        any    `json:"default_tax_type_id"`
			CloseReportTaxBreakdown bool   `json:"close_report_tax_breakdown"`
			CurrencyCode            string `json:"currency_code"`
			// plan-043 T3.3 — the dropped legacy `tax_rate` must NOT be present.
			TaxRate *string `json:"tax_rate"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v body=%s", err, w.Body.String())
	}
	if !resp.Data.EnableQuickOrder {
		t.Errorf("enable_quick_order want true, got %v", resp.Data.EnableQuickOrder)
	}
	if got, _ := resp.Data.DefaultOrderItemStatus.(string); got != "ready" {
		t.Errorf("default_order_item_status want 'ready', got %v", resp.Data.DefaultOrderItemStatus)
	}
	if resp.Data.TaxRate != nil {
		t.Errorf("legacy tax_rate must be absent, got %q", *resp.Data.TaxRate)
	}
	if resp.Data.ServiceChargeRate != "5.00" {
		t.Errorf("service_charge_rate want 5.00, got %q", resp.Data.ServiceChargeRate)
	}
	if resp.Data.ServiceChargeTaxRate != "8.00" {
		t.Errorf("service_charge_tax_rate want 8.00, got %q", resp.Data.ServiceChargeTaxRate)
	}
	if !resp.Data.PricesIncludeTax {
		t.Errorf("prices_include_tax want true, got %v", resp.Data.PricesIncludeTax)
	}
	if got, _ := resp.Data.DefaultTaxTypeID.(string); got != "tt-123" {
		t.Errorf("default_tax_type_id want 'tt-123', got %v", resp.Data.DefaultTaxTypeID)
	}
	if resp.Data.CloseReportTaxBreakdown {
		t.Errorf("close_report_tax_breakdown want false, got %v", resp.Data.CloseReportTaxBreakdown)
	}
	if resp.Data.CurrencyCode != "VND" {
		t.Errorf("currency_code want VND, got %q", resp.Data.CurrencyCode)
	}
}

// #2947 — order settings are a local replica read. A Cloud pull here leaks WAN
// latency into every open/create-order action even though POS and workstation
// are on the same LAN. The background manifest/poke loop owns freshness.
func TestHandleLocalPosOrderSettings_NeverWaitsForCloud(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	mustExec(t, srv.db, `INSERT INTO shop_settings (key, value) VALUES
		('enable_quick_order','true'),
		('currency_code','JPY')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)

	var cloudRequests atomic.Int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		cloudRequests.Add(1)
		time.Sleep(500 * time.Millisecond)
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":{"id":"branch","settings":{}}}`))
	}))
	t.Cleanup(cloud.Close)
	srv.puller = service.NewSyncPuller(srv.db, cloud.URL, func() string { return "device-token" })

	started := time.Now()
	w := httptest.NewRecorder()
	srv.handleLocalPosOrderSettings(w, httptest.NewRequest("GET", "/api/v1/pos/settings/order", nil))
	elapsed := time.Since(started)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	if elapsed > 250*time.Millisecond {
		t.Fatalf("local settings read took %s; it must not inherit Cloud latency", elapsed)
	}
	if got := cloudRequests.Load(); got != 0 {
		t.Fatalf("Cloud requests = %d, want 0 for a local settings read", got)
	}
}

// The settings endpoint used to read every key independently. That looks
// harmless on localhost, but under concurrent sync those round trips queue
// behind SQLite and turn a LAN request into an N+1-shaped latency spike.
// Pin the whole response to one snapshot query, regardless of how many keys
// exist in the replica.
func TestHandleLocalPosOrderSettings_ReadsOneSQLiteSnapshot(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	mustExec(t, db, `INSERT INTO shop_settings (key, value) VALUES
		('enable_quick_order','true'),
		('default_order_item_status','preparing'),
		('service_charge_rate','5.00'),
		('service_charge_tax_rate','10.00'),
		('prices_include_tax','true'),
		('default_tax_type_id','tax-1'),
		('close_report_tax_breakdown','false'),
		('allow_item_edit_any_status','true'),
		('item_voidable_statuses','["pending","preparing"]'),
		('stock_deduction_timing','on_preparing'),
		('currency_code','JPY'),
		('currency','USD'),
		('unrelated_sync_key','must-not-add-a-query')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)

	before := db.Diagnostics()
	w := httptest.NewRecorder()
	srv.handleLocalPosOrderSettings(w, httptest.NewRequest(http.MethodGet, "/api/v1/pos/settings/order", nil))
	after := db.Diagnostics()

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	if got := after.QueryCount - before.QueryCount; got != 1 {
		t.Fatalf("settings snapshot used %d SQLite queries, want exactly 1", got)
	}
	if got := after.ExecCount - before.ExecCount; got != 0 {
		t.Fatalf("read-only settings request performed %d SQLite writes, want 0", got)
	}

	var payload struct {
		Data struct {
			DefaultStatus string `json:"default_order_item_status"`
			CurrencyCode  string `json:"currency_code"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &payload); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if payload.Data.DefaultStatus != "preparing" || payload.Data.CurrencyCode != "JPY" {
		t.Fatalf("snapshot payload changed: %+v", payload.Data)
	}
}

// Cloud's contract: default_order_item_status is nullable. When the
// shop has never picked one, the JSON value MUST be `null` so pos-web's
// "(System default — Pending)" placeholder renders. An empty string
// would corrupt the select control.
func TestHandleLocalPosOrderSettings_NullDefaultStatus(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	mustExec(t, srv.db, `INSERT INTO shop_settings (key, value) VALUES
		('enable_quick_order','false')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)

	req := httptest.NewRequest("GET", "/api/v1/pos/settings/order", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosOrderSettings(w, req)

	var raw map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &raw); err != nil {
		t.Fatal(err)
	}
	data := raw["data"].(map[string]any)
	if data["default_order_item_status"] != nil {
		t.Errorf("default status: want null, got %v", data["default_order_item_status"])
	}
	if data["enable_quick_order"] != false {
		t.Errorf("enable_quick_order: want false, got %v", data["enable_quick_order"])
	}
}

// plan-051 T3.2 — /settings/order surfaces the resolved void matrix +
// stock_deduction_timing passthrough. Three shapes:
//
//  1. list mirrored → served verbatim (resolved, pending floor applied);
//  2. list absent + legacy flag true → fallback resolves to all four
//     (old-Cloud compatibility, same semantics as the Cloud resolver);
//  3. nothing mirrored → pending-only default + timing "on_close".
func TestHandleLocalPosOrderSettings_VoidMatrixAndTiming(t *testing.T) {
	decode := func(t *testing.T, body []byte) (statuses []any, timing any) {
		t.Helper()
		var raw map[string]any
		if err := json.Unmarshal(body, &raw); err != nil {
			t.Fatal(err)
		}
		data := raw["data"].(map[string]any)
		statuses, _ = data["item_voidable_statuses"].([]any)
		return statuses, data["stock_deduction_timing"]
	}

	// 1. mirrored list + timing
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	mustExec(t, srv.db, `INSERT INTO shop_settings (key, value) VALUES
		('item_voidable_statuses','["pending","preparing"]'),
		('stock_deduction_timing','on_preparing'),
		('allow_item_edit_any_status','false')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)
	w := httptest.NewRecorder()
	srv.handleLocalPosOrderSettings(w, httptest.NewRequest("GET", "/api/v1/pos/settings/order", nil))
	statuses, timing := decode(t, w.Body.Bytes())
	if len(statuses) != 2 || statuses[0] != "pending" || statuses[1] != "preparing" {
		t.Errorf("mirrored matrix want [pending preparing], got %v", statuses)
	}
	if timing != "on_preparing" {
		t.Errorf("stock_deduction_timing want on_preparing, got %v", timing)
	}

	// 2. list absent + legacy flag true → all four (fallback path)
	srv2, _ := newServerWithAuth(t, "http://unused")
	srv2.hub = NewHub()
	mustExec(t, srv2.db, `INSERT INTO shop_settings (key, value) VALUES
		('allow_item_edit_any_status','true')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)
	w2 := httptest.NewRecorder()
	srv2.handleLocalPosOrderSettings(w2, httptest.NewRequest("GET", "/api/v1/pos/settings/order", nil))
	statuses2, _ := decode(t, w2.Body.Bytes())
	if len(statuses2) != 4 {
		t.Errorf("fallback flag=true want all 4 statuses, got %v", statuses2)
	}

	// 3. nothing mirrored → pending-only + on_close default
	srv3, _ := newServerWithAuth(t, "http://unused")
	srv3.hub = NewHub()
	w3 := httptest.NewRecorder()
	srv3.handleLocalPosOrderSettings(w3, httptest.NewRequest("GET", "/api/v1/pos/settings/order", nil))
	statuses3, timing3 := decode(t, w3.Body.Bytes())
	if len(statuses3) != 1 || statuses3[0] != "pending" {
		t.Errorf("default matrix want [pending], got %v", statuses3)
	}
	if timing3 != "on_close" {
		t.Errorf("default stock_deduction_timing want on_close, got %v", timing3)
	}
}
