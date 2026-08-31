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

func seedItemForStatusTest(t *testing.T, srv *Server) (string, string) {
	t.Helper()
	if srv.orders == nil {
		srv.orders = service.NewOrderEngine(srv.db)
	}
	mustExec(t, srv.db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, srv.db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active) VALUES ('sk1','p1','R','PHO-R',1000,1)`)
	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := srv.orders.AddItems(o.ID, []service.CreateItemInput{{ProductSkuID: "sk1", Quantity: 1}}); err != nil {
		t.Fatal(err)
	}
	o, _ = srv.orders.GetByID(o.ID)
	return o.ID, o.Items[0].ID
}

// PATCH .../items/{item} with `{status:"served"}` succeeds + response
// reflects the new status + served_at. This is the dropdown path from
// the screenshot (Đã phục vụ).
func TestHandlePosUpdateItem_Status_200(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedItemForStatusTest(t, srv)

	body, _ := json.Marshal(map[string]any{"status": "served"})
	req := httptest.NewRequest("PATCH",
		"/api/v1/pos/orders/"+orderID+"/items/"+itemID,
		bytes.NewReader(body))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosUpdateItem(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	for _, frag := range []string{
		`"status":"served"`,
		`"served_at":`,
	} {
		if !strings.Contains(w.Body.String(), frag) {
			t.Errorf("response missing %q\nbody=%s", frag, w.Body.String())
		}
	}
}

// 422 on a bogus status enum (Cloud parity — FormRequest 'in' rule).
func TestHandlePosUpdateItem_InvalidStatus_422(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedItemForStatusTest(t, srv)

	body, _ := json.Marshal(map[string]any{"status": "frozen"})
	req := httptest.NewRequest("PATCH",
		"/api/v1/pos/orders/"+orderID+"/items/"+itemID,
		bytes.NewReader(body))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosUpdateItem(w, req)

	if w.Code != http.StatusUnprocessableEntity {
		t.Errorf("invalid enum: want 422, got %d body=%s", w.Code, w.Body.String())
	}
}

// 409 on attempting qty edit of a preparing item (BR-OI05 edit gate).
func TestHandlePosUpdateItem_QtyOnPreparing_409(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedItemForStatusTest(t, srv)
	mustExec(t, srv.db, `UPDATE order_items SET status='preparing' WHERE id=?`, itemID)

	body, _ := json.Marshal(map[string]any{"quantity": 3})
	req := httptest.NewRequest("PATCH",
		"/api/v1/pos/orders/"+orderID+"/items/"+itemID,
		bytes.NewReader(body))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosUpdateItem(w, req)

	if w.Code != http.StatusConflict {
		t.Errorf("qty on preparing: want 409, got %d body=%s", w.Code, w.Body.String())
	}
}

// Status change still works on a non-open order (KDS bumps while
// cashier is at /checkout). Cloud's updateItem deliberately omits the
// order-status assertion for the status branch.
func TestHandlePosUpdateItem_StatusOnPayingOrder_200(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	orderID, itemID := seedItemForStatusTest(t, srv)
	mustExec(t, srv.db, `UPDATE orders SET status='paying' WHERE id=?`, orderID)

	body, _ := json.Marshal(map[string]any{"status": "ready"})
	req := httptest.NewRequest("PATCH",
		"/api/v1/pos/orders/"+orderID+"/items/"+itemID,
		bytes.NewReader(body))
	req.SetPathValue("id", orderID)
	req.SetPathValue("item", itemID)
	w := httptest.NewRecorder()
	srv.handleLocalPosUpdateItem(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("status change on paying: want 200, got %d body=%s", w.Code, w.Body.String())
	}
}
