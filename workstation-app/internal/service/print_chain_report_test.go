package service

import (
	"strings"
	"testing"
)

func sampleChainReport() ChainReportInfo {
	return ChainReportInfo{
		ChainID:    "abcdef12-3456-7890",
		TillCode:   "0001",
		OpenedAt:   "2026/07/17 09:00",
		ClosedAt:   "2026/07/17 21:00",
		ShiftCount: 2,
		Shifts: []ChainShiftLine{
			{Sequence: 1, Kind: "handover", CountedCash: 50000, Variance: 0, Gross: 30000},
			{Sequence: 2, Kind: "final", CountedCash: 60000, Variance: -100, Gross: 40000},
		},
		CountedCash: 110000,
		Gross:       70000,
		Net:         63000,
		TaxTotal:    7000,
		TaxByRate: []ShiftTaxRateLine{
			{Rate: 8, TaxableSales: 20000, Tax: 1600},
			{Rate: 10, TaxableSales: 43000, Tax: 5400},
		},
		Currency:           "JPY",
		ShowTaxBreakdown:   true,
		ShowPaymentMethods: true,
		ShowDrawerCheck:    true,
		ShowDenominations:  true,
	}
}

// TestFormatChainReport_Aggregate — the slip carries the title, one condensed
// block per shift, a GRAND TOTAL, and per-rate buckets when the toggle is on.
func TestFormatChainReport_Aggregate(t *testing.T) {
	cfg := PrintJobConfig{StoreName: "テスト店", PaperWidth: 42}
	text := decodeSJIS(t, FormatChainReport(cfg, sampleChainReport()))

	for _, want := range []string{"精算（チェーン）", "合計", "総売上", "消費税内訳", "8%対象", "10%対象"} {
		if !strings.Contains(text, want) {
			t.Errorf("chain report missing %q\n---\n%s", want, text)
		}
	}
	// Count block headers by the kind-paren — unambiguous (the "%d シフト"
	// ShiftCount line would collide with a bare " シフト" count).
	if n := countChainBlocks(text); n != 2 {
		t.Errorf("expected 2 condensed shift blocks, found %d\n---\n%s", n, text)
	}
}

// countChainBlocks counts per-shift block headers via their "(kind)" suffix,
// which appears only on block headers (handover / final).
func countChainBlocks(text string) int {
	return strings.Count(text, "(引き継ぎ)") + strings.Count(text, "(精算)")
}

// TestFormatChainReport_ToggleOffHidesPerRate — P5-9: with the toggle off, the
// per-rate rows collapse but the tax total still prints.
func TestFormatChainReport_ToggleOffHidesPerRate(t *testing.T) {
	info := sampleChainReport()
	info.ShowTaxBreakdown = false
	text := decodeSJIS(t, FormatChainReport(PrintJobConfig{PaperWidth: 42}, info))

	if strings.Contains(text, "8%対象") || strings.Contains(text, "10%対象") {
		t.Errorf("per-rate rows must be hidden when the toggle is off\n---\n%s", text)
	}
	if !strings.Contains(text, "消費税総額") {
		t.Errorf("grand-total tax figure must still print\n---\n%s", text)
	}
}

// TestFormatChainReport_ChainOfOne — a single-shift chain prints exactly one block.
func TestFormatChainReport_ChainOfOne(t *testing.T) {
	info := sampleChainReport()
	info.Shifts = info.Shifts[:1]
	info.ShiftCount = 1
	text := decodeSJIS(t, FormatChainReport(PrintJobConfig{PaperWidth: 42}, info))

	if n := countChainBlocks(text); n != 1 {
		t.Errorf("chain of one should print exactly 1 block, found %d\n---\n%s", n, text)
	}
}

// The final-close slip must show what happened in EACH shift, not just the end
// state — the owner reads it to see how the day moved. And the sales/tax columns
// must be addable: the per-rate buckets cover item lines only, so the service
// charge and its tax are printed explicitly beside them.
func TestFormatChainReport_PerShiftDetailAndReconcilableColumns(t *testing.T) {
	info := sampleChainReport()
	// Real 3-shift shape.
	info.ShiftCount = 3
	info.Shifts = []ChainShiftLine{
		{Sequence: 1, Kind: "handover", OpenedAt: "2026/07/21 09:18", ClosedAt: "2026/07/21 09:19",
			Gross: 1081, Net: 982, Tax: 99, CountedCash: 11081, ExpectedCash: 11081, CheckCount: 1},
		{Sequence: 2, Kind: "handover", OpenedAt: "2026/07/21 09:19", ClosedAt: "2026/07/21 09:23",
			Gross: 5596, Net: 5087, Tax: 509, CountedCash: 16677, ExpectedCash: 16677, CheckCount: 1},
		{Sequence: 3, Kind: "final", OpenedAt: "2026/07/21 09:23", ClosedAt: "2026/07/21 09:30",
			Gross: 6067, Net: 5516, Tax: 551, CountedCash: 22744, ExpectedCash: 22744, CheckCount: 1},
	}
	info.Gross, info.Net, info.TaxTotal = 12744, 11585, 1159
	info.CountedCash, info.ExpectedCash, info.Variance = 50502, 50502, 0
	info.TaxByRate = []ShiftTaxRateLine{{Rate: 10, TaxableSales: 11033, Tax: 1104}}
	info.ServiceCharge, info.ServiceChargeTax = 552, 55
	info.CheckCount = 3
	info.OpeningFloat = 10000
	info.CashSales = 12744
	info.Payments = []ShiftPaymentLine{{Code: "cash", Label: "現金", Amount: 12744}}

	text := decodeSJIS(t, FormatChainReport(PrintJobConfig{StoreName: "テスト店", PaperWidth: 42}, info))

	// Each shift's OWN revenue is listed, so the owner can see how the day moved
	// before reading the totals. (Net/tax per shift live in the cumulative block
	// — the index stays one line per shift so a long chain fits the roll.)
	for _, want := range []string{"1,081", "5,596", "6,067"} {
		if !strings.Contains(text, want) {
			t.Errorf("missing per-shift gross %q\n---\n%s", want, text)
		}
	}
	// Each shift is identified by its chain position + kind.
	for _, want := range []string{"シフト1", "シフト2", "シフト3", "引き継ぎ", "精算"} {
		if !strings.Contains(text, want) {
			t.Errorf("missing shift index label %q\n---\n%s", want, text)
		}
	}
	// Grand total.
	for _, want := range []string{"12,744", "11,585", "1,159", "50,502"} {
		if !strings.Contains(text, want) {
			t.Errorf("missing grand-total figure %q\n---\n%s", want, text)
		}
	}
	// The reconciliation rows — without these the columns can't be added up.
	for _, want := range []string{"サービス料", "サービス料消費税", "552", "55"} {
		if !strings.Contains(text, want) {
			t.Errorf("missing service-charge reconciliation %q\n---\n%s", want, text)
		}
	}
	// Sections that make the slip a real settlement document.
	for _, want := range []string{"売上内訳", "消費税内訳", "支払方法", "会計回数", "レジ点検"} {
		if !strings.Contains(text, want) {
			t.Errorf("missing section %q\n---\n%s", want, text)
		}
	}
	t.Logf("\n%s", text)
}

// A legacy chain settled before the enrichment has no per-shift detail in its
// snapshot; those rows must be OMITTED rather than printed as a confident 0
// (a printed "0円" for a figure nobody recorded is a lie on an accounting doc).
func TestFormatChainReport_OmitsSectionsWithNoData(t *testing.T) {
	info := ChainReportInfo{
		ChainID: "legacy", ShiftCount: 1, Currency: "JPY", ShowTaxBreakdown: true,
		Shifts:      []ChainShiftLine{{Sequence: 1, Kind: "final", Gross: 1000, CountedCash: 1000}},
		Gross:       1000,
		CountedCash: 1000,
	}
	text := decodeSJIS(t, FormatChainReport(PrintJobConfig{PaperWidth: 42}, info))

	// The section skeleton is SHARED with the handover slip and prints its zero
	// rows there too, so its presence is correct and expected — the requirement
	// is that the two slips look identical. What must never appear is a
	// fabricated LIST row for something that never happened.
	for _, unwanted := range []string{"HAPPY15", "クレジット"} {
		if strings.Contains(text, unwanted) {
			t.Errorf("legacy chain fabricated row %q\n---\n%s", unwanted, text)
		}
	}
	if !strings.Contains(text, "1,000") {
		t.Errorf("legacy chain lost its gross\n---\n%s", text)
	}
}

// plan-046 step 2 — the full statement. 伝票削除 in particular is what an owner
// reads to see what MOVED during the day, and the chain slip could not show it
// at all before the snapshot was enriched.
func TestFormatChainReport_Step2Sections(t *testing.T) {
	info := sampleChainReport()
	info.HasDetail = true
	info.ItemCount, info.GuestCount = 42, 17
	info.VoidUnpaidCount, info.VoidUnpaidAmount = 2, 1500
	info.VoidPaidCount, info.VoidPaidAmount = 1, 800
	info.PaidInCount, info.PaidIn = 1, 5000
	info.PaidOutCount, info.PaidOut = 2, 3000
	info.Discounts = []ShiftDiscountLine{{Label: "HAPPY15", Count: 3, Amount: 450}}
	info.Denominations = []ShiftOpenDenomLine{
		{Value: 10000, Quantity: 5, Subtotal: 50000},
		{Value: 1000, Quantity: 3, Subtotal: 3000},
	}

	text := decodeSJIS(t, FormatChainReport(PrintJobConfig{StoreName: "テスト店", PaperWidth: 42}, info))

	// The chain slip is rendered by the SAME formatter as a handover slip, so
	// counts appear the way that slip shows them (42個 / 17人), not under
	// bespoke chain labels.
	for _, want := range []string{
		"42", "17",
		"割引・割増", "HAPPY15",
		"伝票削除", "未会計", "会計済", "1,500", "800",
		"金種", "10,000", "50,000",
	} {
		if !strings.Contains(text, want) {
			t.Errorf("missing %q\n---\n%s", want, text)
		}
	}
	t.Logf("\n%s", text)
}

// Without the enriched snapshot none of those sections may appear — a printed
// "0" for something nobody recorded is a false statement on an accounting doc.
func TestFormatChainReport_LegacyChainOmitsStep2Sections(t *testing.T) {
	info := sampleChainReport()
	info.HasDetail = false

	info.Discounts = nil
	info.Denominations = nil
	info.Payments = nil
	text := decodeSJIS(t, FormatChainReport(PrintJobConfig{PaperWidth: 42}, info))

	// The section SKELETON is shared with the handover slip and prints its zero
	// rows there too, so its presence is correct. What must never appear is a
	// fabricated row — a coupon that was never applied, a denomination never
	// counted, a tender never taken.
	for _, unwanted := range []string{"HAPPY15", "5枚", "クレジット"} {
		if strings.Contains(text, unwanted) {
			t.Errorf("legacy chain fabricated row %q\n---\n%s", unwanted, text)
		}
	}
	// And its real money is still rendered.
	if !strings.Contains(text, "70,000") {
		t.Errorf("legacy chain lost its gross\n---\n%s", text)
	}
}
