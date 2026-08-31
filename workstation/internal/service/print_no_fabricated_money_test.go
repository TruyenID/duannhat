package service

import (
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// #2067 — THE PRINT LAYER PRINTS ORDER DATA. IT DOES NOT PRICE A SALE.
//
// Two fabrications lived in the formatter and were reached in production:
//
//  1. `orderGrossTotal` re-summed the item lines whenever `order.total_amount`
//     was 0 — blind to `order_conditions` (discount, coupon, service charge) and
//     to `voided_at`, so a fully-discounted or fully-voided bill printed its
//     pre-discount / pre-void gross.
//  2. `taxBreakdown` extracted 内税 from the total using a rate, and when the
//     rate was 0 it used a hard-coded `fallbackTaxRate = 10.0`.
//
// (2) was not an edge case. `PrintJobConfig.TaxRate` is fed by
// `shop_settings.tax_rate`, and NO Cloud populates that key any more — plan-043
// T6.2 dropped the `shop_order_settings.tax_rate` column and the workstation
// branch feed's explicit allowlist (`Workstation\BranchController::show`) does
// not carry it. So the rate was 0 on every register, in every country, and the
// "fallback" was the only branch that ever ran: every legacy-path slip printed
// exactly 10%, including in Vietnam, on 軽減税率 8% baskets and on 非課税 lines.
// Meanwhile `OrderEngine.legacyTaxRate` prices those same lines at 0% — the
// engine and the printer disagreed by construction.
//
// The tests below are behavioural, at the formatter boundary, so they fail if
// either fabrication returns through ANY path — not only through the two
// functions that happen to hold it today.

// untaxedUnstampedOrder is the shape that reaches the legacy path: real money,
// no per-line `tax_rate` snapshot (the brand's tax_types never synced) and no
// order-level tax_amount, so there is no tax fact anywhere on the order.
func untaxedUnstampedOrder() (*Order, []Item) {
	order := &Order{
		ID:          "0199aa11-2233-4455-6677-8899aabbccdd",
		OrderCode:   "WS-019e-20260720-004",
		OrderType:   "dine_in",
		TableNumber: "A-1",
		TotalAmount: 1100,
		TaxAmount:   0,
	}
	items := []Item{
		{ID: "item-1", MenuItemName: "Bun bo", Quantity: 1, UnitPrice: 1100},
	}
	return order, items
}

// noTaxRateConfig is what every real workstation actually holds: TaxRate 0,
// because nothing populates `shop_settings.tax_rate`. Under the old code this
// exact config produced the 10% invention.
func noTaxRateConfig() PrintJobConfig {
	return PrintJobConfig{
		StoreName:    "ベト屋",
		PaperWidth:   42,
		Currency:     "₫",
		CurrencyCode: "VND",
		Locale:       "vi",
		TaxRate:      0,
		// #2572 — pinned for the same reason goldenConfig pins it: this config
		// feeds the recorded hashes in print_deficient_golden.json, and an
		// unpinned zone would make those bytes depend on the machine that
		// generated them.
	}.WithSlipLocation(goldenShopLocation)
}

func TestNoFabricatedTax_MoneySlipsPrintNoTaxRowWithoutATaxFact(t *testing.T) {
	order, items := untaxedUnstampedOrder()
	cfg := noTaxRateConfig()
	slip := PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: 1100}

	// The tax the old code would have invented: 1100 - round(1100/1.1) = 100.
	const fabricated = "100"

	slips := map[string][]byte{
		"runner":      FormatRunnerTicket(order, items, 0, cfg),
		"receipt":     FormatPaidTicket(order, items, 0, cfg, slip),
		"red_invoice": FormatRedInvoiceTicket(order, items, cfg, slip),
		"remaining":   FormatRemainingTicket(order, items, 0, cfg, 1100),
		"debt_slip":   FormatDebtSlip(order, items, cfg, DebtSlipInfo{CustomerName: "Cty ABC"}),
	}

	taxLabel := printLabelsFor(cfg.Locale).Tax
	for kind, out := range slips {
		t.Run(kind, func(t *testing.T) {
			text := decodeSJIS(t, out)
			// The tax label must not appear at all — an absent block, not a ₫0
			// row. "Thue ₫0" would tell the customer this sale carries zero tax,
			// which is a different (and equally unfounded) claim.
			if strings.Contains(text, taxLabel) {
				t.Errorf("%s printed a tax row with no tax fact on the order:\n%s", kind, text)
			}
			if got := rowDigits(text, taxLabel); got == fabricated {
				t.Errorf("%s printed the fabricated 10%% figure %s:\n%s", kind, fabricated, text)
			}
		})
	}
}

// The order's OWN tax_amount is still printed — this is order data, not a
// computation, and removing it would have been the opposite over-correction.
func TestNoFabricatedTax_TheOrdersOwnTaxAmountStillPrints(t *testing.T) {
	order, items := untaxedUnstampedOrder()
	order.TaxAmount = 88 // e.g. a legacy order Cloud stamped at 8%
	cfg := noTaxRateConfig()

	text := decodeSJIS(t, FormatRunnerTicket(order, items, 0, cfg))
	taxLabel := printLabelsFor(cfg.Locale).Tax
	if !strings.Contains(text, taxLabel) {
		t.Fatalf("the order's own tax_amount was dropped:\n%s", text)
	}
	// Verbatim: the stamped 88, not the 100 that "10% of 1,100" would produce.
	if got := rowDigits(text, taxLabel); got != "88" {
		t.Errorf("tax row = %q, want the stamped 88:\n%s", got, text)
	}
}

func TestNoFabricatedTotal_MoneySlipsNeverReSumTheLines(t *testing.T) {
	cfg := noTaxRateConfig()

	totalLabel := printLabelsFor(cfg.Locale).Total

	t.Run("a bill covered in full by a coupon", func(t *testing.T) {
		order := &Order{
			ID: "order-coupon", OrderCode: "WS-0001",
			Subtotal: 2000, DiscountAmount: 2000, TotalAmount: 0,
		}
		items := []Item{{ID: "i1", MenuItemName: "Bun bo", Quantity: 2, UnitPrice: 1000}}

		// 2,000 is the pre-discount gross the old line sum produced. It may still
		// appear on the Tam tinh row — that is the order's own `subtotal` column —
		// so the assertion is on the headline Tong row specifically.
		text := decodeSJIS(t, FormatRunnerTicket(order, items, 0, cfg))
		if got := rowDigits(text, totalLabel); got != "0" {
			t.Errorf("headline total = %q, want 0 — it was re-derived past the discount:\n%s", got, text)
		}
	})

	t.Run("an all-voided order", func(t *testing.T) {
		voided := goldenSaleClock
		order := &Order{ID: "order-void", OrderCode: "WS-0002", TotalAmount: 0}
		items := []Item{{
			ID: "i1", MenuItemName: "Bun bo", Quantity: 2, UnitPrice: 1000,
			Status: ItemStatusVoided, VoidedAt: &voided,
		}}

		text := decodeSJIS(t, FormatRunnerTicket(order, items, 0, cfg))
		if got := rowDigits(text, totalLabel); got != "0" {
			t.Errorf("headline total = %q, want 0 — voided lines were summed in:\n%s", got, text)
		}
	})
}

// rowDigits returns the digits of the money value on the FIRST row whose trimmed
// text starts with `label` — currency-symbol agnostic, because the Vietnamese
// catalog ASCII-folds ₫ to "d" for Shift_JIS and the thousands separator differs
// by nothing but taste. Returns "" when no such row was printed.
func rowDigits(text, label string) string {
	for _, line := range strings.Split(text, "\n") {
		trimmed := strings.TrimSpace(line)
		// Rows carry ESC/POS control bytes; trim what is not printable text.
		trimmed = strings.TrimLeft(trimmed, "\x00-\x1f")
		idx := strings.Index(trimmed, label)
		if idx < 0 {
			continue
		}
		rest := trimmed[idx+len(label):]
		var digits strings.Builder
		for _, r := range rest {
			if r >= '0' && r <= '9' {
				digits.WriteRune(r)
			}
		}
		return digits.String()
	}
	return ""
}

// TestPrintLayerDerivesNoTaxRate is the structural half: the two behavioural
// tests above prove the fabrication is gone from the paths they walk, but a new
// slip kind could reintroduce it somewhere they do not reach. This forbids the
// INGREDIENTS instead — the print layer may not read a tax RATE off its config,
// and may not divide by one.
//
// A regex over source is a blunt instrument, and it is used here on purpose: the
// thing being forbidden is not a value at runtime, it is a line of code someone
// writes six months from now while adding a kind. `PrintJobConfig.TaxRate`
// survives only because removing the field would ripple into the PHP contract
// parity fixture; nothing may read it.
func TestPrintLayerDerivesNoTaxRate(t *testing.T) {
	files, err := filepath.Glob("print_*.go")
	if err != nil {
		t.Fatal(err)
	}
	if len(files) < 10 {
		t.Fatalf("expected the print layer to be many files, globbed %d — did the layout move?", len(files))
	}

	// `cfg.TaxRate` / `config.TaxRate` / `c.cfg.TaxRate` — a rate reaching the
	// print layer's own arithmetic. `item.TaxRate` (a per-line SNAPSHOT, which
	// is order data) deliberately does not match.
	readsRate := regexp.MustCompile(`\b(cfg|config|c\.cfg|c\.config)\.TaxRate\b`)
	// A literal 10 used as a percent, in any of the shapes the removed code took.
	fabricatesRate := regexp.MustCompile(`fallbackTaxRate|FallbackTaxRate|1 \+ 10/100|/ 1\.1\b`)

	for _, f := range files {
		if strings.HasSuffix(f, "_test.go") {
			continue
		}
		src, err := os.ReadFile(f)
		if err != nil {
			t.Fatal(err)
		}
		for i, line := range strings.Split(string(src), "\n") {
			code := line
			if idx := strings.Index(line, "//"); idx >= 0 {
				code = line[:idx] // comments may name the removed thing; code may not
			}
			if readsRate.MatchString(code) {
				t.Errorf("%s:%d reads a tax RATE off the print config — the print layer "+
					"derives no tax (#2067):\n\t%s", f, i+1, strings.TrimSpace(line))
			}
			if fabricatesRate.MatchString(code) {
				t.Errorf("%s:%d reintroduces a default tax rate in the print layer (#2067):\n\t%s",
					f, i+1, strings.TrimSpace(line))
			}
		}
	}
}
