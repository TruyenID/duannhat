package service

import (
	"fmt"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// shiftReportTitle is the big centered header ("Settlement" / 精算) — the
// Japanese POS convention for a cashier-shift close (Z) report. Kept as a
// package const so tests + future locale overrides have a single anchor.
const shiftReportTitle = "精算"

// ShiftPaymentLine is one 支払方法 (payment-method) breakdown row: the tender
// label, how many transactions used it (件数), and the collected amount.
type ShiftPaymentLine struct {
	Code   string // tender code (payment_method) — drives locale mapping; empty falls back to Label
	Label  string // Cloud-synced name (payment_methods.name), e.g. "現金", "PayPay"
	Count  int    // 件数 — number of payments with this method
	Amount int    // collected amount in the session currency's minor-free unit
}

// ShiftDiscountLine is one 割引・割増 row — a named discount (coupon code) with
// its apply count + total discounted amount (always rendered as a ▲ deduction).
type ShiftDiscountLine struct {
	Label  string
	Count  int
	Amount int // positive magnitude; printed with a "▲" prefix
}

// ShiftTaxRateLine is one per-rate row in the 消費税内訳 / 売上内訳 breakdown:
// the taxable sales (課税売上) and consumption tax (消費税) for a single rate,
// derived from the shift's orders' per-line tax snapshots (plan-043 T4.2).
type ShiftTaxRateLine struct {
	Rate         float64 // e.g. 8, 10 — heads the row as "8%対象"
	TaxableSales int     // 課税売上 for this rate
	Tax          int     // 消費税 for this rate
}

// ShiftReportInfo is the fully-aggregated 精算 (settlement / Z-report) payload.
// The handler builds this from local SQLite (orders / payments / till_* tables)
// and hands it to FormatShiftReport for pure, testable ESC/POS rendering —
// same split as FormatDebtSlip / FormatPaidTicket.
//
// Amounts are whole units (integer yen at the workstation layer). The layout
// mirrors the Japanese thermal 精算 slip: a sales summary, a tax breakdown, a
// per-method payment breakdown, discount / cash-movement / void audit rows,
// and a closing レジ点検 (drawer check) block.
//
// Tax-rate split (plan-043 T4.2): the 売上内訳 / 消費税内訳 sections break the
// consumption tax into per-rate rows (課税売上 + 消費税 for each rate), derived
// from the shift's orders' per-line tax snapshots. This is gated behind the
// synced close_report_tax_breakdown setting (ShowTaxBreakdown) — the THERMAL
// print only; the Z-report PDF always includes it (Decision 8). When the
// toggle is off, OR no order carries per-line snapshots (legacy shift), the
// sections collapse to the single TaxTotal figure as before.
type ShiftReportInfo struct {
	// Header
	ReportKind string // plan-046 — "handover" prints 引き継ぎ; anything else (default) prints 精算
	// ChainSequence is this shift's 1-based position in its chain. 0 hides the
	// row (no chain, or a legacy session). Without it a stack of same-day
	// handover slips is indistinguishable — same till, near-identical period.
	ChainSequence int

	// ── Chain (kết ca cuối) mode ──
	// When IsChain is set the SAME renderer produces the chain aggregate slip:
	// a chain header + a compact per-shift index, then the identical body from
	// cumulative figures. The operator asked for the final-close report to look
	// exactly like a handover report, only totalled across the chain; sharing
	// one renderer is what guarantees that, where a second hand-written layout
	// would drift the moment either was touched.
	IsChain    bool
	ChainID    string
	ShiftCount int
	ChainIndex []ChainShiftLine
	TillCode   string // レジ{code}
	ZNumber    int    // No.{ZNumber} — ordinal of settled shifts on this till
	Phone      string // 電話番号 — optional; omitted when empty
	OpenedAt   string // preformatted "2006/01/02 15:04"
	ClosedAt   string // preformatted "2006/01/02 15:04"

	// Sales summary
	GrossSales int // 総売上 (tax-included)
	ItemCount  int // 個 — total item quantity sold
	NetSales   int // 純売上 (= GrossSales − TaxTotal)
	GuestCount int // 人 — total covers
	TaxTotal   int // 消費税総額

	// Payment methods (支払方法)
	Payments []ShiftPaymentLine

	// Discounts (割引・割増)
	Discounts           []ShiftDiscountLine
	DiscountTotalCount  int
	DiscountTotalAmount int

	// Counts / cash movement
	CheckCount    int // 会計回数
	PaidInCount   int // 入金 件数
	PaidInAmount  int
	PaidOutCount  int // 出金 件数
	PaidOutAmount int

	// Void audit (伝票削除)
	VoidUnpaidCount  int // 未会計
	VoidUnpaidAmount int
	VoidPaidCount    int // 会計済
	VoidPaidAmount   int

	// Drawer check (レジ点検)
	CountedCash  int    // レジ金額
	ExpectedCash int    // 想定レジ金額
	CashVariance int    // 過不足 (= CountedCash − ExpectedCash, signed)
	Operator     string // 担当者 — falls back to "未設定" when empty

	Currency string // e.g. "JPY" → 円 suffix; other codes suffix the code

	// サービス料 (service charge) — amount is 0 until the workstation aggregates
	// it; the LINE is only printed when ShowServiceCharge is set.
	ServiceCharge int
	// ServiceChargeTax is the tax charged ON the service charge. Together with
	// ServiceCharge it closes the reconciliation gap in the per-rate breakdown:
	// the rate buckets are built from ITEM lines only, so without these two rows
	// Σ(売上内訳) is short of 純売上 and Σ(消費税内訳) is short of 消費税総額 —
	// an auditor totalling either column finds it disagreeing with the figure
	// printed above it. Rendered only when non-zero.
	ServiceChargeTax int

	// 金種 — closing cash-count denomination breakdown (high value first).
	// Printed only when ShowDenominations is set.
	Denominations []ShiftOpenDenomLine

	// TaxBreakdown — per-rate 課税売上 + 消費税 rows (plan-043 T4.2), sorted by
	// rate ascending. Empty when the shift has no per-line snapshots (legacy) —
	// the formatter then falls back to the single collapsed figure.
	TaxBreakdown []ShiftTaxRateLine

	// Optional-section toggles, resolved from shop_settings (close_report_*).
	// Default true → the full report; false hides that section on the slip.
	ShowPaymentMethods bool // 支払方法
	ShowServiceCharge  bool // サービス料
	ShowDrawerCheck    bool // レジ点検
	ShowDenominations  bool // 金種
	// ShowTaxBreakdown gates the per-rate 消費税内訳 / 売上内訳 rows on the
	// THERMAL slip (close_report_tax_breakdown). Off → collapse to one figure.
	ShowTaxBreakdown bool // 消費税内訳 per-rate
}

// moneyUnit returns the currency suffix for the report. JPY (the overwhelming
// deployment) renders the native 円 glyph like the sample slip; any other code
// falls back to a spaced ISO code so the number is never ambiguous.
func moneyUnit(currency string) string {
	if currency == "" || strings.EqualFold(currency, "JPY") {
		return "円"
	}
	return " " + strings.ToUpper(currency)
}

// FormatShiftReport renders the 精算 (cashier-shift settlement) thermal slip.
//
// Layout (42-col paper, matches FormatPaidTicket / FormatDebtSlip conventions):
//
//	          {StoreName}
//	            精算
//	                              レジ0001
//	                              No.00003
//	                 対象期間 2026/07/03 16:57
//	                        2026/07/03 17:09
//	                        電話番号 0368457586
//	- - - - - - - - - - - - - - - - - - - - -
//	総売上                            3,775円
//	                                     5個
//	純売上                            3,460円
//	                                      5人
//	消費税総額                          315円
//	- - - - - - - - - - - - - - - - - - - - -
//	売上内訳
//	  課税売上                        3,460円
//	消費税内訳
//	  消費税                            315円
//	- - - - - - - - - - - - - - - - - - - - -
//	支払方法
//	  現金               2件        2,000円
//	  PayPay             1件          800円
//	... (現金以外おつり / 割引・割増 / 会計 / 入出金 / 伝票削除) ...
//	- - - - - - - - - - - - - - - - - - - - -
//	レジ点検
//	  レジ金額                        2,000円
//	  想定レジ金額                    2,000円
//	  過不足                              0円
//	  担当者                          未設定
func FormatShiftReport(config PrintJobConfig, info ShiftReportInfo) []byte {
	w := config.PaperWidth
	if w == 0 {
		w = 42
	}
	L := labelsFor(config.Locale)
	unit := moneyUnit(info.Currency)

	// money formats a whole-unit amount with thousands separators + currency
	// suffix, carrying a leading "-" for negatives (過不足 can go short).
	money := func(n int) string {
		if n < 0 {
			return "-" + formatPrice(-n) + unit
		}
		return formatPrice(n) + unit
	}

	e := escpos.New()
	e.SetLeftMargin(config.leftMargin(w))

	// row: label flush-left, value flush-right, ≥1 space gap. Uses DISPLAY
	// width (CJK = 2 cols) so Kanji labels stay aligned — utf8.RuneCount would
	// under-count and skew the right edge.
	row := func(label, value string) {
		gap := max(w-displayWidth(label)-displayWidth(value), 1)
		e.Line(label + spaces(gap) + value)
	}
	// right prints a single value flush-right (the 個 / 人 sub-lines).
	right := func(value string) {
		gap := max(w-displayWidth(value), 0)
		e.Line(spaces(gap) + value)
	}
	// padLeft right-aligns s within a fixed display-width column so the
	// count (件) and amount columns line up across payment/movement rows.
	padLeft := func(s string, width int) string {
		dw := displayWidth(s)
		if dw >= width {
			return s
		}
		return spaces(width-dw) + s
	}
	const cntCol, amtCol = 6, 12
	// statValue builds the right-hand "{n}件      {amount}" block shared by the
	// payment / cash-movement / void rows. The count unit is localized (件 / x).
	statValue := func(count int, amount string) string {
		return padLeft(fmt.Sprintf("%d%s", count, L.CountUnit), cntCol) + padLeft(amount, amtCol)
	}
	// sep draws a single divider line flush against the content on both sides
	// — no padding feeds. A long settlement slip has ~9 sections, so a blank
	// line above+below each divider would nearly double the paper used.
	sep := func() {
		e.Line(dashedLine(w))
	}

	// ─── header: store name + 精算 title (centered), meta block (right) ───
	// Both the store name and the 精算 title render double-SIZE (2× height AND
	// width) so the glyphs scale proportionally — double-HEIGHT alone stretches
	// them tall + narrow, which reads as distorted. The store name additionally
	// falls back to double-height when it's too wide to fit the paper at 2×, so
	// a long name never overflows the edge; the short fixed-length title always
	// fits, so it stays double-size unconditionally.
	e.Align(escpos.AlignCenter)
	e.Bold(true)
	if name := strings.TrimSpace(config.StoreName); name != "" {
		nameSize := escpos.DoubleSize
		if displayWidth(name)*2 > w {
			nameSize = escpos.DoubleHeight // too wide for 2× — keep it tall only
		}
		e.Size(nameSize)
		e.Line(name)
	}
	e.Size(escpos.DoubleSize)
	title := L.Title
	if info.ReportKind == "handover" {
		title = L.HandoverTitle // plan-046 — 引き継ぎ / HANDOVER / BAN GIAO CA
	}
	if info.IsChain {
		title = L.ChainTitle // 精算（チェーン） — the whole-chain aggregate
	}
	e.Line(title)
	e.Size(escpos.NormalSize)
	e.Bold(false)

	e.Align(escpos.AlignRight)
	if info.IsChain {
		chainShort := info.ChainID
		if len(chainShort) > 8 {
			chainShort = chainShort[:8]
		}
		e.Line(L.ChainLabel + " " + chainShort)
		e.Line(fmt.Sprintf("%d", info.ShiftCount) + L.ChainShiftUnit)
	} else {
		if code := strings.TrimSpace(info.TillCode); code != "" {
			e.Line(L.Till + code)
		}
		e.Line(fmt.Sprintf("No.%05d", info.ZNumber))
		if info.ChainSequence > 0 {
			e.Line(fmt.Sprintf("%s%d", L.ChainShift, info.ChainSequence))
		}
	}
	if info.OpenedAt != "" {
		e.Line(L.Period + " " + info.OpenedAt)
	}
	if info.ClosedAt != "" {
		// Closing date prints bare (no ～ prefix) — matches the reference slip.
		e.Line(info.ClosedAt)
	}
	if phone := strings.TrimSpace(info.Phone); phone != "" {
		e.Line(L.Phone + " " + phone)
	}
	e.Align(escpos.AlignLeft)

	// ─── per-shift index (chain slip only) ───
	// Each shift's own gross, so the owner sees how the day moved before
	// reading the totals below.
	if info.IsChain && len(info.ChainIndex) > 0 {
		sep()
		for _, cs := range info.ChainIndex {
			kind := L.ChainFinal
			if cs.Kind == "handover" {
				kind = L.ChainHandover
			}
			row(fmt.Sprintf("%s%d (%s)", L.ChainShift, cs.Sequence, kind), money(cs.Gross))
			// The drawer block below reports the FINAL count (one physical till
			// handed along the chain), so a shift's own 過不足 would otherwise be
			// invisible — and per-cashier accountability is the point of a
			// handover. Surface it here instead.
			if cs.Variance != 0 {
				row("  "+L.Variance, money(cs.Variance))
			}
			// Who was accountable for THIS shift. The drawer block below names
			// only the cashier who ended the chain, so without this a handover
			// chain would lose track of everyone before them.
			if cs.Operator != "" {
				row("  "+L.Operator, cs.Operator)
			}
		}
	}

	// ─── sales summary ───
	sep()
	row(L.GrossSales, money(info.GrossSales))
	right(fmt.Sprintf("%d%s", info.ItemCount, L.ItemUnit))
	row(L.NetSales, money(info.NetSales))
	right(fmt.Sprintf("%d%s", info.GuestCount, L.GuestUnit))
	// Blank line separates the 総売上/純売上 pair from the tax total, matching
	// the reference 精算 slip's vertical grouping (no divider, just whitespace).
	e.Feed(1)
	row(L.TaxTotal, money(info.TaxTotal))

	// ─── tax breakdown ───
	// Per-rate rows (課税売上 + 消費税 for each rate) when the toggle is on AND
	// the shift carries per-line snapshots (plan-043 T4.2); otherwise collapse
	// to the single 課税売上 / 消費税 figure (legacy shift or toggle off).
	sep()
	perRate := info.ShowTaxBreakdown && len(info.TaxBreakdown) > 0
	e.Line(L.SalesBreakdown)
	if perRate {
		for _, tl := range info.TaxBreakdown {
			row("  "+fmt.Sprintf(L.RateTarget, formatRatePercent(tl.Rate)), money(tl.TaxableSales))
		}
		// The rate buckets cover item lines only; surface the service charge so
		// the column adds up to 純売上.
		if info.ServiceCharge != 0 {
			row("  "+L.ServiceCharge, money(info.ServiceCharge))
		}
	} else {
		row("  "+L.TaxableSales, money(info.NetSales))
	}
	e.Line(L.TaxBreakdown)
	if perRate {
		for _, tl := range info.TaxBreakdown {
			row("  "+fmt.Sprintf(L.RateTarget, formatRatePercent(tl.Rate)), money(tl.Tax))
		}
		if info.ServiceChargeTax != 0 {
			row("  "+L.ServiceChargeTax, money(info.ServiceChargeTax))
		}
	} else {
		row("  "+L.Tax, money(info.TaxTotal))
	}

	// ─── payment methods (支払方法, optional) ───
	if info.ShowPaymentMethods {
		sep()
		e.Line(L.PaymentMethods)
		for _, p := range info.Payments {
			row("  "+localizePaymentLabel(config.Locale, p.Code, p.Label), statValue(p.Count, money(p.Amount)))
		}
	}

	// ─── change for non-cash tenders (not tracked → zeros, kept for parity) ───
	sep()
	e.Line(L.NonCashChange)
	row("  "+L.NonCashUnpaid, statValue(0, money(0)))
	row("  "+L.NonCashPaid, statValue(0, money(0)))

	// ─── discounts / surcharges ───
	sep()
	e.Line(L.Discounts)
	switch {
	case len(info.Discounts) > 0:
		for _, d := range info.Discounts {
			row("  "+d.Label, statValue(d.Count, "▲"+money(d.Amount)))
		}
	case info.DiscountTotalAmount > 0:
		row("  "+L.DiscountGeneric, statValue(info.DiscountTotalCount, "▲"+money(info.DiscountTotalAmount)))
	}
	// Item-level discount / surcharge are not modelled at the workstation yet.
	row("  "+L.ItemDiscount, statValue(0, money(0)))
	row("  "+L.ItemSurcharge, statValue(0, money(0)))

	// ─── service charge (サービス料, optional; amount 0 until sourced) ───
	if info.ShowServiceCharge {
		sep()
		row(L.ServiceCharge, statValue(0, money(info.ServiceCharge)))
	}

	// ─── accounting corrections ───
	sep()
	row(L.AcctCorrection, statValue(0, money(0)))

	// ─── check count (its own section on the reference slip) ───
	sep()
	row(L.CheckCount, padLeft(fmt.Sprintf("%d%s", info.CheckCount, L.CountUnit), cntCol))

	// ─── cash in / out ───
	sep()
	row(L.CashMovementTotal, money(info.PaidInAmount-info.PaidOutAmount))
	row("  "+L.PaidIn, statValue(info.PaidInCount, money(info.PaidInAmount)))
	row("  "+L.PaidOut, statValue(info.PaidOutCount, money(info.PaidOutAmount)))

	// ─── voided bills ───
	sep()
	e.Line(L.VoidBills)
	row("  "+L.VoidUnpaid, statValue(info.VoidUnpaidCount, money(info.VoidUnpaidAmount)))
	row("  "+L.VoidPaid, statValue(info.VoidPaidCount, money(info.VoidPaidAmount)))

	// ─── drawer check (レジ点検, optional) ───
	if info.ShowDrawerCheck {
		sep()
		e.Line(L.DrawerCheck)
		row("  "+L.CountedCash, money(info.CountedCash))
		row("  "+L.ExpectedCash, money(info.ExpectedCash))
		variance := money(info.CashVariance)
		if info.CashVariance > 0 {
			variance = "+" + variance
		}
		row("  "+L.Variance, variance)
		operator := strings.TrimSpace(info.Operator)
		if operator == "" {
			operator = L.OperatorNone
		}
		row("  "+L.Operator, operator)
	}

	// ─── denominations (金種, optional) — closing cash count, high value first ───
	if info.ShowDenominations {
		sep()
		e.Line(L.Denomination)
		for _, d := range info.Denominations {
			row("  "+money(d.Value),
				padLeft(fmt.Sprintf("%d%s", d.Quantity, L.PieceUnit), cntCol)+padLeft(money(d.Subtotal), amtCol))
		}
	}

	// FullCut (ESC d 3) already feeds 3 lines before cutting, so a single
	// trailing feed is enough tail margin — no need for extra blank lines.
	e.Feed(1)
	e.FullCut()
	return e.Bytes()
}
