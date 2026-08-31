package service

import (
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// ── Japanese VAT invoice (適格簡易請求書) — the ja-locale layout ──
//
// When the shop prints in Japanese (normalizePrintLocale(info.Locale) == "ja")
// the Vietnamese GTGT slip is replaced by this Japanese layout: 【お客様】/【明細】
// section headers, món names + variant (option) + トッピング + 備考, NO
// 買い手/売り手 signature block, and the seller identity moved to a compact 発行
// line at the foot. Every helper here is called by BOTH the legacy
// formatVatInvoiceJA AND the template emit* ja-branches (print_renderer_docs.go),
// so the two renderers cannot drift under the golden gate — exactly as
// vatItemLines is shared for the Vietnamese layout.
//
// All alignment uses DISPLAY width (a full-width JP glyph is 2 columns), not the
// rune count runeRow/runeLen use — with Japanese labels the rune count would push
// every right-aligned amount off the right edge and wrap the line.

const (
	vatJATitle       = "適格簡易請求書"
	vatJATitleNarrow = "簡易請求書"
)

// vatJAQualifiedTitle trả nhãn loại chứng từ, và nó là một TUYÊN BỐ PHÁP LÝ chứ
// không phải chữ trang trí (#1734).
//
// Theo インボイス制度, thứ làm một tờ giấy trở thành 適格簡易請求書 là 登録番号
// của NGƯỜI BÁN. Có số ⇒ tờ giấy đúng là chứng từ đủ điều kiện và người mua
// khấu trừ được thuế đầu vào. KHÔNG có số ⇒ dán nhãn ấy lên là nói sai, nên
// nhãn biến mất và tờ giấy chỉ là 領収書 — đúng như tiêu đề canh giữa ở đầu
// slip vốn đã nói.
//
// Đây là lý do nhãn không thể do brand tự soạn, cùng lập luận đã ghi ở
// emitVatFooter cho câu miễn trừ.
func vatJAQualifiedTitle(sellerReg string, narrow bool) string {
	if strings.TrimSpace(sellerReg) == "" {
		return ""
	}
	if narrow {
		return vatJATitleNarrow
	}
	return vatJATitle
}

// vatJAReceiptHeader prints the centered 領収書 heading a Japanese receipt leads
// with, above the store/title line. Shared by the legacy renderer and
// emitDocHeader's ja-branch so the two stay byte-identical. It restores the left
// alignment renderDocHeader expects.
func vatJAReceiptHeader(e *escpos.Encoder) {
	e.Align(escpos.AlignCenter)
	e.Bold(true)
	e.Line("領収書")
	e.Bold(false)
	e.Align(escpos.AlignLeft)
}

// vatJARow prints "label <pad> value", right-aligning value to width w by
// DISPLAY columns.
func vatJARow(e *escpos.Encoder, w int, label, value string) {
	gap := w - displayWidth(label) - displayWidth(value)
	if gap < 1 {
		gap = 1
	}
	e.Line(label + spaces(gap) + value)
}

// vatJATrunc trims s to at most w display columns (drops whole runes off the end
// until it fits) so a long món / option / note never wraps the thermal line.
func vatJATrunc(s string, w int) string {
	if w < 0 {
		w = 0
	}
	if displayWidth(s) <= w {
		return s
	}
	r := []rune(s)
	for len(r) > 0 && displayWidth(string(r)) > w {
		r = r[:len(r)-1]
	}
	return string(r)
}

// vatItemLinesJA renders the 【明細】 body for the ja layout: món name (its own
// line when it carries a variant, else món + qty/amount on one line), the chosen
// variant with the qty + amount, each topping with its surcharge, and the note.
// Shared byte-for-byte by the legacy renderer and emitVatItems' ja-branch.
func vatItemLinesJA(e *escpos.Encoder, w int, items []VatInvoiceLine, cur string) {
	for _, it := range items {
		name := strings.TrimSpace(it.Name)
		if name == "" {
			name = "-"
		}
		variant := strings.TrimSpace(it.VariantName)
		qtyAmt := itoa(it.Quantity) + "点  " + cur + formatPrice(it.LineTotal)

		if variant != "" {
			// Món name alone; the qty + amount ride the variant line.
			e.Line(" " + vatJATrunc(name, w-1))
			vatJARow(e, w, "   "+vatJATrunc(variant, w-displayWidth(qtyAmt)-4), qtyAmt)
		} else {
			// No variant: món name + qty + amount on one line.
			vatJARow(e, w, " "+vatJATrunc(name, w-displayWidth(qtyAmt)-2), qtyAmt)
		}

		for _, t := range it.Toppings {
			tName := strings.TrimSpace(t.Name)
			if tName == "" {
				continue
			}
			price := cur + formatPrice(t.UnitPrice*maxInt(t.Quantity, 1))
			label := "     ＋" + tName
			if t.Quantity > 1 {
				label += " ×" + itoa(t.Quantity)
			}
			vatJARow(e, w, vatJATrunc(label, w-displayWidth(price)-1), price)
		}

		if n := strings.TrimSpace(it.Note); n != "" {
			e.Line(vatJATrunc("   備考: "+n, w))
		}
	}
}

// vatJAParties prints the 【お客様】 block only. The seller identity moves to the
// 発行 footer (vatJAFooter) — a Japanese receipt names the buyer up top and the
// issuer at the foot, unlike the VN two-party block.
func vatJAParties(e *escpos.Encoder, w int, info VatInvoiceInfo) {
	e.Feed(1)
	e.Bold(true)
	e.Line("【お客様】")
	e.Bold(false)

	buyer := strings.TrimSpace(info.BuyerName)
	company := strings.TrimSpace(info.CompanyName)
	taxCode := strings.TrimSpace(info.TaxCode)
	billing := strings.TrimSpace(info.BillingAddress)

	if buyer != "" {
		e.Line(" 宛名: " + buyer)
	} else if company == "" && taxCode == "" && billing == "" {
		// A pure walk-in with nothing typed → an underline to hand-write, like
		// the VI slip.
		u := w - displayWidth(" 宛名: ")
		if u < 1 {
			u = 1
		}
		e.Line(" 宛名: " + strings.Repeat("_", u))
	}
	if company != "" {
		e.Line(" 会社: " + company)
	}
	if taxCode != "" {
		e.Line(" 登録番号: " + taxCode)
	}
	if billing != "" {
		e.Line(" 住所: " + billing)
	}
}

// vatJAColumnHeader prints the 【明細】 heading + the top rule.
func vatJAColumnHeader(e *escpos.Encoder, w int) {
	e.Feed(1)
	e.Bold(true)
	e.Line("【明細】")
	e.Bold(false)
	e.Line(dashedLine(w))
}

// vatJASubtotal prints the money block down to 消費税: 小計, then the サービス料 /
// 値引き adjustment. On a by-amount split it leads with 商品合計 (the whole order's
// món sum, which the slip lists) then 小計(按分) — this guest's apportioned share.
//
// The adjustment is DERIVED (Total − Subtotal − TaxAmount) so the printed money
// reconciles — 小計 + サービス料 + 消費税 = 合計 — instead of the old slip where the
// service charge silently vanished and the columns did not add up. It is the net
// of service charge less any order discount: a positive net prints サービス料 (the
// common case — a shop that adds a service charge and no discount), a negative
// net prints 値引き. On a by-amount split Subtotal/TaxAmount/Total are all the
// guest's share, so the guest block still adds up.
func vatJASubtotal(e *escpos.Encoder, w int, info VatInvoiceInfo, cur string) {
	if info.IsAmountSplit {
		vatJARow(e, w, " 商品合計", cur+formatPrice(info.itemLinesSum()))
		vatJARow(e, w, " 小計(按分)", cur+formatPrice(info.Subtotal))
	} else {
		vatJARow(e, w, " 小計", cur+formatPrice(info.Subtotal))
	}
	adj := info.Total - info.Subtotal - info.TaxAmount
	if adj > 0 {
		vatJARow(e, w, " サービス料", cur+formatPrice(adj))
	} else if adj < 0 {
		vatJARow(e, w, " 値引き", "-"+cur+formatPrice(-adj))
	}
}

// vatJATaxBreakdown prints the per-rate 消費税 blocks (or the single legacy line
// for a pre-breakdown invoice) — for a WHOLE-order invoice AND a by-amount split
// (the split carries the guest's apportioned per-rate breakdown, so the tax that
// used to be missing from the split slip now prints).
func vatJATaxBreakdown(e *escpos.Encoder, w int, info VatInvoiceInfo, cur string) {
	if len(info.TaxBreakdown) > 0 {
		// The Japanese document always speaks Japanese whatever the render
		// locale, so the labels are pinned rather than taken from info.Locale.
		lines := vatRateLinesFor(taxLabelsFor("ja"), cur, info.TaxBreakdown)
		for _, row := range layoutVatRateRows(w, displayWidth, " ", lines) {
			vatJARow(e, w, row[0], row[1])
		}
		return
	}
	if info.TaxAmount <= 0 {
		return
	}
	label := " 消費税"
	if info.TaxRatePercent > 0 {
		label = " 消費税" + itoa(info.TaxRatePercent) + "%"
	}
	vatJARow(e, w, label, cur+formatPrice(info.TaxAmount))
}

// vatJAGrandTotal prints 合計 (or 合計(按分) for a by-amount split share).
func vatJAGrandTotal(e *escpos.Encoder, w int, info VatInvoiceInfo, cur string) {
	e.Bold(true)
	if info.IsAmountSplit {
		vatJARow(e, w, " 合計(按分)", cur+formatPrice(info.Total))
	} else {
		vatJARow(e, w, " 合計", cur+formatPrice(info.Total))
	}
	e.Bold(false)
	if info.IsAmountSplit {
		e.Line(" (按分請求)")
	}
}

// vatJAPayment prints the お支払 line when a payment method is known.
func vatJAPayment(e *escpos.Encoder, info VatInvoiceInfo) {
	if pm := strings.TrimSpace(info.PaymentMethod); pm != "" {
		e.Line(" お支払: " + pm)
	}
}

// vatJAFooter prints the foot rule, the 発行 (issuer) line with the seller's
// 登録番号, and the centered internal-reference notice. It does NOT cut — the
// legacy caller and the template epilogue each add the FullCut, matching the VI
// path.
func vatJAFooter(e *escpos.Encoder, w int, storeName, sellerReg string) {
	e.Line(dashedLine(w))
	line := " 発行: " + storeName
	if reg := strings.TrimSpace(sellerReg); reg != "" {
		line += "   登録番号 " + reg
	}
	e.Line(vatJATrunc(line, w))

	e.Feed(2)
	// #1734 — câu miễn trừ CHỈ in khi tờ giấy thật sự chưa đủ điều kiện.
	//
	// Trước đây nó in vô điều kiện, ngay dưới nhãn 適格簡易請求書: tờ giấy tự
	// nhận là chứng từ đủ điều kiện ở trên rồi tự phủ nhận ở dưới. Nhiều khả
	// năng là di sản từ trước #1152 — khi chưa có 登録番号 thì nó THẬT SỰ chưa đủ
	// điều kiện và câu này đúng; #1152 ship số đăng ký nhưng không ai quay lại.
	//
	// Hậu quả của việc để nguyên: kế toán của khách đọc dòng "không thay thế
	// được hoá đơn đủ điều kiện" rồi từ chối dùng nó để khấu trừ thuế đầu vào —
	// trong khi về mặt pháp lý nó đủ.
	if strings.TrimSpace(sellerReg) == "" {
		e.Align(escpos.AlignCenter)
		e.Line("社内参照用（控え）")
		e.Line("※適格請求書等の代替ではありません")
		e.Align(escpos.AlignLeft)
	}
	e.Feed(3)
}

// formatVatInvoiceJA is the ja-locale legacy renderer. It calls the SAME shared
// helpers the template emit* ja-branches call, in the SAME order the vat plan's
// blocks fire, so the golden gate's byte comparison holds.
func formatVatInvoiceJA(info VatInvoiceInfo, config PrintJobConfig) []byte {
	w := config.PaperWidth
	if w == 0 {
		w = 48
	}
	currency := info.CurrencyPrefix
	if currency == "" {
		currency = config.cur()
	}

	e := escpos.New()
	// Matches vatInvoicePlan.prologue.
	e.SetLeftMargin(config.leftMargin(w))
	e.Align(escpos.AlignLeft)

	storeName := config.StoreName
	if storeName == "" {
		storeName = "Store"
	}
	title := vatJAQualifiedTitle(info.SellerRegistrationNumber, w < 42)
	vatJAReceiptHeader(e)
	// #2000 bước 6 — dòng danh tính do `store_info.fields` làm chủ, một nguồn cho
	// cả hai đường in. Trước đây `renderDocHeader` tự in `StoreSubName`, nên nó
	// là nguồn thứ hai và không ai chỉnh được nó từ template.
	fields := StoreFieldsForKind("vat_invoice")
	for _, line := range StoreDetailValues(config, fields, true) {
		e.Line(line)
	}

	renderDocHeader(e, w, storeName, title)

	for _, line := range StoreDetailValues(config, fields, false) {
		e.Line(line)
	}

	printReprintMarker(e, w, info.ReprintNumber, config.Locale)

	issuedAt := info.IssuedAt
	if issuedAt.IsZero() {
		issuedAt = printNow()
	}
	vatJAInvoiceNumber(e, w, info.InvoiceNo, issuedAt.Format("2006/01/02 15:04"))

	vatJAParties(e, w, info)
	vatJAColumnHeader(e, w)
	vatItemLinesJA(e, w, info.Items, currency)
	e.Line(dashedLine(w))
	vatJASubtotal(e, w, info, currency)
	vatJATaxBreakdown(e, w, info, currency)
	vatJAGrandTotal(e, w, info, currency)
	vatJAPayment(e, info)
	vatJAFooter(e, w, storeName, info.SellerRegistrationNumber)
	e.FullCut()
	return e.Bytes()
}

// vatJAInvoiceNumber prints "No.{invoice_no}" flush-left with the issue time
// flush-right. Shared by the legacy renderer and emitVatInvoiceNumber's
// ja-branch.
func vatJAInvoiceNumber(e *escpos.Encoder, w int, invoiceNo, dateStr string) {
	noLine := "No." + invoiceNo
	if displayWidth(noLine)+1+displayWidth(dateStr) <= w {
		e.Line(noLine + spaces(w-displayWidth(noLine)-displayWidth(dateStr)) + dateStr)
	} else {
		e.Line(noLine)
		e.Line(spaces(w-displayWidth(dateStr)) + dateStr)
	}
}
