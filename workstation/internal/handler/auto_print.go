package handler

import (
	"database/sql"
	"errors"
	"log/slog"
	"strconv"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printjob"
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
// from customer-web (QR-table ordering) should auto-fire its kitchen + hall slip
// on arrival and on later "add more" rounds. Local workstation toggle
// (auto_print_kitchen), OFF unless a manager sets it to "true" — the mirror of
// the auto_print_bill receipt gate. Takeaway kitchen timing is separate
// (prep_before_payment); POS-created dine-in orders are never auto-fired.
func (s *Server) autoPrintKitchenEnabled() bool {
	return s.settingValue("auto_print_kitchen") == "true"
}

// autoPrintMaxAge is how old a synced order may be and still auto-print. An
// order that arrives via the pull-down mirror (pullCustomerOrders) with an
// opened_at older than this is treated as backfill / re-seed data — NOT a live
// customer order — so its kitchen ticket is NOT auto-fired ON ARRIVAL. Staff
// reprint on demand via the LAN print endpoints ("Gửi bếp" / in hoá đơn).
// Default 5 minutes; override with settings['auto_print_max_age_minutes'].
//
// A live customer-web order reaches the workstation within one pull cycle
// (~5 s), so its opened_at is seconds old when first seen — a 5-minute window
// still leaves a wide margin for that while catching a seeded order that first
// appears 5+ minutes after its opened_at. It is the SECONDARY layer: the primary
// guard against a bulk re-seed is the pull's bulk-count suppression
// (SyncPuller.autoPrintBulkMax, #141), which the age gate backs up for small
// seeds that stay under the bulk threshold. (Trade-off: an order placed during a
// WS-offline gap longer than the window won't auto-print — staff reprint it.)
func (s *Server) autoPrintMaxAge() time.Duration {
	const def = 5 * time.Minute
	v := strings.TrimSpace(s.settingValue("auto_print_max_age_minutes"))
	if v == "" {
		return def
	}
	n, err := strconv.Atoi(v)
	if err != nil || n <= 0 {
		return def
	}
	return time.Duration(n) * time.Minute
}

// syncedOrderTooOldToAutoPrint reports whether a pulled-down order is old enough
// to be treated as backfill/seed rather than a live order, so kitchen auto-print
// on ARRIVAL should be skipped (staff reprints manually). Age is measured from
// orders.opened_at, which for an arriving order is ~now — used ONLY by the
// arrived hook. It is deliberately NOT applied to the paid hook: an order can be
// open far longer than the window before it is paid, so opened_at there is not a
// "backfill" signal (that path relies on the pull's bulk-count guard instead).
//
// Fail-open: an unreadable / unparseable / missing opened_at returns false (we
// print) so a real live order is never silently starved of its kitchen ticket
// over a data glitch.
func (s *Server) syncedOrderTooOldToAutoPrint(orderID string) bool {
	var openedAt sql.NullString
	if err := s.db.QueryRow(`SELECT opened_at FROM orders WHERE id = ?`, orderID).Scan(&openedAt); err != nil {
		return false
	}
	opened, ok := parseSqlTime(openedAt)
	if !ok {
		slog.Warn("auto-print age gate: unparseable opened_at", "order", orderID, "opened_at", openedAt.String)
		return false
	}
	age := time.Since(opened)
	if age > s.autoPrintMaxAge() {
		slog.Info("auto-print skipped: synced order older than max age",
			"order", orderID, "age_min", int(age.Minutes()), "max_min", int(s.autoPrintMaxAge().Minutes()))
		return true
	}
	return false
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
	key := autoPrintClaimKey(kind, orderID)
	if _, found, _ := s.idempotency.Get(key, autoPrintDeviceID); found {
		return false
	}
	_ = s.idempotency.Put(key, autoPrintDeviceID, "", "done")
	return true
}

func autoPrintClaimKey(kind, orderID string) string { return "autoprint:" + kind + ":" + orderID }

// releaseAutoPrint gives a claim back after the print it guarded failed.
//
// The claim is taken BEFORE printing on purpose — paper may already be moving
// when an error surfaces, and a claim taken afterwards would double-print on
// every retry. The cost of that ordering is that a lost bet has to be handed
// back explicitly, or one offline printer silences that order's receipt for the
// 24h the key lives.
func (s *Server) releaseAutoPrint(kind, orderID string) {
	if s.idempotency == nil {
		return
	}
	if err := s.idempotency.Delete(autoPrintClaimKey(kind, orderID), autoPrintDeviceID); err != nil {
		slog.Warn("release auto-print claim failed", "kind", kind, "order", orderID, "err", err)
	}
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
// renders in — kitchen ticket, hall/runner slip and payment receipt alike.
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
//  1. settings.print_locale_override — this workstation's own pick, made by an
//     operator in the WS App.
//  2. shop_settings.print_label_locale — what Cloud resolved for this branch
//     (shop override ?? HQ brand default), mirrored down by PullBranch.
//  3. branches.locale — the branch default Cloud already syncs, so a shop that
//     never opens the setting still prints sensibly.
//  4. settings.pos_print_locale — the legacy operator locale, kept so an
//     existing install's behaviour does not change until someone configures it.
//  5. "" — the print layer's own default (ja).
//
// Unsupported/garbage values are dropped at each step rather than passed on.
func (s *Server) printLabelLocale() string {
	if s.db == nil {
		return ""
	}
	// 1. This workstation's OWN pick (settings.print_locale_override), chosen by
	//    an operator in the WS App. It ranks ABOVE the Cloud value on purpose:
	//    the configured chain is HQ default → shop override → workstation
	//    override, each layer free to overrule the one above it, exactly like
	//    the shop overrules HQ on the Cloud side.
	//
	//    It used to sit BELOW Cloud, reasoning that a shop-wide setting keeps
	//    every station's slips matching. But Cloud ALWAYS resolves
	//    `shop ?? brand default` before shipping the feed, so the moment anyone
	//    filled in either layer this branch became unreachable and the WS App
	//    picker turned into a dead control — no way to fix one odd station, and
	//    no way to print at all in the right language while a wrong value was
	//    synced. Leaving the picker empty ("theo Cloud") is still the default
	//    and still the way to keep every station in step.
	if v := normalizePrintLocaleStrict(s.settingValue(printLocaleOverrideKey)); v != "" {
		return v
	}
	// 2. What Cloud resolved for this branch (shop override ?? HQ brand
	//    default), synced from admin-web.
	if v := normalizePrintLocaleStrict(s.shopSetting("print_label_locale", "")); v != "" {
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

// autoPrintPaymentReceipt is the auto-fire path's way to the receipt printer.
//
// #1875 — both auto-print call sites used to go straight to printPaymentReceipt
// with a hard-coded copy number of 1 and NO ledger row at all. Two consequences,
// both live in shops right now:
//
//   - the print ledger was missing every automatically fired receipt. The most
//     common sheet a shop produces was the one plan-052 could not audit.
//   - the auto-printed sheet consumed no copy number, so the cashier's next
//     "In biên lai" also came out as #1, unmarked. The customer was then holding
//     two sheets that each claimed to be the original — exactly the situation
//     the 「BAN IN #N」 mark exists to make impossible.
//
// With no printer configured it journals NOTHING: printPaymentReceipt is a
// silent no-op there, and a ledger row for paper that never moved is a lie the
// rest of the pipeline would then have to be defended against.
func (s *Server) autoPrintPaymentReceipt(orderID string, amount int) error {
	p := s.journalReceiptPrinter()
	if p == nil {
		// Nothing is journalled on this branch, so there is nowhere to record a
		// layout version — discard it rather than invent a row for it.
		_, err := s.printPaymentReceipt(orderID, amount, s.orderPrintLocale(orderID), "", 1)
		return err
	}

	// No payment id: the auto path fires on the ORDER settling, not on one
	// payer's slip. resolvePrintScope collapses that to the order's sole payment
	// when there is exactly one, so a one-payer order keeps a single counter
	// across the auto sheet and every later manual reprint.
	scope := s.resolvePrintScope(orderID, "")
	ledger := printjob.Entry{
		Kind:         printjob.KindReceipt,
		OrderID:      orderID,
		PaymentID:    scope.PaymentID,
		RequestedVia: "workstation",
		Payload:      map[string]any{"template": "payment_receipt", "amount": amount, "trigger": "auto"},
	}
	res := s.beginMoneyPrint(nil, ledger, scope)
	templateVersion, err := s.printPaymentReceipt(orderID, amount, s.orderPrintLocale(orderID), "", res.ReprintNo)
	// TR-28 — the layout that drew the sheet, known only after the render.
	ledger.TemplateVersion = templateVersion
	// "auto" is not a justification for anything, so reprintReasonFor drops it —
	// the row records that this sheet was fired by the system, not asked for.
	s.finishMoneyPrint(res, p, ledger, "auto", err)
	return err
}

// autoPrintReceiptOnce prints the payment receipt at most once per order.
// Best-effort: a print failure never blocks the sync loop.
// errNoReceiptPrinter — không có máy nào mang role receipt_printer. Là một
// lỗi THẬT chứ không phải trạng thái im lặng: chứng từ tiền không in được
// thì người bán phải biết ngay (#2593).
var errNoReceiptPrinter = errors.New("no printer with role receipt_printer")

func (s *Server) autoPrintReceiptOnce(orderID string, amount int) {
	// Do not consume the claim when nothing can possibly reach paper. The claim
	// is not bookkeeping — other paths read it as "this receipt has been
	// printed", and the LAN print handler stands down on it. With no receipt
	// printer configured, printPaymentReceipt is a silent no-op that returns
	// nil, so claiming here would assert a print that never happened and take
	// the manual path down with it.
	if s.devices == nil || s.resolveReceiptPrinter() == nil {
		return
	}
	if !s.claimAutoPrint("receipt", orderID) {
		return
	}
	// Auto-print has no request Accept-Language. The receipt is handed to the
	// customer, so prefer the locale they ordered in; a POS-created order with
	// no customer locale falls back to the last operator locale captured at
	// payment time (rememberPrintLocale), exactly like the manual path.
	if err := s.autoPrintPaymentReceipt(orderID, amount); err != nil {
		slog.Warn("auto-print payment receipt failed", "order", orderID, "err", err)
		// Hand the claim back. Holding it over a FAILED print is what turns one
		// offline printer into a receipt that can never be produced again until
		// the key ages out. It also has to be released for the LAN print
		// handler's sake: that handler reads "a claim appeared while I was
		// fetching the order" as "the receipt is already on paper" and stands
		// down, so a retained claim over a failed print would convert a visible
		// printer error into a silent 200 with nothing in the customer's hand.
		s.releaseAutoPrint("receipt", orderID)
	}
}

// handleOrderArrivedAutoPrint runs when an order is first inserted locally from
// a pull-down.
//
//   - Dine-in / spot (a customer-web QR-table order, first seen via pull): fire
//     the kitchen + hall slip now — gated by the auto_print_kitchen toggle — and
//     mark the order auto-print-eligible so later "add more" rounds (handled on
//     merge) reprint their delta too. POS-created dine-in orders never reach
//     here: they exist locally before the pull merges them, so onOrderArrived
//     doesn't fire — their manual-fire workflow is left untouched.
//   - Takeaway Mode B (prep-first): fire the kitchen ticket on arrival.
//   - Takeaway Mode A: defer the kitchen to the paid hook.
//
// `status` is what Cloud reported for this FIRST sighting, and it can already be
// `closed` — the customer placed and paid before this workstation's next
// successful pull (a fast online settle, a restart, a network gap). That case
// used to return here, on the reasoning that "the paid hook prints it". The paid
// hook does print the RECEIPT, but it fires the KITCHEN only for takeaway Mode A
// (`handleOrderPaidAutoPrint`): a dine-in order got its receipt and its
// table-paid slip while the cook was never told an order existed, and takeaway
// Mode B fell in the same hole because the paid hook assumes arrival already
// fired — which it had not. So an already-closed order now walks the same gates
// as an open one. Nothing double-prints: the paid hook runs FIRST (see
// `upsertOrderCtxWithSnapshot`) and `fireKitchenForOrder` is delta-idempotent
// via `printed_quantity`, so Mode A's second call finds a closed delta and does
// nothing. The re-seed guards still stand in front — the opened_at age gate
// below and the pull's bulk-count suppression — so a backfill of old closed
// orders cannot storm the kitchen.
func (s *Server) handleOrderArrivedAutoPrint(orderID, orderType, status string) {
	if s.syncedOrderTooOldToAutoPrint(orderID) {
		return // backfill / re-seed of an old order — staff reprints on demand
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
// appended batch to the kitchen + hall printer.
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
	// NOTE: the paid hook is deliberately NOT age-gated on opened_at. This hook is
	// the auto-print path for a Cloud-settled kiosk/customer payment (the order
	// closes via pull-down, not a local payment), and an order can legitimately be
	// OPEN for far longer than the age window before it is paid — gating on
	// opened_at here silently swallowed the receipt for every real kiosk payment.
	// The re-seed receipt storm is instead handled by the pull's bulk-count guard
	// (SyncPuller.autoPrintBulkMax) plus claimAutoPrint's per-order idempotency (#141).

	if string(o.OrderType) != "takeaway" {
		if s.autoPrintBillEnabled() {
			s.autoPrintReceiptOnce(orderID, amount)
		}
		// A dine-in order that reaches this pull-down paid hook was settled
		// remotely (in Cloud) — i.e. the customer self-paid online — so notify
		// the hall staff which table is now paid.
		if o.OrderType == "dine_in" {
			s.fireTablePaidSlip(o.ID, s.orderPrintLocale(o.ID))
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
	// #1951 — the till opens HERE, on the payment event, and before the receipt
	// is drawn. Two reasons, both deliberate:
	//
	//  1. Keyed to the payment, not the slip. Hanging the kick off printing means
	//     a REPRINT pops the drawer, and a till that springs open because someone
	//     pressed "print again" is a theft window, not a convenience.
	//  2. Before the print, not after. The cashier wants the drawer open while
	//     counting change; making them wait on paper is the delay the feature
	//     exists to remove.
	//
	// Cash only, and a no-op on a machine with no drawer — see drawer.go.
	s.kickDrawerForCashPayment(orderID)

	takeaway := string(o.OrderType) == "takeaway"

	if takeaway && s.prepBeforePayment() {
		s.autoPrintKitchen(orderID)
	}

	// Takeaway always prints the receipt on payment (the feature). Non-takeaway
	// keeps the legacy auto_print_bill gate so existing POS flows are unchanged.
	if takeaway || s.autoPrintBillEnabled() {
		// #2593 vòng 2 — nil-guard phải chạy TRƯỚC `claimAutoPrint`, y như
		// `autoPrintReceiptOnce` (đường cloud-settled). Sibling này gọi claim
		// thẳng, và đó là một lỗ hổng thật cho caller LOCAL-payment: kiosk tự
		// thanh toán, card-terminal, takeaway.
		//
		// Không máy receipt ⇒ `autoPrintPaymentReceipt` no-op IM LẶNG trả nil ⇒
		// `printErr == nil` ⇒ claim KHÔNG được nhả **và** kiosk nhận
		// `status: success`. Claim latch 24h, nên cắm máy receipt hay tick lại
		// role cũng không retry được: quán hall-only takeaway thu tiền của khách
		// ở kiosk, kiosk báo đã in, không tờ giấy nào ra, và không cách nào in
		// lại tự động.
		//
		// Claim KHÔNG phải sổ sách — các đường khác đọc nó là "biên lai này đã
		// in xong" và đường in tay đứng xuống theo nó.
		if s.devices == nil || s.resolveReceiptPrinter() == nil {
			// Kêu to: kiosk phải biết là KHÔNG có giấy, thay vì thấy success.
			s.broadcastPrintStatus(orderID, "payment_receipt", errNoReceiptPrinter)
		} else if s.claimAutoPrint("receipt", orderID) {
			printErr := s.autoPrintPaymentReceipt(orderID, amount) // shop-wide print language
			if printErr != nil {
				slog.Warn("auto-print payment receipt failed", "order", orderID, "err", printErr)
			}
			// Tell the kiosk whether the bill actually came out.
			s.broadcastPrintStatus(orderID, "payment_receipt", printErr)
		}
	}

	// Dine-in settled over the LAN means the customer self-paid (kiosk / QR) —
	// this handler is only ever reached from the kiosk self-pay endpoints and the
	// card-terminal recorder, never the staff counter path. Notify the hall staff
	// the table is paid.
	//
	// The counter path (handleLocalPosCreatePayment, which Handy also delegates
	// to) is excluded ON PURPOSE and not by oversight: it closes the order
	// locally and inline, so nothing else would fire the slip for it either.
	// A cashier settling at the till is standing in front of the customer and
	// already knows — the slip exists for the SELF-served table, where no staff
	// member witnessed the payment.
	if o.OrderType == "dine_in" {
		s.fireTablePaidSlip(o.ID, s.orderPrintLocale(o.ID))
	}
}

// fireTablePaidSlip prints the tiny "which table just paid" staff notification
// to the hall (ホール) printer when a dine-in order is settled by an ONLINE
// (kiosk / QR) self-payment — the floor staff otherwise have no signal that a
// self-served table has paid and can be cleared. A table settled at the POS
// counter deliberately prints nothing: the cashier witnessed that payment.
// Best-effort: a missing printer / print error never blocks the sync loop.
// Gated by the shop setting print_table_paid and made once-per-order by
// claimAutoPrint.
func (s *Server) fireTablePaidSlip(orderID, locale string) {
	if s.orders == nil || s.devices == nil {
		return
	}
	// Shop can opt out; on by default so the hall staff get the signal.
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

	// The HALL (ホール) station is the primary target — that is where the runner
	// who actually clears the table stands. It used to go to the kitchen first,
	// which put a floor notice on the cook's spike among the tickets they are
	// working from. Fall back kitchen → receipt so a single-printer shop still
	// gets the slip.
	rp := s.devices.GetPrinterByRole(printer.TypeHallPrinter)
	if rp == nil {
		rp = s.devices.GetPrinterByRole(printer.TypeKitchenPrinter)
	}
	if rp == nil {
		rp = s.devices.GetPrinterByRole(printer.TypeReceiptPrinter)
	}
	if rp == nil {
		// Subject stays `receipt_printer`: reaching here means NO printer of any
		// role is bound, which is the same condition print_receipt.go raises
		// under that subject — a second subject for one fault would just split
		// the alert group in two.
		s.raiseAlert(service.KindNoPrinter, "receipt_printer",
			"Chưa cấu hình máy in hoá đơn", map[string]any{"order_id": orderID})
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

	// plan-053 T3.6 tầng 2 (#1914) — call site 1/13.
	//
	// #1945: renderer layer 0 bật mặc định; explicit off mới chạy legacy().
	// Brand publish cache opt-in qua print_template_use_published_templates.
	cfg := service.PrintJobConfig{
		PaperWidth:    42,
		PhysicalWidth: rp.CharWidth(), // center the 42-col layout on a wider (80mm) head
		Locale:        locale,
		StoreName:     s.storeName(),
	}
	info := service.TablePaidInfo{
		TableNumber: o.TableNumber,
		OrderCode:   o.OrderCode,
		PaidAt:      paidAt,
	}

	// Dấu phiên bản bị bỏ ở ĐÂY, và đó là điều đúng: phiếu "bàn đã thanh toán"
	// không sinh dòng nhật ký nào (nó không phải chứng từ tiền, không mang số
	// bản in). Không có hàng để đóng dấu thì không đóng — thêm một hàng ledger
	// chỉ để có chỗ ghi phiên bản sẽ làm sai bộ đếm 「Bản in #N」 của đơn.
	slip, _ := s.renderMoneySlip(
		service.NewTablePaidRenderData(cfg, info),
		service.PrintRenderProfileFor(rp.Profile(), ""),
		locale,
		func() []byte { return service.FormatTablePaid(cfg, info) },
	)

	if err := rp.Connect(); err != nil {
		slog.Warn("table-paid slip: printer connect failed", "order", orderID, "err", err)
		return
	}
	defer rp.Disconnect()
	if err := rp.Print(slip); err != nil {
		slog.Warn("table-paid slip: print failed", "order", orderID, "err", err)
	}
}
