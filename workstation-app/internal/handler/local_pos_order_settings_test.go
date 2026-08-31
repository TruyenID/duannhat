package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
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
