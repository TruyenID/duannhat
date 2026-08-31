package service

import (
	"bytes"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
	"golang.org/x/text/encoding/japanese"
	"golang.org/x/text/transform"
)

// decodeSJIS turns the ESC/POS byte stream back into UTF-8 so the test can
// assert on the Japanese labels the slip actually prints. Control/command
// bytes that aren't valid Shift-JIS are dropped by the transformer, leaving
// the rendered text intact.
func decodeSJIS(t *testing.T, b []byte) string {
	t.Helper()
	out, _, err := transform.Bytes(japanese.ShiftJIS.NewDecoder(), b)
	if err != nil {
		// The decoder tolerates stray command bytes; a hard error is unexpected.
		t.Fatalf("shift-jis decode: %v", err)
	}
	return string(out)
}

// sampleShiftReport mirrors the reference 精算 slip (ベト屋イベント, No.00003).
func sampleShiftReport() ShiftReportInfo {
	return ShiftReportInfo{
		TillCode: "0001",
		ZNumber:  3,
		Phone:    "0368457586",
		OpenedAt: "2026/07/03 16:57",
		ClosedAt: "2026/07/03 17:09",

		GrossSales: 3775,
		ItemCount:  5,
		NetSales:   3460,
		GuestCount: 5,
		TaxTotal:   315,

		Payments: []ShiftPaymentLine{
			{Label: "現金", Count: 2, Amount: 2000},
			{Label: "PayPay", Count: 1, Amount: 800},
			{Label: "クレジットカード", Count: 1, Amount: 750},
			{Label: "電子マネー", Count: 1, Amount: 225},
		},
		Discounts:           []ShiftDiscountLine{{Label: "50", Count: 1, Amount: 225}},
		DiscountTotalCount:  1,
		DiscountTotalAmount: 225,

		CheckCount: 5,

		CountedCash:  2000,
		ExpectedCash: 2000,
		CashVariance: 0,
		Operator:     "",
		Currency:     "JPY",

		// Default = full report (every optional section on).
		ShowPaymentMethods: true,
		ShowServiceCharge:  true,
		ShowDrawerCheck:    true,
		ShowDenominations:  true,
		Denominations: []ShiftOpenDenomLine{
			{Value: 1000, Quantity: 1, Subtotal: 1000},
			{Value: 500, Quantity: 2, Subtotal: 1000},
		},
	}
}

func TestFormatShiftReport_ReferenceSlip(t *testing.T) {
	cfg := PrintJobConfig{StoreName: "ベト屋イベント", PaperWidth: 42, TaxRate: 10}
	text := decodeSJIS(t, FormatShiftReport(cfg, sampleShiftReport()))

	// Header: store, title, register + settlement number, phone, period.
	for _, want := range []string{
		"ベト屋イベント", shiftReportTitle, "レジ0001", "No.00003",
		"対象期間 2026/07/03 16:57", "2026/07/03 17:09", "電話番号 0368457586",
	} {
		if !strings.Contains(text, want) {
			t.Errorf("header missing %q\n---\n%s", want, text)
		}
	}

	// Sales summary + tax breakdown (single figure).
	for _, want := range []string{
		"総売上", "3,775円", "5個", "純売上", "3,460円", "5人", "消費税総額", "315円",
		"売上内訳", "課税売上", "消費税内訳",
	} {
		if !strings.Contains(text, want) {
			t.Errorf("sales section missing %q\n---\n%s", want, text)
		}
	}

	// Payment methods with counts + amounts.
	for _, want := range []string{
		"支払方法", "現金", "2件", "2,000円", "PayPay", "800円",
		"クレジットカード", "750円", "電子マネー", "225円",
	} {
		if !strings.Contains(text, want) {
			t.Errorf("payment section missing %q\n---\n%s", want, text)
		}
	}

	// Discount, check count, drawer check.
	for _, want := range []string{
		"割引・割増", "▲225円", "個別割引", "会計回数",
		"レジ点検", "レジ金額", "想定レジ金額", "過不足", "担当者", "未設定",
	} {
		if !strings.Contains(text, want) {
			t.Errorf("footer section missing %q\n---\n%s", want, text)
		}
	}
}

// TestFormatShiftReport_ReferenceLayoutRefinements locks the two layout details
// that align the slip with the reference 精算 photo: a blank line grouping the
// sales figures apart from 消費税総額, and a divider splitting 会計修正 from
// 会計回数 into separate sections.
func TestFormatShiftReport_ReferenceLayoutRefinements(t *testing.T) {
	cfg := PrintJobConfig{StoreName: "ベト屋イベント", PaperWidth: 42, TaxRate: 10}
	text := decodeSJIS(t, FormatShiftReport(cfg, sampleShiftReport()))
	lines := strings.Split(text, "\n")

	indexOf := func(sub string) int {
		for i, ln := range lines {
			if strings.Contains(ln, sub) {
				return i
			}
		}
		return -1
	}
	isBlank := func(i int) bool {
		return i >= 0 && i < len(lines) && strings.TrimSpace(lines[i]) == ""
	}
	isDivider := func(i int) bool {
		return i >= 0 && i < len(lines) && strings.Contains(lines[i], "- -")
	}

	// A blank line immediately precedes 消費税総額.
	taxTotal := indexOf("消費税総額")
	if taxTotal < 1 {
		t.Fatalf("消費税総額 line not found\n---\n%s", text)
	}
	if !isBlank(taxTotal - 1) {
		t.Errorf("expected a blank line before 消費税総額, got %q\n---\n%s", lines[taxTotal-1], text)
	}

	// A divider separates 会計修正 from 会計回数.
	corr := indexOf("会計修正")
	checks := indexOf("会計回数")
	if corr < 0 || checks <= corr {
		t.Fatalf("会計修正 (%d) / 会計回数 (%d) not in expected order\n---\n%s", corr, checks, text)
	}
	dividerBetween := false
	for i := corr + 1; i < checks; i++ {
		if isDivider(i) {
			dividerBetween = true
			break
		}
	}
	if !dividerBetween {
		t.Errorf("expected a divider between 会計修正 and 会計回数\n---\n%s", text)
	}

	// The closing date prints bare — no ～ range marker anywhere on the slip.
	if strings.Contains(text, "～") {
		t.Errorf("slip should not contain the ～ range marker\n---\n%s", text)
	}
}

// assertSizeBeforeName checks that sizeCmd is applied to the store name: it
// appears in the byte stream before the name's encoded bytes, with no
// NormalSize reset in between.
func assertSizeBeforeName(t *testing.T, raw []byte, name string, sizeCmd []byte) {
	t.Helper()
	nameBytes, _, err := transform.Bytes(japanese.ShiftJIS.NewEncoder(), []byte(name))
	if err != nil {
		t.Fatalf("encode store name: %v", err)
	}
	nameIdx := bytes.Index(raw, nameBytes)
	szIdx := bytes.Index(raw, sizeCmd)
	if nameIdx < 0 || szIdx < 0 {
		t.Fatalf("store name (idx %d) or size command (idx %d) not found in output", nameIdx, szIdx)
	}
	if szIdx >= nameIdx {
		t.Errorf("size command (idx %d) must precede the store name (idx %d)", szIdx, nameIdx)
	}
	if reset := bytes.Index(raw, escpos.NormalSize); reset > szIdx && reset < nameIdx {
		t.Errorf("NormalSize reset (idx %d) between size command (%d) and store name (%d)", reset, szIdx, nameIdx)
	}
}

// TestFormatShiftReport_StoreNameEnlarged asserts the store name is the hero
// header: double-SIZE when it fits, falling back to double-height (never
// double-width) for a name too long to fit — so it can't overflow the paper.
func TestFormatShiftReport_StoreNameEnlarged(t *testing.T) {
	// Short name fits at double-width → double-size.
	shortName := "ベト屋イベント"
	raw := FormatShiftReport(PrintJobConfig{StoreName: shortName, PaperWidth: 42}, sampleShiftReport())
	assertSizeBeforeName(t, raw, shortName, escpos.DoubleSize)

	// A name too wide for double-width → falls back to double-height, and must
	// NOT be double-size (which would overflow the paper edge).
	longName := strings.Repeat("あ", 30)
	rawLong := FormatShiftReport(PrintJobConfig{StoreName: longName, PaperWidth: 42}, sampleShiftReport())
	assertSizeBeforeName(t, rawLong, longName, escpos.DoubleHeight)

	longBytes, _, _ := transform.Bytes(japanese.ShiftJIS.NewEncoder(), []byte(longName))
	longIdx := bytes.Index(rawLong, longBytes)
	if ds := bytes.Index(rawLong, escpos.DoubleSize); ds >= 0 && ds < longIdx {
		t.Errorf("long store name must not be double-size (would overflow paper)")
	}
}

// TestFormatShiftReport_TitleNotStretched asserts the 精算 title renders
// double-SIZE (proportional 2×2), immediately preceded by the DoubleSize
// command — NOT double-height only, which would stretch the glyphs tall/narrow.
func TestFormatShiftReport_TitleNotStretched(t *testing.T) {
	raw := FormatShiftReport(PrintJobConfig{StoreName: "ベト屋イベント", PaperWidth: 42}, sampleShiftReport())
	titleBytes, _, err := transform.Bytes(japanese.ShiftJIS.NewEncoder(), []byte(shiftReportTitle))
	if err != nil {
		t.Fatalf("encode title: %v", err)
	}
	ti := bytes.Index(raw, titleBytes)
	if ti < len(escpos.DoubleSize) {
		t.Fatalf("title %q not found (idx %d)", shiftReportTitle, ti)
	}
	if got := raw[ti-len(escpos.DoubleSize) : ti]; !bytes.Equal(got, escpos.DoubleSize) {
		t.Errorf("title must be immediately preceded by DoubleSize, got % X want % X", got, escpos.DoubleSize)
	}
}

// TestFormatShiftReport_SectionToggles verifies each optional section is gated
// by its flag: on → present, off → absent, without affecting the always-on
// sections.
func TestFormatShiftReport_SectionToggles(t *testing.T) {
	cfg := PrintJobConfig{StoreName: "ベト屋", PaperWidth: 42, Locale: "ja"}

	// All on → 支払方法 / サービス料 / レジ点検 / 金種 all present.
	on := decodeSJIS(t, FormatShiftReport(cfg, sampleShiftReport()))
	for _, want := range []string{"支払方法", "サービス料", "レジ点検", "金種"} {
		if !strings.Contains(on, want) {
			t.Errorf("all-on slip missing %q\n---\n%s", want, on)
		}
	}

	// All off → those four gone; the always-on sections stay.
	info := sampleShiftReport()
	info.ShowPaymentMethods = false
	info.ShowServiceCharge = false
	info.ShowDrawerCheck = false
	info.ShowDenominations = false
	off := decodeSJIS(t, FormatShiftReport(cfg, info))
	for _, gone := range []string{"支払方法", "サービス料", "レジ点検", "金種"} {
		if strings.Contains(off, gone) {
			t.Errorf("all-off slip should not contain %q\n---\n%s", gone, off)
		}
	}
	for _, keep := range []string{"総売上", "純売上", "割引・割増", "会計回数", "伝票削除"} {
		if !strings.Contains(off, keep) {
			t.Errorf("all-off slip lost always-on section %q", keep)
		}
	}
}

func TestFormatShiftReport_SignedVarianceAndOperator(t *testing.T) {
	info := sampleShiftReport()
	info.CountedCash = 2100
	info.ExpectedCash = 2000
	info.CashVariance = 100
	info.Operator = "田中"
	text := decodeSJIS(t, FormatShiftReport(PrintJobConfig{PaperWidth: 42}, info))

	if !strings.Contains(text, "+100円") {
		t.Errorf("positive variance should print with + sign\n---\n%s", text)
	}
	if !strings.Contains(text, "田中") {
		t.Errorf("operator name should print when set\n---\n%s", text)
	}
	if strings.Contains(text, "未設定") {
		t.Errorf("operator fallback should not appear when a name is set\n---\n%s", text)
	}
}

// TestFormatShiftReport_LocaleEN renders the slip in English and asserts the
// section labels + localized cash tender all switched away from Japanese.
func TestFormatShiftReport_LocaleEN(t *testing.T) {
	info := sampleShiftReport()
	// Give the cash line a code so the tender maps to the localized "Cash".
	info.Payments[0].Code = "cash"
	cfg := PrintJobConfig{StoreName: "ベト屋イベント", PaperWidth: 42, TaxRate: 10, Locale: "en"}
	text := decodeSJIS(t, FormatShiftReport(cfg, info))

	for _, want := range []string{
		"SETTLEMENT", "Reg0001", "Period 2026/07/03 16:57",
		"Gross sales", "5 items", "Net sales", "5 guests", "Total tax",
		"Payment methods", "Cash", "Payment methods", "Drawer check",
		"Counted cash", "Expected cash", "Variance", "Operator", "(not set)",
	} {
		if !strings.Contains(text, want) {
			t.Errorf("EN slip missing %q\n---\n%s", want, text)
		}
	}
	// No Japanese section labels should leak through.
	for _, jp := range []string{"精算", "総売上", "支払方法", "レジ点検", "担当者", "未設定"} {
		if strings.Contains(text, jp) {
			t.Errorf("EN slip should not contain Japanese label %q\n---\n%s", jp, text)
		}
	}
}

// TestFormatShiftReport_LocaleVI asserts the Vietnamese slip renders localized
// labels AND — critically — contains no combining diacritics, because the
// Shift_JIS printer path cannot encode them (see print_shift_report_i18n.go).
func TestFormatShiftReport_LocaleVI(t *testing.T) {
	info := sampleShiftReport()
	info.Payments[0].Code = "cash"
	cfg := PrintJobConfig{StoreName: "ベト屋イベント", PaperWidth: 42, TaxRate: 10, Locale: "vi"}
	slip := FormatShiftReport(cfg, info)
	text := decodeSJIS(t, slip)

	for _, want := range []string{
		"KET CA", "Quay0001", "Tong doanh thu", "Doanh thu thuan",
		"Phuong thuc TT", "Tien mat", "Kiem quy", "Tien dem",
		"Tien du kien", "Chenh lech", "Nguoi phu trach", "(chua dat)",
	} {
		if !strings.Contains(text, want) {
			t.Errorf("VI slip missing %q\n---\n%s", want, text)
		}
	}
	// The whole rendered slip must be pure ASCII in the label ranges — any
	// Vietnamese dấu would mean an un-encodable rune slipped in. We assert the
	// decoded text has no runes in the Latin-1 supplement / combining blocks.
	for _, r := range text {
		if r > 0x7F && !isAllowedNonASCII(r) {
			t.Errorf("VI slip contains non-ASCII rune %q (U+%04X) — Shift_JIS can't print it\n---\n%s", r, r, text)
		}
	}
}

// isAllowedNonASCII whitelists the few non-ASCII glyphs the VI slip legitimately
// carries: the JPY 円 suffix isn't used (currency stays JPY→円 only when
// currency=JPY, but VI stores use their own currency), the ▲ discount marker,
// and the fullwidth store name in the header. We only guard the label body, so
// allow the store-name kana + ▲ + 円.
func isAllowedNonASCII(r rune) bool {
	switch r {
	case '▲', '円':
		return true
	}
	// Store name "ベト屋イベント" is caller-supplied kana/kanji, not a label —
	// permit CJK so the test targets label diacritics, not the header.
	return r >= 0x3000
}

func TestFormatShiftReport_NonJPYCurrencySuffix(t *testing.T) {
	info := sampleShiftReport()
	info.Currency = "VND"
	text := decodeSJIS(t, FormatShiftReport(PrintJobConfig{PaperWidth: 42}, info))
	if !strings.Contains(text, "3,775 VND") {
		t.Errorf("non-JPY currency should suffix the ISO code\n---\n%s", text)
	}
	if strings.Contains(text, "円") {
		t.Errorf("non-JPY report should not print the 円 glyph\n---\n%s", text)
	}
}

// TestFormatShiftReport_PerRateBreakdown proves the 消費税内訳 / 売上内訳 sections
// break into per-rate rows (課税売上 + 消費税 for each rate) when the toggle is on
// AND the shift carries per-line snapshots (plan-043 T4.2).
//
// Defined truth (decoded from Shift_JIS), a mixed 8%/10% shift:
//
//	売上内訳
//	  8%対象                          1,000円
//	  10%対象                           500円
//	消費税内訳
//	  8%対象                             80円
//	  10%対象                            50円
func TestFormatShiftReport_PerRateBreakdown(t *testing.T) {
	info := sampleShiftReport()
	info.ShowTaxBreakdown = true
	info.TaxBreakdown = []ShiftTaxRateLine{
		{Rate: 8, TaxableSales: 1000, Tax: 80},
		{Rate: 10, TaxableSales: 500, Tax: 50},
	}
	text := decodeSJIS(t, FormatShiftReport(PrintJobConfig{PaperWidth: 42}, info))

	for _, want := range []string{
		"売上内訳", "8%対象", "1,000円", "10%対象", "500円",
		"消費税内訳", "80円", "50円",
	} {
		if !strings.Contains(text, want) {
			t.Errorf("per-rate shift breakdown missing %q\n---\n%s", want, text)
		}
	}
	// The collapsed single 課税売上 row is replaced by the per-rate rows.
	if strings.Contains(text, "課税売上") {
		t.Errorf("per-rate mode should replace the single 課税売上 row\n%s", text)
	}
}

// TestFormatShiftReport_PerRateToggleOff proves the toggle gates the thermal
// per-rate rows: with ShowTaxBreakdown=false the slip collapses to the single
// 課税売上 / 消費税 figure even when TaxBreakdown data is present.
func TestFormatShiftReport_PerRateToggleOff(t *testing.T) {
	info := sampleShiftReport()
	info.ShowTaxBreakdown = false
	info.TaxBreakdown = []ShiftTaxRateLine{
		{Rate: 8, TaxableSales: 1000, Tax: 80},
		{Rate: 10, TaxableSales: 500, Tax: 50},
	}
	text := decodeSJIS(t, FormatShiftReport(PrintJobConfig{PaperWidth: 42}, info))

	if !strings.Contains(text, "課税売上") {
		t.Errorf("toggle off must keep the single 課税売上 row\n%s", text)
	}
	if strings.Contains(text, "8%対象") {
		t.Errorf("toggle off must NOT print per-rate rows\n%s", text)
	}
}

// TestFormatShiftReport_PerRateEmptyFallback proves a legacy shift (no per-line
// snapshots → empty TaxBreakdown) collapses to the single figure even with the
// toggle on.
func TestFormatShiftReport_PerRateEmptyFallback(t *testing.T) {
	info := sampleShiftReport()
	info.ShowTaxBreakdown = true
	info.TaxBreakdown = nil
	text := decodeSJIS(t, FormatShiftReport(PrintJobConfig{PaperWidth: 42}, info))
	if !strings.Contains(text, "課税売上") {
		t.Errorf("empty breakdown must fall back to the single 課税売上 row\n%s", text)
	}
}
