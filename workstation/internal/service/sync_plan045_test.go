package service

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

func jn(s string) *json.Number { n := json.Number(s); return &n }

// [GoInt] pull DOWN — upsertOrder writes the rounding snapshot columns, upserts
// conditions[], and a refund negative-qty line upserts like any item.
func TestUpsertOrder_Plan045_RoundingConditionsRefund(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	mode := "round_up"
	conds := []cloudOrderConditionPayload{
		{ID: "c1", ConditionableType: "order", ConditionableID: "ord-1", Type: "tax", Source: "tax_type", Label: "10%対象", Rate: jn("10"), Amount: json.Number("100"), CurrencyCode: "JPY"},
		{ID: "c2", ConditionableType: "order_item", ConditionableID: "refund-1", Type: "refund", Source: "manual", Label: "Refund", Amount: json.Number("-110"), CurrencyCode: "JPY", Meta: json.RawMessage(`{"refund_of_item_id":"orig-1"}`)},
	}
	order := cloudOrderPayload{
		ID: "ord-1", OrderCode: "ORD-1", OrderType: "dine_in", Status: "open",
		OpenedAt: "2026-06-25T10:00:00Z", UpdatedAt: "2026-06-25T10:00:00Z",
		BranchID: "br-1", BrandID: "bd-1", OrgID: "org-1",
		TaxRoundingMode:     mode,
		TaxRoundingDecimals: jn("0"),
		Conditions:          &conds,
		Items: []cloudOrderItemPayload{
			{ID: "orig-1", ProductSkuID: "sku-1", MenuItemName: "X", Quantity: json.Number("3"), UnitPrice: json.Number("100"), Subtotal: json.Number("300"), Status: "served", UpdatedAt: "2026-06-25T10:00:00Z", RefundedQuantity: jn("1")},
			{ID: "refund-1", ProductSkuID: "sku-1", MenuItemName: "X", Quantity: json.Number("-1"), UnitPrice: json.Number("100"), Subtotal: json.Number("-100"), Status: "served", UpdatedAt: "2026-06-25T10:00:00Z", RefundOfItemID: "orig-1"},
		},
	}
	if err := p.upsertOrder(order, false); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}

	// Rounding snapshot on the order.
	var gotMode string
	var gotDecimals int
	if err := db.QueryRow(`SELECT tax_rounding_mode, tax_rounding_decimals FROM orders WHERE id='ord-1'`).Scan(&gotMode, &gotDecimals); err != nil {
		t.Fatalf("read order rounding: %v", err)
	}
	if gotMode != "round_up" || gotDecimals != 0 {
		t.Errorf("rounding snapshot want round_up/0, got %s/%d", gotMode, gotDecimals)
	}

	// Refund line upserted with refund_of_item_id + negative qty; original bumped.
	var refundOf string
	var rqty int
	db.QueryRow(`SELECT COALESCE(refund_of_item_id,''), quantity FROM order_items WHERE id='refund-1'`).Scan(&refundOf, &rqty)
	if refundOf != "orig-1" || rqty != -1 {
		t.Errorf("refund line want orig-1/-1, got %s/%d", refundOf, rqty)
	}
	var origRefunded int
	db.QueryRow(`SELECT refunded_quantity FROM order_items WHERE id='orig-1'`).Scan(&origRefunded)
	if origRefunded != 1 {
		t.Errorf("original refunded_quantity want 1, got %d", origRefunded)
	}

	// Conditions upserted (both order-level + item-level).
	var cn int
	db.QueryRow(`SELECT COUNT(*) FROM order_conditions`).Scan(&cn)
	if cn != 2 {
		t.Errorf("conditions count want 2, got %d", cn)
	}
	var amt float64
	db.QueryRow(`SELECT amount FROM order_conditions WHERE id='c2'`).Scan(&amt)
	if int(amt) != -110 {
		t.Errorf("refund condition amount want -110, got %v", amt)
	}
}

// [GoInt] an OLD cloud that omits the rounding + conditions keys must not clobber
// local values — upsert keeps the local rounding snapshot (COALESCE) and leaves
// conditions untouched (nil pointer).
func TestUpsertOrder_Plan045_OldCloudNoKeys(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	// Pre-seed the order with a local snapshot + a local condition.
	mustExecDB(t, db, `INSERT INTO orders (id, order_code, order_number, order_type, status, opened_at, tax_rounding_mode, tax_rounding_decimals, created_at, updated_at)
		VALUES ('ord-2','ORD-2',1,'spot','open',datetime('now'),'round_down',2,datetime('now'),datetime('now'))`)
	mustExecDB(t, db, `INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, label, amount, currency_code, created_at)
		VALUES ('local-c','order','ord-2','tax','local',50,'JPY',datetime('now'))`)

	// Old-cloud payload: no rounding, Conditions nil.
	order := cloudOrderPayload{
		ID: "ord-2", OrderCode: "ORD-2", OrderType: "spot", Status: "open",
		OpenedAt: "2026-06-25T10:00:00Z", UpdatedAt: "2026-06-25T11:00:00Z",
		BranchID: "br-1", BrandID: "bd-1", OrgID: "org-1",
	}
	if err := p.upsertOrder(order, false); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}

	var mode string
	var dec int
	db.QueryRow(`SELECT tax_rounding_mode, tax_rounding_decimals FROM orders WHERE id='ord-2'`).Scan(&mode, &dec)
	if mode != "round_down" || dec != 2 {
		t.Errorf("old cloud must keep local snapshot round_down/2, got %s/%d", mode, dec)
	}
	var cn int
	db.QueryRow(`SELECT COUNT(*) FROM order_conditions WHERE conditionable_id='ord-2'`).Scan(&cn)
	if cn != 1 {
		t.Errorf("nil conditions must leave local rows untouched, got %d", cn)
	}
}

// [GoInt] PullBranch mirrors tax_rounding_mode + tax_rounding_decimals from
// data.settings.* into shop_settings.
func TestPullBranch_Plan045_MirrorsRoundingKeys(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":{
			"id":"br-1","slug":"x","name":"X","currency":"JPY",
			"timezone":"Asia/Tokyo","locale":"ja",
			"settings":{"tax_rounding_mode":"round_up","tax_rounding_decimals":0}
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	mustExecDB(t, db, `CREATE TABLE IF NOT EXISTS branches (
		id TEXT PRIMARY KEY, console_branch_id TEXT NOT NULL UNIQUE,
		console_organization_id TEXT NOT NULL, slug TEXT NOT NULL, name TEXT NOT NULL,
		is_active INTEGER NOT NULL DEFAULT 1, timezone TEXT, currency TEXT, locale TEXT,
		updated_at TEXT NOT NULL DEFAULT (datetime('now')))`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("T"))
	if err := p.PullBranch(context.Background()); err != nil {
		t.Fatalf("PullBranch: %v", err)
	}

	for key, want := range map[string]string{"tax_rounding_mode": "round_up", "tax_rounding_decimals": "0"} {
		var got string
		_ = db.QueryRow(`SELECT value FROM shop_settings WHERE key=?`, key).Scan(&got)
		if got != want {
			t.Errorf("shop_settings.%s want %q, got %q", key, want, got)
		}
	}
}

// [GoInt] reconcileOrderFromCloud adopts tax_rounding_mode/decimals (gap #3) so a
// locally-priced order converges to Cloud's snapshot.
func TestReconcileOrderFromCloud_Plan045_AdoptsRoundingSnapshot(t *testing.T) {
	db := newPullerTestDB(t)
	e := NewSyncEngine(db, "http://x", nil)

	mustExecDB(t, db, `INSERT INTO orders (id, order_code, order_number, order_type, status, opened_at, tax_rounding_mode, tax_rounding_decimals, created_at, updated_at)
		VALUES ('o1','WS-1',1,'spot','open',datetime('now'),'half_up',NULL,datetime('now'),datetime('now'))`)

	// Cloud response (data object) carries the reconciled snapshot.
	resp := map[string]any{
		"total_amount":          "1100",
		"tax_amount":            "100",
		"tax_rounding_mode":     "round_up",
		"tax_rounding_decimals": float64(0),
	}
	e.reconcileOrderFromCloud("o1", resp)

	var mode string
	var dec int
	if err := db.QueryRow(`SELECT tax_rounding_mode, tax_rounding_decimals FROM orders WHERE id='o1'`).Scan(&mode, &dec); err != nil {
		t.Fatalf("read: %v", err)
	}
	if mode != "round_up" || dec != 0 {
		t.Errorf("reconcile must adopt round_up/0, got %s/%d", mode, dec)
	}
}

// [GoInt] readOrderItemForSync does NOT drop a refund line because it carries the
// original's product_sku_id (gap #2 regression guard).
func TestReadOrderItemForSync_Plan045_RefundLineCarriesSKU(t *testing.T) {
	db := newPullerTestDB(t)
	e := NewSyncEngine(db, "http://x", nil)

	mustExecDB(t, db, `INSERT INTO orders (id, order_code, order_number, order_type, status, opened_at, created_at, updated_at)
		VALUES ('o1','WS-1',1,'spot','open',datetime('now'),datetime('now'),datetime('now'))`)
	// Refund line with a copied product_sku_id + negative qty.
	mustExecDB(t, db, `INSERT INTO order_items (id, customer_order_id, product_sku_id, menu_item_name, quantity, unit_price, subtotal, refund_of_item_id, status, created_at, updated_at)
		VALUES ('refund-1','o1','sku-1','X',-1,100,-100,'orig-1','served',datetime('now'),datetime('now'))`)

	item, ok, err := e.readOrderItemForSync("refund-1")
	if err != nil {
		t.Fatalf("readOrderItemForSync: %v", err)
	}
	if !ok {
		t.Fatal("refund line with a SKU must NOT be dropped (gap #2)")
	}
	if item["product_sku_id"] != "sku-1" {
		t.Errorf("refund line SKU want sku-1, got %v", item["product_sku_id"])
	}
	if item["quantity"] != -1 {
		t.Errorf("refund line qty want -1, got %v", item["quantity"])
	}
}

// [GoInt] the order.item_refund op POSTs to the Cloud refund route with the
// refund-line UUID as client_order_item_id + Idempotency-Key, and adopts the
// reconciled snapshot back. A re-drain must NOT re-post (queue row marked synced).
func TestOrderItemRefundSync_ForwardsAndIdempotent(t *testing.T) {
	var (
		seenPath  string
		seenIdem  string
		seenBody  map[string]any
		callCount int
	)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		callCount++
		seenPath = r.URL.Path
		seenIdem = r.Header.Get("Idempotency-Key")
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"cloud-ord-r","total_amount":"110","tax_amount":"10","tax_rounding_mode":"round_up","tax_rounding_decimals":0}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	mustExecDB(t, db, `INSERT INTO orders (
		id, order_code, order_number, order_type, status, opened_at,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id, cloud_id, tax_rounding_mode, created_at, updated_at
	) VALUES ('local-ord-r','ORD-R',1,'spot','open',datetime('now'),
		100,0,0,10,0,110,0,'','','','cloud-ord-r','half_up',datetime('now'),datetime('now'))`)

	payload := map[string]any{
		"bearer_token":    "ws-token-r",
		"idempotency_key": "idem-r",
		"order_id":        "local-ord-r",
		"item_id":         "orig-1",
		"refund_line_id":  "refund-uuid-1",
		"quantity":        2,
		"reason":          "spill",
	}
	if err := e.Enqueue("order", "local-ord-r", "item_refund", payload, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if callCount != 1 {
		t.Fatalf("expected 1 Cloud call, got %d", callCount)
	}
	if seenPath != "/api/v1/workstation/orders/cloud-ord-r/items/orig-1/refund" {
		t.Errorf("path want .../cloud-ord-r/items/orig-1/refund, got %q", seenPath)
	}
	if seenIdem != "refund-uuid-1" {
		t.Errorf("Idempotency-Key want refund-uuid-1, got %q", seenIdem)
	}
	if seenBody["client_order_item_id"] != "refund-uuid-1" {
		t.Errorf("client_order_item_id want refund-uuid-1, got %v", seenBody["client_order_item_id"])
	}
	if seenBody["quantity"].(float64) != 2 {
		t.Errorf("quantity want 2, got %v", seenBody["quantity"])
	}

	// Reconciled snapshot adopted (gap #3 in the drain path).
	var mode string
	db.QueryRow(`SELECT tax_rounding_mode FROM orders WHERE id='local-ord-r'`).Scan(&mode)
	if mode != "round_up" {
		t.Errorf("order must adopt round_up from cloud response, got %q", mode)
	}

	// Queue row synced → a re-drain does NOT re-post.
	var syncedAt string
	db.QueryRow(`SELECT COALESCE(synced_at,'') FROM sync_queue WHERE operation='item_refund'`).Scan(&syncedAt)
	if syncedAt == "" {
		t.Error("expected item_refund queue row marked synced")
	}
	e.processQueue()
	if callCount != 1 {
		t.Errorf("re-drain must not re-post (idempotent), got %d calls", callCount)
	}
}

// mustExecDB runs a statement against the puller/sync test DB, failing on error.
func mustExecDB(t *testing.T, db *store.DB, q string, args ...any) {
	t.Helper()
	if _, err := db.Exec(q, args...); err != nil {
		t.Fatalf("exec %q: %v", q, err)
	}
}
