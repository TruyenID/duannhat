package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// Menu detail must emit product.image_url, product.product_type_code,
// product.gallery[], sku.image_url so pos-web's MenuCatalog renders the
// real card thumbnails / cart line images / combo card variant in LAN
// mode (parity with Shop\MenuController::show).
func TestLocalPosMenuDetail_EmitsImageAndTypeAndGallery(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m1','Lunch','published',0)`)
	mustExec(t, db, `INSERT INTO pos_menu_sections (id, menu_id, name, sort_order) VALUES ('sec1','m1','Mains',0)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name, image_url, product_type_code) VALUES ('p1','Combo A','https://cdn/p1.jpg','combo')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, image_url) VALUES ('sk1','p1','Regular','PHO-1',1000,'https://cdn/sk1.jpg')`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, menu_section_id, display_order) VALUES ('mp1','m1','p1','sec1',1)`)

	// Two gallery rows in different sort_order — first should be the
	// one with the smaller sort_order.
	mustExec(t, db, `INSERT INTO pos_product_galleries (id, product_id, url, sort_order) VALUES ('g2','p1','https://cdn/p1-b.jpg',2)`)
	mustExec(t, db, `INSERT INTO pos_product_galleries (id, product_id, url, sort_order) VALUES ('g1','p1','https://cdn/p1-a.jpg',1)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()

	for _, frag := range []string{
		`"image_url":"https://cdn/p1.jpg"`,
		`"product_type_code":"combo"`,
		`"gallery"`,
		`"url":"https://cdn/p1-a.jpg"`, // sort_order=1 → should be first
		`"url":"https://cdn/p1-b.jpg"`,
		`"image_url":"https://cdn/sk1.jpg"`, // sku-level image
		`"topping_groups":[]`,
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("response missing %q\nbody=%s", frag, body)
		}
	}

	// Gallery order: a (sort=1) must precede b (sort=2) in the JSON.
	idxA := strings.Index(body, "p1-a.jpg")
	idxB := strings.Index(body, "p1-b.jpg")
	if idxA == -1 || idxB == -1 || idxA > idxB {
		t.Errorf("gallery ordering broken (a=%d b=%d)", idxA, idxB)
	}
}

// /pos/menus/{menu}/products must surface SAME shape (image_url, gallery,
// product_type_code, sku image) — this is the paginated/searchable
// endpoint pos-web hits whenever the cashier types in the search box.
func TestLocalPosMenuProducts_EmitsImagesAndTypeAndGallery(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = nil

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name, image_url, product_type_code) VALUES ('p1','Pho','https://cdn/p1.jpg','food')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, image_url) VALUES ('s1','p1','Bowl','PHO-1',1000,'https://cdn/s1.jpg')`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp1','m1','p1',1)`)
	mustExec(t, db, `INSERT INTO pos_product_galleries (id, product_id, url, sort_order) VALUES ('g1','p1','https://cdn/p1-a.jpg',1)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1/products", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuProducts(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()
	for _, frag := range []string{
		`"image_url":"https://cdn/p1.jpg"`,
		`"product_type_code":"food"`,
		`"url":"https://cdn/p1-a.jpg"`,
		`"image_url":"https://cdn/s1.jpg"`,
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("products response missing %q\nbody=%s", frag, body)
		}
	}
}

// Search must hit name + SKU code + variant name — typing a barcode or
// variant label on LAN must return the product, matching Shop\MenuController
// listProducts behavior.
func TestLocalPosMenuProducts_SearchMatchesSkuAndVariant(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = nil

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)

	// Product A: name match
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('pA','Espresso')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sA','pA','Single','ESP-1',300)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mpA','m1','pA',1)`)

	// Product B: name doesn't match, but a SKU barcode does
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('pB','Latte')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sB','pB','Large','BARZZZ',500)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mpB','m1','pB',2)`)

	// Product C: name doesn't match, but a variant label does
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('pC','Tea')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sC','pC','OolongVariant','TEA-1',400)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mpC','m1','pC',3)`)

	// Search for "BARZZZ" → only mpB.
	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1/products?search=BARZZZ", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuProducts(w, req)
	body := w.Body.String()
	if !strings.Contains(body, `"id":"mpB"`) {
		t.Errorf("barcode search must surface pB: %s", body)
	}
	if strings.Contains(body, `"id":"mpA"`) || strings.Contains(body, `"id":"mpC"`) {
		t.Errorf("barcode search leaked other products: %s", body)
	}

	// Search for "Oolong" → only mpC (variant label match).
	req = httptest.NewRequest("GET", "/api/v1/pos/menus/m1/products?search=Oolong", nil)
	req.SetPathValue("menu", "m1")
	w = httptest.NewRecorder()
	srv.handleLocalPosMenuProducts(w, req)
	body = w.Body.String()
	if !strings.Contains(body, `"id":"mpC"`) {
		t.Errorf("variant search must surface pC: %s", body)
	}
	if strings.Contains(body, `"id":"mpA"`) || strings.Contains(body, `"id":"mpB"`) {
		t.Errorf("variant search leaked other products: %s", body)
	}

	// Search for "Espresso" → only mpA (name match).
	req = httptest.NewRequest("GET", "/api/v1/pos/menus/m1/products?search=Espresso", nil)
	req.SetPathValue("menu", "m1")
	w = httptest.NewRecorder()
	srv.handleLocalPosMenuProducts(w, req)
	body = w.Body.String()
	if !strings.Contains(body, `"id":"mpA"`) {
		t.Errorf("name search must surface pA: %s", body)
	}
}
