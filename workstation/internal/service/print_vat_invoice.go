package service

import (
	"strings"
	"time"
	"unicode/utf8"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// VatInvoiceInfo carries the snapshot needed to render a thermal "HOA DON
// GIA TRI GIA TANG" slip. Mirrors the customer_invoices schema (plan-038
// T11.1) and is filled either from local SQLite (when the workstation
// already pulled the invoice DOWN) or from a force-pull on demand.
type VatInvoiceInfo struct {
	InvoiceNo   string
	IssuedAt    time.Time
	TaxCode     string
	CompanyName string
	// BuyerName is the walk-in buyer's personal name (#1304 tầng 4 / #1310),
	// distinct from CompanyName. Printed as the NGUOI MUA "Ten:" line; when the
	// whole buyer block is blank (khách lẻ, nothing typed) an underline is
	// printed for the cashier to hand-write, mirroring the non-official
	// RedInvoiceDialog slip.
	BuyerName      string
	BillingAddress string
	Subtotal       int
	TaxAmount      int
	Total          int
	TaxRatePercent int    // 8 → "8 %" — legacy single-rate label (fallback only)
	CurrencyPrefix string // "¥" or "đ"
	Items          []VatInvoiceLine
	PaymentMethod  string // headline payment method ("cash"/"card"/…)
	Status         string // "issued" | "voided" | "reprinted"
	ReprintNumber  int    // 0/1 = first print; ≥2 prints "Bản in #N"

	// plan-043 T4.4 — per-rate breakdown [{rate, taxable, tax}] frozen at issue,
	// mirrored from the cloud invoice. When non-empty the footer prints one
	// "{rate}%対象 {taxable} (内消費税 {tax})" block per rate instead of the single
	// "Thue X%" line. SellerRegistrationNumber (T+13) prints only when set.
	TaxBreakdown             []VatInvoiceTaxLine
	SellerRegistrationNumber string
	Locale                   string // "ja" | "en" | "vi" — per-rate label language

	// #1169 — set when this invoice is an equal/by-amount split SHARE: it lists
	// the whole order's món but charges only this guest's part. Cloud stamps it
	// (items_json `amount_split`), never derived here — tax-inclusive pricing and
	// per-line rounding make a Σ-line-vs-subtotal guess unreliable. Drives the
	// "Tổng món / Khách thanh toán (phần chia)" footer.
	IsAmountSplit bool
}

// VatInvoiceTaxLine is one per-rate breakdown row on the VAT invoice.
type VatInvoiceTaxLine struct {
	Rate    float64
	Taxable int
	Tax     int
}

// VatInvoiceLine is one row of items_json — frozen at issue time.
type VatInvoiceLine struct {
	Name      string
	Quantity  int
	UnitPrice int
	LineTotal int
	// #1169 — the chosen option/variant label (e.g. "Size L / Nóng"), composed
	// from the SKU's option values Cloud-side (ProductSku::variant_label) and
	// printed on its own indented line under the món name. Empty for a simple,
	// non-variant SKU.
	VariantName string
	// #1169 — the per-line kitchen/customer note (Ghi chú), printed indented
	// after the toppings. Empty when the line carries no note.
	Note string
	// #1225 — the chosen toppings for this line. A GTGT invoice has to list the
	// goods actually supplied, and a topping-surcharged line otherwise prints as
	// `unit_price × qty ≠ line_total` with nothing to explain the difference.
	// This matters most on a by_items split, where the invoice IS the guest's
	// itemisation and the surcharge is part of what they paid for.
	Toppings []VatInvoiceTopping
}

// VatInvoiceTopping is one chosen topping under a VAT invoice line.
type VatInvoiceTopping struct {
	Name      string
	Quantity  int
	UnitPrice int
}

// itemLinesSum totals the món line_totals. On an equal/by-amount split invoice
// (#1169) this is the WHOLE order's worth — deliberately greater than the
// charged Subtotal, which is only this guest's share — and headlines "Tổng món".
func (v VatInvoiceInfo) itemLinesSum() int {
	s := 0
	for _, it := range v.Items {
		s += it.LineTotal
	}
	return s
}

// vatItemLines renders the item-table body of a GTGT invoice — one row per line
// (name / SL / Đơn giá / Thành tiền), then indented under it the chosen option
// (biến thể), each topping, and the per-line note (Ghi chú). #1169: shared
// verbatim by the legacy FormatVatInvoice and the template emitVatItems so the
// two renderers cannot drift — the golden gate compares them byte-for-byte.
func vatItemLines(e *escpos.Encoder, w, nameWidth int, items []VatInvoiceLine) {
	for _, it := range items {
		name := it.Name
		if name == "" {
			name = "-"
		}
		if utf8.RuneCountInString(name) > nameWidth {
			r := []rune(name)
			name = string(r[:nameWidth])
		}
		e.Line(padRight(name, nameWidth) +
			padLeft(itoa(it.Quantity), 3) +
			padLeft(formatPrice(it.UnitPrice), 11) +
			padLeft(formatPrice(it.LineTotal), 11))

		// #1169 — the chosen option/variant (Size L / Nóng) beside the món.
		if v := strings.TrimSpace(it.VariantName); v != "" {
			vatIndentLine(e, w, "   -- "+v)
		}

		for _, t := range it.Toppings {
			tName := t.Name
			if tName == "" {
				continue
			}
			label := "   —— " + tName
			if t.Quantity > 1 {
				label += " ×" + itoa(t.Quantity)
			}
			price := formatPrice(t.UnitPrice * maxInt(t.Quantity, 1))
			labelWidth := w - utf8.RuneCountInString(price)
			if utf8.RuneCountInString(label) > labelWidth {
				r := []rune(label)
				label = string(r[:maxInt(labelWidth, 0)])
			}
			e.Line(padRight(label, labelWidth) + price)
		}

		// #1169 — the per-line note last (Ghi chú).
		if n := strings.TrimSpace(it.Note); n != "" {
			vatIndentLine(e, w, "   Ghi chu: "+n)
		}
	}
}

// vatIndentLine prints one indented sub-line (option / note), truncated to the
// paper width so it never wraps.
func vatIndentLine(e *escpos.Encoder, w int, s string) {
	if utf8.RuneCountInString(s) > w {
		r := []rune(s)
		s = string(r[:w])
	}
	e.Line(s)
}

// FormatVatInvoice renders the VAT invoice thermal slip. v1 explicit
// non-goals (DESIGN Q-decision-11): NOT a CQT-signed e-invoice; the
// footer carries a legal-notice disclaimer.
//
// Layout (42-col paper):
//
//	Store Name             HOA DON GIA TRI GIA TANG
//	(SubName)
//	Bản in #N   <only when reprint>
//	So HD: HN1-202606-00042                2026/06/20 10:21
//
//	NGUOI MUA
//	Cty:  ABC Foods
//	MST:  0312345678
//	DC:   123 Le Loi, Q.1, TP.HCM
//
//	NGUOI BAN
//	Store name
//	(brand line, optional)
//
//	- - - - - - - - - - - - - - - - - - - -
//	San pham                 SL    Don gia    Thanh tien
//	- - - - - - - - - - - - - - - - - - - -
//	Pho bo                    2     50,000      100,000
//	Cafe sua da               1     30,000       30,000
//	- - - - - - - - - - - - - - - - - - - -
//	Tam tinh                                   130,000
//	Thue 8 %                                    10,400
//	Tong cong                                  140,400
//	Hinh thuc TT: cash
//
//	Ben mua                              Ben ban
//	(signature)                          (signature)
//
//	HOA DON THAM CHIEU NOI BO
//	KHONG THAY THE HDDT CUA CO QUAN THUE
//
// printCountryPicksJapaneseInvoice quyết định tờ giấy là 適格簡易請求書 hay hoá
// đơn GTGT Việt Nam (#1493).
//
// Ba trạng thái, và trạng thái thứ ba là chỗ dễ làm hỏng nhất:
//
//	"JP"        → chứng từ Nhật. Trục ĐÚNG.
//	"VN" | …    → chứng từ Việt, kể cả khi thu ngân đang để giao diện tiếng Nhật.
//	""          → CHƯA BIẾT ⇒ giữ nguyên nhánh locale cũ.
//
// Vế thứ ba không phải sự cẩn thận thừa. `operating_country` không bao giờ rỗng
// theo thiết kế #1490 (resolver fail-safe về JP), nhưng máy trạm vẫn gặp key
// THIẾU HẲN: bản chưa pull lần nào, hoặc Cloud cũ hơn #1490. Mặc định một quốc
// gia ở đó sẽ làm một quán mất chứng từ luật định giữa chừng — hỏng nặng hơn hẳn
// việc chọn sai bằng locale, vốn ít nhất đang chạy được cho quán Nhật hôm nay.
func printCountryPicksJapaneseInvoice(operatingCountry, locale string) bool {
	switch strings.ToUpper(strings.TrimSpace(operatingCountry)) {
	case "JP":
		return true
	case "":
		// Chưa biết quốc gia — đường lui: hành vi trước #1493, từng chữ.
		return normalizePrintLocale(locale) == "ja"
	default:
		return false
	}
}

func FormatVatInvoice(info VatInvoiceInfo, config PrintJobConfig) []byte {
	// #1493 — CHỨNG TỪ đi theo QUỐC GIA của shop, không theo ngôn ngữ thu ngân.
	//
	// Quán Nhật in 適格簡易請求書 (nhãn + tên món tiếng Nhật, không có khối chữ ký
	// 買い手/売り手); quán Việt in hoá đơn GTGT. Trước bản này điều kiện là
	// `normalizePrintLocale(info.Locale) == "ja"`, nên hai ô trong bảng #1459 sai:
	// quán VN với giao diện tiếng Nhật ra chứng từ Nhật, và quán JP với giao diện
	// tiếng Việt ra chứng từ Việt.
	//
	// KHÔNG viết lại layout — `formatVatInvoiceJA` giữ nguyên từng byte. Chỉ đổi
	// điều kiện chọn (cạm bẫy TR-40 mà #1492 đã dặn).
	if printCountryPicksJapaneseInvoice(config.OperatingCountry, info.Locale) {
		return formatVatInvoiceJA(info, config)
	}
	w := config.PaperWidth
	if w == 0 {
		w = 48
	}

	currency := info.CurrencyPrefix
	if currency == "" {
		currency = config.cur()
	}

	e := escpos.New()
	e.SetLeftMargin(config.leftMargin(w))
	e.Align(escpos.AlignLeft)

	storeName := config.StoreName
	if storeName == "" {
		storeName = "Store"
	}
	// #2000 bước 6 — cùng bộ field mà đường template dùng, không gõ lại.
	storeFields := StoreFieldsForKind("vat_invoice")
	for _, line := range StoreDetailValues(config, storeFields, true) {
		e.Line(line)
	}

	title := "HOA DON GIA TRI GIA TANG"
	if w < 42 {
		title = "HOA DON GTGT"
	}
	titleW := utf8.RuneCountInString(title)
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

	for _, line := range StoreDetailValues(config, storeFields, false) {
		e.Line(line)
	}

	// plan-052 P-10b — the locked reprint mark, same bytes on every kind.
	printReprintMarker(e, w, info.ReprintNumber, config.Locale)

	issuedAt := info.IssuedAt
	if issuedAt.IsZero() {
		issuedAt = printNow()
	}
	noLine := "So HD: " + info.InvoiceNo
	dateStr := issuedAt.Format("2006/01/02 15:04")
	if utf8.RuneCountInString(noLine)+1+utf8.RuneCountInString(dateStr) <= w {
		gap := w - utf8.RuneCountInString(noLine) - utf8.RuneCountInString(dateStr)
		e.Line(noLine + spaces(gap) + dateStr)
	} else {
		e.Line(noLine)
		e.Line(spaces(w-utf8.RuneCountInString(dateStr)) + dateStr)
	}

	e.Feed(1)
	e.Bold(true)
	e.Line("NGUOI MUA")
	e.Bold(false)
	buyer := strings.TrimSpace(info.BuyerName)
	company := strings.TrimSpace(info.CompanyName)
	taxCode := strings.TrimSpace(info.TaxCode)
	billing := strings.TrimSpace(info.BillingAddress)
	// #1304 tầng 4 (#1310) — the walk-in buyer's personal name leads the block
	// (Họ tên người mua precedes Tên đơn vị on a GTGT invoice). Blank on a pure
	// khách-lẻ invoice (no name, company, MST or address typed) → an underline so
	// the cashier hand-writes it, exactly like the non-official RedInvoiceDialog
	// slip. A company invoice keeps its Cty/MST/DC lines and prints no empty name
	// line, so this adds nothing to the existing company-buyer path.
	if buyer != "" {
		e.Line("Ten:  " + buyer)
	} else if company == "" && taxCode == "" && billing == "" {
		e.Line("Ten:  " + strings.Repeat("_", w-6))
	}
	if company != "" {
		e.Line("Cty:  " + company)
	}
	if taxCode != "" {
		e.Line("MST:  " + taxCode)
	}
	if billing != "" {
		e.Line("DC:   " + billing)
	}

	e.Feed(1)
	e.Bold(true)
	e.Line("NGUOI BAN")
	e.Bold(false)
	e.Line(storeName)
	// #1224 — the seller's tax code belongs HERE, beside the seller's name and
	// symmetrical with the buyer's block above, because that is what a
	// Vietnamese GTGT invoice is: both parties identified together. It used to
	// print in the footer under "So dang ky", which read as an unrelated
	// afterthought several lines below the totals.
	//
	// The label is "MST", the same one the buyer's line uses, and it is hard
	// coded rather than resolved from a country the way the issue suggested:
	// this whole renderer IS the Vietnamese document. Every heading in it is
	// fixed Vietnamese — NGUOI MUA / NGUOI BAN / Cty / DC / Tam tinh / Tong cong
	// / Ben mua / Ben ban. A Japanese shop never prints this slip; it prints the
	// 適格請求書 receipt, which keeps 登録番号 and is a different renderer. So
	// there is nothing to plumb: no operating_country field, no sync change.
	//
	// Absent registration stays absent (Q5): a 免税事業者 / unregistered shop
	// must not print an empty "MST:" line.
	if reg := strings.TrimSpace(info.SellerRegistrationNumber); reg != "" {
		e.Line("MST:  " + reg)
	}

	e.Feed(1)
	e.Line(dashedLine(w))
	e.Feed(1)
	// Header row — name (variable) / qty (3) / unit (10) / total (12)
	for _, line := range vatColumnHeaderLines(w) {
		e.Line(line)
	}
	e.Line(dashedLine(w))

	// #1169 — item rows + option/variant + toppings + note, shared byte-for-byte
	// with the template renderer (emitVatItems) via vatItemLines.
	vatItemLines(e, w, w-vatNumericColumns, info.Items)

	e.Line(dashedLine(w))

	footerRow := func(label, value string) {
		gap := w - utf8.RuneCountInString(label) - utf8.RuneCountInString(value)
		if gap < 1 {
			gap = 1
		}
		e.Line(label + spaces(gap) + value)
	}
	// #1169 — an equal/by-amount split share lists the whole order but charges
	// only this guest's part, so Σ món > Subtotal. Show "Tổng món" (the full
	// order) vs "Khách thanh toán (phần chia)" (what this guest pays) instead of
	// the normal Tam tinh / tax / Tong cong footer. A whole-order or by-items
	// invoice reconciles exactly and keeps the standard footer unchanged.
	partialShare := info.IsAmountSplit
	if partialShare {
		footerRow("Tong mon", currency+formatPrice(info.itemLinesSum()))
	} else {
		footerRow("Tam tinh", currency+formatPrice(info.Subtotal))
		// Per-rate blocks (plan-043 T4.4) when the invoice carries a breakdown;
		// otherwise fall back to the legacy single "Thue X%" line.
		//
		// #2057 — this one block measures and justifies by DISPLAY width, unlike
		// the rune-counted footer rows around it: its labels follow the
		// invoice's locale (vatRateRowsFor), so a ja-locale invoice carries
		// full-width glyphs here and rune counting overflowed every paper width
		// by 6 columns. Must stay in lockstep with emitVatTaxBreakdown (TR-40).
		if len(info.TaxBreakdown) > 0 {
			for _, row := range vatRateRowsFor(w, displayWidth, "", info, currency) {
				e.Line(justifyTaxLine(row[0], row[1], w))
			}
		} else if info.TaxAmount > 0 {
			label := "Thue"
			if info.TaxRatePercent > 0 {
				label = "Thue " + itoa(info.TaxRatePercent) + " %"
			}
			footerRow(label, currency+formatPrice(info.TaxAmount))
		}
	}
	e.Bold(true)
	if partialShare {
		footerRow("Khach thanh toan (phan chia)", currency+formatPrice(info.Total))
	} else {
		footerRow("Tong cong", currency+formatPrice(info.Total))
	}
	e.Bold(false)
	if partialShare {
		e.Line("(Hoa don phan chia)")
	}
	// #1224 — the seller registration number moved up into the NGUOI BAN block.
	// Printing it here as well would put the same tax code on the slip twice.
	if pm := strings.TrimSpace(info.PaymentMethod); pm != "" {
		footerRow("Hinh thuc TT", pm)
	}

	e.Feed(2)
	// Signature columns — "Ben mua" left, "Ben ban" right.
	left := "Ben mua"
	right := "Ben ban"
	gap := w - utf8.RuneCountInString(left) - utf8.RuneCountInString(right)
	if gap < 1 {
		gap = 1
	}
	e.Line(left + spaces(gap) + right)
	e.Feed(3)
	half := (w - 1) / 2
	e.Line(strings.Repeat("_", half) + " " + strings.Repeat("_", w-half-1))

	e.Feed(2)
	e.Align(escpos.AlignCenter)
	e.Line("HOA DON THAM CHIEU NOI BO")
	for _, line := range vatDisclaimerLines(w) {
		e.Line(line)
	}
	e.Align(escpos.AlignLeft)
	e.Feed(3)
	e.FullCut()
	return e.Bytes()
}

// FormatVoidNotice renders the "BIEN BAN HUY HOA DON" slip, auto-printed
// when an invoice's status flips to voided during a sync DOWN tick
// (plan-038 T11.5).
func FormatVoidNotice(info VatInvoiceInfo, voidReason string, voidedAt time.Time, config PrintJobConfig) []byte {
	w := config.PaperWidth
	if w == 0 {
		w = 48
	}
	e := escpos.New()
	e.SetLeftMargin(config.leftMargin(w))
	e.Align(escpos.AlignCenter)
	e.Bold(true)
	e.Line("BIEN BAN HUY HOA DON")
	e.Bold(false)
	e.Feed(1)
	e.Align(escpos.AlignLeft)
	e.Line("So HD bi huy: " + info.InvoiceNo)
	if !voidedAt.IsZero() {
		e.Line("Thoi diem huy: " + voidedAt.Format("2006/01/02 15:04"))
	}
	if reason := strings.TrimSpace(voidReason); reason != "" {
		e.Line("Ly do: " + reason)
	}
	e.Feed(2)
	e.Align(escpos.AlignCenter)
	e.Line("KHACH HANG NHAN BIET HOA DON DA HUY")
	e.Align(escpos.AlignLeft)
	e.Feed(3)
	e.FullCut()
	return e.Bytes()
}

// padLeft is the right-aligned variant of padRight. Used in invoice
// item rows where numeric columns need to align on the right edge.
func padLeft(s string, width int) string {
	dw := displayWidth(s)
	if dw >= width {
		return s
	}
	return spaces(width-dw) + s
}

// maxInt is a local helper — Go's builtin max is generic and this file targets
// the same toolchain as the rest of the printer package.
func maxInt(a, b int) int {
	if a > b {
		return a
	}

	return b
}
