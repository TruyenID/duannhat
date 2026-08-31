package service

import (
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// plan-053 M3 (#1171) — block emitters for the DOCUMENT slips:
//
//	vat_invoice   HOA DON GIA TRI GIA TANG
//	void_notice   BIEN BAN HUY HOA DON
//	debt_slip     PHIEU GHI NO
//	table_paid    the runner's "table N just paid" notice
//
// These three were written before the receipt was, and they measure their
// columns with RUNE COUNT rather than display width. That is arguably a bug on
// a Japanese slip, but it is the geometry of every invoice a shop has already
// filed — so the migration reproduces it exactly (TR-40) and leaves fixing it
// to a deliberate, visible template edit.

func init() {
	registerPrintKind("vat_invoice", vatInvoicePlan())
	// #1492 — 適格簡易請求書 dùng lại NGUYÊN plan của vat_invoice, và đó là kết
	// luận đo được: hai chứng từ có cùng danh sách block, khác biệt Nhật–Việt nằm
	// bên trong từng emitter (nhánh `ja` gọi chung helper với formatVatInvoiceJA).
	// Viết một plan thứ hai chép y hệt là tạo ra chỗ để hai bản trôi khỏi nhau.
	//
	// Ở tầng này emitter vẫn rẽ theo LOCALE, nên kind mới in ra layout Nhật khi
	// locale là `ja` — đúng bằng baseline TR-40. Đổi trục rẽ sang QUỐC GIA của
	// shop là việc của #1493, và lúc đó chính chỗ này là nơi hai kind tách hành vi.
	registerPrintKind("qualified_simplified_invoice", japaneseInvoicePlan())
	registerPrintKind("void_notice", voidNoticePlan())
	registerPrintKind("debt_slip", debtSlipPlan())
	registerPrintKind("table_paid", tablePaidPlan())
}

// japaneseInvoicePlan là vatInvoicePlan với CÙNG bộ emitter, chỉ khác một cờ:
// chứng từ này là chứng từ Nhật (#1493). Không chép plan ra bản thứ hai — chép
// là tạo sẵn chỗ cho hai bản trôi khỏi nhau.
func japaneseInvoicePlan() printKindPlan {
	p := vatInvoicePlan()
	p.japaneseDoc = true
	return p
}

// ─── VAT invoice ──────────────────────────────────────────────────────────

func vatInvoicePlan() printKindPlan {
	return printKindPlan{
		defaultWidth: 48,
		prologue: func(c *printRenderCtx) {
			c.e.SetLeftMargin(c.cfg.leftMargin(c.w))
			c.e.Align(escpos.AlignLeft)
		},
		epilogue: func(c *printRenderCtx) { c.finish() },
		emitters: map[string]blockEmitter{
			"logo":                emitLogo,
			"store_info":          emitDocHeader,
			"title":               emitDocHeader,
			"reprint_marker":      emitDocReprintMarker,
			"invoice_number":      emitVatInvoiceNumber,
			"customer_header":     emitVatParties,
			"column_header":       emitVatColumnHeader,
			"items":               emitVatItems,
			"subtotal":            emitVatSubtotal,
			"tax_breakdown":       emitVatTaxBreakdown,
			"grand_total":         emitVatGrandTotal,
			"registration_number": emitVatRegistrationNumber,
			"payments":            emitVatPaymentMethod,
			"footer_text":         emitVatFooter,
		},
	}
}

// vatCurrency prefers the currency frozen onto the invoice at issue over the
// shop's live setting — a reprint of last month's invoice must not silently
// re-denominate it.
func (c *printRenderCtx) vatCurrency() string {
	if c.data.Vat != nil && c.data.Vat.CurrencyPrefix != "" {
		return c.data.Vat.CurrencyPrefix
	}
	return c.cfg.cur()
}

// emitDocHeader prints "store name … TITLE" then the store's sub-name line.
// Shared by the VAT invoice and the debt slip, which frame their header
// identically (and measure the title by RUNE count — see the file header).
func emitDocHeader(c *printRenderCtx, _ *PrintTemplateBlock) {
	// TRONG guard, không ở ngoài: emitter này được đăng ký cho CẢ `store_info`
	// lẫn `title`, nên đặt ngoài sẽ in các dòng danh tính hai lần.
	if c.headerDrawn {
		return
	}
	c.headerDrawn = true

	// The Japanese VAT invoice leads with a centered 領収書 heading (a Japanese
	// receipt names its document type up top). Gated to the ja VAT slip so the
	// debt slip and the vi/en invoice are untouched; shared with formatVatInvoiceJA
	// so the two renderers stay byte-identical.
	if c.data.Vat != nil && c.japaneseDoc {
		vatJAReceiptHeader(c.e)
	}

	// SAU tiêu đề 領収書: legacy đặt đúng thứ tự đó, và trên chứng từ Nhật nhãn
	// loại chứng từ đứng trên cùng tờ giấy chứ không phải dưới tên pháp nhân.
	emitStoreAbove(c)

	storeName := c.cfg.StoreName
	if storeName == "" {
		storeName = "Store"
	}
	title := c.def.text(c.def.block("title"), c.locale, c.w < 42)
	// #1734 — trên chứng từ Nhật, nhãn loại chứng từ là một TUYÊN BỐ PHÁP LÝ:
	// nó chỉ đúng khi có 登録番号 của người bán. Emitter ghi đè chữ do brand soạn
	// ở đúng ca đó, cùng lập luận với câu miễn trừ ở emitVatFooter — brand không
	// được phép dán "適格簡易請求書" lên một tờ giấy không đủ điều kiện.
	if c.japaneseDoc && c.data.Vat != nil {
		title = vatJAQualifiedTitle(c.data.Vat.SellerRegistrationNumber, c.w < 42)
	}
	renderDocHeader(c.e, c.w, storeName, title)

	emitStoreBelow(c)
}

// renderDocHeader draws "store … TITLE". Shared by
// emitDocHeader (template) and formatVatInvoiceJA (legacy) so the ja header can't
// drift. Title width is measured in DISPLAY columns so a full-width Japanese
// title (適格簡易請求書) right-aligns correctly; for the ASCII titles every other
// slip ships this is a no-op (displayWidth == runeLen), so no existing golden
// byte changes.
func renderDocHeader(e *escpos.Encoder, w int, storeName, title string) {
	// #1734 — nhãn rỗng thì in mỗi tên cửa hàng, không đệm khoảng trắng tới hết
	// dòng. Nhánh dưới sẽ tạo một hàng dài đầy khoảng trắng đuôi, vô hình trên
	// màn hình và lộ ra trên giấy nhiệt.
	if strings.TrimSpace(title) == "" {
		e.Bold(true)
		e.Line(storeName)
		e.Bold(false)

		return
	}

	titleW := displayWidth(title)
	storeDispW := displayWidth(storeName)
	e.Bold(true)
	if storeDispW+1+titleW <= w {
		e.Line(storeName + spaces(w-storeDispW-titleW) + title)
	} else {
		e.Line(storeName)
		e.Line(spaces(w-titleW) + title)
	}
	e.Bold(false)
}

// emitDocReprintMarker prints the right-aligned copy marker — `BAN IN #N` in
// vi/en, 「再印刷 #N」 in ja (#1890). A first print carries no marker at all, so a
// customer can never mistake an original for a copy — and a copy can never pass
// as an original.
//
// It DELEGATES to printReprintMarker rather than repeating the string: this
// renderer and the legacy formatter must emit identical bytes (the golden gate
// compares them), and two copies of "which word, in which locale, padded to
// which width" is exactly how that gate starts failing for a reason nobody can
// see. Width therefore also comes out in display columns, which a ja mark needs.
func emitDocReprintMarker(c *printRenderCtx, _ *PrintTemplateBlock) {
	printReprintMarker(c.e, c.w, c.reprintNumber(), c.data.Config.Locale)
}

func (c *printRenderCtx) reprintNumber() int {
	if c.data.ReprintNumber > 0 {
		return c.data.ReprintNumber
	}
	if c.data.Vat != nil {
		return c.data.Vat.ReprintNumber
	}
	if c.data.Debt != nil {
		return c.data.Debt.ReprintNumber
	}
	return 0
}

// emitVatInvoiceNumber prints "So HD: {no}" flush-left with the issue timestamp
// flush-right. The two share one physical line, so the invoice number block
// owns both and `issued_at` has no emitter for this kind.
func emitVatInvoiceNumber(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Vat
	if info == nil {
		return
	}
	issuedAt := info.IssuedAt
	if issuedAt.IsZero() {
		issuedAt = c.data.now()
	}
	if c.japaneseDoc {
		vatJAInvoiceNumber(c.e, c.w, info.InvoiceNo, issuedAt.Format("2006/01/02 15:04"))
		return
	}
	noLine := "So HD: " + info.InvoiceNo
	dateStr := issuedAt.Format("2006/01/02 15:04")
	if runeLen(noLine)+1+runeLen(dateStr) <= c.w {
		c.e.Line(noLine + spaces(c.w-runeLen(noLine)-runeLen(dateStr)) + dateStr)
	} else {
		c.e.Line(noLine)
		c.e.Line(spaces(c.w-runeLen(dateStr)) + dateStr)
	}
}

// emitVatParties prints the NGUOI MUA / NGUOI BAN pair. Both parties live in
// one block because an invoice is a statement ABOUT a transaction between two
// named parties — a template that could show one without the other would not be
// an invoice.
func emitVatParties(c *printRenderCtx, b *PrintTemplateBlock) {
	info := c.data.Vat
	if info == nil {
		return
	}
	if c.japaneseDoc {
		vatJAParties(c.e, c.w, *info)
		return
	}
	c.e.Feed(1)
	c.e.Bold(true)
	c.e.Line("NGUOI MUA")
	c.e.Bold(false)

	fields := b.Fields
	if len(fields) == 0 {
		fields = []string{"customer_name", "customer_tax_code", "customer_address"}
	}
	for _, f := range fields {
		switch f {
		case "customer_name":
			if v := strings.TrimSpace(info.CompanyName); v != "" {
				c.e.Line("Cty:  " + v)
			}
		case "customer_tax_code":
			if v := strings.TrimSpace(info.TaxCode); v != "" {
				c.e.Line("MST:  " + v)
			}
		case "customer_address":
			if v := strings.TrimSpace(info.BillingAddress); v != "" {
				c.e.Line("DC:   " + v)
			}
		}
	}

	storeName := c.cfg.StoreName
	if storeName == "" {
		storeName = "Store"
	}
	c.e.Feed(1)
	c.e.Bold(true)
	c.e.Line("NGUOI BAN")
	c.e.Bold(false)
	c.e.Line(storeName)
	// #1224 — seller tax code beside the seller name, mirroring the buyer block
	// above. Kept byte-identical to FormatVatInvoice: the TR-40 gate compares
	// this template path against that hard-coded formatter, so the two have to
	// move together or every VAT invoice fails the gate.
	if reg := strings.TrimSpace(info.SellerRegistrationNumber); reg != "" {
		c.e.Line("MST:  " + reg)
	}
}

// vatNameWidth is the item-name column: the paper minus the three fixed numeric
// columns (SL 3 + Don gia 11 + Thanh tien 11).
func (c *printRenderCtx) vatNameWidth() int { return c.w - vatNumericColumns }

func emitVatColumnHeader(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.japaneseDoc {
		vatJAColumnHeader(c.e, c.w)
		return
	}
	c.e.Feed(1)
	c.e.Line(dashedLine(c.w))
	c.e.Feed(1)
	for _, line := range vatColumnHeaderLines(c.w) {
		c.e.Line(line)
	}
	c.e.Line(dashedLine(c.w))
}

func emitVatItems(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Vat
	if info == nil {
		return
	}
	// #1169 — item rows + option/variant + toppings + note, shared byte-for-byte
	// with the legacy FormatVatInvoice via vatItemLines. The golden gate compares
	// the two renderers, so this and FormatVatInvoice cannot drift.
	if c.japaneseDoc {
		vatItemLinesJA(c.e, c.w, info.Items, c.vatCurrency())
		c.e.Line(dashedLine(c.w))
		return
	}
	vatItemLines(c.e, c.w, c.vatNameWidth(), info.Items)
	c.e.Line(dashedLine(c.w))
}

func emitVatSubtotal(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Vat
	if info == nil {
		return
	}
	if c.japaneseDoc {
		vatJASubtotal(c.e, c.w, *info, c.vatCurrency())
		return
	}
	// #1169 — an equal/by-amount split share lists the whole order (Σ món >
	// Subtotal) → headline "Tổng món" (the full order). See IsAmountSplit.
	if info.IsAmountSplit {
		c.runeRow("Tong mon", c.vatCurrency()+formatPrice(info.itemLinesSum()))
		return
	}
	c.runeRow("Tam tinh", c.vatCurrency()+formatPrice(info.Subtotal))
}

// emitVatTaxBreakdown prints the per-rate blocks frozen onto the invoice at
// issue, falling back to the single legacy "Thue X %" line for an invoice
// issued before per-rate breakdowns existed.
func emitVatTaxBreakdown(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Vat
	if info == nil {
		return
	}
	if c.japaneseDoc {
		vatJATaxBreakdown(c.e, c.w, *info, c.vatCurrency())
		return
	}
	// #1169 — a by-amount split share prints just "Tổng món" / "Khách thanh toán
	// (phần chia)"; the per-rate breakdown (whose taxable is the share, not the
	// listed món) would read as a third, contradictory number, so drop it.
	if info.IsAmountSplit {
		return
	}
	currency := c.vatCurrency()
	if len(info.TaxBreakdown) > 0 {
		// #2057 — DISPLAY width, not rune count, for BOTH the wrap decision and
		// the justification. The rate labels follow the INVOICE's locale
		// (vatRateRowsFor), so a ja-locale invoice puts full-width glyphs
		// (10%対象 / 内消費税) on this one block of the GTGT slip; rune counting
		// under-measured them by 6 columns and the rows overflowed EVERY paper
		// width (32→38, 42→48, 48→54). The rest of the GTGT footer stays on
		// runeRow: its labels are fixed ASCII, where the two measurements agree.
		for _, row := range vatRateRowsFor(c.w, displayWidth, "", *info, currency) {
			c.row(row[0], row[1])
		}
		return
	}
	if info.TaxAmount <= 0 {
		return
	}
	label := "Thue"
	if info.TaxRatePercent > 0 {
		label = "Thue " + itoa(info.TaxRatePercent) + " %"
	}
	c.runeRow(label, currency+formatPrice(info.TaxAmount))
}

func emitVatGrandTotal(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Vat
	if info == nil {
		return
	}
	if c.japaneseDoc {
		vatJAGrandTotal(c.e, c.w, *info, c.vatCurrency())
		return
	}
	c.e.Bold(true)
	// #1169 — on a by-amount split share the grand total is what THIS guest pays.
	if info.IsAmountSplit {
		c.runeRow("Khach thanh toan (phan chia)", c.vatCurrency()+formatPrice(info.Total))
	} else {
		c.runeRow("Tong cong", c.vatCurrency()+formatPrice(info.Total))
	}
	c.e.Bold(false)
	if info.IsAmountSplit {
		c.e.Line("(Hoa don phan chia)")
	}
}

// emitVatRegistrationNumber is intentionally silent since #1224.
//
// The seller's tax code now prints inside the NGUOI BAN block (emitVatParties),
// where a Vietnamese GTGT invoice expects it — beside the seller's name and
// symmetrical with the buyer's. Emitting here as well would put the same number
// on the slip twice.
//
// The BLOCK is left in place rather than deleted from the template definitions.
// Those definitions are mirrored byte-for-byte in Cloud
// (testdata/cloud_print_templates_default.json, gated by the cross-repo parity
// test) and are what a brand's published overlay is authored against; removing a
// block id would change that contract for all seven kinds at once, to delete
// something that costs nothing to keep. An empty locked block renders nothing,
// exactly like the disabled `logo` block above it.
func emitVatRegistrationNumber(_ *printRenderCtx, _ *PrintTemplateBlock) {}

func emitVatPaymentMethod(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.data.Vat == nil {
		return
	}
	if c.japaneseDoc {
		vatJAPayment(c.e, *c.data.Vat)
		return
	}
	pm := strings.TrimSpace(c.data.Vat.PaymentMethod)
	if pm == "" {
		return
	}
	c.runeRow("Hinh thuc TT", pm)
}

// emitVatFooter prints the signature columns and the legal-notice disclaimer.
// v1 is explicitly NOT a CQT-signed e-invoice (DESIGN Q-decision-11), and the
// disclaimer saying so is the reason this block cannot be brand-authored text.
func emitVatFooter(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.japaneseDoc {
		storeName := c.cfg.StoreName
		if storeName == "" {
			storeName = "Store"
		}
		reg := ""
		if c.data.Vat != nil {
			reg = c.data.Vat.SellerRegistrationNumber
		}
		vatJAFooter(c.e, c.w, storeName, reg)
		return
	}
	c.e.Feed(2)
	left, right := "Ben mua", "Ben ban"
	gap := c.w - runeLen(left) - runeLen(right)
	if gap < 1 {
		gap = 1
	}
	c.e.Line(left + spaces(gap) + right)
	c.e.Feed(3)
	half := (c.w - 1) / 2
	c.e.Line(strings.Repeat("_", half) + " " + strings.Repeat("_", c.w-half-1))

	c.e.Feed(2)
	c.e.Align(escpos.AlignCenter)
	c.e.Line("HOA DON THAM CHIEU NOI BO")
	for _, line := range vatDisclaimerLines(c.w) {
		c.e.Line(line)
	}
	c.e.Align(escpos.AlignLeft)
	c.e.Feed(3)
}

// ─── void notice ──────────────────────────────────────────────────────────

func voidNoticePlan() printKindPlan {
	return printKindPlan{
		defaultWidth: 48,
		prologue: func(c *printRenderCtx) {
			c.e.SetLeftMargin(c.cfg.leftMargin(c.w))
		},
		epilogue: func(c *printRenderCtx) {
			c.e.Feed(3)
			c.finish()
		},
		emitters: map[string]blockEmitter{
			"logo":           emitLogo,
			"void_marker":    emitVoidMarker,
			"invoice_number": emitVoidInvoiceNumber,
			"issued_at":      emitVoidVoidedAt,
			"order_meta":     emitVoidReason,
			"footer_text":    emitVoidFooter,
		},
	}
}

// emitVoidMarker is the one block on this slip that can never be turned off
// (TR-18): a cancellation notice that does not say it is a cancellation is a
// receipt.
func emitVoidMarker(c *printRenderCtx, _ *PrintTemplateBlock) {
	c.e.Align(escpos.AlignCenter)
	c.e.Bold(true)
	c.e.Line("BIEN BAN HUY HOA DON")
	c.e.Bold(false)
	c.e.Feed(1)
	c.e.Align(escpos.AlignLeft)
}

func emitVoidInvoiceNumber(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.data.Vat == nil {
		return
	}
	c.e.Line("So HD bi huy: " + c.data.Vat.InvoiceNo)
}

func emitVoidVoidedAt(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.data.VoidedAt.IsZero() {
		return
	}
	c.e.Line("Thoi diem huy: " + c.data.VoidedAt.Format("2006/01/02 15:04"))
}

func emitVoidReason(c *printRenderCtx, _ *PrintTemplateBlock) {
	if reason := strings.TrimSpace(c.data.VoidReason); reason != "" {
		c.e.Line("Ly do: " + reason)
	}
}

func emitVoidFooter(c *printRenderCtx, _ *PrintTemplateBlock) {
	c.e.Feed(2)
	c.e.Align(escpos.AlignCenter)
	c.e.Line("KHACH HANG NHAN BIET HOA DON DA HUY")
	c.e.Align(escpos.AlignLeft)
}

// ─── debt slip ────────────────────────────────────────────────────────────

func debtSlipPlan() printKindPlan {
	return printKindPlan{
		defaultWidth: 48,
		prologue: func(c *printRenderCtx) {
			c.e.SetLeftMargin(c.cfg.leftMargin(c.w))
			c.e.Align(escpos.AlignLeft)
		},
		epilogue: func(c *printRenderCtx) {
			c.e.Feed(3)
			c.finish()
		},
		emitters: map[string]blockEmitter{
			"logo":            emitLogo,
			"store_info":      emitDocHeader,
			"title":           emitDocHeader,
			"reprint_marker":  emitDocReprintMarker,
			"issued_at":       emitDebtIssuedAt,
			"order_meta":      emitDebtOrderMeta,
			"customer_header": emitDebtCustomerHeader,
			"column_header":   emitDebtColumnHeader,
			"items":           emitDebtItems,
			"grand_total":     emitDebtTotal,
			"payments":        emitDebtPaid,
			"debt_summary":    emitDebtOwed,
			"debt_signature":  emitDebtSignature,
			"footer_text":     emitAuthoredText,
		},
	}
}

func emitDebtIssuedAt(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.data.Order == nil {
		return
	}
	dateStr := c.data.now().Format("2006/01/02 15:04")
	codeStr := "#" + OrderCodeSuffix(c.data.Order.OrderCode)
	dateW := c.w - runeLen(codeStr)
	if dateW < 1 {
		dateW = 1
	}
	c.e.Line(padRight(dateStr, dateW) + codeStr)
}

func emitDebtOrderMeta(c *printRenderCtx, _ *PrintTemplateBlock) {
	order := c.data.Order
	if order == nil {
		return
	}
	c.e.Feed(1)
	tableStr := order.TableNumber
	if tableStr == "" {
		tableStr = "-"
	}
	c.runeRow("So HD", OrderCodeSuffix(order.OrderCode))
	c.runeRow("Ban", tableStr)
}

func emitDebtCustomerHeader(c *printRenderCtx, _ *PrintTemplateBlock) {
	info := c.data.Debt
	if info == nil {
		return
	}
	name := strings.TrimSpace(info.CustomerName)
	if name == "" {
		name = "-"
	}
	c.runeRow("Khach hang", name)
	if phone := strings.TrimSpace(info.CustomerPhone); phone != "" {
		c.runeRow("SDT", phone)
	}
	if tax := strings.TrimSpace(info.CustomerTaxCode); tax != "" {
		c.runeRow("MST", tax)
	}
}

func emitDebtColumnHeader(c *printRenderCtx, b *PrintTemplateBlock) {
	left, right := columnHeaderText(c.def.text(b, c.locale, c.w < 42))
	c.separatorBand()
	c.e.Line(padRight(left, c.w-runeLen(right)) + right)
	c.e.Feed(1)
}

// emitDebtItems prints the item lines WITHOUT the ※ reduced-rate marker: a
// PHIEU GHI NO is a receivable record, not an インボイス receipt.
func emitDebtItems(c *printRenderCtx, _ *PrintTemplateBlock) {
	for _, item := range c.data.Items {
		printRunnerItem(c.e, c.w, slipPriceWidth(c.data.Items, c.cfg.cur()), item, c.cfg.cur(), false, c.locale, c.labels.NotePrefix)
	}
	c.separatorBand()
}

func emitDebtTotal(c *printRenderCtx, _ *PrintTemplateBlock) {
	c.e.Bold(true)
	c.runeRow("Tong", c.money(c.data.Total))
	c.e.Bold(false)
}

func emitDebtPaid(c *printRenderCtx, _ *PrintTemplateBlock) {
	c.runeRow("Da thanh toan", c.money(c.data.PaidShown))
}

func emitDebtOwed(c *printRenderCtx, _ *PrintTemplateBlock) {
	c.e.Bold(true)
	c.runeRow("GHI NO", c.money(c.debtAmount()))
	c.e.Bold(false)
}

// debtAmount mirrors FormatDebtSlip: an unset amount means the whole bill went
// on account.
func (c *printRenderCtx) debtAmount() int {
	if c.data.Debt != nil && c.data.Debt.DebtAmount > 0 {
		return c.data.Debt.DebtAmount
	}
	return c.data.Total
}

func emitDebtSignature(c *printRenderCtx, _ *PrintTemplateBlock) {
	c.e.Feed(2)
	c.e.Line("Chu ky khach hang:")
	c.e.Feed(2)
	c.e.Line(strings.Repeat("_", 25))
	c.e.Feed(1)
	c.e.Line("Khach hang xac nhan da nhan no")
}

// ─── table paid ───────────────────────────────────────────────────────────

func tablePaidPlan() printKindPlan {
	return printKindPlan{
		defaultWidth: 42,
		prologue: func(c *printRenderCtx) {
			c.e.Align(escpos.AlignLeft)
			c.e.SetLeftMargin(c.cfg.leftMargin(c.w))
		},
		epilogue: func(c *printRenderCtx) {
			c.e.Feed(1)
			c.finish()
		},
		emitters: map[string]blockEmitter{
			"logo":         emitLogo,
			"store_info":   emitTablePaidStore,
			"issued_at":    emitTablePaidAt,
			"order_meta":   emitTablePaidOrderNo,
			"paid_summary": emitTablePaidHeadline,
			"footer_text":  emitAuthoredText,
		},
	}
}

func emitTablePaidStore(c *printRenderCtx, _ *PrintTemplateBlock) {
	emitStoreAbove(c)

	name := strings.TrimSpace(c.cfg.StoreName)
	if name == "" {
		return
	}
	c.e.Bold(true)
	c.e.Line(name)
	c.e.Bold(false)

	emitStoreBelow(c)
}

func emitTablePaidAt(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.data.TablePaid == nil {
		return
	}
	if paidAt := strings.TrimSpace(c.data.TablePaid.PaidAt); paidAt != "" {
		c.e.Line(paidAt)
	}
}

func emitTablePaidOrderNo(c *printRenderCtx, _ *PrintTemplateBlock) {
	if c.data.TablePaid == nil {
		return
	}
	code := OrderCodeSuffix(strings.TrimSpace(c.data.TablePaid.OrderCode))
	if code == "" {
		return
	}
	c.row(tablePaidLabelsFor(c.locale).Order+":", code)
}

// emitTablePaidHeadline is the whole point of the slip: bold, double-height,
// one row a passing runner reads at a glance.
func emitTablePaidHeadline(c *printRenderCtx, _ *PrintTemplateBlock) {
	L := tablePaidLabelsFor(c.locale)
	title := c.def.text(c.def.block("title"), c.locale, c.w < 42)
	if title == "" {
		title = L.Title
	}
	table := "-"
	if c.data.TablePaid != nil {
		if t := strings.TrimSpace(c.data.TablePaid.TableNumber); t != "" {
			table = t
		}
	}
	c.e.Line(dashedLine(c.w))
	c.e.Bold(true)
	c.e.Size(escpos.DoubleHeight)
	c.e.Line(title + " " + L.Table + " " + table)
	c.e.Size(escpos.NormalSize)
	c.e.Bold(false)
}
