package service

import (
	"strings"
	"testing"
)

// f64 is a tiny helper for the *float64 tax-rate snapshot columns.
func f64(v float64) *float64 { return &v }

// mixedRateOrder is the §1.2 proof case: a takeaway order with a reduced-rate
// bentō (8%) and a standard-rate beer (10%). Prices are tax-EXCLUDED.
func mixedRateOrder() (*Order, []Item) {
	o := &Order{
		ID: "o-mix", OrderCode: "WS-1-004", TableNumber: "-",
		OrderType:     "takeaway",
		IsTaxIncluded: false,
		Subtotal:      1500,
		TaxAmount:     130,
		TotalAmount:   1630,
	}
	items := []Item{
		{MenuItemName: "Bento", Quantity: 1, UnitPrice: 1000, Subtotal: 1000,
			Status: ItemStatusPending, TaxRate: f64(8), TaxAmount: 80},
		{MenuItemName: "Bia", Quantity: 1, UnitPrice: 500, Subtotal: 500,
			Status: ItemStatusPending, TaxRate: f64(10), TaxAmount: 50},
	}
	o.Items = items
	return o, items
}

// TestReceipt_PerRateBlocks_Excluded is the golden-text anchor for the §7.6
// receipt mockup: per-rate blocks with the 内消費税 figure, the ※ reduced
// marker on the 8% line, and the ※ legend. Bug #3: the mode is taken from
// order.IsTaxIncluded (excluded here), never a hardcoded 10% extraction.
//
// Defined truth (the new golden text), decoded from Shift_JIS. NOTE: the ESC/POS
// encoder maps the "¥" prefix to byte 0x5C (the yen glyph on JP thermal heads),
// which decodes back as "\", so the golden text asserts amounts WITHOUT the ¥:
//
//	8%対象 \1,000 (内消費税 \80)
//	10%対象 \500 (内消費税 \50)
//	※は軽減税率対象
func TestReceipt_PerRateBlocks_Excluded(t *testing.T) {
	o, items := mixedRateOrder()
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 42, CurrencyCode: "JPY", Locale: "ja"}
	out := FormatPaidTicket(o, items, 0, cfg, PaymentSlipInfo{AmountPaid: 1630})
	text := decodeSJIS(t, out)

	for _, want := range []string{
		"8%対象", "1,000", "内消費税", "80",
		"10%対象", "500", "50",
		"※は軽減税率対象",
	} {
		if !strings.Contains(text, want) {
			t.Errorf("receipt per-rate block missing %q\n---\n%s", want, text)
		}
	}

	// issue #1042 layout — the per-rate tax block prints BELOW the grand total
	// (合計) and is INDENTED (leading spaces), not above it as before.
	totalIdx := strings.Index(text, "合計")
	blockIdx := strings.Index(text, "対象")
	if totalIdx < 0 || blockIdx < 0 || blockIdx < totalIdx {
		t.Errorf("per-rate tax block must print BELOW the total (合計@%d, 対象@%d)\n%s", totalIdx, blockIdx, text)
	}
	if !strings.Contains(text, "   8%対象") { // 3-space indent before the rate label
		t.Errorf("per-rate tax block must be indented (3 spaces), got %q", printedSJISLineContaining(text, "対象"))
	}

	// The reduced-rate (8%) item name carries a ※ marker; the standard one does not.
	bentoLine := printedSJISLineContaining(text, "Bento")
	if !strings.Contains(bentoLine, "※") {
		t.Errorf("reduced-rate item must carry ※ marker, got %q", bentoLine)
	}
	biaLine := printedSJISLineContaining(text, "Bia")
	if strings.Contains(biaLine, "※") {
		t.Errorf("standard-rate item must NOT carry ※ marker, got %q", biaLine)
	}

	// Bug #3 regression: the single legacy "Thue" line is gone, replaced by blocks.
	if strings.Contains(text, "Thue ") {
		t.Errorf("per-rate order must not print the legacy single Thue line\n%s", text)
	}
}

// TestReceipt_PerRateBlocks_Included proves included mode (総額表示) extracts the
// 内税 from the gross per group rather than adding it on top — and the printed
// tax matches the tax-included extraction, NOT a tax-excluded add-on.
//
// gross 8% = 1,080 → tax = 1080 - round(1080/1.08) = 80
// gross 10% =  550 → tax =  550 - round(550/1.10)  = 50
func TestReceipt_PerRateBlocks_Included(t *testing.T) {
	o := &Order{
		ID: "o-inc", OrderCode: "WS-1-005", TableNumber: "-",
		OrderType: "takeaway", IsTaxIncluded: true,
		TotalAmount: 1630,
	}
	items := []Item{
		{MenuItemName: "Bento", Quantity: 1, UnitPrice: 1080, Subtotal: 1080,
			Status: ItemStatusPending, TaxRate: f64(8)},
		{MenuItemName: "Bia", Quantity: 1, UnitPrice: 550, Subtotal: 550,
			Status: ItemStatusPending, TaxRate: f64(10)},
	}
	o.Items = items
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 42, CurrencyCode: "JPY", Locale: "ja"}
	text := decodeSJIS(t, FormatPaidTicket(o, items, 0, cfg, PaymentSlipInfo{AmountPaid: 1630}))

	// Included: the 内消費税 is the extracted tax (80 / 50), and the taxable base
	// printed is the net (1,000 / 500), not the gross. (¥ → \ after SJIS decode.)
	for _, want := range []string{"8%対象", "1,000", "内消費税 \\80", "10%対象", "500", "50"} {
		if !strings.Contains(text, want) {
			t.Errorf("included-mode block missing %q\n---\n%s", want, text)
		}
	}
}

// TestReceipt_SingleRate_NoMarker proves a single-rate order prints ONE block
// with no ※ marker + no legend (nothing to distinguish).
func TestReceipt_SingleRate_NoMarker(t *testing.T) {
	o := &Order{
		ID: "o-single", OrderCode: "WS-1-006", TableNumber: "A1",
		OrderType: "dine_in", TotalAmount: 1100, TaxAmount: 100,
	}
	items := []Item{
		{MenuItemName: "Pho", Quantity: 1, UnitPrice: 1000, Subtotal: 1000,
			Status: ItemStatusPending, TaxRate: f64(10), TaxAmount: 100},
	}
	o.Items = items
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 42, CurrencyCode: "JPY", Locale: "ja"}
	text := decodeSJIS(t, FormatPaidTicket(o, items, 0, cfg, PaymentSlipInfo{AmountPaid: 1100}))

	if !strings.Contains(text, "10%対象") {
		t.Errorf("single-rate order must print its one 10%%対象 block\n%s", text)
	}
	if strings.Contains(text, "※") {
		t.Errorf("single-rate order must not carry a ※ marker/legend\n%s", text)
	}
}

// TestReceipt_LegacyFallback proves an order with NO per-line tax snapshots
// (pre-migration / old-cloud) still prints the single tax-included 税 line —
// the per-rate path is skipped, nothing regresses.
func TestReceipt_LegacyFallback(t *testing.T) {
	o := sampleOrder() // no TaxRate on its item
	o.PaidAmount = 1780
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 42, TaxRate: 10, CurrencyCode: "JPY"}
	text := decodeSJIS(t, FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{AmountPaid: 1780}))

	if !strings.Contains(text, "税") {
		t.Errorf("legacy order must fall back to the single 税 line\n%s", text)
	}
	if strings.Contains(text, "対象") {
		t.Errorf("legacy order must NOT print per-rate 対象 blocks\n%s", text)
	}
}

// TestReceipt_RegistrationNumber proves the T+13 登録番号 slot prints only when
// the shop carries a non-empty seller registration number (Q5).
func TestReceipt_RegistrationNumber(t *testing.T) {
	o, items := mixedRateOrder()

	// Absent → no 登録番号 line.
	cfgOff := PrintJobConfig{StoreName: "S", PaperWidth: 42, CurrencyCode: "JPY", Locale: "ja"}
	if strings.Contains(decodeSJIS(t, FormatPaidTicket(o, items, 0, cfgOff, PaymentSlipInfo{AmountPaid: 1630})), "登録番号") {
		t.Errorf("no registration number set → 登録番号 line must be absent")
	}

	// Present → printed with the value.
	cfgOn := cfgOff
	cfgOn.SellerRegistrationNumber = "T1234567890123"
	text := decodeSJIS(t, FormatPaidTicket(o, items, 0, cfgOn, PaymentSlipInfo{AmountPaid: 1630}))
	if !strings.Contains(text, "登録番号: T1234567890123") {
		t.Errorf("registration number line missing\n%s", text)
	}
}

// TestReceipt_SubBillSuppressesBreakdown proves the per-rate インボイス breakdown
// stays suppressed on a split SUB-bill (Q13 default) — same rule the legacy
// single Thue line followed.
func TestReceipt_SubBillSuppressesBreakdown(t *testing.T) {
	o, items := mixedRateOrder()
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 42, CurrencyCode: "JPY", Locale: "ja"}
	text := decodeSJIS(t, FormatPaidTicket(o, items, 0, cfg, PaymentSlipInfo{
		PaymentMethod: "cash", AmountPaid: 800, BillTotal: 800, SlipIndex: 1, SplitCount: 2,
	}))
	if strings.Contains(text, "対象") || strings.Contains(text, "※") {
		t.Errorf("sub-bill must suppress the per-rate breakdown\n%s", text)
	}
}

// TestKitchenAndDelta_ShowPerRateTax proves the fix for the "tax section missing
// on the kitchen (giấy Bếp) and hold (giấy hold) papers" report: both the kitchen
// ticket and the per-fire hold/delta slip now print the SAME per-rate 内消費税
// blocks + ※ reduced-rate legend the customer bill shows — computed on the items
// each slip displays (so the breakdown matches that slip's own total, not the
// whole order).
func TestKitchenAndDelta_ShowPerRateTax(t *testing.T) {
	o, items := mixedRateOrder()
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 42, CurrencyCode: "JPY", Locale: "ja"}

	slips := map[string][]byte{
		"kitchen": FormatKitchenTicket(o, items, 7, cfg),
		"delta":   FormatDeltaQRTicket(o, items, cfg),
	}
	for name, out := range slips {
		text := decodeSJIS(t, out)
		for _, want := range []string{"8%対象", "内消費税", "10%対象", "※は軽減税率対象"} {
			if !strings.Contains(text, want) {
				t.Errorf("%s slip missing tax fragment %q\n---\n%s", name, want, text)
			}
		}
		// The reduced-rate (8%) item line carries the ※ marker; the 10% one doesn't.
		if bento := printedSJISLineContaining(text, "Bento"); !strings.Contains(bento, "※") {
			t.Errorf("%s: reduced-rate item must carry ※ marker, got %q", name, bento)
		}
		if bia := printedSJISLineContaining(text, "Bia"); strings.Contains(bia, "※") {
			t.Errorf("%s: standard-rate item must NOT carry ※ marker, got %q", name, bia)
		}
	}
}

// printedSJISLineContaining returns the first \n-delimited line of decoded text
// containing sub (helper for SJIS-decoded golden assertions).
func printedSJISLineContaining(text, sub string) string {
	for _, ln := range strings.Split(text, "\n") {
		if strings.Contains(ln, sub) {
			return ln
		}
	}
	return ""
}
