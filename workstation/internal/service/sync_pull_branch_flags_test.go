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

// plan-051 T3.2 — Cloud's branch settings now carry the void-policy trio:
// item_voidable_statuses (JSON array of RESOLVED voidable statuses),
// void_reasons (VoidReason master rows, labels pre-localized) and
// stock_deduction_timing (display-only passthrough). The generic flatten
// stringifies non-scalars via json.Marshal, so the arrays must land as JSON
// TEXT in shop_settings and round-trip through the engine's accessors
// (ResolveVoidableStatuses / ParseVoidReasons).
func TestPullBranch_Plan051_FlattensVoidPolicyKeys(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":{
			"id":"br-1","slug":"qpb","name":"X","currency":"VND",
			"timezone":"Asia/Tokyo","locale":"vi",
			"settings":{
				"allow_item_edit_any_status":true,
				"item_voidable_statuses":["pending","preparing"],
				"stock_deduction_timing":"on_preparing",
				"void_reasons":[
					{"id":"vr-1","label":"Bấm nhầm","stock_effect":"restock","requires_note":false,"sort_order":0},
					{"id":"vr-2","label":"Comp cho khách","stock_effect":"none","requires_note":true,"sort_order":4}
				]
			}
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
		t.Fatalf("PullBranch: %v", err)
	}

	readKey := func(key string) string {
		var v string
		_ = db.QueryRow(`SELECT value FROM shop_settings WHERE key = ?`, key).Scan(&v)
		return v
	}

	if got := readKey("stock_deduction_timing"); got != "on_preparing" {
		t.Errorf("stock_deduction_timing want on_preparing, got %q", got)
	}
	if got := readKey("allow_item_edit_any_status"); got != "true" {
		t.Errorf("allow_item_edit_any_status want 'true', got %q", got)
	}

	// Array keys → JSON TEXT that the shared parsers understand.
	statuses := ResolveVoidableStatuses(readKey("item_voidable_statuses"), false)
	if want := []string{"pending", "preparing"}; len(statuses) != 2 || statuses[0] != want[0] || statuses[1] != want[1] {
		t.Errorf("item_voidable_statuses round-trip want %v, got %v", want, statuses)
	}
	reasons := ParseVoidReasons(readKey("void_reasons"))
	if len(reasons) != 2 {
		t.Fatalf("void_reasons round-trip want 2 rows, got %d (raw=%q)", len(reasons), readKey("void_reasons"))
	}
	if reasons[0].ID != "vr-1" || reasons[0].StockEffect != "restock" || reasons[0].Label != "Bấm nhầm" {
		t.Errorf("reason[0] mangled: %+v", reasons[0])
	}
	if reasons[1].ID != "vr-2" || !reasons[1].RequiresNote || reasons[1].SortOrder != 4 {
		t.Errorf("reason[1] mangled: %+v", reasons[1])
	}
}
