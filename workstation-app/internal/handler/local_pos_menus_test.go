package handler

import (
	"database/sql"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Menu-eager-load happy path: seed 1 menu + 1 section + 1 product + 1 SKU
// + 1 active percent promotion, fetch the detail, assert the nested shape
// and the active_promotion overlay both materialize.
func TestLocalPosMenuDetail_ShapesNestedProducts(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	// Seed the catalog: menu → section → menu_product → product → sku.
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m1','Lunch','published',0)`)
	mustExec(t, db, `INSERT INTO pos_menu_sections (id, menu_id, name, sort_order) VALUES ('sec1','m1','Mains',0)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','Bowl','PHO-1',1000)`)
	mustExec(t, db, `
		INSERT INTO pos_menu_products (id, menu_id, product_id, menu_section_id, display_order)
		VALUES ('mp1','m1','p1','sec1',1)`)

	// Active 20% promotion on sk1.
	mustExec(t, db, `
		INSERT INTO menu_promotions (id, name, discount_type, discount_value,
		    is_active, exclusive_with_coupons, priority)
		VALUES ('hh1','HH 20%','percent',20,1,1,100)`)
	// Phase B (migration 028): pivot is product-keyed; SKU resolution
	// happens via pos_product_skus inside the active-promotion query.
	mustExec(t, db, `INSERT INTO menu_promotion_products (promotion_id, product_id) VALUES ('hh1','p1')`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()

	// Sanity: the nested shape pos-web expects.
	for _, frag := range []string{
		`"id":"m1"`,
		`"name":"Lunch"`,
		`"menu_sections"`,
		`"menu_products"`,
		`"product"`,
		`"skus"`,
		`"selling_price":1000`,
		`"active_promotion"`,
		`"discounted_price":800`, // 1000 - 20%
		`"stacking_mode":"exclusive_with_coupons"`,
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("response missing %q\nbody=%s", frag, body)
		}
	}
}

// The menu-detail endpoint resolves each product's display name from the
// per-locale columns (name_ja/name_en/name_vi) by the request's Accept-Language,
// falling back to the base name when a locale is missing.
func TestLocalPosMenuDetail_LocalizedProductName(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m1','Lunch','published',0)`)
	mustExec(t, db, `INSERT INTO pos_menu_sections (id, menu_id, name, sort_order) VALUES ('sec1','m1','Mains',0)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja, name_en, name_vi) VALUES ('p1','Beef Pho','牛肉フォー','Beef Pho','Phở Bò')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','Bowl','PHO-1',1000)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, menu_section_id, display_order) VALUES ('mp1','m1','p1','sec1',1)`)
	// A product with NO Japanese translation must fall back to its base name.
	mustExec(t, db, `INSERT INTO pos_products (id, name, name_en, name_vi) VALUES ('p2','100% sugar','100% sugar','100% duong')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk2','p2','x','X-1',0)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, menu_section_id, display_order) VALUES ('mp2','m1','p2','sec1',2)`)

	cases := []struct {
		lang, want, absent string
	}{
		{"ja", "牛肉フォー", "Beef Pho"},
		{"en", "Beef Pho", "牛肉フォー"},
		{"vi", "Phở Bò", "牛肉フォー"},
	}
	for _, c := range cases {
		t.Run(c.lang, func(t *testing.T) {
			req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
			req.SetPathValue("menu", "m1")
			req.Header.Set("Accept-Language", c.lang)
			w := httptest.NewRecorder()
			srv.handleLocalPosMenuDetailLocal(w, req)
			if w.Code != http.StatusOK {
				t.Fatalf("[%s] want 200, got %d: %s", c.lang, w.Code, w.Body.String())
			}
			body := w.Body.String()
			if !strings.Contains(body, `"name":"`+c.want+`"`) {
				t.Errorf("[%s] want product name %q:\n%s", c.lang, c.want, body)
			}
			if strings.Contains(body, `"name":"`+c.absent+`"`) {
				t.Errorf("[%s] must NOT show other-locale name %q:\n%s", c.lang, c.absent, body)
			}
			// Missing-translation fallback: p2 has no name_ja → base "100% sugar"
			// on JA (and its own translation on en/vi).
			wantP2 := map[string]string{"ja": "100% sugar", "en": "100% sugar", "vi": "100% duong"}[c.lang]
			if !strings.Contains(body, `"name":"`+wantP2+`"`) {
				t.Errorf("[%s] want fallback/translated p2 name %q:\n%s", c.lang, wantP2, body)
			}
		})
	}
}

// The product-options dialog (variations / topping groups + items / options)
// must follow the operator's language just like the product name: SKU variant
// "Hot"→"ホット", topping group "Sauce"→"ソース", topping item (a product)
// "Fish sauce"→"ヌクマム", option "size"→"サイズ", option value "並". Missing
// translations fall back to the base value.
func TestLocalPosMenuDetail_LocalizesSkuToppingOption(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m1','Lunch','published',0)`)
	mustExec(t, db, `INSERT INTO pos_menu_sections (id, menu_id, name, sort_order) VALUES ('sec1','m1','Mains',0)`)
	// Main product + its variant SKU, both localized. sk1 wires an option value.
	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja) VALUES ('p1','Vietnamese Coffee','ベトナムコーヒー')`)
	mustExec(t, db, `INSERT INTO pos_product_options (id, product_id, key, name, name_ja, position, is_active) VALUES ('opt1','p1','size','','サイズ',0,1)`)
	mustExec(t, db, `INSERT INTO pos_product_option_values (id, option_id, value, label, label_ja, position, is_active) VALUES ('ov1','opt1','regular','','並',0,1)`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, name_ja, sku, selling_price, is_active, option_value1_id) VALUES ('sk1','p1','Hot','ホット','C-1',1000,1,'ov1')`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, menu_section_id, display_order) VALUES ('mp1','m1','p1','sec1',1)`)
	// A topping group (Sauce) attached to p1, with one item that is a product.
	mustExec(t, db, `INSERT INTO pos_topping_groups (id, name, name_ja, selection_type, modifier_type, price_strategy, min_select, max_qty_per_item, sort_order, is_active) VALUES ('tg1','Sauce','ソース','single','add','flat',0,1,0,1)`)
	mustExec(t, db, `INSERT INTO pos_product_topping_groups (product_id, topping_group_id, sort_order) VALUES ('p1','tg1',0)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja) VALUES ('pt1','Fish sauce','ヌクマム')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, name_ja, sku, selling_price, is_active) VALUES ('pt1sk','pt1','Fish sauce','ヌクマム','FS-1',0,1)`)
	mustExec(t, db, `INSERT INTO pos_topping_group_items (id, topping_group_id, product_id, sort_order, is_default) VALUES ('ti1','tg1','pt1',0,0)`)
	mustExec(t, db, `INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, product_sku_id, extra_price) VALUES ('tis1','ti1','pt1sk',50)`)

	cases := []struct {
		lang    string
		present []string
		absent  []string
	}{
		{"ja", []string{"ベトナムコーヒー", "ホット", "ソース", "ヌクマム", "サイズ", "並"}, []string{`"Fish sauce"`, `"Sauce"`}},
		{"en", []string{`"Hot"`, `"Sauce"`, `"Fish sauce"`}, []string{"ホット", "ソース", "ヌクマム"}},
	}
	for _, c := range cases {
		t.Run(c.lang, func(t *testing.T) {
			req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
			req.SetPathValue("menu", "m1")
			req.Header.Set("Accept-Language", c.lang)
			w := httptest.NewRecorder()
			srv.handleLocalPosMenuDetailLocal(w, req)
			if w.Code != http.StatusOK {
				t.Fatalf("[%s] want 200, got %d: %s", c.lang, w.Code, w.Body.String())
			}
			body := w.Body.String()
			for _, want := range c.present {
				if !strings.Contains(body, want) {
					t.Errorf("[%s] missing %q in body:\n%s", c.lang, want, body)
				}
			}
			for _, no := range c.absent {
				if strings.Contains(body, no) {
					t.Errorf("[%s] must NOT contain other-locale %q", c.lang, no)
				}
			}
		})
	}
}

// fetchMenuDetailBody runs the menu-detail handler and returns the JSON body.
func fetchMenuDetailBody(t *testing.T, srv *Server, menuID string) string {
	t.Helper()
	req := httptest.NewRequest("GET", "/api/v1/pos/menus/"+menuID, nil)
	req.SetPathValue("menu", menuID)
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	return w.Body.String()
}

func seedCoffeeMenu(t *testing.T, db execer) {
	t.Helper()
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m1','Lunch','published',0)`)
	mustExec(t, db, `INSERT INTO pos_menu_sections (id, menu_id, name, sort_order) VALUES ('sec1','m1','Drinks',0)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Vietnamese Coffee')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','Hot','C-1',1000)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, menu_section_id, display_order) VALUES ('mp1','m1','p1','sec1',1)`)
}

// The reported bug: the tile showed Happy Hour −20% but the cart charged −15%.
// Root cause — the badge ignored menu_promotion_schedules while the cart honors
// them. This asserts the badge now reflects the CART's promotion: a 20% promo
// scheduled OUT of the current window is not advertised; the always-on 15%
// all_items promo (what the cart applies) is.
func TestActivePromotion_BadgeRespectsSchedule_MatchesCart(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedCoffeeMenu(t, db)

	// 20% product-scoped promo, scheduled ONLY for tomorrow's weekday → the
	// cart's scheduleMatches excludes it at the current time.
	tomorrow := (int(time.Now().Weekday()) + 1) % 7
	mustExec(t, db, `INSERT INTO menu_promotions (id, name, discount_type, discount_value, is_active, exclusive_with_coupons, priority) VALUES ('hh20','Coffee 20%','percent',20,1,0,100)`)
	mustExec(t, db, `INSERT INTO menu_promotion_products (promotion_id, product_id) VALUES ('hh20','p1')`)
	mustExec(t, db, `INSERT INTO menu_promotion_schedules (id, promotion_id, day_of_week) VALUES ('s20','hh20',?)`, tomorrow)

	// 15% all-items promo, no schedule → always-on → this is what the cart charges.
	mustExec(t, db, `INSERT INTO menu_promotions (id, name, discount_type, discount_value, is_active, exclusive_with_coupons, priority, applies_to) VALUES ('hh15','All 15%','percent',15,1,0,50,'all_items')`)

	body := fetchMenuDetailBody(t, srv, "m1")
	if !strings.Contains(body, `"discount_percent":15`) || !strings.Contains(body, `"discounted_price":850`) {
		t.Errorf("badge should show the cart-applied 15%% (850), got body=%s", body)
	}
	if strings.Contains(body, `"discount_percent":20`) {
		t.Errorf("badge must NOT advertise the out-of-schedule 20%%\nbody=%s", body)
	}

	// Cross-check: the cart applies exactly 15% / 850 for the same sku + now.
	final, match, err := service.NewPromotionEngine(db).ApplyToItem("sk1", 1000, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	if match == nil || match.DiscountValue != 15 || final != 850 {
		t.Errorf("cart parity: want 15%%/850, got match=%v final=%d", match, final)
	}
}

// When several promos are in-window, the badge must pick the SAME winner as the
// cart: highest discount_percent (not the legacy `priority`). A 20% promo with
// LOW priority must beat a 15% promo with HIGH priority on both sides.
func TestActivePromotion_BadgeHighestDiscountWins_MatchesCart(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedCoffeeMenu(t, db)

	// 20% product-scoped, LOW priority — would LOSE the old priority sort.
	mustExec(t, db, `INSERT INTO menu_promotions (id, name, discount_type, discount_value, is_active, exclusive_with_coupons, priority) VALUES ('hh20','Coffee 20%','percent',20,1,0,10)`)
	mustExec(t, db, `INSERT INTO menu_promotion_products (promotion_id, product_id) VALUES ('hh20','p1')`)
	// 15% all-items, HIGH priority — would WIN the old priority sort.
	mustExec(t, db, `INSERT INTO menu_promotions (id, name, discount_type, discount_value, is_active, exclusive_with_coupons, priority, applies_to) VALUES ('hh15','All 15%','percent',15,1,0,999,'all_items')`)

	body := fetchMenuDetailBody(t, srv, "m1")
	if !strings.Contains(body, `"discount_percent":20`) || !strings.Contains(body, `"discounted_price":800`) {
		t.Errorf("badge should show the highest-discount 20%% (800), got body=%s", body)
	}
	if strings.Contains(body, `"discount_percent":15`) {
		t.Errorf("badge must NOT pick the lower 15%% by priority\nbody=%s", body)
	}

	final, match, err := service.NewPromotionEngine(db).ApplyToItem("sk1", 1000, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	if match == nil || match.DiscountValue != 20 || final != 800 {
		t.Errorf("cart parity: want 20%%/800, got match=%v final=%d", match, final)
	}
}

// Direct guards + non-percent branch for the badge helper.
func TestActivePromotionForProduct_GuardsAndAmountType(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	now := time.Now()

	if srv.activePromotionForProduct(nil, now) != nil {
		t.Errorf("empty skus → nil badge")
	}
	// SKUs without an "id" key → repSkuID guard returns nil.
	if srv.activePromotionForProduct([]map[string]any{{"selling_price": 100}}, now) != nil {
		t.Errorf("skus without id → nil badge")
	}

	// amount-type promo → discount_percent stays 0, discounted_price is reduced.
	mustExec(t, db, `INSERT INTO pos_products (id,name) VALUES ('p1','X')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id,product_id,name,sku,selling_price) VALUES ('sk1','p1','','',1000)`)
	mustExec(t, db, `INSERT INTO menu_promotions (id,name,discount_type,discount_value,is_active,exclusive_with_coupons,priority,applies_to) VALUES ('amt','Amt','amount',200,1,0,0,'all_items')`)

	badge := srv.activePromotionForProduct([]map[string]any{{"id": "sk1", "selling_price": 1000}}, now)
	m, ok := badge.(map[string]any)
	if !ok {
		t.Fatalf("want a badge map, got %T", badge)
	}
	if m["discount_percent"] != 0 {
		t.Errorf("amount type → discount_percent 0, got %v", m["discount_percent"])
	}
	if m["discounted_price"] != 800 {
		t.Errorf("amount 200 off 1000 → 800, got %v", m["discounted_price"])
	}
}

// No active promotion → the badge is null (the cart charges full price).
func TestActivePromotion_NoPromo_NullBadge(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedCoffeeMenu(t, db)

	body := fetchMenuDetailBody(t, srv, "m1")
	if !strings.Contains(body, `"active_promotion":null`) {
		t.Errorf("no promo → active_promotion should be null\nbody=%s", body)
	}
}

func TestLocalPosMenuDetail_404OnUnknown(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/does-not-exist", nil)
	req.SetPathValue("menu", "does-not-exist")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)

	if w.Code != http.StatusNotFound {
		t.Errorf("want 404, got %d", w.Code)
	}
}

func TestLocalPosListMenus_StatusFilter(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m1','Lunch','published',0)`)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m2','Old','archived',0)`)

	// Default (no filter): both.
	req := httptest.NewRequest("GET", "/api/v1/pos/menus", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosListMenus(w, req)
	if !strings.Contains(w.Body.String(), `"id":"m1"`) ||
		!strings.Contains(w.Body.String(), `"id":"m2"`) {
		t.Errorf("expected both menus when no filter: %s", w.Body.String())
	}

	// ?status=published — only m1.
	req = httptest.NewRequest("GET", "/api/v1/pos/menus?status=published", nil)
	w = httptest.NewRecorder()
	srv.handleLocalPosListMenus(w, req)
	if !strings.Contains(w.Body.String(), `"id":"m1"`) ||
		strings.Contains(w.Body.String(), `"id":"m2"`) {
		t.Errorf("status filter ignored: %s", w.Body.String())
	}
}

// #481 — the LAN menu list gates on ?service_type so POS only surfaces menus
// valid for the current order's service type. Both + legacy-NULL always show.
func TestLocalPosListMenus_ServiceTypeGate(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order, service_type) VALUES ('dine','Dine','published',0,'DineIn')`)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order, service_type) VALUES ('take','Take','published',1,'Takeaway')`)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order, service_type) VALUES ('both','Both','published',2,'Both')`)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order, service_type) VALUES ('leg','Legacy','published',3,NULL)`)

	// ?service_type=DineIn → DineIn + Both + legacy-NULL, but NOT Takeaway.
	req := httptest.NewRequest("GET", "/api/v1/pos/menus?service_type=DineIn", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosListMenus(w, req)
	body := w.Body.String()
	if !strings.Contains(body, `"id":"dine"`) ||
		!strings.Contains(body, `"id":"both"`) ||
		!strings.Contains(body, `"id":"leg"`) {
		t.Errorf("DineIn gate dropped a valid menu: %s", body)
	}
	if strings.Contains(body, `"id":"take"`) {
		t.Errorf("DineIn gate leaked a Takeaway menu: %s", body)
	}

	// ?service_type=Takeaway → Takeaway shows, DineIn hidden.
	req = httptest.NewRequest("GET", "/api/v1/pos/menus?service_type=Takeaway", nil)
	w = httptest.NewRecorder()
	srv.handleLocalPosListMenus(w, req)
	body = w.Body.String()
	if !strings.Contains(body, `"id":"take"`) || strings.Contains(body, `"id":"dine"`) {
		t.Errorf("Takeaway gate wrong: %s", body)
	}
}

// dispatchMenuTwoSeg routes both /menus/by-day/{dow} and
// /menus/{menu}/products under a single Go-mux pattern so neither one is
// dropped to the cloud proxy. Lock both legs + the 404 fallback.
func TestDispatchMenuTwoSeg_RoutesProductsToMenuProducts(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = nil

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES ('s1','p1','Bowl',1000)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp1','m1','p1',1)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1/products", nil)
	req.SetPathValue("seg1", "m1")
	req.SetPathValue("seg2", "products")
	w := httptest.NewRecorder()
	srv.dispatchMenuTwoSeg(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), `"id":"mp1"`) {
		t.Errorf("expected mp1 row: %s", w.Body.String())
	}
}

func TestDispatchMenuTwoSeg_RoutesByDayToMenuByDay(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO menu_schedules (id, menu_id, day_of_week, is_active) VALUES ('s1','m1',1,1)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/by-day/1", nil)
	req.SetPathValue("seg1", "by-day")
	req.SetPathValue("seg2", "1")
	w := httptest.NewRecorder()
	srv.dispatchMenuTwoSeg(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), `"id":"m1"`) {
		t.Errorf("expected m1 on Monday: %s", w.Body.String())
	}
}

func TestDispatchMenuTwoSeg_404OnUnknownSubresource(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1/somethingelse", nil)
	req.SetPathValue("seg1", "m1")
	req.SetPathValue("seg2", "somethingelse")
	w := httptest.NewRecorder()
	srv.dispatchMenuTwoSeg(w, req)

	if w.Code != http.StatusNotFound {
		t.Errorf("want 404, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestLocalPosMenuByDay_PicksScheduledOnly(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('mA','MonOnly','published',0)`)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('mB','Always','published',0)`)
	// mA only on Mon. mB on every weekday (one schedule row per day —
	// schema's CHECK forbids NULL day_of_week; "always-on" is materialised
	// as a row per day at sync DOWN time by Cloud).
	mustExec(t, db, `INSERT INTO menu_schedules (id, menu_id, day_of_week, is_active) VALUES ('s1','mA',1,1)`)
	for d := 0; d < 7; d++ {
		mustExec(t, db, `INSERT INTO menu_schedules (id, menu_id, day_of_week, is_active) VALUES (?, 'mB', ?, 1)`,
			"s2-"+string(rune('0'+d)), d)
	}

	// Day 1 = Monday → mA + mB.
	req := httptest.NewRequest("GET", "/api/v1/pos/menus/by-day/1", nil)
	req.SetPathValue("dow", "1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuByDay(w, req)
	body := w.Body.String()
	if !strings.Contains(body, `"id":"mA"`) || !strings.Contains(body, `"id":"mB"`) {
		t.Errorf("Monday should yield mA + mB: %s", body)
	}

	// Day 3 = Wed → only mB.
	req = httptest.NewRequest("GET", "/api/v1/pos/menus/by-day/3", nil)
	req.SetPathValue("dow", "3")
	w = httptest.NewRecorder()
	srv.handleLocalPosMenuByDay(w, req)
	body = w.Body.String()
	if strings.Contains(body, `"id":"mA"`) {
		t.Errorf("Wed should NOT yield mA: %s", body)
	}
	if !strings.Contains(body, `"id":"mB"`) {
		t.Errorf("Wed must still yield mB: %s", body)
	}
}

// When menu_schedules is empty for the requested day, by-day falls back to
// every active menu so the LAN POS picker isn't blank on shops that haven't
// configured per-day schedules in Cloud. Cloud-side configured schedules
// still win when present.
func TestLocalPosMenuByDay_FallsBackToAllActiveWhenNoSchedules(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('mP','Lunch','published',0)`)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('mA','Brunch','Active',1)`)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('mX','Archived','archived',2)`)
	// No menu_schedules rows seeded.

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/by-day/3", nil)
	req.SetPathValue("dow", "3")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuByDay(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()
	if !strings.Contains(body, `"id":"mP"`) || !strings.Contains(body, `"id":"mA"`) {
		t.Errorf("fallback must include active menus regardless of status spelling: %s", body)
	}
	if strings.Contains(body, `"id":"mX"`) {
		t.Errorf("archived menu should NOT appear in fallback: %s", body)
	}
	for _, frag := range []string{`"start_time":""`, `"end_time":""`} {
		if !strings.Contains(body, frag) {
			t.Errorf("response missing %q (pickActiveMenu contract): %s", frag, body)
		}
	}
}

// When a menu has multiple schedule rows for the same day, the
// highest-priority row's start_time / end_time must be emitted.
// Mirrors MenuService::listActiveBranchMenusForShopByDay correlated-
// subquery semantics.
func TestLocalPosMenuByDay_EmitsHighestPrioritySchedule(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m1','Lunch','published',0)`)
	// Lower priority = higher precedence (backend convention).
	mustExec(t, db, `INSERT INTO menu_schedules (id, menu_id, day_of_week, start_time, end_time, priority, is_active) VALUES ('s-hi','m1',1,'11:00:00','14:00:00',1,1)`)
	mustExec(t, db, `INSERT INTO menu_schedules (id, menu_id, day_of_week, start_time, end_time, priority, is_active) VALUES ('s-lo','m1',1,'09:00:00','22:00:00',9,1)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/by-day/1", nil)
	req.SetPathValue("dow", "1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuByDay(w, req)

	body := w.Body.String()
	for _, frag := range []string{
		`"start_time":"11:00:00"`,
		`"end_time":"14:00:00"`,
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("highest-priority schedule should win: missing %q\nbody=%s", frag, body)
		}
	}
}

// Response must be a Laravel-paginator envelope so pos-web's
// PaginatedResponse consumer doesn't choke. Includes `meta.from/to`
// (null when empty), `meta.current_page`, `meta.last_page`,
// `meta.total`, `meta.per_page`, + `links` block.
func TestLocalPosMenuByDay_EmitsPaginatedEnvelope(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m1','L','published',0)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp1','m1','p1',1)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/by-day/1?per_page=20", nil)
	req.SetPathValue("dow", "1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuByDay(w, req)

	body := w.Body.String()
	for _, frag := range []string{
		`"data":[`,
		`"links":{`,
		`"meta":{`,
		`"current_page":1`,
		`"last_page":1`,
		`"per_page":20`,
		`"total":1`,
		`"menu_products_count":1`,
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("paginator envelope missing %q: %s", frag, body)
		}
	}
}

// Status-filter normalization on the list endpoint: pos-web sends
// ?status=Active, Cloud's catalog feed emits any of {published, Published,
// active, Active}. Exact-match equality would silently miss the lowercase
// spellings — accept the same broad set Cloud's MenuCatalogReplicaController
// already filters on.
func TestLocalPosListMenus_StatusActiveMatchesAllSpellings(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m1','Pub','published',0)`)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m2','PubCap','Published',1)`)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m3','Act','active',2)`)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m4','ActCap','Active',3)`)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m5','Old','archived',4)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus?status=Active", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosListMenus(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()
	for _, id := range []string{"m1", "m2", "m3", "m4"} {
		if !strings.Contains(body, `"id":"`+id+`"`) {
			t.Errorf("status=Active must accept all four spellings; missing %s: %s", id, body)
		}
	}
	if strings.Contains(body, `"id":"m5"`) {
		t.Errorf("archived menu must NOT match status=Active: %s", body)
	}
}

// mustExec is a small wrapper used by the menu tests; reusable.
func mustExec(t *testing.T, db execer, q string, args ...any) {
	t.Helper()
	if _, err := db.Exec(q, args...); err != nil {
		t.Fatalf("exec %s: %v", q, err)
	}
}

type execer interface {
	Exec(q string, args ...any) (sql.Result, error)
}
