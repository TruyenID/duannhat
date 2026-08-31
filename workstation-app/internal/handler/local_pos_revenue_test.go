package handler

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// Test fixtures + helpers for the revenue endpoints. The page at
// /shop/{slug}/reports/revenue calls /api/v1/pos/revenue/summary and
// /api/v1/pos/revenue/by-product; these tests pin both.

func seedClosedOrder(t *testing.T, srv *Server, orderID, branchID string, daysAgo int, total int, items []orderItemFixture) {
	t.Helper()
	createdAt := time.Now().UTC().AddDate(0, 0, -daysAgo).Format(time.RFC3339)
	if branchID == "" {
		branchID = "branch-A"
	}
	mustExec(t, srv.db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at, closed_at,
		    subtotal, discount_amount, total_amount, paid_amount, branch_id,
		    guest_count, created_at, updated_at)
		VALUES (?, ?, 'spot', 'closed', ?, ?, ?, 0, ?, ?, ?, 2, ?, ?)`,
		orderID, "O-"+orderID[:6], createdAt, createdAt,
		total, total, total, branchID, createdAt, createdAt)
	for i, it := range items {
		mustExec(t, srv.db, `
			INSERT INTO order_items (id, customer_order_id, menu_item_id, menu_item_name,
			    product_sku_id, quantity, unit_price, subtotal,
			    printer_group, status, print_status, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'kitchen', 'served', 'printed', ?, ?)`,
			fmt.Sprintf("oi-%s-%d", orderID, i), orderID,
			it.menuItemID, it.name, nullableIfEmpty(it.skuID),
			it.quantity, it.unitPrice, it.quantity*it.unitPrice,
			createdAt, createdAt)
	}
}

type orderItemFixture struct {
	menuItemID string
	skuID      string
	name       string
	quantity   int
	unitPrice  int
}

func nullableIfEmpty(s string) any {
	if s == "" {
		return nil
	}
	return s
}

// callJSON drives an authenticated GET against the server's mux, parses
// the JSON envelope ({"data": ...}) and returns the inner data + status.
func callJSON(t *testing.T, srv *Server, path string, handler http.HandlerFunc) (map[string]any, int) {
	t.Helper()
	req := httptest.NewRequest("GET", path, nil)
	rec := httptest.NewRecorder()
	handler(rec, req)
	if rec.Code != http.StatusOK {
		return nil, rec.Code
	}
	var env struct {
		Data map[string]any `json:"data"`
	}
	if err := json.NewDecoder(rec.Body).Decode(&env); err != nil {
		t.Fatalf("decode response: %v\nbody=%s", err, rec.Body.String())
	}
	return env.Data, rec.Code
}

func TestRevenueSummary_YearGranularityBucketsByYear(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")

	// Two orders across two different years (relative to today).
	// year granularity should produce ≥2 series buckets covering both.
	seedClosedOrder(t, srv, "ord-yA", "branch-A", 400, 1500, nil)
	seedClosedOrder(t, srv, "ord-yB", "branch-A", 30, 2000, nil)

	data, status := callJSON(t, srv, "/api/v1/pos/revenue/summary?granularity=year",
		srv.handleLocalPosRevenueSummary)
	if status != http.StatusOK {
		t.Fatalf("status: %d", status)
	}
	if got := data["granularity"]; got != "year" {
		t.Errorf("granularity: want year, got %v", got)
	}
	series, _ := data["series"].([]any)
	if len(series) < 1 {
		t.Errorf("year series should have at least 1 bucket, got %d", len(series))
	}
	// Each bucket key must be a 4-digit year.
	for _, pt := range series {
		m := pt.(map[string]any)
		period := m["period"].(string)
		if len(period) != 4 {
			t.Errorf("year period must be YYYY, got %q", period)
		}
	}
}

func TestRevenueByProduct_Pagination(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")

	// Seed 30 distinct products → 30 ranked rows. page=1 per_page=10
	// should return rows[0..9]; page=2 should return rows[10..19].
	for i := 0; i < 30; i++ {
		mustExec(t, srv.db, `INSERT INTO menu_items (id, name, category, price, is_active) VALUES (?, ?, 'Cat', ?, 1)`,
			fmt.Sprintf("mi-%02d", i), fmt.Sprintf("Item %02d", i), 100*(i+1))
		seedClosedOrder(t, srv,
			fmt.Sprintf("ord-%02d", i), "branch-A", i+1, 100*(i+1),
			[]orderItemFixture{{
				menuItemID: fmt.Sprintf("mi-%02d", i),
				name:       fmt.Sprintf("Item %02d", i),
				quantity:   1, unitPrice: 100 * (i + 1),
			}},
		)
	}

	// Wide window so all 30 land.
	url := "/api/v1/pos/revenue/by-product?from=2024-01-01&to=2026-12-31&per_page=10&page=1"
	data, status := callJSON(t, srv, url, srv.handleLocalPosRevenueByProduct)
	if status != http.StatusOK {
		t.Fatalf("status %d", status)
	}
	rows := data["rows"].([]any)
	if len(rows) != 10 {
		t.Errorf("page=1 per_page=10: want 10 rows, got %d", len(rows))
	}
	meta := data["meta"].(map[string]any)
	if int(meta["total"].(float64)) != 30 {
		t.Errorf("meta.total: want 30, got %v", meta["total"])
	}
	if int(meta["current_page"].(float64)) != 1 {
		t.Errorf("meta.current_page: want 1, got %v", meta["current_page"])
	}

	// Page 2: same total, different page number, different row IDs.
	url2 := "/api/v1/pos/revenue/by-product?from=2024-01-01&to=2026-12-31&per_page=10&page=2"
	data2, _ := callJSON(t, srv, url2, srv.handleLocalPosRevenueByProduct)
	rows2 := data2["rows"].([]any)
	if len(rows2) != 10 {
		t.Errorf("page=2: want 10 rows, got %d", len(rows2))
	}
	id1 := rows[0].(map[string]any)["id"].(string)
	id2 := rows2[0].(map[string]any)["id"].(string)
	if id1 == id2 {
		t.Errorf("page 1 and page 2 should have different top rows, both got %s", id1)
	}
}

func TestRevenueByProduct_TotalsAreFullWindowNotPage(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")

	// 5 products, page size 2 — total_revenue must sum ALL 5 not just 2.
	totalExpected := 0
	for i := 0; i < 5; i++ {
		price := 1000 * (i + 1)
		totalExpected += price
		mustExec(t, srv.db, `INSERT INTO menu_items (id, name, category, price, is_active) VALUES (?, ?, 'X', ?, 1)`,
			fmt.Sprintf("mi-tw-%d", i), fmt.Sprintf("TW %d", i), price)
		seedClosedOrder(t, srv,
			fmt.Sprintf("ord-tw-%d", i), "branch-A", i+1, price,
			[]orderItemFixture{{
				menuItemID: fmt.Sprintf("mi-tw-%d", i),
				name:       fmt.Sprintf("TW %d", i),
				quantity:   1, unitPrice: price,
			}},
		)
	}

	url := "/api/v1/pos/revenue/by-product?from=2024-01-01&to=2026-12-31&per_page=2&page=1"
	data, _ := callJSON(t, srv, url, srv.handleLocalPosRevenueByProduct)
	totalRevenue := int(data["total_revenue"].(float64))
	if totalRevenue != totalExpected {
		t.Errorf("total_revenue must reflect FULL window (5 products), not just current page (2): want %d, got %d",
			totalExpected, totalRevenue)
	}
}

func TestRevenueByProduct_PhaseBTablesResolveProductName(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")

	// Seed Phase B catalog rows. order_items.product_sku_id should
	// resolve via pos_product_skus → pos_products for the canonical name.
	mustExec(t, srv.db, `INSERT INTO pos_products (id, name) VALUES ('p-pho', 'Phở bò')`)
	mustExec(t, srv.db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price)
		VALUES ('sk-pho-r', 'p-pho', 'Regular', 'PHO-R', 50000)`)
	mustExec(t, srv.db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1', 'Lunch', 'published')`)
	mustExec(t, srv.db, `INSERT INTO pos_menu_sections (id, menu_id, name, sort_order) VALUES ('sec-noodles', 'm1', 'Noodles', 1)`)
	mustExec(t, srv.db, `INSERT INTO pos_menu_products (id, menu_id, product_id, menu_section_id, display_order, is_active)
		VALUES ('mp1', 'm1', 'p-pho', 'sec-noodles', 1, 1)`)

	seedClosedOrder(t, srv, "ord-pb", "branch-A", 1, 50000,
		[]orderItemFixture{{
			menuItemID: "", skuID: "sk-pho-r",
			// Snapshot name on the order_item is intentionally a
			// stale value to prove the handler joins through
			// pos_products for the displayed name.
			name:     "stale snapshot",
			quantity: 1, unitPrice: 50000,
		}},
	)

	url := "/api/v1/pos/revenue/by-product?from=2024-01-01&to=2026-12-31"
	data, _ := callJSON(t, srv, url, srv.handleLocalPosRevenueByProduct)
	rows := data["rows"].([]any)
	if len(rows) == 0 {
		t.Fatal("expected at least 1 row")
	}
	row := rows[0].(map[string]any)
	if name, _ := row["name"].(string); name != "Phở bò" {
		t.Errorf("name should resolve via pos_products: want 'Phở bò', got %v (the catalog-join regression)", row["name"])
	}
	if cat, _ := row["category_name"].(string); cat != "Noodles" {
		t.Errorf("category_name should resolve via pos_menu_sections: want 'Noodles', got %v", row["category_name"])
	}
}

func TestRevenueByProduct_LegacyMenuItemsFallback(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")

	// Order item with no Phase B sku_id — only legacy menu_items.
	// Handler must still surface the row using menu_items as fallback
	// (existing shops with no Phase B sync yet shouldn't see empty
	// revenue reports).
	mustExec(t, srv.db, `INSERT INTO menu_items (id, name, category, price, is_active) VALUES ('mi-legacy', 'Cà phê', 'Drinks', 30000, 1)`)
	seedClosedOrder(t, srv, "ord-legacy", "branch-A", 1, 30000,
		[]orderItemFixture{{
			menuItemID: "mi-legacy",
			name:       "Cà phê",
			quantity:   1, unitPrice: 30000,
		}},
	)

	url := "/api/v1/pos/revenue/by-product?from=2024-01-01&to=2026-12-31"
	data, _ := callJSON(t, srv, url, srv.handleLocalPosRevenueByProduct)
	rows := data["rows"].([]any)
	if len(rows) != 1 {
		t.Fatalf("legacy menu_items fallback: want 1 row, got %d", len(rows))
	}
	row := rows[0].(map[string]any)
	if row["name"] != "Cà phê" {
		t.Errorf("legacy fallback name: %v", row["name"])
	}
	// Legacy category string surfaces both as category_id and category_name.
	if row["category_name"] != "Drinks" {
		t.Errorf("legacy category_name: %v", row["category_name"])
	}
}

func TestRevenueByProduct_CategoryFilterPosSection(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")

	mustExec(t, srv.db, `INSERT INTO pos_menus (id, name, status) VALUES ('m', 'M', 'published')`)
	mustExec(t, srv.db, `INSERT INTO pos_products (id, name) VALUES ('p-1', 'P1'), ('p-2', 'P2')`)
	mustExec(t, srv.db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES
		('s-1', 'p-1', '', 'S1', 100), ('s-2', 'p-2', '', 'S2', 200)`)
	mustExec(t, srv.db, `INSERT INTO pos_menu_sections (id, menu_id, name, sort_order) VALUES
		('sec-A', 'm', 'A', 1), ('sec-B', 'm', 'B', 2)`)
	mustExec(t, srv.db, `INSERT INTO pos_menu_products (id, menu_id, product_id, menu_section_id, display_order, is_active) VALUES
		('mp-1', 'm', 'p-1', 'sec-A', 1, 1),
		('mp-2', 'm', 'p-2', 'sec-B', 1, 1)`)

	seedClosedOrder(t, srv, "ord-c1", "branch-A", 1, 100,
		[]orderItemFixture{{skuID: "s-1", name: "P1", quantity: 1, unitPrice: 100}})
	seedClosedOrder(t, srv, "ord-c2", "branch-A", 1, 200,
		[]orderItemFixture{{skuID: "s-2", name: "P2", quantity: 1, unitPrice: 200}})

	// Filter to section A only → 1 row (P1).
	url := "/api/v1/pos/revenue/by-product?from=2024-01-01&to=2026-12-31&category_id=sec-A"
	data, _ := callJSON(t, srv, url, srv.handleLocalPosRevenueByProduct)
	rows := data["rows"].([]any)
	if len(rows) != 1 {
		t.Errorf("section filter: want 1 row, got %d", len(rows))
	}
	if rows[0].(map[string]any)["name"] != "P1" {
		t.Errorf("section filter wrong product: %v", rows[0])
	}
}

func TestRevenueByProduct_AvailableCategoriesFromPosSections(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	mustExec(t, srv.db, `INSERT INTO pos_menus (id, name, status) VALUES ('m', 'M', 'published')`)
	mustExec(t, srv.db, `INSERT INTO pos_menu_sections (id, menu_id, name, sort_order, is_active) VALUES
		('s1', 'm', 'Drinks', 1, 1),
		('s2', 'm', 'Mains',  2, 1),
		('s3', 'm', 'Hidden', 3, 0)`)

	url := "/api/v1/pos/revenue/by-product?from=2024-01-01&to=2026-12-31"
	data, _ := callJSON(t, srv, url, srv.handleLocalPosRevenueByProduct)
	cats := data["available_categories"].([]any)
	gotNames := make([]string, 0, len(cats))
	for _, c := range cats {
		gotNames = append(gotNames, c.(map[string]any)["name"].(string))
	}
	if len(gotNames) != 2 || gotNames[0] != "Drinks" || gotNames[1] != "Mains" {
		t.Errorf("available_categories: want [Drinks Mains] from active sections only, got %v", gotNames)
	}
}
