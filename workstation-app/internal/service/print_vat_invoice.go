package service

import (
	"fmt"
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
	InvoiceNo      string
	IssuedAt       time.Time
	TaxCode        string
	CompanyName    string
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
func FormatVatInvoice(info VatInvoiceInfo, config PrintJobConfig) []byte {
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
	if strings.TrimSpace(config.StoreSubName) != "" {
		e.Line(config.StoreSubName)
	}

	if info.ReprintNumber >= 2 {
		reprint := "BAN IN #" + itoa(info.ReprintNumber)
		e.Line(spaces(w-utf8.RuneCountInString(reprint)) + reprint)
	}

	issuedAt := info.IssuedAt
	if issuedAt.IsZero() {
		issuedAt = time.Now()
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
	if v := strings.TrimSpace(info.CompanyName); v != "" {
		e.Line("Cty:  " + v)
	}
	if v := strings.TrimSpace(info.TaxCode); v != "" {
		e.Line("MST:  " + v)
	}
	if v := strings.TrimSpace(info.BillingAddress); v != "" {
		e.Line("DC:   " + v)
	}

	e.Feed(1)
	e.Bold(true)
	e.Line("NGUOI BAN")
	e.Bold(false)
	e.Line(storeName)

	e.Feed(1)
	e.Line(dashedLine(w))
	e.Feed(1)
	// Header row — name (variable) / qty (3) / unit (10) / total (12)
	header := padRight("San pham", w-25) + padLeft("SL", 3) + padLeft("Don gia", 11) + padLeft("Thanh tien", 11)
	e.Line(header)
	e.Line(dashedLine(w))

	for _, it := range info.Items {
		name := it.Name
		if name == "" {
			name = "-"
		}
		qty := itoa(it.Quantity)
		unit := formatPrice(it.UnitPrice)
		line := formatPrice(it.LineTotal)
		// Truncate name to keep right-side columns aligned.
		nameWidth := w - 25
		if utf8.RuneCountInString(name) > nameWidth {
			r := []rune(name)
			name = string(r[:nameWidth])
		}
		row := padRight(name, nameWidth) + padLeft(qty, 3) + padLeft(unit, 11) + padLeft(line, 11)
		e.Line(row)
	}

	e.Line(dashedLine(w))

	footerRow := func(label, value string) {
		gap := w - utf8.RuneCountInString(label) - utf8.RuneCountInString(value)
		if gap < 1 {
			gap = 1
		}
		e.Line(label + spaces(gap) + value)
	}
	footerRow("Tam tinh", currency+formatPrice(info.Subtotal))
	// Per-rate blocks (plan-043 T4.4) when the invoice carries a breakdown;
	// otherwise fall back to the legacy single "Thue X%" line.
	if len(info.TaxBreakdown) > 0 {
		labels := taxLabelsFor(info.Locale)
		for _, tl := range info.TaxBreakdown {
			footerRow(
				fmt.Sprintf(labels.RateTarget, formatRatePercent(tl.Rate)),
				currency+formatPrice(tl.Taxable)+" ("+labels.IncludedTax+" "+currency+formatPrice(tl.Tax)+")",
			)
		}
	} else if info.TaxAmount > 0 {
		label := "Thue"
		if info.TaxRatePercent > 0 {
			label = "Thue " + itoa(info.TaxRatePercent) + " %"
		}
		footerRow(label, currency+formatPrice(info.TaxAmount))
	}
	e.Bold(true)
	footerRow("Tong cong", currency+formatPrice(info.Total))
	e.Bold(false)
	// T+13 seller registration number — printed only when present (Q5).
	if reg := strings.TrimSpace(info.SellerRegistrationNumber); reg != "" {
		footerRow(taxLabelsFor(info.Locale).RegistrationNumber, reg)
	}
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
	e.Line("KHONG THAY THE HDDT CUA CO QUAN THUE")
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
