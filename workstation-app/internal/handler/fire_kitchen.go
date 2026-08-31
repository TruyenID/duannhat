package handler

import (
	"fmt"
	"log/slog"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// fireGroupResult is the per-group success record returned by
// fireKitchenForOrder. Mirrors the shape of the JSON response from
// /api/lan/print/kitchen-ticket so handlers can serialize it verbatim.
type fireGroupResult struct {
	PrinterGroup string `json:"printer_group"`
	TicketNo     int    `json:"ticket_no"`
	Items        int    `json:"items"`
}

// fireGroupError is the per-group failure record. Reason is the structured
// short code (e.g. "no_printer:bar_printer", "ticket_counter", "print").
type fireGroupError struct {
	PrinterGroup string `json:"printer_group"`
	Reason       string `json:"reason"`
	// Detail is a free-form string suitable for logs and toast bodies. The
	// handler may project this into a simple message for the legacy handy
	// response (which used flat strings like "no kitchen printer
	// configured"); the LAN endpoint exposes both fields.
	Detail string `json:"detail,omitempty"`
}

// fireKitchenForOrder is the shared kitchen-fire path used by:
//   - POST /api/lan/print/kitchen-ticket (plan-038 T2.1)
//   - POST /api/v1/handy/orders/{id}/fire (plan-038 T1.3 refactor)
//
// It groups unprinted items by printer_group, dispatches each group to its
// resolved role (via the shared printer/dispatcher.go mapping — fixing the
// historical "always kitchen_printer" bug), allocates a per-station ticket
// number, prints the kitchen + runner tickets, and flips print_status to
// `sent_to_kitchen` for items that actually printed.
//
// Returns:
//   - printedCount — total items whose print_status flipped
//   - groups       — per-group success records (ordered by iteration)
//   - errors       — per-group failure records (ordered by iteration)
//
// Callers wrap the result into their endpoint-specific response envelope.
// unprintedQty is the delta of a line still to be sent to the kitchen:
// current quantity minus what has already been printed, clamped at 0. A line
// with unprintedQty > 0 needs (re)firing — this is the single source of truth
// for fire selection, the 422 pre-check, and the status badge count.
func unprintedQty(it service.Item) int {
	d := it.Quantity - it.PrintedQuantity
	if d < 0 {
		return 0
	}
	return d
}

// needsFire reports whether a line still has units to send to the kitchen: it
// has an unprinted delta AND is not voided. Voided lines are excluded so a
// cancelled item never prints or surfaces on the KDS — even when it was voided
// before it was ever fired (printed_quantity still 0, so unprintedQty alone
// would wrongly select it). This is the single gate shared by fire selection,
// the 422 pre-checks, and the pending-print badge count.
func needsFire(it service.Item) bool {
	return it.Status != service.ItemStatusVoided && unprintedQty(it) > 0
}

// fireKitchenForOrder dispatches the unprinted delta of an order to the
// kitchen. "Dispatch" is intentionally decoupled from "physical print": the
// KDS tablet is the authoritative kitchen ticket, so an item is considered
// fired (and counted in firedCount, which drives the order.kitchen_printed
// broadcast) even when no printer is configured or the print fails. Paper is
// best-effort on top.
//
//   - printer OK            → MarkItemPrinted (print_status=sent_to_kitchen, delta closed)
//   - no printer configured → MarkItemPrinted   (KDS is the ticket; delta closed) + no_printer soft error
//   - print failed          → MarkItemPrintFailed (print_status=failed, delta kept open for retry) + print error
//
// All three count toward firedCount so KDS is always notified.
func (s *Server) fireKitchenForOrder(o *service.Order, locale string) (firedCount int, groups []fireGroupResult, errors []fireGroupError) {
	// Item/topping names on the kitchen + customer-QR slips follow the print
	// locale (Accept-Language), not the name stored at add time — otherwise a
	// JA operator got ASCII-folded Vietnamese. Localize once up front so every
	// per-group copy below carries the resolved names.
	s.localizeOrderForPrint(o, locale)
	// Group items with an unprinted delta by printer_group. Fully-printed
	// lines (delta 0) are skipped so a second fire never duplicates a ticket —
	// the workstation-local idempotency gate pos-web relies on under its retry
	// storm. A quantity bump on an already-fired line reopens a delta, so the
	// line re-enters here and only its NEW units print.
	unprintedByGroup := make(map[string][]service.Item)
	for _, item := range o.Items {
		if needsFire(item) {
			unprintedByGroup[item.PrinterGroup] = append(unprintedByGroup[item.PrinterGroup], item)
		}
	}
	if len(unprintedByGroup) == 0 {
		return 0, nil, nil
	}

	printConfig := service.PrintJobConfig{
		PaperWidth:   42,
		Locale:       locale, // pos-web operator's language (Accept-Language) → ticket labels
		StoreName:    s.settingValue("workstation_branch_name"),
		StoreSubName: s.settingValue("workstation_brand_name"),
		TaxRate:      s.shopTaxRate(),
		Currency:     s.printCurrencySymbol(),
		// plan-043 T4.1 — the hold/runner ticket prints the same per-rate
		// tax blocks as the paid receipt, so it needs the ISO code (rounding
		// step) + the optional T+13 registration number (empty for now, Q5).
		CurrencyCode:             s.shopSetting("currency_code", "JPY"),
		SellerRegistrationNumber: s.shopSetting("seller_registration_number", ""),
	}
	dispatcher := printer.NewDispatcher(s.devices)
	now := time.Now().UTC().Format(time.RFC3339)

	for group, items := range unprintedByGroup {
		kp, _ := dispatcher.RouteKitchenItem(group)
		if kp == nil {
			// No printer configured for this group — KDS-only kitchen. The KDS
			// display IS the kitchen ticket, so still fire the items: mark them
			// sent_to_kitchen (closes the delta) and count them so the caller
			// broadcasts order.kitchen_printed. The soft no_printer error lets
			// the caller tell the operator that no paper came out.
			role, _ := printer.RoleForGroup(group)
			for _, item := range items {
				if err := s.orders.MarkItemPrinted(item.ID, item.Quantity, now); err != nil {
					slog.Warn("fireKitchenForOrder: mark item sent (no printer) failed", "item_id", item.ID, "err", err)
				}
				firedCount++
			}
			errors = append(errors, fireGroupError{
				PrinterGroup: group,
				Reason:       "no_printer:" + string(role),
				Detail:       fmt.Sprintf("no %s configured", role),
			})
			continue
		}

		// Build delta-quantity copies so the kitchen ticket prints only the
		// newly-added units of each line, not the full quantity. The line's
		// toppings/note carry over so the kitchen sees what each new unit is.
		deltaItems := make([]service.Item, 0, len(items))
		for _, item := range items {
			d := unprintedQty(item)
			if d <= 0 {
				continue
			}
			printCopy := item
			printCopy.Quantity = d
			deltaItems = append(deltaItems, printCopy)
		}
		if len(deltaItems) == 0 {
			continue
		}

		ticketNo, err := s.orders.NextKitchenTicketNumber()
		if err != nil {
			errors = append(errors, fireGroupError{
				PrinterGroup: group,
				Reason:       "ticket_counter",
				Detail:       err.Error(),
			})
			continue
		}

		if err := s.printKitchenAndRunnerOn(kp, o, deltaItems, ticketNo, printConfig); err != nil {
			// Paper print failed (printer offline / errored). The kitchen still
			// needs the order, so surface the items on KDS: mark them 'failed'
			// (the KDS show-only-fired filter treats 'failed' as visible) WITHOUT
			// closing the delta, so a retry reprints the same units. Count them
			// so order.kitchen_printed still broadcasts.
			slog.Warn("fireKitchenForOrder: print failed", "printer_group", group, "err", err)
			for _, item := range items {
				if mErr := s.orders.MarkItemPrintFailed(item.ID, now); mErr != nil {
					slog.Warn("fireKitchenForOrder: mark item print-failed failed", "item_id", item.ID, "err", mErr)
				}
				firedCount++
			}
			errors = append(errors, fireGroupError{
				PrinterGroup: group,
				Reason:       "print",
				Detail:       err.Error(),
			})
			continue
		}

		// Mark each line printed up to its FULL current quantity so the delta
		// closes to 0. Only the next quantity bump reopens it.
		for _, item := range items {
			if err := s.orders.MarkItemPrinted(item.ID, item.Quantity, now); err != nil {
				slog.Warn("fireKitchenForOrder: mark item printed failed", "item_id", item.ID, "err", err)
			}
			firedCount++
		}
		groups = append(groups, fireGroupResult{
			PrinterGroup: group,
			TicketNo:     ticketNo,
			Items:        len(deltaItems),
		})
	}
	return
}

// printKitchenAndRunnerOn prints the kitchen ticket then the runner/hold
// ticket on the caller-resolved kitchen printer `kp`. This is the
// dispatcher-aware variant of the legacy printKitchenAndRunner — the kp is
// no longer hard-coded to TypeKitchenPrinter, so a bar group can print on
// the bar printer.
//
// Hold/runner resolution (unchanged):
//  1. device carrying hold_printer → use it
//  2. no hold_printer → reuse kp
func (s *Server) printKitchenAndRunnerOn(
	kp *printer.Printer,
	o *service.Order,
	items []service.Item,
	ticketNo int,
	config service.PrintJobConfig,
) error {
	if kp == nil {
		return fmt.Errorf("printer is nil")
	}
	// Center the 42-col layout on the printer's real width (48 for 80mm).
	config.PhysicalWidth = kp.CharWidth()

	if err := kp.Connect(); err != nil {
		return err
	}

	if err := kp.Print(service.FormatKitchenTicket(o, items, ticketNo, config)); err != nil {
		kp.Disconnect()
		return err
	}

	// Customer / kiosk QR slip — the SECOND slip on every fire (the kitchen
	// ticket is the first). Lists ONLY the newly-fired items (delta) with
	// prices + a QR (order.ID). The FULL-order QR bill is printed separately at
	// checkout (POST /api/lan/print/order-bill → FormatRunnerTicket), so
	// per-fire this shows just "món vừa thêm". Prints to the hold printer, or
	// the kitchen printer when no separate hold printer is configured.
	//
	// TAKEAWAY gets this slip too. It used to be skipped as a paper-saving
	// (issue #456 — "takeaway has no waiter, so the runner slip is meaningless")
	// but it is exactly takeaway that NEEDS it: with no waiter, the kiosk /
	// pickup counter scans the QR (order.ID) to reconcile the food against the
	// order (đối chiếu món ăn). So every order type prints it now.
	rp := s.devices.GetPrinterByRole(printer.TypeHoldPrinter)
	useKitchenForHold := rp == nil || rp.Address() == kp.Address()

	servingTicket := service.FormatDeltaQRTicket(o, items, config)

	if useKitchenForHold {
		_ = kp.Print(servingTicket)
		kp.Disconnect()
	} else {
		kp.Disconnect()
		if err := rp.Connect(); err == nil {
			_ = rp.Print(servingTicket)
			rp.Disconnect()
		}
	}
	return nil
}
