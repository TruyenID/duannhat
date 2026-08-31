package handler

import (
	"net/http"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// Mode A (`prep_before_payment`) holds the kitchen until the customer pays, so
// SOMETHING has to fire it on the payment event. Every other settle path owns
// that call; the POS counter closes the order inline and did not, so a takeaway
// order paid at the till reached the kitchen never — the items stayed un-fired,
// which also means no reprint button covered the gap.
//
// The assertions below are deliberately split by concern: the kitchen MUST
// fire, and the table-paid slip MUST NOT. The second half is what stops this
// being "fixed" by calling handleLocalPaymentAutoPrint, which would also drag
// in the table-paid slip that TestTablePaid_PosCounterSettleDoesNotFire pins as
// a decision.

func seedTakeawayCheckoutOrder(t *testing.T, srv *Server) string {
	t.Helper()
	if _, err := srv.db.Exec(
		`INSERT OR IGNORE INTO pos_products (id, name) VALUES ('p-tk', 'Phở')`); err != nil {
		t.Fatal(err)
	}
	if _, err := srv.db.Exec(`
		INSERT OR IGNORE INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-tk', 'p-tk', 'Regular', 'SKU-TK', 1000, 1)`); err != nil {
		t.Fatal(err)
	}
	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "takeaway"}, nil)
	if err != nil {
		t.Fatalf("create order: %v", err)
	}
	if _, err := srv.orders.AddItems(o.ID, []service.CreateItemInput{
		{ProductSkuID: "sku-tk", Quantity: 1},
	}); err != nil {
		t.Fatalf("add items: %v", err)
	}
	o, _ = srv.orders.GetByID(o.ID)
	for _, it := range o.Items {
		mustExec(t, srv.db, `UPDATE order_items SET printer_group = 'kitchen' WHERE id = ?`, it.ID)
	}
	mustExec(t, srv.db,
		`UPDATE orders SET total_amount = 1000, status = 'checkout' WHERE id = ?`, o.ID)
	return o.ID
}

func newCounterPayServer(t *testing.T) *Server {
	t.Helper()
	srv, _ := newServerWithAuth(t, "http://unused")
	seedOpenShift(t, srv)
	srv.orders = service.NewOrderEngine(srv.db)
	srv.hub = NewHub()
	srv.devices = printer.NewManager(srv.db)
	srv.idempotency = service.NewIdempotencyStore(srv.db)
	mustExec(t, srv.db,
		`INSERT INTO payment_methods (id, code, name, is_active, sort_order, is_auto_confirm)
		 VALUES ('pm-cash', 'cash', 'Cash', 1, 0, 1)`)
	return srv
}

// The regression itself. prep_before_payment is unset, which resolves to Mode A
// (the backend brand default), so this is the out-of-the-box configuration.
func TestPosCounterPay_FiresKitchenForTakeawayModeA(t *testing.T) {
	srv := newCounterPayServer(t)
	orderID := seedTakeawayCheckoutOrder(t, srv)

	if fired := firedItemCount(t, srv, orderID); fired != 0 {
		t.Fatalf("precondition: Mode A must leave the kitchen un-fired before payment, got %d", fired)
	}

	rec := postBroadcastCashPayment(t, srv, orderID, 1000, "idem-counter-kitchen")
	if rec.Code != http.StatusOK && rec.Code != http.StatusCreated {
		t.Fatalf("payment POST: want 200/201, got %d — %s", rec.Code, rec.Body.String())
	}

	if fired := firedItemCount(t, srv, orderID); fired == 0 {
		t.Fatalf("counter-pay settled a Mode A takeaway order but the kitchen was never fired")
	}
}

// Mode B already fired on arrival, so paying must not re-send the same units.
// The delta guard is what makes that true; this pins it at the handler level so
// a future unconditional call is caught here rather than on a shop's paper.
func TestPosCounterPay_ModeBDoesNotRefireKitchen(t *testing.T) {
	srv := newCounterPayServer(t)
	setShopSetting(t, srv, "prep_before_payment", "false") // Mode B
	orderID := seedTakeawayCheckoutOrder(t, srv)

	// Simulate the arrival fire Mode B performs.
	srv.autoPrintKitchen(orderID)
	before := firedItemCount(t, srv, orderID)
	if before == 0 {
		t.Fatalf("precondition: Mode B fires on arrival, got 0 fired")
	}

	rec := postBroadcastCashPayment(t, srv, orderID, 1000, "idem-counter-modeb")
	if rec.Code != http.StatusOK && rec.Code != http.StatusCreated {
		t.Fatalf("payment POST: want 200/201, got %d — %s", rec.Code, rec.Body.String())
	}
	if after := firedItemCount(t, srv, orderID); after != before {
		t.Fatalf("Mode B must not re-fire on payment: %d fired before, %d after", before, after)
	}
}

// Dine-in is untouched: the counter cashier witnessed the payment, and the
// kitchen for a dine-in order is fired by staff (or by the dine-in auto-print
// toggle on arrival), never by the act of settling.
func TestPosCounterPay_DineInDoesNotFireKitchen(t *testing.T) {
	srv := newCounterPayServer(t)
	orderID := seedTakeawayCheckoutOrder(t, srv)
	mustExec(t, srv.db, `UPDATE orders SET order_type = 'dine_in' WHERE id = ?`, orderID)

	rec := postBroadcastCashPayment(t, srv, orderID, 1000, "idem-counter-dinein")
	if rec.Code != http.StatusOK && rec.Code != http.StatusCreated {
		t.Fatalf("payment POST: want 200/201, got %d — %s", rec.Code, rec.Body.String())
	}
	if fired := firedItemCount(t, srv, orderID); fired != 0 {
		t.Fatalf("settling a dine-in order at the counter must not fire the kitchen, got %d", fired)
	}
}

// A split-bill partial collection must not fire the kitchen: the order is not
// closed yet, and half the money is not the signal Mode A waits for.
func TestPosCounterPay_PartialPaymentDoesNotFireKitchen(t *testing.T) {
	srv := newCounterPayServer(t)
	orderID := seedTakeawayCheckoutOrder(t, srv)

	rec := postBroadcastCashPayment(t, srv, orderID, 400, "idem-counter-partial")
	if rec.Code != http.StatusOK && rec.Code != http.StatusCreated {
		t.Fatalf("payment POST: want 200/201, got %d — %s", rec.Code, rec.Body.String())
	}
	if fired := firedItemCount(t, srv, orderID); fired != 0 {
		t.Fatalf("a partial collection must not fire the kitchen, got %d fired", fired)
	}
}
