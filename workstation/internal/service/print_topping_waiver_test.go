package service

import (
	"strings"
	"testing"
	"unicode"
)

// THE SLIP MUST ADD UP WHEN A `free_up_to_n` GROUP GAVE SOMETHING AWAY.
//
// The engine waives the `free_quantity` most expensive units in a group and
// stores only the RESULT in `customer_order_items.topping_subtotal`. Which units
// were waived is never persisted — `order_item_toppings` carries the list price
// and nothing else — so the topping rows print at list price and the item block
// over-states by exactly the amount given away:
//
//	3  Pho bo                   ¥3.000
//	   -- 3 x Them gio lua        ¥300     ← this one was FREE
//	   -- 3 x Trung chan          ¥240
//	小計                         ¥3.240     ← rows say ¥3.540
//
// The reconciling row states the total given away, which is the one thing both
// stored figures agree on. It is a DISPLAY row: every total on the slip already
// comes from figures that account for the waiver.
//
// These tests are behavioural, at the formatter boundary, and they pin the
// SILENCES as hard as the row itself — a slip that invents a discount is worse
// than one that does not add up.

// waivedLine is the worked example above. Two extras picked per bowl, the ¥100
// one waived by a 1-free tier, three bowls.
//
//	list per unit   = 100 + 80 = 180
//	charged per unit = 80            → topping_subtotal
//	waived per unit  = 100           → the row prints 100 × 3
func waivedLine() (*Order, []Item) {
	rate := 10.0
	order := &Order{
		ID:          "0199aa11-2233-4455-6677-8899aabbccdd",
		OrderCode:   "WS-019e-20260813-002",
		OrderType:   "dine_in",
		TableNumber: "A-1",
		Subtotal:    3240,
		TotalAmount: 3564,
		TaxAmount:   324,
	}
	items := []Item{{
		ID:              "item-1",
		MenuItemName:    "Pho bo",
		Quantity:        3,
		UnitPrice:       1000,
		Subtotal:        3240,
		ToppingSubtotal: 80,
		TaxRate:         &rate,
		Toppings: []ItemTopping{
			{Name: "Them gio lua", Quantity: 1, UnitPrice: 100, ModifierType: "add"},
			{Name: "Trung chan", Quantity: 1, UnitPrice: 80, ModifierType: "add"},
		},
	}}
	return order, items
}

func renderPaid(t *testing.T, order *Order, items []Item, cfg PrintJobConfig) string {
	t.Helper()
	if cfg.PaperWidth == 0 {
		cfg.PaperWidth = 48
	}
	if cfg.CurrencyCode == "" {
		cfg.CurrencyCode = "JPY"
	}
	out := FormatPaidTicket(order, items, 0, cfg,
		PaymentSlipInfo{AmountPaid: order.TotalAmount})
	return decodeSJIS(t, out)
}

func TestToppingWaiver_RowStatesWhatWasGivenAway(t *testing.T) {
	order, items := waivedLine()
	txt := renderPaid(t, order, items, PrintJobConfig{Locale: "vi"})

	if !strings.Contains(txt, printLabelsVI.ToppingWaived) {
		t.Fatalf("no reconciling row on a waived line:\n%s", txt)
	}
	row := rowContaining(t, txt, printLabelsVI.ToppingWaived)
	if got := trailingAmount(row); got != -300 {
		t.Errorf("waiver row = %d, want -300 (¥100 waived per unit × 3 bowls):\n%s", got, txt)
	}
}

func TestToppingWaiver_ItemBlockSumsToTheSubtotal(t *testing.T) {
	// THE property. A customer adding up the printed lines must land on the
	// printed 小計 — that is the entire reason this row exists, and it is worth
	// asserting directly rather than through the individual figures.
	order, items := waivedLine()
	txt := renderPaid(t, order, items, PrintJobConfig{Locale: "vi"})

	sum := 0
	for _, name := range []string{"Pho bo", "Them gio lua", "Trung chan", printLabelsVI.ToppingWaived} {
		sum += trailingAmount(rowContaining(t, txt, name))
	}
	if sum != order.Subtotal {
		t.Errorf("printed item block sums to %d, subtotal says %d:\n%s", sum, order.Subtotal, txt)
	}
}

func TestToppingWaiver_TaxBaseIsNotDiscountedTwice(t *testing.T) {
	// The row is DISPLAY ONLY. `itemTaxableSubtotal` reads the stored
	// `ToppingSubtotal`, which is already net of the waiver — subtracting the
	// printed row from it as well would under-state the tax on a 適格請求書.
	_, items := waivedLine()
	if got, want := itemTaxableSubtotal(items[0]), 3240; got != want {
		t.Fatalf("taxable base = %d, want %d (= 3 × (1000 + 80))", got, want)
	}
}

func TestToppingWaiver_SilentOnAFlatGroup(t *testing.T) {
	// The default strategy, and the only one a shop can configure from
	// admin-web. Nothing was given away, so nothing is claimed.
	rate := 10.0
	order := &Order{OrderCode: "OC-F", Subtotal: 3300, TotalAmount: 3630, TaxAmount: 330}
	items := []Item{{
		ID: "item-1", MenuItemName: "Pho bo", Quantity: 3, UnitPrice: 1000,
		Subtotal: 3300, ToppingSubtotal: 100, TaxRate: &rate,
		Toppings: []ItemTopping{{Name: "Them gio lua", Quantity: 1, UnitPrice: 100, ModifierType: "add"}},
	}}
	txt := renderPaid(t, order, items, PrintJobConfig{Locale: "vi"})

	if strings.Contains(txt, printLabelsVI.ToppingWaived) {
		t.Errorf("a flat group must print no reconciling row:\n%s", txt)
	}
}

func TestToppingWaiver_SilentWhenThereIsNoStoredSubtotalToReconcileAgainst(t *testing.T) {
	// `ToppingSubtotal == 0` with priced rows is the legacy/unsynced shape, not
	// "everything was free". Printing a discount derived from a missing value
	// would be inventing one on a tax document — and `itemTaxableSubtotal`
	// falls back to summing the same rows in that shape, so the slip already
	// adds up without any help.
	rate := 10.0
	order := &Order{OrderCode: "OC-Z", Subtotal: 3300, TotalAmount: 3630, TaxAmount: 330}
	items := []Item{{
		ID: "item-1", MenuItemName: "Pho bo", Quantity: 3, UnitPrice: 1000,
		Subtotal: 3300, ToppingSubtotal: 0, TaxRate: &rate,
		Toppings: []ItemTopping{{Name: "Them gio lua", Quantity: 1, UnitPrice: 100, ModifierType: "add"}},
	}}
	txt := renderPaid(t, order, items, PrintJobConfig{Locale: "vi"})

	if strings.Contains(txt, printLabelsVI.ToppingWaived) {
		t.Errorf("no stored subtotal ⇒ no reconciling row:\n%s", txt)
	}
	// …and the fallback keeps the block consistent on its own.
	if got, want := itemTaxableSubtotal(items[0]), 3300; got != want {
		t.Errorf("taxable base = %d, want %d", got, want)
	}
	sum := trailingAmount(rowContaining(t, txt, "Pho bo")) +
		trailingAmount(rowContaining(t, txt, "Them gio lua"))
	if sum != order.Subtotal {
		t.Errorf("block sums to %d, subtotal %d:\n%s", sum, order.Subtotal, txt)
	}
}

func TestToppingWaiver_NeverPrintsASurchargeUnderADiscountLabel(t *testing.T) {
	// The shop charged MORE for the extras than the rows show. That is not a
	// waiver — no free tier can produce it — so the row is omitted and the
	// disagreement is logged. A "free toppings" row carrying a positive amount
	// would be a false statement on a document a customer keeps.
	rate := 10.0
	order := &Order{OrderCode: "OC-N", Subtotal: 3600, TotalAmount: 3960, TaxAmount: 360}
	items := []Item{{
		ID: "item-1", MenuItemName: "Pho bo", Quantity: 3, UnitPrice: 1000,
		Subtotal: 3600, ToppingSubtotal: 200, TaxRate: &rate,
		Toppings: []ItemTopping{{Name: "Them gio lua", Quantity: 1, UnitPrice: 100, ModifierType: "add"}},
	}}
	txt := renderPaid(t, order, items, PrintJobConfig{Locale: "vi"})

	if strings.Contains(txt, printLabelsVI.ToppingWaived) {
		t.Errorf("a negative gap must not print a row:\n%s", txt)
	}
}

func TestToppingWaiver_SilentWhenARowCouldNotBeAccountedFor(t *testing.T) {
	// A priced topping with no name prints nothing but was still priced by the
	// engine, so the block cannot be reconciled. Folding that difference into a
	// row labelled "free toppings" would blame a discount for a missing name.
	rate := 10.0
	order := &Order{OrderCode: "OC-B", Subtotal: 3240, TotalAmount: 3564, TaxAmount: 324}
	items := []Item{{
		ID: "item-1", MenuItemName: "Pho bo", Quantity: 3, UnitPrice: 1000,
		Subtotal: 3240, ToppingSubtotal: 80, TaxRate: &rate,
		Toppings: []ItemTopping{
			{Name: "", Quantity: 1, UnitPrice: 100, ModifierType: "add"},
			{Name: "Trung chan", Quantity: 1, UnitPrice: 80, ModifierType: "add"},
		},
	}}
	txt := renderPaid(t, order, items, PrintJobConfig{Locale: "vi"})

	if strings.Contains(txt, printLabelsVI.ToppingWaived) {
		t.Errorf("an unaccountable row must switch reconciliation off:\n%s", txt)
	}
}

func TestToppingWaiver_SpeaksTheOperatorsLanguageAndTheShopsCurrency(t *testing.T) {
	order, items := waivedLine()

	for _, c := range []struct {
		locale string
		want   string
	}{
		{"vi", printLabelsVI.ToppingWaived},
		{"en", printLabelsEN.ToppingWaived},
		{"ja", printLabelsJA.ToppingWaived},
		{"", printLabelsJA.ToppingWaived}, // unresolved locale → ja, like every other label
	} {
		txt := renderPaid(t, order, items, PrintJobConfig{Locale: c.locale})
		if !strings.Contains(txt, c.want) {
			t.Errorf("locale %q: want label %q:\n%s", c.locale, c.want, txt)
		}
	}

	// The shop's currency, never a hard-coded yen sign.
	txt := renderPaid(t, order, items,
		PrintJobConfig{Locale: "vi", CurrencyCode: "VND", Currency: "d"})
	row := rowContaining(t, txt, printLabelsVI.ToppingWaived)
	if !strings.Contains(row, "-d") {
		t.Errorf("waiver row must use the shop currency, got %q", row)
	}
	if strings.Contains(row, "¥") {
		t.Errorf("waiver row printed a yen sign on a VND slip: %q", row)
	}
}

func TestToppingWaiver_RowIsAlignedToTheSameMoneyColumn(t *testing.T) {
	// A money column that does not line up is a money column staff stop
	// reading down. The width maths goes through `displayWidth`, so the case
	// that would break first is Japanese — 「トッピング無料分」 is 8 runes but 16
	// columns, and counting runes there would pull the amount 8 places left.
	order, items := waivedLine()
	for _, locale := range []string{"vi", "en", "ja"} {
		for _, width := range []int{32, 42, 48} {
			txt := renderPaid(t, order, items,
				PrintJobConfig{Locale: locale, PaperWidth: width})
			label := printLabelsFor(locale).ToppingWaived
			got := displayWidth(rowContaining(t, txt, label))
			if got != width {
				t.Errorf("%s @ %d cols: waiver row is %d columns wide", locale, width, got)
			}
		}
	}
}

func TestToppingWaiver_KitchenTicketScalesWithTheFiredDelta(t *testing.T) {
	// The kitchen ticket prices its rows off the delta being fired, so the
	// reconciling row rides the same scale — the block stays internally
	// consistent with the partial figures around it.
	order, items := waivedLine()
	items[0].Quantity = 2 // a 2-unit delta of the 3-unit line
	out := FormatKitchenTicket(order, items, 1,
		PrintJobConfig{PaperWidth: 48, CurrencyCode: "JPY", Locale: "vi"})
	txt := decodeSJIS(t, out)

	row := rowContaining(t, txt, printLabelsVI.ToppingWaived)
	if got := trailingAmount(row); got != -200 {
		t.Errorf("kitchen waiver row = %d, want -200 (¥100 × 2 fired units):\n%s", got, txt)
	}
}

// rowContaining returns the single printed line holding `needle`.
func rowContaining(t *testing.T, txt, needle string) string {
	t.Helper()
	var found string
	for _, line := range strings.Split(txt, "\n") {
		if strings.Contains(line, needle) {
			if found != "" {
				t.Fatalf("%q appears on more than one row", needle)
			}
			found = line
		}
	}
	if found == "" {
		t.Fatalf("no row contains %q:\n%s", needle, txt)
	}
	return found
}

// trailingAmount reads the money figure at the END of a printed row, with its
// sign — "   -- Topping mien phi   -¥300" → -300. Read off the tail rather than
// matched against a formatted string: the glyph is the shop's currency and the
// separators are locale-shaped, so pinning either would make these tests fail
// for reasons that have nothing to do with the arithmetic they guard. Returns 0
// for a row that carries no amount.
func trailingAmount(line string) int {
	r := []rune(strings.TrimRight(line, " "))
	i := len(r) - 1
	n, digits := 0, 0
	place := 1
	for i >= 0 && (unicode.IsDigit(r[i]) || r[i] == ',' || r[i] == '.') {
		if unicode.IsDigit(r[i]) {
			n += int(r[i]-'0') * place
			place *= 10
			digits++
		}
		i--
	}
	if digits == 0 {
		return 0
	}
	// Step back over the currency glyph sitting between the sign and the digits.
	for i >= 0 && !unicode.IsDigit(r[i]) && r[i] != ' ' && r[i] != '-' {
		i--
	}
	if i >= 0 && r[i] == '-' {
		return -n
	}
	return n
}
