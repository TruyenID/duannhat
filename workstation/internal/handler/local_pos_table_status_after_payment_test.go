package handler

import (
	"net/http"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// #491 — when a dine-in order is fully paid on the workstation, the bound
// table must adopt the branch's table-status-after-payment policy LOCALLY
// (synced into shop_settings via PullBranch) so POS reflects it without a
// Cloud round-trip: `cleaning` when opted in, `free` by default.
func seedDineInPaidTable(t *testing.T, statusSetting string) (*Server, string) {
	t.Helper()
	srv, _ := newServerWithAuth(t, "http://unused")
	seedOpenShift(t, srv)
	srv.orders = service.NewOrderEngine(srv.db)
	srv.hub = NewHub()

	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "dine_in"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	mustExec(t, srv.db,
		`UPDATE orders SET total_amount = 1000, status = 'checkout' WHERE id = ?`, o.ID)
	mustExec(t, srv.db,
		`INSERT INTO tables (id, name, status) VALUES ('tbl-491','T491','occupied')`)
	mustExec(t, srv.db,
		`INSERT INTO order_tables (order_id, table_id, sort_order) VALUES (?, 'tbl-491', 0)`, o.ID)
	if statusSetting != "" {
		mustExec(t, srv.db,
			`INSERT INTO shop_settings (key, value) VALUES ('table_status_after_payment', ?)
			 ON CONFLICT(key) DO UPDATE SET value = excluded.value`, statusSetting)
	}
	return srv, o.ID
}

func tableStatus(t *testing.T, srv *Server, id string) string {
	t.Helper()
	var s string
	if err := srv.db.QueryRow(`SELECT status FROM tables WHERE id = ?`, id).Scan(&s); err != nil {
		t.Fatal(err)
	}
	return s
}

func confirmPayment(t *testing.T, srv *Server, id, orderID string, amount int) {
	t.Helper()
	mustExec(t, srv.db,
		`INSERT INTO payments (id, order_id, amount, status, payment_method)
		 VALUES (?, ?, ?, 'confirmed', 'cash')`, id, orderID, amount)
}

func TestTableStatusAfterPayment_Cleaning(t *testing.T) {
	srv, orderID := seedDineInPaidTable(t, "cleaning")
	confirmPayment(t, srv, "pay-491", orderID, 1000) // full

	srv.applyConfirmedPaymentToOrder(orderID, "cash", "2026-07-08T00:00:00Z")

	if got := tableStatus(t, srv, "tbl-491"); got != "cleaning" {
		t.Fatalf("table status: want cleaning, got %q", got)
	}
}

func TestTableStatusAfterPayment_DefaultsFree(t *testing.T) {
	srv, orderID := seedDineInPaidTable(t, "")       // no setting → free
	confirmPayment(t, srv, "pay-491", orderID, 1000) // full

	srv.applyConfirmedPaymentToOrder(orderID, "cash", "2026-07-08T00:00:00Z")

	if got := tableStatus(t, srv, "tbl-491"); got != "free" {
		t.Fatalf("table status: want free, got %q", got)
	}
}

// End-to-end through the real POS payment handler (handleLocalPosCreatePayment),
// which closes inline — NOT via applyConfirmedPaymentToOrder. This is the path a
// POS terminal actually hits, and the one that regressed: it must flip the
// dine-in table to `cleaning` when the branch opted in.
func TestTableStatusAfterPayment_POSHandler_Cleaning(t *testing.T) {
	srv, orderID := seedDineInPaidTable(t, "cleaning")
	mustExec(t, srv.db,
		`INSERT INTO payment_methods (id, code, name, is_active, sort_order, is_auto_confirm)
		 VALUES ('pm-cash', 'cash', 'Cash', 1, 0, 1)`)

	rec := postBroadcastCashPayment(t, srv, orderID, 1000, "idem-491-pos")
	if rec.Code != http.StatusOK && rec.Code != http.StatusCreated {
		t.Fatalf("payment POST: want 200/201, got %d — %s", rec.Code, rec.Body.String())
	}

	if got := tableStatus(t, srv, "tbl-491"); got != "cleaning" {
		t.Fatalf("POS handler close: want table cleaning, got %q", got)
	}
}

func TestTableStatusAfterPayment_POSHandler_DefaultsFree(t *testing.T) {
	srv, orderID := seedDineInPaidTable(t, "") // no setting → free
	mustExec(t, srv.db,
		`INSERT INTO payment_methods (id, code, name, is_active, sort_order, is_auto_confirm)
		 VALUES ('pm-cash', 'cash', 'Cash', 1, 0, 1)`)

	postBroadcastCashPayment(t, srv, orderID, 1000, "idem-491-pos-free")

	if got := tableStatus(t, srv, "tbl-491"); got != "free" {
		t.Fatalf("POS handler close: want table free, got %q", got)
	}
}

// A partial payment must NOT touch the table — the order stays open.
func TestTableStatusAfterPayment_PartialLeavesTable(t *testing.T) {
	srv, orderID := seedDineInPaidTable(t, "cleaning")
	mustExec(t, srv.db, `UPDATE orders SET total_amount = 2000 WHERE id = ?`, orderID)

	confirmPayment(t, srv, "pay-491", orderID, 1000) // only half of 2000
	srv.applyConfirmedPaymentToOrder(orderID, "cash", "2026-07-08T00:00:00Z")

	if got := tableStatus(t, srv, "tbl-491"); got != "occupied" {
		t.Fatalf("partial payment must leave table occupied, got %q", got)
	}
}
