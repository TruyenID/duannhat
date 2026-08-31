package service

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

// #2188 — PullBranch still flattens whatever data.settings.* Cloud ships into
// shop_settings (generic flatten, kept on purpose), but the engine no longer
// READS a flat tax_rate: `legacyTaxRate` was deleted with the legacy ruling,
// and an unstamped line is DROPPED (with a warning), never priced at a synced
// or config fallback rate. This test pins both halves: the flatten survives,
// the fallback does not.
func TestBranchTaxRateFlattensButNoLongerPricesAnything(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/branch" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		// Cloud (Workstation\BranchController) still ships the authoritative
		// per-branch tax_rate under data.settings.tax_rate.
		w.Write([]byte(`{"data":{
			"id":"br-1","slug":"qpb","name":"Quán Phở Bò",
			"currency":"VND","timezone":"Asia/Tokyo","locale":"vi",
			"settings":{"tax_rate":"10.00","service_charge_rate":"0.00","currency_code":"VND"}
		}}`))
	}))
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

	// 1. Sync DOWN the branch settings.
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullBranch(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	// 2. The branch rate must have landed in shop_settings.
	var raw string
	if err := db.QueryRow(`SELECT value FROM shop_settings WHERE key = 'tax_rate'`).Scan(&raw); err != nil {
		t.Fatalf("branch tax_rate did not flatten into shop_settings: %v", err)
	}
	if raw != "10.00" {
		t.Fatalf("shop_settings.tax_rate = %q, want 10.00", raw)
	}

	// 3. The flattened rate is INERT: an unstamped line is dropped (nothing to
	//    group it under), never priced at the synced flat rate. 0 across the
	//    board — a visibly short total, warned in the log, not an invented 10%.
	e := NewOrderEngine(db)
	items := []Item{{Quantity: 1, UnitPrice: 1000}}
	tax, serviceCharge, total := e.computeOrderTotalsForItems(items, 0, false)
	if tax != 0 || serviceCharge != 0 || total != 0 {
		t.Errorf("unstamped line after PullBranch must price NOTHING (drop + warn, #2188), got tax=%g svc=%d total=%d",
			tax, serviceCharge, total)
	}
}
