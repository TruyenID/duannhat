package handler

import (
	"fmt"
	"testing"
	"time"
)

// The void report splits cancellations into two NON-overlapping lenses:
//   - order voids  : wholly-voided orders; value = their items' subtotals
//     (total_amount is zeroed on void).
//   - item voids   : per-item voids where the parent order is NOT voided (so an
//     item inside a voided order is counted under order voids, never twice).
func TestRevenueVoids_OrderAndItemLenses(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused") // paired to branch-A
	now := time.Now().UTC().Format(time.RFC3339)

	orderCols := `INSERT INTO orders (id, order_code, order_type, status, opened_at,
		subtotal, discount_amount, total_amount, paid_amount, branch_id, guest_count,
		void_reason, voided_at, created_at, updated_at)`
	itemCols := `INSERT INTO order_items (id, customer_order_id, menu_item_id, menu_item_name,
		product_sku_id, quantity, unit_price, subtotal, printer_group, status, print_status,
		void_reason, voided_at, created_at, updated_at)`

	// A wholly-voided order (reason manager_void). total_amount zeroed. Two
	// items worth 2000 + 1000 = 3000 → that's the order's lost value. Its
	// items must NOT count as per-item voids.
	mustExec(t, srv.db, orderCols+` VALUES ('vo1','O-vo1','dine_in','voided',?,0,0,0,0,'branch-A',2,'manager_void',?,?,?)`,
		now, now, now, now)
	mustExec(t, srv.db, itemCols+` VALUES ('voi1','vo1','mi1','Pho','sku-pho',1,2000,2000,'kitchen','voided','printed','manager_void',?,?,?)`,
		now, now, now)
	mustExec(t, srv.db, itemCols+` VALUES ('voi2','vo1','mi2','Coffee','sku-cof',1,1000,1000,'kitchen','served','printed',NULL,NULL,?,?)`,
		now, now)

	// A CLOSED order with ONE per-item void (reason wrong_item, value 1200).
	mustExec(t, srv.db, orderCols+` VALUES ('o1','O-o1','dine_in','closed',?,5000,0,5000,5000,'branch-A',2,NULL,NULL,?,?)`,
		now, now, now)
	mustExec(t, srv.db, itemCols+` VALUES ('ivo1','o1','mi3','Spring Rolls','sku-sr',2,600,1200,'kitchen','voided','printed','wrong_item',?,?,?)`,
		now, now, now)

	fromD := time.Now().UTC().AddDate(0, 0, -1).Format("2006-01-02")
	toD := time.Now().UTC().AddDate(0, 0, 1).Format("2006-01-02")
	url := fmt.Sprintf("/api/v1/pos/revenue/voids?granularity=day&from=%s&to=%s", fromD, toD)
	data, status := callJSON(t, srv, url, srv.handleLocalPosRevenueVoids)
	if status != 200 {
		t.Fatalf("status %d", status)
	}

	kpis := data["kpis"].(map[string]any)
	assertNum := func(key string, want float64) {
		if got, _ := kpis[key].(float64); got != want {
			t.Errorf("kpis.%s: want %v, got %v", key, want, kpis[key])
		}
	}
	assertNum("order_voids", 1)
	assertNum("order_void_value", 3000) // both items of the voided order
	assertNum("item_voids", 1)          // only the per-item void in the closed order
	assertNum("item_void_value", 1200)
	assertNum("order_void_rate_pct", 50) // 1 voided / (1 voided + 1 closed)

	// Reasons — order lens has manager_void, item lens has wrong_item.
	orderReasons := data["order_reasons"].([]any)
	if len(orderReasons) == 0 || orderReasons[0].(map[string]any)["reason"] != "manager_void" {
		t.Errorf("order_reasons: %v", orderReasons)
	}
	itemReasons := data["item_reasons"].([]any)
	if len(itemReasons) == 0 || itemReasons[0].(map[string]any)["reason"] != "wrong_item" {
		t.Errorf("item_reasons: %v", itemReasons)
	}

	// Top voided items — the per-item void surfaces "Spring Rolls".
	topItems := data["top_items"].([]any)
	if len(topItems) == 0 || topItems[0].(map[string]any)["name"] != "Spring Rolls" {
		t.Errorf("top_items: %v", topItems)
	}

	// Series backfills the full window and carries the counts.
	series := data["series"].([]any)
	if len(series) < 3 {
		t.Errorf("series should span the 3-day window, got %d", len(series))
	}
	var totalOrderVoids, totalItemVoids float64
	for _, p := range series {
		m := p.(map[string]any)
		totalOrderVoids += m["order_voids"].(float64)
		totalItemVoids += m["item_voids"].(float64)
	}
	if totalOrderVoids != 1 || totalItemVoids != 1 {
		t.Errorf("series totals: order=%v item=%v", totalOrderVoids, totalItemVoids)
	}
}

// The void-event log is a flat, paginated, newest-first list unioning the same
// two lenses: one row per wholly-voided order (value = its item subtotals,
// item_count = its line count) + one row per per-item void on a live order.
func TestRevenueVoidEvents_ListPagingAndFilter(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused") // paired to branch-A
	base := time.Now().UTC()
	earlier := base.Add(-2 * time.Hour).Format(time.RFC3339)
	later := base.Add(-1 * time.Hour).Format(time.RFC3339)
	created := base.Add(-3 * time.Hour).Format(time.RFC3339)

	orderCols := `INSERT INTO orders (id, order_code, order_type, status, opened_at,
		subtotal, discount_amount, total_amount, paid_amount, branch_id, guest_count,
		void_reason, voided_at, created_at, updated_at)`
	itemCols := `INSERT INTO order_items (id, customer_order_id, menu_item_id, menu_item_name,
		sku_variant_name, product_sku_id, quantity, unit_price, subtotal, printer_group, status,
		print_status, void_reason, voided_at, created_at, updated_at)`

	// Wholly-voided order (earlier). 2 items = 3000 lost value.
	mustExec(t, srv.db, orderCols+` VALUES ('vo1','ORD-2026-0001','dine_in','voided',?,0,0,0,0,'branch-A',2,'manager_void',?,?,?)`,
		created, earlier, created, created)
	mustExec(t, srv.db, itemCols+` VALUES ('voi1','vo1','mi1','Pho','','sku-pho',1,2000,2000,'kitchen','voided','printed','manager_void',?,?,?)`,
		earlier, created, created)
	mustExec(t, srv.db, itemCols+` VALUES ('voi2','vo1','mi2','Coffee','','sku-cof',1,1000,1000,'kitchen','served','printed',NULL,NULL,?,?)`,
		created, created)

	// Closed order with a per-item void (later → sorts first). qty 2, value 1200.
	mustExec(t, srv.db, orderCols+` VALUES ('o1','ORD-2026-0002','dine_in','closed',?,5000,0,5000,5000,'branch-A',2,NULL,NULL,?,?)`,
		created, created, created)
	mustExec(t, srv.db, itemCols+` VALUES ('ivo1','o1','mi3','Spring Rolls','2pc','sku-sr',2,600,1200,'kitchen','voided','printed','wrong_item',?,?,?)`,
		later, created, created)

	fromD := base.AddDate(0, 0, -1).Format("2006-01-02")
	toD := base.AddDate(0, 0, 1).Format("2006-01-02")

	// Full list, newest first.
	url := fmt.Sprintf("/api/v1/pos/revenue/void-events?granularity=day&from=%s&to=%s", fromD, toD)
	data, status := callJSON(t, srv, url, srv.handleLocalPosRevenueVoidEvents)
	if status != 200 {
		t.Fatalf("status %d", status)
	}
	if total, _ := data["total"].(float64); total != 2 {
		t.Errorf("total: want 2, got %v", data["total"])
	}
	rows := data["rows"].([]any)
	if len(rows) != 2 {
		t.Fatalf("rows: want 2, got %d", len(rows))
	}
	r0 := rows[0].(map[string]any)
	if r0["kind"] != "item" || r0["order_code"] != "ORD-2026-0002" || r0["reason"] != "wrong_item" {
		t.Errorf("row0 (newest, item void): %v", r0)
	}
	if v, _ := r0["value"].(float64); v != 1200 {
		t.Errorf("row0 value: want 1200, got %v", r0["value"])
	}
	if q, _ := r0["quantity"].(float64); q != 2 {
		t.Errorf("row0 quantity: want 2, got %v", r0["quantity"])
	}
	if r0["item_name"] != "Spring Rolls" || r0["variant"] != "2pc" {
		t.Errorf("row0 name/variant: %v", r0)
	}
	r1 := rows[1].(map[string]any)
	if r1["kind"] != "order" || r1["order_code"] != "ORD-2026-0001" || r1["reason"] != "manager_void" {
		t.Errorf("row1 (older, order void): %v", r1)
	}
	if v, _ := r1["value"].(float64); v != 3000 {
		t.Errorf("row1 value: want 3000, got %v", r1["value"])
	}
	if c, _ := r1["item_count"].(float64); c != 2 {
		t.Errorf("row1 item_count: want 2, got %v", r1["item_count"])
	}

	// type=order narrows to the whole-order void only.
	urlOrder := url + "&type=order"
	dataO, _ := callJSON(t, srv, urlOrder, srv.handleLocalPosRevenueVoidEvents)
	if total, _ := dataO["total"].(float64); total != 1 {
		t.Errorf("type=order total: want 1, got %v", dataO["total"])
	}
	if r := dataO["rows"].([]any); len(r) != 1 || r[0].(map[string]any)["kind"] != "order" {
		t.Errorf("type=order rows: %v", dataO["rows"])
	}

	// Pagination: per_page=1&page=2 → the older order void.
	urlPage := url + "&per_page=1&page=2"
	dataP, _ := callJSON(t, srv, urlPage, srv.handleLocalPosRevenueVoidEvents)
	if total, _ := dataP["total"].(float64); total != 2 {
		t.Errorf("paged total: want 2, got %v", dataP["total"])
	}
	rp := dataP["rows"].([]any)
	if len(rp) != 1 || rp[0].(map[string]any)["kind"] != "order" {
		t.Errorf("page 2 rows: %v", dataP["rows"])
	}
}
