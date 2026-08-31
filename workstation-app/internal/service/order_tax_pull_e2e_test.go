package service

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

// BLOCKER-WS-TAX — end-to-end pin: a non-zero branch tax_rate shipped by Cloud's
// /workstation/branch payload must flow PullBranch → shop_settings.tax_rate →
// OrderEngine.legacyTaxRate → order tax. The plan-043 T6.2/T6.3 change skipped +
// deleted the flat tax_rate on branch pull; because the per-line tax_types /
// default_tax_type_id cloud phases plan-043 presupposed were never built, EVERY
// line falls back to legacyTaxRate, so dropping the branch rate zeroed all
// workstation/LAN/kiosk order tax. This test would have failed with tax=0 under
// the reverted change.
func TestBranchTaxRateFlowsPullBranchToOrderTax(t *testing.T) {
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

	// 3. Engine built with config rate 0 — proving the BRANCH rate (not a
	//    hardcoded/config value) is what drives the order tax.
	e := NewOrderEngine(db, 0)
	if got := e.legacyTaxRate(); got != 10 {
		t.Fatalf("legacyTaxRate after PullBranch = %g, want 10 (branch rate)", got)
	}

	// 4. A line with no per-line tax snapshot (TaxRate nil — the reality today)
	//    must be taxed at the branch rate. Tax-excluded: 10% of 1000 = 100.
	items := []Item{{Quantity: 1, UnitPrice: 1000}}
	tax, serviceCharge, total := e.computeOrderTotalsForItems(items, 0, false)
	if tax != 100 {
		t.Errorf("order tax = %g, want 100 (10%% of 1000) — 0 means the branch rate was dropped", tax)
	}
	if serviceCharge != 0 {
		t.Errorf("service charge = %d, want 0", serviceCharge)
	}
	if total != 1100 {
		t.Errorf("order total = %d, want 1100 (1000 + 100 tax)", total)
	}
}

// Regression contrast: with NO branch rate synced and an engine config rate of
// 0, tax is 0 — this documents exactly the 0%-tax state the dropped branch
// tax_rate produced, so the fix's dependency on the sync is explicit.
func TestNoBranchRateAndZeroConfigYieldsZeroTax(t *testing.T) {
	db := newPullerTestDB(t)
	e := NewOrderEngine(db, 0)
	items := []Item{{Quantity: 1, UnitPrice: 1000}}
	if tax, _, _ := e.computeOrderTotalsForItems(items, 0, false); tax != 0 {
		t.Errorf("tax with no synced rate + zero config = %g, want 0", tax)
	}
}
