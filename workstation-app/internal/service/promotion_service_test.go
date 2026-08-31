package service

import (
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

func newPromoTestDB(t *testing.T) *store.DB {
	t.Helper()
	db, err := store.Open(filepath.Join(t.TempDir(), "promo.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return db
}

func seedPromo(t *testing.T, db *store.DB, opts seedPromoOpts) string {
	t.Helper()
	id := uuid.NewString()
	// applies_to defaults to 'products' (migration 028 ADD COLUMN
	// default) — the engine requires a pivot row to match in that
	// case. seedPromo takes "skuIDs" for backwards-compat with the
	// pre-028 fixture API but actually keys the pivot on the SKU's
	// product_id by seeding pos_products + pos_product_skus on the
	// fly so the engine's SKU → product resolution finds the row.
	if _, err := db.Exec(`
		INSERT INTO menu_promotions (id, name, discount_type, discount_value,
		    is_active, exclusive_with_coupons, priority, applies_to)
		VALUES (?, ?, ?, ?, 1, ?, ?, COALESCE(NULLIF(?, ''), 'products'))`,
		id, opts.name, opts.discountType, opts.discountValue,
		boolInt(opts.excl), opts.priority, opts.appliesTo); err != nil {
		t.Fatalf("insert promo: %v", err)
	}
	for _, sku := range opts.skuIDs {
		// Pivot the SKU to a synthetic product so engine's
		// SKU → product_id lookup succeeds. Re-using sku as product
		// is fine for these tests because the engine only cares
		// about pivot membership, not the actual product row.
		productID := "p-" + sku
		_, _ = db.Exec(
			`INSERT OR IGNORE INTO pos_products (id, name) VALUES (?, ?)`,
			productID, "test-prod-"+sku,
		)
		_, _ = db.Exec(
			`INSERT OR IGNORE INTO pos_product_skus (id, product_id, name, sku, selling_price)
			 VALUES (?, ?, '', '', 0)`,
			sku, productID,
		)
		if _, err := db.Exec(
			"INSERT OR IGNORE INTO menu_promotion_products (promotion_id, product_id) VALUES (?, ?)",
			id, productID); err != nil {
			t.Fatalf("insert product link: %v", err)
		}
	}
	if opts.dayOfWeek != nil || opts.timeFrom != "" || opts.timeTo != "" {
		var dow any
		if opts.dayOfWeek != nil {
			dow = *opts.dayOfWeek
		}
		if _, err := db.Exec(`
			INSERT INTO menu_promotion_schedules (id, promotion_id, day_of_week,
			    daily_time_from, daily_time_to)
			VALUES (?, ?, ?, ?, ?)`,
			uuid.NewString(), id, dow,
			nullIfEmptyStr(opts.timeFrom), nullIfEmptyStr(opts.timeTo)); err != nil {
			t.Fatalf("insert schedule: %v", err)
		}
	}
	return id
}

type seedPromoOpts struct {
	name          string
	discountType  string
	discountValue int
	excl          bool
	priority      int
	skuIDs        []string
	dayOfWeek     *int
	timeFrom      string
	timeTo        string
	appliesTo     string // "all_items" | "products" | "categories" | "mixed" — defaults to "products"
}

func boolInt(b bool) int {
	if b {
		return 1
	}
	return 0
}

func nullIfEmptyStr(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func TestPromotion_PercentDiscount(t *testing.T) {
	db := newPromoTestDB(t)
	skuID := uuid.NewString()
	seedPromo(t, db, seedPromoOpts{
		name: "HH 20%", discountType: "percent", discountValue: 20, // 20%
		skuIDs: []string{skuID}, priority: 100,
	})
	p := NewPromotionEngine(db)
	final, match, err := p.ApplyToItem(skuID, 1000, time.Now())
	if err != nil {
		t.Fatalf("apply: %v", err)
	}
	if match == nil {
		t.Fatal("expected match")
	}
	if final != 800 {
		t.Errorf("want 800 got %d", final)
	}
}

func TestPromotion_AmountDiscount(t *testing.T) {
	db := newPromoTestDB(t)
	skuID := uuid.NewString()
	seedPromo(t, db, seedPromoOpts{
		discountType: "amount", discountValue: 300,
		skuIDs: []string{skuID},
	})
	p := NewPromotionEngine(db)
	final, _, _ := p.ApplyToItem(skuID, 1000, time.Now())
	if final != 700 {
		t.Errorf("want 700 got %d", final)
	}
}

func TestPromotion_FixedUnitPrice(t *testing.T) {
	db := newPromoTestDB(t)
	skuID := uuid.NewString()
	seedPromo(t, db, seedPromoOpts{
		discountType: "fixed_unit_price", discountValue: 500,
		skuIDs: []string{skuID},
	})
	p := NewPromotionEngine(db)
	final, _, _ := p.ApplyToItem(skuID, 1000, time.Now())
	if final != 500 {
		t.Errorf("want 500 got %d", final)
	}
}

func TestPromotion_NoMatchWhenSkuNotInList(t *testing.T) {
	db := newPromoTestDB(t)
	seedPromo(t, db, seedPromoOpts{
		discountType: "percent", discountValue: 50,
		skuIDs: []string{uuid.NewString()},
	})
	p := NewPromotionEngine(db)
	final, match, _ := p.ApplyToItem("different-sku", 1000, time.Now())
	if match != nil {
		t.Error("should not match")
	}
	if final != 1000 {
		t.Errorf("price should be unchanged, got %d", final)
	}
}

func TestPromotion_DayOfWeekFilter(t *testing.T) {
	db := newPromoTestDB(t)
	skuID := uuid.NewString()
	// Only matches on Sunday (0).
	sunday := 0
	seedPromo(t, db, seedPromoOpts{
		discountType: "percent", discountValue: 50,
		skuIDs:    []string{skuID},
		dayOfWeek: &sunday,
	})
	p := NewPromotionEngine(db)
	// Pick a known Monday: 2026-06-15 is a Monday.
	mon := time.Date(2026, 6, 15, 12, 0, 0, 0, time.UTC)
	final, match, _ := p.ApplyToItem(skuID, 1000, mon)
	if match != nil {
		t.Error("Monday should not match Sunday promo")
	}
	if final != 1000 {
		t.Errorf("expected unchanged 1000, got %d", final)
	}
	// Sunday matches.
	sun := time.Date(2026, 6, 14, 12, 0, 0, 0, time.UTC)
	final, match, _ = p.ApplyToItem(skuID, 1000, sun)
	if match == nil {
		t.Error("Sunday should match")
	}
	if final != 500 {
		t.Errorf("want 500 got %d", final)
	}
}

func TestPromotion_TimeWindowFilter(t *testing.T) {
	db := newPromoTestDB(t)
	skuID := uuid.NewString()
	seedPromo(t, db, seedPromoOpts{
		discountType: "percent", discountValue: 50,
		skuIDs:   []string{skuID},
		timeFrom: "11:00", timeTo: "14:00",
	})
	p := NewPromotionEngine(db)
	// 12:00 → inside window
	in := time.Date(2026, 6, 15, 12, 0, 0, 0, time.UTC)
	_, match, _ := p.ApplyToItem(skuID, 1000, in)
	if match == nil {
		t.Error("12:00 should be inside [11:00, 14:00]")
	}
	// 10:00 → before window
	out := time.Date(2026, 6, 15, 10, 0, 0, 0, time.UTC)
	_, match, _ = p.ApplyToItem(skuID, 1000, out)
	if match != nil {
		t.Error("10:00 should be outside")
	}
}

func TestPromotion_PriorityWinsTies(t *testing.T) {
	db := newPromoTestDB(t)
	skuID := uuid.NewString()
	seedPromo(t, db, seedPromoOpts{
		name: "low", discountType: "amount", discountValue: 100,
		priority: 50, skuIDs: []string{skuID},
	})
	seedPromo(t, db, seedPromoOpts{
		name: "high", discountType: "amount", discountValue: 500,
		priority: 200, skuIDs: []string{skuID},
	})
	p := NewPromotionEngine(db)
	final, match, _ := p.ApplyToItem(skuID, 1000, time.Now())
	if match == nil || match.Name != "high" {
		t.Errorf("higher priority should win; got %+v", match)
	}
	if final != 500 {
		t.Errorf("want 500 got %d", final)
	}
}

func TestApplyDiscount_PercentEdgeCases(t *testing.T) {
	// discount_value is a PLAIN PERCENT (Cloud: 15% → 15), not basis points.
	// 15% off 1000 → 850 (was applied as 0.15% = ~2 off under the old / 10000 bug).
	if got := applyDiscount(1000, "percent", 15); got != 850 {
		t.Errorf("15%% off 1000 should be 850, got %d", got)
	}
	// 20% off 1000 → 800
	if got := applyDiscount(1000, "percent", 20); got != 800 {
		t.Errorf("20%% off 1000 should be 800, got %d", got)
	}
	// 100% discount → 0
	if got := applyDiscount(1000, "percent", 100); got != 0 {
		t.Errorf("100%% should zero out, got %d", got)
	}
	// Over 100% → 0
	if got := applyDiscount(1000, "percent", 120); got != 0 {
		t.Errorf("over 100%% should zero out, got %d", got)
	}
	// 0% → unchanged
	if got := applyDiscount(1000, "percent", 0); got != 1000 {
		t.Errorf("0%% should be no-op, got %d", got)
	}
	// Amount > original → 0 (never negative)
	if got := applyDiscount(500, "amount", 1000); got != 0 {
		t.Errorf("over-discount amount should clamp to 0, got %d", got)
	}
	// Negative fixed price → 0
	if got := applyDiscount(1000, "fixed_unit_price", -10); got != 0 {
		t.Errorf("negative fixed price should clamp to 0, got %d", got)
	}
	// Unknown type → unchanged
	if got := applyDiscount(1000, "mystery", 500); got != 1000 {
		t.Errorf("unknown type should be no-op, got %d", got)
	}
}

// ResolveForSku short-circuits an empty sku and returns nil when nothing
// matches (the two guard branches shared with the menu badge).
func TestResolveForSku_EmptyAndNoMatch(t *testing.T) {
	db := newPromoTestDB(t)
	eng := NewPromotionEngine(db)
	now := time.Now()

	if m, err := eng.ResolveForSku("", now); err != nil || m != nil {
		t.Errorf("empty sku: want (nil,nil), got (%v,%v)", m, err)
	}

	if _, err := db.Exec(`INSERT INTO pos_products (id,name) VALUES ('p1','X')`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`INSERT INTO pos_product_skus (id,product_id,name,sku,selling_price) VALUES ('sk1','p1','','',0)`); err != nil {
		t.Fatal(err)
	}
	if m, err := eng.ResolveForSku("sk1", now); err != nil || m != nil {
		t.Errorf("no promo: want (nil,nil), got (%v,%v)", m, err)
	}
}

// ResolveForSku carries ends_at through to the winner so the menu badge can
// render the Happy Hour countdown.
func TestResolveForSku_CarriesEndsAt(t *testing.T) {
	db := newPromoTestDB(t)
	eng := NewPromotionEngine(db)
	now := time.Now()
	ends := now.Add(2 * time.Hour).Format(time.RFC3339)

	if _, err := db.Exec(`INSERT INTO pos_products (id,name) VALUES ('p1','X')`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`INSERT INTO pos_product_skus (id,product_id,name,sku,selling_price) VALUES ('sk1','p1','','',0)`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`INSERT INTO menu_promotions (id,name,discount_type,discount_value,is_active,exclusive_with_coupons,priority,applies_to,ends_at) VALUES ('pr1','HH','percent',20,1,0,0,'all_items',?)`, ends); err != nil {
		t.Fatal(err)
	}

	m, err := eng.ResolveForSku("sk1", now)
	if err != nil {
		t.Fatal(err)
	}
	if m == nil || m.EndsAt != ends {
		t.Errorf("EndsAt: want %q, got %+v", ends, m)
	}
}
