package service

import (
	"testing"
	"time"

	"github.com/google/uuid"
)

// applies_to=all_items must match every product without a pivot entry.
// Pre-028 the engine always JOINed menu_promotion_products, so all_items
// promotions silently never applied in LAN mode.
func TestPromotion_AppliesToAllItems_NoPivotNeeded(t *testing.T) {
	db := newPromoTestDB(t)

	id := uuid.NewString()
	if _, err := db.Exec(`
		INSERT INTO menu_promotions (id, name, discount_type, discount_value,
		    is_active, applies_to, exclusive_with_coupons, priority)
		VALUES (?, 'All Items HH', 'percent', 10, 1, 'all_items', 0, 100)`,
		id); err != nil {
		t.Fatal(err)
	}
	// Note: no menu_promotion_products row, no pos_product_skus row
	// either — the engine must still match because applies_to='all_items'.
	skuID := uuid.NewString()
	// Seed the SKU row so the engine's resolved product lookup doesn't
	// fail before reaching the applies_to=all_items branch.
	_, _ = db.Exec(`INSERT INTO pos_products (id, name) VALUES ('p-any', 'p')`)
	_, _ = db.Exec(
		`INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES (?, 'p-any', '', '', 0)`,
		skuID,
	)

	p := NewPromotionEngine(db)
	final, match, err := p.ApplyToItem(skuID, 1000, time.Now())
	if err != nil {
		t.Fatalf("apply: %v", err)
	}
	if match == nil {
		t.Fatal("applies_to=all_items must match SKU without pivot row")
	}
	if final != 900 {
		t.Errorf("10%% off 1000 = 900, got %d", final)
	}
}

// applies_to=products must NOT match a SKU whose product isn't in the
// pivot — preserves the old behaviour where SKU-level scoping required
// an explicit pivot row.
func TestPromotion_AppliesToProducts_RequiresPivot(t *testing.T) {
	db := newPromoTestDB(t)

	id := uuid.NewString()
	if _, err := db.Exec(`
		INSERT INTO menu_promotions (id, name, discount_type, discount_value,
		    is_active, applies_to, exclusive_with_coupons, priority)
		VALUES (?, 'Scoped HH', 'percent', 10, 1, 'products', 0, 100)`,
		id); err != nil {
		t.Fatal(err)
	}
	// Seed a SKU but no pivot row → no match.
	otherSku := uuid.NewString()
	_, _ = db.Exec(`INSERT INTO pos_products (id, name) VALUES ('p-other', 'p')`)
	_, _ = db.Exec(
		`INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES (?, 'p-other', '', '', 0)`,
		otherSku,
	)

	p := NewPromotionEngine(db)
	_, match, err := p.ApplyToItem(otherSku, 1000, time.Now())
	if err != nil {
		t.Fatalf("apply: %v", err)
	}
	if match != nil {
		t.Errorf("applies_to=products with no pivot must not match, got %+v", match)
	}
}

// Tie-breaker: when two promotions match the same SKU with the SAME
// discount_value, the one with the EARLIER promo_created_at wins.
// Backend MenuPromotionService::resolveForItem decision Q-B3.
func TestPromotion_TieBreaker_EarliestCreatedAtWins(t *testing.T) {
	db := newPromoTestDB(t)

	skuID := uuid.NewString()
	productID := "p-" + skuID
	_, _ = db.Exec(`INSERT INTO pos_products (id, name) VALUES (?, 'p')`, productID)
	_, _ = db.Exec(
		`INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES (?, ?, '', '', 0)`,
		skuID, productID,
	)

	older := "z-" + uuid.NewString() // larger lex id but earlier created_at
	newer := "a-" + uuid.NewString() // smaller lex id but later created_at
	_, _ = db.Exec(`
		INSERT INTO menu_promotions (id, name, discount_type, discount_value,
		    is_active, applies_to, exclusive_with_coupons, priority, promo_created_at)
		VALUES (?, 'OLDER', 'percent', 10, 1, 'products', 0, 100, '2025-01-01T00:00:00Z')`,
		older)
	_, _ = db.Exec(`
		INSERT INTO menu_promotions (id, name, discount_type, discount_value,
		    is_active, applies_to, exclusive_with_coupons, priority, promo_created_at)
		VALUES (?, 'NEWER', 'percent', 10, 1, 'products', 0, 100, '2026-01-01T00:00:00Z')`,
		newer)
	_, _ = db.Exec(`INSERT INTO menu_promotion_products (promotion_id, product_id) VALUES (?, ?)`, older, productID)
	_, _ = db.Exec(`INSERT INTO menu_promotion_products (promotion_id, product_id) VALUES (?, ?)`, newer, productID)

	p := NewPromotionEngine(db)
	_, match, err := p.ApplyToItem(skuID, 1000, time.Now())
	if err != nil || match == nil {
		t.Fatalf("apply: err=%v match=%v", err, match)
	}
	if match.ID != older {
		t.Errorf("tie-breaker: want older promo %q, got %q (name=%s)", older, match.ID, match.Name)
	}
}

// Tie-breaker primary axis: higher discount_value wins regardless of
// created_at. Pre-028 the engine sorted on `priority` first — Cloud
// always emits priority=100 so this axis was effectively dead.
func TestPromotion_TieBreaker_HigherDiscountValueWins(t *testing.T) {
	db := newPromoTestDB(t)
	skuID := uuid.NewString()
	productID := "p-" + skuID
	_, _ = db.Exec(`INSERT INTO pos_products (id, name) VALUES (?, 'p')`, productID)
	_, _ = db.Exec(
		`INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES (?, ?, '', '', 0)`,
		skuID, productID,
	)

	weakID := uuid.NewString()
	strongID := uuid.NewString()
	_, _ = db.Exec(`
		INSERT INTO menu_promotions (id, name, discount_type, discount_value,
		    is_active, applies_to, exclusive_with_coupons, priority, promo_created_at)
		VALUES (?, 'WEAK', 'percent', 5, 1, 'products', 0, 100, '2024-01-01T00:00:00Z')`,
		weakID)
	_, _ = db.Exec(`
		INSERT INTO menu_promotions (id, name, discount_type, discount_value,
		    is_active, applies_to, exclusive_with_coupons, priority, promo_created_at)
		VALUES (?, 'STRONG', 'percent', 25, 1, 'products', 0, 100, '2026-01-01T00:00:00Z')`,
		strongID)
	_, _ = db.Exec(`INSERT INTO menu_promotion_products (promotion_id, product_id) VALUES (?, ?)`, weakID, productID)
	_, _ = db.Exec(`INSERT INTO menu_promotion_products (promotion_id, product_id) VALUES (?, ?)`, strongID, productID)

	p := NewPromotionEngine(db)
	_, match, err := p.ApplyToItem(skuID, 1000, time.Now())
	if err != nil || match == nil {
		t.Fatalf("apply: %v %v", err, match)
	}
	if match.ID != strongID {
		t.Errorf("higher discount_value should win: want %q got %q", strongID, match.ID)
	}
}
