package service

import (
	"strings"
	"testing"
)

// A cash payment slip must show what the customer handed over and the change.
func TestFormatPaidTicket_ShowsTenderedAndChange(t *testing.T) {
	o := sampleOrder()
	o.PaidAmount = 1780
	cfg := PrintJobConfig{StoreName: "Store", PaperWidth: 48, TaxRate: 10, Locale: "vi"}

	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{
		PaymentMethod: "cash",
		AmountPaid:    1780,
		Tendered:      2000,
		Change:        220,
	})

	// #1056 — labels are locale-driven (printLabelsFor); Locale "vi" renders
	// the ASCII Vietnamese catalog, not お預かり/お釣り.
	text := decodeSJIS(t, out)
	if !strings.Contains(text, "Tien khach dua") {
		t.Error("paid slip missing tendered (Tien khach dua) line")
	}
	if !strings.Contains(text, "Tien thoi lai") {
		t.Error("paid slip missing change (Tien thoi lai) line")
	}
}

// A non-cash slip (no tendered recorded) must not print the tendered/change rows.
func TestFormatPaidTicket_OmitsTenderedWhenZero(t *testing.T) {
	o := sampleOrder()
	cfg := PrintJobConfig{PaperWidth: 48}

	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{
		PaymentMethod: "qr",
		AmountPaid:    1780,
	})

	if strings.Contains(decodeSJIS(t, out), "お預かり") {
		t.Error("non-cash slip must not show a tendered line")
	}
}

// The red invoice carries the red-invoice title, the customer name, and (like
// the receipt) the tendered/change.
func TestFormatRedInvoiceTicket_TitleAndNamedCustomer(t *testing.T) {
	o := sampleOrder()
	cfg := PrintJobConfig{PaperWidth: 48, TaxRate: 10, Locale: "vi"}

	out := FormatRedInvoiceTicket(o, o.Items, cfg, PaymentSlipInfo{
		PaymentMethod: "cash",
		AmountPaid:    1780,
		Tendered:      2000,
		Change:        220,
		CustomerName:  "Nguyen Van A",
	})

	// #1056 — Locale "vi" renders the Vietnamese catalog.
	text := decodeSJIS(t, out)
	// #2062 — the slip no longer calls itself a statutory invoice. It is not one
	// (no number, no form code, no signature, no tax-authority code since #1779),
	// so the heading says what it is: a payment receipt.
	if !strings.Contains(text, "PHIEU THANH TOAN") {
		t.Error("red invoice missing PHIEU THANH TOAN title")
	}
	if strings.Contains(text, "HOA DON") {
		t.Error("#2062: the slip must not name itself a statutory invoice")
	}
	// #2547 — câu miễn trừ ĐÃ GỠ khỏi hoá đơn đỏ theo quyết định sản phẩm.
	// Assertion đảo chiều chứ không xoá: khối `vat_disclaimer` từng là `locked`
	// với ba đường chặn (REQUIRED_BLOCK_MISSING · LOCKED_BLOCK_DISABLED ·
	// LOCKED_BLOCK_REORDERED), nên nếu nó quay lại thì đó là một khối locked
	// mọc lại — phải đỏ, không phải im lặng. Hoá đơn GTGT (`vat_invoice`) vẫn
	// giữ bản sao riêng của câu này trong emitter `footer_text` của nó.
	if strings.Contains(text, "KHONG THAY THE HDDT") {
		t.Error("#2547: hoá đơn đỏ không được in câu miễn trừ nữa")
	}
	if !strings.Contains(text, "Khach hang: Nguyen Van A") {
		t.Error("red invoice missing named customer line")
	}
	if !strings.Contains(text, "Tien khach dua") {
		t.Error("red invoice missing tendered line")
	}
}

// A red invoice with no name still prints a blank customer line to hand-write it.
func TestFormatRedInvoiceTicket_BlankCustomerLineForHandwriting(t *testing.T) {
	o := sampleOrder()
	cfg := PrintJobConfig{PaperWidth: 48, Locale: "vi"}

	out := FormatRedInvoiceTicket(o, o.Items, cfg, PaymentSlipInfo{
		PaymentMethod: "cash",
		AmountPaid:    1780,
	})

	if !strings.Contains(decodeSJIS(t, out), "Khach hang: __") {
		t.Error("red invoice with no name must print a blank underline customer line")
	}
}
