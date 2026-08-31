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
		"登録番号", "T1234567890123",
	} {
		if !strings.Contains(text, want) {
			t.Errorf("VAT invoice per-rate block missing %q\n---\n%s", want, text)
		}
	}
	// The single-rate legacy line must not appear when blocks are present.
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
		Items:          []VatInvoiceLine{{Name: "Bento", Quantity: 1, UnitPrice: 1000, LineTotal: 1000}},
	}
	text := decodeSJIS(t, FormatVatInvoice(info, PrintJobConfig{PaperWidth: 48}))

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
