package handler

import (
	"database/sql"
	"log/slog"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// Auto-print orchestration for customer/online (takeaway) orders — issue #456.
//
// Two shop modes, driven by the `prep_before_payment` setting synced from Cloud
// into shop_settings:
//
//   - prep_before_payment = true  (Mode A, "pay before receiving"): the kitchen
//     doesn't start until the customer pays. The order reaches the workstation
//     already `closed` (paid), so on the paid hook we fire the kitchen ticket
//     AND the payment receipt together.
//
//   - prep_before_payment = false (Mode B, "prepare immediately"): the order is
//     open the moment it's placed. On arrival (unpaid) we fire only the kitchen
//     ticket; the payment receipt prints later, when the customer pays.
//
// Only takeaway (customer-web / kiosk) orders use this flow. Dine-in / spot POS
// orders keep the legacy `auto_print_bill`-gated receipt behaviour.

const autoPrintDeviceID = "workstation-autoprint"

// shopSetting reads a value from shop_settings (synced from Cloud via
// PullBranch). Returns defaultVal when the key is absent or empty.
func (s *Server) shopSetting(key, defaultVal string) string {
	var v string
	if err := s.db.QueryRow("SELECT value FROM shop_settings WHERE key = ?", key).Scan(&v); err != nil || v == "" {
		return defaultVal
	}
	return v
}

// printCurrencySymbol returns the money symbol to print on receipts / kitchen
// tickets, from the shop's currency_code (synced from Cloud). So a VND shop
// prints ₫ instead of a hard-coded ¥.
func (s *Server) printCurrencySymbol() string {
	return service.CurrencySymbol(s.shopSetting("currency_code", "JPY"))
}

// prepBeforePayment reports whether the shop requires payment before the
// kitchen starts (Mode A). Defaults to true — matching the backend brand-policy
// default (`default_prep_before_payment`) — when the setting hasn't synced yet.
func (s *Server) prepBeforePayment() bool {
	return s.shopSetting("prep_before_payment", "true") != "false"
}

// autoPrintKitchenEnabled reports whether a dine-in / spot order that arrives
// from customer-web (QR-table ordering) should auto-fire its kitchen + hold slip
// on arrival and on later "add more" rounds. Local workstation toggle
// (auto_print_kitchen), OFF unless a manager sets it to "true" — the mirror of
// the auto_print_bill receipt gate. Takeaway kitchen timing is separate
// (prep_before_payment); POS-created dine-in orders are never auto-fired.
func (s *Server) autoPrintKitchenEnabled() bool {
	return s.settingValue("auto_print_kitchen") == "true"
}

// claimAutoPrint returns true the first time it's called for a given
// (kind, orderID), and false afterwards, so a re-sync / F5 / retry can't
// double-print. Backed by the shared idempotency store (24h TTL). Kitchen
// tickets do NOT use this — fireKitchenForOrder is already idempotent via
// order_items.printed_quantity (delta printing), and a claim would wrongly
// block a legitimate re-fire of newly-added lines.
func (s *Server) claimAutoPrint(kind, orderID string) bool {
	if s.idempotency == nil {
		return true
	}
	key := "autoprint:" + kind + ":" + orderID
	if _, found, _ := s.idempotency.Get(key, autoPrintDeviceID); found {
		return false
	}
	_ = s.idempotency.Put(key, autoPrintDeviceID, "", "done")
	return true
}

// autoPrintKitchen fires the kitchen ticket(s) for an order. Best-effort:
// a missing printer / group error never blocks the sync loop. Relies on
// fireKitchenForOrder's printed_quantity delta logic for dedup.
func (s *Server) autoPrintKitchen(orderID string) {
	if s.orders == nil {
		return
	}
	o, err := s.orders.GetByID(orderID)
	if err != nil || o == nil {
		slog.Warn("auto-print kitchen: order not found locally", "order", orderID, "err", err)
		return
	}
	// Auto-print runs from the sync loop with no operator request, so there is
	// no Accept-Language. Use the locale the CUSTOMER ordered in (mirrored from
	// Cloud onto the order), falling back to the last operator locale.
	fired, _, ferrs := s.fireKitchenForOrder(o, s.orderPrintLocale(orderID))
	if len(ferrs) > 0 {
		slog.Warn("auto-print kitchen ticket had group errors", "order", orderID, "fired", fired, "errors", len(ferrs))
	}
}

// printLabelLocale resolves the ONE language every printed slip in this shop
// renders in — kitchen ticket, hold/runner slip and payment receipt alike.
//
// It governs BOTH kinds of text on the paper:
//   - the fixed labels (Tạm tính / 小計 / Subtotal), from the label catalogs
//     compiled into the binary (print_kitchen_bill_i18n.go), and
//   - the item names, via localizeOrderForPrint reading the synced
//     pos_products.name_<locale> translations. An item with no translation for
//     the locale falls back to its base `name` — a DATA gap, not a routing one.
//
// Why one locale for the whole shop rather than one per reader: the slips of a
// single order must reconcile against each other. When each print path read the
// Accept-Language of whichever terminal called it, terminal A (ja) firing the
// kitchen and terminal B (vi) printing the receipt produced two slips naming
// the same dish differently, and staff could not match them when handing food
// over.
//
// Resolution order — every step reads LOCAL SQLite, so printing keeps working
// in the configured language while the Cloud link is down:
//
//  1. shop_settings.print_label_locale — the manager's choice in admin-web,
//     mirrored down by PullBranch.
//  2. branches.locale — the branch default Cloud already syncs, so a shop that
//     never opens the setting still prints sensibly.
//  3. settings.pos_print_locale — the legacy operator locale, kept so an
//     existing install's behaviour does not change until someone configures it.
//  4. "" — the print layer's own default (ja).
//
// Unsupported/garbage values are dropped at each step rather than passed on.
func (s *Server) printLabelLocale() string {
	if s.db == nil {
		return ""
	}
	// 1. Manager's explicit choice, synced from admin-web. Cloud is the source
	//    of truth and wins whenever it has a value.
	if v := normalizePrintLocaleStrict(s.shopSetting("print_label_locale", "")); v != "" {
		return v
	}
	// 2. Explicit local override (settings.print_locale_override), chosen by an
	//    operator in the WS App. This is the offline escape hatch: when Cloud
	//    never synced a print_label_locale (fresh install, or the shop hasn't
	//    configured one), staff can pin the print language on this workstation
	//    without waiting for Cloud. It sits BELOW the Cloud value on purpose —
	//    once admin-web sets a shop-wide language that shared choice takes over
	//    so every station's slips match — but ABOVE the branch/operator guesses
	//    because it is a deliberate human choice, not an inferred default.
	if v := normalizePrintLocaleStrict(s.settingValue(printLocaleOverrideKey)); v != "" {
		return v
	}
	// 3. Branch default (already mirrored by PullBranch).
	var branchLocale sql.NullString
	_ = s.db.QueryRow(`SELECT locale FROM branches LIMIT 1`).Scan(&branchLocale)
	if v := normalizePrintLocaleStrict(branchLocale.String); v != "" {
		return v
	}
	// 4. Legacy operator locale (settings.pos_print_locale) — auto-captured at
	//    payment time, not a deliberate choice, so it stays the last resort.
	return normalizePrintLocaleStrict(s.rememberedPrintLocale())
}

// normalizePrintLocaleStrict whitelists a stored locale. Unlike
// normalizePrintLocale (which defaults an unknown REQUEST header to "ja"), this
// returns "" for anything unsupported so the caller falls through to the next
// configured source instead of stopping at a language nobody chose.
func normalizePrintLocaleStrict(raw string) string {
	v := strings.ToLower(strings.TrimSpace(raw))
	if supportedPrintLocales[v] {
		return v
	}
	return ""
}

// orderPrintLocale is the per-order entry point for the auto-print paths. The
// shop-wide language governs regardless of who placed the order, so this is a
// thin wrapper — kept as a named function because the auto-print call sites read
// better with the order in scope, and so a future per-order exception has one
// place to live.
//
// `customer_locale` is deliberately NOT consulted. A guest ordering in Japanese
// used to flip the kitchen ticket to Japanese while the receipt stayed
// Vietnamese; the kitchen reads one language and the slips must match.
func (s *Server) orderPrintLocale(orderID string) string {
	return s.printLabelLocale()
}

// autoPrintReceiptOnce prints the payment receipt at most once per order.
// Best-effort: a print failure never blocks the sync loop.
func (s *Server) autoPrintReceiptOnce(orderID string, amount int) {
	if !s.claimAutoPrint("receipt", orderID) {
		return
	}
	// Auto-print has no request Accept-Language. The receipt is handed to the
	// customer, so prefer the locale they ordered in; a POS-created order with
	// no customer locale falls back to the last operator locale captured at
	// payment time (rememberPrintLocale), exactly like the manual path.
	if err := s.printPaymentReceipt(orderID, amount, s.orderPrintLocale(orderID), ""); err != nil {
		slog.Warn("auto-print payment receipt failed", "order", orderID, "err", err)
	}
}

// handleOrderArrivedAutoPrint runs when an order is first inserted locally from
// a pull-down.
//
//   - Dine-in / spot (a customer-web QR-table order, first seen via pull): fire
//     the kitchen + hold slip now — gated by the auto_print_kitchen toggle — and
//     mark the order auto-print-eligible so later "add more" rounds (handled on
//     merge) reprint their delta too. POS-created dine-in orders never reach
//     here: they exist locally before the pull merges them, so onOrderArrived
//     doesn't fire — their manual-fire workflow is left untouched.
//   - Takeaway Mode B (prep-first): fire the kitchen ticket on arrival.
//   - Takeaway Mode A, or any already-closed order: defer to the paid hook.
func (s *Server) handleOrderArrivedAutoPrint(orderID, orderType, status string) {
	if status == "closed" {
		return // already paid — the paid hook prints the receipt
	}

	if orderType != "takeaway" {
		if !s.autoPrintKitchenEnabled() {
			return // dine-in kitchen auto-print turned off for this shop
		}
		s.markDineInAutoPrint(orderID)
		s.autoPrintKitchen(orderID)
		return
	}

	if s.prepBeforePayment() {
		return // Mode A: the kitchen waits until the customer pays
	}
	s.autoPrintKitchen(orderID) // Mode B: fire the kitchen ticket on arrival
}

// handleOrderMergedAutoPrint runs on every pull-down merge of an order that
// already existed locally (an update / "add more" round; the first insert goes
// through handleOrderArrivedAutoPrint). It sends a dine-in order's newly
// appended batch to the kitchen + hold printer.
//
// Gated on markDineInAutoPrint eligibility so ONLY customer-web orders (first
// seen via pull) auto-fire their appends — a POS-created dine-in order is never
// marked, so its later rounds keep waiting for the staff's manual fire.
// fireKitchenForOrder is delta-idempotent (printed_quantity), so a merge that
// added no new items reprints nothing.
func (s *Server) handleOrderMergedAutoPrint(orderID, orderType, status string) {
	if orderType == "takeaway" {
		return // takeaway keeps the arrived / paid-hook behaviour
	}
	if status == "closed" || status == "voided" {
		return // nothing new to send to the kitchen
	}
	if !s.autoPrintKitchenEnabled() {
		return
	}
	if !s.isDineInAutoPrintEligible(orderID) {
		return // not a customer-web order we adopted for auto-print
	}
	s.autoPrintKitchen(orderID)
}

// markDineInAutoPrint tags a dine-in order as customer-web-originated (first seen
// via pull) so later append rounds auto-fire on merge. Backed by the shared
// idempotency store — survives restarts within its 24h TTL (longer than any
// table session). No-op without the store.
func (s *Server) markDineInAutoPrint(orderID string) {
	if s.idempotency == nil {
		return
	}
	_ = s.idempotency.Put(dineInAutoPrintKey(orderID), autoPrintDeviceID, "", "eligible")
}

// isDineInAutoPrintEligible reports whether markDineInAutoPrint tagged this order.
func (s *Server) isDineInAutoPrintEligible(orderID string) bool {
	if s.idempotency == nil {
		return false
	}
	_, found, _ := s.idempotency.Get(dineInAutoPrintKey(orderID), autoPrintDeviceID)
	return found
}

func dineInAutoPrintKey(orderID string) string { return "dinein-autoprint:" + orderID }

// handleOrderPaidAutoPrint runs when an order first transitions to closed/paid
// via pull-down.
//
//   - Takeaway + Mode A: fire kitchen ticket + receipt together (kitchen was
//     held until payment).
//   - Takeaway + Mode B: receipt only (kitchen already fired on arrival).
//   - Non-takeaway (dine-in/spot): keep the legacy auto_print_bill-gated
//     receipt behaviour so existing POS flows are unaffected.
func (s *Server) handleOrderPaidAutoPrint(orderID string, amount int) {
	if s.orders == nil {
		return
	}
	o, err := s.orders.GetByID(orderID)
	if err != nil || o == nil {
		slog.Warn("auto-print on paid: order not found locally", "order", orderID, "err", err)
		return
	}

	if string(o.OrderType) != "takeaway" {
		if s.autoPrintBillEnabled() {
			s.autoPrintReceiptOnce(orderID, amount)
		}
		// A dine-in order that reaches this pull-down paid hook was settled
		// remotely (in Cloud) — i.e. the customer self-paid online — so notify
		// the kitchen/hold staff which table is now paid.
		if o.OrderType == "dine_in" {
			s.fireTablePaidSlip(o.ID, s.rememberedPrintLocale())
		}
		return
	}

	if s.prepBeforePayment() {
		s.autoPrintKitchen(orderID)
	}
	s.autoPrintReceiptOnce(orderID, amount)
}

// handleLocalPaymentAutoPrint runs when a payment is settled on the workstation
// itself (kiosk / POS counter-pay over the LAN) rather than in Cloud — the
// order is closed LOCALLY, so the sync-down onOrderPaid hook never fires. Same
// orchestration as the paid hook, plus the kiosk print-status broadcast the
// legacy receipt path emitted.
//
//   - Takeaway + Mode A: kitchen (held until payment) + receipt.
//   - Takeaway + Mode B: receipt only (kitchen already fired on arrival).
//   - Non-takeaway (dine-in/POS): receipt only, still gated by auto_print_bill.
func (s *Server) handleLocalPaymentAutoPrint(orderID string, amount int) {
	if s.orders == nil {
		return
	}
	o, err := s.orders.GetByID(orderID)
	if err != nil || o == nil {
		return
	}
	// Only act once the order is fully settled (closed) — a partial split
	// payment must not fire the kitchen or the final receipt early.
	if string(o.Status) != "closed" {
		return
	}
	takeaway := string(o.OrderType) == "takeaway"

	if takeaway && s.prepBeforePayment() {
		s.autoPrintKitchen(orderID)
	}

	// Takeaway always prints the receipt on payment (the feature). Non-takeaway
	// keeps the legacy auto_print_bill gate so existing POS flows are unchanged.
	if takeaway || s.autoPrintBillEnabled() {
		if s.claimAutoPrint("receipt", orderID) {
			printErr := s.printPaymentReceipt(orderID, amount, s.orderPrintLocale(orderID), "") // customer locale, else last operator locale
			if printErr != nil {
				slog.Warn("auto-print payment receipt failed", "order", orderID, "err", printErr)
			}
			// Tell the kiosk whether the bill actually came out.
			s.broadcastPrintStatus(orderID, "payment_receipt", printErr)
		}
	}

	// Dine-in settled over the LAN means the customer self-paid (kiosk / QR) —
	// this handler is only ever reached from the kiosk self-pay endpoints, never
	// the staff counter path. Notify the kitchen/hold staff the table is paid.
	if o.OrderType == "dine_in" {
		s.fireTablePaidSlip(o.ID, s.rememberedPrintLocale())
	}
}

// fireTablePaidSlip prints the tiny "which table just paid" staff notification
// to the kitchen (or hold) printer when a dine-in order is settled by an online
// (kiosk / QR) self-payment — the floor/kitchen staff otherwise have no signal
// that a self-served table has paid and can be cleared. Best-effort: a missing
// printer / print error never blocks the sync loop. Gated by the shop setting
// print_table_paid and made once-per-order by claimAutoPrint.
func (s *Server) fireTablePaidSlip(orderID, locale string) {
	if s.orders == nil || s.devices == nil {
		return
	}
	// Shop can opt out; on by default so the kitchen/hold staff get the signal.
	if s.shopSetting("print_table_paid", "true") == "false" {
		return
	}
	o, err := s.orders.GetByID(orderID)
	if err != nil || o == nil {
		slog.Warn("table-paid slip: order not found locally", "order", orderID, "err", err)
		return
	}
	// A table-less dine-in order shouldn't print a blank "BAN -" slip.
	if strings.TrimSpace(o.TableNumber) == "" {
		return
	}
	// Once per order — a re-sync / split-payment settle must not reprint.
	if !s.claimAutoPrint("table_paid", orderID) {
		return
	}

	// Kitchen is the primary staff-facing target; fall back to the hold station,
	// then the receipt printer so a single-printer shop still gets the slip.
	rp := s.devices.GetPrinterByRole(printer.TypeKitchenPrinter)
	if rp == nil {
		rp = s.devices.GetPrinterByRole(printer.TypeHoldPrinter)
	}
	if rp == nil {
		rp = s.devices.GetPrinterByRole(printer.TypeReceiptPrinter)
	}
	if rp == nil {
		slog.Warn("table-paid slip: no printer configured", "order", orderID)
		return
	}

	// Full date/time in shop tz — same "2006/01/02 15:04" shape the receipt
	// prints. Prefer the order's paid timestamp, but fall back to now (the print
	// moment, exactly what the receipt uses) so the line is never blank — closed_at
	// can be nil on the sync-down / raw-close paths.
	loc := s.shopLocation()
	when := time.Now()
	switch {
	case o.ClosedAt != nil:
		when = *o.ClosedAt
	case o.CheckoutAt != nil:
		when = *o.CheckoutAt
	}
	paidAt := when.In(loc).Format("2006/01/02 15:04")

	slip := service.FormatTablePaid(service.PrintJobConfig{
		PaperWidth:    42,
		PhysicalWidth: rp.CharWidth(), // center the 42-col layout on a wider (80mm) head
		Locale:        locale,
		StoreName:     s.settingValue("workstation_branch_name"),
	}, service.TablePaidInfo{
		TableNumber: o.TableNumber,
		OrderCode:   o.OrderCode,
		PaidAt:      paidAt,
	})

	if err := rp.Connect(); err != nil {
		slog.Warn("table-paid slip: printer connect failed", "order", orderID, "err", err)
		return
	}
	defer rp.Disconnect()
	if err := rp.Print(slip); err != nil {
		slog.Warn("table-paid slip: print failed", "order", orderID, "err", err)
	}
}
