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
	srv.orders = service.NewOrderEngine(db)

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

// Online Stripe / PayPay / customer-web payments live only in
// cloud_payment_summary (#1282 — never a payments row). History must still
// list them or the UI shows "Chưa có thanh toán" over a paid closed order.
func TestCustomerOrderShape_MergesCloudPaymentSummary(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES
		('pm-stripe','stripe_card','Stripe'),
		('pm-paypay','paypay','PayPay')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
		VALUES ('o-online', 18, 'closed', 297, ?)`,
		`[{"id":"pay-cloud-1","payment_method_id":"pm-stripe","payment_method_code":"stripe_card","payment_method_name":"Stripe","amount":297,"status":"succeeded","paid_at":"2026-08-10T10:00:00Z"}]`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
		VALUES ('o-paypay', 19, 'closed', 1500, ?)`,
		`[{"id":"pay-pp-1","payment_method_id":"pm-paypay","payment_method_code":"paypay","payment_method_name":"PayPay","amount":1500,"status":"succeeded","paid_at":"2026-08-10T12:00:00Z"}]`)

	for _, tc := range []struct {
		orderID, payID, method, name string
		amount                       int
	}{
		{"o-online", "pay-cloud-1", "stripe_card", "Stripe", 297},
		{"o-paypay", "pay-pp-1", "paypay", "PayPay", 1500},
	} {
		shape := srv.customerOrderShape(&service.Order{ID: tc.orderID}, "en")
		payments, _ := shape["payments"].([]map[string]any)
		if len(payments) != 1 {
			t.Fatalf("%s: want 1 merged payment, got %d (%v)", tc.orderID, len(payments), shape["payments"])
		}
		p := payments[0]
		if p["id"] != tc.payID {
			t.Errorf("%s id: got %v", tc.orderID, p["id"])
		}
		if p["amount"] != tc.amount {
			t.Errorf("%s amount: want %d, got %v", tc.orderID, tc.amount, p["amount"])
		}
		if p["status"] != "succeeded" {
			t.Errorf("%s status: want succeeded, got %v", tc.orderID, p["status"])
		}
		if p["payment_method"] != tc.method {
			t.Errorf("%s payment_method: want %s, got %v", tc.orderID, tc.method, p["payment_method"])
		}
		if p["payment_method_name"] != tc.name {
			t.Errorf("%s payment_method_name: want %s, got %v", tc.orderID, tc.name, p["payment_method_name"])
		}
		if p["capture_source"] != "cloud_payment_summary" {
			t.Errorf("%s capture_source: got %v", tc.orderID, p["capture_source"])
		}
		var n int
		if err := db.QueryRow(`SELECT COUNT(*) FROM payments WHERE order_id = ?`, tc.orderID).Scan(&n); err != nil {
			t.Fatal(err)
		}
		if n != 0 {
			t.Errorf("%s: payments table must stay empty, got %d rows", tc.orderID, n)
		}
	}
}

// Pending / failed online payments stay out of history payments[].
func TestCustomerOrderShape_SkipsUnsettledCloudPaymentSummary(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
		VALUES ('o-pend', 1, 'open', 0, ?)`,
		`[{"id":"pay-pend","payment_method_code":"stripe_card","amount":100,"status":"pending"}]`)

	shape := srv.customerOrderShape(&service.Order{ID: "o-pend"}, "en")
	payments, _ := shape["payments"].([]map[string]any)
	if len(payments) != 0 {
		t.Fatalf("want 0 payments for pending cloud summary, got %d", len(payments))
	}
}

// Local till payment + Cloud online summary must both appear (split settle).
func TestCustomerOrderShape_LocalPlusCloudPayments(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES
		('pm-cash','cash','Cash'), ('pm-stripe','stripe_card','Stripe')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
		VALUES ('o-split', 1, 'closed', 500, ?)`,
		`[{"id":"pay-stripe","payment_method_id":"pm-stripe","payment_method_code":"stripe_card","payment_method_name":"Stripe","amount":300,"status":"succeeded","paid_at":"2026-08-10T11:00:00Z"}]`)
	mustExec(t, db, `INSERT INTO payments (id, order_id, payment_method, payment_method_id, amount, status, paid_at, idempotency_key, created_at)
		VALUES ('pay-cash','o-split','cash','pm-cash',200,'confirmed','2026-08-10T10:55:00Z','k-split','2026-08-10T10:55:00Z')`)

	shape := srv.customerOrderShape(&service.Order{ID: "o-split"}, "en")
	payments, _ := shape["payments"].([]map[string]any)
	if len(payments) != 2 {
		t.Fatalf("want cash + stripe, got %d (%v)", len(payments), payments)
	}
	byID := map[string]map[string]any{}
	for _, p := range payments {
		byID[p["id"].(string)] = p
	}
	if byID["pay-cash"]["amount"] != 200 {
		t.Errorf("cash amount: got %v", byID["pay-cash"]["amount"])
	}
	if byID["pay-cash"]["capture_source"] != nil {
		t.Errorf("local cash must not carry capture_source, got %v", byID["pay-cash"]["capture_source"])
	}
	stripe := byID["pay-stripe"]
	if stripe == nil {
		t.Fatal("missing cloud stripe payment in shape")
	}
	if stripe["amount"] != 300 || stripe["paid_at"] != "2026-08-10T11:00:00Z" {
		t.Errorf("stripe fields: amount=%v paid_at=%v", stripe["amount"], stripe["paid_at"])
	}
	if stripe["capture_source"] != "cloud_payment_summary" {
		t.Errorf("stripe capture_source: got %v", stripe["capture_source"])
	}
}

// Same Cloud payment id already mirrored locally must not double-list.
func TestCustomerOrderShape_DedupesCloudPaymentAgainstLocal(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES ('pm-stripe','stripe_card','Stripe')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
		VALUES ('o-dup', 1, 'closed', 297, ?)`,
		`[{"id":"pay-same","payment_method_id":"pm-stripe","payment_method_code":"stripe_card","payment_method_name":"Stripe","amount":297,"status":"succeeded"}]`)
	mustExec(t, db, `INSERT INTO payments (id, order_id, payment_method, payment_method_id, amount, status, idempotency_key, created_at)
		VALUES ('pay-same','o-dup','stripe_card','pm-stripe',297,'succeeded','k-dup','2026-08-10T10:00:00Z')`)

	shape := srv.customerOrderShape(&service.Order{ID: "o-dup"}, "en")
	payments, _ := shape["payments"].([]map[string]any)
	if len(payments) != 1 {
		t.Fatalf("want 1 deduped payment, got %d", len(payments))
	}
	if payments[0]["id"] != "pay-same" {
		t.Errorf("id: got %v", payments[0]["id"])
	}
	if payments[0]["capture_source"] != nil {
		t.Errorf("deduped local row must win without cloud marker, got %v", payments[0]["capture_source"])
	}
}

// Summary often lands on the Cloud-keyed sibling while POS reads the local
// WS row (linked via orders.cloud_id). History must still merge.
func TestCustomerOrderShape_MergesSummaryFromLinkedSibling(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES ('pm-paypay','paypay','PayPay')`)
	// Local WS row the POS opens — no summary blob.
	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_id)
		VALUES ('ws-local', 1, 'closed', 1500, 'cloud-ord-1')`)
	// Pulled sibling carries the online PayPay summary.
	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
		VALUES ('cloud-ord-1', 1, 'closed', 1500, ?)`,
		`[{"id":"pay-pp","payment_method_code":"paypay","payment_method_name":"PayPay","amount":1500,"status":"succeeded","paid_at":"2026-08-10T09:00:00Z"}]`)

	shape := srv.customerOrderShape(&service.Order{ID: "ws-local"}, "en")
	payments, _ := shape["payments"].([]map[string]any)
	if len(payments) != 1 {
		t.Fatalf("want sibling summary merged onto local shape, got %d (%v)", len(payments), payments)
	}
	if payments[0]["id"] != "pay-pp" || payments[0]["amount"] != 1500 {
		t.Errorf("got %+v", payments[0])
	}
}

// Local payments.id is a WS UUID while cloud_payment_summary.id is the Cloud
// payment id — dedupe must also match payments.cloud_id.
func TestCustomerOrderShape_DedupesByLocalCloudID(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES ('pm-paypay','paypay','PayPay')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
		VALUES ('o-cid', 1, 'closed', 880, ?)`,
		`[{"id":"cloud-pay-9","payment_method_code":"paypay","payment_method_name":"PayPay","amount":880,"status":"succeeded"}]`)
	mustExec(t, db, `INSERT INTO payments (id, order_id, cloud_id, payment_method, payment_method_id, amount, status, idempotency_key, created_at)
		VALUES ('local-uuid','o-cid','cloud-pay-9','paypay','pm-paypay',880,'succeeded','k-cid','2026-08-10T10:00:00Z')`)

	shape := srv.customerOrderShape(&service.Order{ID: "o-cid"}, "en")
	payments, _ := shape["payments"].([]map[string]any)
	if len(payments) != 1 {
		t.Fatalf("want 1 deduped by cloud_id, got %d (%v)", len(payments), payments)
	}
	if payments[0]["id"] != "local-uuid" {
		t.Errorf("local row must win: got %v", payments[0]["id"])
	}
}

// Refunded online payments stay visible in history; failed still stay out.
func TestCustomerOrderShape_ShowsRefundedHidesFailedCloudPayment(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
		VALUES ('o-ref', 1, 'closed', 0, ?)`,
		`[{"id":"pay-ref","payment_method_code":"paypay","payment_method_name":"PayPay","amount":297,"status":"refunded"},
		 {"id":"pay-fail","payment_method_code":"stripe_card","payment_method_name":"Stripe","amount":297,"status":"failed"}]`)

	shape := srv.customerOrderShape(&service.Order{ID: "o-ref"}, "en")
	payments, _ := shape["payments"].([]map[string]any)
	if len(payments) != 1 {
		t.Fatalf("want only refunded row, got %d (%v)", len(payments), payments)
	}
	if payments[0]["id"] != "pay-ref" || payments[0]["status"] != "refunded" {
		t.Errorf("got %+v", payments[0])
	}
	if payments[0]["refunded_amount"] != 297 {
		t.Errorf("refunded_amount: want 297, got %v", payments[0]["refunded_amount"])
	}
}

// Edge matrix for online-payment history merge: statuses, empty blob, name
// resolution, string amounts, multi-entry summary, PayPay paths.
func TestCustomerOrderShape_CloudPaymentSummaryEdgeMatrix(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES
		('pm-stripe','stripe_card','Stripe Card'),
		('pm-paypay','paypay','PayPay')`)

	type wantPay struct {
		id, method, name, status string
		amount                   int
		paidAt                   string
		refunded                 int
	}
	cases := []struct {
		name    string
		orderID string
		blob    string
		want    []wantPay
	}{
		{
			name:    "empty summary array",
			orderID: "e-empty",
			blob:    `[]`,
			want:    nil,
		},
		{
			name:    "confirmed status counts as settled",
			orderID: "e-confirmed",
			blob:    `[{"id":"c1","payment_method_code":"stripe_card","payment_method_name":"Stripe JP","amount":100,"status":"confirmed","paid_at":"2026-08-10T01:00:00Z"}]`,
			// Local catalog wins over Cloud name for locale.
			want: []wantPay{{id: "c1", method: "stripe_card", name: "Stripe Card", status: "confirmed", amount: 100, paidAt: "2026-08-10T01:00:00Z"}},
		},
		{
			name:    "name falls back to local payment_methods catalog",
			orderID: "e-fallback",
			blob:    `[{"id":"c2","payment_method_id":"pm-paypay","payment_method_code":"paypay","payment_method_name":"","amount":880,"status":"succeeded"}]`,
			want:    []wantPay{{id: "c2", method: "paypay", name: "PayPay", status: "succeeded", amount: 880}},
		},
		{
			name:    "built-in code label when catalog missing",
			orderID: "e-label",
			blob:    `[{"id":"c2b","payment_method_code":"linepay","payment_method_name":"","amount":100,"status":"succeeded"}]`,
			want:    []wantPay{{id: "c2b", method: "linepay", name: "LINE Pay", status: "succeeded", amount: 100}},
		},
		{
			name:    "string decimal amount decodes",
			orderID: "e-str-amt",
			blob:    `[{"id":"c-str","payment_method_code":"stripe_card","payment_method_name":"Stripe","amount":"297.00","status":"succeeded"}]`,
			want:    []wantPay{{id: "c-str", method: "stripe_card", name: "Stripe Card", status: "succeeded", amount: 297}},
		},
		{
			name:    "zero amount skipped",
			orderID: "e-zero",
			blob:    `[{"id":"c-zero","payment_method_code":"paypay","payment_method_name":"PayPay","amount":0,"status":"succeeded"}]`,
			want:    nil,
		},
		{
			name:    "paypay pending skipped",
			orderID: "e-pp-pend",
			blob:    `[{"id":"c3","payment_method_code":"paypay","payment_method_name":"PayPay","amount":500,"status":"pending"}]`,
			want:    nil,
		},
		{
			name:    "paypay refunded visible",
			orderID: "e-pp-ref",
			blob:    `[{"id":"c4","payment_method_code":"paypay","payment_method_name":"PayPay","amount":500,"status":"refunded"}]`,
			want:    []wantPay{{id: "c4", method: "paypay", name: "PayPay", status: "refunded", amount: 500, refunded: 500}},
		},
		{
			name:    "mixed settled unsettled refunded",
			orderID: "e-mix",
			blob: `[
				{"id":"ok-pp","payment_method_code":"paypay","payment_method_name":"PayPay","amount":700,"status":"succeeded","paid_at":"2026-08-10T02:00:00Z"},
				{"id":"bad","payment_method_code":"stripe_card","payment_method_name":"Stripe","amount":100,"status":"pending"},
				{"id":"ok-st","payment_method_code":"stripe_card","payment_method_name":"Stripe","amount":200,"status":"paid"},
				{"id":"ref","payment_method_code":"paypay","payment_method_name":"PayPay","amount":50,"status":"refunded"}
			]`,
			want: []wantPay{
				{id: "ok-pp", method: "paypay", name: "PayPay", status: "succeeded", amount: 700, paidAt: "2026-08-10T02:00:00Z"},
				{id: "ok-st", method: "stripe_card", name: "Stripe Card", status: "paid", amount: 200},
				{id: "ref", method: "paypay", name: "PayPay", status: "refunded", amount: 50, refunded: 50},
			},
		},
		{
			name:    "entry without id is skipped",
			orderID: "e-noid",
			blob:    `[{"payment_method_code":"paypay","payment_method_name":"PayPay","amount":100,"status":"succeeded"}]`,
			want:    nil,
		},
		{
			name:    "string amount beside int does not kill whole blob",
			orderID: "e-mixed-amt",
			blob: `[
				{"id":"a","payment_method_code":"paypay","payment_method_name":"PayPay","amount":"1500.00","status":"succeeded"},
				{"id":"b","payment_method_code":"stripe_card","payment_method_name":"Stripe","amount":297,"status":"succeeded"}
			]`,
			want: []wantPay{
				{id: "a", method: "paypay", name: "PayPay", status: "succeeded", amount: 1500},
				{id: "b", method: "stripe_card", name: "Stripe Card", status: "succeeded", amount: 297},
			},
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
				VALUES (?, 1, 'closed', 0, ?)`, tc.orderID, tc.blob)

			shape := srv.customerOrderShape(&service.Order{ID: tc.orderID}, "en")
			payments, _ := shape["payments"].([]map[string]any)
			if len(payments) != len(tc.want) {
				t.Fatalf("payments len: want %d, got %d (%v)", len(tc.want), len(payments), payments)
			}
			for i, w := range tc.want {
				p := payments[i]
				if p["id"] != w.id || p["payment_method"] != w.method || p["payment_method_name"] != w.name {
					t.Errorf("[%d] identity: got id=%v method=%v name=%v", i, p["id"], p["payment_method"], p["payment_method_name"])
				}
				if p["status"] != w.status {
					t.Errorf("[%d] status: want %s, got %v", i, w.status, p["status"])
				}
				if p["amount"] != w.amount {
					t.Errorf("[%d] amount: want %d, got %v", i, w.amount, p["amount"])
				}
				if w.paidAt != "" && p["paid_at"] != w.paidAt {
					t.Errorf("[%d] paid_at: want %s, got %v", i, w.paidAt, p["paid_at"])
				}
				if w.refunded > 0 && p["refunded_amount"] != w.refunded {
					t.Errorf("[%d] refunded_amount: want %d, got %v", i, w.refunded, p["refunded_amount"])
				}
				if p["capture_source"] != "cloud_payment_summary" {
					t.Errorf("[%d] capture_source missing", i)
				}
				if p["customer_order_id"] != tc.orderID {
					t.Errorf("[%d] customer_order_id: got %v", i, p["customer_order_id"])
				}
			}
			var n int
			_ = db.QueryRow(`SELECT COUNT(*) FROM payments WHERE order_id = ?`, tc.orderID).Scan(&n)
			if n != 0 {
				t.Errorf("payments table must stay empty, got %d", n)
			}
		})
	}
}
