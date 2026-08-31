package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printjob"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// newServerWithAuth builds a minimal Server wired with a working AuthMiddleware
// pointing at the given (mock) Cloud URL. Only fields used by local-replica
// handlers are populated. Order/audit/monitor are left nil — handlers under test
// must not depend on them.
func newServerWithAuth(t testing.TB, cloudURL string) (*Server, *store.DB) {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('cloud_api_url', ?)`, cloudURL); err != nil {
		t.Fatalf("seed cloud_api_url: %v", err)
	}

	// Sprint 3 S3.6b made branchOK() fail-close — every test must seed
	// workstation_branch_id matching its mock cloud's branch. "branch-A"
	// is the default that mockKioskMeCloud uses; tests with cross-branch
	// scenarios override via direct settings UPDATE before calling.
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('workstation_branch_id', 'branch-A')`); err != nil {
		t.Fatalf("seed workstation_branch_id: %v", err)
	}

	sync := service.NewSyncEngine(db, cloudURL, nil)

	s := &Server{db: db, sync: sync}
	s.refreshWorkstationBranchID()
	s.authCache = service.NewAuthCacheStore(db, 5*time.Minute)
	verifier := service.NewCloudVerifier(s.cloudAPIURL)
	s.authMW = NewAuthMiddleware(s.authCache, verifier, s.workstationBranchID, nil)
	s.idempotency = service.NewIdempotencyStore(db)
	// #1875 — New() always wires this, so a harness without it lets the print
	// paths silently take their journal-less fallback: every copy number comes
	// back 1 and no ledger row is written. That is how the missing auto-print
	// ledger rows stayed invisible for so long.
	s.printJournal = printjob.NewJournal(db)
	// Rate-limiter pools must be non-nil — route registration calls
	// .Middleware on them. Use generous defaults for tests (1000/min,
	// burst 100) so the per-IP gate never trips mid-suite. Dedicated
	// rate-limit tests override with stricter limits.
	s.pairLimiter = newRateLimiterPool(1000, 100)
	s.paymentLimiter = newRateLimiterPool(1000, 100)
	// Each pool spawns a long-lived gc goroutine — stop them in the
	// test cleanup so goleak.VerifyNone doesn't see them as a leak
	// from a sibling test that forgot to call Stop.
	t.Cleanup(func() {
		s.pairLimiter.Stop()
		s.paymentLimiter.Stop()
	})

	return s, db
}

// mockKioskMeCloud returns a fake Cloud that responds to GET /api/v1/kiosk/me
// for ANY bearer token (the auth tests cover the negative cases).
func mockKioskMeCloud(t *testing.T, deviceID, branchID string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"` + deviceID + `","type":"kiosk","branch_id":"` + branchID + `","status":"active"}}`))
	}))
	t.Cleanup(srv.Close)
	return srv
}

func TestKioskMeEndpoint(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/kiosk/me", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	var resp struct {
		Data struct {
			ID       string `json:"id"`
			Type     string `json:"type"`
			BranchID string `json:"branch_id"`
			Status   string `json:"status"`
		} `json:"data"`
	}
	if err := json.NewDecoder(rec.Body).Decode(&resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if resp.Data.ID != "kiosk-1" || resp.Data.Type != "kiosk" || resp.Data.BranchID != "branch-A" {
		t.Errorf("wrong data: %+v", resp.Data)
	}
}

func TestKioskMeRequiresAuth(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/kiosk/me", nil)
	// No Authorization header
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("expected 401, got %d", rec.Code)
	}
}

func TestCreatePaymentInsertsLocalAndQueues(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	body, _ := json.Marshal(map[string]any{
		"order_id":       "order-1",
		"payment_method": "card",
		"amount":         1500,
	})
	req := httptest.NewRequest("POST", "/api/v1/kiosk/payments", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer kiosk-token")
	req.Header.Set("Idempotency-Key", "idem-payment-1")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusCreated {
		t.Fatalf("expected 201, got %d body=%s", rec.Code, rec.Body.String())
	}

	// Payment row exists
	var n int
	if err := db.QueryRow(`SELECT COUNT(*) FROM payments WHERE idempotency_key = ?`, "idem-payment-1").Scan(&n); err != nil || n != 1 {
		t.Errorf("expected 1 payment row, got n=%d err=%v", n, err)
	}

	// Sync queue has an entry for the payment
	if err := db.QueryRow(`SELECT COUNT(*) FROM sync_queue WHERE entity_type = 'payment' AND operation = 'create'`).Scan(&n); err != nil || n != 1 {
		t.Errorf("expected 1 sync_queue entry, got n=%d err=%v", n, err)
	}
}

func TestCreatePaymentIdempotent(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	makeReq := func() *http.Request {
		body, _ := json.Marshal(map[string]any{
			"order_id": "order-1", "payment_method": "qr", "amount": 800,
		})
		req := httptest.NewRequest("POST", "/api/v1/kiosk/payments", bytes.NewReader(body))
		req.Header.Set("Authorization", "Bearer kiosk-token")
		req.Header.Set("Idempotency-Key", "idem-dup")
		return req
	}

	rec1 := httptest.NewRecorder()
	mux.ServeHTTP(rec1, makeReq())
	if rec1.Code != http.StatusCreated {
		t.Fatalf("first call expected 201, got %d", rec1.Code)
	}

	rec2 := httptest.NewRecorder()
	mux.ServeHTTP(rec2, makeReq())
	if rec2.Code != http.StatusOK {
		t.Errorf("duplicate Idempotency-Key expected 200 (idempotent), got %d body=%s", rec2.Code, rec2.Body.String())
	}

	// Should still be only ONE row
	var n int
	db.QueryRow(`SELECT COUNT(*) FROM payments WHERE idempotency_key = 'idem-dup'`).Scan(&n)
	if n != 1 {
		t.Errorf("expected 1 payment row after idempotent retry, got %d", n)
	}
}

func TestCreatePaymentRequiresIdempotencyKey(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	body, _ := json.Marshal(map[string]any{"order_id": "o1", "payment_method": "cash", "amount": 100})
	req := httptest.NewRequest("POST", "/api/v1/kiosk/payments", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer kiosk-token")
	// No Idempotency-Key
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusBadRequest {
		t.Errorf("expected 400, got %d", rec.Code)
	}
}

func TestConfirmPaymentTransitions(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// Pre-seed a pending payment
	_, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p-1', 'o-1', 'card', 1000, 'pending', 'idem-x')`)
	if err != nil {
		t.Fatalf("seed: %v", err)
	}
	// Pre-seed an order so update can do something (Sprint 4 schema: total → total_amount)
	if _, err := db.Exec(`INSERT INTO orders (id, order_number, status, total_amount) VALUES ('o-1', 1, 'open', 1000)`); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	req := httptest.NewRequest("POST", "/api/v1/kiosk/payments/p-1/confirm", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	var status string
	db.QueryRow("SELECT status FROM payments WHERE id='p-1'").Scan(&status)
	if status != "succeeded" {
		t.Errorf("expected status=succeeded, got %q", status)
	}

	var orderStatus string
	db.QueryRow("SELECT status FROM orders WHERE id='o-1'").Scan(&orderStatus)
	if orderStatus != "closed" {
		t.Errorf("expected order status=closed, got %q", orderStatus)
	}

	// Sync queue should have a 'confirm' op
	var n int
	db.QueryRow("SELECT COUNT(*) FROM sync_queue WHERE entity_type='payment' AND operation='confirm'").Scan(&n)
	if n != 1 {
		t.Errorf("expected 1 confirm op in sync_queue, got %d", n)
	}
}

// TestConfirmFailCarryTerminalFields proves confirm/fail accept the A6
// terminal contract (confirm: terminal_ref + terminal_data; fail adds reason +
// error_code) and stash them in the sync-UP payload so Cloud receives the same
// field names when the queue is pushed.
func TestConfirmFailCarryTerminalFields(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	seed := []string{
		`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p-c', 'o-1', 'card', 1000, 'pending', 'ik-c')`,
		`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p-f', 'o-1', 'qr',   1000, 'pending', 'ik-f')`,
		`INSERT INTO orders (id, order_number, status, total_amount) VALUES ('o-1', 1, 'open', 1000)`,
	}
	for _, q := range seed {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}

	payloadFor := func(op string) map[string]any {
		var raw string
		if err := db.QueryRow(
			`SELECT payload FROM sync_queue WHERE entity_type='payment' AND operation=? ORDER BY created_at DESC LIMIT 1`, op,
		).Scan(&raw); err != nil {
			t.Fatalf("read %s payload: %v", op, err)
		}
		var m map[string]any
		if err := json.Unmarshal([]byte(raw), &m); err != nil {
			t.Fatalf("decode %s payload: %v", op, err)
		}
		return m
	}

	// confirm: terminal_ref + terminal_data (object)
	confirmBody, _ := json.Marshal(map[string]any{
		"terminal_ref":  "TXN-123",
		"terminal_data": map[string]any{"approval": "A1", "rrn": "999"},
	})
	req := httptest.NewRequest("POST", "/api/v1/kiosk/payments/p-c/confirm", bytes.NewReader(confirmBody))
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("confirm: expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	cp := payloadFor("confirm")
	if cp["terminal_ref"] != "TXN-123" {
		t.Errorf("confirm payload terminal_ref = %v, want TXN-123", cp["terminal_ref"])
	}
	if td, _ := cp["terminal_data"].(string); !strings.Contains(td, "approval") {
		t.Errorf("confirm payload terminal_data missing/malformed: %v", cp["terminal_data"])
	}

	// fail: reason + error_code + terminal_ref
	failBody, _ := json.Marshal(map[string]any{
		"reason": "declined", "error_code": "51", "terminal_ref": "TXN-456",
	})
	req2 := httptest.NewRequest("POST", "/api/v1/kiosk/payments/p-f/fail", bytes.NewReader(failBody))
	req2.Header.Set("Authorization", "Bearer kiosk-token")
	rec2 := httptest.NewRecorder()
	mux.ServeHTTP(rec2, req2)
	if rec2.Code != http.StatusOK {
		t.Fatalf("fail: expected 200, got %d body=%s", rec2.Code, rec2.Body.String())
	}
	fp := payloadFor("fail")
	if fp["reason"] != "declined" || fp["error_code"] != "51" || fp["terminal_ref"] != "TXN-456" {
		t.Errorf("fail payload missing A6 fields: %+v", fp)
	}
}

func TestCustomerTableQRLookup(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	_, err := db.Exec(`INSERT INTO tables (id, qr_token, name, zone_id, status, capacity) VALUES ('t-1', 'qr-abc', 'Table 5', 'z-1', 'available', 4)`)
	if err != nil {
		t.Fatalf("seed table: %v", err)
	}

	req := httptest.NewRequest("GET", "/api/v1/customer/tables/qr-abc", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	var resp struct {
		Data struct {
			ID       string `json:"id"`
			Name     string `json:"name"`
			QRToken  string `json:"qr_token"`
			Status   string `json:"status"`
			Capacity int    `json:"capacity"`
		} `json:"data"`
	}
	json.NewDecoder(rec.Body).Decode(&resp)
	if resp.Data.ID != "t-1" || resp.Data.Name != "Table 5" {
		t.Errorf("wrong table data: %+v", resp.Data)
	}
}

// seedKioskOrder reproduces the real sync-down situation: an active order on
// table t-9 whose ORDER-LEVEL totals are stored as 0 (Cloud sync doesn't carry
// them) and whose items leave menu_item_name blank (the kiosk add-item flow
// only sends product_sku_id). The kiosk endpoint must resolve item names from
// pos_products and recompute subtotal/tax/service/total from items using the
// branch tax/service rates in shop_settings.
func seedKioskOrder(t *testing.T, db *store.DB) {
	t.Helper()
	stmts := []string{
		// Branch rates Cloud used: 10% tax, 5% service.
		`INSERT INTO shop_settings (key, value) VALUES ('tax_rate', '10.00')`,
		`INSERT INTO shop_settings (key, value) VALUES ('service_charge_rate', '5.00')`,
		// plan-043 (T3.6) — the SYNCED display currency. The kiosk bill used to
		// hard-code "JPY"; it now reads this. Seed it so the shape test asserts
		// the synced value flows through (a non-JPY value would also work now).
		`INSERT INTO shop_settings (key, value) VALUES ('currency', 'JPY')`,
		`INSERT INTO tables (id, name, status) VALUES ('t-9', 'Table 9', 'occupied')`,
		`INSERT INTO pos_products (id, name) VALUES ('prod-1', 'Regular')`,
		`INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES ('sku-1', 'prod-1', 'Large', 1870)`,
		`INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES ('sku-2', 'prod-1', 'XL', 2210)`,
		// Order-level money intentionally zeroed — mirrors sync-down rows.
		`INSERT INTO orders (id, order_number, order_code, status, table_id,
			subtotal, discount_amount, service_charge, tax_amount, total_amount, paid_amount)
		 VALUES ('ord-1', 1, 'ORD-2026-4240', 'pending', 't-9',
			0, 0, 0, 0, 0, 0)`,
		// #2188 — lines carry their tax snapshot (10%); unstamped lines are
		// dropped by the engine, never priced at a fallback rate.
		`INSERT INTO order_items (id, customer_order_id, product_sku_id, menu_item_name,
			quantity, unit_price, subtotal, printer_group, status, print_status, topping_subtotal, tax_rate)
		 VALUES ('it-1', 'ord-1', 'sku-1', '', 3, 1870, 5610, 'kitchen', 'pending', 'pending', 0, 10)`,
		`INSERT INTO order_items (id, customer_order_id, product_sku_id, menu_item_name,
			quantity, unit_price, subtotal, printer_group, status, print_status, topping_subtotal, tax_rate)
		 VALUES ('it-2', 'ord-1', 'sku-2', '', 1, 2210, 2210, 'kitchen', 'pending', 'pending', 0, 10)`,
	}
	for _, q := range stmts {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed (%s): %v", q, err)
		}
	}
}

func TestKioskOrdersByTableReturnsNormalizedShape(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	seedKioskOrder(t, db)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/kiosk/orders?table_id=t-9", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	var resp struct {
		Data struct {
			ID            string `json:"id"`
			TableID       string `json:"table_id"`
			TableName     string `json:"table_name"`
			Subtotal      int    `json:"subtotal"`
			Discount      int    `json:"discount"`
			TaxAmount     int    `json:"tax_amount"`
			ServiceCharge int    `json:"service_charge"`
			Total         int    `json:"total"`
			PaidAmount    int    `json:"paid_amount"`
			Currency      string `json:"currency"`
			Items         []struct {
				ID        string `json:"id"`
				Name      string `json:"name"`
				Quantity  int    `json:"quantity"`
				UnitPrice int    `json:"unit_price"`
			} `json:"items"`
		} `json:"data"`
	}
	if err := json.NewDecoder(rec.Body).Decode(&resp); err != nil {
		t.Fatalf("decode: %v body=%s", err, rec.Body.String())
	}

	d := resp.Data
	if d.ID != "ord-1" || d.TableID != "t-9" || d.TableName != "Table 9" {
		t.Errorf("order identity wrong: %+v", d)
	}
	// Totals recomputed from items despite zeroed storage — must match Cloud.
	if d.Subtotal != 7820 || d.Discount != 0 || d.TaxAmount != 782 ||
		d.ServiceCharge != 391 || d.Total != 8993 || d.PaidAmount != 0 || d.Currency != "JPY" {
		t.Errorf("order amounts wrong: %+v", d)
	}
	if len(d.Items) != 2 {
		t.Fatalf("expected 2 items, got %d", len(d.Items))
	}
	// name must resolve from pos_products even though menu_item_name is blank.
	it := d.Items[0]
	if it.ID != "it-1" || it.Name != "Regular" || it.Quantity != 3 || it.UnitPrice != 1870 {
		t.Errorf("item[0] wrong: %+v", it)
	}
	if d.Items[1].UnitPrice != 2210 || d.Items[1].Quantity != 1 || d.Items[1].Name != "Regular" {
		t.Errorf("item[1] wrong: %+v", d.Items[1])
	}
}

// TestKioskOrdersExtrasShape proves the kiosk extras payload ships the full
// shape the kiosk-app expects: label, price (per-unit), quantity, modifier_type.
// "remove" modifiers must NOT be filtered out — the kiosk renders them as
// "− No onion" so the customer sees the order was tweaked.
func TestKioskOrdersExtrasShape(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	seedKioskOrder(t, db)

	// Two toppings on it-1: a multi-unit add ("4 x Egg" @¥100/cái) and a
	// removal ("No onion"). Both must surface in the response.
	tops := []string{
		`INSERT INTO order_item_toppings
			(id, order_item_id, topping_group_item_id, product_sku_id, name, modifier_type, quantity, unit_price)
		 VALUES ('top-1', 'it-1', 'tgi-egg', 'sku-egg', 'Egg', 'add', 4, 100)`,
		`INSERT INTO order_item_toppings
			(id, order_item_id, topping_group_item_id, product_sku_id, name, modifier_type, quantity, unit_price)
		 VALUES ('top-2', 'it-1', 'tgi-onion', 'sku-onion', 'No onion', 'remove', 1, 0)`,
	}
	for _, q := range tops {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed topping: %v", err)
		}
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/kiosk/orders?table_id=t-9", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	var resp struct {
		Data struct {
			Items []struct {
				ID     string `json:"id"`
				Extras []struct {
					Label        string `json:"label"`
					Price        int    `json:"price"`
					Quantity     int    `json:"quantity"`
					ModifierType string `json:"modifier_type"`
				} `json:"extras"`
			} `json:"items"`
		} `json:"data"`
	}
	if err := json.NewDecoder(rec.Body).Decode(&resp); err != nil {
		t.Fatalf("decode: %v body=%s", err, rec.Body.String())
	}

	// it-1 carries both toppings; it-2 has none.
	var extras []struct {
		Label        string `json:"label"`
		Price        int    `json:"price"`
		Quantity     int    `json:"quantity"`
		ModifierType string `json:"modifier_type"`
	}
	for _, item := range resp.Data.Items {
		if item.ID == "it-1" {
			extras = item.Extras
			break
		}
	}
	if len(extras) != 2 {
		t.Fatalf("expected 2 extras forwarded (add + remove), got %d: %+v", len(extras), extras)
	}

	byLabel := map[string]struct {
		Label        string `json:"label"`
		Price        int    `json:"price"`
		Quantity     int    `json:"quantity"`
		ModifierType string `json:"modifier_type"`
	}{}
	for _, e := range extras {
		byLabel[e.Label] = e
	}

	egg, ok := byLabel["Egg"]
	if !ok {
		t.Fatalf("Egg extra missing: %+v", extras)
	}
	if egg.Price != 100 || egg.Quantity != 4 || egg.ModifierType != "add" {
		t.Errorf("Egg shape wrong: price=%d (want 100, per-unit) quantity=%d (want 4) modifier_type=%q (want add)",
			egg.Price, egg.Quantity, egg.ModifierType)
	}

	noOnion, ok := byLabel["No onion"]
	if !ok {
		t.Fatalf("'No onion' remove extra dropped — must be forwarded, not filtered: %+v", extras)
	}
	if noOnion.ModifierType != "remove" {
		t.Errorf("'No onion' modifier_type wrong: %q (want remove)", noOnion.ModifierType)
	}
	if noOnion.Quantity != 1 {
		t.Errorf("'No onion' quantity wrong: %d (want 1)", noOnion.Quantity)
	}
}

// TestKioskOrdersPaidAmountFromNonFailedPayments proves that re-entering a
// partially-paid (still open) order reports the sum of non-failed local
// payments — not the orders.paid_amount column (which stays 0 until full
// close). Failed payments are excluded.
func TestKioskOrdersPaidAmountFromNonFailedPayments(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	seedKioskOrder(t, db) // ord-1, totals zeroed, no payments yet

	pays := []string{
		`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('pay-1', 'ord-1', 'cash', 5000, 'confirmed', 'k1')`,
		`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('pay-2', 'ord-1', 'qr',   1000, 'pending',   'k2')`,
		`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('pay-3', 'ord-1', 'card', 2000, 'failed',    'k3')`,
	}
	for _, q := range pays {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed payment: %v", err)
		}
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/kiosk/orders?table_id=t-9", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	var resp struct {
		Data struct {
			PaidAmount int `json:"paid_amount"`
		} `json:"data"`
	}
	json.NewDecoder(rec.Body).Decode(&resp)
	// 5000 (confirmed) + 1000 (pending) = 6000; 2000 (failed) excluded.
	if resp.Data.PaidAmount != 6000 {
		t.Errorf("paid_amount = %d, want 6000 (confirmed+pending, failed excluded)", resp.Data.PaidAmount)
	}
}

func TestKioskOrdersByCodeAndNotFound(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	seedKioskOrder(t, db)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// by code → resolves the same single order as a top-level object
	req := httptest.NewRequest("GET", "/api/v1/kiosk/orders?code=ORD-2026-4240", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("by-code expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	var resp struct {
		Data struct {
			ID string `json:"id"`
		} `json:"data"`
	}
	json.NewDecoder(rec.Body).Decode(&resp)
	if resp.Data.ID != "ord-1" {
		t.Errorf("by-code wrong order: %+v", resp.Data)
	}

	// unknown code → 404 (not an empty array)
	req2 := httptest.NewRequest("GET", "/api/v1/kiosk/orders?code=ORD-NOPE", nil)
	req2.Header.Set("Authorization", "Bearer kiosk-token")
	rec2 := httptest.NewRecorder()
	mux.ServeHTTP(rec2, req2)
	if rec2.Code != http.StatusNotFound {
		t.Errorf("unknown code expected 404, got %d", rec2.Code)
	}

	// no filter → 400
	req3 := httptest.NewRequest("GET", "/api/v1/kiosk/orders", nil)
	req3.Header.Set("Authorization", "Bearer kiosk-token")
	rec3 := httptest.NewRecorder()
	mux.ServeHTTP(rec3, req3)
	if rec3.Code != http.StatusBadRequest {
		t.Errorf("no filter expected 400, got %d", rec3.Code)
	}
}

// TestKioskUnimplementedRoutesProxyToCloud proves the kiosk/customer catch-all
// forwards routes the workstation doesn't serve locally (split-by-items/preview,
// customer/qr/{token}, kiosk/audit-logs) to Cloud and returns Cloud's JSON —
// rather than letting them fall through to the SPA handler (the "Unexpected
// character: <" bug).
// TestKioskOrdersBranchScope proves an order belonging to a different branch
// is never returned, even if it somehow lands in the local replica.
func TestKioskOrdersBranchScope(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL) // seeds workstation_branch_id = 'branch-A'
	s.orders = service.NewOrderEngine(db)

	// Active order on table t-7 but owned by branch-OTHER.
	stmts := []string{
		`INSERT INTO tables (id, name, status) VALUES ('t-7', 'Table 7', 'occupied')`,
		`INSERT INTO orders (id, order_number, order_code, status, table_id, branch_id)
		 VALUES ('ord-x', 9, 'ORD-OTHER-1', 'pending', 't-7', 'branch-OTHER')`,
	}
	for _, q := range stmts {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	for _, qs := range []string{"table_id=t-7", "code=ORD-OTHER-1"} {
		req := httptest.NewRequest("GET", "/api/v1/kiosk/orders?"+qs, nil)
		req.Header.Set("Authorization", "Bearer kiosk-token")
		rec := httptest.NewRecorder()
		mux.ServeHTTP(rec, req)
		if rec.Code != http.StatusNotFound {
			t.Errorf("%s: foreign-branch order must 404, got %d body=%s", qs, rec.Code, rec.Body.String())
		}
	}
}

// TestPartialPaymentKeepsOrderOpen reproduces the reported bug: pay one part,
// leave, come back → order must still be there with the partial paid_amount,
// NOT reverted to a full unpaid bill. Confirming a partial payment must not
// close the order; only a full payment closes it.
func TestPartialPaymentKeepsOrderOpen(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	seedKioskOrder(t, db) // ord-1 on t-9, items → total 8993 (10% tax, 5% svc)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	pay := func(amount int, idem string) string {
		body, _ := json.Marshal(map[string]any{
			"order_id": "ord-1", "payment_method": "cash", "amount": amount,
		})
		req := httptest.NewRequest("POST", "/api/v1/kiosk/payments", bytes.NewReader(body))
		req.Header.Set("Authorization", "Bearer kiosk-token")
		req.Header.Set("Idempotency-Key", idem)
		rec := httptest.NewRecorder()
		mux.ServeHTTP(rec, req)
		if rec.Code != http.StatusCreated {
			t.Fatalf("create payment: %d body=%s", rec.Code, rec.Body.String())
		}
		var pr struct {
			Data struct {
				// W-PAY4 contract: cash auto-confirms on create, so this payment is
				// already CONFIRMED; the redundant confirm() below proves that
				// re-confirming is idempotent (no double-count of paid_amount).
				ID string `json:"payment_id"`
			} `json:"data"`
		}
		json.NewDecoder(rec.Body).Decode(&pr)
		return pr.Data.ID
	}
	confirm := func(id string) {
		req := httptest.NewRequest("POST", "/api/v1/kiosk/payments/"+id+"/confirm", nil)
		req.Header.Set("Authorization", "Bearer kiosk-token")
		rec := httptest.NewRecorder()
		mux.ServeHTTP(rec, req)
		if rec.Code != http.StatusOK {
			t.Fatalf("confirm %s: %d body=%s", id, rec.Code, rec.Body.String())
		}
	}
	getByTable := func() *httptest.ResponseRecorder {
		req := httptest.NewRequest("GET", "/api/v1/kiosk/orders?table_id=t-9", nil)
		req.Header.Set("Authorization", "Bearer kiosk-token")
		rec := httptest.NewRecorder()
		mux.ServeHTTP(rec, req)
		return rec
	}

	// Pay 4000 of 8993, confirm → order MUST stay open.
	confirm(pay(4000, "idem-part-1"))

	var status string
	db.QueryRow(`SELECT status FROM orders WHERE id='ord-1'`).Scan(&status)
	if status == "closed" {
		t.Fatalf("order closed after partial payment — would force re-pay full")
	}

	rec := getByTable()
	if rec.Code != http.StatusOK {
		t.Fatalf("re-enter order after partial: expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	var resp struct {
		Data struct {
			PaidAmount int `json:"paid_amount"`
			Total      int `json:"total"`
		} `json:"data"`
	}
	json.NewDecoder(rec.Body).Decode(&resp)
	if resp.Data.PaidAmount != 4000 {
		t.Errorf("paid_amount = %d after partial, want 4000", resp.Data.PaidAmount)
	}
	if resp.Data.Total != 8993 {
		t.Errorf("total = %d, want 8993", resp.Data.Total)
	}

	// Pay the remaining 4993 → fully paid → order closes, drops out of kiosk.
	confirm(pay(4993, "idem-part-2"))
	db.QueryRow(`SELECT status FROM orders WHERE id='ord-1'`).Scan(&status)
	if status != "closed" {
		t.Errorf("order status = %q after full payment, want closed", status)
	}
	if rec := getByTable(); rec.Code != http.StatusNotFound {
		t.Errorf("fully-paid order should drop out of kiosk (404), got %d", rec.Code)
	}
}

// paymentResultEnvelope is the W-PAY4 kiosk PaymentResult shape.
type paymentResultEnvelope struct {
	Data struct {
		PaymentID   string `json:"payment_id"`
		ReferenceNo string `json:"reference_no"`
		Status      string `json:"status"`
		ConfirmType string `json:"confirm_type"`
		Method      string `json:"method"`
		AmountPaid  int    `json:"amount_paid"`
	} `json:"data"`
}

func postKioskPayment(t *testing.T, mux http.Handler, method string, amount int, idem string) *httptest.ResponseRecorder {
	t.Helper()
	body, _ := json.Marshal(map[string]any{
		"order_id": "ord-1", "payment_method": method, "amount": amount,
	})
	req := httptest.NewRequest("POST", "/api/v1/kiosk/payments", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer kiosk-token")
	req.Header.Set("Idempotency-Key", idem)
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)
	return rec
}

// TestCreatePayment_CashAutoConfirms covers W-PAY1/2/3/4: a cash tender settles
// the moment it's recorded — the create response is the PaymentResult contract
// (named fields, status=succeeded, confirm_type=auto, a PAY- reference) and the
// order's paid_amount advances WITHOUT a separate /confirm round-trip.
func TestCreatePayment_CashAutoConfirms(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	seedKioskOrder(t, db) // ord-1, total 8993

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	rec := postKioskPayment(t, mux, "cash", 4000, "idem-cash-1")
	if rec.Code != http.StatusCreated {
		t.Fatalf("create cash payment: %d body=%s", rec.Code, rec.Body.String())
	}
	var pr paymentResultEnvelope
	json.NewDecoder(rec.Body).Decode(&pr)

	if pr.Data.PaymentID == "" {
		t.Error("missing payment_id")
	}
	if !strings.HasPrefix(pr.Data.ReferenceNo, "PAY-") {
		t.Errorf("reference_no = %q, want PAY- prefix", pr.Data.ReferenceNo)
	}
	if pr.Data.Status != "succeeded" {
		t.Errorf("status = %q, want succeeded (cash auto-confirms)", pr.Data.Status)
	}
	if pr.Data.ConfirmType != "auto" {
		t.Errorf("confirm_type = %q, want auto", pr.Data.ConfirmType)
	}
	if pr.Data.Method != "cash" || pr.Data.AmountPaid != 4000 {
		t.Errorf("method/amount_paid = %q/%d, want cash/4000", pr.Data.Method, pr.Data.AmountPaid)
	}

	// Order advanced without a confirm call; partial keeps it open.
	var status string
	var paid int
	db.QueryRow(`SELECT status, COALESCE(paid_amount,0) FROM orders WHERE id='ord-1'`).Scan(&status, &paid)
	if paid != 4000 {
		t.Errorf("order paid_amount = %d after auto-confirm, want 4000", paid)
	}
	if status == "closed" {
		t.Error("partial cash payment must not close the order")
	}

	// The local payment row is born SUCCEEDED (#1120 — no new 'confirmed' rows).
	var pstatus string
	db.QueryRow(`SELECT status FROM payments WHERE id=?`, pr.Data.PaymentID).Scan(&pstatus)
	if pstatus != "succeeded" {
		t.Errorf("payment status = %q, want succeeded", pstatus)
	}
}

// TestCreatePayment_CardStaysPending covers W-PAY1: a terminal-backed tender is
// born PENDING (confirm_type=manual) and does NOT touch the order until /confirm.
func TestCreatePayment_CardStaysPending(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	seedKioskOrder(t, db)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	rec := postKioskPayment(t, mux, "card", 8993, "idem-card-1")
	if rec.Code != http.StatusCreated {
		t.Fatalf("create card payment: %d body=%s", rec.Code, rec.Body.String())
	}
	var pr paymentResultEnvelope
	json.NewDecoder(rec.Body).Decode(&pr)

	if pr.Data.Status != "pending" {
		t.Errorf("status = %q, want pending (card awaits terminal)", pr.Data.Status)
	}
	if pr.Data.ConfirmType != "manual" {
		t.Errorf("confirm_type = %q, want manual", pr.Data.ConfirmType)
	}

	var paid int
	db.QueryRow(`SELECT COALESCE(paid_amount,0) FROM orders WHERE id='ord-1'`).Scan(&paid)
	if paid != 0 {
		t.Errorf("order paid_amount = %d, want 0 (card not yet confirmed)", paid)
	}
}

// TestConfirmPaymentIdempotent_NoDuplicate is the regression for the duplicate
// printed bill: a cash payment auto-confirms on create (enqueuing create +
// confirm), but the kiosk still POSTs /confirm. The second confirm must be a
// no-op — no extra sync_queue confirm row (and, in production, no second bill).
func TestConfirmPaymentIdempotent_NoDuplicate(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	seedKioskOrder(t, db)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	rec := postKioskPayment(t, mux, "cash", 4000, "idem-dup-1")
	if rec.Code != http.StatusCreated {
		t.Fatalf("create: %d body=%s", rec.Code, rec.Body.String())
	}
	var pr paymentResultEnvelope
	json.NewDecoder(rec.Body).Decode(&pr)

	confirmCount := func() int {
		var n int
		db.QueryRow(`SELECT COUNT(*) FROM sync_queue WHERE entity_type='payment' AND operation='confirm' AND entity_id=?`, pr.Data.PaymentID).Scan(&n)
		return n
	}
	before := confirmCount() // auto-confirm enqueued exactly one confirm

	// Kiosk redundantly confirms the already-confirmed cash payment.
	req := httptest.NewRequest("POST", "/api/v1/kiosk/payments/"+pr.Data.PaymentID+"/confirm", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec2 := httptest.NewRecorder()
	mux.ServeHTTP(rec2, req)
	if rec2.Code != http.StatusOK {
		t.Fatalf("redundant confirm: %d body=%s", rec2.Code, rec2.Body.String())
	}
	if after := confirmCount(); after != before {
		t.Errorf("redundant confirm enqueued another sync row: before=%d after=%d (duplicate bill)", before, after)
	}
}

// TestKioskOrderSiblingAwarePaidAndRemaining reproduces the "đi đơn khác rồi
// quay lại bị revert" bug: the pull-down re-imports a WS-origin order as a
// sibling row keyed by the cloud uuid, and the kiosk re-read can land on the
// paymentless sibling. The shape must aggregate paid_amount + claimed units
// across the whole order family (linked by cloud_id), and expose
// remaining_amount + per-item paid/remaining units.
func TestKioskOrderSiblingAwarePaidAndRemaining(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)

	stmts := []string{
		`INSERT INTO shop_settings (key, value) VALUES ('tax_rate', '10.00')`,
		`INSERT INTO shop_settings (key, value) VALUES ('service_charge_rate', '5.00')`,
		`INSERT INTO tables (id, name, status) VALUES ('t-9', 'Table 9', 'occupied')`,
		`INSERT INTO pos_products (id, name) VALUES ('prod-1', 'Regular')`,
		`INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES ('sku-1', 'prod-1', 'L', 1870)`,
		`INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES ('sku-2', 'prod-1', 'XL', 2210)`,
		// The order the kiosk reads (WS-origin local row, carries cloud_id).
		`INSERT INTO orders (id, order_number, order_code, status, table_id, cloud_id,
			subtotal, total_amount, paid_amount) VALUES ('local-A', 1, 'ORD-A', 'pending', 't-9', 'cloud-A', 0, 0, 0)`,
		// The pulled sibling: same cloud_id, NO table_id (so ListActive returns local-A).
		`INSERT INTO orders (id, order_number, order_code, status, cloud_id,
			subtotal, total_amount, paid_amount) VALUES ('cloud-A', 1, 'ORD-A', 'pending', 'cloud-A', 0, 0, 0)`,
		`INSERT INTO order_items (id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, printer_group, status, print_status, topping_subtotal, tax_rate)
			VALUES ('it-1', 'local-A', 'sku-1', '', 3, 1870, 5610, 'kitchen', 'pending', 'pending', 0, 10)`,
		`INSERT INTO order_items (id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, printer_group, status, print_status, topping_subtotal, tax_rate)
			VALUES ('it-2', 'local-A', 'sku-2', '', 1, 2210, 2210, 'kitchen', 'pending', 'pending', 0, 10)`,
		// Payment recorded against the SIBLING (cloud-A), claiming 2 units of it-1.
		`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, metadata)
			VALUES ('pay-1', 'cloud-A', 'cash', 4000, 'confirmed', 'ik-1', '{"item_allocations":[{"item_id":"it-1","units":2}]}')`,
	}
	for _, q := range stmts {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed (%s): %v", q, err)
		}
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)
	req := httptest.NewRequest("GET", "/api/v1/kiosk/orders?table_id=t-9", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	var resp struct {
		Data struct {
			ID              string `json:"id"`
			Total           int    `json:"total"`
			PaidAmount      int    `json:"paid_amount"`
			RemainingAmount int    `json:"remaining_amount"`
			Items           []struct {
				ID             string `json:"id"`
				Quantity       int    `json:"quantity"`
				PaidUnits      int    `json:"paid_units"`
				RemainingUnits int    `json:"remaining_units"`
			} `json:"items"`
		} `json:"data"`
	}
	json.NewDecoder(rec.Body).Decode(&resp)
	d := resp.Data

	if d.ID != "local-A" {
		t.Fatalf("expected to read local-A, got %q", d.ID)
	}
	// Payment lives on the sibling but must be counted via cloud_id linkage.
	if d.Total != 8993 || d.PaidAmount != 4000 || d.RemainingAmount != 4993 {
		t.Errorf("amounts wrong: total=%d paid=%d remaining=%d (want 8993/4000/4993)", d.Total, d.PaidAmount, d.RemainingAmount)
	}
	var it1 *struct {
		ID             string `json:"id"`
		Quantity       int    `json:"quantity"`
		PaidUnits      int    `json:"paid_units"`
		RemainingUnits int    `json:"remaining_units"`
	}
	for i := range d.Items {
		if d.Items[i].ID == "it-1" {
			it1 = &d.Items[i]
		}
	}
	if it1 == nil {
		t.Fatalf("it-1 missing from response")
	}
	if it1.PaidUnits != 2 || it1.RemainingUnits != 1 {
		t.Errorf("it-1 units wrong: paid=%d remaining=%d (want 2/1)", it1.PaidUnits, it1.RemainingUnits)
	}
}

// TestKioskOrdersOnlineProxiesUnlessPaymentPending verifies the two-case
// routing: online + clean → proxy to Cloud; online + a payment still queued →
// serve local so the just-made payment isn't lost to a lagging Cloud read.
func TestKioskOrdersOnlineProxiesUnlessPaymentPending(t *testing.T) {
	var hitCloud bool
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/api/v1/kiosk/orders" {
			hitCloud = true
			w.Write([]byte(`{"data":{"id":"from-cloud"}}`))
			return
		}
		// Any other path (incl. the auth verify endpoint) → a valid kiosk device.
		w.Write([]byte(`{"data":{"id":"kiosk-1","type":"kiosk","branch_id":"branch-A","status":"active"}}`))
	}))
	t.Cleanup(cloud.Close)

	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	s.cloudReachable = func() bool { return true } // force online
	seedKioskOrder(t, db)                          // local ord-1 on t-9

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)
	get := func() *httptest.ResponseRecorder {
		req := httptest.NewRequest("GET", "/api/v1/kiosk/orders?table_id=t-9", nil)
		req.Header.Set("Authorization", "Bearer kiosk-token")
		rec := httptest.NewRecorder()
		mux.ServeHTTP(rec, req)
		return rec
	}

	// Online + no pending payment → proxied to Cloud.
	rec := get()
	if !hitCloud {
		t.Fatalf("online + clean should proxy to Cloud, but didn't (code=%d body=%s)", rec.Code, rec.Body.String())
	}
	var cloudResp struct {
		Data struct {
			ID string `json:"id"`
		} `json:"data"`
	}
	json.NewDecoder(rec.Body).Decode(&cloudResp)
	if cloudResp.Data.ID != "from-cloud" {
		t.Errorf("expected Cloud response, got %+v", cloudResp.Data)
	}

	// A payment write is now queued (un-pushed) → must serve LOCAL despite online.
	if err := s.sync.Enqueue("payment", "pay-x", "create", map[string]any{"order_id": "ord-1"}, 1); err != nil {
		t.Fatalf("seed queue: %v", err)
	}
	hitCloud = false
	rec2 := get()
	if hitCloud {
		t.Errorf("pending payment should force LOCAL read, but proxied to Cloud")
	}
	var localResp struct {
		Data struct {
			ID string `json:"id"`
		} `json:"data"`
	}
	json.NewDecoder(rec2.Body).Decode(&localResp)
	if localResp.Data.ID != "ord-1" {
		t.Errorf("expected local ord-1, got %+v", localResp.Data)
	}
}

func TestKioskUnimplementedRoutesProxyToCloud(t *testing.T) {
	var gotPath, gotMethod string
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotMethod = r.URL.Path, r.Method
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"proxied":true}}`))
	}))
	t.Cleanup(cloud.Close)

	s, _ := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	cases := []struct{ method, path string }{
		{"POST", "/api/v1/kiosk/audit-logs"},
		{"POST", "/api/v1/customer/qr/tok-123"},
		{"POST", "/api/v1/kiosk/orders/ord-1/split-by-items/preview"},
	}
	for _, c := range cases {
		gotPath, gotMethod = "", ""
		req := httptest.NewRequest(c.method, c.path, nil)
		req.Header.Set("Authorization", "Bearer kiosk-token")
		rec := httptest.NewRecorder()
		mux.ServeHTTP(rec, req)

		if rec.Code != http.StatusOK {
			t.Errorf("%s %s: expected 200 from proxy, got %d body=%s", c.method, c.path, rec.Code, rec.Body.String())
		}
		if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
			t.Errorf("%s %s: expected JSON content-type, got %q", c.method, c.path, ct)
		}
		// Cloud saw the request verbatim (path preserved, not rewritten to SPA).
		if gotPath != c.path || gotMethod != c.method {
			t.Errorf("%s %s: cloud got %s %s", c.method, c.path, gotMethod, gotPath)
		}
	}
}

func TestTMSListZones(t *testing.T) {
	// /tms/* is tms-device-only (Phase 2). Authenticate with a tms device token.
	cloud := mockDeviceMeCloud(t, "tms", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	db.Exec(`INSERT INTO zones (id, name, sort_order) VALUES ('z-1', '1F', 1)`)
	db.Exec(`INSERT INTO zones (id, name, sort_order) VALUES ('z-2', 'Patio', 2)`)

	req := httptest.NewRequest("GET", "/api/v1/tms/zones", nil)
	req.Header.Set("Authorization", "Bearer tms-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", rec.Code)
	}

	var resp struct {
		Data []struct {
			ID   string `json:"id"`
			Name string `json:"name"`
		} `json:"data"`
	}
	json.NewDecoder(rec.Body).Decode(&resp)
	if len(resp.Data) != 2 {
		t.Fatalf("expected 2 zones, got %d", len(resp.Data))
	}
	if resp.Data[0].ID != "z-1" {
		t.Errorf("expected 1F first by sort_order, got %s", resp.Data[0].ID)
	}
}

// TestResolveItemDisplay covers the W1 name resolver fallback chain + the
// product_name / sku_code parity fields across every data shape WS sees.
func TestResolveItemDisplay(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)

	seed := []string{
		// Fully-catalogued món: product name + variant + sku code.
		`INSERT INTO pos_products (id, name) VALUES ('p-cafe', 'Ca phe sua')`,
		`INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sku-cafe', 'p-cafe', 'L', 'PV-1234-AB', 4500)`,
		// Catalogued sku but product has NO name in any synced locale — only sku code.
		`INSERT INTO pos_products (id, name) VALUES ('p-noname', '')`,
		`INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sku-noname', 'p-noname', '', 'PV-9999-ZZ', 1000)`,
	}
	for _, q := range seed {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed (%s): %v", q, err)
		}
	}

	cases := []struct {
		name        string
		item        service.Item
		wantName    string
		wantProduct string
		wantSku     string
	}{
		{
			name:        "catalog hit → product.name + parity fields",
			item:        service.Item{ProductSkuID: "sku-cafe"},
			wantName:    "Ca phe sua",
			wantProduct: "Ca phe sua",
			wantSku:     "PV-1234-AB",
		},
		{
			name:        "snapshot wins over catalog for name",
			item:        service.Item{ProductSkuID: "sku-cafe", MenuItemName: "Ca phe (luc mua)"},
			wantName:    "Ca phe (luc mua)",
			wantProduct: "Ca phe sua", // product_name still the live catalog product
			wantSku:     "PV-1234-AB",
		},
		{
			name:        "no product name in any locale → falls back to sku_code",
			item:        service.Item{ProductSkuID: "sku-noname"},
			wantName:    "PV-9999-ZZ",
			wantProduct: "",
			wantSku:     "PV-9999-ZZ",
		},
		{
			name:        "nothing resolvable → (unknown)",
			item:        service.Item{ProductSkuID: "ghost-sku"},
			wantName:    "(unknown)",
			wantProduct: "",
			wantSku:     "",
		},
		{
			name:        "ad-hoc item (no sku) uses snapshot",
			item:        service.Item{MenuItemName: "Mon tu do"},
			wantName:    "Mon tu do",
			wantProduct: "",
			wantSku:     "",
		},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			name, productName, skuCode, _ := s.resolveItemDisplay(c.item)
			if name != c.wantName {
				t.Errorf("name = %q, want %q", name, c.wantName)
			}
			if productName != c.wantProduct {
				t.Errorf("product_name = %q, want %q", productName, c.wantProduct)
			}
			if skuCode != c.wantSku {
				t.Errorf("sku_code = %q, want %q", skuCode, c.wantSku)
			}
		})
	}
}

// TestHealKioskOrderNames force-pulls an order from Cloud when a món would
// render "(unknown)" locally (blank name + SKU absent from the local catalog),
// then re-reads it with the Cloud-resolved name — pos-web parity for the kiosk
// bill. Cloud nests product_sku.product so SyncPuller freezes the món name.
func TestHealKioskOrderNames(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/orders" {
			http.Error(w, "unexpected path "+r.URL.Path, http.StatusNotFound)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":[
			{"id":"ord-heal","order_code":"O-HEAL","order_type":"dine_in","status":"open",
			 "opened_at":"2026-06-19T10:00:00Z","updated_at":"2026-06-19T10:00:00Z",
			 "branch_id":"br-1","brand_id":"bd-1","organization_id":"org-1",
			 "items":[{"id":"it-heal","product_sku_id":"sku-ghost",
			           "quantity":"1","unit_price":"50000","subtotal":"50000",
			           "status":"pending","updated_at":"2026-06-19T10:00:00Z",
			           "product_sku":{"name":"L","sku":"PV-7","product":{"name":"Tra sua"}}}]}
		]}`))
	}))
	defer cloud.Close()

	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	s.puller = service.NewSyncPuller(db, cloud.URL, s.GetDeviceToken)
	s.cloudReachable = func() bool { return true }
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token', ?)`, "WS-TOKEN"); err != nil {
		t.Fatalf("seed token: %v", err)
	}

	// Pre-seed the order locally with a BLANK món name + a SKU missing from
	// the local catalog → resolveItemDisplay would return "(unknown)".
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, order_type, status, opened_at, branch_id, brand_id, organization_id, created_at, updated_at)
		VALUES ('ord-heal','O-HEAL','dine_in','open','2026-06-19T10:00:00Z','br-1','bd-1','org-1','2026-06-19T10:00:00Z','2026-06-19T10:00:00Z')`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO order_items (id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, status, printer_group, created_at, updated_at)
		VALUES ('it-heal','ord-heal','sku-ghost','', 1, 50000, 50000, 'pending', 'kitchen', '2026-06-19T10:00:00Z','2026-06-19T10:00:00Z')`); err != nil {
		t.Fatalf("seed item: %v", err)
	}

	o, err := s.orders.GetByID("ord-heal")
	if err != nil || o == nil {
		t.Fatalf("load order: %v", err)
	}
	if name, _, _, _ := s.resolveItemDisplay(o.Items[0]); name != "(unknown)" {
		t.Fatalf("precondition: want (unknown), got %q", name)
	}

	healed := s.healKioskOrderNames(httptest.NewRequest("GET", "/api/v1/kiosk/orders", nil), o)
	if healed == nil {
		t.Fatal("expected order to be healed (reloaded), got nil")
	}
	if name, _, _, _ := s.resolveItemDisplay(healed.Items[0]); name != "Tra sua · L" {
		t.Errorf("after heal: name = %q, want %q", name, "Tra sua · L")
	}
}

// TestKioskOrderItemShape_ParityFields confirms the kiosk item shape emits the
// Cloud-parity fields (name / product_name / sku_code) populated from catalog.
func TestKioskOrderItemShape_ParityFields(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	if _, err := db.Exec(`INSERT INTO pos_products (id, name) VALUES ('p1', 'Tra dao')`); err != nil {
		t.Fatalf("seed product: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('s1', 'p1', 'M', 'TD-001', 3000)`); err != nil {
		t.Fatalf("seed sku: %v", err)
	}

	out := s.kioskOrderItemShape(service.Item{ID: "it-1", ProductSkuID: "s1", Quantity: 2, UnitPrice: 3000}, 0)
	if out["name"] != "Tra dao" {
		t.Errorf("name = %v, want Tra dao", out["name"])
	}
	if out["product_name"] != "Tra dao" {
		t.Errorf("product_name = %v, want Tra dao", out["product_name"])
	}
	if out["sku_code"] != "TD-001" {
		t.Errorf("sku_code = %v, want TD-001", out["sku_code"])
	}
}

// TestCreatePayment_RejectsOverpay is the #551 regression for the missing
// overpay guard on the kiosk-LAN create path. Order total is 8993; a first
// 8000 payment leaves 993 outstanding, so a second 2000 payment (Σ 10000 > 8993)
// must be rejected 422 — never inserted, never synced. The exact-outstanding
// closing payment (993) must still be accepted (boundary: Σ == total is not over).
func TestCreatePayment_RejectsOverpay(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	seedKioskOrder(t, db) // ord-1, items → total 8993

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// First payment: 8000 of 8993 → accepted (cash auto-confirms).
	if rec := postKioskPayment(t, mux, "cash", 8000, "idem-op-1"); rec.Code != http.StatusCreated {
		t.Fatalf("first payment 8000: expected 201, got %d body=%s", rec.Code, rec.Body.String())
	}

	// Second payment: 2000 → Σ 10000 exceeds 8993 → 422, no row, no sync.
	rec := postKioskPayment(t, mux, "cash", 2000, "idem-op-2")
	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("overpay 2000: expected 422, got %d body=%s", rec.Code, rec.Body.String())
	}
	var n int
	db.QueryRow(`SELECT COUNT(*) FROM payments WHERE idempotency_key = 'idem-op-2'`).Scan(&n)
	if n != 0 {
		t.Errorf("rejected overpay must not insert a payment row, got %d", n)
	}
	// paid_amount must still reflect only the accepted 8000, never the overpay.
	var paid int
	db.QueryRow(`SELECT COALESCE(paid_amount,0) FROM orders WHERE id='ord-1'`).Scan(&paid)
	if paid != 8000 {
		t.Errorf("order paid_amount = %d after rejected overpay, want 8000", paid)
	}

	// Exact-outstanding closing payment (993) lands at the Σ == total boundary.
	if rec := postKioskPayment(t, mux, "cash", 993, "idem-op-3"); rec.Code != http.StatusCreated {
		t.Fatalf("closing payment 993: expected 201, got %d body=%s", rec.Code, rec.Body.String())
	}
	var status string
	db.QueryRow(`SELECT status FROM orders WHERE id='ord-1'`).Scan(&status)
	if status != "closed" {
		t.Errorf("order status = %q after full payment, want closed", status)
	}
}

// TestCreatePayment_RejectsClosedOrder is the #551 regression for the missing
// status gate: a fully-paid, closed order must reject a re-scanned QR payment
// (409) instead of minting a second real charge.
func TestCreatePayment_RejectsClosedOrder(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	if _, err := db.Exec(
		`INSERT INTO orders (id, order_number, status, total_amount, paid_amount) VALUES ('o-closed', 1, 'closed', 1000, 1000)`,
	); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	body, _ := json.Marshal(map[string]any{
		"order_id": "o-closed", "payment_method": "card", "amount": 1000,
	})
	req := httptest.NewRequest("POST", "/api/v1/kiosk/payments", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer kiosk-token")
	req.Header.Set("Idempotency-Key", "idem-closed-1")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusConflict {
		t.Fatalf("payment on closed order: expected 409, got %d body=%s", rec.Code, rec.Body.String())
	}
	var n int
	db.QueryRow(`SELECT COUNT(*) FROM payments WHERE idempotency_key = 'idem-closed-1'`).Scan(&n)
	if n != 0 {
		t.Errorf("payment on closed order must not insert a row, got %d", n)
	}
}

// TestConfirmPayment_LegacyConfirmedRowIsIdempotent (#1120): a row written as
// 'confirmed' by a pre-#1120 build (racing migration 058 via a frozen replay)
// counts as already captured — re-confirming it must return 200 with the row
// untouched: no status rewrite, no second confirm op enqueued, no order
// paid_amount re-apply (the duplicate-slip guard).
func TestConfirmPayment_LegacyConfirmedRowIsIdempotent(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	seed := []string{
		`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p-legacy', 'o-1', 'cash', 1000, 'confirmed', 'ik-legacy')`,
		`INSERT INTO orders (id, order_number, status, total_amount, paid_amount) VALUES ('o-1', 1, 'closed', 1000, 1000)`,
	}
	for _, q := range seed {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}

	req := httptest.NewRequest("POST", "/api/v1/kiosk/payments/p-legacy/confirm", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200 idempotent replay, got %d body=%s", rec.Code, rec.Body.String())
	}
	var status string
	db.QueryRow("SELECT status FROM payments WHERE id='p-legacy'").Scan(&status)
	if status != "confirmed" {
		t.Errorf("legacy row must be left untouched, got status=%q", status)
	}
	var n int
	db.QueryRow("SELECT COUNT(*) FROM sync_queue WHERE entity_type='payment' AND operation='confirm'").Scan(&n)
	if n != 0 {
		t.Errorf("idempotent replay must not enqueue a confirm op, got %d", n)
	}
}

// TestSumConfirmedPayments_CountsLegacyAndSucceeded (#1120): captured money is
// the union of canonical 'succeeded' and legacy 'confirmed' rows — a mixed
// order (one row from each era) must read as fully paid.
func TestSumConfirmedPayments_CountsLegacyAndSucceeded(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)

	seed := []string{
		`INSERT INTO orders (id, order_number, status, total_amount) VALUES ('o-mix', 1, 'open', 3000)`,
		`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p-old', 'o-mix', 'cash', 1000, 'confirmed', 'ik-1')`,
		`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p-new', 'o-mix', 'cash', 2000, 'succeeded', 'ik-2')`,
		`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p-bad', 'o-mix', 'cash',  500, 'failed',    'ik-3')`,
	}
	for _, q := range seed {
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}

	if got := s.sumConfirmedPayments("o-mix"); got != 3000 {
		t.Errorf("sumConfirmedPayments = %d, want 3000 (1000 legacy + 2000 canonical, failed excluded)", got)
	}
}
