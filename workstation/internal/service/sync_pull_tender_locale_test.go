package service

import (
	"context"
	"database/sql"
	"net/http"
	"net/http/httptest"
	"testing"
)

// The tender + payment-option mirrors must carry EVERY locale, not one
// resolved string. cloudGet sends no Accept-Language, so a single mirrored
// name is whatever `config('app.locale')` is on Cloud — served to every
// terminal in the shop regardless of the language its cashier picked. These
// pin the per-locale columns the LAN handlers read.

const tenderTypesI18nBody = `{"data":[
	{
		"id": "tt-1",
		"tender_key": "credit",
		"name": "Credit",
		"name_i18n": {"ja": "クレジット", "en": "Credit", "vi": "Tín dụng"},
		"category": "card",
		"parent_tender_key": null,
		"currency_code": "JPY",
		"payment_method_code": "card",
		"is_expected_anchor": true,
		"requires_terminal_total": true,
		"sort_order": 1
	},
	{
		"id": "tt-2",
		"tender_key": "paypay",
		"name": "PayPay",
		"name_i18n": {"ja": "PayPay", "en": "PayPay", "vi": "PayPay"},
		"category": "qr",
		"parent_tender_key": null,
		"currency_code": "JPY",
		"payment_method_code": null,
		"is_expected_anchor": false,
		"requires_terminal_total": true,
		"sort_order": 2
	}
]}`

func TestPullTenderTypesStoresEveryLocale(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/till-tender-types" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(tenderTypesI18nBody))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullTenderTypes(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var base string
	var ja, en, vi sql.NullString
	if err := db.QueryRow(`
		SELECT name, name_ja, name_en, name_vi
		FROM till_tender_types WHERE tender_key = 'credit'`,
	).Scan(&base, &ja, &en, &vi); err != nil {
		t.Fatalf("read row: %v", err)
	}
	if ja.String != "クレジット" || en.String != "Credit" || vi.String != "Tín dụng" {
		t.Errorf("locales dropped: ja=%q en=%q vi=%q", ja.String, en.String, vi.String)
	}
	if base == "" {
		t.Errorf("base name must stay populated as the untranslated fallback")
	}
}

// A Cloud older than the feed change sends no `name_i18n`. The pull must still
// land the row with its base name — an empty `name` would render a nameless,
// untappable chip in the payment dialog.
func TestPullTenderTypesWithoutI18nKeepsBaseName(t *testing.T) {
	const body = `{"data":[{
		"id": "tt-1", "tender_key": "credit", "name": "Credit", "category": "card",
		"currency_code": "JPY", "is_expected_anchor": true,
		"requires_terminal_total": true, "sort_order": 1
	}]}`
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(body))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullTenderTypes(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var base string
	var ja sql.NullString
	if err := db.QueryRow(`SELECT name, name_ja FROM till_tender_types WHERE tender_key = 'credit'`).
		Scan(&base, &ja); err != nil {
		t.Fatalf("read row: %v", err)
	}
	if base != "Credit" {
		t.Errorf("want base name %q, got %q", "Credit", base)
	}
	if ja.Valid {
		t.Errorf("no translation sent → column must stay NULL so COALESCE falls back, got %q", ja.String)
	}
}

// `name` is NOT NULL in SQLite. When Cloud resolved no name but DID send
// translations, the base column takes one of them — a human-written word beats
// the raw key for any locale that has no column of its own.
func TestPullTenderTypesBaseNameFallsBackToATranslation(t *testing.T) {
	const body = `{"data":[{
		"id": "tt-1", "tender_key": "momo", "name": null,
		"name_i18n": {"vi": "Ví MoMo"},
		"category": "qr", "currency_code": "VND", "sort_order": 1
	}]}`
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(body))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullTenderTypes(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var base string
	var vi sql.NullString
	if err := db.QueryRow(`SELECT name, name_vi FROM till_tender_types WHERE tender_key = 'momo'`).
		Scan(&base, &vi); err != nil {
		t.Fatalf("read row: %v", err)
	}
	if base != "Ví MoMo" || vi.String != "Ví MoMo" {
		t.Errorf("want the translation in both columns, got base=%q vi=%q", base, vi.String)
	}
}

// Nothing to name it with at all → the tender key, so the chip stays tappable.
func TestPullTenderTypesFallsBackToTenderKeyWhenUnnamed(t *testing.T) {
	const body = `{"data":[{
		"id": "tt-1", "tender_key": "credit", "name": null, "category": "card",
		"currency_code": "JPY", "sort_order": 1
	}]}`
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(body))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullTenderTypes(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var base string
	if err := db.QueryRow(`SELECT name FROM till_tender_types WHERE tender_key = 'credit'`).
		Scan(&base); err != nil {
		t.Fatalf("read row: %v", err)
	}
	if base != "credit" {
		t.Errorf("want tender_key fallback %q, got %q", "credit", base)
	}
}

const effectiveOptionsI18nBody = `{"data": {
	"branch": {
		"pos": {
			"revision": 4,
			"snapshot_hash": "hash-i18n",
			"ownership_revision": "own-rev-1",
			"published_at": "2026-08-06T00:00:00Z",
			"options": [
				{
					"id": "opt-cash",
					"display_name": "Cash (internal ledger)",
					"display_name_i18n": {
						"ja": "現金（内部台帳）",
						"en": "Cash (internal ledger)",
						"vi": "Tiền mặt (sổ nội bộ)"
					},
					"provider": "internal",
					"rail": "cash",
					"effective": true,
					"source": "internal_catalog",
					"reason": "internal_tender",
					"shop_preference": "inherit",
					"device_preference": "inherit",
					"trace": []
				},
				{
					"id": "opt-stripe",
					"display_name": "card_present",
					"provider": "stripe",
					"rail": "card",
					"effective": true,
					"source": "policy",
					"reason": "allowed",
					"shop_preference": "inherit",
					"device_preference": "inherit",
					"trace": []
				}
			]
		}
	},
	"devices": []
}}`

func TestPullEffectivePaymentOptionsStoresEveryLocale(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(effectiveOptionsI18nBody))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullEffectivePaymentOptions(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var base string
	var ja, en, vi sql.NullString
	if err := db.QueryRow(`
		SELECT display_name, display_name_ja, display_name_en, display_name_vi
		FROM effective_payment_options WHERE id = 'opt-cash' AND channel = 'pos'`,
	).Scan(&base, &ja, &en, &vi); err != nil {
		t.Fatalf("read cash row: %v", err)
	}
	if ja.String != "現金（内部台帳）" || vi.String != "Tiền mặt (sổ nội bộ)" || en.String != "Cash (internal ledger)" {
		t.Errorf("locales dropped: ja=%q en=%q vi=%q", ja.String, en.String, vi.String)
	}

	// A connection-backed option has no translatable label; its locale columns
	// must stay NULL so the LAN COALESCE falls back to display_name.
	var slug string
	var slugJA sql.NullString
	if err := db.QueryRow(`
		SELECT display_name, display_name_ja
		FROM effective_payment_options WHERE id = 'opt-stripe' AND channel = 'pos'`,
	).Scan(&slug, &slugJA); err != nil {
		t.Fatalf("read stripe row: %v", err)
	}
	if slug != "card_present" || slugJA.Valid {
		t.Errorf("untranslated option corrupted: display_name=%q ja_valid=%v", slug, slugJA.Valid)
	}
}
