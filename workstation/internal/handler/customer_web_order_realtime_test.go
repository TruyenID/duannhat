package handler

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// cloudServingOrder stands up a fake Cloud whose
// GET /api/v1/workstation/orders?id=… returns exactly one order, in the same
// envelope the real controller emits.
func cloudServingOrder(t *testing.T, raw []byte) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[` + string(raw) + `],"count":1,"cursor_field":"id"}`))
	}))
	t.Cleanup(srv.Close)
	return srv
}

// End-to-end coverage for the customer-web → workstation → pos-web path.
//
// The unit tests in internal/service pin what upsertOrder writes to SQLite.
// This one closes the loop on the part the operator actually sees: after a
// customer-web order is pulled DOWN from Cloud, does the LAN order shape that
// pos-web parses carry the table binding, the extras and the takeaway contact —
// and does the workstation tell pos-web that anything happened at all?

// cwOrderJSON is a trimmed copy of a real
// GET /api/v1/workstation/orders payload for a customer-web QR dine-in order.
// Money arrives as decimal STRINGS ("50.00") and `tables[]`/`toppings[]` are the
// shapes Cloud actually emits — both verified against the live backend.
const cwOrderJSON = `{
  "id": "cw-order-1",
  "order_code": "ORD-2026-4343",
  "order_type": "dine_in",
  "status": "open",
  "opened_at": "2026-07-21T10:00:00Z",
  "updated_at": "2026-07-21T10:00:00Z",
  "branch_id": "branch-A",
  "brand_id": "brand-1",
  "organization_id": "org-1",
  "customer_takeaway_name": "Truyền Văn",
  "customer_takeaway_phone": "0336 909 454",
  "customer_locale": "vi",
  "note": "Ít cay",
  "subtotal": "1980.00",
  "total_amount": "1980.00",
  "paid_amount": "0.00",
  "tables": [{"id": "tbl-a", "code": "A1", "status": "occupied"}],
  "items": [{
    "id": "cw-item-1",
    "product_sku_id": "sku-1",
    "quantity": 1,
    "unit_price": "1930.00",
    "topping_subtotal": "50.00",
    "subtotal": "1980.00",
    "status": "pending",
    "note": "Không hành",
    "updated_at": "2026-07-21T10:00:00Z",
    "toppings": [{
      "id": "top-1",
      "topping_group_id": "grp-1",
      "topping_group_name": "麺種類フォーミニ",
      "topping_group_item_id": "gi-1",
      "product_sku_id": "sku-top-1",
      "name": "Classic Beef Pho",
      "modifier_type": "add",
      "quantity": 1,
      "unit_price": "50.00"
    }]
  }]
}`

func TestCustomerWebOrder_PullDownSurfacesInLANShapeAndBroadcasts(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	if srv.orders == nil {
		srv.orders = service.NewOrderEngine(srv.db)
	}
	// The table replica is pulled from TMS independently; loadOrderTables joins
	// against it for the display label.
	mustExec(t, srv.db, `INSERT INTO tables (id, name, status) VALUES ('tbl-a','A1','occupied')`)

	cloud := cloudServingOrder(t, []byte(cwOrderJSON))
	puller := service.NewSyncPuller(srv.db, cloud.URL, func() string { return "T" })

	// Wire the same callback server.New wires in production.
	type broadcast struct {
		orderID, branchID string
		isNew             bool
	}
	var events []broadcast
	puller.SetOnOrderSynced(func(orderID, branchID string, isNew bool) {
		events = append(events, broadcast{orderID, branchID, isNew})
	})

	if err := puller.PullOrderNow(context.Background(), "cw-order-1"); err != nil {
		t.Fatalf("pull down: %v", err)
	}

	// ── 1. pos-web is told something arrived ────────────────────────────────
	if len(events) != 1 {
		t.Fatalf("expected 1 order_synced broadcast, got %d", len(events))
	}
	if !events[0].isNew || events[0].orderID != "cw-order-1" || events[0].branchID != "branch-A" {
		t.Errorf("broadcast wrong: %+v", events[0])
	}

	// ── 2. The order renders correctly in the shape pos-web parses ──────────
	o, err := srv.orders.GetByID("cw-order-1")
	if err != nil {
		t.Fatalf("order not stored locally: %v", err)
	}
	shape := srv.customerOrderShape(o, "vi")

	if got := shape["order_code"]; got != "ORD-2026-4343" {
		t.Errorf("order_code = %v", got)
	}
	if got := shape["order_type"]; got != "dine_in" {
		t.Errorf("order_type = %v", got)
	}
	if got := shape["customer_takeaway_name"]; got != "Truyền Văn" {
		t.Errorf("customer_takeaway_name = %v", got)
	}
	if got := shape["customer_takeaway_phone"]; got != "0336 909 454" {
		t.Errorf("customer_takeaway_phone = %v", got)
	}
	if got := shape["note"]; got != "Ít cay" {
		t.Errorf("note = %v", got)
	}

	// tables[] — the "Chưa có bàn" bug. Cloud sends the binding; before the fix
	// the order_tables pivot was never written so this came back empty.
	tables, ok := shape["tables"].([]map[string]any)
	if !ok || len(tables) != 1 {
		t.Fatalf("shape.tables = %#v, want 1 row", shape["tables"])
	}
	if tables[0]["id"] != "tbl-a" || tables[0]["code"] != "A1" {
		t.Errorf("table row wrong: %#v", tables[0])
	}

	// items[] with the extras surcharge and the modifiers.
	items, ok := shape["items"].([]map[string]any)
	if !ok || len(items) != 1 {
		t.Fatalf("shape.items = %#v, want 1 row", shape["items"])
	}
	if got := items[0]["topping_subtotal"]; got != 50 {
		t.Errorf("topping_subtotal = %#v, want 50", got)
	}
	if got := items[0]["note"]; got != "Không hành" {
		t.Errorf("item note = %v", got)
	}
	toppings, ok := items[0]["toppings"].([]map[string]any)
	if !ok || len(toppings) != 1 {
		t.Fatalf("item toppings = %#v, want 1 row", items[0]["toppings"])
	}
	if toppings[0]["name"] != "Classic Beef Pho" || toppings[0]["modifier_type"] != "add" {
		t.Errorf("topping row wrong: %#v", toppings[0])
	}
	if toppings[0]["topping_group_name"] != "麺種類フォーミニ" {
		t.Errorf("topping_group_name lost: %#v", toppings[0]["topping_group_name"])
	}

	// ── 3. The order is visible to the open-orders list pos-web queries ─────
	// A customer-web counter-pay takeaway arrives `confirmed`; a QR dine-in
	// arrives `open`. Both must pass the LAN list filter.
	orders, total, err := srv.orders.ListByFilters(service.ListFilters{
		BranchID: "branch-A",
		Statuses: []string{"pending", "awaiting_confirmation", "confirmed", "open", "dining", "checkout", "paying"},
		PerPage:  100,
		Page:     1,
	})
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if total != 1 || len(orders) != 1 || orders[0].ID != "cw-order-1" {
		t.Fatalf("open-orders list missed the customer-web order: total=%d rows=%d", total, len(orders))
	}
}

// The counter-pay takeaway variant: Cloud stamps `confirmed`, which used to sit
// outside every filter pos-web asked for, making the order invisible even after
// a manual refresh.
func TestCustomerWebOrder_ConfirmedTakeawayIsListed(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	if srv.orders == nil {
		srv.orders = service.NewOrderEngine(srv.db)
	}
	var payload map[string]any
	if err := json.Unmarshal([]byte(cwOrderJSON), &payload); err != nil {
		t.Fatalf("fixture: %v", err)
	}
	payload["id"] = "cw-takeaway-1"
	payload["order_type"] = "takeaway"
	payload["status"] = "confirmed"
	payload["tables"] = []any{}
	raw, _ := json.Marshal(payload)

	cloud := cloudServingOrder(t, raw)
	puller := service.NewSyncPuller(srv.db, cloud.URL, func() string { return "T" })

	if err := puller.PullOrderNow(context.Background(), "cw-takeaway-1"); err != nil {
		t.Fatalf("pull down: %v", err)
	}

	orders, _, err := srv.orders.ListByFilters(service.ListFilters{
		BranchID:  "branch-A",
		OrderType: "takeaway",
		Statuses:  []string{"pending", "awaiting_confirmation", "confirmed", "open", "dining", "checkout", "paying"},
		PerPage:   100,
		Page:      1,
	})
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(orders) != 1 || orders[0].ID != "cw-takeaway-1" {
		t.Fatalf("confirmed takeaway not listed: %d rows", len(orders))
	}
	if orders[0].Status != "confirmed" {
		t.Errorf("status = %q, want confirmed", orders[0].Status)
	}
}

// #3123 — a customer-web dine-in order whose FIRST appearance on this
// workstation is already `closed` must still reach the kitchen.
//
// The shape is the real ORD-2026-0695 incident at 人形町店: the guest ordered at
// a QR table and settled with PayPay 31 seconds later, so the order was born and
// paid in Cloud between two of this workstation's pulls. It landed here as a
// single first-insert carrying status=closed, paid=total and one line already
// marked `served` by Cloud.
//
// This drives the REAL sync-DOWN path (PullOrderNow → upsertOrder → the pull's
// own hooks), wired to the same handlers server.go wires in production — not
// handleOrderArrivedAutoPrint called by hand. The old code returned on `closed`
// and the paid hook fires the kitchen only for takeaway Mode A, so the cook was
// never told: firedItemCount stayed 0 while the receipt and the table-paid slip
// both printed.
func TestCustomerWebOrder_ClosedOnArrivalStillFiresKitchen(t *testing.T) {
	closedDineInPulledDown := func(t *testing.T, kitchenToggle string) *Server {
		t.Helper()
		srv, _ := newServerWithAuth(t, "http://unused")
		srv.orders = service.NewOrderEngine(srv.db)
		srv.devices = printer.NewManager(srv.db)
		setWSSetting(t, srv, "auto_print_kitchen", kitchenToggle)

		// opened_at must be RECENT: the arrival path is age-gated
		// (syncedOrderTooOldToAutoPrint) so a backfill of old closed orders
		// cannot storm the kitchen. A live order is seconds old when first seen.
		now := time.Now().UTC().Format(time.RFC3339)
		var payload map[string]any
		if err := json.Unmarshal([]byte(cwOrderJSON), &payload); err != nil {
			t.Fatalf("seed payload: %v", err)
		}
		payload["id"] = "cw-closed-1"
		payload["order_code"] = "ORD-2026-0695"
		payload["order_type"] = "dine_in"
		payload["status"] = "closed"
		payload["paid_amount"] = "1980.00"
		payload["opened_at"] = now
		payload["updated_at"] = now
		// Cloud reported the single line as already served on this order.
		items := payload["items"].([]any)
		items[0].(map[string]any)["status"] = "served"
		raw, _ := json.Marshal(payload)

		cloud := cloudServingOrder(t, raw)
		puller := service.NewSyncPuller(srv.db, cloud.URL, func() string { return "T" })
		// Exactly the wiring server.go installs.
		puller.SetOnOrderPaid(func(orderID, _ string, amount int) {
			srv.handleOrderPaidAutoPrint(orderID, amount)
		})
		puller.SetOnOrderArrived(srv.handleOrderArrivedAutoPrint)
		puller.SetOnOrderMerged(srv.handleOrderMergedAutoPrint)

		if err := puller.PullOrderNow(context.Background(), "cw-closed-1"); err != nil {
			t.Fatalf("pull down: %v", err)
		}
		return srv
	}

	t.Run("toggle on fires the kitchen", func(t *testing.T) {
		srv := closedDineInPulledDown(t, "true")
		if got := firedItemCount(t, srv, "cw-closed-1"); got != 1 {
			t.Fatalf("closed-on-arrival dine-in reached the kitchen 0 times: want 1 fired, got %d", got)
		}
	})

	// The fix must not become an unconditional print: the shop's toggle is what
	// decides whether customer-web dine-in orders auto-fire at all.
	t.Run("toggle off stays silent", func(t *testing.T) {
		srv := closedDineInPulledDown(t, "false")
		if got := firedItemCount(t, srv, "cw-closed-1"); got != 0 {
			t.Fatalf("auto_print_kitchen off must not fire: got %d", got)
		}
	})
}
