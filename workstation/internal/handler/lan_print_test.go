package handler

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/audit"
	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
)

// newLANPrintTestServer builds a minimal Server for handler-level tests.
// Reuses the seeding pattern from local_handy_test.go.
func newLANPrintTestServer(t *testing.T) *Server {
	t.Helper()
	s := newFireTestServer(t)
	return s
}

// stubAuth puts a DeviceContext on the request context so the handler
// doesn't short-circuit with 401 — the real auth path is exercised
// separately by the authMW tests.
func stubAuth(req *http.Request) *http.Request {
	d := &DeviceContext{ID: "d-test", Type: "workstation", BranchID: "br-1", IdentityType: "device"}
	ctx := context.WithValue(req.Context(), deviceCtxKey, d)
	return req.WithContext(ctx)
}

func TestLANPrintKitchen_400_OnMissingOrderID(t *testing.T) {
	s := newLANPrintTestServer(t)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-ticket",
		bytes.NewBufferString(`{}`))
	req = stubAuth(req)
	s.handleLANPrintKitchenTicket(w, req)
	if w.Code != http.StatusBadRequest {
		t.Errorf("want 400, got %d (%s)", w.Code, w.Body.String())
	}
}

func TestLANPrintKitchen_401_OnUnauth(t *testing.T) {
	s := newLANPrintTestServer(t)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-ticket",
		bytes.NewBufferString(`{"order_id":"x"}`))
	s.handleLANPrintKitchenTicket(w, req)
	if w.Code != http.StatusUnauthorized {
		t.Errorf("want 401, got %d", w.Code)
	}
}

func TestLANPrintKitchen_404_WhenOrderMissingAndNoPuller(t *testing.T) {
	s := newLANPrintTestServer(t)
	s.puller = nil
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-ticket",
		bytes.NewBufferString(`{"order_id":"missing"}`))
	req = stubAuth(req)
	s.handleLANPrintKitchenTicket(w, req)
	if w.Code != http.StatusNotFound {
		t.Errorf("want 404, got %d (%s)", w.Code, w.Body.String())
	}
}

func TestLANPrintKitchen_422_WhenAllItemsAlreadyPrinted(t *testing.T) {
	s := newLANPrintTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen"})
	for _, item := range o.Items {
		_ = s.orders.MarkItemPrinted(item.ID, item.Quantity, "2026-06-20T10:00:00Z")
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-ticket",
		bytes.NewBufferString(`{"order_id":"`+o.ID+`"}`))
	req = stubAuth(req)
	s.handleLANPrintKitchenTicket(w, req)
	if w.Code != http.StatusUnprocessableEntity {
		t.Errorf("want 422, got %d (%s)", w.Code, w.Body.String())
	}
}

// pendingCount calls GET /api/lan/print/status?order_id=… and returns the
// reported open_items_pending_print.
func pendingCount(t *testing.T, s *Server, orderID string) int {
	t.Helper()
	w := httptest.NewRecorder()
	req := stubAuth(httptest.NewRequest(http.MethodGet,
		"/api/lan/print/status?order_id="+orderID, nil))
	s.handleLANPrintStatus(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("status: want 200, got %d (%s)", w.Code, w.Body.String())
	}
	var body struct {
		Order struct {
			OpenItemsPendingPrint int `json:"open_items_pending_print"`
		} `json:"order"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
		t.Fatalf("decode status: %v", err)
	}
	return body.Order.OpenItemsPendingPrint
}

// TestLANPrintKitchen_DeltaAfterQuantityBump is the exact reported repro:
// an already-printed line whose quantity is then increased must become
// printable again — the new units surface as pending and the kitchen-ticket
// pre-check must NOT reject with 422.
func TestLANPrintKitchen_DeltaAfterQuantityBump(t *testing.T) {
	s := newLANPrintTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen"})
	item := o.Items[0]

	// Fire the initial quantity (1) — mark the line fully printed.
	if err := s.orders.MarkItemPrinted(item.ID, item.Quantity, "2026-06-20T10:00:00Z"); err != nil {
		t.Fatal(err)
	}
	if got := pendingCount(t, s, o.ID); got != 0 {
		t.Fatalf("after fire: want 0 pending, got %d", got)
	}

	// Cashier bumps the already-printed line 1 → 3 via the same path pos-web
	// uses (PATCH item → OrderEngine.UpdateItem).
	q := 3
	if _, err := s.orders.UpdateItem(o.ID, item.ID, service.ItemPatch{Quantity: &q}); err != nil {
		t.Fatalf("bump qty: %v", err)
	}

	// The 2 newly-added units must now surface as an unprinted delta.
	if got := pendingCount(t, s, o.ID); got != 1 {
		t.Fatalf("after bump: want 1 line pending, got %d", got)
	}

	// The kitchen-ticket pre-check must NOT 422 — it should proceed to fire
	// (200 partial here: the test server has no printer, so items fire to KDS
	// only). A 422 would mean the regression is back.
	w := httptest.NewRecorder()
	req := stubAuth(httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-ticket",
		bytes.NewBufferString(`{"order_id":"`+o.ID+`"}`)))
	s.handleLANPrintKitchenTicket(w, req)
	if w.Code == http.StatusUnprocessableEntity {
		t.Fatalf("after bump: pre-check wrongly returned 422 (no unprinted items); body=%s", w.Body.String())
	}
}

// With no physical printer configured, firing must NOT fail: the KDS display
// is the kitchen ticket, so the items are still dispatched (marked fired, no
// longer pending) and the response is 200 `partial` carrying the no_printer
// note — never a 503. (Old contract: 503 no_printer. Changed so a KDS-only
// kitchen works.)
func TestLANPrintKitchen_NoPrinter_FiresToKDS(t *testing.T) {
	s := newLANPrintTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen"})

	w := httptest.NewRecorder()
	req := stubAuth(httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-ticket",
		bytes.NewBufferString(`{"order_id":"`+o.ID+`"}`)))
	s.handleLANPrintKitchenTicket(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d (%s)", w.Code, w.Body.String())
	}
	var body map[string]any
	_ = json.Unmarshal(w.Body.Bytes(), &body)
	if body["status"] != "partial" {
		t.Errorf("want status=partial, got %v", body["status"])
	}
	// printed reports the number of items fired to the kitchen (KDS), even
	// though no paper printed.
	if printed, _ := body["printed"].(float64); printed < 1 {
		t.Errorf("want printed>=1 (items fired to KDS), got %v", body["printed"])
	}
	// The errors array carries the no_printer note so pos-web can warn.
	errs, _ := body["errors"].([]any)
	if len(errs) == 0 {
		t.Errorf("want a no_printer error in the response, got none")
	} else if first, _ := errs[0].(map[string]any); first != nil {
		if reason, _ := first["reason"].(string); !strings.HasPrefix(reason, "no_printer:") {
			t.Errorf("want reason no_printer:*, got %v", reason)
		}
	}

	// Items are now fired → no longer counted as pending-print.
	if got := pendingCount(t, s, o.ID); got != 0 {
		t.Errorf("after no-printer fire: want 0 pending, got %d", got)
	}
}

// A line voided before it was ever fired (printed_quantity still 0, so the raw
// unprinted delta is > 0) must NOT be fired — neither printed nor surfaced on
// KDS. With only a voided item, the pre-check returns 422.
func TestLANPrintKitchen_VoidedItemNotFired(t *testing.T) {
	s := newLANPrintTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen"})
	if _, err := s.db.Exec(`UPDATE order_items SET status='voided' WHERE id=?`, o.Items[0].ID); err != nil {
		t.Fatal(err)
	}

	w := httptest.NewRecorder()
	req := stubAuth(httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-ticket",
		bytes.NewBufferString(`{"order_id":"`+o.ID+`"}`)))
	s.handleLANPrintKitchenTicket(w, req)

	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("voided-only order: want 422 (nothing to fire), got %d (%s)", w.Code, w.Body.String())
	}
}

// End-to-end: firing with no printer still broadcasts order.kitchen_printed so
// a connected KDS client renders the items live. This is the core of the
// "press send-to-kitchen → items appear on KDS" feature when the kitchen has
// no physical printer.
func TestLANPrintKitchen_NoPrinter_BroadcastsToKDS(t *testing.T) {
	s := newLANPrintTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen"})

	hub := NewHub()
	go hub.Run()
	defer hub.Stop()
	s.hub = hub

	verifier := &stubVerifier{identity: &service.Identity{
		Type: "device", DeviceID: "kds-1", DeviceType: "kds", BranchID: "br-1",
	}}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hub.ServeWS(w, r, verifier)
	}))
	defer srv.Close()

	ws := authAndWait(t, srv.URL)
	defer ws.Close()
	waitForClients(t, hub, 1)

	w := httptest.NewRecorder()
	req := stubAuth(httptest.NewRequest(http.MethodPost, "/api/lan/print/kitchen-ticket",
		bytes.NewBufferString(`{"order_id":"`+o.ID+`"}`)))
	s.handleLANPrintKitchenTicket(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("fire: want 200, got %d (%s)", w.Code, w.Body.String())
	}

	var msg Message
	ws.SetReadDeadline(time.Now().Add(2 * time.Second))
	if err := ws.ReadJSON(&msg); err != nil {
		t.Fatalf("expected order.kitchen_printed broadcast, got read error: %v", err)
	}
	if msg.Type != "order.kitchen_printed" {
		t.Fatalf("want order.kitchen_printed, got %s", msg.Type)
	}
}

func TestLANPrintStatus_RespondsWithPrinterRoles(t *testing.T) {
	s := newLANPrintTestServer(t)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/lan/print/status", nil)
	req = stubAuth(req)
	s.handleLANPrintStatus(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d (%s)", w.Code, w.Body.String())
	}
	var body map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	roles, ok := body["printer_roles"].(map[string]any)
	if !ok {
		t.Fatal("printer_roles missing")
	}
	for _, role := range []string{"kitchen_printer", "bar_printer", "hall_printer", "receipt_printer"} {
		if _, ok := roles[role]; !ok {
			t.Errorf("printer_roles missing %s", role)
		}
	}
}

func TestLANPrintStatus_OrderProjection(t *testing.T) {
	s := newLANPrintTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen", "bar"})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/lan/print/status?order_id="+o.ID, nil)
	req = stubAuth(req)
	s.handleLANPrintStatus(w, req)

	var body map[string]any
	_ = json.Unmarshal(w.Body.Bytes(), &body)
	ord, ok := body["order"].(map[string]any)
	if !ok {
		t.Fatal("order block missing")
	}
	if ord["in_local"] != true {
		t.Errorf("expected in_local=true, got %v", ord["in_local"])
	}
	// OrderEngine may consolidate same-SKU items into one line; either 1 or 2
	// pending counts is acceptable here. We only assert the projection
	// surfaces at least one pending item, not the exact granularity.
	if n, _ := ord["open_items_pending_print"].(float64); n < 1 {
		t.Errorf("expected open_items_pending_print >= 1, got %v", ord["open_items_pending_print"])
	}
}

func TestLANPrintReceipt_PaymentIDNotFound(t *testing.T) {
	s := newLANPrintTestServer(t)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/payment-receipt",
		bytes.NewBufferString(`{"order_id":"o1","payment_id":"missing"}`))
	req = stubAuth(req)
	s.handleLANPrintReceipt(w, req)
	if w.Code != http.StatusNotFound {
		t.Errorf("want 404, got %d (%s)", w.Code, w.Body.String())
	}
}

func TestLANPrintReceipt_ReprintReasonTooLong(t *testing.T) {
	s := newLANPrintTestServer(t)
	huge := strings.Repeat("x", 300)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/payment-receipt",
		bytes.NewBufferString(`{"order_id":"o1","reprint_reason":"`+huge+`"}`))
	req = stubAuth(req)
	s.handleLANPrintReceipt(w, req)
	if w.Code != http.StatusBadRequest {
		t.Errorf("want 400, got %d", w.Code)
	}
}

// Ensure stubAuth utility doesn't shadow / collide with the real auth path
// when the printer.Manager is wired in the future.
func TestLANPrint_PrinterTypesAreKnown(t *testing.T) {
	t.Helper()
	// Sanity guard so a future refactor doesn't drop a printer type.
	if !sliceHasDeviceType(printer.PrinterRoles, printer.TypeKitchenPrinter) {
		t.Error("PrinterRoles missing kitchen")
	}
}

func sliceHasDeviceType(s []printer.DeviceType, want printer.DeviceType) bool {
	for _, v := range s {
		if v == want {
			return true
		}
	}
	return false
}

// Unused imports guard: compile-time uses below for the linter.
var (
	_ = store.Open
	_ = audit.NewLogger
	_ = service.NewOrderEngine
)

// ─── order-bill (full order + QR, unlimited) ─────────────────────────────────

func TestLANPrintOrderBill_400_OnMissingOrderID(t *testing.T) {
	s := newLANPrintTestServer(t)
	w := httptest.NewRecorder()
	req := stubAuth(httptest.NewRequest(http.MethodPost, "/api/lan/print/order-bill", bytes.NewBufferString(`{}`)))
	s.handleLANPrintOrderBill(w, req)
	if w.Code != http.StatusBadRequest {
		t.Errorf("want 400, got %d (%s)", w.Code, w.Body.String())
	}
}

func TestLANPrintOrderBill_401_OnUnauth(t *testing.T) {
	s := newLANPrintTestServer(t)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/order-bill", bytes.NewBufferString(`{"order_id":"x"}`))
	s.handleLANPrintOrderBill(w, req)
	if w.Code != http.StatusUnauthorized {
		t.Errorf("want 401, got %d", w.Code)
	}
}

func TestLANPrintOrderBill_404_WhenOrderMissingAndNoPuller(t *testing.T) {
	s := newLANPrintTestServer(t)
	s.puller = nil
	w := httptest.NewRecorder()
	req := stubAuth(httptest.NewRequest(http.MethodPost, "/api/lan/print/order-bill", bytes.NewBufferString(`{"order_id":"missing"}`)))
	s.handleLANPrintOrderBill(w, req)
	if w.Code != http.StatusNotFound {
		t.Errorf("want 404, got %d (%s)", w.Code, w.Body.String())
	}
}

// Order exists but no printer configured → 503 no_printer. Proves the endpoint
// resolves the order and reaches printer selection for the FULL-order bill.
// (No reprint counter is touched — the button is unlimited by construction.)
func TestLANPrintOrderBill_503_NoPrinter(t *testing.T) {
	s := newLANPrintTestServer(t)
	o := seedSpotOrder(t, s, []string{"kitchen"})
	w := httptest.NewRecorder()
	req := stubAuth(httptest.NewRequest(http.MethodPost, "/api/lan/print/order-bill",
		bytes.NewBufferString(`{"order_id":"`+o.ID+`"}`)))
	s.handleLANPrintOrderBill(w, req)
	if w.Code != http.StatusServiceUnavailable {
		t.Errorf("want 503, got %d (%s)", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), "no_printer") {
		t.Errorf("want no_printer, got %s", w.Body.String())
	}
}
