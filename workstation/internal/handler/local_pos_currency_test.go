package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// BR-SOS06 — when admin sets a per-shop currency in Settings → Order,
// PullBranch writes it to shop_settings.currency_code. The endpoint
// must surface that value (overriding the branch-level fallback) so
// pos-web's formatCurrency switches symbols immediately on next poll.
func TestHandleLocalPosOrderSettings_PrefersCurrencyCodeOverBranchCurrency(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	mustExec(t, srv.db, `INSERT INTO shop_settings (key, value) VALUES
		('currency_code','JPY'),
		('currency','VND')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)

	req := httptest.NewRequest("GET", "/api/v1/pos/settings/order", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosOrderSettings(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d", w.Code)
	}
	var resp struct {
		Data struct {
			CurrencyCode string `json:"currency_code"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}
	if resp.Data.CurrencyCode != "JPY" {
		t.Errorf("currency_code must win over legacy `currency`, got %q", resp.Data.CurrencyCode)
	}
}

// Branch-level fallback — Cloud's ShopOrderSetting row may not exist
// for a brand-new shop; PullBranch still writes `shop_settings.currency`
// from the `branches.currency` column. The handler must fall back to
// this instead of returning a hard-coded "VND" the cashier never picked.
func TestHandleLocalPosOrderSettings_FallsBackToBranchCurrency(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	mustExec(t, srv.db, `INSERT INTO shop_settings (key, value) VALUES ('currency','JPY')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)

	req := httptest.NewRequest("GET", "/api/v1/pos/settings/order", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosOrderSettings(w, req)

	var resp struct {
		Data struct {
			CurrencyCode string `json:"currency_code"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}
	if resp.Data.CurrencyCode != "JPY" {
		t.Errorf("branch-currency fallback expected JPY, got %q", resp.Data.CurrencyCode)
	}
}

// Empty shop_settings → "VND" default (Vietnam-market default,
// matches Cloud's ShopOrderSettingsController::show fallback).
func TestHandleLocalPosOrderSettings_DefaultsToVNDWhenEverythingMissing(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	req := httptest.NewRequest("GET", "/api/v1/pos/settings/order", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosOrderSettings(w, req)

	var resp struct {
		Data struct {
			CurrencyCode string `json:"currency_code"`
		} `json:"data"`
	}
	json.Unmarshal(w.Body.Bytes(), &resp)
	if resp.Data.CurrencyCode != "VND" {
		t.Errorf("no settings → VND default, got %q", resp.Data.CurrencyCode)
	}
}

// TestShopCurrency_ReadsSyncedNotHardcoded (plan-043 T3.6) — the kiosk bill
// currency is the SYNCED shop currency, no longer the hard-coded "JPY". A shop
// synced as VND reports VND; currency_code wins over the legacy currency key.
func TestShopCurrency_ReadsSyncedNotHardcoded(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")

	// currency_code wins.
	mustExec(t, srv.db, `INSERT INTO shop_settings (key, value) VALUES ('currency_code','THB'),('currency','VND')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)
	if got := srv.shopCurrency(); got != "THB" {
		t.Errorf("shopCurrency = %q, want THB (synced currency_code, not hardcoded JPY)", got)
	}

	// legacy currency fallback when currency_code absent.
	srv2, _ := newServerWithAuth(t, "http://unused")
	mustExec(t, srv2.db, `INSERT INTO shop_settings (key, value) VALUES ('currency','VND')`)
	if got := srv2.shopCurrency(); got != "VND" {
		t.Errorf("shopCurrency fallback = %q, want VND", got)
	}

	// absolute default.
	srv3, _ := newServerWithAuth(t, "http://unused")
	if got := srv3.shopCurrency(); got != "VND" {
		t.Errorf("shopCurrency default = %q, want VND", got)
	}
}
