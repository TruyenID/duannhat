package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// #1180 / #1380 — the spotlight read path.
//
// What these pin is the pair of things that turn into wrong money if they slip:
// the price served is the PROMO price (never the menu price for the same SKU),
// and `tax_type_id` is whatever Cloud already collapsed (null stays null =
// inherit). Plus the three filters that decide whether a promo is on screen at
// all: section active, window open, and the member actually priced.

func getFloatingSections(t *testing.T, srv *Server, locale string) map[string]any {
	t.Helper()
	req := httptest.NewRequest("GET", "/api/v1/pos/floating-sections", nil)
	if locale != "" {
		req.Header.Set("Accept-Language", locale)
	}
	w := httptest.NewRecorder()
	srv.handleLocalPosFloatingSections(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	var out map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &out); err != nil {
		t.Fatalf("bad JSON: %v\nbody=%s", err, w.Body.String())
	}
	return out
}

func TestFloatingSections_ServesPromoPriceAndCollapsedTax(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	// The same SKU is sold from an ordinary menu at 50000 and from the
	// spotlight at 30000. Both numbers are real; the endpoint must return the
	// spotlight one.
	mustExec(t, db, `INSERT INTO pos_products (id, name, name_vi) VALUES ('p1','Beer','Bia')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price)
	                 VALUES ('sk1','p1','Bottle','BIA-1',50000)`)

	// No schedule rows and no date bounds = open all day. Deterministic, so the
	// test does not depend on the wall clock the way a 17:00-19:00 window would.
	mustExec(t, db, `INSERT INTO pos_floating_sections (id, name, name_vi, priority, is_active)
	                 VALUES ('fs1','Happy Hour','Giờ vàng',5,1)`)
	mustExec(t, db, `INSERT INTO pos_floating_section_products
	                 (id, floating_section_id, product_id, tax_type_id, is_active, display_order)
	                 VALUES ('fsp1','fs1','p1','tax-reduced',1,0)`)
	mustExec(t, db, `INSERT INTO pos_floating_section_product_skus
	                 (id, floating_section_product_id, product_sku_id, selling_price, is_active)
	                 VALUES ('fss1','fsp1','sk1',30000,1)`)

	out := getFloatingSections(t, srv, "vi")
	data, _ := out["data"].([]any)
	if len(data) != 1 {
		t.Fatalf("want 1 open section, got %d: %v", len(data), out)
	}
	sec := data[0].(map[string]any)

	if sec["name"] != "Giờ vàng" {
		t.Errorf("locale name: want 'Giờ vàng', got %v", sec["name"])
	}

	prods := sec["products"].([]any)
	if len(prods) != 1 {
		t.Fatalf("want 1 product, got %d", len(prods))
	}
	p := prods[0].(map[string]any)

	// The membership id is what identifies "this product, from THIS spotlight"
	// — the key the order path will need to price toppings and tax correctly.
	if p["floating_section_product_id"] != "fsp1" {
		t.Errorf("floating_section_product_id: want fsp1, got %v", p["floating_section_product_id"])
	}
	if p["name"] != "Bia" {
		t.Errorf("product locale name: want 'Bia', got %v", p["name"])
	}
	if p["tax_type_id"] != "tax-reduced" {
		t.Errorf("tax_type_id must pass through Cloud's collapsed value, got %v", p["tax_type_id"])
	}

	skus := p["skus"].([]any)
	if len(skus) != 1 {
		t.Fatalf("want 1 sku, got %d", len(skus))
	}
	price := skus[0].(map[string]any)["selling_price"]
	if price != float64(30000) {
		t.Errorf("PROMO price expected (30000), got %v — the menu price is 50000", price)
	}
}

func TestFloatingSections_NullTaxTypeStaysNull(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price)
	                 VALUES ('sk1','p1','Bowl',80000)`)
	mustExec(t, db, `INSERT INTO pos_floating_sections (id, name, is_active) VALUES ('fs1','Lunch deal',1)`)
	mustExec(t, db, `INSERT INTO pos_floating_section_products
	                 (id, floating_section_id, product_id, tax_type_id, is_active)
	                 VALUES ('fsp1','fs1','p1',NULL,1)`)
	mustExec(t, db, `INSERT INTO pos_floating_section_product_skus
	                 (id, floating_section_product_id, product_sku_id, selling_price, is_active)
	                 VALUES ('fss1','fsp1','sk1',60000,1)`)

	out := getFloatingSections(t, srv, "")
	p := out["data"].([]any)[0].(map[string]any)["products"].([]any)[0].(map[string]any)

	// null = inherit → the device resolver walks on to branch then brand
	// default, exactly as Cloud does. An empty string here would send the
	// resolver looking for a tax type with id "" and quietly bill 0%.
	if v, ok := p["tax_type_id"]; !ok || v != nil {
		t.Errorf("null tax_type_id must stay null (inherit), got %#v", v)
	}
}

func TestFloatingSections_HiddenWhenNotSellable(t *testing.T) {
	// Every case seeds a FULLY sellable member (active promo sku) unless it is
	// the case about pricing itself. Without that the section would drop out
	// for the wrong reason — mutation-checked: disabling the window evaluator
	// left these green until the sku was seeded, which means they were not
	// testing the gate they claim to.
	cases := []struct {
		name      string
		seed      []string
		activeSku bool
	}{
		{
			// An operator switched the promo off; it must vanish from the POS
			// even though its rows are still replicated.
			name:      "section inactive",
			activeSku: true,
			seed: []string{
				`INSERT INTO pos_floating_sections (id, name, is_active) VALUES ('fs1','Off',0)`,
			},
		},
		{
			// days_of_week = 0 matches no day, so the window never opens. This
			// is the "outside the happy hour" case without depending on the
			// wall clock at test time.
			name:      "window never open",
			activeSku: true,
			seed: []string{
				`INSERT INTO pos_floating_sections (id, name, is_active) VALUES ('fs1','Never',1)`,
				`INSERT INTO pos_floating_section_schedules
				 (id, floating_section_id, days_of_week, start_time, end_time, is_active)
				 VALUES ('sch1','fs1',0,'17:00','19:00',1)`,
			},
		},
		{
			// Date range already over.
			name:      "date range expired",
			activeSku: true,
			seed: []string{
				`INSERT INTO pos_floating_sections (id, name, is_active, start_date, end_date)
				 VALUES ('fs1','Past',1,'2020-01-01','2020-01-31')`,
			},
		},
		{
			// Member with no ACTIVE promo sku has no price of its own here.
			// Showing it would mean selling the ordinary price under a promo
			// label, so the whole (now empty) section is dropped.
			name:      "member has no active promo sku",
			activeSku: false,
			seed: []string{
				`INSERT INTO pos_floating_sections (id, name, is_active) VALUES ('fs1','Empty',1)`,
				`INSERT INTO pos_floating_section_product_skus
				 (id, floating_section_product_id, product_sku_id, selling_price, is_active)
				 VALUES ('fss1','fsp1','sk1',30000,0)`,
			},
		},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			srv, db := newServerWithAuth(t, "http://unused")
			mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Beer')`)
			mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price)
			                 VALUES ('sk1','p1','Bottle',50000)`)
			for _, stmt := range c.seed {
				mustExec(t, db, stmt)
			}
			mustExec(t, db, `INSERT INTO pos_floating_section_products
			                 (id, floating_section_id, product_id, is_active)
			                 VALUES ('fsp1','fs1','p1',1)`)
			if c.activeSku {
				mustExec(t, db, `INSERT INTO pos_floating_section_product_skus
				                 (id, floating_section_product_id, product_sku_id, selling_price, is_active)
				                 VALUES ('fss-ok','fsp1','sk1',30000,1)`)
			}

			out := getFloatingSections(t, srv, "")
			if n := len(out["data"].([]any)); n != 0 {
				t.Errorf("section must not be served (%s), got %d: %v", c.name, n, out)
			}
		})
	}
}
