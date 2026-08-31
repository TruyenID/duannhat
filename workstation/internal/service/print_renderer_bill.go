package service

import (
	"fmt"
	"math"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// plan-053 M3 (#1171) — block emitters for the ORDER-BACKED slips.
//
// Two families live here:
//
//	bill     receipt · runner · delta_qr · remaining · red_invoice
//	         (the five slips formatBillTicket renders today)
//	kitchen  the kitchen ticket
//
// Every emitter below is a transcription of one segment of the corresponding
// hard-coded formatter. Keeping the transcription line-by-line — including the
// feeds and the bold toggles, which are BYTES on the wire — is what lets
// print_renderer_golden_test.go assert byte equality instead of "looks right".

// billPlan builds the emitter table shared by the five bill-family kinds.
func billPlan() printKindPlan {
	return printKindPlan{
		defaultWidth: 48,
		prologue: func(c *printRenderCtx) {
			c.e.SetLeftMargin(c.cfg.leftMargin(c.w))
			c.e.Align(escpos.AlignLeft)
			c.e.Feed(slipTopPadding)
			c.prepareBillTax()
		},
		epilogue: func(c *printRenderCtx) {
			// The QR block already leaves two blank lines behind it. Without a
			// QR the slip still needs that tail margin before the cut, exactly
			// as formatBillTicket's `else` branch does.
			if !c.def.has("qr_block") {
				c.e.Feed(2)
			}
			c.finish()
		},
		emitters: map[string]blockEmitter{
			"logo":            emitLogo,
			"store_info":      emitBillHeader,
			"title":           emitBillHeader,
			"issued_at":       emitBillIssuedAt,
			"split_banner":    emitBillSplitBanner,
			"order_meta":      emitBillOrderMeta,
			"customer_header": emitBillCustomerHeader,
			"order_note":      emitBillOrderNote,
			"column_header":   emitOrderColumnHeader,
			"items":           emitBillItems,
			"subtotal":        emitBillSubtotal,
			// #2071 — per-rate discount rows straight from the
			// `order_conditions` ledger. Only `receipt` DECLARES the block in
			// the catalog, but the emitter lives on the SHARED bill plan (same
			// arrangement) so the contract parity gate sees
			// one block set on both sides.
			"discounts":           emitBillDiscounts,
			"service_charge":      emitBillServiceCharge,
			"grand_total":         emitBillGrandTotal,
			"tax_breakdown":       emitOrderTaxBreakdown,
			"tax_legend":          emitOrderTaxLegend,
			"registration_number": emitOrderRegistrationNumber,
			"payments":            emitBillPayments,
			"change_due":          emitBillChangeDue,
			"remaining":           emitBillRemaining,
			// plan-052 P-10b (#1166) — the bill family DECLARED this locked
			// block from day one but had no emitter for it, so a reprinted
			// receipt or red invoice came out looking exactly like the
			// original. That gap is what the removed 422 was standing in for.
			"reprint_marker": emitDocReprintMarker,
			// #2062 — only `red_invoice` declares this block in the catalog, but
			// the emitter lives on the SHARED bill plan, so it must be named here
			// (and in BillKindPlans::BLOCKS on the PHP side) or the contract
			// parity gate goes red.
			"qr_block":    emitBillQR,
			"header_text": emitAuthoredText,
			"footer_text": emitAuthoredText,
			"greeting":    emitAuthoredText,
		},
	}
}

func init() {
	// `kitchen` is in this list, not beside it: the kitchen ticket and the
	// hall/runner slip render through the SAME plan, and the only difference
	// between the two sheets is the QR — which the definition turns on for the
	// hall and off for the kitchen. What still differs is the DATA (a fired
	// batch, not the whole order), and data is the caller's business.
	for _, kind := range []string{"receipt", "runner", "delta_qr", "remaining", "red_invoice", "kitchen"} {
		registerPrintKind(kind, billPlan())
	}
}

// prepareBillTax reproduces the three derived flags formatBillTicket computes
// ABOVE its item loop, so the ※ markers on the lines and the per-rate blocks in
// the footer are always the same summary (a split sub-bill shows a monetary
// share that its item slice cannot explain, so its per-rate breakdown + ※ stay
// suppressed — plan-043 Q13, still open in #2064: they may only return once the
// share carries an allocated per-rate snapshot whose sums match the whole bill
// to the yen. The seller 登録番号 is deliberately NOT behind this flag anymore —
// see emitOrderRegistrationNumber, #2064).
func (c *printRenderCtx) prepareBillTax() {
	d := c.data
	if d.Order == nil {
		return
	}
	// #2170 — the SAME source choice the legacy formatters make: ledger-first
	// for whole-order kinds, batch recompute for kitchen/delta. TR-40 compares
	// the two renderers byte for byte, so the choice must live in one place.
	c.taxSummary = receiptTaxSummaryForKind(c.data.Kind, d.DeltaBill, d.Order, d.Items, c.cfg.step())
	isSplitSubBill := d.Slip != nil && d.Slip.SplitCount > 1 && d.Slip.BillTotal > 0
	c.showTaxBreakdown = !isSplitSubBill
	c.suppressOrderRows = isSplitSubBill || d.DeltaBill
}

// billTitle resolves the header title. delta_qr is the one kind whose title is
// data-dependent: a takeaway order has no waiter round, so the slip identifies
// a pickup instead of "newly-added items". That switch lives in the renderer
// (the definition cannot branch — principle #1) and matches FormatDeltaQRTicket.
func (c *printRenderCtx) billTitle() string {
	b := c.def.block("title")
	if !b.isEnabled() {
		return ""
	}
	if c.data.Kind == "delta_qr" && c.data.Order != nil && c.data.Order.OrderType == "takeaway" {
		return strings.ToUpper(c.labels.Takeaway)
	}
	return c.def.text(b, c.locale, c.w < 42)
}

// emitBillHeader prints "store name … TITLE" on one line, or on two when they
// do not fit. Registered for BOTH `store_info` and `title` because the two
// blocks share a physical line; whichever comes first in the definition draws
// it, the other is then a no-op.
func emitBillHeader(c *printRenderCtx, _ *PrintTemplateBlock) {
	// TRONG guard: emitter này đăng ký cho CẢ `store_info` lẫn `title`, nên đặt
	// ngoài sẽ in các dòng danh tính hai lần.
	if c.headerDrawn {
		return
	}
	c.headerDrawn = true

	emitStoreAbove(c)

	storeName := ""
	if c.def.has("store_info") {
		storeName = c.cfg.StoreName
		if storeName == "" {
			storeName = "Store"
		}
	}
	title := c.billTitle()

	c.e.Bold(true)
	switch {
	case storeName != "" && title != "":
		titleW := displayWidth(title)
		storeDispW := displayWidth(storeName)
		if storeDispW+1+titleW <= c.w {
			c.e.Line(storeName + spaces(c.w-storeDispW-titleW) + title)
		} else {
			c.e.Line(storeName)
			c.e.Line(spaces(c.w-titleW) + title)
		}
	case storeName != "":
		c.e.Line(storeName)
	case title != "":
		c.e.Line(spaces(c.w-displayWidth(title)) + title)
	}
	c.e.Bold(false)

	emitStoreBelow(c)
}

// emitBillIssuedAt prints the transaction date ALONE. The order code that used
// to share this line is gone on every kind and every status (chủ dự án
// 2026-08-17) — `order_meta` prints 伝票番号 at ×2 a few lines below, and one
// fact stated twice on one sheet is one fact too many. Mirrors formatBillTicket.
func emitBillIssuedAt(c *printRenderCtx, _ *PrintTemplateBlock) {
	printIssuedAtRow(c.e, c.w, c.data.now().Format("2006/01/02 15:04"), "")
}

func emitBillSplitBanner(c *printRenderCtx, _ *PrintTemplateBlock) {
	printSplitBanner(c.e, c.w, c.labels, c.data.Slip)
}

// emitBillOrderMeta prints the 伝票/卓 rows. The leading Feed(1) belongs to this
// block because formatBillTicket emits it there — a takeaway order still gets
// the blank line even though it prints no table row.
func emitBillOrderMeta(c *printRenderCtx, b *PrintTemplateBlock) {
	order := c.data.Order
	if order == nil {
		return
	}
	c.e.Feed(1)
	fields := b.Fields
	if len(fields) == 0 {
		fields = orderMetaFieldsFor(c.data.Kind)
	}
	printOrderMetaFields(c.e, c.w, c.data.Kind, fields,
		c.labels, order, OrderCodeSuffix(order.OrderCode), c.data.TicketNo)
}

// emitBillCustomerHeader prints, in formatBillTicket's order: the named payer
// (or the red invoice's hand-write underline), the legacy split label, and the
// takeaway customer block.
func emitBillCustomerHeader(c *printRenderCtx, _ *PrintTemplateBlock) {
	d := c.data
	if d.Order == nil {
		return
	}
	if d.Slip != nil {
		if cn := strings.TrimSpace(d.Slip.CustomerName); cn != "" {
			c.e.Line(c.labels.CustomerLabel + ": " + cn)
		} else if d.Kind == "red_invoice" {
			c.e.Line(c.labels.CustomerLabel + ": " + strings.Repeat("_", 18))
		}
	}
	// Legacy split label ("Khach n/N") — only for slips WITHOUT the split
	// banner, which already carries mode + i/N + label.
	if d.Slip != nil && d.Slip.splitModeKind() == "" {
		label := strings.TrimSpace(d.Slip.Label)
		switch {
		case label != "" && d.Slip.SplitCount > 1 && d.Slip.SlipIndex > 0:
			c.row(c.labels.Guest, fmt.Sprintf("%s (%d/%d)", label, d.Slip.SlipIndex, d.Slip.SplitCount))
		case label != "":
			c.row(c.labels.Guest, label)
		case d.Slip.SplitCount > 1 && d.Slip.SlipIndex > 0:
			c.row(c.labels.Guest, fmt.Sprintf("%d/%d", d.Slip.SlipIndex, d.Slip.SplitCount))
		}
	}
	if d.Slip == nil || strings.TrimSpace(d.Slip.CustomerName) == "" {
		printCustomerHeader(c.e, d.Order, c.labels, c.cfg.slipLocation())
	}
}

func emitBillOrderNote(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.data.Order == nil {
		return
	}
	if note := strings.TrimSpace(c.data.Order.Note); note != "" {
		printNoteLines(c.e, c.w, 0, note, c.labels.NotePrefix)
	}
}

// emitOrderColumnHeader draws the separator band + the two-column item header.
// Shared by the bill family, the kitchen ticket and the debt slip, which all
// frame it identically.
func emitOrderColumnHeader(c *printRenderCtx, b *PrintTemplateBlock) {
	left, right := columnHeaderText(c.def.text(b, c.locale, c.w < 42))
	// Whitespace, not a rule — see printBillTicket's column header.
	c.e.Feed(1)
	c.e.Line(padRight(left, c.w-displayWidth(right)) + right)
	c.e.Feed(1)
}

// emitBillItems prints the item table and the separator band that closes it.
// The trailing band belongs to the block for the same reason the leading one
// belongs to `column_header`: the table owns its own frame, so a definition
// that hides the table does not leave a stray rule behind.
func emitBillItems(c *printRenderCtx, _ *PrintTemplateBlock) {
	priceColW := slipPriceWidth(c.data.Items, c.cfg.cur())
	for _, item := range c.data.Items {
		reduced := c.showTaxBreakdown && c.taxSummary.HasReduced && isReducedLine(item, c.taxSummary.blockMaxRate())
		printRunnerItem(c.e, c.w, priceColW, item, c.cfg.cur(), reduced, c.locale, c.labels.NotePrefix)
	}
	printFooterRule(c.e, c.w, c.data.Kind)
}

func emitBillSubtotal(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.suppressOrderRows || c.data.Order == nil || c.data.Order.Subtotal <= 0 {
		return
	}
	c.row(c.labels.Subtotal, c.money(c.data.Order.Subtotal))
}

// emitBillDiscounts prints ONE ROW PER LEDGER DISCOUNT ROW (#2071) — the
// per-rate split `order_conditions` already carries (#2031), read by the caller
// into Order.Discounts. The renderer computes NOTHING here: no summing, no
// re-allocation, no fallback to `order.DiscountAmount` (that column is the
// REQUESTED figure; the ledger holds the APPLIED one, and when they differ the
// ledger is the one that talks about money).
//
//	Giam gia (8%)                     -¥9
//	Giam gia (10%)                   -¥91
//
// The rate suffix rides every row that has a rate group, so a mixed 8%/10%
// order shows which side each share landed on — the whole point of #2071 over
// the legacy single total. A row with no rate group (order with no taxable
// line) prints the bare word. Suppressed on split sub-bills and delta slips for
// the same reason `subtotal` is: those sheets show a share/subset the
// whole-order ledger rows do not describe.
func emitBillDiscounts(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.suppressOrderRows || c.data.Order == nil {
		return
	}
	for _, d := range c.data.Order.Discounts {
		c.row(discountRowLabel(c.labels, d), discountRowValue(c.cfg.cur(), d))
	}
}

// discountRowLabel builds `Giam gia (10%)` — catalog word + the ledger row's
// rate group. Shared by the template emitter and the legacy formatter so the
// TR-40 gate compares one implementation with itself only on WHERE it is
// called, never on how the string is built.
func discountRowLabel(labels printLabels, d OrderDiscountLine) string {
	if d.Rate == nil {
		return labels.Discount
	}
	return fmt.Sprintf("%s (%s%%)", labels.Discount, formatRatePercent(*d.Rate))
}

// discountRowValue renders the ledger amount VERBATIM, sign included — a
// deduction row is negative in the ledger and prints `-¥91`. The sign is
// handled outside formatPrice because formatPrice's thousands-grouping walks
// digits only and would comma-split a leading minus.
func discountRowValue(currency string, d OrderDiscountLine) string {
	if d.Amount < 0 {
		return "-" + currency + formatPrice(-d.Amount)
	}
	return currency + formatPrice(d.Amount)
}

func emitBillServiceCharge(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.suppressOrderRows || c.data.Order == nil || c.data.Order.ServiceCharge <= 0 {
		return
	}
	c.row(c.labels.ServiceCharge, c.money(c.data.Order.ServiceCharge))
}

// emitBillGrandTotal renders the headline money row. On a split slip it renders
// the share visualisation the cashier confirmed — the same three cases
// formatBillTicket switches on.
func emitBillGrandTotal(c *printRenderCtx, _ *PrintTemplateBlock) {
	d := c.data
	switch d.Slip.splitModeKind() {
	case "by_items":
		c.e.Bold(true)
		c.row(c.labels.SplitShare+splitIdxSuffix(d.Slip), c.money(d.Total))
		c.e.Bold(false)
		c.row(c.labels.OrderTotal, c.money(d.Slip.OrderGrossTotal))
	case "even", "by_amount":
		c.e.Bold(true)
		c.row(c.labels.OrderTotal, c.money(d.Total))
		c.e.Bold(false)
		c.e.Bold(true)
		c.row(c.labels.SplitShare+splitIdxSuffix(d.Slip), c.money(d.Slip.AmountPaid))
		c.e.Bold(false)
	default:
		c.e.Bold(true)
		c.row(c.labels.Total, c.money(d.Total))
		c.e.Bold(false)
	}
}

// taxIndent is the 3-column indent the per-rate blocks sit at, below the grand
// total (issue #1042 layout): the tax is 内税, already inside the total, so it
// reads as an informational split UNDER it.
const printTaxIndent = 3

// emitOrderTaxBreakdown prints the per-rate 内税 blocks, or the single aggregate
// tax line when no order line carries a rate snapshot.
func emitOrderTaxBreakdown(c *printRenderCtx, _ *PrintTemplateBlock) {
	if !c.showTaxBreakdown {
		return
	}
	if len(c.taxSummary.Blocks) > 0 {
		w := c.w - printTaxIndent
		wrap := taxBlocksNeedWrap(c.w, w, c.tax, c.cfg.cur(), c.taxSummary.Blocks)
		for _, block := range c.taxSummary.Blocks {
			for _, line := range formatRateBlockLines(w, c.tax, c.cfg.cur(), block, wrap) {
				c.e.Line(spaces(printTaxIndent) + line)
			}
		}
		return
	}
	// The single aggregate row comes from the order's own tax_amount or not at all
	// (#2067) — the template NEVER derives it from the total, and never invents a
	// rate. Must stay in lockstep with printTaxBreakdown: TR-40 compares the two
	// byte for byte.
	taxAmount, ok := stampedTaxRow(c.data.Order, c.data.Kind, c.stampedOrderTax(), c.data.Total, c.taxIsAuthoritative())
	if !ok {
		return
	}
	label := c.labels.Tax
	value := c.money(taxAmount)
	gap := (c.w - printTaxIndent) - displayWidth(label) - displayWidth(value)
	if gap < 1 {
		gap = 1
	}
	c.e.Line(spaces(printTaxIndent) + label + spaces(gap) + value)
}

// taxIsAuthoritative reports whether this slip shows the WHOLE order, so that
// order.TaxAmount is the tax fact it should be carrying. A kitchen ticket or a
// per-fire delta shows a subset and never had one — the distinction only decides
// whether a missing row is worth logging (#2067); neither kind computes tax.
func (c *printRenderCtx) taxIsAuthoritative() bool {
	return !(c.data.Kind == "kitchen" || c.data.DeltaBill || c.data.Order == nil)
}

// stampedOrderTax mirrors formatBillTicket: a full bill passes the authoritative
// order.TaxAmount, while a partial slip (kitchen batch / delta) passes 0 — it
// shows a subset the whole-order figure does not describe.
func (c *printRenderCtx) stampedOrderTax() int {
	if !c.taxIsAuthoritative() {
		return 0
	}
	return int(math.Round(c.data.Order.TaxAmount))
}

func emitOrderTaxLegend(c *printRenderCtx, _ *PrintTemplateBlock) {
	if !c.showTaxBreakdown || !c.taxSummary.HasReduced {
		return
	}
	c.e.Line(c.tax.ReducedLegend)
}

// emitOrderRegistrationNumber prints 登録番号 flush-left. #1152: a seller with no
// number simply prints nothing — 免税事業者 is legal and must not be nagged.
//
// #2064 — NOT gated on showTaxBreakdown: the registration number is the
// SELLER's identity (適格簡易請求書 field ①) and does not depend on whether this
// slip is a split sub-bill. The old gate rode along with the per-rate
// suppression (Q13) by accident and stripped a mandatory field from every
// sub-bill handed to a guest. The per-rate block (④⑤) and the ※ marks (③)
// stay suppressed on sub-bills until the share carries an allocated per-rate
// snapshot — see #2064 for the rounding invariant that blocks them.
func emitOrderRegistrationNumber(c *printRenderCtx, _ *PrintTemplateBlock) {
	reg := strings.TrimSpace(c.cfg.SellerRegistrationNumber)
	if reg == "" {
		return
	}
	c.e.Line(c.tax.RegistrationNumber + ": " + reg)
}

func emitBillPayments(c *printRenderCtx, _ *PrintTemplateBlock) {
	slip := c.data.Slip
	if slip == nil {
		return
	}
	c.e.Bold(true)
	c.row(c.labels.PaidAmount, c.money(c.data.PaidShown))
	c.e.Bold(false)
	if m := strings.TrimSpace(slip.PaymentMethod); m != "" {
		c.row(c.labels.PaymentMethod, m)
	}
}

// emitBillChangeDue prints お預かり / お釣り — only when the payment actually
// recorded a tendered amount (cash). Non-cash methods print nothing.
func emitBillChangeDue(c *printRenderCtx, _ *PrintTemplateBlock) {
	slip := c.data.Slip
	if slip == nil || slip.Tendered <= 0 {
		return
	}
	c.row(c.labels.Tendered, c.money(slip.Tendered))
	c.row(c.labels.Change, c.money(slip.Change))
}

// emitBillRemaining prints 残額 only when something is actually left — a clean
// full settle does not print a ¥0 line.
func emitBillRemaining(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.data.Remaining <= 0 {
		return
	}
	printMoneyTableRow(c.e, c.w, c.labels, c.data.Order)
	c.e.Bold(true)
	c.row(c.labels.Remaining, c.money(c.data.Remaining))
	c.e.Bold(false)
}

func emitBillQR(c *printRenderCtx, b *PrintTemplateBlock) {
	if c.data.Order == nil {
		return
	}
	// #146/#1190 — the QR must carry the SAME JSON {orderId,orderCode,type}
	// payload formatBillTicket emits, or a kiosk scan breaks the moment T3.6
	// routes the LAN handler through this renderer. `order_code` stays an
	// explicit opt-in for shops that want the bare code on paper.
	// Settled hall sheet ⇒ no QR, but STILL the two-line tail margin: the legacy
	// formatter's `else` branch feeds it, and TR-40 compares the two byte for
	// byte. Returning bare here would shorten the paper by two lines on exactly
	// the sheets this rule touches, and the diff would read as a QR bug.
	if hallQRSuppressed(c.data.Kind, c.data.Order) {
		c.e.Feed(2)
		return
	}
	target := kioskQRPayload(c.data.Order)
	if b.Source == "order_code" {
		target = c.data.Order.OrderCode
	}
	c.e.Feed(2)
	c.e.Align(escpos.AlignCenter)
	c.e.QRCode(target, qrCellSize)
	c.e.Feed(2)
	c.e.Align(escpos.AlignLeft)
}
