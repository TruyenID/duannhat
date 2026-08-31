package service

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// PullOrderNow happy path — Cloud responds with a single order, workstation
// upserts it into local SQLite, no error returned.
func TestPullOrderNow_HappyPath(t *testing.T) {
	var observedQuery string
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/orders" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		observedQuery = r.URL.RawQuery
		if r.Header.Get("Authorization") != "Bearer WS-TOKEN" {
			t.Errorf("wrong auth header: %q", r.Header.Get("Authorization"))
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":[
			{"id":"ord-1","order_code":"O-1","order_type":"dine_in","status":"open",
			 "opened_at":"2026-06-19T10:00:00Z","updated_at":"2026-06-19T10:00:00Z",
			 "branch_id":"br-1","brand_id":"bd-1","organization_id":"org-1",
			 "items":[{"id":"it-1","product_sku_id":"sku-1","menu_item_name":"Phở",
			           "quantity":"2","unit_price":"50000","subtotal":"100000",
			           "status":"pending","updated_at":"2026-06-19T10:00:00Z"}]}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullOrderNow(context.Background(), "ord-1"); err != nil {
		t.Fatalf("PullOrderNow: %v", err)
	}

	if observedQuery != "id=ord-1" {
		t.Errorf("expected query 'id=ord-1', got %q", observedQuery)
	}

	var status string
	db.QueryRow("SELECT status FROM orders WHERE id = 'ord-1'").Scan(&status)
	if status != "open" {
		t.Errorf("expected upserted order status=open, got %q", status)
	}

	var itemCount int
	db.QueryRow("SELECT COUNT(*) FROM order_items WHERE customer_order_id = 'ord-1'").Scan(&itemCount)
	if itemCount != 1 {
		t.Errorf("expected 1 item upserted, got %d", itemCount)
	}
}

// Cloud never serializes menu_item_name on /workstation/orders (it's a model
// $appends accessor the Resource drops); the món name arrives only inside the
// nested `product_sku` object (product.name + variant), exactly what pos-web
// reads. The workstation must freeze the name from that nested object instead of
// falling through to "(unknown)" when the SKU isn't in the local catalog mirror.
func TestPullOrderNow_ResolvesNameFromNestedProductSku(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":[
			{"id":"ord-ns","order_code":"O-NS","order_type":"dine_in","status":"open",
			 "opened_at":"2026-06-19T10:00:00Z","updated_at":"2026-06-19T10:00:00Z",
			 "branch_id":"br-1","brand_id":"bd-1","organization_id":"org-1",
			 "items":[{"id":"it-ns","product_sku_id":"sku-uncatalogued",
			           "quantity":"1","unit_price":"50000","subtotal":"50000",
			           "status":"pending","updated_at":"2026-06-19T10:00:00Z",
			           "product_sku":{"name":"Lon","sku":"PV-1","product":{"name":"Coca"}}}]}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullOrderNow(context.Background(), "ord-ns"); err != nil {
		t.Fatalf("PullOrderNow: %v", err)
	}

	var name string
	db.QueryRow("SELECT menu_item_name FROM order_items WHERE id = 'it-ns'").Scan(&name)
	if name != "Coca · Lon" {
		t.Errorf("expected name from nested product_sku 'Coca · Lon', got %q", name)
	}
}

// A blank-name row already stored (synced before this fix) is backfilled on the
// next sync once a name resolves — re-sync must heal the "(unknown)" line.
func TestPullOrderNow_BackfillsBlankNameOnResync(t *testing.T) {
	db := newPullerTestDB(t)
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, order_type, status, opened_at, branch_id, brand_id, organization_id, created_at, updated_at)
		VALUES ('ord-bf','O-BF','dine_in','open','2026-06-19T10:00:00Z','br-1','bd-1','org-1','2026-06-19T10:00:00Z','2026-06-19T10:00:00Z')`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO order_items (id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, status, printer_group, created_at, updated_at)
		VALUES ('it-bf','ord-bf','sku-uncatalogued','', 1, 50000, 50000, 'pending', 'kitchen', '2026-06-19T10:00:00Z','2026-06-19T10:00:00Z')`); err != nil {
		t.Fatalf("seed item: %v", err)
	}

	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":[
			{"id":"ord-bf","order_code":"O-BF","order_type":"dine_in","status":"open",
			 "opened_at":"2026-06-19T10:00:00Z","updated_at":"2026-06-19T11:00:00Z",
			 "branch_id":"br-1","brand_id":"bd-1","organization_id":"org-1",
			 "items":[{"id":"it-bf","product_sku_id":"sku-uncatalogued",
			           "quantity":"1","unit_price":"50000","subtotal":"50000",
			           "status":"pending","updated_at":"2026-06-19T11:00:00Z",
			           "product_sku":{"name":"","sku":"PV-1","product":{"name":"Banh Mi"}}}]}
		]}`))
	}))
	defer cloud.Close()

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullOrderNow(context.Background(), "ord-bf"); err != nil {
		t.Fatalf("PullOrderNow: %v", err)
	}

	var name string
	db.QueryRow("SELECT menu_item_name FROM order_items WHERE id = 'it-bf'").Scan(&name)
	if name != "Banh Mi" {
		t.Errorf("expected blank name backfilled to 'Banh Mi' on re-sync, got %q", name)
	}
}

// PullOrderNow returns ErrOrderNotFoundOnCloud when Cloud responds 200 with
// empty data — distinct from a transport error so the handler can map to 404.
func TestPullOrderNow_NotFoundReturnsSentinel(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"data":[]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	err := p.PullOrderNow(context.Background(), "ord-missing")
	if !errors.Is(err, ErrOrderNotFoundOnCloud) {
		t.Errorf("expected ErrOrderNotFoundOnCloud, got %v", err)
	}
}

// PullOrderNow respects the 1.5 s timeout — if Cloud blocks longer, the
// context deadline propagates so the caller knows to return 504.
func TestPullOrderNow_RespectsTimeout(t *testing.T) {
	blocked := make(chan struct{})
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		<-blocked // hold until test finishes
	}))
	defer func() {
		close(blocked)
		cloud.Close()
	}()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	// Override the package-level timeout to keep the test snappy.
	start := time.Now()
	err := p.PullOrderNow(context.Background(), "ord-slow")
	elapsed := time.Since(start)

	if err == nil {
		t.Fatal("expected error from timeout, got nil")
	}
	if elapsed > 2*time.Second {
		t.Errorf("PullOrderNow took %v, expected ≤ 1.5s + transport slack", elapsed)
	}
}

// PullOrderNow surfaces Cloud 5xx as a plain error (not the not-found
// sentinel) so the handler can map to 503.
func TestPullOrderNow_Cloud5xxReturnsError(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "boom", http.StatusInternalServerError)
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	err := p.PullOrderNow(context.Background(), "ord-x")
	if err == nil {
		t.Fatal("expected error on cloud 500, got nil")
	}
	if errors.Is(err, ErrOrderNotFoundOnCloud) {
		t.Errorf("5xx should NOT map to ErrOrderNotFoundOnCloud, got %v", err)
	}
}

// PullOrderNow rejects empty order_id without making any HTTP call.
func TestPullOrderNow_EmptyIDRejected(t *testing.T) {
	called := false
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		called = true
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullOrderNow(context.Background(), ""); err == nil {
		t.Error("expected error for empty order_id, got nil")
	}
	if called {
		t.Error("expected no HTTP call when order_id is empty")
	}
}

// PullOrderNow does NOT advance the periodic puller's customer_orders cursor
// — the on-demand projection is a side-channel that mustn't shift the
// background-loop resume point.
func TestPullOrderNow_DoesNotAdvanceCursor(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"data":[
			{"id":"ord-2","order_code":"O-2","order_type":"dine_in","status":"open",
			 "opened_at":"2030-01-01T00:00:00Z","updated_at":"2030-01-01T00:00:00Z",
			 "branch_id":"br-1","brand_id":"bd-1","organization_id":"org-1","items":[]}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	// Seed an existing cursor — PullOrderNow must NOT overwrite it.
	const seedCursor = "2026-06-19T09:00:00Z"
	if _, err := db.Exec(
		`INSERT INTO settings (key, value) VALUES (?, ?)`,
		settingsCursorKey, seedCursor,
	); err != nil {
		t.Fatalf("seed cursor: %v", err)
	}
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullOrderNow(context.Background(), "ord-2"); err != nil {
		t.Fatalf("PullOrderNow: %v", err)
	}

	var got string
	db.QueryRow(`SELECT value FROM settings WHERE key = ?`, settingsCursorKey).Scan(&got)
	if got != seedCursor {
		t.Errorf("cursor should remain %q, got %q", seedCursor, got)
	}
}
