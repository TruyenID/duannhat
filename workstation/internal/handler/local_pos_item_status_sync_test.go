package handler

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
)

// PATCH /api/v1/pos/orders/{id}/items/{item} — sync-UP op routing.
//
// A status change must ride the dedicated customer_order_item.update_status
// op (the KDS-bump path): order.item_update does NOT forward status, so
// before this split a POS bump never reached Cloud and the 5s pull-DOWN
// clobbered the local change back (the "served reverts to pending" bug).

func seedItemStatusOrder(t *testing.T, srv *Server, db *store.DB) (orderID, itemID string) {
	t.Helper()
	if _, err := db.Exec(`INSERT OR IGNORE INTO pos_products (id, name) VALUES ('p1','Pho')`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-A', 'p1', 'Regular', 'SKU-A', 1000, 1)`); err != nil {
		t.Fatal(err)
	}
	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "takeaway"}, nil)
	if err != nil {
		t.Fatalf("create order: %v", err)
	}
	items, err := srv.orders.AddItems(o.ID, []service.CreateItemInput{{ProductSkuID: "sku-A", Quantity: 2}})
	if err != nil {
		t.Fatalf("add items: %v", err)
	}
	// A takeaway Create lands `pending`; the qty-edit gate needs open|confirmed.
	if _, err := db.Exec(`UPDATE orders SET status='open' WHERE id = ?`, o.ID); err != nil {
		t.Fatal(err)
	}
	return o.ID, items[0].ID
}

func patchItem(t *testing.T, srv *Server, orderID, itemID, body string) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest("PATCH", "/api/v1/pos/orders/"+orderID+"/items/"+itemID,
		bytes.NewReader([]byte(body)))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosUpdateItem(rec, req)
	return rec
}

func countQueueRows(t *testing.T, db *store.DB, entityType, entityID, operation string) int {
	t.Helper()
	var n int
	db.QueryRow(`SELECT COUNT(*) FROM sync_queue
	              WHERE entity_type = ? AND entity_id = ? AND operation = ?`,
		entityType, entityID, operation).Scan(&n)
	return n
}

func TestPosItemStatusPatch_EnqueuesUpdateStatusOp(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.orders = service.NewOrderEngine(db)
	srv.hub = NewHub()

	orderID, itemID := seedItemStatusOrder(t, srv, db)
	rec := patchItem(t, srv, orderID, itemID, `{"status":"served"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("status: want 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	var dbStatus string
	db.QueryRow(`SELECT status FROM order_items WHERE id = ?`, itemID).Scan(&dbStatus)
	if dbStatus != "served" {
		t.Errorf("local status want served, got %s", dbStatus)
	}

	if n := countQueueRows(t, db, "customer_order_item", itemID, "update_status"); n != 1 {
		t.Errorf("want 1 update_status sync row, got %d", n)
	}
	// Status-only patch must NOT enqueue the empty-body item_update no-op.
	if n := countQueueRows(t, db, "order", orderID, "item_update"); n != 0 {
		t.Errorf("status-only patch must not enqueue order.item_update, got %d rows", n)
	}

	// Payload contract: previous_status + status + snapshot for ghost-create.
	var payload string
	db.QueryRow(`SELECT payload FROM sync_queue
	              WHERE entity_type='customer_order_item' AND entity_id=? AND operation='update_status'`,
		itemID).Scan(&payload)
	for _, want := range []string{
		`"status":"served"`,
		`"previous_status":"pending"`,
		`"order_id":"` + orderID + `"`,
		`"item_snapshot"`,
		`"product_sku_id":"sku-A"`,
	} {
		if !bytes.Contains([]byte(payload), []byte(want)) {
			t.Errorf("payload missing %s — got %s", want, payload)
		}
	}
}

func TestPosItemQtyPatch_EnqueuesItemUpdateOnly(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.orders = service.NewOrderEngine(db)
	srv.hub = NewHub()

	orderID, itemID := seedItemStatusOrder(t, srv, db)
	rec := patchItem(t, srv, orderID, itemID, `{"quantity":3}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("status: want 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	if n := countQueueRows(t, db, "order", orderID, "item_update"); n != 1 {
		t.Errorf("want 1 item_update sync row for a qty edit, got %d", n)
	}
	if n := countQueueRows(t, db, "customer_order_item", itemID, "update_status"); n != 0 {
		t.Errorf("qty-only patch must not enqueue update_status, got %d rows", n)
	}
}

func TestPosItemMixedPatch_EnqueuesBothOps(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.orders = service.NewOrderEngine(db)
	srv.hub = NewHub()

	orderID, itemID := seedItemStatusOrder(t, srv, db)
	rec := patchItem(t, srv, orderID, itemID, `{"quantity":3,"status":"preparing"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("status: want 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	if n := countQueueRows(t, db, "order", orderID, "item_update"); n != 1 {
		t.Errorf("want 1 item_update row for the qty half, got %d", n)
	}
	if n := countQueueRows(t, db, "customer_order_item", itemID, "update_status"); n != 1 {
		t.Errorf("want 1 update_status row for the status half, got %d", n)
	}
}
