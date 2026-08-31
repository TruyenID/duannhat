package service

import (
	"fmt"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// plan-053 M3 (#1171) — block emitters for the CASHIER-SHIFT slips:
//
//	shift_open    レジ開け  (opening cash count)
//	shift_report  精算 / 引き継ぎ (settlement · handover)
//	chain_report  精算（チェーン） — the same renderer, cumulative figures
//
// chain_report shares shift_report's plan exactly, because FormatChainReport is
// literally FormatShiftReport(info.asShiftReport()). Two layouts for "the same
// report, totalled" is how the two drift apart the first time either is touched.
//
// The 精算 slip is nine sections deep, and each is a real toggle a shop asks
// for ("stop printing the drawer check"). Giving each its own block id is what
// turns those toggles into template decisions instead of nine more shop
// settings — but it also means the Cloud block catalog needs the same ids; see
// the "catalog gaps" table in docs/guide/print-templates.md.

func init() {
	registerPrintKind("shift_open", shiftOpenPlan())
	registerPrintKind("shift_report", shiftReportPlan())
	registerPrintKind("chain_report", shiftReportPlan())
}

// ─── shift open ───────────────────────────────────────────────────────────

func shiftOpenPlan() printKindPlan {
	return printKindPlan{
		defaultWidth: 42,
		// No SetLeftMargin: FormatShiftOpenReport centres its own header and
		// prints full-width rows, so an extra indent would push the right-hand
		// amounts off the paper.
		prologue: func(c *printRenderCtx) {},
		epilogue: func(c *printRenderCtx) {
			c.e.Feed(1)
			c.finish()
		},
		emitters: map[string]blockEmitter{
			"logo":               emitLogo,
			"store_info":         emitShiftHeader,
			"title":              emitShiftHeader,
			"shift_meta":         emitShiftOpenMeta,
			"denomination_table": emitShiftOpenDenominations,
			"float_count":        emitShiftOpenTotal,
			"order_note":         emitShiftOpenNote,
			"shift_signature":    emitAuthoredText,
			"footer_text":        emitAuthoredText,
		},
	}
}

// shiftMoney formats a whole-unit amount with the report's currency suffix,
// carrying a leading "-" for negatives (過不足 can go short).
func (c *printRenderCtx) shiftMoney(n int) string {
	unit := moneyUnit(c.shiftCurrency())
	if n < 0 {
		return "-" + formatPrice(-n) + unit
	}
	return formatPrice(n) + unit
}

func (c *printRenderCtx) shiftCurrency() string {
	if c.data.Shift != nil {
		return c.data.Shift.Currency
	}
	if c.data.ShiftOpen != nil {
		return c.data.ShiftOpen.Currency
	}
	return ""
}

// shiftPadLeft right-aligns within a fixed DISPLAY-width column so the count
// (件/枚) and amount columns line up across every section.
func shiftPadLeft(s string, width int) string {
	if dw := displayWidth(s); dw < width {
		return spaces(width-dw) + s
	}
	return s
}

const shiftCountCol, shiftAmountCol = 6, 12

func (c *printRenderCtx) shiftSep() { c.e.Line(dashedLine(c.w)) }

// emitShiftHeader prints the centred store name + report title, both at double
// SIZE (2× height AND width) so the glyphs scale proportionally. A store name
// too wide to fit at 2× falls back to double height only, so a long name never
// runs off the paper edge.
func emitShiftHeader(c *printRenderCtx, _ *PrintTemplateBlock) {
	// TRONG guard: emitter này đăng ký cho cả `store_info` lẫn `title`.
	if c.headerDrawn {
		return
	}
	c.headerDrawn = true

	emitStoreAbove(c)

	c.e.Align(escpos.AlignCenter)
	c.e.Bold(true)
	if c.def.has("store_info") {
		if name := strings.TrimSpace(c.cfg.StoreName); name != "" {
			nameSize := escpos.DoubleSize
			if displayWidth(name)*2 > c.w {
				nameSize = escpos.DoubleHeight
			}
			c.e.Size(nameSize)
			c.e.Line(name)
		}
	}
	c.e.Size(escpos.DoubleSize)
	c.e.Line(c.shiftTitle())
	c.e.Size(escpos.NormalSize)
	c.e.Bold(false)

	emitStoreBelow(c)
}

// shiftTitle resolves the headline. 引き継ぎ and 精算（チェーン） are not brand
// choices — they state WHICH accounting act this slip records, so the renderer
// picks them from the data and the definition's text only supplies the ordinary
// 精算 / レジ開け case.
func (c *printRenderCtx) shiftTitle() string {
	if c.data.ShiftOpen != nil {
		if t := c.def.text(c.def.block("title"), c.locale, false); t != "" {
			return t
		}
		return openLabelsFor(c.locale).Title
	}
	L := labelsFor(c.locale)
	info := c.data.Shift
	if info != nil {
		if info.IsChain {
			return L.ChainTitle
		}
		if info.ReportKind == "handover" {
			return L.HandoverTitle
		}
	}
	if t := c.def.text(c.def.block("title"), c.locale, false); t != "" {
		return t
	}
	return L.Title
}

func emitShiftOpenMeta(c *printRenderCtx, b *PrintTemplateBlock) {
	info := c.data.ShiftOpen
	if info == nil {
		return
	}
	L := openLabelsFor(c.locale)
	c.e.Align(escpos.AlignLeft)

	fields := b.Fields
	if len(fields) == 0 {
		fields = []string{"device_name", "cashier_name", "opened_at"}
	}
	for _, f := range fields {
		switch f {
		// #2188 — alias "till_name" removed (legacy ban); definitions say device_name.
		case "device_name":
			if d := strings.TrimSpace(info.DeviceName); d != "" {
				c.row(L.Device, d)
			}
		case "cashier_name":
			operator := strings.TrimSpace(info.Operator)
			if operator == "" {
				operator = L.OperatorNone
			}
			c.row(L.Operator, operator)
		case "opened_at":
			if info.OpenedAt != "" {
				c.row(L.OpenedAt, info.OpenedAt)
			}
		}
	}
}

func emitShiftOpenDenominations(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.ShiftOpen
	if info == nil {
		return
	}
	L := openLabelsFor(c.locale)
	c.shiftSep()
	c.e.Line(L.DenomHeader +
		spaces(max(c.w-displayWidth(L.DenomHeader)-shiftCountCol-shiftAmountCol, 1)) +
		shiftPadLeft(L.QtyHeader, shiftCountCol) + shiftPadLeft(L.AmountHeader, shiftAmountCol))
	for _, d := range info.Denominations {
		c.row("  "+c.shiftMoney(d.Value),
			shiftPadLeft(fmt.Sprintf("%d%s", d.Quantity, L.QtyUnit), shiftCountCol)+
				shiftPadLeft(c.shiftMoney(d.Subtotal), shiftAmountCol))
	}
}

func emitShiftOpenTotal(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.ShiftOpen
	if info == nil {
		return
	}
	c.shiftSep()
	c.row(openLabelsFor(c.locale).Total, c.shiftMoney(info.OpeningFloat))
}

func emitShiftOpenNote(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.ShiftOpen
	if info == nil {
		return
	}
	note := strings.TrimSpace(info.Note)
	if note == "" {
		return
	}
	L := openLabelsFor(c.locale)
	c.shiftSep()
	c.e.Line(L.Note)
	for _, line := range wrapText(note, c.w-2) {
		c.e.Line("  " + line)
	}
}

// ─── shift report / chain report ──────────────────────────────────────────

func shiftReportPlan() printKindPlan {
	return printKindPlan{
		defaultWidth: 42,
		prologue: func(c *printRenderCtx) {
			c.e.SetLeftMargin(c.cfg.leftMargin(c.w))
		},
		epilogue: func(c *printRenderCtx) {
			// FullCut (ESC d 3) already feeds 3 lines before cutting, so one
			// trailing feed is enough tail margin.
			c.e.Feed(1)
			c.finish()
		},
		emitters: map[string]blockEmitter{
			"logo":               emitLogo,
			"store_info":         emitShiftHeader,
			"title":              emitShiftHeader,
			"shift_meta":         emitShiftReportMeta,
			"chain_summary":      emitShiftChainIndex,
			"sales_summary":      emitShiftSalesSummary,
			"tax_breakdown":      emitShiftTaxBreakdown,
			"tender_summary":     emitShiftTenderSummary,
			"non_cash_change":    emitShiftNonCashChange,
			"discount_summary":   emitShiftDiscounts,
			"service_charge":     emitShiftServiceCharge,
			"acct_correction":    emitShiftAcctCorrection,
			"check_count":        emitShiftCheckCount,
			"cash_movement":      emitShiftCashMovement,
			"void_summary":       emitShiftVoidSummary,
			"variance":           emitShiftDrawerCheck,
			"denomination_table": emitShiftDenominations,
			"shift_signature":    emitAuthoredText,
			"footer_text":        emitAuthoredText,
		},
	}
}

// shiftStat builds the right-hand "{n}件      {amount}" block shared by the
// payment / cash-movement / void rows.
func (c *printRenderCtx) shiftStat(count int, amount string) string {
	L := labelsFor(c.locale)
	return shiftPadLeft(fmt.Sprintf("%d%s", count, L.CountUnit), shiftCountCol) +
		shiftPadLeft(amount, shiftAmountCol)
}

// emitShiftReportMeta prints the right-aligned identity block: which till, which
// Z number (or which chain), the period, and the shop phone.
func emitShiftReportMeta(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Shift
	if info == nil {
		return
	}
	L := labelsFor(c.locale)
	c.e.Align(escpos.AlignRight)
	if info.IsChain {
		chainShort := info.ChainID
		if len(chainShort) > 8 {
			chainShort = chainShort[:8]
		}
		c.e.Line(L.ChainLabel + " " + chainShort)
		c.e.Line(fmt.Sprintf("%d", info.ShiftCount) + L.ChainShiftUnit)
	} else {
		if code := strings.TrimSpace(info.TillCode); code != "" {
			c.e.Line(L.Till + code)
		}
		c.e.Line(fmt.Sprintf("No.%05d", info.ZNumber))
		if info.ChainSequence > 0 {
			c.e.Line(fmt.Sprintf("%s%d", L.ChainShift, info.ChainSequence))
		}
	}
	if info.OpenedAt != "" {
		c.e.Line(L.Period + " " + info.OpenedAt)
	}
	if info.ClosedAt != "" {
		c.e.Line(info.ClosedAt)
	}
	if phone := strings.TrimSpace(info.Phone); phone != "" {
		c.e.Line(L.Phone + " " + phone)
	}
	c.e.Align(escpos.AlignLeft)
}

// emitShiftChainIndex prints each shift's own gross (plus its variance and the
// cashier accountable for it) so a handover chain never loses track of who held
// the drawer before the person who closed it.
func emitShiftChainIndex(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Shift
	if info == nil || !info.IsChain || len(info.ChainIndex) == 0 {
		return
	}
	L := labelsFor(c.locale)
	c.shiftSep()
	for _, cs := range info.ChainIndex {
		kind := L.ChainFinal
		if cs.Kind == "handover" {
			kind = L.ChainHandover
		}
		c.row(fmt.Sprintf("%s%d (%s)", L.ChainShift, cs.Sequence, kind), c.shiftMoney(cs.Gross))
		if cs.Variance != 0 {
			c.row("  "+L.Variance, c.shiftMoney(cs.Variance))
		}
		if cs.Operator != "" {
			c.row("  "+L.Operator, cs.Operator)
		}
	}
}

func emitShiftSalesSummary(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Shift
	if info == nil {
		return
	}
	L := labelsFor(c.locale)
	right := func(value string) {
		c.e.Line(spaces(max(c.w-displayWidth(value), 0)) + value)
	}
	c.shiftSep()
	c.row(L.GrossSales, c.shiftMoney(info.GrossSales))
	right(fmt.Sprintf("%d%s", info.ItemCount, L.ItemUnit))
	c.row(L.NetSales, c.shiftMoney(info.NetSales))
	right(fmt.Sprintf("%d%s", info.GuestCount, L.GuestUnit))
	// Blank line groups the 総売上/純売上 pair away from the tax total, matching
	// the reference 精算 slip (whitespace, not a divider).
	c.e.Feed(1)
	c.row(L.TaxTotal, c.shiftMoney(info.TaxTotal))
}

// emitShiftTaxBreakdown prints 売上内訳 + 消費税内訳. Per-rate rows appear when
// the shop enabled them AND the shift actually carries per-line snapshots;
// otherwise both sections collapse to the single figure a legacy shift has.
func emitShiftTaxBreakdown(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Shift
	if info == nil {
		return
	}
	L := labelsFor(c.locale)
	c.shiftSep()
	perRate := info.ShowTaxBreakdown && len(info.TaxBreakdown) > 0
	c.e.Line(L.SalesBreakdown)
	if perRate {
		for _, tl := range info.TaxBreakdown {
			c.row("  "+fmt.Sprintf(L.RateTarget, formatRatePercent(tl.Rate)), c.shiftMoney(tl.TaxableSales))
		}
		// The rate buckets cover item lines only; the service charge is surfaced
		// so the column actually adds up to 純売上.
		if info.ServiceCharge != 0 {
			c.row("  "+L.ServiceCharge, c.shiftMoney(info.ServiceCharge))
		}
	} else {
		c.row("  "+L.TaxableSales, c.shiftMoney(info.NetSales))
	}
	c.e.Line(L.TaxBreakdown)
	if perRate {
		for _, tl := range info.TaxBreakdown {
			c.row("  "+fmt.Sprintf(L.RateTarget, formatRatePercent(tl.Rate)), c.shiftMoney(tl.Tax))
		}
		if info.ServiceChargeTax != 0 {
			c.row("  "+L.ServiceChargeTax, c.shiftMoney(info.ServiceChargeTax))
		}
	} else {
		c.row("  "+L.Tax, c.shiftMoney(info.TaxTotal))
	}
}

func emitShiftTenderSummary(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Shift
	if info == nil || !info.ShowPaymentMethods {
		return
	}
	L := labelsFor(c.locale)
	c.shiftSep()
	c.e.Line(L.PaymentMethods)
	for _, p := range info.Payments {
		c.row("  "+localizePaymentLabel(c.locale, p.Code, p.Label), c.shiftStat(p.Count, c.shiftMoney(p.Amount)))
	}
}

// emitShiftNonCashChange prints 現金以外おつり. The workstation does not track it,
// so the figures are zeros — kept because the reference slip has the section and
// an auditor comparing two shops' slips must see the same sections.
func emitShiftNonCashChange(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.data.Shift == nil {
		return
	}
	L := labelsFor(c.locale)
	c.shiftSep()
	c.e.Line(L.NonCashChange)
	c.row("  "+L.NonCashUnpaid, c.shiftStat(0, c.shiftMoney(0)))
	c.row("  "+L.NonCashPaid, c.shiftStat(0, c.shiftMoney(0)))
}

func emitShiftDiscounts(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Shift
	if info == nil {
		return
	}
	L := labelsFor(c.locale)
	c.shiftSep()
	c.e.Line(L.Discounts)
	switch {
	case len(info.Discounts) > 0:
		for _, d := range info.Discounts {
			c.row("  "+d.Label, c.shiftStat(d.Count, "▲"+c.shiftMoney(d.Amount)))
		}
	case info.DiscountTotalAmount > 0:
		c.row("  "+L.DiscountGeneric, c.shiftStat(info.DiscountTotalCount, "▲"+c.shiftMoney(info.DiscountTotalAmount)))
	}
	// Item-level discount / surcharge are not modelled at the workstation yet.
	c.row("  "+L.ItemDiscount, c.shiftStat(0, c.shiftMoney(0)))
	c.row("  "+L.ItemSurcharge, c.shiftStat(0, c.shiftMoney(0)))
}

func emitShiftServiceCharge(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Shift
	if info == nil || !info.ShowServiceCharge {
		return
	}
	c.shiftSep()
	c.row(labelsFor(c.locale).ServiceCharge, c.shiftStat(0, c.shiftMoney(info.ServiceCharge)))
}

func emitShiftAcctCorrection(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.data.Shift == nil {
		return
	}
	c.shiftSep()
	c.row(labelsFor(c.locale).AcctCorrection, c.shiftStat(0, c.shiftMoney(0)))
}

func emitShiftCheckCount(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Shift
	if info == nil {
		return
	}
	L := labelsFor(c.locale)
	c.shiftSep()
	c.row(L.CheckCount, shiftPadLeft(fmt.Sprintf("%d%s", info.CheckCount, L.CountUnit), shiftCountCol))
}

func emitShiftCashMovement(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Shift
	if info == nil {
		return
	}
	L := labelsFor(c.locale)
	c.shiftSep()
	c.row(L.CashMovementTotal, c.shiftMoney(info.PaidInAmount-info.PaidOutAmount))
	c.row("  "+L.PaidIn, c.shiftStat(info.PaidInCount, c.shiftMoney(info.PaidInAmount)))
	c.row("  "+L.PaidOut, c.shiftStat(info.PaidOutCount, c.shiftMoney(info.PaidOutAmount)))
}

func emitShiftVoidSummary(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Shift
	if info == nil {
		return
	}
	L := labelsFor(c.locale)
	c.shiftSep()
	c.e.Line(L.VoidBills)
	c.row("  "+L.VoidUnpaid, c.shiftStat(info.VoidUnpaidCount, c.shiftMoney(info.VoidUnpaidAmount)))
	c.row("  "+L.VoidPaid, c.shiftStat(info.VoidPaidCount, c.shiftMoney(info.VoidPaidAmount)))
}

func emitShiftDrawerCheck(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Shift
	if info == nil || !info.ShowDrawerCheck {
		return
	}
	L := labelsFor(c.locale)
	c.shiftSep()
	c.e.Line(L.DrawerCheck)
	c.row("  "+L.CountedCash, c.shiftMoney(info.CountedCash))
	c.row("  "+L.ExpectedCash, c.shiftMoney(info.ExpectedCash))
	variance := c.shiftMoney(info.CashVariance)
	if info.CashVariance > 0 {
		variance = "+" + variance
	}
	c.row("  "+L.Variance, variance)
	operator := strings.TrimSpace(info.Operator)
	if operator == "" {
		operator = L.OperatorNone
	}
	c.row("  "+L.Operator, operator)
}

func emitShiftDenominations(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Shift
	if info == nil || !info.ShowDenominations {
		return
	}
	L := labelsFor(c.locale)
	c.shiftSep()
	c.e.Line(L.Denomination)
	for _, d := range info.Denominations {
		c.row("  "+c.shiftMoney(d.Value),
			shiftPadLeft(fmt.Sprintf("%d%s", d.Quantity, L.PieceUnit), shiftCountCol)+
				shiftPadLeft(c.shiftMoney(d.Subtotal), shiftAmountCol))
	}
}
