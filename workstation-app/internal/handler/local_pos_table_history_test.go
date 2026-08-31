package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// seedTableOrder inserts a dine-in order bound to the paired branch (branch-A)
// with a primary table_id. status lets the test cover history (closed/voided).
func seedTableOrder(t *testing.T, db execer, id, status, tableID string) {
	t.Helper()
	now := time.Now().UTC().Format(time.RFC3339)
	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, total_amount, paid_amount, table_id,
		    organization_id, brand_id, branch_id, created_at, updated_at)
		VALUES (?, ?, 'dine_in', ?, ?, 1000, 1000, 1000, ?, 'org','brand','branch-A', ?, ?)`,
		id, "T-"+id, status, now, nullIfEmpty(tableID), now, now)
}

// The per-table history returns EVERY order bound to the table — via the primary
// orders.table_id AND via the order_tables pivot (merged tables) — across all
// statuses, and excludes orders on other tables. Both links survive close/void,
// unlike Cloud's live-only tables.current_order_id.
func TestHandleLocalPosOrders_FilterByTable(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused") // paired to branch-A
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db, 10)

	// T1: one closed (primary link) + one voided (merged via pivot). T2: one order.
	seedTableOrder(t, db, "t1-closed", "closed", "table-1")
	seedTableOrder(t, db, "t1-merged", "voided", "table-OTHER")                                     // primary is a different table…
	mustExec(t, db, `INSERT INTO order_tables (order_id, table_id) VALUES ('t1-merged','table-1')`) // …but merged onto table-1
	seedTableOrder(t, db, "t2-open", "open", "table-2")

	req := httptest.NewRequest("GET",
		"/api/v1/pos/orders?table_id=table-1&status=open,closed,voided&per_page=100", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosOrders(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()
	for _, want := range []string{`"id":"t1-closed"`, `"id":"t1-merged"`} {
		if !strings.Contains(body, want) {
			t.Errorf("table history should include %s\n%s", want, body)
		}
	}
	if strings.Contains(body, `"id":"t2-open"`) {
		t.Errorf("order on another table must NOT appear: %s", body)
	}
}

// The order shape exposes the coupon's discount + applied-at, so the history
// detail can render "SAVE10 · giảm ¥500 · lúc …" not just the code.
func TestCustomerOrderShape_CouponDetail(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO orders (id, order_number, status) VALUES ('o-cp', 1, 'closed')`)
	mustExec(t, db, `INSERT INTO coupons (id, code, name, discount_type, discount_value, min_order_subtotal,
		times_used, status, stacking_mode, exclusive_with_promotions, applies_to, local_synced_at)
		VALUES ('cp1','SAVE10','Save 10','fixed',500,0,0,'active','exclusive',0,'order', datetime('now'))`)
	mustExec(t, db, `INSERT INTO order_coupons (id, order_id, coupon_id, coupon_code, discount_applied, applied_at)
		VALUES ('oc1','o-cp','cp1','SAVE10',500,'2026-07-20T10:00:00Z')`)

	shape := srv.customerOrderShape(&service.Order{ID: "o-cp"}, "en")
	if shape["coupon_code_snapshot"] != "SAVE10" {
		t.Errorf("coupon_code_snapshot: got %v", shape["coupon_code_snapshot"])
	}
	if shape["coupon_discount"] != 500 {
		t.Errorf("coupon_discount: want 500, got %v", shape["coupon_discount"])
	}
	if shape["coupon_applied_at"] != "2026-07-20T10:00:00Z" {
		t.Errorf("coupon_applied_at: got %v", shape["coupon_applied_at"])
	}
	// The coupons JOIN adds the coupon's own terms.
	if shape["coupon_name"] != "Save 10" {
		t.Errorf("coupon_name: want 'Save 10', got %v", shape["coupon_name"])
	}
	if shape["coupon_discount_type"] != "fixed" {
		t.Errorf("coupon_discount_type: want fixed, got %v", shape["coupon_discount_type"])
	}
	if shape["coupon_discount_value"] != 500 {
		t.Errorf("coupon_discount_value: want 500, got %v", shape["coupon_discount_value"])
	}
}

// A percent coupon carries its cap so the UI can show "10% (up to ¥50,000)".
func TestCustomerOrderShape_PercentCouponWithCap(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO orders (id, order_number, status) VALUES ('o-pc', 1, 'closed')`)
	mustExec(t, db, `INSERT INTO coupons (id, code, name, discount_type, discount_value, min_order_subtotal,
		max_discount_cap, times_used, status, stacking_mode, exclusive_with_promotions, applies_to, local_synced_at)
		VALUES ('cp2','WELCOME10','Welcome 10% off','percent',10,100000,50000,0,'active','exclusive',0,'order', datetime('now'))`)
	mustExec(t, db, `INSERT INTO order_coupons (id, order_id, coupon_id, coupon_code, discount_applied, applied_at)
		VALUES ('oc2','o-pc','cp2','WELCOME10',5000,'2026-07-20T11:00:00Z')`)

	shape := srv.customerOrderShape(&service.Order{ID: "o-pc"}, "en")
	if shape["coupon_discount_type"] != "percent" {
		t.Errorf("coupon_discount_type: want percent, got %v", shape["coupon_discount_type"])
	}
	if shape["coupon_discount_value"] != 10 {
		t.Errorf("coupon_discount_value: want 10, got %v", shape["coupon_discount_value"])
	}
	if shape["coupon_max_discount_cap"] == nil {
		t.Errorf("coupon_max_discount_cap should be set for a capped percent coupon")
	}
}

// The order shape's payments[] now carries the resolved method name + tendered /
// change / paid_at, so the history detail can render "Cash ¥2,000" not "cash".
func TestLoadOrderPayments_EnrichedForHistory(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES ('pm-cash','cash','Cash')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status) VALUES ('o-pay', 1, 'closed')`)
	mustExec(t, db, `INSERT INTO payments (id, order_id, payment_method, payment_method_id, amount, status, tendered_amount, change_amount, paid_at, idempotency_key, created_at)
		VALUES ('pay1','o-pay','cash','pm-cash',2000,'confirmed',3000,1000,'2026-07-20T10:00:00Z','k1','2026-07-20T10:00:00Z')`)

	payments := srv.loadOrderPayments("o-pay", "en")
	if len(payments) != 1 {
		t.Fatalf("want 1 payment, got %d", len(payments))
	}
	p := payments[0]
	if p["payment_method_name"] != "Cash" {
		t.Errorf("payment_method_name: want Cash, got %v", p["payment_method_name"])
	}
	if p["tendered_amount"] != int64(3000) {
		t.Errorf("tendered_amount: want 3000, got %v (%T)", p["tendered_amount"], p["tendered_amount"])
	}
	if p["change_amount"] != int64(1000) {
		t.Errorf("change_amount: want 1000, got %v", p["change_amount"])
	}
	if p["paid_at"] != "2026-07-20T10:00:00Z" {
		t.Errorf("paid_at: want the stamp, got %v", p["paid_at"])
	}
}
