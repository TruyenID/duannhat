package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// #1099 — resolveProductTaxRate powers the pos-web 税込/税抜 menu display. It
// mirrors the resolver chain (menu-item type → branch default → brand default)
// off the synced local mirror. ONE rate per type; no order-type branch.
func TestResolveProductTaxRate(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO tax_types (id, code, rate, is_default) VALUES
		('std','STANDARD',10,1),
		('red','REDUCED',8,0)`)

	// p1: explicit REDUCED type. p2: inherits (null type) → branch default.
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Bento'),('p2','Cola')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES
		('s1','p1','R','B-1',1000),
		('s2','p2','R','C-1',300)`)
	mustExec(t, db, `INSERT INTO menu_items (id, sku_id, name, tax_type_id) VALUES
		('mi1','s1','Bento','red'),
		('mi2','s2','Cola',NULL)`)
	// Branch default = STANDARD (used by the inherit product p2).
	mustExec(t, db, `INSERT INTO shop_settings (key, value) VALUES ('default_tax_type_id','std')`)

	t.Run("explicit reduced type resolves its ONE rate", func(t *testing.T) {
		rate, ok := srv.resolveProductTaxRate("p1")
		if !ok || rate != 8 {
			t.Fatalf("p1 = (%v,%v), want (8,true)", rate, ok)
		}
	})

	t.Run("null type inherits the branch default", func(t *testing.T) {
		rate, ok := srv.resolveProductTaxRate("p2")
		if !ok || rate != 10 {
			t.Fatalf("p2 = (%v,ok=%v), want (10,true) via STANDARD default", rate, ok)
		}
	})

	t.Run("no default anywhere → not ok", func(t *testing.T) {
		mustExec(t, db, `DELETE FROM shop_settings WHERE key='default_tax_type_id'`)
		mustExec(t, db, `UPDATE tax_types SET is_default=0`)
		if _, ok := srv.resolveProductTaxRate("p2"); ok {
			t.Fatal("p2 with no resolvable type should return ok=false")
		}
	})
}

// TestLocalPosMenuProducts_SerializesTaxRate locks the actual wire contract:
// the paginated /pos/menus/{menu}/products endpoint (what pos-web's menu grid
// calls) must stamp tax_rate onto each product so the card can render the
// 税込/税抜 pair without a per-item round-trip.
func TestLocalPosMenuProducts_SerializesTaxRate(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = nil

	mustExec(t, db, `INSERT INTO tax_types (id, code, rate, is_default) VALUES
		('std','STANDARD',10,1),
		('red','REDUCED',8,0)`)
	// Branch default = REDUCED — an untyped menu item inherits 8%.
	mustExec(t, db, `INSERT INTO shop_settings (key, value) VALUES ('default_tax_type_id','red')`)

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Bun Cha'),('p2','Beer')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES
		('s1','p1','Regular',2350),
		('s2','p2','Can',500)`)
	// p1: untyped menu item → inherits branch default REDUCED (8).
	// p2: explicit STANDARD — assigned in the catalog like any other product.
	mustExec(t, db, `INSERT INTO menu_items (id, sku_id, name, tax_type_id) VALUES
		('mi1','s1','Bun Cha',NULL),
		('mi2','s2','Beer','std')`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES
		('mp1','m1','p1',1),
		('mp2','m1','p2',2)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1/products", nil)
	req.SetPathValue("seg1", "m1")
	req.SetPathValue("seg2", "products")
	w := httptest.NewRecorder()
	srv.dispatchMenuTwoSeg(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}

	var env struct {
		Data []struct {
			ProductID string `json:"product_id"`
			Product   struct {
				Name    string   `json:"name"`
				TaxRate *float64 `json:"tax_rate"`
			} `json:"product"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &env); err != nil {
		t.Fatalf("decode: %v — body=%s", err, w.Body.String())
	}
	if len(env.Data) != 2 {
		t.Fatalf("want 2 products, got %d — body=%s", len(env.Data), w.Body.String())
	}

	byName := map[string]int{}
	for i, d := range env.Data {
		byName[d.Product.Name] = i
	}

	bunCha := env.Data[byName["Bun Cha"]].Product
	if bunCha.TaxRate == nil || *bunCha.TaxRate != 8 {
		t.Errorf("Bun Cha tax_rate = %v, want 8 inherited from REDUCED default", ptrOrNil(bunCha.TaxRate))
	}

	beer := env.Data[byName["Beer"]].Product
	if beer.TaxRate == nil || *beer.TaxRate != 10 {
		t.Errorf("Beer tax_rate = %v, want 10 — configured STANDARD, no flag, no escalation", ptrOrNil(beer.TaxRate))
	}
}

func ptrOrNil(p *float64) any {
	if p == nil {
		return nil
	}
	return *p
}
