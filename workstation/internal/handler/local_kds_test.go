package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// mockKdsMeCloud returns a fake Cloud responding to GET /api/v1/devices/me
// for KDS device tokens (plain bearer without '|' uses that path).
func mockKdsMeCloud(t *testing.T, deviceID, branchID string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"` + deviceID + `","type":"kds","branch_id":"` + branchID + `","status":"active"}}`))
	}))
	t.Cleanup(srv.Close)
	return srv
}

// seedKdsTables creates the orders and order_items tables (post-Sprint-4 schema,
// migration 007_align_orders_schema.sql) in the test DB. Full migrations don't
// run in unit tests so we create the tables inline with the aligned column set.
func seedKdsTables(t *testing.T, s *Server) {
	t.Helper()
	_, err := s.db.Exec(`
		CREATE TABLE IF NOT EXISTS orders (
			id                      TEXT PRIMARY KEY,
			cloud_id                TEXT,
			order_code              TEXT NOT NULL DEFAULT '',
			order_number            INTEGER NOT NULL DEFAULT 0,
			order_type              TEXT NOT NULL DEFAULT 'spot',
			status                  TEXT NOT NULL DEFAULT 'open',
			opened_at               TEXT NOT NULL DEFAULT (datetime('now')),
			checkout_at             TEXT,
			closed_at               TEXT,
			voided_at               TEXT,
			void_reason             TEXT,
			table_id                TEXT,
			table_number            TEXT,
			guest_count             INTEGER DEFAULT 1,
			customer_takeaway_name  TEXT,
			customer_takeaway_phone TEXT,
			note                    TEXT,
			subtotal                INTEGER NOT NULL DEFAULT 0,
			discount_amount         INTEGER NOT NULL DEFAULT 0,
			service_charge          INTEGER NOT NULL DEFAULT 0,
			tax_amount              INTEGER NOT NULL DEFAULT 0,
			total_tip               INTEGER NOT NULL DEFAULT 0,
			total_amount            INTEGER NOT NULL DEFAULT 0,
			paid_amount             INTEGER NOT NULL DEFAULT 0,
			payment_method          TEXT,
			organization_id         TEXT NOT NULL DEFAULT '',
			brand_id                TEXT NOT NULL DEFAULT '',
			branch_id               TEXT NOT NULL DEFAULT '',
			created_at              TEXT NOT NULL DEFAULT (datetime('now')),
			updated_at              TEXT NOT NULL DEFAULT (datetime('now')),
			synced_at               TEXT
		)
	`)
	if err != nil {
		t.Fatalf("create orders: %v", err)
	}
	_, err = s.db.Exec(`
		CREATE TABLE IF NOT EXISTS order_items (
			id                TEXT PRIMARY KEY,
			customer_order_id TEXT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
			product_sku_id    TEXT,
			menu_item_id      TEXT,
			menu_item_name    TEXT NOT NULL DEFAULT '',
			quantity          INTEGER NOT NULL DEFAULT 1,
			unit_price        INTEGER NOT NULL DEFAULT 0,
			subtotal          INTEGER NOT NULL DEFAULT 0,
			note              TEXT,
			printer_group     TEXT NOT NULL DEFAULT 'kitchen',
			status            TEXT NOT NULL DEFAULT 'pending',
			served_at         TEXT,
			voided_at         TEXT,
			void_reason       TEXT,
			printed_at        TEXT,
			created_at        TEXT NOT NULL DEFAULT (datetime('now')),
			updated_at        TEXT NOT NULL DEFAULT (datetime('now'))
		)
	`)
	if err != nil {
		t.Fatalf("create order_items: %v", err)
	}
}

func TestLocalKdsMe_ReturnsDeviceContext(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/kds/me", nil)
	req.Header.Set("Authorization", "Bearer kds-device-token")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}

	var resp struct {
		Data struct {
			ID       string `json:"id"`
			Type     string `json:"type"`
			BranchID string `json:"branch_id"`
		} `json:"data"`
	}
	if err := json.NewDecoder(w.Body).Decode(&resp); err != nil {
		t.Fatalf("decode: %v body=%s", err, w.Body.String())
	}
	if resp.Data.ID != "kds-1" {
		t.Errorf("expected id=kds-1, got %q", resp.Data.ID)
	}
	if resp.Data.Type != "kds" {
		t.Errorf("expected type=kds, got %q", resp.Data.Type)
	}
	if resp.Data.BranchID != "branch-A" {
		t.Errorf("expected branch_id=branch-A, got %q", resp.Data.BranchID)
	}
}

func TestLocalKdsOrders_ReturnsBranchScopedActive(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	// Seed orders:
	//   order-1: branch-A, status=open   → should appear
	//   order-2: branch-A, status=closed → should NOT appear
	//   order-3: branch-B, status=open   → should NOT appear
	_, err := db.Exec(`
		INSERT INTO orders (id, order_code, branch_id, status, opened_at)
		VALUES
			('order-1', 'ORD-001', 'branch-A', 'open',   datetime('now')),
			('order-2', 'ORD-002', 'branch-A', 'closed', datetime('now')),
			('order-3', 'ORD-003', 'branch-B', 'open',   datetime('now'))
	`)
	if err != nil {
		t.Fatalf("seed orders: %v", err)
	}
	// Seed an item for order-1 using post-Sprint-4 order_items columns.
	_, err = db.Exec(`
		INSERT INTO order_items (id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, status, printer_group)
		VALUES ('item-1', 'order-1', 'sku-x', 'Ramen', 2, 5000, 10000, 'pending', 'kitchen')
	`)
	if err != nil {
		t.Fatalf("seed items: %v", err)
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/kds/orders", nil)
	req.Header.Set("Authorization", "Bearer kds-device-token")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}

	var resp struct {
		Data []map[string]any `json:"data"`
		Meta map[string]any   `json:"meta"`
	}
	if err := json.NewDecoder(w.Body).Decode(&resp); err != nil {
		t.Fatalf("decode: %v body=%s", err, w.Body.String())
	}

	if len(resp.Data) != 1 {
		t.Fatalf("expected 1 order (open branch-A only), got %d: %v", len(resp.Data), resp.Data)
	}
	if resp.Data[0]["id"] != "order-1" {
		t.Errorf("expected order-1, got %v", resp.Data[0]["id"])
	}
	// Verify post-Sprint-4 fields are present in the response.
	if _, ok := resp.Data[0]["opened_at"]; !ok {
		t.Errorf("expected opened_at field in order response")
	}
	if _, ok := resp.Data[0]["guest_count"]; !ok {
		t.Errorf("expected guest_count field in order response")
	}
	// Items should be embedded with post-Sprint-4 fields.
	items, ok := resp.Data[0]["items"].([]any)
	if !ok || len(items) != 1 {
		t.Errorf("expected 1 embedded item, got %v", resp.Data[0]["items"])
	}
	if len(items) == 1 {
		item := items[0].(map[string]any)
		if item["menu_item_name"] != "Ramen" {
			t.Errorf("expected menu_item_name=Ramen, got %v", item["menu_item_name"])
		}
		if item["status"] != "pending" {
			t.Errorf("expected item status=pending, got %v", item["status"])
		}
		// KdsItemResource shape (post-Phase-5.4): printer_group, product_sku_id,
		// unit_price, subtotal are hidden cloud-side and gone from workstation
		// response too. Derived fields are present.
		if _, ok := item["allowed_transitions"]; !ok {
			t.Errorf("expected allowed_transitions, got nil")
		}
		if _, ok := item["aging_minutes"]; !ok {
			t.Errorf("expected aging_minutes, got nil")
		}
		if _, ok := item["printer_group"]; ok {
			t.Errorf("printer_group should be hidden in KdsItemResource shape, got %v", item["printer_group"])
		}
	}
	if resp.Meta == nil {
		t.Errorf("expected meta, got nil")
	}
	// Aggregate meta (post-Phase-5.4) should expose active_count + items_by_status.
	if _, ok := resp.Meta["active_count"]; !ok {
		t.Errorf("expected meta.active_count, got nil")
	}
	if _, ok := resp.Meta["items_by_status"]; !ok {
		t.Errorf("expected meta.items_by_status, got nil")
	}
}

// With kds_show_only_fired=true, an order whose items have NOT been fired
// ("gửi bếp") must not appear on KDS at all — no empty card. The moment its
// first item is sent to the kitchen (print_status flips to sent_to_kitchen),
// the order surfaces with that item.
func TestLocalKdsOrders_ShowOnlyFired_HidesUnfiredOrders(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	if _, err := db.Exec(`INSERT INTO settings (key, value) VALUES ('kds_show_only_fired','true')
		ON CONFLICT(key) DO UPDATE SET value='true'`); err != nil {
		t.Fatalf("set setting: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at)
		VALUES ('order-1','ORD-001','branch-A','open',datetime('now'))`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status, printer_group)
		VALUES ('item-1','order-1','Ramen',1,5000,5000,'pending','kitchen')`); err != nil {
		t.Fatalf("seed item: %v", err)
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	fetch := func() []map[string]any {
		req := httptest.NewRequest("GET", "/api/v1/kds/orders", nil)
		req.Header.Set("Authorization", "Bearer kds-device-token")
		w := httptest.NewRecorder()
		mux.ServeHTTP(w, req)
		if w.Code != http.StatusOK {
			t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
		}
		var resp struct {
			Data []map[string]any `json:"data"`
		}
		if err := json.NewDecoder(w.Body).Decode(&resp); err != nil {
			t.Fatalf("decode: %v", err)
		}
		return resp.Data
	}

	// Before firing — order hidden (no fired items).
	if got := fetch(); len(got) != 0 {
		t.Fatalf("show_only_fired: want 0 orders before fire, got %d: %v", len(got), got)
	}

	// Fire the item (what pos-web "gửi bếp" does) — order now surfaces.
	if _, err := db.Exec(`UPDATE order_items SET print_status='sent_to_kitchen' WHERE id='item-1'`); err != nil {
		t.Fatalf("fire item: %v", err)
	}
	got := fetch()
	if len(got) != 1 {
		t.Fatalf("show_only_fired: want 1 order after fire, got %d", len(got))
	}
	if items, _ := got[0]["items"].([]any); len(items) != 1 {
		t.Fatalf("want 1 fired item, got %v", got[0]["items"])
	}
}

func TestLocalKdsOrders_503WhenReplicaEmpty(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	// Create tables but leave orders empty.
	seedKdsTables(t, s)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/kds/orders", nil)
	req.Header.Set("Authorization", "Bearer kds-device-token")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusServiceUnavailable {
		t.Fatalf("expected 503 when replica empty, got %d body=%s", w.Code, w.Body.String())
	}
}

// ─── KDS Bump Tests ───────────────────────────────────────────────────────────

func TestLocalKdsBumpItem_UpdatesStatus(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	// Seed order + pending item
	_, err := db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-1', 'ORD-001', 'branch-A', 'open', datetime('now'))`)
	if err != nil {
		t.Fatalf("seed order: %v", err)
	}
	_, err = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status) VALUES ('item-1', 'order-1', 'Ramen', 1, 5000, 5000, 'pending')`)
	if err != nil {
		t.Fatalf("seed item: %v", err)
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	body := bytes.NewBufferString(`{"status":"preparing"}`)
	req := httptest.NewRequest("PATCH", "/api/v1/kds/orders/order-1/items/item-1/status", body)
	req.Header.Set("Authorization", "Bearer kds-device-token")
	req.Header.Set("Idempotency-Key", "idem-bump-1")
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}

	// Verify DB updated
	var gotStatus string
	if err := db.QueryRow(`SELECT status FROM order_items WHERE id = 'item-1'`).Scan(&gotStatus); err != nil {
		t.Fatalf("read status: %v", err)
	}
	if gotStatus != "preparing" {
		t.Errorf("expected status=preparing, got %q", gotStatus)
	}

	// Verify sync_queue entry
	var n int
	if err := db.QueryRow(`SELECT COUNT(*) FROM sync_queue WHERE entity_type = 'customer_order_item' AND operation = 'update_status'`).Scan(&n); err != nil || n != 1 {
		t.Errorf("expected 1 sync_queue entry for customer_order_item.update_status, got n=%d err=%v", n, err)
	}
}

func TestLocalKdsBumpItem_IdempotencyReplay(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, err := db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-2', 'ORD-002', 'branch-A', 'open', datetime('now'))`)
	if err != nil {
		t.Fatalf("seed order: %v", err)
	}
	_, err = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status) VALUES ('item-2', 'order-2', 'Soba', 1, 4000, 4000, 'pending')`)
	if err != nil {
		t.Fatalf("seed item: %v", err)
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	doRequest := func() int {
		body := bytes.NewBufferString(`{"status":"ready"}`)
		req := httptest.NewRequest("PATCH", "/api/v1/kds/orders/order-2/items/item-2/status", body)
		req.Header.Set("Authorization", "Bearer kds-device-token")
		req.Header.Set("Idempotency-Key", "idem-replay-1")
		req.Header.Set("Content-Type", "application/json")
		w := httptest.NewRecorder()
		mux.ServeHTTP(w, req)
		return w.Code
	}

	if code := doRequest(); code != http.StatusOK {
		t.Fatalf("first call: expected 200, got %d", code)
	}
	if code := doRequest(); code != http.StatusOK {
		t.Fatalf("second (replay) call: expected 200, got %d", code)
	}

	// Item status should still be "ready" (set by first call)
	var gotStatus string
	if err := db.QueryRow(`SELECT status FROM order_items WHERE id = 'item-2'`).Scan(&gotStatus); err != nil {
		t.Fatalf("read status: %v", err)
	}
	if gotStatus != "ready" {
		t.Errorf("expected status=ready after replay, got %q", gotStatus)
	}

	// Only 1 sync_queue entry despite 2 calls (replay short-circuits before enqueue)
	var n int
	if err := db.QueryRow(`SELECT COUNT(*) FROM sync_queue WHERE entity_type = 'customer_order_item' AND operation = 'update_status' AND entity_id = 'item-2'`).Scan(&n); err != nil || n != 1 {
		t.Errorf("expected exactly 1 sync_queue entry after replay, got n=%d err=%v", n, err)
	}
}

func TestLocalKdsBumpItem_RejectsInvalidStatus(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, err := db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-3', 'ORD-003', 'branch-A', 'open', datetime('now'))`)
	if err != nil {
		t.Fatalf("seed order: %v", err)
	}
	_, err = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status) VALUES ('item-3', 'order-3', 'Udon', 1, 3000, 3000, 'pending')`)
	if err != nil {
		t.Fatalf("seed item: %v", err)
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	for _, badStatus := range []string{"pending", "voided"} {
		body := bytes.NewBufferString(`{"status":"` + badStatus + `"}`)
		req := httptest.NewRequest("PATCH", "/api/v1/kds/orders/order-3/items/item-3/status", body)
		req.Header.Set("Authorization", "Bearer kds-device-token")
		req.Header.Set("Idempotency-Key", "idem-invalid-"+badStatus)
		req.Header.Set("Content-Type", "application/json")
		w := httptest.NewRecorder()
		mux.ServeHTTP(w, req)
		if w.Code != http.StatusUnprocessableEntity {
			t.Errorf("status=%q: expected 422, got %d body=%s", badStatus, w.Code, w.Body.String())
		}
	}
}

func TestLocalKdsBumpItem_RejectsMissingIdempotencyKey(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, err := db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-4', 'ORD-004', 'branch-A', 'open', datetime('now'))`)
	if err != nil {
		t.Fatalf("seed order: %v", err)
	}
	_, err = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status) VALUES ('item-4', 'order-4', 'Gyoza', 2, 600, 1200, 'pending')`)
	if err != nil {
		t.Fatalf("seed item: %v", err)
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	body := bytes.NewBufferString(`{"status":"preparing"}`)
	req := httptest.NewRequest("PATCH", "/api/v1/kds/orders/order-4/items/item-4/status", body)
	req.Header.Set("Authorization", "Bearer kds-device-token")
	// intentionally omit Idempotency-Key header
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422 for missing Idempotency-Key, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestLocalKdsBumpItem_404OnUnknownItem(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, err := db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-5', 'ORD-005', 'branch-A', 'open', datetime('now'))`)
	if err != nil {
		t.Fatalf("seed order: %v", err)
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	body := bytes.NewBufferString(`{"status":"preparing"}`)
	req := httptest.NewRequest("PATCH", "/api/v1/kds/orders/order-5/items/nonexistent-item/status", body)
	req.Header.Set("Authorization", "Bearer kds-device-token")
	req.Header.Set("Idempotency-Key", "idem-404-test")
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusNotFound {
		t.Fatalf("expected 404 for unknown item, got %d body=%s", w.Code, w.Body.String())
	}
}
