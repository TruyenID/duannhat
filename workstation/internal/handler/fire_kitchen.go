package handler

import (
	"fmt"
	"log/slog"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printjob"
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
	// locale, not the name stored at add time — otherwise a JA operator got
	// ASCII-folded Vietnamese. `locale` here is only the SHOP-WIDE fallback;
	// each group is localized separately below (after routing resolves which
	// printer it lands on), because a printer can pin its own locale (e.g. a
	// vi-speaking kitchen station in an otherwise-ja shop) that must win for
	// THAT group's slip without touching another group's copy of the order.
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
		StoreName:    s.storeName(),
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
	// Xem chú thích ở chỗ đặt cờ trong vòng lặp.
	orderBillClaimed := false

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

		// Localize only this group's delta copy — o.Items and other groups'
		// copies are untouched.
		s.localizeItemsForPrint(deltaItems, locale)
		groupConfig := printConfig
		groupConfig.Locale = locale

		ticketNo, err := s.orders.NextKitchenTicketNumber()
		if err != nil {
			errors = append(errors, fireGroupError{
				PrinterGroup: group,
				Reason:       "ticket_counter",
				Detail:       err.Error(),
			})
			continue
		}

		// Tờ hoá đơn takeaway là tài liệu TOÀN ĐƠN, nên chỉ nhóm ĐẦU TIÊN in
		// được nó; các nhóm sau chỉ in phiếu bếp của mình. Đặt cờ TRƯỚC khi gọi
		// và không đặt lại ở nhánh lỗi: một tờ hoá đơn không in được thì lượt
		// bắn này thôi có hoá đơn, chứ không phải nhóm sau in bù — nhóm sau in
		// bù là quay lại đúng chuyện hai tờ.
		withOrderBill := !orderBillClaimed
		orderBillClaimed = true

		if err := s.printKitchenAndRunnerOn(kp, o, deltaItems, ticketNo, groupConfig, withOrderBill); err != nil {
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

// reprintKitchenForOrder re-prints an order's kitchen ticket AS IT STANDS,
// without firing anything.
//
// It is deliberately NOT fireKitchenForOrder with a flag. Fire is a WRITE: it
// closes the unprinted delta (MarkItemPrinted), and its caller broadcasts
// `order.kitchen_printed` so every KDS tablet re-fetches. A reprint must do
// neither — the paper is what got lost, not the món. Re-firing a finished
// order would drop it back onto the kitchen display as new work, which is how
// a shop cooks a plate twice.
//
// It also prints the kitchen ticket ONLY, never the runner/hold slip that
// printKitchenAndRunnerOn sends after it. The hold slip has its own button
// (`POST /api/lan/print/order-bill`) precisely so a cashier who lost one sheet
// does not have to burn the other.
//
// Two more differences from fire, both following from "this is paper, not
// dispatch":
//
//   - EVERY non-voided line prints at its FULL quantity. The delta is about
//     what the kitchen has not been told yet; a reprint is about what the
//     ticket said, and on a finished order the delta is 0 for every line —
//     keying off it would print an empty ticket.
//   - "No printer configured" is a plain error here. On the fire path it is a
//     soft one because the KDS *is* the ticket, so the món still arrives. With
//     no paper and no dispatch, a reprint achieved nothing and must say so.
//
// Voided lines are excluded, same as fire: a cancelled món must never reappear
// on a kitchen ticket, whichever route drew it.
func (s *Server) reprintKitchenForOrder(o *service.Order, locale string) (groups []fireGroupResult, errors []fireGroupError) {
	byGroup := make(map[string][]service.Item)
	for _, item := range o.Items {
		if item.Status == service.ItemStatusVoided {
			continue
		}
		byGroup[item.PrinterGroup] = append(byGroup[item.PrinterGroup], item)
	}
	if len(byGroup) == 0 {
		return nil, nil
	}

	printConfig := service.PrintJobConfig{
		PaperWidth:               42,
		StoreName:                s.storeName(),
		StoreSubName:             s.settingValue("workstation_brand_name"),
		TaxRate:                  s.shopTaxRate(),
		Currency:                 s.printCurrencySymbol(),
		CurrencyCode:             s.shopSetting("currency_code", "JPY"),
		SellerRegistrationNumber: s.shopSetting("seller_registration_number", ""),
	}
	dispatcher := printer.NewDispatcher(s.devices)

	for group, items := range byGroup {
		kp, _ := dispatcher.RouteKitchenItem(group)
		if kp == nil {
			role, _ := printer.RoleForGroup(group)
			errors = append(errors, fireGroupError{
				PrinterGroup: group,
				Reason:       "no_printer:" + string(role),
				Detail:       fmt.Sprintf("no %s configured", role),
			})
			continue
		}

		// Localize a copy so o.Items and the other groups' copies stay
		// untouched — same reason fire builds delta copies before localizing.
		printItems := make([]service.Item, len(items))
		copy(printItems, items)
		s.localizeItemsForPrint(printItems, locale)

		groupConfig := printConfig
		groupConfig.Locale = locale
		groupConfig.PhysicalWidth = kp.CharWidth()

		ticketNo, err := s.orders.NextKitchenTicketNumber()
		if err != nil {
			errors = append(errors, fireGroupError{
				PrinterGroup: group,
				Reason:       "ticket_counter",
				Detail:       err.Error(),
			})
			continue
		}

		if err := kp.Connect(); err != nil {
			// Nothing was rendered — no template version to stamp, and "" is
			// the honest answer rather than an invented one.
			s.journalKitchenTicket(kp, o, len(printItems), "", err)
			errors = append(errors, fireGroupError{
				PrinterGroup: group,
				Reason:       "print",
				Detail:       err.Error(),
			})
			continue
		}

		slip, templateVersion := s.renderMoneySlip(
			service.NewKitchenRenderData(o, printItems, ticketNo, groupConfig),
			service.PrintRenderProfileFor(kp.Profile(), ""),
			groupConfig.Locale,
			func() []byte { return service.FormatKitchenTicket(o, printItems, ticketNo, groupConfig) },
		)
		printErr := kp.Print(slip)
		kp.Disconnect()
		s.journalKitchenTicket(kp, o, len(printItems), templateVersion, printErr)
		if printErr != nil {
			slog.Warn("reprintKitchenForOrder: print failed", "printer_group", group, "err", printErr)
			errors = append(errors, fireGroupError{
				PrinterGroup: group,
				Reason:       "print",
				Detail:       printErr.Error(),
			})
			continue
		}

		groups = append(groups, fireGroupResult{
			PrinterGroup: group,
			TicketNo:     ticketNo,
			Items:        len(printItems),
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
//  1. device carrying hall_printer → use it
//  2. no hall_printer → reuse kp
//
// `withOrderBill` chỉ true cho MỘT nhóm trong cả lượt bắn: tờ phục vụ của
// takeaway là hoá đơn toàn đơn, nên in nó mỗi nhóm là đưa cho khách nhiều tờ
// cùng đòi một khoản tiền. Tờ bếp vẫn in cho mọi nhóm.
func (s *Server) printKitchenAndRunnerOn(
	kp *printer.Printer,
	o *service.Order,
	items []service.Item,
	ticketNo int,
	config service.PrintJobConfig,
	withOrderBill bool,
) error {
	if kp == nil {
		return fmt.Errorf("printer is nil")
	}
	// Center the 42-col layout on the printer's real width (48 for 80mm).
	config.PhysicalWidth = kp.CharWidth()

	if err := kp.Connect(); err != nil {
		// plan-052 T1.2 — a ticket that never reached the machine is exactly
		// the case the ledger exists for: today the kitchen simply never gets
		// the món and nobody upstream ever learns why.
		// Chưa render gì cả (connect hỏng trước cả seam), nên KHÔNG có phiên bản
		// để đóng — chuỗi rỗng, không phải một giá trị bịa ra.
		s.journalKitchenTicket(kp, o, len(items), "", err)
		return err
	}

	// plan-053 T3.6 tầng 2 (#1914) — call site 2/13.
	kitchenSlip, templateVersion := s.renderMoneySlip(
		service.NewKitchenRenderData(o, items, ticketNo, config),
		service.PrintRenderProfileFor(kp.Profile(), ""),
		config.Locale,
		func() []byte { return service.FormatKitchenTicket(o, items, ticketNo, config) },
	)

	if err := kp.Print(kitchenSlip); err != nil {
		kp.Disconnect()
		s.journalKitchenTicket(kp, o, len(items), templateVersion, err)
		return err
	}
	s.journalKitchenTicket(kp, o, len(items), templateVersion, nil)

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
	rp := s.devices.GetPrinterByRole(printer.TypeHallPrinter)
	useKitchenForHold := rp == nil || rp.Address() == kp.Address()

	// call site 3/13. Profile lấy từ máy in HOLD nếu có — phiếu này in ra ĐÓ,
	// và profile sai máy là lý do một phiếu vừa vặn trên khổ này lại tràn ở khổ
	// kia.
	holdProfile := kp.Profile()
	if rp != nil && !useKitchenForHold {
		holdProfile = rp.Profile()
	}

	// Phiếu QR delta không có dòng nhật ký riêng — `journalKitchenTicket` chỉ
	// ghi phiếu BẾP. Không có hàng thì không có chỗ đóng dấu, và ghi phiên bản
	// của phiếu này lên hàng của phiếu bếp sẽ là một khẳng định sai về tờ giấy
	// khác.
	// TAKEAWAY takes the hall BILL (`runner`), not the delta slip (chủ dự án
	// 2026-08-17). The two sheets differ on more than a heading:
	//
	//	delta_qr — totals the FIRE BATCH from unit prices, and deliberately
	//	           suppresses 小計 / per-rate tax / 登録番号, because those figures
	//	           are meaningless for a slice of an order.
	//	runner   — the whole order with the real money block.
	//
	// A takeaway sheet is handed to a CUSTOMER at a pickup counter, so the
	// batch total is not merely incomplete there, it is wrong: measured on a
	// ¥2,190 order it printed ¥2,140 — the tax — while the customer had
	// already paid ¥2,190. Dine-in keeps the delta slip, where the sheet is a
	// runner's work order and the batch is exactly the point.
	//
	// Whole order, so `o.Items` rather than the fired `items`, and the ledger
	// tax rows loaded the same way the /order-bill route loads them — without
	// them the money block falls back to recomputation and stops matching the
	// receipt it is supposed to reconcile against.
	servingData := service.NewDeltaQRRenderData(o, items, config)
	servingFormatter := func() []byte { return service.FormatDeltaQRTicket(o, items, config) }
	if string(o.OrderType) == "takeaway" {
		// MỘT tờ cho cả lượt bắn, không phải một tờ mỗi printer_group.
		// `printKitchenAndRunnerOn` chạy một lần cho MỖI nhóm (vòng lặp ở
		// `fireKitchenForOrder`), mà tờ này là hoá đơn CẢ ĐƠN — nên đơn có 1 món
		// bếp + 1 đồ uống quầy bar sẽ ra HAI tờ giống hệt, mỗi tờ khai đủ tiền
		// cả đơn và mỗi tờ mang một mã QR trả tiền còn dùng được. Khách ở quầy
		// nhận hàng cầm hai tờ đòi cùng một khoản.
		//
		// Tờ BẾP thì đúng là per-group (mỗi bếp chỉ cần phần của mình); chỉ tờ
		// phục vụ của takeaway mới là tài liệu toàn đơn.
		if !withOrderBill {
			return nil
		}

		bill := *o
		// Lọc dòng đã HUỶ: tờ này đi tới tay KHÁCH, và luật #3044 nói dòng đã
		// huỷ không lên giấy của khách. Đường cũ (delta slip) dùng `items` vốn
		// đã qua `needsFire` nên tự loại voided; đường mới lấy thẳng `o.Items`
		// nên phải lọc tường minh — nếu không, các dòng in ra KHÔNG cộng lại
		// bằng con số ở cuối tờ, vì tổng lấy từ `o.TotalAmount`.
		bill.Items = nonVoidedItems(o.Items)
		s.localizeOrderForPrint(&bill, config.Locale)
		bill.TaxLines = s.orders.OrderTaxLines(bill.ID)
		servingData = service.NewRunnerRenderData(&bill, bill.Items, 0, config)
		servingFormatter = func() []byte { return service.FormatRunnerTicket(&bill, bill.Items, 0, config) }
	}
	servingTicket, _ := s.renderMoneySlip(
		servingData,
		service.PrintRenderProfileFor(holdProfile, ""),
		config.Locale,
		servingFormatter,
	)

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

// journalKitchenTicket records one kitchen fire in the print ledger.
//
// The kind follows the PRINTER's role rather than the order: a shop with a
// separate bar station routes drinks to `bar_printer` (Dispatcher.
// RouteKitchenItem), and a ledger that called those "kitchen" would make the
// bar's failures invisible inside the kitchen's numbers.
//
// `templateVersion` is the layout that drew THE KITCHEN TICKET (TR-28). It is
// "" when nothing was rendered — a connect failure never reaches the seam — and
// "" when the legacy formatter drew it; both store as NULL, which is the honest
// "this row records no layout".
func (s *Server) journalKitchenTicket(kp *printer.Printer, o *service.Order, itemCount int, templateVersion string, err error) {
	kind := printjob.KindKitchen
	if kp != nil && kp.HasRole(printer.TypeBarPrinter) && !kp.HasRole(printer.TypeKitchenPrinter) {
		kind = printjob.KindBar
	}

	orderID := ""
	if o != nil {
		orderID = o.ID
	}

	s.journalPrint(kp, printjob.Entry{
		Kind:            kind,
		OrderID:         orderID,
		RequestedVia:    "pos",
		TemplateVersion: templateVersion,
		Payload:         map[string]any{"template": "kitchen_ticket", "items": itemCount},
	}, err)
}
