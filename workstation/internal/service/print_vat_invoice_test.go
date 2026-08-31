package service

import (
	"strings"
	"testing"
	"time"
)

// TestFormatVatInvoice_PerRateBlocks proves the VAT invoice renders per-rate
// breakdown blocks (plan-043 T4.4) instead of the single "Thue X%" line when the
// invoice carries a tax_breakdown, plus the T+13 registration number when set.
//
// Defined truth (decoded from Shift_JIS; ¥→\ on the JP head, but this invoice
// uses the đ VND prefix so amounts print bare):
//
//	Tam tinh                                   1,500
//	8%対象                          1,000 (内消費税 80)
//	10%対象                           500 (内消費税 50)
//	Tong cong                                  1,630
//	登録番号                             T1234567890123
func TestFormatVatInvoice_PerRateBlocks(t *testing.T) {
	info := VatInvoiceInfo{
		InvoiceNo:      "HN1-202607-00042",
		IssuedAt:       time.Date(2026, 7, 9, 10, 21, 0, 0, time.UTC),
		TaxCode:        "0312345678",
		CompanyName:    "ABC Foods",
		Subtotal:       1500,
		TaxAmount:      130,
		Total:          1630,
		CurrencyPrefix: "d",
		Locale:         "ja",
		Items: []VatInvoiceLine{
			{Name: "Bento", Quantity: 1, UnitPrice: 1000, LineTotal: 1000},
			{Name: "Bia", Quantity: 1, UnitPrice: 500, LineTotal: 500},
		},
		TaxBreakdown: []VatInvoiceTaxLine{
			{Rate: 8, Taxable: 1000, Tax: 80},
			{Rate: 10, Taxable: 500, Tax: 50},
		},
		SellerRegistrationNumber: "T1234567890123",
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48, Locale: "ja"}))

	for _, want := range []string{
		"8%対象", "1,000", "内消費税", "80",
		"10%対象", "500", "50",
		// Locale: "ja" → the 適格簡易請求書 layout. The seller's registration
		// number prints on the 発行 footer line as "登録番号 T…".
		"登録番号 T1234567890123",
	} {
		if !strings.Contains(text, want) {
			t.Errorf("VAT invoice per-rate block missing %q\n---\n%s", want, text)
		}
	}
	// The seller number must appear ONCE (the 発行 footer), never twice.
	if n := strings.Count(text, "T1234567890123"); n != 1 {
		t.Errorf("seller tax code printed %d times, want exactly 1\n---\n%s", n, text)
	}
	// The single-rate legacy line must not appear when per-rate blocks are present.
	if strings.Contains(text, "Thue 8 %") || strings.Contains(text, "Thue 10 %") {
		t.Errorf("per-rate invoice must not print the legacy single Thue line\n%s", text)
	}
}

// TestFormatVatInvoice_LegacyFallback proves an invoice with NO breakdown (old
// cloud / pre-migration) still prints the single "Thue X%" line, and no
// registration-number line when the number is empty.
func TestFormatVatInvoice_LegacyFallback(t *testing.T) {
	info := VatInvoiceInfo{
		InvoiceNo:      "HN1-202607-00043",
		CompanyName:    "ABC Foods",
		Subtotal:       1000,
		TaxAmount:      80,
		Total:          1080,
		TaxRatePercent: 8,
		CurrencyPrefix: "d",
		// The single "Thue X %" fallback is the Vietnamese layout — pin the
		// locale to "vi" (empty now normalizes to "ja" → the 適格簡易請求書 slip).
		Locale: "vi",
		Items:  []VatInvoiceLine{{Name: "Bento", Quantity: 1, UnitPrice: 1000, LineTotal: 1000}},
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48, Locale: "vi"}))

	if !strings.Contains(text, "Thue 8 %") {
		t.Errorf("legacy invoice must fall back to the single Thue line\n%s", text)
	}
	if strings.Contains(text, "対象") {
		t.Errorf("legacy invoice must NOT print per-rate 対象 blocks\n%s", text)
	}
	if strings.Contains(text, "登録番号") {
		t.Errorf("empty registration number → no 登録番号 line\n%s", text)
	}
}

// #1224 — an unregistered seller must not leave an empty "MST:" line on the
// slip. 免税事業者 in Japan and a not-yet-registered shop in Vietnam are both
// legitimate states, so a blank label would be worse than no label: it reads as
// a printing fault rather than an absent registration.
func TestFormatVatInvoice_NoSellerTaxCodeLine_WhenUnregistered(t *testing.T) {
	info := VatInvoiceInfo{
		InvoiceNo:      "HN1-202607-00043",
		TaxCode:        "0312345678",
		CompanyName:    "ABC Foods",
		Subtotal:       1000,
		Total:          1100,
		CurrencyPrefix: "d",
		Locale:         "vi",
		Items:          []VatInvoiceLine{{Name: "Bento", Quantity: 1, UnitPrice: 1000, LineTotal: 1000}},
		// SellerRegistrationNumber deliberately empty — also the shape the
		// backend sends when show_seller_registration_on_receipt is OFF.
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48, Locale: "vi", StoreName: "Quan Pho Ha Noi"}))

	// The buyer still has one, so a naive "contains MST" check would pass on the
	// wrong line — count instead.
	if n := strings.Count(text, "MST:"); n != 1 {
		t.Errorf("want exactly the BUYER's MST line, got %d MST lines\n---\n%s", n, text)
	}
	if strings.Contains(text, "MST:  \n") || strings.Contains(text, "MST:   \n") {
		t.Errorf("printed an empty seller MST line\n---\n%s", text)
	}
}

// #1224 — the seller block must carry name AND tax code, in that order, and the
// buyer block must be untouched. This is the symmetry a GTGT invoice requires:
// both parties identified in their own block.
func TestFormatVatInvoice_SellerBlockMirrorsBuyerBlock(t *testing.T) {
	info := VatInvoiceInfo{
		InvoiceNo:      "HN1-202607-00044",
		TaxCode:        "0312345678",
		CompanyName:    "ABC Foods",
		BillingAddress: "12 Le Loi, Q1",
		Subtotal:       1000,
		Total:          1100,
		CurrencyPrefix: "d",
		Locale:         "vi",
		Items:          []VatInvoiceLine{{Name: "Pho", Quantity: 1, UnitPrice: 1000, LineTotal: 1000}},
		// A Vietnamese seller tax code, the real shape for this document.
		SellerRegistrationNumber: "0101243150-001",
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48, Locale: "vi", StoreName: "Quan Pho Ha Noi"}))

	buyer := strings.Index(text, "NGUOI MUA")
	seller := strings.Index(text, "NGUOI BAN")
	sellerCode := strings.Index(text, "0101243150-001")
	buyerCode := strings.Index(text, "0312345678")

	if buyer < 0 || seller < 0 || sellerCode < 0 || buyerCode < 0 {
		t.Fatalf("missing a block or a tax code\n---\n%s", text)
	}
	// The seller's code has to fall INSIDE the seller block — after the heading,
	// and not adrift in the footer where it used to live.
	if !(buyer < buyerCode && buyerCode < seller && seller < sellerCode) {
		t.Errorf("tax codes are not each inside their own block (buyer=%d code=%d seller=%d code=%d)\n---\n%s",
			buyer, buyerCode, seller, sellerCode, text)
	}
	// Buyer block intact — no regression on the side that was already correct.
	for _, want := range []string{"Cty:  ABC Foods", "MST:  0312345678", "DC:   12 Le Loi, Q1"} {
		if !strings.Contains(text, want) {
			t.Errorf("buyer block lost %q\n---\n%s", want, text)
		}
	}
}

// #1304 tầng 4 (#1310) — a walk-in individual's personal name prints as the
// NGUOI MUA "Ten:" line, leading the buyer block, when supplied.
func TestFormatVatInvoice_PrintsBuyerName(t *testing.T) {
	info := VatInvoiceInfo{
		InvoiceNo:      "R00001",
		BuyerName:      "Nguyen Van A",
		Subtotal:       1000,
		Total:          1100,
		CurrencyPrefix: "d",
		Locale:         "vi",
		Items:          []VatInvoiceLine{{Name: "Pho", Quantity: 1, UnitPrice: 1000, LineTotal: 1000}},
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48, Locale: "vi", StoreName: "Quan Pho"}))

	if !strings.Contains(text, "Ten:  Nguyen Van A") {
		t.Errorf("buyer name line missing\n---\n%s", text)
	}
	// The name must sit inside the buyer block, before the seller block.
	buyer := strings.Index(text, "NGUOI MUA")
	name := strings.Index(text, "Nguyen Van A")
	seller := strings.Index(text, "NGUOI BAN")
	if !(buyer >= 0 && buyer < name && name < seller) {
		t.Errorf("buyer name not inside the NGUOI MUA block\n---\n%s", text)
	}
}

// #1304 tầng 4 — a pure khách-lẻ invoice with NOTHING typed (no name, company,
// MST or address) prints an underline on the Ten line so the cashier can
// hand-write the buyer, exactly like the non-official RedInvoiceDialog slip.
func TestFormatVatInvoice_BlankBuyerPrintsHandwriteUnderline(t *testing.T) {
	info := VatInvoiceInfo{
		InvoiceNo:      "R00002",
		Subtotal:       1000,
		Total:          1100,
		CurrencyPrefix: "d",
		Locale:         "vi",
		Items:          []VatInvoiceLine{{Name: "Pho", Quantity: 1, UnitPrice: 1000, LineTotal: 1000}},
		// BuyerName, CompanyName, TaxCode, BillingAddress all empty.
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48, Locale: "vi", StoreName: "Quan Pho"}))

	if !strings.Contains(text, "Ten:  _") {
		t.Errorf("blank khách-lẻ buyer must print a hand-write underline\n---\n%s", text)
	}
}

// #1304 tầng 4 — a COMPANY invoice (Cty/MST present, no personal name) must NOT
// gain an empty "Ten:" line: the buyer is identified by the company block, and
// the underline is only for the nothing-typed walk-in case. Guards against the
// new buyer line regressing the existing company-buyer path.
func TestFormatVatInvoice_CompanyBuyerHasNoEmptyNameLine(t *testing.T) {
	info := VatInvoiceInfo{
		InvoiceNo:      "R00003",
		TaxCode:        "0312345678",
		CompanyName:    "ABC Foods",
		Subtotal:       1000,
		Total:          1100,
		CurrencyPrefix: "d",
		Locale:         "vi",
		Items:          []VatInvoiceLine{{Name: "Pho", Quantity: 1, UnitPrice: 1000, LineTotal: 1000}},
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48, Locale: "vi", StoreName: "Quan Pho"}))

	if strings.Contains(text, "Ten:") {
		t.Errorf("company invoice must not print a Ten line\n---\n%s", text)
	}
	if !strings.Contains(text, "Cty:  ABC Foods") {
		t.Errorf("company invoice lost its Cty line\n---\n%s", text)
	}
}

// #1225 — a topping-surcharged line has to account for its own arithmetic.
//
// Without the toppings printed, the row reads `qty × unit_price` on the left and
// a LARGER line_total on the right, with nothing on the slip to explain the
// difference. A customer checking their own invoice cannot reconcile it, and on
// a by_items split this invoice IS the guest's itemisation of what they paid for.
func TestFormatVatInvoice_PrintsToppingsUnderTheLine(t *testing.T) {
	info := VatInvoiceInfo{
		InvoiceNo:      "HN1-202607-00045",
		TaxCode:        "0312345678",
		CompanyName:    "ABC Foods",
		Subtotal:       1300,
		TaxAmount:      130,
		Total:          1430,
		CurrencyPrefix: "d",
		Locale:         "vi",
		Items: []VatInvoiceLine{{
			Name: "Pho bo", Quantity: 1, UnitPrice: 1000, LineTotal: 1300,
			Toppings: []VatInvoiceTopping{
				{Name: "Them thit", Quantity: 1, UnitPrice: 200},
				{Name: "Trung", Quantity: 2, UnitPrice: 50},
			},
		}},
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48, Locale: "vi", StoreName: "Quan Pho"}))

	for _, want := range []string{"Pho bo", "Them thit", "200", "Trung", "100"} {
		if !strings.Contains(text, want) {
			t.Errorf("VAT invoice missing %q\n---\n%s", want, text)
		}
	}
	// ×2 of a 50 topping prints its LINE total, not its unit price — otherwise
	// the arithmetic still does not close.
	if strings.Contains(text, "Trung") && !strings.Contains(text, "×2") {
		t.Errorf("a multi-unit topping must show its quantity\n---\n%s", text)
	}
	// The three parts must reconcile to the printed line total: 1000 + 200 + 100.
	if got := 1000 + 200 + 2*50; got != info.Items[0].LineTotal {
		t.Fatalf("fixture is inconsistent: parts %d != line_total %d", got, info.Items[0].LineTotal)
	}
}

// A line with no toppings must print exactly as before — this is the majority of
// every invoice, and the change must not add a stray blank row to all of them.
func TestFormatVatInvoice_NoToppingRowsWhenThereAreNone(t *testing.T) {
	info := VatInvoiceInfo{
		InvoiceNo: "HN1-202607-00046", TaxCode: "0312345678", CompanyName: "ABC Foods",
		Subtotal: 1000, Total: 1100, CurrencyPrefix: "d", Locale: "vi",
		Items: []VatInvoiceLine{{Name: "Pho bo", Quantity: 1, UnitPrice: 1000, LineTotal: 1000}},
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48, Locale: "vi", StoreName: "Quan Pho"}))

	if strings.Contains(text, "——") {
		t.Errorf("printed a topping marker on a line that has no toppings\n---\n%s", text)
	}
}

// #1169 — the chosen option/variant and the per-line note print indented under
// the món, so a GTGT invoice states exactly what was supplied.
func TestFormatVatInvoice_PrintsVariantAndNote(t *testing.T) {
	info := VatInvoiceInfo{
		InvoiceNo:      "HN1-202607-00050",
		TaxCode:        "0312345678",
		CompanyName:    "ABC Foods",
		Subtotal:       30000,
		Total:          30000,
		CurrencyPrefix: "d",
		Locale:         "vi",
		Items: []VatInvoiceLine{{
			Name: "Ca phe sua", Quantity: 1, UnitPrice: 30000, LineTotal: 30000,
			VariantName: "Size L", Note: "it duong",
		}},
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48, Locale: "vi", StoreName: "Quan Cafe"}))

	for _, want := range []string{"Ca phe sua", "-- Size L", "Ghi chu: it duong"} {
		if !strings.Contains(text, want) {
			t.Errorf("VAT invoice missing %q\n---\n%s", want, text)
		}
	}
}

// #1169 — a by-amount split share lists the FULL order but charges only this
// guest's part, so the footer shows "Tong mon" (full order) + "Khach thanh toan
// (phan chia)" + the "(Hoa don phan chia)" note, and never the normal Tam tinh /
// Tong cong labels.
func TestFormatVatInvoice_AmountSplitFooter(t *testing.T) {
	info := VatInvoiceInfo{
		InvoiceNo:      "HN1-202607-00051",
		CurrencyPrefix: "d",
		Locale:         "vi",
		// Two dishes worth 80,000; this guest paid only 40,000.
		Subtotal:      40000,
		Total:         40000,
		IsAmountSplit: true,
		Items: []VatInvoiceLine{
			{Name: "Pho bo", Quantity: 1, UnitPrice: 50000, LineTotal: 50000},
			{Name: "Ca phe sua da", Quantity: 1, UnitPrice: 30000, LineTotal: 30000},
		},
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48, Locale: "vi", StoreName: "Quan Pho"}))

	for _, want := range []string{"Pho bo", "Ca phe sua da", "Tong mon", "Khach thanh toan (phan chia)", "(Hoa don phan chia)"} {
		if !strings.Contains(text, want) {
			t.Errorf("amount-split VAT invoice missing %q\n---\n%s", want, text)
		}
	}
	// The whole-order worth (80,000) headlines "Tong mon"; the normal
	// single-invoice footer labels must NOT appear.
	if strings.Contains(text, "Tam tinh") {
		t.Errorf("amount-split VAT invoice must not print Tam tinh\n---\n%s", text)
	}
	if strings.Contains(text, "Tong cong") {
		t.Errorf("amount-split VAT invoice must not print Tong cong\n---\n%s", text)
	}
	if !strings.Contains(text, "80,000") {
		t.Errorf("amount-split VAT invoice must headline the whole-order 80,000\n---\n%s", text)
	}
}

// A reconciling whole-order invoice (IsAmountSplit false) keeps the standard
// footer — the share branch must not fire when Cloud did not flag it, even if
// tax-inclusive pricing makes Σ món differ from the subtotal.
func TestFormatVatInvoice_WholeOrderKeepsStandardFooter(t *testing.T) {
	info := VatInvoiceInfo{
		InvoiceNo: "HN1-202607-00052", CurrencyPrefix: "d", Locale: "vi",
		Subtotal: 80000, Total: 80000,
		Items: []VatInvoiceLine{
			{Name: "Pho bo", Quantity: 1, UnitPrice: 50000, LineTotal: 50000},
			{Name: "Ca phe", Quantity: 1, UnitPrice: 30000, LineTotal: 30000},
		},
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48, Locale: "vi", StoreName: "Quan Pho"}))

	if !strings.Contains(text, "Tam tinh") || !strings.Contains(text, "Tong cong") {
		t.Errorf("whole-order invoice must keep Tam tinh / Tong cong\n---\n%s", text)
	}
	if strings.Contains(text, "phan chia") {
		t.Errorf("whole-order invoice must not print a share footer\n---\n%s", text)
	}
}
