package service

import (
	"testing"
)

// intPtr is a tiny ergonomic helper for *int field literals in tests.
// Lifted into package scope so other test files can use it too.
func intPtr(v int) *int { return &v }

func seedTablesForCreate(t *testing.T, eng *OrderEngine, ids ...string) {
	t.Helper()
	for _, id := range ids {
		if _, err := eng.db.Exec(`
			INSERT INTO tables (id, name, status) VALUES (?, ?, 'available')
		`, id, id); err != nil {
			t.Fatalf("seed table %s: %v", id, err)
		}
	}
}

// Multi-table orders must write every selected table to the order_tables
// pivot (not just the primary). Without this, the /pos/tables handler
// would render secondary tables as free for a merged order; a second
// cashier could grab them mid-bill.
func TestCreate_PersistsMultiTablePivot(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedTablesForCreate(t, eng, "tA", "tB", "tC")

	o, err := eng.Create(CreateOrderInput{
		TableIDs:   []string{"tA", "tB", "tC"},
		OrderType:  "dine_in",
		GuestCount: intPtr(4),
	}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}

	// Primary (orders.table_id) is the first picked.
	if o.TableID != "tA" {
		t.Errorf("primary TableID want 'tA', got %q", o.TableID)
	}

	// Pivot carries all three in sort_order.
	rows, err := db.Query(`SELECT table_id FROM order_tables WHERE order_id = ? ORDER BY sort_order`, o.ID)
	if err != nil {
		t.Fatalf("query pivot: %v", err)
	}
	defer rows.Close()
	got := []string{}
	for rows.Next() {
		var id string
		if err := rows.Scan(&id); err != nil {
			t.Fatal(err)
		}
		got = append(got, id)
	}
	want := []string{"tA", "tB", "tC"}
	if len(got) != 3 || got[0] != want[0] || got[1] != want[1] || got[2] != want[2] {
		t.Errorf("pivot rows want %v, got %v", want, got)
	}
}

// Legacy single-table contract (handy / kiosk) must still work — TableID
// without TableIDs[] populates the pivot with one entry so subsequent
// /pos/tables joins surface occupancy via the same path as multi-table.
func TestCreate_LegacySingleTableStillWritesPivot(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedTablesForCreate(t, eng, "tA")

	o, err := eng.Create(CreateOrderInput{
		TableID:    "tA",
		OrderType:  "dine_in",
		GuestCount: intPtr(2),
	}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	var cnt int
	db.QueryRow(`SELECT COUNT(*) FROM order_tables WHERE order_id = ?`, o.ID).Scan(&cnt)
	if cnt != 1 {
		t.Errorf("expected 1 pivot row for legacy single-table create, got %d", cnt)
	}
}

// Backend CustomerOrderService::insertOrder sets status=pending for
// takeaway and open otherwise. The LAN handler must mirror this or
// cashiers can check out takeaway orders locally that Cloud still
// considers awaiting confirmation — sync UP then rejects them and the
// local + Cloud rows diverge.
func TestCreate_TakeawayStartsPending(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)

	o, err := eng.Create(CreateOrderInput{
		OrderType:             "takeaway",
		CustomerTakeawayPhone: "0900000000",
	}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if string(o.Status) != "pending" {
		t.Errorf("takeaway want status=pending, got %q", o.Status)
	}
}

// A staff-driven creator (POS / Handy) passes an explicit `open` status so a
// counter takeaway opens directly instead of sitting in `pending` — the cashier
// at the till is the confirmation. Empty Status keeps the self-service default
// (proven by TestCreate_TakeawayStartsPending above).
func TestCreate_ExplicitOpenOverridesTakeawayPending(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)

	o, err := eng.Create(CreateOrderInput{
		OrderType:             "takeaway",
		CustomerTakeawayPhone: "0900000000",
		Status:                string(StatusOpen),
	}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if string(o.Status) != "open" {
		t.Errorf("staff takeaway want status=open, got %q", o.Status)
	}
}

func TestCreate_DineInStartsOpen(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedTablesForCreate(t, eng, "tX")

	o, err := eng.Create(CreateOrderInput{
		TableIDs:  []string{"tX"},
		OrderType: "dine_in",
	}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if string(o.Status) != "open" {
		t.Errorf("dine_in want status=open, got %q", o.Status)
	}
}

// CustomerID must round-trip into orders.customer_id so loyalty + per-
// customer outstanding totals work in LAN mode. Pre-fix the column was
// in the schema but the INSERT never wrote it.
func TestCreate_PersistsCustomerID(t *testing.T) {
	eng, db := newOrderEngineForTest(t)

	o, err := eng.Create(CreateOrderInput{
		CustomerID: "cust-1",
		OrderType:  "spot",
	}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if o.CustomerID != "cust-1" {
		t.Errorf("returned order CustomerID want 'cust-1', got %q", o.CustomerID)
	}
	var got string
	db.QueryRow(`SELECT COALESCE(customer_id, '') FROM orders WHERE id = ?`, o.ID).Scan(&got)
	if got != "cust-1" {
		t.Errorf("persisted customer_id want 'cust-1', got %q", got)
	}
}

// createItem must fall back to pos_product_skus + pos_products when
// menu_items doesn't have a row for the SKU. Symptom pre-fix: every
// add-to-cart on a SKU that wasn't yet (or no longer) in the legacy
// menu_items table returned 500 with "menu item not found".
func TestAddItems_FallsBackToPosProductSkusWhenMenuItemsMissing(t *testing.T) {
	eng, db := newOrderEngineForTest(t)

	if _, err := db.Exec(`INSERT INTO pos_products (id, name) VALUES ('p1','Pho Bo')`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-A','p1','Large','PHO-L',2800,1)`); err != nil {
		t.Fatal(err)
	}

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}

	added, err := eng.AddItems(o.ID, []CreateItemInput{{
		ProductSkuID: "sku-A",
		Quantity:     1,
	}})
	if err != nil {
		t.Fatalf("AddItems fallback path: %v", err)
	}
	if len(added) != 1 {
		t.Fatalf("expected 1 item added, got %d", len(added))
	}
	it := added[0]
	if it.MenuItemName != "Pho Bo · Large" {
		t.Errorf("menu_item_name want 'Pho Bo · Large', got %q", it.MenuItemName)
	}
	if it.UnitPrice != 2800 {
		t.Errorf("unit_price want 2800, got %d", it.UnitPrice)
	}
	if it.SkuVariantName != "Large" {
		t.Errorf("sku_variant_name want 'Large', got %q", it.SkuVariantName)
	}
}

// Duplicate table ids in the input (clumsy double-click on the same
// table chip in pos-web) must not abort the create with a UNIQUE
// constraint on (order_id, table_id) — resolvedTableIDs() de-dupes.
func TestCreate_DedupesDuplicateTableInput(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedTablesForCreate(t, eng, "tA", "tB")

	o, err := eng.Create(CreateOrderInput{
		TableIDs:  []string{"tA", "tB", "tA"},
		OrderType: "dine_in",
	}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	var cnt int
	db.QueryRow(`SELECT COUNT(*) FROM order_tables WHERE order_id = ?`, o.ID).Scan(&cnt)
	if cnt != 2 {
		t.Errorf("dedup expected 2 pivot rows, got %d", cnt)
	}
}
