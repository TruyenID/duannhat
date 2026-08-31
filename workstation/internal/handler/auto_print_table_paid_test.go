package handler

import (
	"io"
	"net"
	"net/http"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// ─── "Bàn X đã thanh toán" staff-notification slip ─────────────────────────
//
// fireTablePaidSlip prints a tiny "which table just paid" slip to the hall
// (ホール) printer when a dine-in order is settled. Most of these tests have no
// physical printer, so they can't capture the rendered bytes — but
// fireTablePaidSlip consumes the once-per-order claimAutoPrint("table_paid", …)
// latch AFTER passing its setting + type + table gates and BEFORE the printer
// lookup, so "latch consumed" is the observable proof the slip was attempted.
// tablePaidAttempted reads that latch (consuming a claim, so call it at most
// once per order). TestTablePaid_PrintsToHallNotKitchen is the exception: it
// binds two real TCP listeners and asserts WHICH one got the paper.

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

// The POS counter is deliberately NOT a caller: handleLocalPosCreatePayment
// closes the order inline and locally, and nothing there fires the slip. A
// cashier settling at the till witnessed the payment; the slip exists for the
// SELF-served table. This pins that as a decision rather than an oversight, so
// a later reading of "the counter prints nothing" doesn't get 'fixed'.
func TestTablePaid_PosCounterSettleDoesNotFire(t *testing.T) {
	srv, orderID := seedDineInPaidTable(t, "")
	srv.devices = printer.NewManager(srv.db)
	srv.idempotency = service.NewIdempotencyStore(srv.db)
	mustExec(t, srv.db, `UPDATE orders SET table_number = 'A-01' WHERE id = ?`, orderID)
	mustExec(t, srv.db,
		`INSERT INTO payment_methods (id, code, name, is_active, sort_order, is_auto_confirm)
		 VALUES ('pm-cash', 'cash', 'Cash', 1, 0, 1)`)

	rec := postBroadcastCashPayment(t, srv, orderID, 1000, "idem-tablepaid-pos")
	if rec.Code != http.StatusOK && rec.Code != http.StatusCreated {
		t.Fatalf("payment POST: want 200/201, got %d — %s", rec.Code, rec.Body.String())
	}
	if tablePaidAttempted(srv, orderID) {
		t.Fatalf("a table settled at the POS counter must NOT print the table-paid slip")
	}
}

// ─── routing: hall, not kitchen ────────────────────────────────────────────

// slipListener is a throwaway TCP printer: it accepts one connection and hands
// back everything written to it.
type slipListener struct {
	addr string
	got  chan []byte
}

func listenForSlip(t *testing.T) *slipListener {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	t.Cleanup(func() { _ = ln.Close() })
	l := &slipListener{addr: ln.Addr().String(), got: make(chan []byte, 1)}
	go func() {
		conn, err := ln.Accept()
		if err != nil {
			return // listener closed at test cleanup — nothing ever printed here
		}
		b, _ := io.ReadAll(conn)
		_ = conn.Close()
		l.got <- b
	}()
	return l
}

// The slip belongs to the HALL station — that is where the runner who clears the
// table stands. It used to go to the kitchen first, which put a floor notice on
// the cook's spike among the tickets they are working from. Both printers are
// registered here, so this fails the moment the fallback order regresses.
func TestTablePaid_PrintsToHallNotKitchen(t *testing.T) {
	s := newTablePaidTestServer(t)
	hall, kitchen := listenForSlip(t), listenForSlip(t)

	for _, p := range []struct {
		name string
		role printer.DeviceType
		addr string
	}{
		{"hall", printer.TypeHallPrinter, hall.addr},
		{"kitchen", printer.TypeKitchenPrinter, kitchen.addr},
	} {
		if _, err := s.devices.AddPrinter(p.name, []printer.DeviceType{p.role},
			printer.ConnNetwork, p.addr, printer.PrinterConfig{PaperWidth: 80}); err != nil {
			t.Fatalf("add %s printer: %v", p.name, err)
		}
	}

	o := seedClosedTableOrder(t, s, "dine_in", "A-01")
	s.fireTablePaidSlip(o.ID, "vi")

	select {
	case b := <-hall.got:
		if len(b) == 0 {
			t.Fatalf("hall printer accepted the connection but received no bytes")
		}
	case <-time.After(3 * time.Second):
		t.Fatalf("table-paid slip never reached the hall printer")
	}
	select {
	case <-kitchen.got:
		t.Fatalf("table-paid slip must not reach the kitchen while a hall printer is configured")
	case <-time.After(200 * time.Millisecond):
	}
}

// Single-station shop: with no hall printer bound the slip still comes out, on
// the kitchen printer. The fallback chain is hall → kitchen → receipt.
func TestTablePaid_FallsBackToKitchenWithoutHall(t *testing.T) {
	s := newTablePaidTestServer(t)
	kitchen := listenForSlip(t)
	if _, err := s.devices.AddPrinter("kitchen", []printer.DeviceType{printer.TypeKitchenPrinter},
		printer.ConnNetwork, kitchen.addr, printer.PrinterConfig{PaperWidth: 80}); err != nil {
		t.Fatalf("add kitchen printer: %v", err)
	}

	o := seedClosedTableOrder(t, s, "dine_in", "A-03")
	s.fireTablePaidSlip(o.ID, "vi")

	select {
	case b := <-kitchen.got:
		if len(b) == 0 {
			t.Fatalf("kitchen printer accepted the connection but received no bytes")
		}
	case <-time.After(3 * time.Second):
		t.Fatalf("with no hall printer the slip must fall back to the kitchen")
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

// #2593 vòng 2 — caller LOCAL-payment (kiosk tự trả · card-terminal · takeaway)
// KHÔNG được đốt claim biên lai khi không có máy mang role `receipt_printer`.
//
// `autoPrintReceiptOnce` (đường cloud-settled) đã có nil-guard trước
// `claimAutoPrint`; sibling này gọi claim THẲNG. Hệ quả đo được: không máy
// receipt ⇒ `autoPrintPaymentReceipt` no-op im lặng trả `nil` ⇒ claim ở lại
// **và** kiosk nhận `status: success`. Claim latch 24h, nên cắm máy receipt hay
// tick lại role sau đó cũng KHÔNG in lại được — và đường in tay đọc chính claim
// đó thành "đã in rồi" rồi cũng đứng im.
//
// Quán hall-only takeaway: khách trả ở kiosk, kiosk báo đã in, không tờ giấy
// nào ra, không cách nào in lại tự động.
func TestLocalPaymentAutoPrint_NoReceiptPrinterDoesNotBurnTheClaim(t *testing.T) {
	s := newTablePaidTestServer(t)
	// Máy in HALL — role đúng cho phiếu bàn, SAI cho biên lai. Đây là đúng cấu
	// hình quán trong báo cáo: một máy, chỉ tick 「Chạy bàn」.
	hall := listenForSlip(t)
	if _, err := s.devices.AddPrinter("hall", []printer.DeviceType{printer.TypeHallPrinter},
		printer.ConnNetwork, hall.addr, printer.PrinterConfig{PaperWidth: 80}); err != nil {
		t.Fatalf("add hall printer: %v", err)
	}
	if p := s.resolveReceiptPrinter(); p != nil {
		t.Fatalf("tiền đề sai: máy hall-only không được coi là máy biên lai, got %q", p.Name())
	}

	o := seedClosedTableOrder(t, s, "takeaway", "5")
	s.handleLocalPaymentAutoPrint(o.ID, 50000)

	// `claimAutoPrint` trả true ở lần gọi ĐẦU. Nếu handler đã đốt claim thì lần
	// gọi này trả false — tức biên lai bị đánh dấu "đã in" mà không tờ nào ra.
	if !s.claimAutoPrint("receipt", o.ID) {
		t.Fatal("claim biên lai đã bị đốt dù không có máy receipt — cắm máy vào cũng không in lại được, " +
			"và đường in tay đọc claim này thành 'đã in rồi' rồi cũng đứng im")
	}
}
