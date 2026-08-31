package handler

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// callJSONLocale is callJSON with an Accept-Language header so the revenue
// reports resolve product / SKU names in the operator's selected pos-web
// language (the reported bug: by-product + voids showed base-language names).
func callJSONLocale(t *testing.T, srv *Server, path, locale string, handler http.HandlerFunc) map[string]any {
	t.Helper()
	req := httptest.NewRequest("GET", path, nil)
	req.Header.Set("Accept-Language", locale)
	rec := httptest.NewRecorder()
	handler(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("status %d body=%s", rec.Code, rec.Body.String())
	}
	var env struct {
		Data map[string]any `json:"data"`
	}
	if err := json.NewDecoder(rec.Body).Decode(&env); err != nil {
		t.Fatalf("decode: %v body=%s", err, rec.Body.String())
	}
	return env.Data
}

func revenueWindow() (string, string) {
	return time.Now().UTC().AddDate(0, 0, -1).Format("2006-01-02"),
		time.Now().UTC().AddDate(0, 0, 1).Format("2006-01-02")
}

// by-product report follows Accept-Language for product name + SKU variant.
func TestRevenueByProduct_LocalizesNames(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused") // paired to branch-A
	now := time.Now().UTC().Format(time.RFC3339)

	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja) VALUES ('p1','Vietnamese Coffee','ベトナムコーヒー')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, name_ja, selling_price) VALUES ('sk1','p1','Iced','アイス',450)`)

	mustExec(t, db, `INSERT INTO orders (id, order_code, order_type, status, opened_at,
		subtotal, discount_amount, total_amount, paid_amount, branch_id, guest_count, created_at, updated_at)
		VALUES ('o1','O-1','dine_in','closed',?,450,0,450,450,'branch-A',1,?,?)`, now, now, now)
	mustExec(t, db, `INSERT INTO order_items (id, customer_order_id, menu_item_name, sku_variant_name,
		product_sku_id, quantity, unit_price, subtotal, printer_group, status, print_status, created_at, updated_at)
		VALUES ('it1','o1','Vietnamese Coffee','Iced','sk1',1,450,450,'bar','served','printed',?,?)`, now, now)

	from, to := revenueWindow()
	url := fmt.Sprintf("/api/v1/pos/revenue/by-product?level=sku&from=%s&to=%s", from, to)

	ja := callJSONLocale(t, srv, url, "ja", srv.handleLocalPosRevenueByProduct)
	rows := ja["rows"].([]any)
	if len(rows) == 0 {
		t.Fatal("no rows")
	}
	r0 := rows[0].(map[string]any)
	if r0["name"] != "ベトナムコーヒー" {
		t.Errorf("ja name: want 'ベトナムコーヒー', got %v", r0["name"])
	}
	if r0["sku"] != "アイス" {
		t.Errorf("ja sku: want 'アイス', got %v", r0["sku"])
	}

	// vi has no translation seeded → falls back to the base name.
	vi := callJSONLocale(t, srv, url, "vi", srv.handleLocalPosRevenueByProduct)
	r0vi := vi["rows"].([]any)[0].(map[string]any)
	if r0vi["name"] != "Vietnamese Coffee" {
		t.Errorf("vi fallback: want base 'Vietnamese Coffee', got %v", r0vi["name"])
	}
}

// voids (top items + void-events) follow Accept-Language for name + variant.
func TestRevenueVoids_LocalizesNames(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	now := time.Now().UTC().Format(time.RFC3339)

	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja) VALUES ('p1','Fresh Spring Rolls','生春巻き')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, name_ja, selling_price) VALUES ('sk1','p1','2pc','2個',600)`)

	// A closed order carrying ONE per-item void.
	mustExec(t, db, `INSERT INTO orders (id, order_code, order_type, status, opened_at,
		subtotal, discount_amount, total_amount, paid_amount, branch_id, guest_count, created_at, updated_at)
		VALUES ('o1','O-1','dine_in','closed',?,600,0,600,600,'branch-A',1,?,?)`, now, now, now)
	mustExec(t, db, `INSERT INTO order_items (id, customer_order_id, menu_item_name, sku_variant_name,
		product_sku_id, quantity, unit_price, subtotal, printer_group, status, print_status, void_reason, voided_at, created_at, updated_at)
		VALUES ('it1','o1','Fresh Spring Rolls','2pc','sk1',1,600,600,'kitchen','voided','printed','wrong_item',?,?,?)`, now, now, now)

	from, to := revenueWindow()

	// top_items localized
	voids := callJSONLocale(t, srv,
		fmt.Sprintf("/api/v1/pos/revenue/voids?granularity=day&from=%s&to=%s", from, to),
		"ja", srv.handleLocalPosRevenueVoids)
	top := voids["top_items"].([]any)
	if len(top) == 0 {
		t.Fatal("no top_items")
	}
	t0 := top[0].(map[string]any)
	if t0["name"] != "生春巻き" {
		t.Errorf("top item name: want '生春巻き', got %v", t0["name"])
	}
	if t0["variant"] != "2個" {
		t.Errorf("top item variant: want '2個', got %v", t0["variant"])
	}

	// void-events item_name + variant localized
	events := callJSONLocale(t, srv,
		fmt.Sprintf("/api/v1/pos/revenue/void-events?granularity=day&from=%s&to=%s&type=item", from, to),
		"ja", srv.handleLocalPosRevenueVoidEvents)
	evRows := events["rows"].([]any)
	if len(evRows) == 0 {
		t.Fatal("no void events")
	}
	ev0 := evRows[0].(map[string]any)
	if ev0["item_name"] != "生春巻き" {
		t.Errorf("void-event item_name: want '生春巻き', got %v", ev0["item_name"])
	}
	if ev0["variant"] != "2個" {
		t.Errorf("void-event variant: want '2個', got %v", ev0["variant"])
	}
}
