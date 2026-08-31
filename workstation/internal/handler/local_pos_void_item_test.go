package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// seedVoidItemOrder creates a workstation order + one pending item for
// the handler tests below. Returns (orderID, itemID).
func seedVoidItemOrder(t *testing.T, srv *Server) (string, string) {
	t.Helper()
	if srv.orders == nil {
		srv.orders = service.NewOrderEngine(srv.db)
	}
	mustExec(t, srv.db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, srv.db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active) VALUES ('sk1','p1','R','PHO-R',1000,1)`)

	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	_, err = srv.orders.AddItems(o.ID, []service.CreateItemInput{{ProductSkuID: "sk1", Quantity: 1}})
	if err != nil {
		t.Fatalf("AddItems: %v", err)
	}
	o, _ = srv.orders.GetByID(o.ID)
	return o.ID, o.Items[0].ID
}

// 422 when `void_reason` is missing — mirrors Cloud's validation.
func TestHandleVoidItem_422OnMissingReason(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedVoidItemOrder(t, srv)

	req := httptest.NewRequest("POST", "/api/v1/pos/orders/"+orderID+"/items/"+itemID+"/void",
		bytes.NewReader([]byte(`{}`)))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosVoidItem(w, req)

	if w.Code != http.StatusUnprocessableEntity {
		t.Errorf("missing void_reason: want 422, got %d body=%s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), "void_reason") {
		t.Errorf("error body must mention void_reason: %s", w.Body.String())
	}
}

func TestHandleVoidItem_422OnBlankReason(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedVoidItemOrder(t, srv)

	req := httptest.NewRequest("POST", "/api/v1/pos/orders/"+orderID+"/items/"+itemID+"/void",
		bytes.NewReader([]byte(`{"void_reason":"   "}`)))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosVoidItem(w, req)
	if w.Code != http.StatusUnprocessableEntity {
		t.Errorf("blank void_reason: want 422, got %d", w.Code)
	}
}

// Happy path → 200 + voided line still in items[] (Cloud parity per
// MenuController docblock: "the voided item still appears inside
// data.items[] with status = voided").
func TestHandleVoidItem_200KeepsVoidedItemInResponse(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedVoidItemOrder(t, srv)

	req := httptest.NewRequest("POST", "/api/v1/pos/orders/"+orderID+"/items/"+itemID+"/void",
		bytes.NewReader([]byte(`{"void_reason":"customer changed mind"}`)))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosVoidItem(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()
	for _, frag := range []string{
		`"status":"voided"`,
		`"void_reason":"customer changed mind"`,
		`"id":"` + itemID + `"`,
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("response missing %q\nbody=%s", frag, body)
		}
	}
}

// 409 when item is past pending — matches Cloud's BR-OI05 body. plan-051:
// the 409 now also carries code ITEM_STATUS_NOT_VOIDABLE + the resolved
// voidable list (default matrix = ["pending"]) while keeping the legacy
// message sentence for deployed pos-web.
func TestHandleVoidItem_409OnPreparingItem(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedVoidItemOrder(t, srv)
	mustExec(t, srv.db, `UPDATE order_items SET status='preparing' WHERE id=?`, itemID)

	req := httptest.NewRequest("POST", "/api/v1/pos/orders/"+orderID+"/items/"+itemID+"/void",
		bytes.NewReader([]byte(`{"void_reason":"test"}`)))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosVoidItem(w, req)
	if w.Code != http.StatusConflict {
		t.Errorf("preparing item: want 409, got %d body=%s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), "Only pending items") {
		t.Errorf("response must explain BR-OI05: %s", w.Body.String())
	}
	var resp struct {
		Code             string   `json:"code"`
		VoidableStatuses []string `json:"voidable_statuses"`
		ItemStatus       string   `json:"item_status"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode 409 body: %v", err)
	}
	if resp.Code != "ITEM_STATUS_NOT_VOIDABLE" {
		t.Errorf("code want ITEM_STATUS_NOT_VOIDABLE, got %q", resp.Code)
	}
	if len(resp.VoidableStatuses) != 1 || resp.VoidableStatuses[0] != "pending" {
		t.Errorf("voidable_statuses want [pending], got %v", resp.VoidableStatuses)
	}
	if resp.ItemStatus != "preparing" {
		t.Errorf("item_status want preparing, got %q", resp.ItemStatus)
	}
}

// plan-051 — matrix ["pending","preparing"] mirrored into shop_settings:
// voiding a preparing line with a real reason + picked reason id succeeds,
// stores the id on the row, surfaces it in the response, and the sync-UP
// payload carries void_reason_id alongside the text.
func TestHandleVoidItem_MatrixAndReasonID(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedVoidItemOrder(t, srv)
	mustExec(t, srv.db, `UPDATE order_items SET status='preparing' WHERE id=?`, itemID)
	mustExec(t, srv.db, `INSERT INTO shop_settings (key, value) VALUES
		('item_voidable_statuses','["pending","preparing"]'),
		('void_reasons','[{"id":"vr-1","label":"Bấm nhầm","stock_effect":"restock","requires_note":false,"sort_order":0}]')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)

	req := httptest.NewRequest("POST", "/api/v1/pos/orders/"+orderID+"/items/"+itemID+"/void",
		bytes.NewReader([]byte(`{"void_reason":"Bấm nhầm","void_reason_id":"vr-1"}`)))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosVoidItem(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("matrix void: want 200, got %d body=%s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), `"void_reason_id":"vr-1"`) {
		t.Errorf("response item must carry void_reason_id: %s", w.Body.String())
	}

	var reasonID string
	srv.db.QueryRow(`SELECT COALESCE(void_reason_id,'') FROM order_items WHERE id=?`, itemID).Scan(&reasonID)
	if reasonID != "vr-1" {
		t.Errorf("row void_reason_id want vr-1, got %q", reasonID)
	}

	// Sync-UP op payload — Cloud's handleOrderItemVoid reads both keys.
	var payload string
	if err := srv.db.QueryRow(
		`SELECT payload FROM sync_queue WHERE entity_type='order' AND operation='item_void' ORDER BY id DESC LIMIT 1`,
	).Scan(&payload); err != nil {
		t.Fatalf("sync_queue row: %v", err)
	}
	for _, frag := range []string{`"void_reason_id":"vr-1"`, `"void_reason":"Bấm nhầm"`, `"item_id":"` + itemID + `"`} {
		if !strings.Contains(payload, frag) {
			t.Errorf("sync payload missing %s: %s", frag, payload)
		}
	}
}

// plan-051 — a status outside a CONFIGURED (non-default) matrix returns the
// list-carrying 409 so pos-web can explain which statuses are voidable.
func TestHandleVoidItem_409ListsVoidableStatuses(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedVoidItemOrder(t, srv)
	mustExec(t, srv.db, `UPDATE order_items SET status='served' WHERE id=?`, itemID)
	mustExec(t, srv.db, `INSERT INTO shop_settings (key, value) VALUES
		('item_voidable_statuses','["pending","preparing"]')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)

	req := httptest.NewRequest("POST", "/api/v1/pos/orders/"+orderID+"/items/"+itemID+"/void",
		bytes.NewReader([]byte(`{"void_reason":"real reason"}`)))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosVoidItem(w, req)
	if w.Code != http.StatusConflict {
		t.Fatalf("served outside matrix: want 409, got %d body=%s", w.Code, w.Body.String())
	}
	var resp struct {
		Code             string   `json:"code"`
		VoidableStatuses []string `json:"voidable_statuses"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}
	if resp.Code != "ITEM_STATUS_NOT_VOIDABLE" {
		t.Errorf("code want ITEM_STATUS_NOT_VOIDABLE, got %q", resp.Code)
	}
	if len(resp.VoidableStatuses) != 2 || resp.VoidableStatuses[1] != "preparing" {
		t.Errorf("voidable_statuses want [pending preparing], got %v", resp.VoidableStatuses)
	}
}

// GET /api/v1/pos/void-reasons — serves the mirrored VoidReason master;
// empty data array (not null) when the mirror hasn't arrived.
func TestHandleVoidReasons_ServesMirroredList(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")

	// No mirror yet → empty list.
	req := httptest.NewRequest("GET", "/api/v1/pos/void-reasons", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosVoidReasons(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d", w.Code)
	}
	if !strings.Contains(w.Body.String(), `"data":[]`) {
		t.Errorf("empty mirror must serve data:[] (not null): %s", w.Body.String())
	}

	mustExec(t, srv.db, `INSERT INTO shop_settings (key, value) VALUES
		('void_reasons','[{"id":"vr-1","label":"Bấm nhầm","stock_effect":"restock","requires_note":false,"sort_order":0},{"id":"vr-2","label":"Comp cho khách","stock_effect":"none","requires_note":true,"sort_order":4}]')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)

	w = httptest.NewRecorder()
	srv.handleLocalPosVoidReasons(w, httptest.NewRequest("GET", "/api/v1/pos/void-reasons", nil))
	var resp struct {
		Data []struct {
			ID           string `json:"id"`
			Label        string `json:"label"`
			StockEffect  string `json:"stock_effect"`
			RequiresNote bool   `json:"requires_note"`
			SortOrder    int    `json:"sort_order"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v body=%s", err, w.Body.String())
	}
	if len(resp.Data) != 2 {
		t.Fatalf("want 2 reasons, got %d", len(resp.Data))
	}
	if resp.Data[0].ID != "vr-1" || resp.Data[0].StockEffect != "restock" {
		t.Errorf("reason[0] mangled: %+v", resp.Data[0])
	}
	if resp.Data[1].ID != "vr-2" || !resp.Data[1].RequiresNote || resp.Data[1].SortOrder != 4 {
		t.Errorf("reason[1] mangled: %+v", resp.Data[1])
	}
}

// 409 when order is past open.
func TestHandleVoidItem_409OnNonOpenOrder(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedVoidItemOrder(t, srv)
	mustExec(t, srv.db, `UPDATE orders SET status='voided' WHERE id=?`, orderID)

	req := httptest.NewRequest("POST", "/api/v1/pos/orders/"+orderID+"/items/"+itemID+"/void",
		bytes.NewReader([]byte(`{"void_reason":"test"}`)))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosVoidItem(w, req)
	if w.Code != http.StatusConflict {
		t.Errorf("non-open order: want 409, got %d", w.Code)
	}
}

// 404 when item id doesn't belong to the order.
func TestHandleVoidItem_404OnUnknownItem(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, _ := seedVoidItemOrder(t, srv)
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/"+orderID+"/items/no-such/void",
		bytes.NewReader([]byte(`{"void_reason":"test"}`)))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", "no-such")
	w := httptest.NewRecorder()
	srv.handleLocalPosVoidItem(w, req)
	if w.Code != http.StatusNotFound {
		t.Errorf("unknown item: want 404, got %d", w.Code)
	}
}

// DELETE /items/{item} delegates → BR-OI05 still applies (409 on
// preparing). Body shape matches voidItem.
func TestHandleDeleteItem_409OnPreparingItem(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedVoidItemOrder(t, srv)
	mustExec(t, srv.db, `UPDATE order_items SET status='preparing' WHERE id=?`, itemID)

	req := httptest.NewRequest("DELETE", "/api/v1/pos/orders/"+orderID+"/items/"+itemID, nil)
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosDeleteItem(w, req)
	if w.Code != http.StatusConflict {
		t.Errorf("DELETE preparing: want 409, got %d", w.Code)
	}
}

// DELETE succeeds on a pending item → soft-voids it with the
// fixed "Removed by staff" reason.
func TestHandleDeleteItem_200SoftVoidsWithStaffReason(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedVoidItemOrder(t, srv)
	req := httptest.NewRequest("DELETE", "/api/v1/pos/orders/"+orderID+"/items/"+itemID, nil)
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosDeleteItem(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("DELETE happy: want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()
	if !strings.Contains(body, `"void_reason":"Removed by staff"`) {
		t.Errorf("DELETE must soft-void with 'Removed by staff' reason: %s", body)
	}
	if !strings.Contains(body, `"status":"voided"`) {
		t.Errorf("DELETE must leave the row with status voided: %s", body)
	}
}
