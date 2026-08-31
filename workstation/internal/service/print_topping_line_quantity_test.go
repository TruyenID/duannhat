package service

import (
	"strings"
	"testing"
)

// A SHOP REPORTED THIS. It is not a hypothetical.
//
// Pick a dish, add an extra that costs money, then set the quantity to 3. The
// slip printed:
//
//	3  Pho bo                    ¥3.000     ← ×3
//	   -- Them gio lua             ¥100     ← ×1
//	小計                          ¥3.300
//	    10%対象      ¥3.100 (内消費税 ¥310)
//
// Three separate wrongs on one piece of paper: the topping row understated the
// charge, the item rows no longer summed to 小計 so the customer could not check
// their own bill, and the tax block — which also prints on the red invoice —
// stated a taxable base and a tax figure that were not the ones collected.
//
// The money CHARGED was always right: every writer on both transports stores
// `subtotal = quantity × (unit_price + topping_subtotal)` and
// `topping_subtotal` is per unit. Only the paper was wrong, which is the worst
// combination — the shop is not out of pocket and has nothing to reconcile
// against, so the defect can run for months on a document a customer signs.
//
// These tests are behavioural, at the formatter boundary, so they fail if the
// line quantity gets dropped again through ANY path — not only through the two
// functions that hold the arithmetic today.

// toppedLine is the shape the shop reported: one dish, quantity 3, one paid
// extra at ¥100 per unit. Charged: 3 × (1000 + 100) = 3300 + 10% = 3630.
func toppedLine() (*Order, []Item) {
	rate := 10.0
	order := &Order{
		ID:          "0199aa11-2233-4455-6677-8899aabbccdd",
		OrderCode:   "WS-019e-20260813-001",
		OrderType:   "dine_in",
		TableNumber: "A-1",
		Subtotal:    3300,
		TotalAmount: 3630,
		TaxAmount:   330,
	}
	items := []Item{{
		ID:              "item-1",
		MenuItemName:    "Pho bo",
		Quantity:        3,
		UnitPrice:       1000,
		Subtotal:        3300,
		ToppingSubtotal: 100,
		TaxRate:         &rate,
		Toppings: []ItemTopping{
			{Name: "Them gio lua", Quantity: 1, UnitPrice: 100, ModifierType: "add"},
		},
	}}
	return order, items
}

func TestReceipt_ToppingRowCarriesTheLineQuantity(t *testing.T) {
	order, items := toppedLine()
	out := FormatPaidTicket(order, items, 0,
		PrintJobConfig{PaperWidth: 48, CurrencyCode: "JPY"},
		PaymentSlipInfo{AmountPaid: 3630})
	txt := decodeSJIS(t, out)

	// ¥300, not ¥100 — three bowls, three helpings of extra.
	if !strings.Contains(txt, "300") || strings.Contains(txt, "-- Them gio lua                          ¥100") {
		t.Errorf("topping row must print the LINE amount (3 × ¥100):\n%s", txt)
	}
	// And the count moves with it, so what is printed on the left times the
	// topping's price is what is printed on the right.
	if !strings.Contains(txt, "3 x Them gio lua") {
		t.Errorf("topping row must show the total count (3), got:\n%s", txt)
	}
}

func TestReceipt_ItemRowsSumToTheSubtotal(t *testing.T) {
	// THE property, independent of layout: a customer adding up the printed
	// lines must land on the printed 小計. Anything else is a bill that cannot
	// be checked, whatever the individual numbers say.
	order, items := toppedLine()
	out := FormatPaidTicket(order, items, 0,
		PrintJobConfig{PaperWidth: 48, CurrencyCode: "JPY"},
		PaymentSlipInfo{AmountPaid: 3630})
	txt := decodeSJIS(t, out)

	sum := 0
	for _, line := range strings.Split(txt, "\n") {
		// Only the item table: the dish row and its indented "-- extra" rows.
		// Stop at the separator that closes the table so the totals block
		// (小計 / 合計 / 対象) is not counted as if it were an item.
		if strings.Contains(line, "小計") {
			break
		}
		if strings.Contains(line, "Pho bo") || strings.Contains(line, "Them gio lua") {
			sum += lastAmountOnLine(t, line)
		}
	}
	if sum != order.Subtotal {
		t.Errorf("printed item rows sum to %d, subtotal says %d — the customer cannot check this bill:\n%s",
			sum, order.Subtotal, txt)
	}
}

func TestReceipt_TaxBlockUsesTheChargedBase(t *testing.T) {
	// The figure on a 適格請求書 / red invoice. It must be the base the shop
	// actually charged tax on, not a re-derivation that drops the quantity.
	order, items := toppedLine()
	if got, want := itemTaxableSubtotal(items[0]), 3300; got != want {
		t.Fatalf("taxable base = %d, want %d (= 3 × (1000 + 100))", got, want)
	}

	out := FormatPaidTicket(order, items, 0,
		PrintJobConfig{PaperWidth: 48, CurrencyCode: "JPY"},
		PaymentSlipInfo{AmountPaid: 3630})
	txt := decodeSJIS(t, out)
	if !strings.Contains(txt, "3,300") {
		t.Errorf("tax block must state the ¥3.300 taxable base:\n%s", txt)
	}
	if strings.Contains(txt, "3,100") {
		t.Errorf("tax block still carries the pre-fix ¥3.100 base:\n%s", txt)
	}
}

func TestReceipt_ToppingWithItsOwnQuantityMultipliesBoth(t *testing.T) {
	// `ItemTopping.Quantity` counts the extra WITHIN ONE UNIT ("2 slices on
	// each bowl"), so the printed count is topping-qty × line-qty and the
	// printed amount follows it. 2 × 3 = 6 helpings at ¥100 = ¥600.
	rate := 10.0
	order := &Order{OrderCode: "OC-2", Subtotal: 3600, TotalAmount: 3960, TaxAmount: 360}
	items := []Item{{
		ID: "item-1", MenuItemName: "Pho bo", Quantity: 3, UnitPrice: 1000,
		Subtotal: 3600, ToppingSubtotal: 200, TaxRate: &rate,
		Toppings: []ItemTopping{{Name: "Them gio lua", Quantity: 2, UnitPrice: 100, ModifierType: "add"}},
	}}
	out := FormatPaidTicket(order, items, 0,
		PrintJobConfig{PaperWidth: 48, CurrencyCode: "JPY"},
		PaymentSlipInfo{AmountPaid: 3960})
	txt := decodeSJIS(t, out)

	if !strings.Contains(txt, "6 x Them gio lua") {
		t.Errorf("want the total helping count (2 per bowl × 3 bowls = 6):\n%s", txt)
	}
	if !strings.Contains(txt, "600") {
		t.Errorf("want ¥600 for 6 helpings at ¥100:\n%s", txt)
	}
	if got, want := itemTaxableSubtotal(items[0]), 3600; got != want {
		t.Errorf("taxable base = %d, want %d", got, want)
	}
}

func TestKitchenTicket_ToppingRowCarriesTheFiredQuantity(t *testing.T) {
	// The kitchen ticket prices its rows too, and its `Quantity` is the delta
	// being fired (see fireKitchenForOrder). Two rows in different units on the
	// same ticket is the same defect, so it gets the same rule.
	order, items := toppedLine()
	out := FormatKitchenTicket(order, items, 1, PrintJobConfig{PaperWidth: 48, CurrencyCode: "JPY"})
	txt := decodeSJIS(t, out)
	if !strings.Contains(txt, "3 x Them gio lua") || !strings.Contains(txt, "300") {
		t.Errorf("kitchen ticket topping row must carry the fired quantity:\n%s", txt)
	}
}

func TestReceipt_RemovedToppingUsesTheShopCurrency(t *testing.T) {
	// A deduction row used to be built with a hard-coded "-¥" while every
	// other amount on the slip went through the shop's currency. One yen sign
	// on a Vietnamese bill is the row nobody trusts afterwards.
	rate := 10.0
	order := &Order{OrderCode: "OC-3", Subtotal: 2700, TotalAmount: 2970, TaxAmount: 270}
	items := []Item{{
		ID: "item-1", MenuItemName: "Pho bo", Quantity: 3, UnitPrice: 1000,
		Subtotal: 2700, ToppingSubtotal: -100, TaxRate: &rate,
		Toppings: []ItemTopping{{Name: "Bo hanh", Quantity: 1, UnitPrice: 100, ModifierType: "remove"}},
	}}
	out := FormatPaidTicket(order, items, 0,
		PrintJobConfig{PaperWidth: 48, CurrencyCode: "VND", Currency: "d"},
		PaymentSlipInfo{AmountPaid: 2970})
	txt := decodeSJIS(t, out)

	if strings.Contains(txt, "-¥") {
		t.Errorf("deduction row printed a yen sign on a VND slip:\n%s", txt)
	}
	if !strings.Contains(txt, "-d300") {
		t.Errorf("deduction row must be the line amount in the shop currency:\n%s", txt)
	}
}

// lastAmountOnLine reads the trailing money figure off a printed row —
// "3  Pho bo        ¥3,000" → 3000. Digits and separators only; the currency
// symbol is whatever the shop uses, so it is skipped rather than matched.
func lastAmountOnLine(t *testing.T, line string) int {
	t.Helper()
	// #2757 — money is padded to the widest price ON THE SLIP and only then
	// right-aligned, so a row whose amount is narrower than the column ends in
	// blanks. The scan below resets its digit run on any non-digit rune, so
	// those trailing blanks used to read the amount as 0 and this helper
	// reported a bill that did not add up when the bill was fine.
	//
	// Trimming ONLY the trailing blanks keeps the property intact: the amount
	// must still be the last thing printed on the row.
	line = strings.TrimRight(line, " ")
	digits := []rune{}
	for _, r := range line {
		switch {
		case r >= '0' && r <= '9':
			digits = append(digits, r)
		case r == ',' || r == '.':
			// separator inside a number — keep scanning
		default:
			// Any other rune ends the current run. Reset only if we are not
			// already past the amount (the amount is last on the row).
			digits = digits[:0]
		}
	}
	n := 0
	for _, r := range digits {
		n = n*10 + int(r-'0')
	}
	return n
}
