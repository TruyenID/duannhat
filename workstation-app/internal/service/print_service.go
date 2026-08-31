package service

import (
	"fmt"
	"math"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

type PrintJobConfig struct {
	StoreName    string  // e.g. "ベト屋"
	StoreSubName string  // e.g. "VIET ORIGIN"
	StoreAddress string  // e.g. "Tokyo..."
	PaperWidth   int     // content layout width in characters (e.g. 42)
	TaxRate      float64 // consumption-tax percent per shop, e.g. 10 = 10%. 0 → fallbackTaxRate.
	Currency     string  // money symbol for printed prices (e.g. "¥", "₫", "$"). Empty → "¥".
	// PhysicalWidth is the printer's real characters-per-line (32 for 58mm, 48
	// for 80mm). When it exceeds the content PaperWidth, the layout is centered
	// on the paper with an equal left/right margin. 0 → no centering (content is
	// printed flush-left, the legacy behavior).
	PhysicalWidth int
	Locale        string // "ja" | "en" | "vi" — label language; empty → "ja". See print_shift_report_i18n.go.
	// CurrencyCode is the ISO 4217 code (e.g. "JPY", "VND") the shop prices in.
	// Drives the per-rate tax-block rounding STEP (JPY/VND = 1, USD = 0.01) so
	// the printed 内消費税 figures round the same way the engine does. Empty →
	// project default (VND → step 1), matching the engine's currencyStep.
	CurrencyCode string
	// SellerRegistrationNumber is the インボイス T+13 registration number
	// (適格請求書発行事業者登録番号). Printed as "登録番号: {value}" ONLY when
	// non-empty (Q5 — null for now, so it typically won't print).
	SellerRegistrationNumber string
}

// step returns the currency rounding step for the per-rate tax blocks, derived
// from CurrencyCode via the same currencyStep the engine uses.
func (c PrintJobConfig) step() float64 {
	return currencyStep(c.CurrencyCode)
}

// leftMargin returns the blank columns to indent content by so a contentW-wide
// layout is centered within the printer's PhysicalWidth (equal margin on both
// sides). Returns 0 when PhysicalWidth is unset or not wider than the content.
func (c PrintJobConfig) leftMargin(contentW int) int {
	if c.PhysicalWidth > contentW {
		return (c.PhysicalWidth - contentW) / 2
	}
	return 0
}

// cur returns the money symbol to prefix printed prices with, so a VND shop
// prints ₫ instead of a hard-coded ¥. Defaults to ¥ when unset (back-compat).
func (c PrintJobConfig) cur() string {
	if c.Currency == "" {
		return "¥"
	}
	return c.Currency
}

// fallbackTaxRate is used when the shop has no tax_rate configured (Japan
// standard consumption tax). Per-shop tax_rate from shop_settings always wins.
const fallbackTaxRate = 10.0

// taxBreakdown returns the tax-included consumption tax for a gross (total)
// amount. Japanese receipts are tax-INCLUDED: the displayed total already
// contains the tax, so tax = total - round(total / (1 + rate/100)).
//
// Resolution order:
//  1. orderTax (order.TaxAmount from backend) when > 0 — authoritative, never recomputed.
//  2. otherwise compute tax-included from total using rate (percent).
//
// rate of 0 falls back to fallbackTaxRate so a misconfigured shop still
// produces a sane breakdown rather than ¥0 tax.
func taxBreakdown(total, orderTax int, rate float64) int {
	if orderTax > 0 {
		return orderTax
	}
	if total <= 0 {
		return 0
	}
	if rate <= 0 {
		rate = fallbackTaxRate
	}
	net := math.Round(float64(total) / (1 + rate/100))
	return total - int(net)
}

// FormatKitchenTicket formats a kitchen ticket (phiếu bếp).
//
// Layout:
//
//	Cach dat   STT   So HD   Ban
//	Tai ban    319   004     A-1    ← STT = incrementing daily count, So HD = order-code suffix (matches receipt), table bold
//	- - - - - - - - - - - - - - -
//	San pham              Thanh tien
//	1  Bun ga                ¥1,000
//	   —— Tang co mi           ¥150
//	   Ghi chu: It cay
//	- - - - - - - - - - - - - - -
//	              Tong cong  ¥2,200
//
// STT is the per-day kitchen ticket counter (ticketNo). So HD is the invoice
// number shown on the customer receipt (OrderCodeSuffix), so the kitchen slip
// and the receipt can be cross-referenced by the same "So HD".
// OrderCodeSuffix extracts the last segment of an OrderCode separated by "-".
// "WS-019e-20260608-004" → "004". Falls back to the full code if no "-" found.
func OrderCodeSuffix(code string) string {
	if i := strings.LastIndex(code, "-"); i >= 0 && i+1 < len(code) {
		return code[i+1:]
	}
	return code
}

func FormatKitchenTicket(order *Order, items []Item, ticketNo int, config PrintJobConfig) []byte {
	w := config.PaperWidth
	if w == 0 {
		w = 48
	}

	e := escpos.New()
	e.SetLeftMargin(config.leftMargin(w))
	e.Align(escpos.AlignLeft)

	labels := printLabelsFor(config.Locale)

	// ─── row 1: label row (small) ───
	orderTypeLabel := labels.DineIn
	switch order.OrderType {
	case "takeaway":
		orderTypeLabel = labels.Takeaway
	case "spot":
		orderTypeLabel = labels.Spot
	}

	// Takeaway has no table — drop the "Ban" column entirely (header AND value)
	// so a pickup slip carries no table reference at all.
	isTakeaway := order.OrderType == "takeaway"

	// Columns: Cach dat | STT | So HD [| Ban]. col1 gets a floor of 9 so the
	// "Cach dat" header can't collide with STT on a narrow 58mm (w=32) printer.
	col1W := max(w/4, 9)
	col2W := (w - col1W) / 3
	col3W := (w - col1W) / 3
	header := padRight(labels.OrderMethod, col1W) + padRight(labels.TicketSeq, col2W) + padRight(labels.OrderNo, col3W)
	if !isTakeaway {
		header += labels.Table
	}
	e.Line(header)

	// ─── row 2: values — orderType | STT (daily count) | So HD (order code)
	// [| tableNo bold] ───
	sttStr := fmt.Sprintf("%d", ticketNo)
	hdStr := OrderCodeSuffix(order.OrderCode)
	if hdStr == "" {
		hdStr = "-"
	}
	if isTakeaway {
		// No table column: So HD is the last field, so it needs no right-padding.
		e.Line(padRight(orderTypeLabel, col1W) + padRight(sttStr, col2W) + hdStr)
	} else {
		tableStr := order.TableNumber
		if tableStr == "" {
			tableStr = "-"
		}
		e.Text(padRight(orderTypeLabel, col1W) + padRight(sttStr, col2W) + padRight(hdStr, col3W))
		e.Bold(true)
		e.Line(tableStr)
		e.Bold(false)
	}

	// ─── takeaway customer (Khach hang / SDT) — who the order is for ───
	printCustomerHeader(e, order, labels)

	// ─── order-level note (Ghi chu) — whole-order instruction for the kitchen ───
	if note := strings.TrimSpace(order.Note); note != "" {
		e.Feed(1)
		e.Bold(true)
		printNoteLines(e, w, 0, note, labels.NotePrefix)
		e.Bold(false)
	}

	// ─── separator + column header ───
	e.Feed(1)
	e.Line(dashedLine(w))
	e.Feed(1)
	priceHeader := labels.Price
	itemHeaderLeft := labels.Item
	e.Line(padRight(itemHeaderLeft, w-displayWidth(priceHeader)) + priceHeader)
	e.Feed(1)

	// ─── per-rate tax breakdown (plan-043) — computed on THIS batch's items so
	// the ※ markers + the footer blocks below match the batch total exactly. ───
	taxSummary := buildReceiptTaxSummary(order, items, config.step())

	// ─── items ───
	for _, item := range items {
		reduced := taxSummary.HasReduced && isReducedLine(item, taxSummary.blockMaxRate())
		printKitchenItem(e, w, item, config.cur(), reduced, config.Locale, labels.NotePrefix)
	}

	// ─── footer: sum of this batch only ───
	batchTotal := 0
	for _, item := range items {
		batchTotal += item.UnitPrice * item.Quantity
		for _, t := range item.Toppings {
			batchTotal += t.UnitPrice * t.Quantity
		}
	}
	e.Feed(1)
	e.Line(dashedLine(w))
	e.Feed(1)
	totalStr := config.cur() + formatPrice(batchTotal)
	totalLabel := labels.KitchenTotal
	gap := w - displayWidth(totalLabel) - displayWidth(totalStr)
	if gap < 1 {
		gap = 1
	}
	e.Bold(true)
	e.Line(totalLabel + spaces(gap) + totalStr)
	e.Bold(false)

	// ─── per-rate tax breakdown below the batch total (issue #1042 layout) ───
	// Same block the customer bill prints, but for this batch only. legacyOrderTax
	// is 0 so a snapshot-less order extracts the tax from batchTotal, not the
	// whole-order order.TaxAmount (which wouldn't match a partial kitchen ticket).
	printTaxBreakdown(e, w, order, config, taxSummary, batchTotal, 0)

	e.Feed(1)
	e.FullCut()
	return e.Bytes()
}

// printCustomerHeader prints the takeaway customer's name (+ phone) so both the
// kitchen ticket and the delta-QR serving slip identify who the order is for.
// Only takeaway carries these fields (customer_takeaway_name/phone), so dine-in
// / spot orders print nothing here. Each line is emitted only when non-empty so
// a name-without-phone (or vice versa) never leaves a dangling label.
func printCustomerHeader(e *escpos.Encoder, order *Order, labels printLabels) {
	if order == nil || order.OrderType != "takeaway" {
		return
	}
	if name := strings.TrimSpace(order.CustomerTakeawayName); name != "" {
		e.Line(labels.CustomerLabel + ": " + name)
	}
	if phone := strings.TrimSpace(order.CustomerTakeawayPhone); phone != "" {
		e.Line(labels.Phone + ": " + phone)
	}
	if pu := formatPickupTime(order.ScheduledPickupTime); pu != "" {
		e.Line(labels.PickupTime + ": " + pu)
	}
}

// formatPickupTime renders the ISO-8601 pickup timestamp Cloud mirrored into
// "MM/DD HH:MM" for the slip. Returns "" for an empty or unparseable value so a
// missing/garbage pickup never prints a bare label. Matches the local-clock
// rendering formatBillTicket already uses for the slip date line (both call
// .Local()); we deliberately avoid a shop-timezone lookup the slip date itself
// doesn't do, keeping the two lines consistent.
func formatPickupTime(raw string) string {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return ""
	}
	t, err := time.Parse(time.RFC3339, raw)
	if err != nil {
		return ""
	}
	return t.Local().Format("01/02 15:04")
}

// printKitchenItem prints one item row for the kitchen ticket:
// - item name (+ ※ when this is a reduced-rate 軽減税率 line, plan-043) + price (bold)
// - toppings: "   —— Name        ¥price"  (from item.Toppings)
// - free note: "   Ghi chu: ..."          (from item.Note, no price)
//
// reduced marks the line as 軽減税率対象 → a ※ is appended to the name, matching
// printRunnerItem so the kitchen ticket's ※ markers agree with its tax legend.
func printKitchenItem(e *escpos.Encoder, w int, item Item, cur string, reduced bool, locale, notePrefix string) {
	if item.Quantity <= 0 || strings.TrimSpace(item.MenuItemName) == "" {
		return
	}

	name := stripVariantSuffix(item.MenuItemName)
	if reduced {
		name = name + " " + taxLabelsFor(locale).ReducedMarker
	}
	slStr := fmt.Sprintf("%d", item.Quantity)
	priceStr := cur + formatPrice(item.UnitPrice*item.Quantity)
	indentW := utf8.RuneCountInString(slStr) + 2

	e.Bold(true)
	printWrappedName(e, w, slStr, name, priceStr)
	e.Bold(false)

	printToppingLines(e, w, indentW, item, cur, notePrefix)
}

// FormatRunnerTicket formats a waiter/hold ticket (hóa đơn bàn).
//
// Always shows ALL items of the order (not just the newly fired batch)
// so the waiter has a complete bill. On re-fire (add more items), the
// previous hold slip is superseded by this new full reprint.
//
//	ベト屋                       HOA DON BAN
//	VIET ORIGIN
//	2026/01/10 12:49                   #525
//	So HD                              Tong: ¥1,780
//	  525                              Ban:  C-07
//	- - - - - - - - - - - - - - - - - - - -
//	San pham                      Thanh tien
//	1  Bun ga la chanh               ¥980
//	   —— Them rau mui               ¥150
//	   Ghi chu: Khong hanh
//	- - - - - - - - - - - - - - - - - - - -
//	Tong (co thue)                  ¥1,780
//	Thue                              ¥162
//	Con lai                             ¥0
//	[QR — order.ID centered]
func FormatRunnerTicket(order *Order, items []Item, ticketNo int, config PrintJobConfig) []byte {
	// Fallback: if order totals are 0 (legacy/race), derive the gross total
	// from items (tax-INCLUDED prices → line sum IS the total).
	total := orderGrossTotal(order, items)
	return formatBillTicket(order, items, config, billTicketOpts{
		title:     printLabelsFor(config.Locale).TitleTableBill,
		total:     total,
		remaining: total - order.PaidAmount,
		showQR:    true,
	})
}

// FormatDeltaQRTicket is the customer-facing QR slip printed on each fire. It
// lists ONLY the newly-fired items (the delta) with their prices + a delta
// subtotal, plus a QR (order.ID) the customer scans at a kiosk. This is the
// per-fire counterpart to FormatRunnerTicket (the full-order QR bill printed
// at checkout): both carry the same order QR, but this one shows just "món vừa
// thêm". deltaBill mode suppresses the whole-order tax breakdown so the total
// matches the partial item list.
func FormatDeltaQRTicket(order *Order, items []Item, config PrintJobConfig) []byte {
	// Sum the delta lines (unit_price*qty + toppings) — same basis as the
	// kitchen ticket's batch total, so the two fire slips agree.
	total := 0
	for _, it := range items {
		total += it.UnitPrice * it.Quantity
		for _, t := range it.Toppings {
			total += t.UnitPrice * t.Quantity
		}
	}
	labels := printLabelsFor(config.Locale)
	// Normally titled "追加商品" (newly-added items) — the waiter's serving slip.
	// A takeaway order has no waiter round; the slip identifies a pickup order,
	// so title it "Takeaway" in the operator's set language (uppercased to match
	// the sibling bill titles). 持ち帰り / TAKEAWAY / MANG VE.
	title := labels.TitleNewItems
	if order.OrderType == "takeaway" {
		title = strings.ToUpper(labels.Takeaway)
	}
	return formatBillTicket(order, items, config, billTicketOpts{
		title:     title,
		total:     total,
		showQR:    true,
		deltaBill: true,
	})
}

// PaymentSlipInfo carries the per-slip payment context for a paid receipt.
// For split bills, AmountPaid is this slip's amount (not the order total) and
// SlipIndex/SplitCount drive the "Khach n/N" label.
type PaymentSlipInfo struct {
	PaymentMethod string // e.g. "cash", "qr", "card" — printed as-is if non-empty
	AmountPaid    int    // amount settled on THIS slip (split) or the full total
	SlipIndex     int    // 1-based index of this payment within the order (0 = unknown)
	SplitCount    int    // total people in the split (0/1 = not a split)
	Remaining     int    // "Con lai" on the slip (sub-bill remainder for a split)
	BillTotal     int    // headline "Tong" — this slip's sub-bill total (0 = use order total)
	// Plan-038 T2.4 — by_amount per-person label ("Người 1", "Người 2", …).
	// When set, the formatter prints "Khach: <label>" instead of the
	// numeric "Khach n/N" so the slip identifies the payer by name.
	Label string
	// Cash tendered + change returned. Printed on the paid slip only when
	// Tendered > 0 (a cash payment recorded the amount handed over). Both are
	// omitted for non-cash methods where they don't apply.
	Tendered int
	Change   int
	// CustomerName — when non-empty the slip prints a "Khach hang: <name>" line.
	// Used by the red-invoice (hoá đơn đỏ) variant which names the payer.
	CustomerName string
	// Split visualization (share-bill receipts). SplitMode drives the top
	// "HOA DON CHIA" banner + the share breakdown in the footer:
	//   - "even"/"equal" + "by_amount": full items shown; footer prints
	//     Tong don (OrderGrossTotal) then Phan chia (i/N) = AmountPaid.
	//   - "by_items": only this payer's items shown; footer prints Phan chia
	//     (i/N) = this slip's total, then Tong don (OrderGrossTotal) as context.
	// Empty SplitMode + SplitCount<=1 → a normal (non-split) receipt.
	SplitMode       string
	OrderGrossTotal int // whole-order gross total, for the "Tong don" context line
}

// splitModeKind classifies a slip's split mode. Returns "" for a non-split slip.
func (slip *PaymentSlipInfo) splitModeKind() string {
	if slip == nil {
		return ""
	}
	switch slip.SplitMode {
	case "by_items":
		return "by_items"
	case "by_amount":
		return "by_amount"
	case "even", "equal":
		return "equal"
	}
	// Legacy: a positive multi-count split with no explicit mode → treat as even.
	if slip.SplitCount > 1 {
		return "equal"
	}
	return ""
}

// FormatPaidTicket formats a "DA THANH TOAN" receipt — a runner-ticket clone
// with NO QR, a paid-status title, the payment method, and Con lai = ¥0
// (the order is fully settled, or this slip closed the split).
//
//	ベト屋                      DA THANH TOAN
//	VIET ORIGIN
//	2026/01/10 12:49                   #525
//	...items...
//	- - - - - - - - - - - - - - - - - - - -
//	Tong (co thue)                  ¥1,780
//	Thue                              ¥162
//	Da thanh toan                   ¥1,780
//	Phuong thuc                         cash
//	Con lai                             ¥0
//	(no QR)
func FormatPaidTicket(order *Order, items []Item, ticketNo int, config PrintJobConfig, slip PaymentSlipInfo) []byte {
	// Headline total is this slip's sub-bill when set (split), else the order's
	// gross total. Without BillTotal every split slip would print the whole bill.
	total := slip.BillTotal
	if total <= 0 {
		total = orderGrossTotal(order, items)
	}
	// Paid amount shown is this slip's amount when known, else the full total.
	paid := slip.AmountPaid
	if paid <= 0 {
		paid = total
	}
	return formatBillTicket(order, items, config, billTicketOpts{
		title:     printLabelsFor(config.Locale).TitlePaid,
		total:     total,
		remaining: slip.Remaining, // real "Con lai" (0 on a full settle, >0 on a partial)
		showQR:    false,
		slip:      &slip,
		paidShown: paid,
	})
}

// FormatRedInvoiceTicket formats the hoá đơn đỏ (red invoice) — identical to the
// paid receipt (items + totals + payment method + tendered/change) but titled
// as a red invoice and ALWAYS carrying a customer-name line (the entered name,
// or a blank underline to hand-write it). slip.CustomerName carries the name.
func FormatRedInvoiceTicket(order *Order, items []Item, config PrintJobConfig, slip PaymentSlipInfo) []byte {
	total := slip.BillTotal
	if total <= 0 {
		total = orderGrossTotal(order, items)
	}
	paid := slip.AmountPaid
	if paid <= 0 {
		paid = total
	}
	return formatBillTicket(order, items, config, billTicketOpts{
		title:      printLabelsFor(config.Locale).TitleRedInvoice,
		total:      total,
		remaining:  slip.Remaining,
		showQR:     false,
		slip:       &slip,
		paidShown:  paid,
		redInvoice: true,
	})
}

// FormatRemainingTicket formats the "phan con lai" slip for a split bill —
// identical to the runner ticket (WITH QR so the next person can pay) except
// the headline Total is the REMAINING amount, not the order's gross total.
func FormatRemainingTicket(order *Order, items []Item, ticketNo int, config PrintJobConfig, remaining int) []byte {
	if remaining < 0 {
		remaining = 0
	}
	total := orderGrossTotal(order, items)
	return formatBillTicket(order, items, config, billTicketOpts{
		title:     printLabelsFor(config.Locale).TitleRemaining,
		total:     total,
		remaining: remaining,
		showQR:    true,
	})
}

// orderGrossTotal returns order.TotalAmount, falling back to the item line sum
// when the order total is 0 (legacy/race). Item prices are tax-included, so the
// line sum already IS the gross total — nothing is added on top.
func orderGrossTotal(order *Order, items []Item) int {
	if order.TotalAmount > 0 {
		return order.TotalAmount
	}
	total := 0
	for _, item := range items {
		total += item.UnitPrice * item.Quantity
		for _, t := range item.Toppings {
			total += t.UnitPrice * t.Quantity
		}
	}
	return total
}

// splitIdxSuffix returns " (i/N)" when the slip is one bill of a multi-way
// split, else "". Appended to the "HOA DON CHIA" title + the "Phan chia" line.
func splitIdxSuffix(slip *PaymentSlipInfo) string {
	if slip != nil && slip.SplitCount > 1 && slip.SlipIndex > 0 {
		return fmt.Sprintf(" (%d/%d)", slip.SlipIndex, slip.SplitCount)
	}
	return ""
}

// printSplitBanner renders the top "HOA DON CHIA" header block on a share
// receipt (centered within the paper width):
//
//	========================================
//	           HOA DON CHIA (1/2)
//	           Chia deu - 2 nguoi
//	           Nguoi 1                       (only when a label is set)
//	========================================
//
// No-op for a non-split slip (SplitMode empty + SplitCount <= 1).
func printSplitBanner(e *escpos.Encoder, w int, labels printLabels, slip *PaymentSlipInfo) {
	kind := slip.splitModeKind()
	if kind == "" {
		return
	}
	center := func(s string) string {
		dw := displayWidth(s)
		if dw >= w {
			return s
		}
		return spaces((w-dw)/2) + s
	}
	bar := strings.Repeat("=", w)
	e.Feed(1)
	e.Line(bar)
	e.Bold(true)
	e.Line(center(labels.SplitTitle + splitIdxSuffix(slip)))
	e.Bold(false)
	mode := labels.splitModeText(kind)
	if slip.SplitCount > 1 {
		mode = fmt.Sprintf("%s - %d %s", mode, slip.SplitCount, labels.SplitPeople)
	}
	e.Line(center(mode))
	if lbl := strings.TrimSpace(slip.Label); lbl != "" {
		e.Line(center(lbl))
	}
	e.Line(bar)
}

// billTicketOpts parameterises the shared bill-ticket body so the runner,
// paid, and remaining slips share one layout (header → items → totals → QR).
type billTicketOpts struct {
	title     string           // header right-side title
	total     int              // gross total (tax-included)
	remaining int              // "Con lai" value
	showQR    bool             // draw order.ID QR at the bottom
	slip      *PaymentSlipInfo // non-nil on paid tickets → print payment method / slip label
	paidShown int              // "Da thanh toan" amount on paid tickets
	// deltaBill: this slip lists only a DELTA of the order (the newly-fired
	// items), so the whole-order breakdown (Tam tinh / per-rate tax) wouldn't
	// match — suppress it and print just the delta Tong (opts.total), like a
	// split sub-bill but without the "Khach n/N" labels.
	deltaBill bool
	// redInvoice: the hoá đơn đỏ variant — always prints a customer line (the
	// entered name, or a blank underline to hand-write it).
	redInvoice bool
}

// formatBillTicket renders the common runner/paid/remaining layout.
func formatBillTicket(order *Order, items []Item, config PrintJobConfig, opts billTicketOpts) []byte {
	w := config.PaperWidth
	if w == 0 {
		w = 48
	}

	e := escpos.New()
	e.SetLeftMargin(config.leftMargin(w))
	e.Align(escpos.AlignLeft)

	labels := printLabelsFor(config.Locale)

	// ─── store name (left) + title (right) ───
	// If both fit on one line (with at least 1 space gap), print together.
	// Otherwise print on separate lines to avoid wrapping.
	storeName := config.StoreName
	if storeName == "" {
		storeName = "Store"
	}
	title := opts.title
	titleW := displayWidth(title)
	storeDispW := displayWidth(storeName)
	e.Bold(true)
	if storeDispW+1+titleW <= w {
		gap := w - storeDispW - titleW
		e.Line(storeName + spaces(gap) + title)
	} else {
		e.Line(storeName)
		e.Line(spaces(w-titleW) + title)
	}
	e.Bold(false)

	// ─── date/time (left) ───
	// The #orderCode suffix used to print at the right here; removed per request
	// (the code still appears in the "So HD" footer row below). suffix is kept
	// because that footer row consumes it.
	suffix := OrderCodeSuffix(order.OrderCode)
	dateStr := time.Now().Format("2006/01/02 15:04")
	e.Line(dateStr)

	// ─── split banner (share receipts): "HOA DON CHIA (i/N) / <mode> - N nguoi" ───
	printSplitBanner(e, w, labels, opts.slip)

	// ─── So HD + Ban ───
	e.Feed(1)
	tableStr := order.TableNumber
	if tableStr == "" {
		tableStr = "-"
	}
	footerRow := func(label, value string) {
		// DISPLAY width, not rune count — a Japanese label ("サービス料") is
		// fullwidth (2 cols/char), so rune count under-measures it and the row
		// overflows the paper and wraps. displayWidth keeps every locale on one
		// line.
		gap := w - displayWidth(label) - displayWidth(value)
		if gap < 1 {
			gap = 1
		}
		e.Line(label + spaces(gap) + value)
	}
	footerRow(labels.OrderNo, suffix)
	// Takeaway has no table — drop the "Ban"/卓 row entirely (matches the kitchen
	// ticket, TestKitchenTicket_TakeawayDropsTable). The takeaway customer header
	// (Khach hang / SDT / pickup) below identifies the order instead. Dine-in /
	// spot keep the table row.
	if order.OrderType != "takeaway" {
		e.Bold(true)
		footerRow(labels.Table, tableStr)
		e.Bold(false)
	}
	// Red-invoice customer line: the entered name, or a blank underline to
	// hand-write it. Normal slips only print it when a name was supplied.
	if opts.slip != nil {
		if cn := strings.TrimSpace(opts.slip.CustomerName); cn != "" {
			e.Line(labels.CustomerLabel + ": " + cn)
		} else if opts.redInvoice {
			e.Line(labels.CustomerLabel + ": " + strings.Repeat("_", 18))
		}
	}
	// Legacy split label ("Khach n/N"). Only for slips WITHOUT the split banner
	// (which already carries mode + i/N + label) — i.e. non-split slips, where
	// these conditions are all false anyway. Kept for backward compatibility.
	if opts.slip != nil && opts.slip.splitModeKind() == "" {
		label := strings.TrimSpace(opts.slip.Label)
		switch {
		case label != "" && opts.slip.SplitCount > 1 && opts.slip.SlipIndex > 0:
			footerRow(labels.Guest, fmt.Sprintf("%s (%d/%d)", label, opts.slip.SlipIndex, opts.slip.SplitCount))
		case label != "":
			footerRow(labels.Guest, label)
		case opts.slip.SplitCount > 1 && opts.slip.SlipIndex > 0:
			footerRow(labels.Guest, fmt.Sprintf("%d/%d", opts.slip.SlipIndex, opts.slip.SplitCount))
		}
	}
	// ─── takeaway customer (Khach hang / SDT) — who the order is for ───
	// Skipped on split sub-bills that already name the payer via slip.CustomerName
	// above, to avoid printing the customer twice.
	if opts.slip == nil || strings.TrimSpace(opts.slip.CustomerName) == "" {
		printCustomerHeader(e, order, labels)
	}

	// ─── order-level note (Ghi chu) ───
	if note := strings.TrimSpace(order.Note); note != "" {
		printNoteLines(e, w, 0, note, labels.NotePrefix)
	}

	// ─── separator + column header ───
	e.Feed(1)
	e.Line(dashedLine(w))
	e.Feed(1)
	colRight := labels.Price
	colLeft := labels.Item
	e.Line(padRight(colLeft, w-displayWidth(colRight)) + colRight)
	e.Feed(1)

	// ─── per-rate tax breakdown (plan-043 T4.1) ───
	// Built from the order's per-line tax snapshots (item.TaxRate). Drives both
	// the ※ reduced-rate marker on item names below AND the per-rate footer
	// blocks. Empty (no line stamped) → legacy single-line Thue fallback.
	taxSummary := buildReceiptTaxSummary(order, items, config.step())

	// A split SUB-bill shows a monetary SHARE (opts.total) that doesn't correspond
	// to its item slice, so the whole per-rate インボイス breakdown stays suppressed
	// (Q13) — a ※ marker whose rate group isn't derivable is confusing.
	//
	// A per-fire DELTA slip (hold/serving) is different: opts.total IS the sum of
	// the delta items, so buildReceiptTaxSummary(order, deltaItems) matches it to
	// the yen. The delta slip DOES print the tax breakdown (computed on the fired
	// items) — only the whole-order subtotal/service rows stay suppressed below
	// (they'd dwarf the delta). suppressOrderRows gates those; showTaxBreakdown
	// gates the per-rate blocks + ※ markers/legend.
	isSplitSubBill := opts.slip != nil && opts.slip.SplitCount > 1 && opts.slip.BillTotal > 0
	showTaxBreakdown := !isSplitSubBill
	suppressOrderRows := isSplitSubBill || opts.deltaBill

	// ─── items ───
	for _, item := range items {
		reduced := showTaxBreakdown && taxSummary.HasReduced && isReducedLine(item, taxSummary.blockMaxRate())
		printRunnerItem(e, w, item, config.cur(), reduced, config.Locale, labels.NotePrefix)
	}

	// ─── separator + footer totals ───
	e.Feed(1)
	e.Line(dashedLine(w))
	e.Feed(1)

	// Additive breakdown matching the order/screen (subtotal + service + tax =
	// total). Each component prints only when present, so a legacy tax-included
	// order (subtotal/service = 0) still renders a clean Thue + Tong pair, while
	// a normalized order shows Tam tinh / Phi phuc vu / Thue / Tong exactly as
	// the kiosk bill — no more mismatch between print and screen.
	//
	// For a split SUB-bill (opts.total is this person's share, not the order
	// total) the order-level subtotal/tax/service don't correspond to the
	// headline, so we skip the breakdown and print only the sub-bill Tong.
	//
	// plan-043 Q13 (default): the per-rate インボイス breakdown (8%対象 / 10%対象
	// blocks + ※ legend) is suppressed on a split sub-bill (its share isn't
	// derivable from the whole-order snapshots) via showTaxBreakdown; a delta slip
	// keeps its breakdown but drops these whole-order subtotal/service rows via
	// suppressOrderRows, both computed above the items loop so the ※ markers stay
	// in step.
	if !suppressOrderRows {
		if order.Subtotal > 0 {
			footerRow(labels.Subtotal, config.cur()+formatPrice(order.Subtotal))
		}
		if order.ServiceCharge > 0 {
			footerRow(labels.ServiceCharge, config.cur()+formatPrice(order.ServiceCharge))
		}
		// The per-rate tax breakdown is printed BELOW the grand total + indented
		// (issue #1042 layout) — see after the total line.
	}

	// Total line(s). For a split slip we show the share visualization the
	// operator confirmed: whole-order "Tong don" + this payer's "Phan chia (i/N)".
	//   - by_items: this payer's món total IS their share → headline "Phan chia",
	//     then "Tong don" (whole order) as context.
	//   - equal / by_amount: full món shown → headline "Tong don" (order gross),
	//     then "Phan chia (i/N)" = this payer's amount.
	switch opts.slip.splitModeKind() {
	case "by_items":
		e.Bold(true)
		footerRow(labels.SplitShare+splitIdxSuffix(opts.slip), config.cur()+formatPrice(opts.total))
		e.Bold(false)
		footerRow(labels.OrderTotal, config.cur()+formatPrice(opts.slip.OrderGrossTotal))
	case "equal", "by_amount":
		e.Bold(true)
		footerRow(labels.OrderTotal, config.cur()+formatPrice(opts.total))
		e.Bold(false)
		e.Bold(true)
		footerRow(labels.SplitShare+splitIdxSuffix(opts.slip), config.cur()+formatPrice(opts.slip.AmountPaid))
		e.Bold(false)
	default:
		e.Bold(true)
		footerRow(labels.Total, config.cur()+formatPrice(opts.total))
		e.Bold(false)
	}

	// ─── per-rate tax breakdown — BELOW the grand total, INDENTED (issue #1042) ───
	// The tax is 内税 (already inside the total), so it reads as an informational
	// split UNDER the total, followed by the ※ legend + 登録番号. Shared with the
	// kitchen ticket + per-fire delta slip (printTaxBreakdown). The legacy fallback
	// extracts from order.TaxAmount on a full bill, but from opts.total on a delta
	// slip whose total is only the fired items, not the whole order.
	if showTaxBreakdown {
		legacyOrderTax := int(math.Round(order.TaxAmount))
		if opts.deltaBill {
			legacyOrderTax = 0
		}
		printTaxBreakdown(e, w, order, config, taxSummary, opts.total, legacyOrderTax)
	}

	// Paid-ticket extras: amount settled on this slip + payment method.
	if opts.slip != nil {
		e.Bold(true)
		footerRow(labels.PaidAmount, config.cur()+formatPrice(opts.paidShown))
		e.Bold(false)
		if m := strings.TrimSpace(opts.slip.PaymentMethod); m != "" {
			footerRow(labels.PaymentMethod, m)
		}
		// Cash tendered + change — only when the payment recorded a tendered
		// amount (cash). Non-cash methods leave these zero and print nothing.
		if opts.slip.Tendered > 0 {
			footerRow(labels.Tendered, config.cur()+formatPrice(opts.slip.Tendered))
			footerRow(labels.Change, config.cur()+formatPrice(opts.slip.Change))
		}
	}

	// "Con lai" only when there's actually something left (or an over-payment
	// excess) — a clean full settle doesn't print a ¥0 line.
	if opts.remaining > 0 {
		e.Bold(true)
		footerRow(labels.Remaining, config.cur()+formatPrice(opts.remaining))
		e.Bold(false)
	}

	// ─── QR code centered ───
	if opts.showQR {
		e.Feed(2)
		e.Align(escpos.AlignCenter)
		e.QRCode(order.ID, 7)
		e.Feed(2)
		e.Align(escpos.AlignLeft)
	} else {
		e.Feed(2)
	}

	e.FullCut()
	return e.Bytes()
}

// printTaxBreakdown renders the per-rate 内税 breakdown (plan-043) indented below
// a total line, followed by the ※ reduced-rate legend and the seller 登録番号 —
// the shared tax footer used by the customer bill (formatBillTicket), the kitchen
// ticket (FormatKitchenTicket), and the per-fire hold/delta slip. Each caller
// passes a taxSummary built from the SAME items it displays so the breakdown
// always agrees with the printed total. When no line carries a rate snapshot
// (legacy order) it falls back to a single tax-included 税/Thue line: legacyOrderTax
// lets the full bill pass the authoritative order.TaxAmount, while partial slips
// (kitchen batch / delta) pass 0 so the tax is extracted from their own `total`.
func printTaxBreakdown(e *escpos.Encoder, w int, order *Order, config PrintJobConfig, taxSummary receiptTaxSummary, total, legacyOrderTax int) {
	const taxIndent = 3
	tl := taxLabelsFor(config.Locale)
	if len(taxSummary.Blocks) > 0 {
		for _, block := range taxSummary.Blocks {
			e.Line(spaces(taxIndent) + formatRateBlockLine(w-taxIndent, tl, config.cur(), block))
		}
	} else {
		// Legacy fallback: a single tax-included tax line, indented too.
		if taxAmount := taxBreakdown(total, legacyOrderTax, config.TaxRate); taxAmount > 0 {
			label := printLabelsFor(config.Locale).Tax
			value := config.cur() + formatPrice(taxAmount)
			gap := (w - taxIndent) - displayWidth(label) - displayWidth(value)
			if gap < 1 {
				gap = 1
			}
			e.Line(spaces(taxIndent) + label + spaces(gap) + value)
		}
	}

	// ─── ※ legend + T+13 registration number (plan-043 T4.1) ───
	// The ※ legend prints when a reduced-rate line was marked; 登録番号 prints only
	// when the shop carries a non-empty seller registration number (Q5 — usually null).
	if taxSummary.HasReduced {
		e.Line(tl.ReducedLegend)
	}
	if reg := strings.TrimSpace(config.SellerRegistrationNumber); reg != "" {
		// Flush-left "登録番号: {value}" per the reference mockup — not a
		// right-aligned amount row.
		e.Line(tl.RegistrationNumber + ": " + reg)
	}
}

// printRunnerItem prints one item row for the runner/hold ticket:
// - item name (+ ※ when this is a reduced-rate 軽減税率 line, plan-043 T4.1) + price
// - toppings: "   —— Name        ¥price"  (em-dash style, from item.Toppings)
// - free note: "   Ghi chu: ..."
//
// reduced marks the line as 軽減税率対象 → a ※ is appended to the name so the
// receipt satisfies インボイス §1.3.2 (8% items must be marked). locale is
// unused for the marker (※ is a locale-neutral glyph) but threaded for parity
// with the rest of the localized print path.
func printRunnerItem(e *escpos.Encoder, w int, item Item, cur string, reduced bool, locale, notePrefix string) {
	if item.Quantity <= 0 || strings.TrimSpace(item.MenuItemName) == "" {
		return
	}

	name := stripVariantSuffix(item.MenuItemName)
	if reduced {
		name = name + " " + taxLabelsFor(locale).ReducedMarker
	}
	slStr := fmt.Sprintf("%d", item.Quantity)
	priceStr := cur + formatPrice(item.UnitPrice*item.Quantity)
	indentW := utf8.RuneCountInString(slStr) + 2

	printWrappedName(e, w, slStr, name, priceStr)
	printVariantLine(e, w, indentW, item)
	printToppingLines(e, w, indentW, item, cur, notePrefix)
}

// itemVariant returns the SKU variant label (e.g. "Large") for an item.
// Prefers the structured sku_variant_name column; falls back to the " · "
// suffix in the display name (stripVariantSuffix removes that suffix from the
// printed item name, so without this the variant would be lost entirely).
func itemVariant(item Item) string {
	if v := strings.TrimSpace(item.SkuVariantName); v != "" {
		return v
	}
	if dot := strings.Index(item.MenuItemName, " \xc2\xb7 "); dot != -1 {
		return strings.TrimSpace(item.MenuItemName[dot+len(" \xc2\xb7 "):])
	}
	return ""
}

// printVariantLine renders the SKU variant as an indented line under the item
// name, matching the topping line style ("-- Large") but without a price —
// mirroring how the Handy note format surfaces the variant as its own line.
// Printed before toppings since the variant is part of the item's identity.
func printVariantLine(e *escpos.Encoder, w, indentW int, item Item) {
	v := itemVariant(item)
	if v == "" {
		return
	}
	e.Line(spaces(indentW) + "-- " + v)
}

// parsedTopping holds a topping line parsed from item.Note fallback.
type parsedTopping struct {
	name         string
	modifierType string // "add" | "remove"
	price        int
}

// parseNoteAsToppings parses handy-app note format into toppings + free note.
// Handy encodes toppings into note as: "+ Name ¥price", "- Name", "Name" (variant).
// Pure free-text lines (no +/- prefix and no ¥) are returned separately as freeNote.
func parseNoteAsToppings(note string) (toppings []parsedTopping, freeNote string) {
	var freeLines []string
	for _, raw := range strings.Split(note, "\n") {
		line := strings.TrimSpace(raw)
		if line == "" {
			continue
		}
		if strings.HasPrefix(line, "+ ") || strings.HasPrefix(line, "+") {
			name := strings.TrimPrefix(strings.TrimPrefix(line, "+ "), "+")
			price := 0
			if i := strings.LastIndex(name, "¥"); i >= 0 {
				fmt.Sscanf(strings.ReplaceAll(name[i+len("¥"):], ",", ""), "%d", &price)
				name = strings.TrimSpace(name[:i])
			}
			toppings = append(toppings, parsedTopping{name: name, modifierType: "add", price: price})
		} else if strings.HasPrefix(line, "- ") || strings.HasPrefix(line, "-") {
			name := strings.TrimSpace(strings.TrimPrefix(strings.TrimPrefix(line, "- "), "-"))
			toppings = append(toppings, parsedTopping{name: name, modifierType: "remove", price: 0})
		} else {
			// No +/- prefix — a genuine free-text customer note (e.g.
			// "it cay, khong hanh"). Handy encodes toppings with explicit
			// +/- markers, so anything else is the note and must surface as
			// "Ghi chu: ..." rather than being hidden as a "-- <note>" topping.
			freeLines = append(freeLines, line)
		}
	}
	freeNote = strings.Join(freeLines, "\n")
	return
}

// printToppingLines renders toppings + free note for an item.
// Uses item.Toppings (structured) when available; falls back to parsing item.Note.
func printToppingLines(e *escpos.Encoder, w, indentW int, item Item, cur, notePrefix string) {
	prefix := "-- "
	prefixW := displayWidth(prefix)

	if len(item.Toppings) > 0 {
		// Structured toppings from DB
		for _, t := range item.Toppings {
			tName := collapseMirroredName(strings.TrimSpace(t.Name))
			if tName == "" {
				continue
			}
			if t.Quantity > 1 && t.ModifierType != "remove" {
				tName = fmt.Sprintf("%d x %s", t.Quantity, tName)
			}
			tPrice := ""
			if t.UnitPrice != 0 {
				if t.ModifierType == "remove" {
					tPrice = "-¥" + formatPrice(t.UnitPrice*t.Quantity)
				} else {
					tPrice = cur + formatPrice(t.UnitPrice*t.Quantity)
				}
			}
			tPriceW := displayWidth(tPrice)
			tNameW := w - indentW - prefixW - tPriceW
			if tNameW < 1 {
				tNameW = 1
			}
			e.Line(spaces(indentW) + prefix + padRight(tName, tNameW) + tPrice)
		}
		// Free note alongside structured toppings
		note := strings.TrimSpace(item.Note)
		if note != "" {
			printNoteLines(e, w, indentW, note, notePrefix)
		}
		return
	}

	// Fallback: parse item.Note (handy app encodes toppings into note)
	note := strings.TrimSpace(item.Note)
	if note == "" {
		return
	}
	toppings, freeNote := parseNoteAsToppings(note)
	for _, t := range toppings {
		tPrice := ""
		if t.price != 0 {
			if t.modifierType == "remove" {
				tPrice = "-¥" + formatPrice(t.price)
			} else {
				tPrice = cur + formatPrice(t.price)
			}
		}
		tPriceW := displayWidth(tPrice)
		tNameW := w - indentW - prefixW - tPriceW
		if tNameW < 1 {
			tNameW = 1
		}
		e.Line(spaces(indentW) + prefix + padRight(t.name, tNameW) + tPrice)
	}
	if freeNote != "" {
		printNoteLines(e, w, indentW, freeNote, notePrefix)
	}
}

// printNoteLines renders "   Ghi chu: ..." lines, word-wrapping long notes so a
// note is never hard-cut mid-word by the printer and every wrapped line stays
// inside the content width (so the left/right padding is preserved). Wrapped
// continuation lines are indented to align under the note text, past the
// "Ghi chu: " prefix.
func printNoteLines(e *escpos.Encoder, w, indentW int, note, notePrefix string) {
	notePrefixW := displayWidth(notePrefix)
	textW := w - indentW - notePrefixW
	if textW < 1 {
		textW = 1
	}
	contIndent := indentW + notePrefixW
	for _, para := range strings.Split(note, "\n") {
		para = strings.TrimSpace(para)
		if para == "" {
			continue
		}
		// Wrap on word boundaries at the note-text width; lines[0] follows the
		// "Ghi chu: " label, the rest align under it.
		lines := wrapNameLines(para, textW, textW)
		if len(lines) == 0 {
			continue
		}
		e.Line(spaces(indentW) + notePrefix + lines[0])
		for _, ln := range lines[1:] {
			e.Line(spaces(contIndent) + ln)
		}
	}
}

// stripVariantSuffix removes " · VariantName" suffix added by the frontend.
func stripVariantSuffix(name string) string {
	if dot := strings.Index(name, " \xc2\xb7 "); dot != -1 {
		return name[:dot]
	}
	return name
}

// collapseMirroredName collapses a name that is nothing but the same label
// repeated across the " · " separator down to a single label ("Fish sauce ·
// Fish sauce" → "Fish sauce"). Orders created before workstation-app#101 stored
// topping names doubled this way (a default-SKU topping whose SKU name equals
// its product name); the print path renders the stored value verbatim, so
// without this a printed bill showed the topping twice and wrapped it onto a
// second, prefix-less line. A genuine "Product · Variant" topping (distinct
// parts) is left untouched. Mirrors pos-web's collapseMirroredToppingName.
func collapseMirroredName(name string) string {
	const sep = " \xc2\xb7 " // " · " (U+00B7) — matches resolveToppingSnapshot's format
	if !strings.Contains(name, sep) {
		return name
	}
	parts := strings.Split(name, sep)
	first := strings.TrimSpace(parts[0])
	for _, p := range parts {
		if !strings.EqualFold(strings.TrimSpace(p), first) {
			return name
		}
	}
	return first
}

// runeDisplayWidth returns the printer column width of a rune.
// CJK characters (fullwidth) occupy 2 columns; combining marks occupy 0 (they
// stack on the preceding glyph — e.g. Vietnamese "á" in NFD form is a base
// letter + a combining accent); everything else occupies 1.
func runeDisplayWidth(r rune) int {
	// Nonspacing (Mn) / enclosing (Me) combining marks add no columns. Without
	// this, a decomposed (NFD) Vietnamese name like "Chi nhánh Hà Nội" measures
	// 20 cols instead of 16, wrongly bumping the branch name onto its own line.
	if unicode.Is(unicode.Mn, r) || unicode.Is(unicode.Me, r) {
		return 0
	}
	if r >= 0x1100 && (r <= 0x115F || // Hangul Jamo
		r == 0x2329 || r == 0x232A ||
		(r >= 0x2E80 && r <= 0x303E) || // CJK Radicals / Kangxi
		(r >= 0x3040 && r <= 0x33FF) || // Hiragana / Katakana / CJK compat
		(r >= 0x3400 && r <= 0x4DBF) || // CJK Ext-A
		(r >= 0x4E00 && r <= 0x9FFF) || // CJK Unified
		(r >= 0xA000 && r <= 0xA4CF) || // Yi
		(r >= 0xAC00 && r <= 0xD7AF) || // Hangul Syllables
		(r >= 0xF900 && r <= 0xFAFF) || // CJK compat ideographs
		(r >= 0xFE10 && r <= 0xFE19) ||
		(r >= 0xFE30 && r <= 0xFE6F) || // CJK compat forms
		(r >= 0xFF00 && r <= 0xFF60) || // Fullwidth forms
		(r >= 0xFFE0 && r <= 0xFFE6) ||
		(r >= 0x1F300 && r <= 0x1F64F) || // Emoji
		(r >= 0x20000 && r <= 0x2FFFD) ||
		(r >= 0x30000 && r <= 0x3FFFD)) {
		return 2
	}
	return 1
}

// displayWidth returns total printer column width of a string.
func displayWidth(s string) int {
	w := 0
	for _, r := range s {
		w += runeDisplayWidth(r)
	}
	return w
}

// wrapNameLines lays a menu-item name out across printer lines using DISPLAY
// width (CJK/emoji = 2 cols). Line 0 is firstW columns wide (the price sits to
// its right); continuation lines are contW wide. Rules:
//   - break on spaces — a word is never split mid-word when it fits a line;
//   - a single token wider than a whole line is char-split (e.g. a spaceless
//     Japanese name);
//   - widow control: a final line that would be a lone 1-column character has
//     the previous line's last rune pulled down so it never prints alone.
func wrapNameLines(name string, firstW, contW int) []string {
	if firstW < 1 {
		firstW = 1
	}
	if contW < 1 {
		contW = 1
	}

	var lines []string
	var cur []rune
	curW := 0
	// limit is the column budget of the line currently being built: the narrow
	// firstW for line 0, contW for every continuation line.
	limit := func() int {
		if len(lines) == 0 {
			return firstW
		}
		return contW
	}
	flush := func() {
		lines = append(lines, string(cur))
		cur = nil
		curW = 0
	}
	// charSplit appends an over-long token rune-by-rune, wrapping as it fills
	// each line's budget.
	charSplit := func(word string) {
		for _, r := range word {
			rw := runeDisplayWidth(r)
			if curW+rw > limit() {
				flush()
			}
			cur = append(cur, r)
			curW += rw
		}
	}

	for _, word := range strings.Fields(name) {
		ww := displayWidth(word)
		if curW == 0 {
			if ww <= limit() {
				cur = []rune(word)
				curW = ww
			} else {
				charSplit(word)
			}
			continue
		}
		if curW+1+ww <= limit() {
			cur = append(cur, ' ')
			cur = append(cur, []rune(word)...)
			curW += 1 + ww
			continue
		}
		// Doesn't fit the current line — start a fresh one and retry the word.
		flush()
		if ww <= limit() {
			cur = []rune(word)
			curW = ww
		} else {
			charSplit(word)
		}
	}
	flush()
	if len(lines) == 0 {
		lines = []string{""}
	}

	// Widow control: never leave a final 1-column orphan (the classic "name
	// drops one character to its own line"). Pull the previous line's last rune
	// down so the tail line carries at least two columns.
	if n := len(lines); n >= 2 && displayWidth(lines[n-1]) < 2 {
		prev := []rune(lines[n-2])
		if len(prev) > 1 {
			moved := prev[len(prev)-1]
			lines[n-2] = string(prev[:len(prev)-1])
			lines[n-1] = string(moved) + lines[n-1]
		}
	}
	return lines
}

// printWrappedName prints "slStr  name....price", word-wrapping long names and
// right-aligning the price on the first line. Continuation lines are indented
// under the name column.
func printWrappedName(e *escpos.Encoder, w int, slStr, name, priceStr string) {
	indentW := utf8.RuneCountInString(slStr) + 2
	priceW := displayWidth(priceStr)
	nameColW := w - indentW - priceW
	if nameColW < 1 {
		nameColW = 1
	}
	contW := w - indentW
	if contW < 1 {
		contW = 1
	}

	lines := wrapNameLines(name, nameColW, contW)
	// First line: name padded to the name column so the price is right-aligned.
	e.Line(slStr + "  " + padRight(lines[0], nameColW) + priceStr)
	for _, ln := range lines[1:] {
		e.Line(spaces(indentW) + ln)
	}
}

// CurrencySymbol maps an ISO 4217 code to the symbol printed on receipts /
// kitchen tickets, so a shop prints in the currency it prices in (e.g. a VND
// shop prints ₫ instead of a hard-coded ¥). Defaults to ¥ for JPY / unknown.
func CurrencySymbol(code string) string {
	switch strings.ToUpper(strings.TrimSpace(code)) {
	case "VND":
		return "₫"
	case "USD", "AUD", "CAD", "SGD", "HKD", "NZD":
		return "$"
	case "EUR":
		return "€"
	case "GBP":
		return "£"
	case "KRW":
		return "₩"
	case "THB":
		return "฿"
	case "PHP":
		return "₱"
	case "CNY", "JPY":
		return "¥"
	default:
		return "¥"
	}
}

func formatPrice(amount int) string {
	s := fmt.Sprintf("%d", amount)
	if len(s) <= 3 {
		return s
	}
	var result []byte
	for i, c := range s {
		if i > 0 && (len(s)-i)%3 == 0 {
			result = append(result, ',')
		}
		result = append(result, byte(c))
	}
	return string(result)
}

func padRight(s string, width int) string {
	dw := displayWidth(s)
	if dw >= width {
		return s
	}
	return s + spaces(width-dw)
}

func spaces(n int) string {
	if n <= 0 {
		return ""
	}
	s := make([]byte, n)
	for i := range s {
		s[i] = ' '
	}
	return string(s)
}

// dashedLine returns a "- - - - - -" separator matching the HTML design.
func dashedLine(w int) string {
	// "- " repeated, trimmed to w runes
	unit := "- "
	var b strings.Builder
	for b.Len() < w*2 {
		b.WriteString(unit)
	}
	runes := []rune(b.String())
	if len(runes) > w {
		runes = runes[:w]
	}
	return string(runes)
}
