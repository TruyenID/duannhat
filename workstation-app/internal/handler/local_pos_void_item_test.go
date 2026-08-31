package handler

import (
	"bytes"
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
		srv.orders = service.NewOrderEngine(srv.db, 0)
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

// 409 when item is past pending — matches Cloud's BR-OI05 body.
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
