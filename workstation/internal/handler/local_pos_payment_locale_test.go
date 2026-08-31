package handler

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// The POS payment dialog reads two LAN feeds — the payment-method buttons
// (effective-payment-options) and the tender-brand chips below them
// (till/tender-types). Both used to serve ONE mirrored string, resolved by
// Cloud against `config('app.locale')` because the sync client sends no
// Accept-Language, so a cashier running pos-web in 日本語 read "Cash (internal
// ledger)" and "Credit"/"Transit IC". These pin the per-request resolution
// that replaced it.

func tenderTypesVia(t *testing.T, s *Server, lang string) []map[string]any {
	t.Helper()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/tender-types", nil)
	if lang != "" {
		req.Header.Set("Accept-Language", lang)
	}
	w := httptest.NewRecorder()
	s.handleLocalPosTillTenderTypes(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("[%s] want 200, got %d (%s)", lang, w.Code, w.Body.String())
	}
	var resp struct {
		Data []map[string]any `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	return resp.Data
}

func nameOfTender(rows []map[string]any, key string) string {
	for _, row := range rows {
		if row["tender_key"] == key {
			name, _ := row["name"].(string)
			return name
		}
	}
	return ""
}

func TestLocalPosTenderTypes_NameFollowsAcceptLanguage(t *testing.T) {
	s := newFireTestServer(t)

	mustExec(t, s.db, `
		INSERT INTO till_tender_types
			(id, tender_key, name, name_ja, name_en, name_vi, category, currency_code, sort_order)
		VALUES ('tt-1','credit','Credit','クレジット','Credit','Tín dụng','card','JPY',0)`)
	mustExec(t, s.db, `
		INSERT INTO till_tender_types
			(id, tender_key, name, name_ja, name_en, name_vi, category, currency_code, sort_order)
		VALUES ('tt-2','ic','Transit IC','交通系IC','Transit IC','IC giao thông','emoney','JPY',1)`)
	// A brand the shop never translated: every locale must fall back to the
	// base column instead of rendering an empty chip.
	mustExec(t, s.db, `
		INSERT INTO till_tender_types
			(id, tender_key, name, category, currency_code, sort_order)
		VALUES ('tt-3','paypay','PayPay','qr','JPY',2)`)

	cases := []struct {
		lang, credit, ic string
	}{
		{"ja", "クレジット", "交通系IC"},
		{"en", "Credit", "Transit IC"},
		{"vi", "Tín dụng", "IC giao thông"},
		// Region subtag + quality weights are the shapes a browser actually
		// sends; they must resolve, not silently drop to the default.
		{"vi-VN,vi;q=0.9,en;q=0.8", "Tín dụng", "IC giao thông"},
		// STATED NO LANGUAGE → the untranslated base column, NOT Japanese.
		// This is the case that was reported as "whichever language I pick,
		// everything is Japanese": a client that fails to send the header
		// used to get the whole tender vocabulary in ja, which on screen is
		// indistinguishable from a broken language switch. The honest answer
		// is the value Cloud resolved with its own configured default.
		{"", "Credit", "Transit IC"},
		{"ko", "Credit", "Transit IC"},
	}
	for _, c := range cases {
		t.Run(c.lang, func(t *testing.T) {
			rows := tenderTypesVia(t, s, c.lang)
			if got := nameOfTender(rows, "credit"); got != c.credit {
				t.Errorf("credit: want %q, got %q", c.credit, got)
			}
			if got := nameOfTender(rows, "ic"); got != c.ic {
				t.Errorf("ic: want %q, got %q", c.ic, got)
			}
			if got := nameOfTender(rows, "paypay"); got != "PayPay" {
				t.Errorf("untranslated row must fall back to base name, got %q", got)
			}
		})
	}
}

// A workstation upgraded ahead of Cloud has the columns but no values in them
// (the feed does not send `name_i18n` yet). Every locale must still render the
// mirrored base name rather than an empty chip.
func TestLocalPosTenderTypes_FallsBackWhenCloudSendsNoTranslations(t *testing.T) {
	s := newFireTestServer(t)
	mustExec(t, s.db, `
		INSERT INTO till_tender_types (id, tender_key, name, category, currency_code, sort_order)
		VALUES ('tt-1','credit','Credit','card','JPY',0)`)

	for _, lang := range []string{"ja", "en", "vi"} {
		if got := nameOfTender(tenderTypesVia(t, s, lang), "credit"); got != "Credit" {
			t.Errorf("[%s] want base name %q, got %q", lang, "Credit", got)
		}
	}
}

func seedLocalizedOptionRow(t *testing.T, s *Server, id, base, ja, en, vi string) {
	t.Helper()
	mustExec(t, s.db, `
		INSERT INTO effective_payment_options
			(device_id, channel, id, display_name, display_name_ja, display_name_en,
			 display_name_vi, provider, rail, effective, sort_order)
		VALUES (NULL, 'pos', ?, ?, ?, ?, ?, 'internal', 'cash', 1, 0)`,
		id, base, nullOrText(ja), nullOrText(en), nullOrText(vi))
	mustExec(t, s.db, `
		INSERT INTO payment_policy_snapshot (id, revision, snapshot_hash)
		VALUES (1, 3, 'h') ON CONFLICT(id) DO NOTHING`)
}

func nullOrText(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func optionsForLocale(t *testing.T, s *Server, lang string) []map[string]any {
	t.Helper()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/effective-payment-options", nil)
	req.Header.Set("Accept-Language", lang)
	ctx := context.WithValue(req.Context(), deviceCtxKey, &DeviceContext{
		ID: "pos-1", Type: "pos", BranchID: "br-1", IdentityType: "device",
	})
	w := httptest.NewRecorder()
	s.handleLocalEffectivePaymentOptions(w, req.WithContext(ctx))
	if w.Code != http.StatusOK {
		t.Fatalf("[%s] want 200, got %d (%s)", lang, w.Code, w.Body.String())
	}
	var resp struct {
		Data struct {
			Options []map[string]any `json:"options"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	return resp.Data.Options
}

func TestLocalEffectiveOptions_DisplayNameFollowsAcceptLanguage(t *testing.T) {
	s := newFireTestServer(t)
	seedLocalizedOptionRow(t, s, "opt-cash",
		"Cash (internal ledger)", "現金（内部台帳）", "Cash (internal ledger)", "Tiền mặt (sổ nội bộ)")
	// A connection-backed option carries a method-type slug with nothing to
	// translate — it must survive untouched in every locale.
	seedLocalizedOptionRow(t, s, "opt-stripe", "card_present", "", "", "")

	cases := []struct{ lang, want string }{
		{"ja", "現金（内部台帳）"},
		{"en", "Cash (internal ledger)"},
		{"vi", "Tiền mặt (sổ nội bộ)"},
		// No stated language → base column, never a silent Japanese.
		{"", "Cash (internal ledger)"},
	}
	for _, c := range cases {
		t.Run(c.lang, func(t *testing.T) {
			byID := map[string]string{}
			for _, opt := range optionsForLocale(t, s, c.lang) {
				name, _ := opt["display_name"].(string)
				id, _ := opt["id"].(string)
				byID[id] = name
			}
			if byID["opt-cash"] != c.want {
				t.Errorf("opt-cash: want %q, got %q", c.want, byID["opt-cash"])
			}
			if byID["opt-stripe"] != "card_present" {
				t.Errorf("untranslated option must keep its base display_name, got %q", byID["opt-stripe"])
			}
		})
	}
}
