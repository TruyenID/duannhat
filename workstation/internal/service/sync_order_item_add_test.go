package service

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
)

// TestOrderItemAddSyncForwardsToCloud asserts that an order.item_add queue row
// reads each referenced line's current state (+ toppings) from SQLite and POSTs
// them to /api/v1/workstation/orders/{cloud_id}/items — the sync path that makes
// LAN-mode items appear on shop/HQ order views.
func TestOrderItemAddSyncForwardsToCloud(t *testing.T) {
	var (
		seenPath  string
		seenAuth  string
		seenBody  map[string]any
		callCount int32
	)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&callCount, 1)
		seenPath = r.URL.Path
		seenAuth = r.Header.Get("Authorization")
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"cloud-ord-9"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	// Order already synced → has a cloud_id the item-add push can target.
	if _, err := db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status, opened_at,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, cloud_id, created_at, updated_at
	) VALUES ('local-ord-9', 'ORD-009', 9, 'spot', 'open', datetime('now'),
		0,0,0,0,0,0,0, '','','', 'cloud-ord-9', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	// Two lines: one plain, one carrying a topping.
	if _, err := db.Exec(`INSERT INTO order_items
		(id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, note, status)
		VALUES ('item-1', 'local-ord-9', 'sku-1', 'Phở', 2, 500, 1000, 'ít hành', 'pending')`); err != nil {
		t.Fatalf("seed item-1: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO order_items
		(id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, status)
		VALUES ('item-2', 'local-ord-9', 'sku-2', 'Trà', 1, 300, 400, 'pending')`); err != nil {
		t.Fatalf("seed item-2: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO order_item_toppings
		(id, order_item_id, topping_group_item_id, product_sku_id, quantity, unit_price)
		VALUES ('top-1', 'item-2', 'tgi-1', 'sku-topping', 1, 100)`); err != nil {
		t.Fatalf("seed topping: %v", err)
	}

	payload := map[string]any{
		"bearer_token":    "ws-token-9",
		"idempotency_key": "idem-add-9",
		"order_id":        "local-ord-9",
		"item_ids":        []any{"item-1", "item-2"},
	}
	if err := e.Enqueue("order", "local-ord-9", "item_add", payload, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if atomic.LoadInt32(&callCount) != 1 {
		t.Fatalf("expected 1 Cloud call, got %d", callCount)
	}
	if seenPath != "/api/v1/workstation/orders/cloud-ord-9/items" {
		t.Errorf("expected path .../cloud-ord-9/items, got %q", seenPath)
	}
	if seenAuth != "Bearer ws-token-9" {
		t.Errorf("Authorization wrong: %q", seenAuth)
	}

	items, _ := seenBody["items"].([]any)
	if len(items) != 2 {
		t.Fatalf("expected 2 items in body, got %d (%+v)", len(items), seenBody)
	}
	first, _ := items[0].(map[string]any)
	if first["id"] != "item-1" || first["product_sku_id"] != "sku-1" || first["quantity"].(float64) != 2 {
		t.Errorf("item-1 payload wrong: %+v", first)
	}
	if first["note"] != "ít hành" {
		t.Errorf("expected note carried through, got %+v", first["note"])
	}
	// unit_price must NOT be sent — Cloud resolves it server-side (plan-040 H17).
	if _, ok := first["unit_price"]; ok {
		t.Errorf("unit_price must not be sent (server resolves it): %+v", first)
	}

	second, _ := items[1].(map[string]any)
	toppings, _ := second["toppings"].([]any)
	if len(toppings) != 1 {
		t.Fatalf("expected item-2 to carry 1 topping, got %+v", second)
	}
	top, _ := toppings[0].(map[string]any)
	if top["topping_group_item_id"] != "tgi-1" || top["unit_price"].(float64) != 100 {
		t.Errorf("topping payload wrong: %+v", top)
	}

	// Queue row marked synced.
	var syncedAt string
	db.QueryRow(`SELECT COALESCE(synced_at,'') FROM sync_queue WHERE operation='item_add'`).Scan(&syncedAt)
	if syncedAt == "" {
		t.Error("expected item_add queue row to be marked synced")
	}
}

// TestReconcileBackfillsUnsyncedItems reproduces the ORD-2026-4107 field bug:
// an order that synced (has cloud_id) but whose items were never pushed (no
// item_add was ever enqueued — an older build, or items added at create time).
// The reconciler must enqueue an item_add for it, which then pushes the lines
// to Cloud and stamps them synced so it does not re-fire.
func TestReconcileBackfillsUnsyncedItems(t *testing.T) {
	var (
		callCount int32
		seenPath  string
		seenAuth  string
		seenBody  map[string]any
	)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&callCount, 1)
		seenPath = r.URL.Path
		seenAuth = r.Header.Get("Authorization")
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Write([]byte(`{"data":{"id":"cloud-ord-77"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	// Real settings key is `device_token` (underscore); the reconciler pulls the
	// Bearer via deviceToken(), which must read this exact key.
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token','WS-TOK')`); err != nil {
		t.Fatalf("seed token: %v", err)
	}

	// Order already on Cloud (cloud_id set) — but its items are synced_at NULL
	// and there is NO item_add queued for it.
	if _, err := db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status, opened_at,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, cloud_id, created_at, updated_at
	) VALUES ('local-ord-77', 'ORD-2026-4107', 77, 'dine_in', 'open', datetime('now'),
		2446,0,122,245,0,2813,0, '','','', 'cloud-ord-77', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO order_items
		(id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, status)
		VALUES ('bmi-1', 'local-ord-77', 'sku-bmi', 'Banh Mi', 1, 1298, 1298, 'pending'),
		       ('fsr-1', 'local-ord-77', 'sku-fsr', 'Spring Rolls', 1, 1148, 1148, 'pending')`); err != nil {
		t.Fatalf("seed items: %v", err)
	}

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()

	// One reconcile tick enqueues the backfill; draining pushes it.
	e.reconcileUnsyncedItems()
	e.processQueue()

	if atomic.LoadInt32(&callCount) != 1 {
		t.Fatalf("expected 1 backfill push, got %d", callCount)
	}
	if seenPath != "/api/v1/workstation/orders/cloud-ord-77/items" {
		t.Errorf("wrong path: %q", seenPath)
	}
	if seenAuth != "Bearer WS-TOK" {
		t.Errorf("reconciler must send the device token as Bearer, got %q", seenAuth)
	}
	if items, _ := seenBody["items"].([]any); len(items) != 2 {
		t.Errorf("expected 2 items backfilled, got %+v", seenBody["items"])
	}

	// Lines stamped synced → a second reconcile is a no-op (no new push).
	var unsynced int
	db.QueryRow(`SELECT COUNT(*) FROM order_items WHERE customer_order_id='local-ord-77' AND synced_at IS NULL`).Scan(&unsynced)
	if unsynced != 0 {
		t.Errorf("expected all lines stamped synced, %d still NULL", unsynced)
	}
	e.reconcileUnsyncedItems()
	e.processQueue()
	if atomic.LoadInt32(&callCount) != 1 {
		t.Errorf("reconcile must not re-push already-synced lines, got %d calls", callCount)
	}
}

// TestOrderItemAddWaitsForOrderCreate asserts the item-add push is a no-op that
// stays queued (dependency-not-ready) while the order's own create hasn't synced
// yet — there is no cloud_id to address the items to.
func TestOrderItemAddWaitsForOrderCreate(t *testing.T) {
	var callCount int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&callCount, 1)
		w.Write([]byte(`{"data":{}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	// Order with NO cloud_id (create not synced yet).
	if _, err := db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status, opened_at,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, created_at, updated_at
	) VALUES ('local-ord-10', 'WS-0010', 10, 'spot', 'open', datetime('now'),
		0,0,0,0,0,0,0, '','','', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO order_items
		(id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, status)
		VALUES ('item-x', 'local-ord-10', 'sku-x', 'X', 1, 100, 100, 'pending')`); err != nil {
		t.Fatalf("seed item: %v", err)
	}

	payload := map[string]any{
		"bearer_token": "ws-token-10",
		"order_id":     "local-ord-10",
		"item_ids":     []any{"item-x"},
	}
	if err := e.Enqueue("order", "local-ord-10", "item_add", payload, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if atomic.LoadInt32(&callCount) != 0 {
		t.Errorf("expected NO Cloud call while order.create unsynced, got %d", callCount)
	}
	// Row must remain unsynced (waiting), attempts NOT burned.
	var syncedAt string
	var attempts int
	db.QueryRow(`SELECT COALESCE(synced_at,''), attempts FROM sync_queue WHERE operation='item_add'`).Scan(&syncedAt, &attempts)
	if syncedAt != "" {
		t.Error("item_add must stay queued until order.create syncs")
	}
	if attempts != 0 {
		t.Errorf("dependency-not-ready must not burn an attempt, got attempts=%d", attempts)
	}
}

func TestFilterNewItemAddIDs_DedupsPending(t *testing.T) {
	e, _ := newSyncTestEngine(t, "http://cloud.invalid")

	// First add for two lines enqueues a pending item_add row.
	if err := e.Enqueue("order", "ord-x", "item_add", map[string]any{
		"order_id": "ord-x",
		"item_ids": []any{"item-1", "item-2"},
	}, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	// Re-adding the same two lines → nothing new (all already queued).
	if got := e.FilterNewItemAddIDs("ord-x", []string{"item-1", "item-2"}); len(got) != 0 {
		t.Fatalf("want 0 new ids (all deduped), got %v", got)
	}

	// A mix: item-2 already queued, item-3 is new → only item-3 survives.
	got := e.FilterNewItemAddIDs("ord-x", []string{"item-2", "item-3"})
	if len(got) != 1 || got[0] != "item-3" {
		t.Fatalf("want [item-3], got %v", got)
	}

	// Different order id is unaffected by ord-x's queue.
	if got := e.FilterNewItemAddIDs("ord-y", []string{"item-1"}); len(got) != 1 {
		t.Fatalf("want 1 id for other order, got %v", got)
	}
}

func TestFilterNewItemAddIDs_IgnoresSyncedRows(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://cloud.invalid")

	if err := e.Enqueue("order", "ord-z", "item_add", map[string]any{
		"order_id": "ord-z",
		"item_ids": []any{"item-1"},
	}, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}
	// Mark the queued row synced → it must no longer suppress a re-add.
	if _, err := db.Exec(`UPDATE sync_queue SET synced_at = datetime('now') WHERE entity_id = 'ord-z'`); err != nil {
		t.Fatalf("mark synced: %v", err)
	}

	if got := e.FilterNewItemAddIDs("ord-z", []string{"item-1"}); len(got) != 1 {
		t.Fatalf("synced row should not dedup; want 1, got %v", got)
	}
}

// TestOrderItemAddSync_CarriesPrintedQuantity — #2622 (tầng 1 của #2551): the
// item_add payload must report each line's printed_quantity (units already
// sent to the kitchen, migration 034) so Cloud's customer_order_items mirror
// learns how much of the line has fired. Read fresh at push time, in the same
// SELECT as quantity: a BR-OI06 merge after a fire pushes (new quantity, old
// printed_quantity) and Cloud sees the exact unprinted delta.
func TestOrderItemAddSync_CarriesPrintedQuantity(t *testing.T) {
	var seenBody map[string]any
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"cloud-ord-22"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	if _, err := db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status, opened_at,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, cloud_id, created_at, updated_at
	) VALUES ('local-ord-22', 'ORD-022', 22, 'spot', 'open', datetime('now'),
		0,0,0,0,0,0,0, '','','', 'cloud-ord-22', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	// item-fired: fired at qty 2, then BR-OI06-merged up to 5 → delta 3 unprinted.
	// item-fresh: never fired — column default 0 must ride up as 0, not be omitted.
	if _, err := db.Exec(`INSERT INTO order_items
		(id, customer_order_id, product_sku_id, menu_item_name, quantity, printed_quantity, unit_price, subtotal, status)
		VALUES ('item-fired', 'local-ord-22', 'sku-a', 'Phở', 5, 2, 500, 2500, 'pending')`); err != nil {
		t.Fatalf("seed item-fired: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO order_items
		(id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, status)
		VALUES ('item-fresh', 'local-ord-22', 'sku-b', 'Trà', 1, 300, 300, 'pending')`); err != nil {
		t.Fatalf("seed item-fresh: %v", err)
	}

	if err := e.Enqueue("order", "local-ord-22", "item_add", map[string]any{
		"bearer_token":    "ws-token-22",
		"idempotency_key": "idem-add-22",
		"order_id":        "local-ord-22",
		"item_ids":        []any{"item-fired", "item-fresh"},
	}, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	items, _ := seenBody["items"].([]any)
	if len(items) != 2 {
		t.Fatalf("expected 2 items in body, got %d (%+v)", len(items), seenBody)
	}
	fired, _ := items[0].(map[string]any)
	pq, ok := fired["printed_quantity"]
	if !ok {
		t.Fatalf("item_add payload must carry printed_quantity for a fired line, got %+v", fired)
	}
	if pq.(float64) != 2 {
		t.Errorf("printed_quantity must be the line's fired units (2), got %v", pq)
	}
	if fired["quantity"].(float64) != 5 {
		t.Errorf("quantity must be the merged total (5), got %v", fired["quantity"])
	}

	fresh, _ := items[1].(map[string]any)
	pqFresh, ok := fresh["printed_quantity"]
	if !ok {
		t.Fatalf("printed_quantity must be present (0) even for a never-fired line, got %+v", fresh)
	}
	if pqFresh.(float64) != 0 {
		t.Errorf("never-fired line must report printed_quantity 0, got %v", pqFresh)
	}
}
