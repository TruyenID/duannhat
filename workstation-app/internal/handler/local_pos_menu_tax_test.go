package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// plan-043 — resolveProductTaxRates powers the pos-web 税込/税抜 menu display. It
// mirrors the resolver chain (menu-item type → branch default → brand default)
// + 酒類 escalation, off the synced local mirror.
func TestResolveProductTaxRates(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO tax_types (id, code, rate_dine_in, rate_takeaway, is_default) VALUES
		('std','STANDARD',10,10,1),
		('red','REDUCED',10,8,0)`)

	// p1: explicit REDUCED type. p2: inherits (null type) → branch default.
	// p3: alcohol on REDUCED → escalated to the dine-in rate for both.
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Bento'),('p2','Cola'),('p3','Beer')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES
		('s1','p1','R','B-1',1000),
		('s2','p2','R','C-1',300),
		('s3','p3','R','BE-1',500)`)
	mustExec(t, db, `INSERT INTO menu_items (id, sku_id, name, tax_type_id, is_alcohol) VALUES
		('mi1','s1','Bento','red',0),
		('mi2','s2','Cola',NULL,0),
		('mi3','s3','Beer','red',1)`)
	// Branch default = STANDARD (used by the inherit product p2).
	mustExec(t, db, `INSERT INTO shop_settings (key, value) VALUES ('default_tax_type_id','std')`)

	t.Run("explicit reduced type", func(t *testing.T) {
		dineIn, takeaway, alcohol, ok := srv.resolveProductTaxRates("p1")
		if !ok || dineIn != 10 || takeaway != 8 || alcohol {
			t.Fatalf("p1 = (%v,%v,%v,%v), want (10,8,false,true)", dineIn, takeaway, alcohol, ok)
		}
	})

	t.Run("null type inherits the branch default", func(t *testing.T) {
		dineIn, takeaway, _, ok := srv.resolveProductTaxRates("p2")
		if !ok || dineIn != 10 || takeaway != 10 {
			t.Fatalf("p2 = (%v,%v,ok=%v), want (10,10,true) via STANDARD default", dineIn, takeaway, ok)
		}
	})

	t.Run("alcohol on a reduced type escalates takeaway to the dine-in rate", func(t *testing.T) {
		dineIn, takeaway, alcohol, ok := srv.resolveProductTaxRates("p3")
		if !ok || dineIn != 10 || takeaway != 10 || !alcohol {
			t.Fatalf("p3 = (%v,%v,%v,%v), want (10,10,true,true) — 酒類 escalated", dineIn, takeaway, alcohol, ok)
		}
	})

	t.Run("no default anywhere → not ok", func(t *testing.T) {
		mustExec(t, db, `DELETE FROM shop_settings WHERE key='default_tax_type_id'`)
		mustExec(t, db, `UPDATE tax_types SET is_default=0`)
		if _, _, _, ok := srv.resolveProductTaxRates("p2"); ok {
			t.Fatal("p2 with no resolvable type should return ok=false")
		}
	})
}

// TestLocalPosMenuProducts_SerializesTaxRates locks the actual wire contract:
// the paginated /pos/menus/{menu}/products endpoint (what pos-web's menu grid
// calls) must stamp tax_rate_dine_in / tax_rate_takeaway / is_alcohol onto each
// product so the card can render the 税込/税抜 pair without a per-item round-trip.
// This is the exact "Bun Cha shows one price in both modes" regression — the
// resolver worked but the products handler dropped the fields on the floor.
func TestLocalPosMenuProducts_SerializesTaxRates(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = nil

	mustExec(t, db, `INSERT INTO tax_types (id, code, rate_dine_in, rate_takeaway, is_default) VALUES
		('std','STANDARD',10,10,1),
		('red','REDUCED',10,8,0)`)
	// Branch default = REDUCED — mirrors ベト屋 新宿店 where shop_settings pins
	// the reduced type so an untyped menu item inherits 10/8.
	mustExec(t, db, `INSERT INTO shop_settings (key, value) VALUES ('default_tax_type_id','red')`)

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','L','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Bun Cha'),('p2','Beer')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES
		('s1','p1','Regular',2350),
		('s2','p2','Can',500)`)
	// p1: untyped menu item → inherits branch default REDUCED (10/8).
	// p2: explicit REDUCED + is_alcohol → 酒類 escalates takeaway to the 10 dine-in rate.
	mustExec(t, db, `INSERT INTO menu_items (id, sku_id, name, tax_type_id, is_alcohol) VALUES
		('mi1','s1','Bun Cha',NULL,0),
		('mi2','s2','Beer','red',1)`)
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
				Name            string   `json:"name"`
				TaxRateDineIn   *float64 `json:"tax_rate_dine_in"`
				TaxRateTakeaway *float64 `json:"tax_rate_takeaway"`
				IsAlcohol       bool     `json:"is_alcohol"`
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
	if bunCha.TaxRateDineIn == nil || *bunCha.TaxRateDineIn != 10 ||
		bunCha.TaxRateTakeaway == nil || *bunCha.TaxRateTakeaway != 8 || bunCha.IsAlcohol {
		t.Errorf("Bun Cha tax = (dineIn=%v, takeaway=%v, alcohol=%v), want (10,8,false) inherited from REDUCED default",
			ptrOrNil(bunCha.TaxRateDineIn), ptrOrNil(bunCha.TaxRateTakeaway), bunCha.IsAlcohol)
	}

	beer := env.Data[byName["Beer"]].Product
	if beer.TaxRateDineIn == nil || *beer.TaxRateDineIn != 10 ||
		beer.TaxRateTakeaway == nil || *beer.TaxRateTakeaway != 10 || !beer.IsAlcohol {
		t.Errorf("Beer tax = (dineIn=%v, takeaway=%v, alcohol=%v), want (10,10,true) — 酒類 escalated off REDUCED",
			ptrOrNil(beer.TaxRateDineIn), ptrOrNil(beer.TaxRateTakeaway), beer.IsAlcohol)
	}
}

func ptrOrNil(p *float64) any {
	if p == nil {
		return nil
	}
	return *p
}
