package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
)

// POST /api/v1/pos/orders/{id}/confirm — the "Tiếp nhận đơn" button.
// Accepts a customer-submitted takeaway (pending|confirmed → open) so it can
// flow through the regular checkout pipeline, enqueues the order.confirm sync
// op, and is idempotent when another terminal already accepted.

func seedConfirmOrder(t *testing.T, srv *Server, db *store.DB, status string) string {
	t.Helper()
	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "takeaway"}, nil)
	if err != nil {
		t.Fatalf("create order: %v", err)
	}
	if _, err := db.Exec(`UPDATE orders SET status = ? WHERE id = ?`, status, o.ID); err != nil {
		t.Fatalf("set status: %v", err)
	}
	return o.ID
}

func postConfirm(t *testing.T, srv *Server, orderID string) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/"+orderID+"/confirm",
		bytes.NewReader([]byte(`{}`)))
	req.SetPathValue("id", orderID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosConfirmOrder(rec, req)
	return rec
}

func TestPosConfirmOrder_ConfirmedFlipsToOpen_AndEnqueuesSync(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.orders = service.NewOrderEngine(db)
	srv.hub = NewHub()

	orderID := seedConfirmOrder(t, srv, db, "confirmed")
	rec := postConfirm(t, srv, orderID)

	if rec.Code != http.StatusOK {
		t.Fatalf("status: want 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	var env struct {
		Data map[string]any `json:"data"`
	}
	if err := json.NewDecoder(rec.Body).Decode(&env); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if got, _ := env.Data["status"].(string); got != "open" {
		t.Errorf("response status want open, got %q", got)
	}

	var dbStatus string
	db.QueryRow(`SELECT status FROM orders WHERE id = ?`, orderID).Scan(&dbStatus)
	if dbStatus != "open" {
		t.Errorf("db status want open, got %s", dbStatus)
	}

	var queued int
	db.QueryRow(`SELECT COUNT(*) FROM sync_queue
	              WHERE entity_type = 'order' AND operation = 'confirm' AND entity_id = ?`,
		orderID).Scan(&queued)
	if queued != 1 {
		t.Errorf("want 1 order.confirm sync row, got %d", queued)
	}
}

func TestPosConfirmOrder_PendingFlipsToOpen(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.orders = service.NewOrderEngine(db)
	srv.hub = NewHub()

	orderID := seedConfirmOrder(t, srv, db, "pending")
	rec := postConfirm(t, srv, orderID)

	if rec.Code != http.StatusOK {
		t.Fatalf("status: want 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	var dbStatus string
	db.QueryRow(`SELECT status FROM orders WHERE id = ?`, orderID).Scan(&dbStatus)
	if dbStatus != "open" {
		t.Errorf("db status want open, got %s", dbStatus)
	}
}

// Two terminals racing: the second accept lands on an already-open order and
// must succeed silently — no error, no duplicate sync row.
func TestPosConfirmOrder_AlreadyOpenIsIdempotentNoOp(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.orders = service.NewOrderEngine(db)
	srv.hub = NewHub()

	orderID := seedConfirmOrder(t, srv, db, "confirmed")
	if rec := postConfirm(t, srv, orderID); rec.Code != http.StatusOK {
		t.Fatalf("first accept: %d", rec.Code)
	}
	rec := postConfirm(t, srv, orderID)
	if rec.Code != http.StatusOK {
		t.Fatalf("second accept: want 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	var queued int
	db.QueryRow(`SELECT COUNT(*) FROM sync_queue
	              WHERE entity_type = 'order' AND operation = 'confirm' AND entity_id = ?`,
		orderID).Scan(&queued)
	if queued != 1 {
		t.Errorf("idempotent replay must not enqueue again: want 1 row, got %d", queued)
	}
}

func TestPosConfirmOrder_CheckoutOrderRejected409(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.orders = service.NewOrderEngine(db)
	srv.hub = NewHub()

	orderID := seedConfirmOrder(t, srv, db, "checkout")
	rec := postConfirm(t, srv, orderID)
	if rec.Code != http.StatusConflict {
		t.Fatalf("want 409 on a checkout order, got %d", rec.Code)
	}
	var dbStatus string
	db.QueryRow(`SELECT status FROM orders WHERE id = ?`, orderID).Scan(&dbStatus)
	if dbStatus != "checkout" {
		t.Errorf("status must stay checkout, got %s", dbStatus)
	}
}

func TestPosConfirmOrder_UnknownOrder404(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.orders = service.NewOrderEngine(db)
	srv.hub = NewHub()

	rec := postConfirm(t, srv, "no-such-order")
	if rec.Code != http.StatusNotFound {
		t.Fatalf("want 404, got %d", rec.Code)
	}
}
