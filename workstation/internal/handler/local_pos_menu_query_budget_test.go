package handler

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"reflect"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

func seedSixtyProductMenu(t testing.TB) *Server {
	t.Helper()
	srv, db := newServerWithAuth(t, "")
	srv.imageFetcher = service.NewImageFetcher(db)
	db.Conn().SetMaxOpenConns(2)
	db.Conn().SetMaxIdleConns(2)

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('menu-budget','Lunch','published')`)
	mustExec(t, db, `INSERT INTO pos_menu_sections (id, menu_id, name, sort_order)
		VALUES ('section-budget','menu-budget','Mains',1)`)
	mustExec(t, db, `INSERT INTO tax_types (id, code, name, rate, is_default, is_active)
		VALUES ('tax-standard','standard','Standard',10,1,1)`)
	mustExec(t, db, `INSERT INTO menu_promotions
		(id, name, discount_type, discount_value, is_active, applies_to, promo_created_at)
		VALUES ('promo-budget','Happy Hour','percent',10,1,'all_items','2026-01-01T00:00:00Z')`)

	// Three shared topping choices are attached to every product. This is
	// intentionally dense enough to exercise group/item/SKU hydration while
	// keeping the fixture understandable.
	mustExec(t, db, `INSERT INTO pos_topping_groups
		(id, name, selection_type, modifier_type, price_strategy, min_select, max_qty_per_item)
		VALUES ('tg-budget','Extras','multiple','add','flat',0,3)`)
	for i := 0; i < 3; i++ {
		productID := fmt.Sprintf("topping-product-%d", i)
		itemID := fmt.Sprintf("topping-item-%d", i)
		skuID := fmt.Sprintf("topping-sku-%d", i)
		mustExec(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES (?, ?, ?)`,
			productID, fmt.Sprintf("Topping %d", i), fmt.Sprintf("https://cdn/topping-%d.jpg", i))
		mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price)
			VALUES (?, ?, ?, ?, ?)`, skuID, productID, fmt.Sprintf("T%d", i), fmt.Sprintf("TOP-%d", i), 50+i)
		mustExec(t, db, `INSERT INTO pos_topping_group_items
			(id, topping_group_id, product_id, sort_order, is_default) VALUES (?, 'tg-budget', ?, ?, 0)`,
			itemID, productID, i)
		mustExec(t, db, `INSERT INTO pos_topping_group_item_skus
			(id, topping_group_item_id, product_sku_id, extra_price) VALUES (?, ?, ?, ?)`,
			fmt.Sprintf("topping-row-%d", i), itemID, skuID, 50+i)
	}

	for i := 0; i < 60; i++ {
		productID := fmt.Sprintf("product-%02d", i)
		menuProductID := fmt.Sprintf("menu-product-%02d", i)
		optionID := fmt.Sprintf("option-%02d", i)
		mustExec(t, db, `INSERT INTO pos_products (id, name, image_url, product_type_code)
			VALUES (?, ?, ?, 'food')`, productID, fmt.Sprintf("Product %02d", i), fmt.Sprintf("https://cdn/product-%02d.jpg", i))
		mustExec(t, db, `INSERT INTO pos_menu_products
			(id, menu_id, product_id, menu_section_id, display_order)
			VALUES (?, 'menu-budget', ?, 'section-budget', ?)`, menuProductID, productID, i)
		mustExec(t, db, `INSERT INTO pos_product_options (id, product_id, key, name, position)
			VALUES (?, ?, 'size', 'Size', 1)`, optionID, productID)
		for j := 0; j < 2; j++ {
			valueID := fmt.Sprintf("option-value-%02d-%d", i, j)
			skuID := fmt.Sprintf("sku-%02d-%d", i, j)
			pivotID := fmt.Sprintf("menu-sku-%02d-%d", i, j)
			mustExec(t, db, `INSERT INTO pos_product_option_values
				(id, option_id, value, label, position) VALUES (?, ?, ?, ?, ?)`,
				valueID, optionID, fmt.Sprintf("size-%d", j), fmt.Sprintf("Size %d", j), j)
			mustExec(t, db, `INSERT INTO pos_product_skus
				(id, product_id, name, sku, selling_price, image_url, option_value1_id)
				VALUES (?, ?, ?, ?, ?, ?, ?)`, skuID, productID, fmt.Sprintf("Variant %d", j),
				fmt.Sprintf("SKU-%02d-%d", i, j), 1000+i*10+j, fmt.Sprintf("https://cdn/sku-%02d-%d.jpg", i, j), valueID)
			mustExec(t, db, `INSERT INTO pos_menu_product_skus
				(id, menu_product_id, product_sku_id, is_active) VALUES (?, ?, ?, 1)`, pivotID, menuProductID, skuID)
		}
		mustExec(t, db, `INSERT INTO pos_product_galleries (id, product_id, url, sort_order)
			VALUES (?, ?, ?, 1)`, fmt.Sprintf("gallery-%02d", i), productID, fmt.Sprintf("https://cdn/gallery-%02d.jpg", i))
		mustExec(t, db, `INSERT INTO pos_product_topping_groups
			(product_id, topping_group_id, sort_order) VALUES (?, 'tg-budget', 1)`, productID)
	}
	return srv
}

func runSixtyProductMenu(t testing.TB, srv *Server) (*httptest.ResponseRecorder, time.Duration, int64) {
	t.Helper()
	before := srv.db.Diagnostics().QueryCount
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/menus/menu-budget/products?per_page=60", nil)
	req.SetPathValue("menu", "menu-budget")
	w := httptest.NewRecorder()
	started := time.Now()
	srv.handleLocalPosMenuProducts(w, req)
	return w, time.Since(started), srv.db.Diagnostics().QueryCount - before
}

func TestLocalPosMenuProducts_SixtyProductQueryBudgetAndLatency(t *testing.T) {
	srv := seedSixtyProductMenu(t)

	baselineBefore := srv.db.Diagnostics().QueryCount
	baselineReq := httptest.NewRequest(http.MethodGet, "/api/v1/pos/menus/menu-budget/products?per_page=1", nil)
	baselineReq.SetPathValue("menu", "menu-budget")
	baselineW := httptest.NewRecorder()
	srv.handleLocalPosMenuProducts(baselineW, baselineReq)
	if baselineW.Code != http.StatusOK {
		t.Fatalf("one-product baseline status=%d body=%s", baselineW.Code, baselineW.Body.String())
	}
	baselineQueries := srv.db.Diagnostics().QueryCount - baselineBefore
	if baselineQueries != 19 {
		t.Fatalf("1-product menu used %d queries, want fixed budget 19", baselineQueries)
	}

	for _, run := range []string{"cold", "warm"} {
		t.Run(run, func(t *testing.T) {
			w, elapsed, queries := runSixtyProductMenu(t, srv)
			if w.Code != http.StatusOK {
				t.Fatalf("status=%d body=%s", w.Code, w.Body.String())
			}
			if queries != baselineQueries {
				t.Errorf("60-product menu used %d queries, want same %d-query budget as 1 product", queries, baselineQueries)
			}
			if elapsed > 2*time.Second {
				t.Errorf("%s local menu read took %s, want <=2s", run, elapsed)
			}

			var payload struct {
				Data []map[string]any `json:"data"`
			}
			if err := json.Unmarshal(w.Body.Bytes(), &payload); err != nil {
				t.Fatalf("decode response: %v", err)
			}
			if len(payload.Data) != 60 {
				t.Fatalf("products=%d, want 60", len(payload.Data))
			}
			first := payload.Data[0]
			if got := len(first["skus"].([]any)); got != 2 {
				t.Errorf("skus=%d, want 2", got)
			}
			product := first["product"].(map[string]any)
			if got := len(product["gallery"].([]any)); got != 1 {
				t.Errorf("gallery=%d, want 1", got)
			}
			if got := len(product["topping_groups"].([]any)); got != 1 {
				t.Errorf("topping_groups=%d, want 1", got)
			}
			if product["tax_rate"] != float64(10) || first["active_promotion"] == nil {
				t.Errorf("tax/promotion contract changed: product=%#v promotion=%#v", product, first["active_promotion"])
			}
		})
	}
}

func TestHydrateMenuProductRows_MatchesLegacyRelationShape(t *testing.T) {
	srv := seedSixtyProductMenu(t)
	now := time.Date(2026, 8, 16, 12, 0, 0, 0, time.Local)
	row := menuProductRow{
		id: "menu-product-00", menuID: "menu-budget", productID: "product-00",
		sectionID: "section-budget", productName: "Product 00",
		productImage: "https://cdn/product-00.jpg", productTypeCode: "food",
		active: 1,
	}

	gotRows, err := srv.hydrateMenuProductRows([]menuProductRow{row}, now, "", false, true)
	if err != nil {
		t.Fatalf("batch hydrate: %v", err)
	}
	legacySkus, err := srv.loadProductSkus(row.id, row.productID, now, "", false)
	if err != nil {
		t.Fatalf("legacy skus: %v", err)
	}
	legacyGallery, err := srv.loadProductGallery(row.productID)
	if err != nil {
		t.Fatalf("legacy gallery: %v", err)
	}
	legacyToppings, err := srv.loadProductToppingGroups(row.id, row.productID, "", false)
	if err != nil {
		t.Fatalf("legacy toppings: %v", err)
	}
	product := map[string]any{
		"id": row.productID, "name": row.productName, "description": nil,
		"is_active": true, "image_url": row.productImage,
		"product_type_code": row.productTypeCode, "gallery": legacyGallery,
		"topping_groups": legacyToppings, "tax_rate": nil,
	}
	if rate, ok := srv.resolveProductTaxRate(row.productID); ok {
		product["tax_rate"] = rate
	}
	want := map[string]any{
		"id": row.id, "menu_id": row.menuID, "product_id": row.productID,
		"menu_section_id": row.sectionID, "is_active": true, "display_order": 0,
		"skus": legacySkus, "product": product, "section": nil,
		"active_promotion": srv.activePromotionForProduct(legacySkus, now),
		"disabled_reason":  nil, "disabled_at": nil, "disabled_by_name": nil,
	}
	if !reflect.DeepEqual(gotRows[0], want) {
		gotJSON, _ := json.Marshal(gotRows[0])
		wantJSON, _ := json.Marshal(want)
		t.Fatalf("batch shape changed payload\ngot:  %s\nwant: %s", gotJSON, wantJSON)
	}
}

func BenchmarkLocalPosMenuProducts_SixtyProducts(b *testing.B) {
	srv := seedSixtyProductMenu(b)
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		w, _, _ := runSixtyProductMenu(b, srv)
		if w.Code != http.StatusOK {
			b.Fatalf("status=%d", w.Code)
		}
	}
}
