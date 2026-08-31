package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// pos-web's add-to-cart payload reads `sku.product_sku_id` (NOT
// `sku.id`) and forwards it as the canonical ProductSku UUID workstation
// uses to resolve menu_items + pos_product_skus. Forgetting this field
// → every "Thêm vào đơn" returns 500 with "menu item not found". Lock
// the contract so a future refactor can't quietly regress it.
func TestLocalPosMenuDetail_SkuEmitsProductSkuIdForCart(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sku-A','p1','Regular','PHO-R',2300)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp1','m1','p1',1)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()

	// Both fields must be present and equal — workstation has no
	// pivot table so they share the same UUID.
	for _, frag := range []string{
		`"id":"sku-A"`,
		`"product_sku_id":"sku-A"`,
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("response missing %q\nbody=%s", frag, body)
		}
	}
}

// SKU emission must mirror MenuProductSkuResource:
//   - default_price emitted ONLY when is_price_overridden=true
//   - option_value1/2/3 nested with their parent option (ProductSkuResource)
func TestLocalPosMenuDetail_EmitsPriceOverrideAndOptionValues(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, db, `INSERT INTO pos_product_options (id, product_id, key, name, position) VALUES ('opt1','p1','size','Size',0)`)
	mustExec(t, db, `INSERT INTO pos_product_option_values (id, option_id, value, label, position) VALUES ('ov1','opt1','L','Large',0)`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, default_price, is_price_overridden, option_value1_id) VALUES ('sk1','p1','LargeBowl','PHO-L',900,1000,1,'ov1')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, default_price, is_price_overridden) VALUES ('sk2','p1','Small','PHO-S',500,500,0)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp1','m1','p1',1)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()

	// Override SKU: selling_price reflects override, default_price shows canonical,
	// is_price_overridden=true, option_value1 nested with parent option.
	for _, frag := range []string{
		`"is_price_overridden":true`,
		`"selling_price":900`,
		`"default_price":1000`,
		`"option_value1":{`,
		`"value":"L"`,
		`"label":"Large"`,
		`"option":{`,
		`"name":"Size"`,
		`"key":"size"`,
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("override sku missing %q\nbody=%s", frag, body)
		}
	}

	// Non-override SKU: default_price MUST be null (matches
	// MenuProductSkuResource.php:25-28 `when($this->is_price_overridden)`).
	if !strings.Contains(body, `"is_price_overridden":false`) {
		t.Errorf("expected non-override sku to emit is_price_overridden:false")
	}
}

// Topping group resolution: tier-3 base extra_price falls through when no
// overrides exist. Effective select bounds reflect the pivot override.
func TestLocalPosMenuDetail_EmitsToppingGroupsBaseTier(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','B','PHO-1',1000)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp1','m1','p1',1)`)

	// Topping group "Toppings" with one item ("Cheese") + one base sku row at 100.
	mustExec(t, db, `INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, min_select, max_select, max_qty_per_item, sort_order, is_active) VALUES ('tg1','Toppings','multiple','add','flat',0,5,2,0,1)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('topping1','Cheese')`)
	mustExec(t, db, `INSERT INTO pos_topping_group_items (id, topping_group_id, product_id, sort_order) VALUES ('it1','tg1','topping1',0)`)
	mustExec(t, db, `INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, extra_price) VALUES ('isk1','it1',100)`)
	// Pivot binds the group to the parent product with override min=1, max=3.
	mustExec(t, db, `INSERT INTO pos_product_topping_groups (product_id, topping_group_id, sort_order, min_select_override, max_select_override) VALUES ('p1','tg1',0,1,3)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()

	for _, frag := range []string{
		`"topping_groups":[`,
		`"name":"Toppings"`,
		`"selection_type":"multiple"`,
		`"price_strategy":"flat"`,
		`"effective_min_select":1`, // pivot override beats group.min_select=0
		`"effective_max_select":3`, // pivot override beats group.max_select=5
		`"max_qty_per_item":2`,
		`"items":[`,
		`"name":"Cheese"`,
		`"extra_price":"100"`, // tier-3 base flows through
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("topping shape missing %q\nbody=%s", frag, body)
		}
	}
}

// Tier-1 (menu_product) override beats tier-2 (product) which beats tier-3
// (base). Matches MenuToppingGroupItemResource resolution exactly.
func TestLocalPosMenuDetail_ToppingTier1BeatsTier2BeatsTier3(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','B','PHO-1',1000)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp-shop','m1','p1',1)`)

	mustExec(t, db, `INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, min_select, max_qty_per_item, is_active) VALUES ('tg1','T','multiple','add','flat',0,1,1)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('top1','TC')`)
	mustExec(t, db, `INSERT INTO pos_topping_group_items (id, topping_group_id, product_id, sort_order) VALUES ('it1','tg1','top1',0)`)
	mustExec(t, db, `INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, product_sku_id, extra_price) VALUES ('isk1','it1','sk1',100)`)
	mustExec(t, db, `INSERT INTO pos_product_topping_groups (product_id, topping_group_id, sort_order) VALUES ('p1','tg1',0)`)

	// Tier-2 says 200, tier-1 says 50 → expect 50.
	mustExec(t, db, `INSERT INTO pos_product_topping_item_overrides (id, product_id, topping_group_item_id, product_sku_id, override_price, is_hidden) VALUES ('ov2','p1','it1','sk1',200,0)`)
	mustExec(t, db, `INSERT INTO pos_menu_product_topping_overrides (id, menu_product_id, topping_group_id, topping_group_item_id, product_sku_id, is_hidden, override_price) VALUES ('ov1','mp-shop','tg1','it1','sk1',0,50)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)
	body := w.Body.String()
	if !strings.Contains(body, `"extra_price":"50"`) {
		t.Errorf("tier-1 override must win: %s", body)
	}
	if strings.Contains(body, `"extra_price":"200"`) || strings.Contains(body, `"extra_price":"100"`) {
		t.Errorf("tier-1 should suppress tier-2 + tier-3: %s", body)
	}
}

// Tier-2 override applies when no tier-1 row exists.
func TestLocalPosMenuDetail_ToppingTier2WhenNoTier1(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','B','PHO-1',1000)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp-shop','m1','p1',1)`)

	mustExec(t, db, `INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, min_select, max_qty_per_item, is_active) VALUES ('tg1','T','multiple','add','flat',0,1,1)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('top1','TC')`)
	mustExec(t, db, `INSERT INTO pos_topping_group_items (id, topping_group_id, product_id, sort_order) VALUES ('it1','tg1','top1',0)`)
	mustExec(t, db, `INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, product_sku_id, extra_price) VALUES ('isk1','it1','sk1',100)`)
	mustExec(t, db, `INSERT INTO pos_product_topping_groups (product_id, topping_group_id, sort_order) VALUES ('p1','tg1',0)`)
	mustExec(t, db, `INSERT INTO pos_product_topping_item_overrides (id, product_id, topping_group_item_id, product_sku_id, override_price, is_hidden) VALUES ('ov2','p1','it1','sk1',200,0)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)
	body := w.Body.String()
	if !strings.Contains(body, `"extra_price":"200"`) {
		t.Errorf("tier-2 must apply when no tier-1: %s", body)
	}
}

// Topping items reference a SEPARATE product (the topping itself) that
// isn't a menu_product. After the backend feed extension, that product
// IS synced into pos_products + pos_product_galleries. This test
// verifies the LAN handler reads name + image_url from the topping
// product's pos_products row — so the topping picker renders correctly
// instead of an empty card.
func TestLocalPosMenuDetail_ToppingItemReadsToppingProductName(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Burger')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','B','BU-1',1000)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp1','m1','p1',1)`)

	// Topping product — not in any menu, but synced through the
	// expanded productIds path on the feed.
	mustExec(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES ('cheese','Cheese','https://cdn/cheese.jpg')`)
	mustExec(t, db, `INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, min_select, max_qty_per_item, is_active) VALUES ('tg1','Extras','multiple','add','flat',0,1,1)`)
	mustExec(t, db, `INSERT INTO pos_topping_group_items (id, topping_group_id, product_id, sort_order) VALUES ('it1','tg1','cheese',0)`)
	mustExec(t, db, `INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, extra_price) VALUES ('isk1','it1',100)`)
	mustExec(t, db, `INSERT INTO pos_product_topping_groups (product_id, topping_group_id, sort_order) VALUES ('p1','tg1',0)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)
	body := w.Body.String()
	for _, frag := range []string{
		`"name":"Cheese"`,
		`"image_url":"https://cdn/cheese.jpg"`,
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("topping product field missing %q\nbody=%s", frag, body)
		}
	}
}

// is_hidden at any active tier removes the SKU. If all SKUs hidden,
// the item itself is dropped (matches Cloud's MenuToppingGroupItemResource).
func TestLocalPosMenuDetail_ToppingHiddenDropsItem(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','B','PHO-1',1000)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp-shop','m1','p1',1)`)

	mustExec(t, db, `INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, min_select, max_qty_per_item, is_active) VALUES ('tg1','T','multiple','add','flat',0,1,1)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('top1','Hidden')`)
	mustExec(t, db, `INSERT INTO pos_topping_group_items (id, topping_group_id, product_id, sort_order) VALUES ('it1','tg1','top1',0)`)
	mustExec(t, db, `INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, product_sku_id, extra_price) VALUES ('isk1','it1','sk1',100)`)
	mustExec(t, db, `INSERT INTO pos_product_topping_groups (product_id, topping_group_id, sort_order) VALUES ('p1','tg1',0)`)
	// Tier-1 hides the only sku entry.
	mustExec(t, db, `INSERT INTO pos_menu_product_topping_overrides (id, menu_product_id, topping_group_id, topping_group_item_id, product_sku_id, is_hidden) VALUES ('ov1','mp-shop','tg1','it1','sk1',1)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)
	body := w.Body.String()
	if strings.Contains(body, `"name":"Hidden"`) {
		t.Errorf("hidden item must be dropped: %s", body)
	}
}

// #1708 — once a simple topping's product_sku_id is bound to the topping
// product's own SKU (which the menu-catalog feed ships into pos_product_skus),
// the POS feed must emit that non-null id plus a resolved sku_label / sku_code
// so pos-web can select it. A null id (the pre-fix state) leaves the option
// silently unclickable.
func TestLocalPosMenuDetail_BoundToppingSkuIsSelectable(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Banh mi')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','Default','BM',900)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp1','m1','p1',1)`)

	// Simple topping product with its OWN sku in the mirror, bound onto the item.
	mustExec(t, db, `INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, min_select, max_select, max_qty_per_item, sort_order, is_active) VALUES ('tg1','Nuoc ep','single','add','flat',1,1,1,0,1)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('top1','Nuoc dua')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('tsku1','top1','Coconut','NC01',300)`)
	mustExec(t, db, `INSERT INTO pos_topping_group_items (id, topping_group_id, product_id, sort_order) VALUES ('it1','tg1','top1',0)`)
	mustExec(t, db, `INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, product_sku_id, extra_price) VALUES ('isk1','it1','tsku1',300)`)
	mustExec(t, db, `INSERT INTO pos_product_topping_groups (product_id, topping_group_id, sort_order) VALUES ('p1','tg1',0)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()

	for _, frag := range []string{
		`"product_sku_id":"tsku1"`, // bound → the picker can resolve it
		`"sku_label":"Coconut"`,    // resolves from the mirrored topping SKU
		`"sku_code":"NC01"`,
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("selectable topping shape missing %q\nbody=%s", frag, body)
		}
	}
}
