package service

import (
	"context"
	"database/sql"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Cloud's /api/v1/workstation/branch nests ShopOrderSetting under
// `data.settings`. PullBranch must flatten EVERY key — pre-fix sweep of this
// regression left enable_quick_order (bool) and default_order_item_status
// (nullable string), the two knobs pos-web's Settings → Order page
// exposes, unpinned. (tax_rate flatten is pinned in
// TestPullBranchUpsertsBranchAndFlattensSettings.)
func TestPullBranch_FlattensEnableQuickOrderAndDefaultStatus(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/branch" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
	}))
	cloud.Config.Handler = http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":{
			"id":"br-1","slug":"qpb","name":"Quán Phở Bò",
			"currency":"VND","timezone":"Asia/Tokyo","locale":"vi",
			"settings":{
				"service_charge_rate":"5.00",
				"currency_code":"VND",
				"enable_quick_order":true,
				"default_order_item_status":"ready"
			}
		}}`))
	})
	defer cloud.Close()

	db := newPullerTestDB(t)
	if _, err := db.Exec(`
		CREATE TABLE IF NOT EXISTS branches (
			id TEXT PRIMARY KEY,
			console_branch_id TEXT NOT NULL UNIQUE,
			console_organization_id TEXT NOT NULL,
			slug TEXT NOT NULL,
			name TEXT NOT NULL,
			is_active INTEGER NOT NULL DEFAULT 1,
			timezone TEXT, currency TEXT, locale TEXT,
			updated_at TEXT NOT NULL DEFAULT (datetime('now'))
		)`); err != nil {
		t.Fatal(err)
	}

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullBranch(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	cases := map[string]string{
		"enable_quick_order":        "true",
		"default_order_item_status": "ready",
		"service_charge_rate":       "5.00",
		"currency_code":             "VND",
	}
	for key, want := range cases {
		var got string
		_ = db.QueryRow(`SELECT value FROM shop_settings WHERE key = ?`, key).Scan(&got)
		if got != want {
			t.Errorf("shop_settings.%s want %q, got %q", key, want, got)
		}
	}
}

// enable_quick_order=false (the more common default) must STILL produce
// a "false" row so the absence is unambiguous in shop_settings — pos-web
// reads `value === "true"` and a missing row would short-circuit to
// disabled, which happens to coincide but masks sync bugs.
func TestPullBranch_FlattensFalsyEnableQuickOrder(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":{
			"id":"br-1","slug":"qpb","name":"X","currency":"VND",
			"timezone":"Asia/Tokyo","locale":"vi",
			"settings":{"enable_quick_order":false,"default_order_item_status":null}
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	if _, err := db.Exec(`CREATE TABLE IF NOT EXISTS branches (
		id TEXT PRIMARY KEY, console_branch_id TEXT NOT NULL UNIQUE,
		console_organization_id TEXT NOT NULL, slug TEXT NOT NULL, name TEXT NOT NULL,
		is_active INTEGER NOT NULL DEFAULT 1, timezone TEXT, currency TEXT, locale TEXT,
		updated_at TEXT NOT NULL DEFAULT (datetime('now')))`); err != nil {
		t.Fatal(err)
	}
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullBranch(context.Background()); err != nil {
		t.Fatal(err)
	}

	var quick string
	_ = db.QueryRow(`SELECT value FROM shop_settings WHERE key='enable_quick_order'`).Scan(&quick)
	if quick != "false" {
		t.Errorf("enable_quick_order want 'false' literal, got %q", quick)
	}

	// null → empty string (stringifyValue(nil) == ""). Handler's
	// `readOptional` then surfaces it as JSON null, not "".
	var defStatus sql.NullString
	_ = db.QueryRow(`SELECT value FROM shop_settings WHERE key='default_order_item_status'`).Scan(&defStatus)
	if defStatus.Valid && defStatus.String != "" {
		t.Errorf("null default must stringify to empty, got %q", defStatus.String)
	}
}
