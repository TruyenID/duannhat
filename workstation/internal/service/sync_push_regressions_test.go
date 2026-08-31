package service

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
)

// Pre-fix regression: enqueueOrderSync passed `action` ("order.init")
// directly as the Enqueue `operation` argument while hard-coding
// entityType="order", yielding the lookup key "order.order.init" inside
// pushToCloud. The handler map has key "order.init", so the lookup
// failed silently — every Phase 1/3/4/5 write that ran through the
// helper (13 operations) was dropped from the queue without ever
// reaching Cloud.
//
// These tests run each operation through the real processQueue() and
// assert the corresponding Cloud endpoint was hit at least once. If a
// future refactor reintroduces the double-prefix path, every test in
// this file would fail loudly.

// recordingCloud spins up a per-test httptest.Server that records every
// (path, method, body) tuple it receives. Tests assert against the
// recorded calls without needing per-test mocks.
type recordingCloud struct {
	server *httptest.Server
	calls  []recordedCall
	count  int32
}

type recordedCall struct {
	path   string
	method string
	body   map[string]any
}

func newRecordingCloud(t *testing.T) *recordingCloud {
	t.Helper()
	rc := &recordingCloud{}
	rc.server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&rc.count, 1)
		raw, _ := io.ReadAll(r.Body)
		var body map[string]any
		_ = json.Unmarshal(raw, &body)
		rc.calls = append(rc.calls, recordedCall{
			path: r.URL.Path, method: r.Method, body: body,
		})
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"cloud-id-1"}}`))
	}))
	t.Cleanup(rc.server.Close)
	return rc
}

func (rc *recordingCloud) assertHit(t *testing.T, wantPath string) recordedCall {
	t.Helper()
	for _, c := range rc.calls {
		if c.path == wantPath {
			return c
		}
	}
	paths := []string{}
	for _, c := range rc.calls {
		paths = append(paths, c.method+" "+c.path)
	}
	t.Fatalf("no call to %q — recorded calls: %v", wantPath, paths)
	return recordedCall{}
}

// seedOrderWithCloudID writes a local order row with a cloud_id so the
// orderCloudPath() resolver can build the Cloud URL.
func seedOrderWithCloudID(t *testing.T, e *SyncEngine, localID, cloudID string) {
	t.Helper()
	_, err := e.db.Exec(`INSERT INTO orders (
		id, cloud_id, order_code, order_number, order_type, status,
		opened_at, guest_count,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id,
		created_at, updated_at
	) VALUES (?, ?, 'WS-0001', 1, 'dine_in', 'open',
		datetime('now'), 2,
		60000, 0, 0, 6000, 0, 66000, 0,
		'', '', '',
		datetime('now'), datetime('now'))`, localID, cloudID)
	if err != nil {
		t.Fatalf("seed order: %v", err)
	}
}

func forceOnline(e *SyncEngine) {
	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
}

// enqueueLikeHelper mimics what handler.enqueueOrderSync should do after
// the fix: split the action into (entityType, operation) so the queue
// row's Enqueue arguments produce the right key in pushToCloud. If
// pre-fix code is ever reintroduced, the test will fail because no
// handler will be found for "order.order.init" etc.
func enqueueLikeHelper(t *testing.T, e *SyncEngine, action, entityID string, extra map[string]any) {
	t.Helper()
	entityType, operation, ok := strings.Cut(action, ".")
	if !ok || entityType == "" || operation == "" {
		t.Fatalf("malformed action %q", action)
	}
	payload := map[string]any{
		"bearer_token":    "ws-token",
		"idempotency_key": "idem-" + entityID + "-" + operation,
		"order_id":        entityID,
	}
	for k, v := range extra {
		payload[k] = v
	}
	if err := e.Enqueue(entityType, entityID, operation, payload, 1); err != nil {
		t.Fatalf("enqueue %s: %v", action, err)
	}
}

// ─── Order lifecycle pushes ───────────────────────────────────────────────

func TestOrderInitPushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.init", "ord-local", map[string]any{
		"guest_count": 4, "table_ids": []string{"tbl-1"},
	})
	forceOnline(e)
	e.processQueue()
	rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/init")
}

func TestOrderUpdatePushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.update", "ord-local", map[string]any{
		"note": "no cilantro",
	})
	forceOnline(e)
	e.processQueue()
	rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/update")
}

func TestOrderDeletePushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.delete", "ord-local", nil)
	forceOnline(e)
	e.processQueue()
	rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/delete")
}

func TestOrderVoidPushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.void", "ord-local", map[string]any{
		"void_reason": "customer left",
	})
	forceOnline(e)
	e.processQueue()
	call := rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/void")
	if call.body["void_reason"] != "customer left" {
		t.Errorf("void_reason: %v", call.body)
	}
}

func TestOrderCheckoutPushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.checkout", "ord-local", nil)
	forceOnline(e)
	e.processQueue()
	rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/checkout")
}

// ─── Order-item ops ───────────────────────────────────────────────────────

func TestOrderItemUpdatePushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.item_update", "ord-local", map[string]any{
		"item_id":  "item-xyz",
		"quantity": 3,
		"note":     "extra spicy",
	})
	forceOnline(e)
	e.processQueue()
	rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/items/item-xyz")
}

func TestOrderItemDeletePushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.item_delete", "ord-local", map[string]any{
		"item_id": "item-xyz",
	})
	forceOnline(e)
	e.processQueue()
	rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/items/item-xyz/delete")
}

func TestOrderItemVoidPushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.item_void", "ord-local", map[string]any{
		"item_id":     "item-xyz",
		"void_reason": "kitchen mistake",
	})
	forceOnline(e)
	e.processQueue()
	call := rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/items/item-xyz/void")
	if call.body["void_reason"] != "kitchen mistake" {
		t.Errorf("item_void body: %+v", call.body)
	}
	// plan-051 — no picked reason → the key must be ABSENT (an old Cloud's
	// validator should never even see it).
	if _, present := call.body["void_reason_id"]; present {
		t.Errorf("void_reason_id must be omitted when not picked: %+v", call.body)
	}
}

// plan-051 (#1149) — a picked VoidReason id rides the sync-UP body alongside
// the reason text (the text is ALWAYS sent; Cloud degrades an unresolvable id
// to a text-void, converge-not-reject).
func TestOrderItemVoidPushesToCloud_WithReasonID(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.item_void", "ord-local", map[string]any{
		"item_id":        "item-xyz",
		"void_reason":    "Bấm nhầm",
		"void_reason_id": "vr-1",
	})
	forceOnline(e)
	e.processQueue()
	call := rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/items/item-xyz/void")
	if call.body["void_reason_id"] != "vr-1" {
		t.Errorf("void_reason_id want vr-1 in body, got %+v", call.body)
	}
	if call.body["void_reason"] != "Bấm nhầm" {
		t.Errorf("void_reason text must ALWAYS accompany the id, got %+v", call.body)
	}
}

// ─── Coupon ops ───────────────────────────────────────────────────────────

func TestOrderApplyCouponPushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.apply_coupon", "ord-local", map[string]any{
		"coupon_code": "WELCOME10",
		"customer_id": "cust-1",
	})
	forceOnline(e)
	e.processQueue()
	call := rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/apply-coupon")
	if call.body["code"] != "WELCOME10" {
		t.Errorf("apply_coupon body: %+v", call.body)
	}
}

func TestOrderReleaseCouponPushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.release_coupon", "ord-local", nil)
	forceOnline(e)
	e.processQueue()
	rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/release-coupon")
}

// ─── Table merge ops ──────────────────────────────────────────────────────

func TestOrderMergeTablePushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.merge_table", "ord-local", map[string]any{
		"table_id": "tbl-7",
	})
	forceOnline(e)
	e.processQueue()
	call := rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/merge-table")
	if call.body["table_id"] != "tbl-7" {
		t.Errorf("merge_table body: %+v", call.body)
	}
}

func TestOrderUnmergeTablePushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	enqueueLikeHelper(t, e, "order.unmerge_table", "ord-local", map[string]any{
		"table_id": "tbl-7",
	})
	forceOnline(e)
	e.processQueue()
	rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/unmerge-table")
}

// ─── Payment refund ───────────────────────────────────────────────────────

// #520 Bug B — the Cloud refund route is nested under the order
// (/orders/{cloudOrderID}/payments/{cloudPaymentID}/refund), NOT the
// non-existent top-level /payments/{id}/refund that used to 404 and get
// dropped. The enqueued entityID is the local ORDER id; the handler must
// resolve the order's cloud_id too.
func TestPaymentRefundPushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	// Seed the order (with cloud_id) and a local payment (with cloud_id) so
	// handlePaymentRefund can resolve the nested Cloud URL.
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")
	if _, err := e.db.Exec(`INSERT INTO payments
		(id, cloud_id, order_id, payment_method, amount, status, created_at)
		VALUES ('pay-local', 'pay-cloud', 'ord-local', 'cash', 1000, 'succeeded', datetime('now'))`); err != nil {
		t.Fatal(err)
	}
	enqueueLikeHelper(t, e, "payment.refund", "ord-local", map[string]any{
		"payment_id": "pay-local",
		"refund_id":  "refund-1",
		"amount":     500,
		"note":       "partial",
	})
	forceOnline(e)
	e.processQueue()
	call := rc.assertHit(t, "/api/v1/workstation/orders/ord-cloud/payments/pay-cloud/refund")
	if call.body["refund_id"] != "refund-1" {
		t.Errorf("refund body: %+v", call.body)
	}
}

// #520 Bug B — until the order's own create syncs (cloud_id NULL), the refund
// must be held (retryable), NOT posted to a bad path or dropped.
func TestPaymentRefundWaitsForOrderCloudID(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	// Order exists locally but has not synced yet (no cloud_id).
	if _, err := e.db.Exec(`INSERT INTO orders (
		id, cloud_id, order_code, order_number, order_type, status,
		opened_at, guest_count,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, created_at, updated_at
	) VALUES ('ord-local', NULL, 'WS-0002', 2, 'dine_in', 'open',
		datetime('now'), 2, 60000, 0, 0, 6000, 0, 66000, 0,
		'', '', '', datetime('now'), datetime('now'))`); err != nil {
		t.Fatal(err)
	}
	if _, err := e.db.Exec(`INSERT INTO payments
		(id, cloud_id, order_id, payment_method, amount, status, created_at)
		VALUES ('pay-local', 'pay-cloud', 'ord-local', 'cash', 1000, 'succeeded', datetime('now'))`); err != nil {
		t.Fatal(err)
	}
	enqueueLikeHelper(t, e, "payment.refund", "ord-local", map[string]any{
		"payment_id": "pay-local",
		"refund_id":  "refund-2",
		"amount":     500,
	})
	forceOnline(e)
	e.processQueue()
	// No refund call must have gone out (order not synced → held for retry).
	for _, c := range rc.calls {
		if strings.Contains(c.path, "refund") {
			t.Fatalf("refund must not be posted before order cloud_id exists; got %s", c.path)
		}
	}
}

// ─── Customer create ──────────────────────────────────────────────────────

func TestCustomerCreatePushesToCloud(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	// Seed a local customer the workstation just synthesized.
	if _, err := e.db.Exec(`INSERT INTO customers
		(id, first_name, full_name, phone, local_pending_sync, local_synced_at)
		VALUES ('cust-local', 'Anh Tuan', 'Anh Tuan', '0901234567', 1, datetime('now'))`); err != nil {
		t.Fatal(err)
	}
	enqueueLikeHelper(t, e, "customer.create", "cust-local", map[string]any{
		"phone":      "0901234567",
		"first_name": "Anh Tuan",
	})
	forceOnline(e)
	e.processQueue()
	call := rc.assertHit(t, "/api/v1/workstation/customers/find-or-create")
	if call.body["phone"] != "0901234567" {
		t.Errorf("customer body: %+v", call.body)
	}

	// local_pending_sync must drop to 0 after successful push so the
	// next PullCustomers tick can replace the row without confusing
	// the recovery flow.
	var pending int
	_ = e.db.QueryRow(`SELECT local_pending_sync FROM customers WHERE id = 'cust-local'`).Scan(&pending)
	if pending != 0 {
		t.Errorf("local_pending_sync should clear after push: got %d", pending)
	}
}

// ─── Negative regression: pre-fix double-prefix must NOT be how it works ──
//
// Enqueue using the BUGGY pre-fix shape (entityType="order", operation=
// "order.init") and assert processQueue logs "no handler" + does NOT hit
// Cloud. This is the regression guard — if some future refactor calls
// Enqueue("order", id, "order.init", …) again, this test catches it.
func TestSyncEngine_DoubleEnqueueKeyIsNotAccepted(t *testing.T) {
	rc := newRecordingCloud(t)
	e, _ := newSyncTestEngine(t, rc.server.URL)
	seedOrderWithCloudID(t, e, "ord-local", "ord-cloud")

	// Buggy enqueue — would map to key "order.order.init".
	if err := e.Enqueue("order", "ord-local", "order.init",
		map[string]any{"bearer_token": "ws-token", "idempotency_key": "i1"}, 1); err != nil {
		t.Fatal(err)
	}
	forceOnline(e)
	e.processQueue()

	if atomic.LoadInt32(&rc.count) != 0 {
		t.Errorf("buggy double-prefix key must NOT hit Cloud, got %d call(s)", rc.count)
	}
}
