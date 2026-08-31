package handler

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/audit"
	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// newFireTestServer builds a minimal Server with the bits T1.3's snapshot
// tests need: order engine, printer Manager, audit logger. No HTTP server,
// no Cloud verifier — handlers are called directly with httptest recorders.
func newFireTestServer(t *testing.T) *Server {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "fire.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	s := &Server{
		db:      db,
		orders:  service.NewOrderEngine(db),
		devices: printer.NewManager(db),
		audit:   audit.NewLogger(db),
	}
	return s
}

// seedSpotOrder inserts a minimal SKU + order + items for fire tests.
// `groups` is a slice of printer_group strings; one item per group.
func seedSpotOrder(t *testing.T, s *Server, groups []string) *service.Order {
	t.Helper()
	if _, err := s.db.Exec(
		`INSERT OR IGNORE INTO pos_products (id, name) VALUES ('p1', 'Phở')`,
	); err != nil {
		t.Fatal(err)
	}
	if _, err := s.db.Exec(`
		INSERT OR IGNORE INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-1', 'p1', 'Regular', 'SKU-1', 50000, 1)`); err != nil {
		t.Fatal(err)
	}

	o, err := s.orders.Create(service.CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("create order: %v", err)
	}
	items := make([]service.CreateItemInput, 0, len(groups))
	for range groups {
		items = append(items, service.CreateItemInput{ProductSkuID: "sku-1", Quantity: 1})
	}
	if _, err := s.orders.AddItems(o.ID, items); err != nil {
		t.Fatalf("add items: %v", err)
	}
	// Reload to capture item IDs, then assign printer_group per the slice.
	o, _ = s.orders.GetByID(o.ID)
	for i, item := range o.Items {
		if i >= len(groups) {
			break
		}
		if _, err := s.db.Exec(
			`UPDATE order_items SET printer_group = ? WHERE id = ?`,
			groups[i], item.ID,
		); err != nil {
			t.Fatal(err)
		}
	}
	o, _ = s.orders.GetByID(o.ID)
	return o
}

// TestHandyFire_OrderNotFound — pre-refactor contract: 404 with the exact
// {message: "order not found"} body. Snapshot guard for plan-038 T1.3.
func TestHandyFire_OrderNotFound(t *testing.T) {
	s := newFireTestServer(t)

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/handy/orders/missing-id/fire", nil)
	req.SetPathValue("order", "missing-id")
	req = req.WithContext(context.Background())
	s.handleLocalHandyFireOrder(w, req)

	if w.Code != http.StatusNotFound {
		t.Errorf("status: want 404, got %d", w.Code)
	}
	var body struct{ Message string }
	_ = json.Unmarshal(w.Body.Bytes(), &body)
	if body.Message != "order not found" {
		t.Errorf("message: want 'order not found', got %q", body.Message)
	}
}

// TestHandyFire_NoUnprintedItems — pre-refactor contract: 422 with the
// exact {message: "no unprinted items"} body when every item is already
// sent_to_kitchen.
func TestHandyFire_NoUnprintedItems(t *testing.T) {
	s := newFireTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen"})

	// Mark all items printed manually so the helper sees nothing to fire.
	for _, item := range o.Items {
		if err := s.orders.MarkItemPrinted(item.ID, item.Quantity, "2026-06-20T10:00:00Z"); err != nil {
			t.Fatal(err)
		}
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/handy/orders/"+o.ID+"/fire", nil)
	req.SetPathValue("order", o.ID)
	s.handleLocalHandyFireOrder(w, req)

	if w.Code != http.StatusUnprocessableEntity {
		t.Errorf("status: want 422, got %d (body=%s)", w.Code, w.Body.String())
	}
	var body struct{ Message string }
	_ = json.Unmarshal(w.Body.Bytes(), &body)
	if body.Message != "no unprinted items" {
		t.Errorf("message: want 'no unprinted items', got %q", body.Message)
	}
}

// TestHandyFire_NoPrinter — KDS-only kitchen contract: 200 status=partial with
// the legacy flat error "no kitchen printer configured", but the item is still
// FIRED to the kitchen (KDS is the ticket) so printed=1, not 0. (Pre-KDS-fire
// contract reported printed=0 — changed so a printer-less kitchen still works.)
func TestHandyFire_NoPrinter(t *testing.T) {
	s := newFireTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen"})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/handy/orders/"+o.ID+"/fire", nil)
	req.SetPathValue("order", o.ID)
	s.handleLocalHandyFireOrder(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("status: want 200, got %d (body=%s)", w.Code, w.Body.String())
	}
	var body struct {
		Status  string   `json:"status"`
		Printed int      `json:"printed"`
		Errors  []string `json:"errors"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	if body.Status != "partial" {
		t.Errorf("status: want 'partial', got %q", body.Status)
	}
	if body.Printed != 1 {
		t.Errorf("printed: want 1 (item fired to KDS), got %d", body.Printed)
	}
	if len(body.Errors) != 1 || body.Errors[0] != "no kitchen printer configured" {
		t.Errorf("errors: want exactly ['no kitchen printer configured'], got %#v", body.Errors)
	}

	// The item must now be fired (no longer pending-print) even without paper.
	o2, _ := s.orders.GetByID(o.ID)
	if o2.Items[0].PrintStatus != service.PrintStatusSentToKitchen {
		t.Errorf("item print_status: want sent_to_kitchen, got %q", o2.Items[0].PrintStatus)
	}

	// Audit log row should record source=handy + printed=1 + errors=1.
	row := s.db.QueryRow(
		`SELECT details FROM audit_log WHERE action = 'order.fire' ORDER BY id DESC LIMIT 1`,
	)
	var details string
	if err := row.Scan(&details); err != nil {
		t.Fatalf("read audit: %v", err)
	}
	wantDetails := `{"source":"handy","printed":1,"errors":1}`
	if details != wantDetails {
		t.Errorf("audit details: want %q, got %q", wantDetails, details)
	}
}

// TestHandyFire_NoPrinter_BarGroup — the dispatcher routes bar items to
// bar_printer, so when neither bar nor kitchen is configured the error
// surfaces with the legacy flat message PROJECTED from the structured
// "no_printer:bar_printer" — i.e. the handy app still receives the
// fallback "no bar_printer configured" copy (preserving the convention
// of a short kitchen-side hint without leaking the structured reason).
func TestHandyFire_NoPrinter_BarGroup(t *testing.T) {
	s := newFireTestServer(t)
	o := seedSpotOrder(t, s, []string{"bar"})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/handy/orders/"+o.ID+"/fire", nil)
	req.SetPathValue("order", o.ID)
	s.handleLocalHandyFireOrder(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("status: want 200, got %d", w.Code)
	}
	var body struct {
		Status string   `json:"status"`
		Errors []string `json:"errors"`
	}
	_ = json.Unmarshal(w.Body.Bytes(), &body)
	if body.Status != "partial" {
		t.Errorf("status: want 'partial', got %q", body.Status)
	}
	if len(body.Errors) != 1 {
		t.Fatalf("errors: want 1, got %d (%v)", len(body.Errors), body.Errors)
	}
	// The handy contract returns the structured Detail (free-form). The
	// new dispatcher emits "no bar_printer configured" for the bar group;
	// "no kitchen_printer configured" for kitchen group. Both are accept-
	// able shapes for the handy projection — assert the role surfaces.
	if !strings.Contains(body.Errors[0], "bar_printer") {
		t.Errorf("errors[0] should mention bar_printer (the dispatcher's resolved role); got %q", body.Errors[0])
	}
}

// TestHandyCreateOrder_SyncPayloadParity asserts the handy create handler
// enqueues a sync-UP payload matching pos (plan-041): client_order_id present
// as the durable idempotency key, table_ids resolved, and the legacy
// order_code field NOT sent (Cloud is the single authority for ORD-####).
func TestHandyCreateOrder_SyncPayloadParity(t *testing.T) {
	s := newFireTestServer(t)
	s.hub = NewHub()
	s.sync = service.NewSyncEngine(s.db, "http://cloud.invalid", nil)

	bodyJSON := `{"order_type":"dine_in","table_ids":["tbl-1"],"guest_count":3,"note":"x"}`
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/handy/orders", strings.NewReader(bodyJSON))
	s.handleLocalHandyCreateOrder(w, req)

	if w.Code != http.StatusCreated {
		t.Fatalf("status: want 201, got %d (body=%s)", w.Code, w.Body.String())
	}
	var resp struct {
		Data struct {
			ID string `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("unmarshal resp: %v", err)
	}
	if resp.Data.ID == "" {
		t.Fatal("response missing data.id")
	}

	// Read the enqueued sync payload back out of the queue.
	var payloadStr string
	if err := s.db.QueryRow(
		`SELECT payload FROM sync_queue WHERE entity_type='order' AND entity_id=? AND operation='create'`,
		resp.Data.ID,
	).Scan(&payloadStr); err != nil {
		t.Fatalf("read sync_queue: %v", err)
	}
	var payload struct {
		Order map[string]any `json:"order"`
	}
	if err := json.Unmarshal([]byte(payloadStr), &payload); err != nil {
		t.Fatalf("unmarshal payload: %v", err)
	}

	if got, _ := payload.Order["client_order_id"].(string); got != resp.Data.ID {
		t.Errorf("client_order_id: want %q, got %q", resp.Data.ID, got)
	}
	if _, present := payload.Order["order_code"]; present {
		t.Error("order_code must NOT be in the sync payload (Cloud mints ORD-####)")
	}
	ids, _ := payload.Order["table_ids"].([]any)
	if len(ids) != 1 || ids[0] != "tbl-1" {
		t.Errorf("table_ids: want [tbl-1], got %#v", payload.Order["table_ids"])
	}
}

// A void from the handheld must reach Cloud.
//
// The POS void enqueues `order.void`; this handler did not, so an order voided
// on a handheld stayed ACTIVE on Cloud forever — local said voided, Cloud kept
// counting it as revenue. The table WAS freed on Cloud (via the table.status
// sync op), which made it harder to notice: the floor looked right while the
// money did not.
func TestHandyVoidOrder_EnqueuesSync(t *testing.T) {
	s := newFireTestServer(t)
	s.sync = service.NewSyncEngine(s.db, "", nil)
	s.hub = NewHub()

	order := seedSpotOrder(t, s, []string{"kitchen"})

	req := httptest.NewRequest(
		http.MethodPost,
		"/api/v1/handy/orders/"+order.ID+"/void",
		strings.NewReader(`{"void_reason":"khách đổi ý"}`),
	)
	req.SetPathValue("order", order.ID)
	rec := httptest.NewRecorder()

	s.handleLocalHandyVoidOrder(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200 (%s)", rec.Code, rec.Body.String())
	}

	var n int
	if err := s.db.QueryRow(
		`SELECT COUNT(*) FROM sync_queue WHERE entity_type = 'order' AND operation = 'void' AND entity_id = ?`,
		order.ID,
	).Scan(&n); err != nil {
		t.Fatal(err)
	}
	if n != 1 {
		t.Fatalf("sync_queue rows for order.void = %d, want 1 — the void never reaches Cloud", n)
	}
}
