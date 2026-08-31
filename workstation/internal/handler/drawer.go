package handler

import (
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// Cash-drawer control (#1951).
//
// Before this file, `KickDrawer` was called from exactly ONE place — the printer
// setup wizard — so on a cash POS the till never opened during a sale. The
// cashier took the money and then opened the drawer by hand or by pressing the
// button on the printer itself, which is the state a drawer-kick feature exists
// to remove.
//
// Everything needed was already there: `escpos.Encoder.KickDrawer` pulses the
// configured pin with the profile's timings, `Profile.CanKickDrawer` answers
// P-37, and `Finishing.DrawerKick` is data-driven per model. What was missing
// was anyone asking.
//
// # Three rulings this file implements (#1951, decided 2026-08-06)
//
// **Cash only.** The drawer opens so notes can go IN and change can come OUT.
// Card, QR and e-money involve neither, and opening a till nobody is going to
// touch is exactly what shrinkage control exists to prevent. `on_account` (debt)
// likewise: no money changes hands. A MIXED payment that contains at least one
// cash line DOES open — cash still moved.
//
// **Keyed to the PAYMENT, not to the SLIP.** This is the load-bearing one. Hang
// the kick off printing and a REPRINT pops the till: a receipt reprinted at
// 22:00 for a 19:00 order moves no money, and a drawer that springs open because
// somebody pressed "print again" is a theft window, not a convenience. The pulse
// still physically travels down the printer's RJ11 port — that is how these
// drawers are wired — but the TRIGGER is the payment event. Hence this is called
// from the payment path and never from a print handler, and `reprint_no >= 2`
// can never reach it.
//
// **Anyone may open; a manual open is logged.** Refusing to let a cashier open
// the till is counter-productive — they need change, and if the system says no
// they wedge the drawer open for the whole shift, which is far worse. What the
// trade controls is not *who* may open it but that *every* open has a name on
// it. "No-sale count" is a classic loss-prevention metric, which is why the
// manual endpoint audits and the automatic path does not (the payment row is
// already its own evidence).
//
// # A machine with no drawer is a valid shop, not an error
//
// `CanKickDrawer() == false` returns "did nothing" and no error, the same
// contract the print endpoints use for a shop with no printer. A cafe with a
// cash box under the counter must not see failures.

// cashDrawerResult reports whether a pulse actually went out. The caller needs
// to distinguish "opened" from "this machine has no drawer" — reporting success
// for a machine that did nothing is how a cashier ends up standing there
// pressing a button while the queue grows.
type cashDrawerResult struct {
	Kicked bool   `json:"kicked"`
	Reason string `json:"reason,omitempty"`
}

// openCashDrawer pulses the drawer attached to the receipt printer.
//
// It resolves the SAME printer the receipt goes to, deliberately: the drawer is
// physically plugged into that machine, so any other choice would pulse a port
// with nothing on it.
func (s *Server) openCashDrawer() (cashDrawerResult, error) {
	// `s.devices == nil` FIRST. `resolveReceiptPrinter` reaches into the printer
	// manager and panics on a nil one rather than returning nil, so every other
	// caller in this package guards the same way. Omitting it took down
	// `TestPartialPaymentKeepsOrderOpen` with a nil dereference — a test about
	// payments, failing inside the drawer, which is exactly how a missing nil
	// check reads to whoever finds it next.
	if s.devices == nil {
		return cashDrawerResult{Kicked: false, Reason: "no_receipt_printer"}, nil
	}

	rp := s.resolveReceiptPrinter()
	if rp == nil {
		return cashDrawerResult{Kicked: false, Reason: "no_receipt_printer"}, nil
	}

	profile := rp.Profile()
	if !profile.CanKickDrawer() {
		// P-37. Not an error: a shop whose printer has no drawer port, or whose
		// model was never claimed to have one, is a supported configuration.
		return cashDrawerResult{Kicked: false, Reason: "drawer_not_supported"}, nil
	}

	e := escpos.New()
	if !e.KickDrawer(profile.FinishingSpec()) {
		// Belt and braces: the encoder re-checks the same flag. If the two ever
		// disagree, trust the one closest to the bytes.
		return cashDrawerResult{Kicked: false, Reason: "drawer_not_supported"}, nil
	}

	if err := rp.Connect(); err != nil {
		return cashDrawerResult{Kicked: false, Reason: "printer_unreachable"}, err
	}
	defer rp.Disconnect()

	if err := rp.Print(e.Bytes()); err != nil {
		return cashDrawerResult{Kicked: false, Reason: "printer_error"}, err
	}

	return cashDrawerResult{Kicked: true}, nil
}

// kickDrawerForCashPayment opens the till when an order settled with cash.
//
// Called from the PAYMENT path. Never call it from a print handler — see the
// second ruling in this file's header.
func (s *Server) kickDrawerForCashPayment(orderID string) {
	if s.db == nil || orderID == "" {
		return
	}

	hasCash, err := s.orderHasCashPayment(orderID)
	if err != nil {
		// A drawer that fails to open is a cashier opening it by hand. A sale
		// that fails because of a drawer is a customer waiting. Log and move on.
		slog.Warn("cash drawer: could not read payment methods", "order", orderID, "err", err)
		return
	}
	if !hasCash {
		return
	}

	res, err := s.openCashDrawer()
	if err != nil {
		slog.Warn("cash drawer: kick failed", "order", orderID, "reason", res.Reason, "err", err)
		return
	}
	if res.Kicked {
		slog.Info("cash drawer: opened for cash payment", "order", orderID)
	}
}

// orderHasCashPayment reports whether any ACTIVE payment on the order is cash.
//
// Treats a mixed payment as cash the moment one line is cash — money still
// moved through the till.
//
// The column names and the active-status set are COPIED from the till code
// (`local_pos_till.go`), not invented: `is_cash = payment_method == 'cash'` is
// already how the shift reconciliation decides what belongs in the drawer, and
// a second opinion about "is this cash" would eventually disagree with the
// figure the cashier counts against.
//
// My first draft wrote `customer_order_id` and `payment_method_code` — both
// belong to the CLOUD table. Locally they are `order_id` and `payment_method`;
// SQLite would have failed at runtime, inside a goroutine nobody watches, and
// the till would simply never have opened.
//
// An inactive payment is excluded: a voided cash payment took no notes, so
// re-settling the order by card afterwards must not still open the drawer.
func (s *Server) orderHasCashPayment(orderID string) (bool, error) {
	const q = `
		SELECT COUNT(*)
		  FROM payments
		 WHERE order_id = ?
		   AND LOWER(COALESCE(payment_method, '')) = 'cash'
		   AND status IN ('pending', 'confirmed', 'succeeded')`

	var n int
	if err := s.db.QueryRow(q, orderID).Scan(&n); err != nil {
		return false, err
	}

	return n > 0, nil
}

// handleLANDrawerOpen — POST /api/lan/drawer/open. The "no-sale" open.
//
// Audited on purpose (third ruling): the control the trade applies to a till is
// not permission but attribution.
func (s *Server) handleLANDrawerOpen(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Reason string `json:"reason"`
	}
	// A body is optional — the common case is a cashier needing change, and
	// demanding a typed reason for that turns a two-second action into a form.
	_ = json.NewDecoder(r.Body).Decode(&body)

	res, err := s.openCashDrawer()

	// Audit BEFORE deciding the response, and audit even when nothing opened:
	// "tried to open the till and the machine refused" is exactly as
	// interesting to a loss-prevention review as a successful open.
	s.auditLog(r, "drawer.open", "printer", "", auditDetails(map[string]any{
		"kicked":  res.Kicked,
		"reason":  strings.TrimSpace(body.Reason),
		"outcome": res.Reason,
		"error":   errText(err),
	}))

	if err != nil {
		writeServerError(w, r, fmt.Errorf("cash drawer: %w", err))

		return
	}

	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(res)
}

func errText(err error) string {
	if err == nil || errors.Is(err, nil) {
		return ""
	}

	return err.Error()
}
