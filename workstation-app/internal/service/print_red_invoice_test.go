package service

import (
	"strings"
	"testing"
)

// A cash payment slip must show what the customer handed over and the change.
func TestFormatPaidTicket_ShowsTenderedAndChange(t *testing.T) {
	o := sampleOrder()
	o.PaidAmount = 1780
	cfg := PrintJobConfig{StoreName: "Store", PaperWidth: 48, TaxRate: 10}

	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{
		PaymentMethod: "cash",
		AmountPaid:    1780,
		Tendered:      2000,
		Change:        220,
	})

	text := decodeSJIS(t, out)
	if !strings.Contains(text, "お預かり") {
		t.Error("paid slip missing tendered (お預かり) line")
	}
	if !strings.Contains(text, "お釣り") {
		t.Error("paid slip missing change (お釣り) line")
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
	cfg := PrintJobConfig{PaperWidth: 48, TaxRate: 10}

	out := FormatRedInvoiceTicket(o, o.Items, cfg, PaymentSlipInfo{
		PaymentMethod: "cash",
		AmountPaid:    1780,
		Tendered:      2000,
		Change:        220,
		CustomerName:  "Nguyen Van A",
	})

	text := decodeSJIS(t, out)
	if !strings.Contains(text, "領収書") {
		t.Error("red invoice missing 領収書 title")
	}
	if !strings.Contains(text, "お客様: Nguyen Van A") {
		t.Error("red invoice missing named customer line")
	}
	if !strings.Contains(text, "お預かり") {
		t.Error("red invoice missing tendered line")
	}
}

// A red invoice with no name still prints a blank customer line to hand-write it.
func TestFormatRedInvoiceTicket_BlankCustomerLineForHandwriting(t *testing.T) {
	o := sampleOrder()
	cfg := PrintJobConfig{PaperWidth: 48}

	out := FormatRedInvoiceTicket(o, o.Items, cfg, PaymentSlipInfo{
		PaymentMethod: "cash",
		AmountPaid:    1780,
	})

	if !strings.Contains(decodeSJIS(t, out), "お客様: __") {
		t.Error("red invoice with no name must print a blank underline customer line")
	}
}
