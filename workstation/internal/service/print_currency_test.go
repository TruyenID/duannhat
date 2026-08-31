package service

import (
	"bytes"
	"testing"
)

func TestCurrencySymbol(t *testing.T) {
	cases := map[string]string{
		"VND": "₫",
		"vnd": "₫", // case-insensitive
		"USD": "$",
		"JPY": "¥",
		"CNY": "¥",
		"EUR": "€",
		"GBP": "£",
		"KRW": "₩",
		"":    "¥", // unknown → default ¥
		"ZZZ": "¥",
	}
	for code, want := range cases {
		if got := CurrencySymbol(code); got != want {
			t.Errorf("CurrencySymbol(%q) = %q, want %q", code, got, want)
		}
	}
}

func TestPrintJobConfigCur(t *testing.T) {
	if got := (PrintJobConfig{}).cur(); got != "¥" {
		t.Errorf("empty Currency should default to ¥, got %q", got)
	}
	if got := (PrintJobConfig{Currency: "₫"}).cur(); got != "₫" {
		t.Errorf("cur() = %q, want ₫", got)
	}
}

// The kitchen ticket must print the config currency, never a hard-coded ¥.
// Uses "$" (ASCII, survives Shift-JIS as-is) so the assertion is on the raw
// printed bytes; VND (₫) is exercised at the encoder level (encoder_test.go),
// where ₫ is mapped to the printable ASCII "d".
func TestKitchenTicketUsesConfigCurrency(t *testing.T) {
	order := &Order{OrderType: "takeaway", TableNumber: "-"}
	items := []Item{{MenuItemName: "Bun Cha", Quantity: 1, UnitPrice: 50000, PrinterGroup: "kitchen"}}
	out := FormatKitchenTicket(order, items, 1, PrintJobConfig{PaperWidth: 42, Currency: "$"})
	if !bytes.Contains(out, []byte("$")) {
		t.Fatalf("kitchen ticket should print the config currency ($)")
	}
	// 0x5C is the Shift-JIS yen sign a hard-coded ¥ would encode to.
	if bytes.Contains(out, []byte{0x5C}) {
		t.Fatalf("kitchen ticket must not contain a hard-coded ¥ (0x5C)")
	}
}
