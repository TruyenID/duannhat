package service

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
)

// TestOrderCreateSyncForwardsToCloud asserts that enqueuing an order.create
// pushes the payload to POST /api/v1/workstation/orders with the workstation
// device Bearer token + Idempotency-Key, and that the returned cloud_id is
// persisted back to the local orders row so subsequent ops can link.
func TestOrderCreateSyncForwardsToCloud(t *testing.T) {
	var (
		seenPath  string
		seenAuth  string
		seenIdem  string
		seenBody  map[string]any
		callCount int32
	)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&callCount, 1)
		seenPath = r.URL.Path
		seenAuth = r.Header.Get("Authorization")
		seenIdem = r.Header.Get("Idempotency-Key")
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"cloud-ord-1","order_code":"ORD-001","status":"open"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	// plan-041 — capture the reconcile broadcast hook.
	var assignedID, assignedCode string
	e.SetOrderCodeAssignedCallback(func(orderID, orderCode string) {
		assignedID, assignedCode = orderID, orderCode
	})

	// Seed local order (new schema: tax_amount, total_amount, opened_at)
	_, err := db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status,
		opened_at, guest_count,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id,
		created_at, updated_at
	) VALUES ('local-ord-1', 'WS-0001', 1, 'dine_in', 'open',
		datetime('now'), 2,
		60000, 0, 0, 6000, 0, 66000, 0,
		'', '', '',
		datetime('now'), datetime('now'))`)
	if err != nil {
		t.Fatalf("seed order: %v", err)
	}

	payload := map[string]any{
		"bearer_token":    "ws-token-xyz",
		"idempotency_key": "idem-order-1",
		"order": map[string]any{
			"client_order_id":         "local-ord-1",
			"order_type":              "takeaway",
			"customer_takeaway_name":  "Anh Tuấn",
			"customer_takeaway_phone": "0901234567",
			"note":                    "Không hành",
		},
	}
	if err := e.Enqueue("order", "local-ord-1", "create", payload, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	// Force online so processQueue runs
	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()

	e.processQueue()

	if atomic.LoadInt32(&callCount) != 1 {
		t.Fatalf("expected 1 Cloud call, got %d", callCount)
	}
	if seenPath != "/api/v1/workstation/orders" {
		t.Errorf("expected path /api/v1/workstation/orders, got %q", seenPath)
	}
	if seenAuth != "Bearer ws-token-xyz" {
		t.Errorf("Authorization header wrong: %q", seenAuth)
	}
	if seenIdem != "idem-order-1" {
		t.Errorf("Idempotency-Key header wrong: %q", seenIdem)
	}
	if seenBody["order_type"] != "takeaway" || seenBody["customer_takeaway_name"] != "Anh Tuấn" {
		t.Errorf("request body wrong: %+v", seenBody)
	}
	// plan-041 — durable idempotency key (local UUID) is forwarded; no
	// order_code is sent up (Cloud mints it).
	if seenBody["client_order_id"] != "local-ord-1" {
		t.Errorf("expected client_order_id=local-ord-1 in body, got %+v", seenBody["client_order_id"])
	}
	if _, ok := seenBody["order_code"]; ok {
		t.Errorf("order_code must NOT be sent up; Cloud is the authority. body=%+v", seenBody)
	}

	// cloud_id should be persisted to orders
	var cloudID string
	db.QueryRow(`SELECT COALESCE(cloud_id, '') FROM orders WHERE id = 'local-ord-1'`).Scan(&cloudID)
	if cloudID != "cloud-ord-1" {
		t.Errorf("expected cloud_id=cloud-ord-1, got %q", cloudID)
	}

	// plan-041 — the Cloud-minted ORD code is adopted onto the local order,
	// replacing the provisional WS-0001, and the broadcast hook fires.
	var localCode string
	db.QueryRow(`SELECT order_code FROM orders WHERE id = 'local-ord-1'`).Scan(&localCode)
	if localCode != "ORD-001" {
		t.Errorf("expected local order_code adopted to ORD-001, got %q", localCode)
	}
	var orderSyncedAt string
	db.QueryRow(`SELECT COALESCE(synced_at, '') FROM orders WHERE id = 'local-ord-1'`).Scan(&orderSyncedAt)
	if orderSyncedAt == "" {
		t.Error("expected orders.synced_at to be stamped after reconcile")
	}
	if assignedID != "local-ord-1" || assignedCode != "ORD-001" {
		t.Errorf("expected code-assigned callback (local-ord-1, ORD-001), got (%q, %q)", assignedID, assignedCode)
	}

	// sync_queue entry should be marked synced
	var syncedAt string
	db.QueryRow(`SELECT COALESCE(synced_at, '') FROM sync_queue WHERE entity_type='order' AND entity_id='local-ord-1'`).Scan(&syncedAt)
	if syncedAt == "" {
		t.Error("expected sync_queue entry to be marked synced")
	}
}

// TestOrderCreateSyncSendsCustomerPhone asserts that an order linked to a
// workstation-local customer syncs UP with `customer_phone` alongside
// `customer_id`, so Cloud can find-or-create the canonical customer by phone
// instead of 422-ing on `exists:customers,id` (the LAN-minted id is unknown to
// Cloud). This is the regression guard for the "order with phone never syncs"
// bug. Exercises the reconcileUnsyncedOrders orderShape builder end-to-end.
func TestOrderCreateSyncSendsCustomerPhone(t *testing.T) {
	var seenBody map[string]any
	var callCount int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&callCount, 1)
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"cloud-ord-2","order_code":"ORD-002","status":"open"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	// A workstation-local customer minted via /pos/customers/find-or-create:
	// its id is a LAN UUID Cloud has never seen; only the phone is resolvable.
	if _, err := db.Exec(`INSERT INTO customers (id, first_name, full_name, phone)
		VALUES ('local-cust-1', 'Khách 4567', 'Khách 4567', '0901234567')`); err != nil {
		t.Fatalf("seed customer: %v", err)
	}

	// An order linked to that customer, never synced, no queue row yet — the
	// exact shape reconcileUnsyncedOrders backfills.
	if _, err := db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status,
		opened_at, guest_count,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, customer_id,
		created_at, updated_at
	) VALUES ('local-ord-2', 'WS-0002', 2, 'takeaway', 'open',
		datetime('now'), 1,
		30000, 0, 0, 3000, 0, 33000, 0,
		'', '', '', 'local-cust-1',
		datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()

	e.reconcileUnsyncedOrders()
	e.processQueue()

	if atomic.LoadInt32(&callCount) != 1 {
		t.Fatalf("expected 1 Cloud call, got %d", callCount)
	}
	if seenBody["customer_id"] != "local-cust-1" {
		t.Errorf("expected customer_id=local-cust-1, got %+v", seenBody["customer_id"])
	}
	if seenBody["customer_phone"] != "0901234567" {
		t.Errorf("expected customer_phone=0901234567 in body, got %+v", seenBody["customer_phone"])
	}
}
