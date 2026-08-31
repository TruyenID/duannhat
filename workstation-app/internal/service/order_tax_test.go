package service

import "testing"

// legacyTaxRate prefers the per-shop shop_settings.tax_rate (synced from Cloud
// as a percent decimal string), falling back to the engine's config rate, then
// to 0. plan-043 (T3.4) REMOVED the old hard-coded 10% fallback — an un-synced
// shop with no config rate now prices at 0%, never an invented 10%.
func TestLegacyTaxRate(t *testing.T) {
	e, db := newOrderEngineForTest(t)

	// No shop_settings row + engine built with rate 0 → 0 (NO hard 10% anymore).
	if got := e.legacyTaxRate(); got != 0 {
		t.Errorf("default rate = %g, want 0 (no hard-10%% fallback)", got)
	}

	// Per-shop value wins.
	if _, err := db.Exec(`INSERT INTO shop_settings (key, value) VALUES ('tax_rate', '8.00')`); err != nil {
		t.Fatalf("seed: %v", err)
	}
	if got := e.legacyTaxRate(); got != 8 {
		t.Errorf("per-shop rate = %g, want 8", got)
	}
}

func TestLegacyTaxRate_ConfigFallback(t *testing.T) {
	e, _ := newOrderEngineForTest(t)
	// No shop_settings row → config rate wins over the 0 default.
	e.taxRate = 5
	if got := e.legacyTaxRate(); got != 5 {
		t.Errorf("config fallback rate = %g, want 5", got)
	}
}
