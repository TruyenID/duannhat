package store

import (
	"path/filepath"
	"testing"
)

// Migration 017 drops the legacy CHECK(discount_type IN ('flat','percent'))
// that migration 009 baked in. Without this, PullCoupons would crash on
// every Cloud row whose discount_type is 'fixed' (the Cloud enum's actual
// value). Lock the new behaviour: an INSERT with 'fixed' must succeed.
func TestMigration017_CouponsAcceptFixedDiscountType(t *testing.T) {
	db, err := Open(filepath.Join(t.TempDir(), "m017.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	// Should not violate any CHECK constraint.
	_, err = db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode)
		VALUES ('cp-1', 'CLOUDFIX', 'cloud-aligned',
		        'fixed', 100, 0, 'active', 'exclusive')`)
	if err != nil {
		t.Fatalf("INSERT discount_type='fixed' should succeed after 017: %v", err)
	}

	var got string
	if err := db.QueryRow("SELECT discount_type FROM coupons WHERE id = 'cp-1'").Scan(&got); err != nil {
		t.Fatalf("readback: %v", err)
	}
	if got != "fixed" {
		t.Errorf("want 'fixed', got %q", got)
	}

	// Legacy 'flat' should still go in (controller treats it as alias).
	if _, err := db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode)
		VALUES ('cp-2', 'LEGACYFLAT', 'legacy',
		        'flat', 100, 0, 'active', 'exclusive')`); err != nil {
		t.Errorf("legacy 'flat' should still INSERT: %v", err)
	}

	// 'percent' continues to work.
	if _, err := db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode)
		VALUES ('cp-3', 'PCT', 'pct',
		        'percent', 1000, 0, 'active', 'exclusive')`); err != nil {
		t.Errorf("'percent' should INSERT: %v", err)
	}
}

// LAN-offline ext columns from migration 016 must survive the recreate.
func TestMigration017_PreservesExtensionColumns(t *testing.T) {
	db, err := Open(filepath.Join(t.TempDir(), "m017b.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, err = db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode,
		    exclusive_with_promotions, usage_limit_per_customer, applies_to)
		VALUES ('cp-ext', 'EXTCOL', 'ext',
		        'fixed', 100, 0, 'active', 'exclusive',
		        1, 2, 'order')`)
	if err != nil {
		t.Fatalf("write extended cols: %v", err)
	}

	var excl, perCust int
	var appliesTo string
	if err := db.QueryRow(`
		SELECT exclusive_with_promotions, usage_limit_per_customer, applies_to
		FROM coupons WHERE id = 'cp-ext'`).Scan(&excl, &perCust, &appliesTo); err != nil {
		t.Fatalf("readback: %v", err)
	}
	if excl != 1 || perCust != 2 || appliesTo != "order" {
		t.Errorf("extension cols not preserved: excl=%d per=%d applies=%q", excl, perCust, appliesTo)
	}
}
