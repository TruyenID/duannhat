package service

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

// PullPromotions must ingest Phase B fields:
//   - applies_to enum, stacking_mode enum, description
//   - product_ids[] (canonical Phase B; preferred over legacy product_sku_ids)
//   - branch_id / brand_id / organization_id / created_at
//
// And drop new fields onto the menu_promotions table so the engine's
// applies_to scoping + tie-breaker can run.
func TestPullPromotions_IngestsPhaseBFields(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":[
			{
				"id":"promo-1","name":"All Items 20%","description":"Phase B",
				"discount_type":"percent","discount_value":2000,
				"starts_at":"2026-01-01T00:00:00Z","ends_at":"2027-01-01T00:00:00Z",
				"is_active":true,
				"applies_to":"all_items",
				"stacking_mode":"exclusive_with_coupons",
				"exclusive_with_coupons":true,
				"branch_id":"br-1","brand_id":"brand-A","organization_id":"org-A",
				"created_at":"2026-01-01T00:00:00Z",
				"product_ids":[],
				"product_sku_ids":[],
				"priority":100,
				"schedules":[{"id":"s1","day_of_week":null,"daily_time_from":"","daily_time_to":""}]
			},
			{
				"id":"promo-2","name":"Pho only","description":null,
				"discount_type":"percent","discount_value":1500,
				"starts_at":"","ends_at":"",
				"is_active":true,
				"applies_to":"products",
				"stacking_mode":"stackable_with_coupons",
				"exclusive_with_coupons":false,
				"product_ids":["p-pho","p-noodles"],
				"product_sku_ids":[],
				"priority":100,
				"schedules":[]
			}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullPromotions(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	// promo-1: applies_to=all_items, stacking_mode, branch_id stored.
	var appliesTo, stackingMode, branchID, brandID, orgID, createdAt, desc string
	if err := db.QueryRow(`
		SELECT applies_to, stacking_mode, branch_id, brand_id, organization_id,
		       promo_created_at, description
		FROM menu_promotions WHERE id = 'promo-1'`).
		Scan(&appliesTo, &stackingMode, &branchID, &brandID, &orgID, &createdAt, &desc); err != nil {
		t.Fatalf("promo-1 readback: %v", err)
	}
	if appliesTo != "all_items" {
		t.Errorf("applies_to: want all_items, got %s", appliesTo)
	}
	if stackingMode != "exclusive_with_coupons" {
		t.Errorf("stacking_mode: want exclusive_with_coupons, got %s", stackingMode)
	}
	if branchID != "br-1" || brandID != "brand-A" || orgID != "org-A" {
		t.Errorf("scope IDs: branch=%q brand=%q org=%q", branchID, brandID, orgID)
	}
	if createdAt != "2026-01-01T00:00:00Z" {
		t.Errorf("created_at: %s", createdAt)
	}
	if desc != "Phase B" {
		t.Errorf("description: %s", desc)
	}

	// promo-1: empty product_ids[] → no pivot rows.
	var pivot1 int
	_ = db.QueryRow(`SELECT COUNT(*) FROM menu_promotion_products WHERE promotion_id = 'promo-1'`).Scan(&pivot1)
	if pivot1 != 0 {
		t.Errorf("promo-1 should have no pivot rows: %d", pivot1)
	}

	// promo-2: product_ids[] ingested into the pivot.
	var pivot2 int
	_ = db.QueryRow(`SELECT COUNT(*) FROM menu_promotion_products WHERE promotion_id = 'promo-2'`).Scan(&pivot2)
	if pivot2 != 2 {
		t.Errorf("promo-2 pivot: want 2 product rows, got %d", pivot2)
	}

	// promo-2 description NULL → stored as NULL → reads back empty string
	// from the COALESCE chain.
	var p2Desc, p2AppliesTo string
	_ = db.QueryRow(`SELECT COALESCE(description, ''), applies_to FROM menu_promotions WHERE id = 'promo-2'`).
		Scan(&p2Desc, &p2AppliesTo)
	if p2Desc != "" {
		t.Errorf("promo-2 description should be empty: %q", p2Desc)
	}
	if p2AppliesTo != "products" {
		t.Errorf("promo-2 applies_to: want products, got %s", p2AppliesTo)
	}
}

// Phase C — promotion translations must land in
// menu_promotion_translations and round-trip per locale.
func TestPullPromotions_IngestsTranslations(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":[{
			"id":"promo-i18n","name":"HH EN",
			"discount_type":"percent","discount_value":2000,
			"is_active":true,"applies_to":"all_items",
			"stacking_mode":"stackable_with_coupons",
			"product_ids":[],"schedules":[],
			"translations":[
				{"locale":"ja","name":"ハッピーアワー","description":"20%引き"},
				{"locale":"vi","name":"Giờ vàng","description":"Giảm 20%"}
			]
		}]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullPromotions(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var jaName, viName string
	_ = db.QueryRow(`
		SELECT name FROM menu_promotion_translations
		WHERE menu_promotion_id = 'promo-i18n' AND locale = 'ja'`).Scan(&jaName)
	_ = db.QueryRow(`
		SELECT name FROM menu_promotion_translations
		WHERE menu_promotion_id = 'promo-i18n' AND locale = 'vi'`).Scan(&viName)
	if jaName != "ハッピーアワー" {
		t.Errorf("ja name: %q", jaName)
	}
	if viName != "Giờ vàng" {
		t.Errorf("vi name: %q", viName)
	}
}

// Legacy fallback: when Cloud emits the pre-028 product_sku_ids[] field
// instead of product_ids[], workstation must resolve each SKU to its
// parent product via pos_product_skus and write the pivot rows there.
func TestPullPromotions_LegacySkuIdsFallback(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":[{
			"id":"promo-legacy","name":"Legacy",
			"discount_type":"percent","discount_value":1000,
			"is_active":true,"applies_to":"products",
			"stacking_mode":"stackable_with_coupons",
			"product_ids":[],
			"product_sku_ids":["sku-A","sku-B","sku-missing"],
			"schedules":[]
		}]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	// Seed two SKUs → their parent products. The third "sku-missing"
	// has no parent row, so it should silently drop.
	if _, err := db.Exec(`INSERT INTO pos_products (id, name) VALUES ('prod-A', 'A'), ('prod-B', 'B')`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price)
		VALUES ('sku-A','prod-A','','sk1',0),('sku-B','prod-B','','sk2',0)`); err != nil {
		t.Fatal(err)
	}

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullPromotions(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	rows, err := db.Query(`SELECT product_id FROM menu_promotion_products WHERE promotion_id = 'promo-legacy' ORDER BY product_id`)
	if err != nil {
		t.Fatal(err)
	}
	defer rows.Close()
	var got []string
	for rows.Next() {
		var pid string
		_ = rows.Scan(&pid)
		got = append(got, pid)
	}
	if len(got) != 2 || got[0] != "prod-A" || got[1] != "prod-B" {
		t.Errorf("legacy SKU → product resolution failed: got %v, want [prod-A prod-B]", got)
	}
}
