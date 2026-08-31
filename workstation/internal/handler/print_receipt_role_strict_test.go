package handler

import (
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// ─── money documents follow the receipt_printer TICK, not a fallback ───────
//
// The role checkboxes in Settings are a declaration of what a machine may
// print. resolveReceiptPrinter used to fall back receipt → hall → kitchen, so a
// machine with only 「Chạy bàn」 ticked still printed the customer's bill — the
// 「Hóa đơn」 checkbox changed nothing. These tests pin the strict behaviour.

func newReceiptRoleTestServer(t *testing.T) *Server {
	t.Helper()
	s := newFireTestServer(t)
	s.idempotency = service.NewIdempotencyStore(s.db)
	return s
}

func addRolePrinter(t *testing.T, s *Server, name string, roles ...printer.DeviceType) {
	t.Helper()
	// 127.0.0.1:9100 is never dialled by these tests — they stop at the role
	// lookup, before Connect.
	if _, err := s.devices.AddPrinter(name, roles, printer.ConnNetwork,
		"127.0.0.1:9100", printer.PrinterConfig{PaperWidth: 80}); err != nil {
		t.Fatalf("add %s printer: %v", name, err)
	}
}

// The reported case: one machine, only the hall role ticked. The table-paid
// slip belongs there — the bill does not.
func TestResolveReceiptPrinter_HallOnlyIsNotAReceiptPrinter(t *testing.T) {
	s := newReceiptRoleTestServer(t)
	addRolePrinter(t, s, "hall", printer.TypeHallPrinter)

	if p := s.resolveReceiptPrinter(); p != nil {
		t.Fatalf("a hall-only machine must not be chosen for money documents, got %q", p.Name())
	}
}

// Same for a kitchen-only machine — the other leg of the old fallback.
func TestResolveReceiptPrinter_KitchenOnlyIsNotAReceiptPrinter(t *testing.T) {
	s := newReceiptRoleTestServer(t)
	addRolePrinter(t, s, "kitchen", printer.TypeKitchenPrinter)

	if p := s.resolveReceiptPrinter(); p != nil {
		t.Fatalf("a kitchen-only machine must not be chosen for money documents, got %q", p.Name())
	}
}

// A one-printer shop is still supported — it just has to say so by ticking the
// role. Then the SAME machine legitimately serves both.
func TestResolveReceiptPrinter_OneMachineWithBothRoles(t *testing.T) {
	s := newReceiptRoleTestServer(t)
	addRolePrinter(t, s, "all", printer.TypeHallPrinter, printer.TypeReceiptPrinter)

	if p := s.resolveReceiptPrinter(); p == nil {
		t.Fatalf("a machine carrying receipt_printer must be chosen for money documents")
	}
}

// End-to-end on the gate that matters: with only a hall printer, the auto
// receipt must stand down — and WITHOUT consuming its once-per-order claim, or
// the LAN print handler would later read "already printed" and stand down too,
// leaving the customer with nothing and no error anywhere.
func TestAutoPrintReceipt_StandsDownWithoutReceiptRole(t *testing.T) {
	s := newReceiptRoleTestServer(t)
	addRolePrinter(t, s, "hall", printer.TypeHallPrinter)
	o := seedClosedTableOrder(t, s, "dine_in", "A-01")

	s.autoPrintReceiptOnce(o.ID, 2541)

	if !s.claimAutoPrint("receipt", o.ID) {
		t.Fatalf("the receipt claim must NOT be consumed when no receipt printer is configured")
	}
}

// The drawer is physically plugged into the till machine, so it follows the
// same rule: no receipt printer, no pulse.
func TestCashDrawer_NoPulseWithoutReceiptRole(t *testing.T) {
	s := newReceiptRoleTestServer(t)
	addRolePrinter(t, s, "hall", printer.TypeHallPrinter)

	res, err := s.openCashDrawer()
	if err != nil {
		t.Fatalf("openCashDrawer: %v", err)
	}
	if res.Kicked {
		t.Fatalf("drawer must not pulse a hall-only machine")
	}
	if res.Reason != "no_receipt_printer" {
		t.Fatalf("reason: want no_receipt_printer, got %q", res.Reason)
	}
}
