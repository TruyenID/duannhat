package handler

import (
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// pos-web's cart line reads `item.product_sku.image_url` for the
// thumbnail. loadProductSkuStub must surface it from the pos_*
// schema — pre-fix the stub only emitted name + price (no image),
// so every LAN cart line rendered the placeholder regardless of
// what the catalog had.
func TestLoadProductSkuStub_EmitsImageURL(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES ('p1','Pho','https://cdn/p1.jpg')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, image_url) VALUES ('sk1','p1','Regular','PHO-R',2300,'https://cdn/sk1.jpg')`)

	stub := srv.loadProductSkuStub("sk1")
	if stub == nil {
		t.Fatalf("stub must resolve when pos_product_skus has the row")
	}
	if stub["image_url"] != "https://cdn/sk1.jpg" {
		t.Errorf("sku image_url want 'https://cdn/sk1.jpg', got %v", stub["image_url"])
	}
	if stub["product_sku_id"] != "sk1" {
		t.Errorf("product_sku_id want 'sk1', got %v", stub["product_sku_id"])
	}
	product := stub["product"].(map[string]any)
	if product["name"] != "Pho" {
		t.Errorf("product.name want 'Pho', got %v", product["name"])
	}
	if product["image_url"] != "https://cdn/p1.jpg" {
		t.Errorf("product.image_url want 'https://cdn/p1.jpg', got %v", product["image_url"])
	}
}

// The cart line reads product_sku.product.name; it must follow the operator's
// selected language (name_ja / name_en / name_vi) so the order line matches the
// localized menu — not the base name. An unknown/empty locale keeps the base
// name (kiosk parity). A product missing a per-locale name falls back to base.
func TestLoadProductSkuStubLocalized_ResolvesNameByLocale(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja, name_en, name_vi)
		VALUES ('p1','Vietnamese Coffee','ベトナムコーヒー','Vietnamese Coffee','Cà phê Việt Nam')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price)
		VALUES ('sk1','p1','Iced','VC-ICE',450)`)
	// A second product with NO Japanese name → must fall back to the base name.
	mustExec(t, db, `INSERT INTO pos_products (id, name, name_en) VALUES ('p2','Plain Tea','Plain Tea')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price)
		VALUES ('sk2','p2','Hot','PT-HOT',300)`)

	cases := []struct {
		locale string
		sku    string
		want   string
	}{
		{"ja", "sk1", "ベトナムコーヒー"},
		{"en", "sk1", "Vietnamese Coffee"},
		{"vi", "sk1", "Cà phê Việt Nam"},
		{"", "sk1", "Vietnamese Coffee"}, // unknown locale → base name
		{"ja", "sk2", "Plain Tea"},       // no name_ja → base-name fallback
	}
	for _, c := range cases {
		stub := srv.loadProductSkuStubLocalized(c.sku, c.locale)
		if stub == nil {
			t.Fatalf("locale %q sku %q: stub must resolve", c.locale, c.sku)
		}
		got := stub["product"].(map[string]any)["name"]
		if got != c.want {
			t.Errorf("locale %q sku %q: product.name want %q, got %v", c.locale, c.sku, c.want, got)
		}
	}
}

// The serialized order item's `name` (and the cart's product_sku.product.name)
// must be localized so an already-added line follows a later language switch —
// the reported bug: menu showed 日本語 but the order line stayed English.
func TestCustomerOrderItemShape_LocalizesDisplayName(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja) VALUES ('p1','Vietnamese Coffee','ベトナムコーヒー')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price)
		VALUES ('sk1','p1','Iced','VC-ICE',450)`)

	it := service.Item{
		ID:           "it1",
		ProductSkuID: "sk1",
		MenuItemName: "Vietnamese Coffee", // English snapshot taken at add time
		Quantity:     1,
		UnitPrice:    450,
	}

	shaped := srv.customerOrderItemShape(it, "ja")
	if shaped["name"] != "ベトナムコーヒー" {
		t.Errorf("name want localized 'ベトナムコーヒー', got %v", shaped["name"])
	}
	prod := shaped["product_sku"].(map[string]any)["product"].(map[string]any)
	if prod["name"] != "ベトナムコーヒー" {
		t.Errorf("product_sku.product.name want 'ベトナムコーヒー', got %v", prod["name"])
	}
}

// Printed tickets must follow the print locale, not the name snapshotted at add
// time (the reported bug: JA operator got ASCII-folded Vietnamese). localizeOrder
// ForPrint rewrites the item name, SKU variant name and topping names in place.
func TestLocalizeOrderForPrint_ItemVariantAndTopping(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja) VALUES ('p1','Vietnamese Coffee','ベトナムコーヒー')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, name_ja, sku, selling_price) VALUES ('sk1','p1','Iced','アイス','C-1',450)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja) VALUES ('tp','Fish sauce','ヌクマム')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, name_ja, sku, selling_price) VALUES ('tsk','tp','Fish sauce','ヌクマム','FS',0)`)

	newOrder := func() *service.Order {
		return &service.Order{
			Items: []service.Item{{
				ID:             "it1",
				ProductSkuID:   "sk1",
				MenuItemName:   "Ca phe Viet", // add-time (ASCII-folded vi) snapshot
				SkuVariantName: "Da",
				Toppings:       []service.ItemTopping{{ProductSkuID: "tsk", Name: "Nuoc mam"}},
			}},
		}
	}

	o := newOrder()
	srv.localizeOrderForPrint(o, "ja")
	it := o.Items[0]
	if it.MenuItemName != "ベトナムコーヒー" {
		t.Errorf("item name want 'ベトナムコーヒー', got %q", it.MenuItemName)
	}
	if it.SkuVariantName != "アイス" {
		t.Errorf("variant want 'アイス', got %q", it.SkuVariantName)
	}
	if it.Toppings[0].Name != "ヌクマム" {
		t.Errorf("topping want 'ヌクマム', got %q", it.Toppings[0].Name)
	}

	// Blank locale is a no-op — the stored snapshot is preserved.
	o2 := newOrder()
	srv.localizeOrderForPrint(o2, "")
	if o2.Items[0].MenuItemName != "Ca phe Viet" {
		t.Errorf("blank locale must keep snapshot, got %q", o2.Items[0].MenuItemName)
	}
}

// The reported bug: the MENU showed 日本語 toppings but an ADDED order line kept
// the base-language topping snapshot. order_item_toppings only stores the add-
// time name, so the shape (API response) must re-localize it from the catalog —
// exactly like product name / variant / options already do.
func TestCustomerOrderShape_LocalizesToppingNames(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja) VALUES ('p1','Vietnamese Coffee','ベトナムコーヒー')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, name_ja, sku, selling_price) VALUES ('sk1','p1','Iced','アイス','C-1',450)`)
	// The topping is itself a product SKU with its own ja name.
	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja) VALUES ('tp','100% sugar','通常の甘さ')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, name_ja, sku, selling_price) VALUES ('tsk','tp','100% sugar','通常の甘さ','S',0)`)

	newOrder := func() *service.Order {
		return &service.Order{
			Items: []service.Item{{
				ID:           "it1",
				ProductSkuID: "sk1",
				MenuItemName: "Ca phe Viet",
				Quantity:     1,
				UnitPrice:    450,
				Toppings: []service.ItemTopping{
					// base-language snapshot, mirrored "X · X" as stored at add time
					{ID: "t1", ProductSkuID: "tsk", Name: "100% sugar · 100% sugar"},
					// orphaned topping SKU (product removed) — must keep its snapshot
					{ID: "t2", ProductSkuID: "gone", Name: "Chili"},
				},
			}},
		}
	}

	shape := srv.customerOrderShape(newOrder(), "ja")
	items := shape["items"].([]map[string]any)
	toppings := items[0]["toppings"].([]map[string]any)
	if toppings[0]["name"] != "通常の甘さ" {
		t.Errorf("topping[0] name want localized '通常の甘さ', got %v", toppings[0]["name"])
	}
	if toppings[1]["name"] != "Chili" {
		t.Errorf("orphaned topping must keep snapshot 'Chili', got %v", toppings[1]["name"])
	}

	// Blank locale → keep the stored snapshot untouched.
	shapeBlank := srv.customerOrderShape(newOrder(), "")
	tBlank := shapeBlank["items"].([]map[string]any)[0]["toppings"].([]map[string]any)
	if tBlank[0]["name"] != "100% sugar · 100% sugar" {
		t.Errorf("blank locale must keep snapshot, got %v", tBlank[0]["name"])
	}
}

// SKU with no per-variant image must fall back to the product image
// so pos-web still shows something instead of a placeholder.
func TestLoadProductSkuStub_FallsBackToProductImage(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES ('p1','Pho','https://cdn/p1.jpg')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','Regular','PHO-R',2300)`)

	stub := srv.loadProductSkuStub("sk1")
	if stub["image_url"] != "https://cdn/p1.jpg" {
		t.Errorf("fallback want product image, got %v", stub["image_url"])
	}
}

// When pos_products.image_url drifts from pos_product_galleries (Cloud
// sync runs them on separate cadences — the canonical image_url can lag
// behind the gallery rows), the cart sidebar must read the SAME row as
// the MenuCatalog tile. pos-web's catalog renders `product.gallery[0]`,
// so the stub prefers the first gallery row over the canonical
// image_url field. Without this, customer sees image A on the menu
// card and image B in the checkout sidebar after add-to-cart.
func TestLoadProductSkuStub_PrefersGalleryOverProductImageURL(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES ('p1','Pho','https://cdn/stale-product.jpg')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','Regular','PHO-R',2300)`)
	mustExec(t, db, `INSERT INTO pos_product_galleries (id, product_id, url, sort_order) VALUES
		('g1','p1','https://cdn/fresh-1.jpg',1),
		('g2','p1','https://cdn/fresh-2.jpg',2)`)

	stub := srv.loadProductSkuStub("sk1")
	if stub["image_url"] != "https://cdn/fresh-1.jpg" {
		t.Errorf("sku image: want gallery[0] 'https://cdn/fresh-1.jpg' (the row catalog tile renders), got %v", stub["image_url"])
	}
	product := stub["product"].(map[string]any)
	if product["image_url"] != "https://cdn/fresh-1.jpg" {
		t.Errorf("product image: want gallery[0], got %v", product["image_url"])
	}
}

// Gallery rows with NULL sort_order go LAST — matches loadProductGallery's
// `ORDER BY (sort_order IS NULL), sort_order, id` clause. Picking the
// wrong gallery row would put a never-curated upload at the top of the
// cart while the catalog tile keeps showing the curated first row.
func TestLoadProductSkuStub_GalleryNullSortOrderGoesLast(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','Regular','PHO-R',2300)`)
	mustExec(t, db, `INSERT INTO pos_product_galleries (id, product_id, url, sort_order) VALUES ('g-null','p1','https://cdn/no-sort.jpg', NULL)`)
	mustExec(t, db, `INSERT INTO pos_product_galleries (id, product_id, url, sort_order) VALUES ('g-1','p1','https://cdn/first.jpg', 1)`)

	stub := srv.loadProductSkuStub("sk1")
	if stub["image_url"] != "https://cdn/first.jpg" {
		t.Errorf("want sort_order=1 row 'first.jpg', got %v", stub["image_url"])
	}
}

// Gallery row wins over ps.image_url too — the cart sidebar must
// mirror the menu catalog tile (which always renders gallery[0]).
// Cloud's productSku.galleryFirst (snapshotted into ps.image_url) can
// point at a different asset than product.galleryFirst (snapshotted
// into pos_product_galleries) because they're separate gallery
// relations on Cloud. Letting ps.image_url win produced the very
// "menu shows A, cart shows B" drift this fix targets.
func TestLoadProductSkuStub_GalleryBeatsPerVariantImage(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES ('p1','Pho','https://cdn/p1.jpg')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, image_url) VALUES ('sk1','p1','Large','PHO-L',3000,'https://cdn/sk1-large.jpg')`)
	mustExec(t, db, `INSERT INTO pos_product_galleries (id, product_id, url, sort_order) VALUES ('g1','p1','https://cdn/gallery.jpg',1)`)

	stub := srv.loadProductSkuStub("sk1")
	if stub["image_url"] != "https://cdn/gallery.jpg" {
		t.Errorf("gallery row must win so cart mirrors catalog: got %v", stub["image_url"])
	}
	product := stub["product"].(map[string]any)
	if product["image_url"] != "https://cdn/gallery.jpg" {
		t.Errorf("product.image_url want gallery row 'https://cdn/gallery.jpg', got %v", product["image_url"])
	}
}

// When no gallery exists, ps.image_url is the next best snapshot of the
// SKU's appearance. Preserves the cart line for shops that haven't yet
// populated the gallery table but do snapshot per-variant images.
func TestLoadProductSkuStub_PerVariantImageWhenNoGallery(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES ('p1','Pho','https://cdn/p1.jpg')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, image_url) VALUES ('sk1','p1','Large','PHO-L',3000,'https://cdn/sk1-large.jpg')`)

	stub := srv.loadProductSkuStub("sk1")
	if stub["image_url"] != "https://cdn/sk1-large.jpg" {
		t.Errorf("no gallery → ps.image_url wins: got %v", stub["image_url"])
	}
}

// shapeOrderForResponse must rewrite item.product_sku.image_url to the
// /api/lan/images/{hash} form when the source URL is cached. Locks
// down the contract for cart line images across all 9 order
// endpoints that go through this helper.
func TestShapeOrderForResponse_RewritesCartLineImage(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.imageFetcher = service.NewImageFetcher(db)

	cachedURL := "https://cdn.example.com/cart.jpg"
	mustExec(t, db, `INSERT INTO pos_image_cache (url_hash, source_url, content_type, bytes, size) VALUES (?, ?, 'image/jpeg', x'00', 1)`,
		service.URLHash(cachedURL), cachedURL)

	mustExec(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES ('p1','Pho',?)`, cachedURL)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price, image_url) VALUES ('sk1','p1','R',1000,?)`, cachedURL)

	o := &service.Order{
		ID:        "o1",
		OrderCode: "ORD-1",
		OrderType: "spot",
		Status:    service.StatusOpen,
		OpenedAt:  time.Now(),
		Items: []service.Item{{
			ID:           "it1",
			ProductSkuID: "sk1",
			MenuItemName: "Pho · R",
			Quantity:     1,
			UnitPrice:    1000,
			Subtotal:     1000,
			Status:       service.ItemStatusPending,
		}},
	}

	req := httptest.NewRequest("GET", "/", nil)
	req.Host = "tablet.local:8080"
	shape := srv.shapeOrderForResponse(req, o)

	items := shape["items"].([]map[string]any)
	if len(items) != 1 {
		t.Fatalf("expected 1 item, got %d", len(items))
	}
	productSku := items[0]["product_sku"].(map[string]any)
	skuImage, _ := productSku["image_url"].(string)
	if !strings.HasPrefix(skuImage, "http://tablet.local:8080/api/lan/images/") {
		t.Errorf("cart line sku image_url should be rewritten to LAN URL, got %q", skuImage)
	}
	product := productSku["product"].(map[string]any)
	prodImage, _ := product["image_url"].(string)
	if !strings.HasPrefix(prodImage, "http://tablet.local:8080/api/lan/images/") {
		t.Errorf("cart line product image_url should be rewritten, got %q", prodImage)
	}
}

// plan-043 — the shared POS/customer order shape must carry the per-rate
// tax_breakdown, not just the kiosk bill. pos-web reads it to present the cart
// subtotal + service charge as 税込 (総額表示) so a LAN-served order matches the
// menu card. Without it pos-web falls back to the net summary while the gross
// line rows (which read the per-line tax_rate) render a divergent number.
func TestCustomerOrderShape_EmitsTaxBreakdown(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")

	// Net-entered order (is_tax_included=false): two 8% lines merge into one
	// group, one 10% line, a voided line drops out.
	o := &service.Order{
		ID:            "o1",
		OrderCode:     "ORD-1",
		OrderType:     "spot",
		Status:        service.StatusOpen,
		OpenedAt:      time.Now(),
		IsTaxIncluded: false,
		Items: []service.Item{
			{ID: "a", Subtotal: 1000, TaxAmount: 80, TaxRate: rp(8), Status: service.ItemStatusServed},
			{ID: "b", Subtotal: 500, TaxAmount: 50, TaxRate: rp(10), Status: service.ItemStatusServed},
			{ID: "c", Subtotal: 999, TaxAmount: 80, TaxRate: rp(8), Status: service.ItemStatusServed},
			{ID: "d", Subtotal: 300, TaxAmount: 30, TaxRate: rp(10), Status: "voided"},
		},
	}

	shape := srv.customerOrderShape(o, "")
	rows, ok := shape["tax_breakdown"].([]map[string]any)
	if !ok {
		t.Fatalf("tax_breakdown must be a []map[string]any, got %T", shape["tax_breakdown"])
	}
	if len(rows) != 2 {
		t.Fatalf("want 2 rate groups (8%% + 10%%), got %d: %+v", len(rows), rows)
	}
	// Ascending by rate: 8% (1000+999 net, 80+80 tax) then 10% (500 net, 50 tax).
	if rows[0]["rate"].(float64) != 8 || rows[0]["taxable"].(float64) != 1999 || rows[0]["tax"].(float64) != 160 {
		t.Errorf("8%% row = %+v, want rate 8 / taxable 1999 / tax 160", rows[0])
	}
	if rows[1]["rate"].(float64) != 10 || rows[1]["taxable"].(float64) != 500 || rows[1]["tax"].(float64) != 50 {
		t.Errorf("10%% row = %+v, want rate 10 / taxable 500 / tax 50", rows[1])
	}
}
