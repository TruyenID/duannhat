package handler

import (
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// ─── "Bàn X đã thanh toán" staff-notification slip ─────────────────────────
//
// fireTablePaidSlip prints a tiny "which table just paid" slip to the kitchen /
// hold printer when a dine-in order is settled by an online (kiosk / QR) self-
// payment. The test harness has no physical printer, so we can't capture the
// rendered bytes — but fireTablePaidSlip consumes the once-per-order
// claimAutoPrint("table_paid", …) latch AFTER passing its setting + table gates
// and BEFORE the printer lookup, so "latch consumed" is the observable proof the
// slip was attempted. tablePaidAttempted reads that latch (consuming a claim, so
// call it at most once per order).

func newTablePaidTestServer(t *testing.T) *Server {
	t.Helper()
	s := newFireTestServer(t)
	s.idempotency = service.NewIdempotencyStore(s.db)
	return s
}

func tablePaidAttempted(s *Server, orderID string) bool {
	return !s.claimAutoPrint("table_paid", orderID)
}

// seedClosedTableOrder inserts a settled order of the given type with a table number.
func seedClosedTableOrder(t *testing.T, s *Server, orderType, table string) *service.Order {
	t.Helper()
	o, err := s.orders.Create(service.CreateOrderInput{OrderType: orderType}, nil)
	if err != nil {
		t.Fatalf("create %s order: %v", orderType, err)
	}
	if _, err := s.db.Exec(
		`UPDATE orders SET table_number = ?, status = 'closed', closed_at = ? WHERE id = ?`,
		table, "2026-07-20T14:32:00Z", o.ID,
	); err != nil {
		t.Fatalf("settle order: %v", err)
	}
	o, _ = s.orders.GetByID(o.ID)
	return o
}

// A dine-in order settled over the LAN (kiosk / QR self-pay) attempts the slip.
func TestTablePaid_LocalPaymentDineInFires(t *testing.T) {
	s := newTablePaidTestServer(t)
	o := seedClosedTableOrder(t, s, "dine_in", "12")
	s.handleLocalPaymentAutoPrint(o.ID, 50000)
	if !tablePaidAttempted(s, o.ID) {
		t.Fatalf("dine-in online payment should attempt the table-paid slip")
	}
}

// The sync-down paid hook (customer paid online in Cloud) also fires for dine-in.
func TestTablePaid_PaidHookDineInFires(t *testing.T) {
	s := newTablePaidTestServer(t)
	o := seedClosedTableOrder(t, s, "dine_in", "7")
	s.handleOrderPaidAutoPrint(o.ID, 50000)
	if !tablePaidAttempted(s, o.ID) {
		t.Fatalf("sync-down paid hook should attempt the table-paid slip for dine-in")
	}
}

// Takeaway is a self-order too, but it has no table to notify staff about — skip.
func TestTablePaid_TakeawaySkips(t *testing.T) {
	s := newTablePaidTestServer(t)
	o := seedClosedTableOrder(t, s, "takeaway", "9")
	s.handleLocalPaymentAutoPrint(o.ID, 50000)
	if tablePaidAttempted(s, o.ID) {
		t.Fatalf("takeaway must not attempt the table-paid slip")
	}
}

// A dine-in order with no table number must not print a blank "BAN -" slip.
func TestTablePaid_NoTableSkips(t *testing.T) {
	s := newTablePaidTestServer(t)
	o := seedClosedTableOrder(t, s, "dine_in", "")
	s.handleLocalPaymentAutoPrint(o.ID, 50000)
	if tablePaidAttempted(s, o.ID) {
		t.Fatalf("dine-in without a table must not attempt the slip")
	}
}

// The shop can opt out via print_table_paid=false.
func TestTablePaid_SettingOffSkips(t *testing.T) {
	s := newTablePaidTestServer(t)
	setShopSetting(t, s, "print_table_paid", "false")
	o := seedClosedTableOrder(t, s, "dine_in", "12")
	s.handleLocalPaymentAutoPrint(o.ID, 50000)
	if tablePaidAttempted(s, o.ID) {
		t.Fatalf("print_table_paid=false must suppress the slip")
	}
}

// The user's key requirement: the paid-confirmation slip is INDEPENDENT of the
// auto_print_bill (receipt) toggle — turning the receipt off must NOT suppress
// the table-paid notification. Verified on both paid hooks.
func TestTablePaid_PrintsEvenWhenAutoPrintBillOff(t *testing.T) {
	s := newTablePaidTestServer(t)
	setWSSetting(t, s, "auto_print_bill", "false") // receipt auto-print OFF

	local := seedClosedTableOrder(t, s, "dine_in", "12")
	s.handleLocalPaymentAutoPrint(local.ID, 50000)
	if !tablePaidAttempted(s, local.ID) {
		t.Fatalf("local-pay: table-paid slip must still fire with auto_print_bill off")
	}

	pulled := seedClosedTableOrder(t, s, "dine_in", "13")
	s.handleOrderPaidAutoPrint(pulled.ID, 50000)
	if !tablePaidAttempted(s, pulled.ID) {
		t.Fatalf("paid-hook: table-paid slip must still fire with auto_print_bill off")
	}
}

// Once-per-order: a re-sync / retry / split-payment settle must not reprint.
func TestTablePaid_OncePerOrder(t *testing.T) {
	s := newTablePaidTestServer(t)
	o := seedClosedTableOrder(t, s, "dine_in", "12")
	s.handleLocalPaymentAutoPrint(o.ID, 50000) // first settle: attempts + claims
	// The latch is now taken — a fresh claim returns false, proving the first
	// call consumed it so a second settle can't reprint.
	if s.claimAutoPrint("table_paid", o.ID) {
		t.Fatalf("first paid hook should have consumed the once-per-order latch")
	}
}
