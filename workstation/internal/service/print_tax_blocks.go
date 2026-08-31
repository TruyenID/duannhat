package service

import (
	"fmt"
	"sort"
)

// plan-043 T4.1 — the per-rate breakdown block computation shared by the receipt
// (formatBillTicket) and the VAT invoice. Sourced from the order's per-line tax
// snapshots (item.TaxRate), NOT recomputed from a single shop rate — that was
// bug #3 (a hardcoded 10% tax-included extraction inconsistent with the
// tax-EXCLUDED engine). The once-per-rate-group rounding (端数処理は税率ごとに1回)
// reuses the priceGroups engine so a printed block agrees with the engine total
// to the yen.

// receiptTaxBlock is one printed per-rate row: "{rate}%対象 {taxable} (内消費税 {tax})".
type receiptTaxBlock struct {
	Rate    float64
	Taxable int // the taxable base printed after "{rate}%対象"
	Tax     int // the consumption tax printed inside "(内消費税 …)"
}

// receiptTaxSummary is the full per-rate breakdown for one bill.
type receiptTaxSummary struct {
	Blocks     []receiptTaxBlock
	HasReduced bool // any block below the highest rate → print the ※ legend
	// ReducedRate is the rate that carries the ※ marker (the reduced tier). Only
	// meaningful when HasReduced. A line at exactly this rate gets ※.
	ReducedRate float64
	// MaxRate is the highest snapshot rate present — the threshold isReducedLine
	// compares each line against so a mixed-rate order marks only the lower tier.
	MaxRate float64
}

// blockMaxRate returns the threshold above which a line is NOT reduced (the
// highest snapshot rate in the order). Lines strictly below it carry ※.
func (s receiptTaxSummary) blockMaxRate() float64 { return s.MaxRate }

// itemTaxableSubtotal is the taxable base of one line, matching the engine's
// group-1 accumulation exactly:
//
//	quantity × (unit_price + topping_subtotal)
//
// `ToppingSubtotal` is PER UNIT — the column comment says so
// (`customer_order_items.topping_subtotal`, "Topping Subtotal (per unit)"), the
// pricer says so, and every writer on both transports stores
// `subtotal = quantity × (unit_price + topping_subtotal)`.
//
// This used to read `qty×unit_price + topping_subtotal`, adding the extras once
// per LINE instead of once per unit. On a ¥1.000 bowl ordered ×3 with a ¥100
// extra it made the printed block say
//
//	10%対象  ¥3.100 (内消費税 ¥310)
//
// against ¥3.300 actually charged and ¥330 actually collected — a wrong tax
// figure on a 適格請求書, and on the red invoice printed from the same code.
// #2067 again: the print layer prints order data, it does not price a sale.
//
// The row-sum fallback stays for a line that carries topping rows but no stored
// subtotal, and is now per-unit like the primary branch. It is deliberately NOT
// the preferred source: only `ToppingSubtotal` has been through the free-tier
// waiver (`free_up_to_n`), so re-summing rows would charge extras the engine
// gave away.
func itemTaxableSubtotal(it Item) int {
	qty := it.Quantity
	if qty < 1 {
		qty = 1
	}
	if it.ToppingSubtotal != 0 {
		return qty * (it.UnitPrice + it.ToppingSubtotal)
	}
	perUnitToppings := 0
	for _, t := range it.Toppings {
		perUnitToppings += t.UnitPrice * t.Quantity
	}
	return qty * (it.UnitPrice + perUnitToppings)
}

// isReducedLine reports whether an item line carries a resolved reduced-rate
// snapshot relative to the order's rate spread — i.e. its rate is present and
// strictly below the highest rate in the order. This is the defined truth for
// the thermal ※ marker (§1.3.2): 8% is marked when a 10% line coexists; a
// single-rate order marks nothing.
func isReducedLine(it Item, maxRate float64) bool {
	if it.TaxRate == nil {
		return false
	}
	// Mức 0 KHÔNG BAO GIỜ mang ※ (#2086): 非課税/免税 không phải 軽減税率, và ※
	// trỏ tới chú thích 「※は軽減税率対象」 — dán nó lên một món miễn thuế là
	// tuyên bố sai trên chứng từ thuế.
	if *it.TaxRate <= 0 {
		return false
	}

	return *it.TaxRate < maxRate
}

// buildReceiptTaxSummary is the per-rate breakdown of a WHOLE-ORDER money slip.
//
// #2170 — the ledger comes FIRST. When the order carries `order_conditions`
// tax rows (Order.TaxLines, loaded by the print funnel), those ARE the printed
// blocks: they were written by the pricing engine with the real discount and
// service charge in hand, so they match `order.TaxAmount` to the yen and match
// what Cloud prints on the CloudPRNT slip for the same order. The computation
// below survives only as the FALLBACK for an order whose ledger is empty (an
// old order, or an offline order not yet priced) — such an order must still
// produce paper, never a 500 or an empty table.
//
// Partial-batch slips (kitchen ticket, per-fire delta) must NOT come through
// here — the ledger describes the whole order, not one fired batch. They call
// computeReceiptTaxSummary directly; receiptTaxSummaryForKind is the one
// chokepoint that makes that choice.
func buildReceiptTaxSummary(order *Order, items []Item, step float64) receiptTaxSummary {
	if order != nil && len(order.TaxLines) > 0 {
		summary := receiptTaxSummary{}
		for _, l := range order.TaxLines {
			summary.Blocks = append(summary.Blocks, receiptTaxBlock{
				Rate:    l.Rate,
				Taxable: l.Taxable,
				Tax:     l.Tax,
			})
			if l.Rate > summary.MaxRate {
				summary.MaxRate = l.Rate
			}
		}
		sort.Slice(summary.Blocks, func(i, j int) bool { return summary.Blocks[i].Rate < summary.Blocks[j].Rate })
		markReducedTier(&summary)
		return summary
	}

	return computeReceiptTaxSummary(order, items, step)
}

// receiptTaxSummaryForKind picks the summary source the way the emitters need
// it: a kitchen ticket or a per-fire delta slip shows ONE batch, which never
// had an order-level tax fact — the whole-order ledger would print figures the
// slip's own lines cannot explain — so those two always recompute from the
// items they were handed. Every other bill kind is a whole-order document and
// goes ledger-first. Both the legacy formatters, the template renderer
// (prepareBillTax) and the golden input export route through THIS function so
// the three cannot drift on the choice.
func receiptTaxSummaryForKind(kind string, deltaBill bool, order *Order, items []Item, step float64) receiptTaxSummary {
	if kind == "kitchen" || deltaBill {
		return computeReceiptTaxSummary(order, items, step)
	}
	return buildReceiptTaxSummary(order, items, step)
}

// computeReceiptTaxSummary groups the non-void, tax-stamped items by snapshot rate
// and computes the printed taxable + tax per group, honouring the order's
// tax-included vs tax-excluded mode (fix bug #3 — the mode comes from
// order.IsTaxIncluded, never a hardcoded 10%). Returns an empty summary (no
// blocks) when no line carries a rate snapshot, so a legacy order falls back to
// the single-line Thue path in formatBillTicket.
//
// #2170 — this recompute forces discount + service charge to 0, so on a
// discounted order its blocks do NOT reconcile with `order.TaxAmount`. That is
// why it is no longer the first choice for whole-order slips (see
// buildReceiptTaxSummary); it remains the only possible source for a batch
// slip and for an order the pricing engine has not written a ledger for.
func computeReceiptTaxSummary(order *Order, items []Item, step float64) receiptTaxSummary {
	rateSubtotals := map[string]float64{}
	maxRate := 0.0
	anyStamped := false
	for _, it := range items {
		if it.VoidedAt != nil || it.Status == ItemStatusVoided {
			continue
		}
		if it.TaxRate == nil {
			continue
		}
		anyStamped = true
		rate := *it.TaxRate
		if rate > maxRate {
			maxRate = rate
		}
		rateSubtotals[rateKey(rate)] += float64(itemTaxableSubtotal(it))
	}
	if !anyStamped {
		return receiptTaxSummary{}
	}

	// Reuse the engine so once-per-group rounding matches the order total. The
	// receipt never re-allocates coupons/service-charge into the printed blocks
	// (those already sit on the order totals), so discount + sc rates are 0 here.
	// plan-045 — round the printed tax blocks with the order's SNAPSHOT rule so
	// they match the stored order total (never the live setting).
	taxMode := order.TaxRoundingMode
	if taxMode == "" {
		taxMode = "round"
	}
	tStep := taxStepFrom(order.TaxRoundingDecimals, step)
	res := priceGroups(rateSubtotals, 0, 0, 0, order.IsTaxIncluded, step, tStep, taxMode, nil)

	summary := receiptTaxSummary{MaxRate: maxRate}
	for _, g := range res.Groups {
		summary.Blocks = append(summary.Blocks, receiptTaxBlock{
			Rate:    g.Rate,
			Taxable: int(g.Taxable),
			Tax:     int(g.Tax),
		})
	}
	sort.Slice(summary.Blocks, func(i, j int) bool { return summary.Blocks[i].Rate < summary.Blocks[j].Rate })
	markReducedTier(&summary)
	return summary
}

// markReducedTier stamps HasReduced/ReducedRate on a summary whose Blocks are
// already sorted rate-ASC and whose MaxRate is set. Shared by the ledger path
// and the compute path (#2170) so the ※ rules cannot fork between them.
//
// Mức "giảm" = mức thấp nhất LỚN HƠN 0, và chỉ khi có từ hai mức phân biệt.
//
// #2086 — điều kiện cũ (`Blocks[0].Rate < maxRate`) coi 0% là mức giảm, và
// hỏng theo HAI hướng cùng lúc trên một đơn có cả 非課税 lẫn 軽減:
//
//   - món 非課税 mang dấu ※, mà ※ trỏ tới chú thích 「※は軽減税率対象」 —
//     một khẳng định SAI về chế độ thuế trên chứng từ;
//   - `ReducedRate` trỏ về 0 thay vì 8, nên món 軽減 THẬT mất nhãn nó cần.
//
// 非課税 / 免税 và 軽減税率 là hai chế độ khác hẳn (Peppol tách hẳn thành Z/E
// so với S). Cloud sửa ở `d1634ec9a`; đây là bản gương.
func markReducedTier(summary *receiptTaxSummary) {
	for _, b := range summary.Blocks {
		if b.Rate <= 0 {
			continue
		}
		if b.Rate < summary.MaxRate {
			summary.HasReduced = true
			summary.ReducedRate = b.Rate
		}

		break // Blocks đã sắp tăng dần ⇒ mức dương đầu tiên là ứng viên duy nhất.
	}
}

// taxWrapIndent is the EXTRA indent of the second line when a per-rate block has
// to wrap. Two columns, not four: the 内消費税 line must read as the part INSIDE
// the taxable figure above it rather than as a separate charge, while still
// leaving room for the longest label ("thue trong") on 58mm paper.
const taxWrapIndent = 2

// formatRateBlockLines renders one per-rate block the way the mockup shows:
//
//	8%対象  ¥1,000 (内消費税 ¥80)
//
// label = the localized "{rate}%対象" prefix, cur = currency symbol. The value
// column ("{cur}{taxable} ({内消費税} {cur}{tax})") is right-aligned so blocks of
// different rates line up.
//
// It returns TWO lines when that single line does not fit the paper (#2035):
//
//	8%対象           ¥1,000
//	  内消費税          ¥80
//
// Why wrap rather than shorten. On 58mm paper (32 columns) the one-line form
// fits in NO language — 33 columns in ja, 38 in en, 41 in vi, counting the
// 3-column indent and the one-column minimum gap. The gap clamp below floors at
// 1 and then still emits a line wider than the paper, so the REAL printer
// overflows there, not just the cloud preview. Shrinking the amounts does not
// close it either: "¥5 (thue trong ¥1)" still leaves vi at 35.
//
// The alternatives were weighed and rejected: shortening the catalog labels
// changes the words on paper at EVERY width, and dropping the per-rate block on
// narrow paper drops a legally required field of a 適格請求書 (per-rate tax is
// not optional — the document beats the aesthetics). Layout is what is left,
// and wrapping loses no figure.
//
// Only 32-column paper changes. At 42 the tightest case (vi, 38 columns of
// content against 39 available) still fits, so the slip every shop prints today
// is unchanged byte for byte.
//
// `wrap` is decided ONCE for the whole breakdown by taxBlocksNeedWrap, not per
// rate: the 8% block is usually shorter than the 10% one (smaller figures, a
// shorter ja label), so a per-rate decision prints one rate inline and the other
// wrapped — losing the shared right margin that lets a reader compare 8% with
// 10% at a glance, which is the whole reason the value column is justified.
//
// Mirrored by BillKindPlans::rateBlockLines on the PHP side; SlipByteParityTest
// (G3) holds the two byte-identical.
func formatRateBlockLines(w int, labels taxLabels, cur string, block receiptTaxBlock, wrap bool) []string {
	label := fmt.Sprintf(labels.RateTarget, formatRatePercent(block.Rate))
	taxable := cur + formatPrice(block.Taxable)
	tax := cur + formatPrice(block.Tax)

	if !wrap {
		return []string{justifyTaxLine(label, rateBlockInline(labels, taxable, tax), w)}
	}

	return []string{
		justifyTaxLine(label, taxable, w),
		spaces(taxWrapIndent) + justifyTaxLine(labels.IncludedTax, tax, w-taxWrapIndent),
	}
}

// rateBlockInline is the value column of the one-line form: "¥2,915 (内消費税 ¥265)".
func rateBlockInline(labels taxLabels, taxable, tax string) string {
	return fmt.Sprintf("%s (%s %s)", taxable, labels.IncludedTax, tax)
}

// taxBlocksNeedWrap reports whether ANY per-rate block fails to fit `w` columns
// on one line — in which case every block in the breakdown wraps.
//
// `columns` is the FULL paper width (`w` is already net of the caller's indent)
// and gates the whole thing on narrow paper: see printNarrowColumns for why the
// width gate is there even though the fit test alone would never fire at 42 or
// 48 with today's figures.
func taxBlocksNeedWrap(columns, w int, labels taxLabels, cur string, blocks []receiptTaxBlock) bool {
	if !isNarrowSlip(columns) {
		return false
	}
	for _, block := range blocks {
		label := fmt.Sprintf(labels.RateTarget, formatRatePercent(block.Rate))
		inline := rateBlockInline(labels, cur+formatPrice(block.Taxable), cur+formatPrice(block.Tax))
		if displayWidth(label)+1+displayWidth(inline) > w {
			return true
		}
	}
	return false
}

// ─── per-rate blocks on the INVOICE family (#2035) ────────────────────────
//
// The bill family above owns its own indent and writes lines directly. The VAT
// invoice and the 適格簡易請求書 instead push every footer row through their own
// justifier (`runeRow`, `footerRow`, `vatJARow`), so the shared piece here is
// the label/value PAIRS, not the finished line.

// vatRateLine is one per-rate row of an invoice footer before layout, with the
// currency symbol already applied to both figures.
type vatRateLine struct {
	Label    string // localized "{rate}%対象"
	SubLabel string // localized "内消費税"
	Taxable  string // "¥2,915"
	Tax      string // "¥265"
}

// vatRateInline is the one-line value column: "¥2,915 (内消費税 ¥265)".
func vatRateInline(l vatRateLine) string {
	return l.Taxable + " (" + l.SubLabel + " " + l.Tax + ")"
}

// layoutVatRateRows turns per-rate lines into the label/value pairs to print,
// splitting each in two on narrow paper (#2035):
//
//	10%対象                    ¥2,915
//	  内消費税                   ¥265
//
// `prefix` is the document's own left indent, applied to BOTH lines so the
// wrapped row keeps the block's margin; the continuation adds taxWrapIndent on
// top of it. All-or-nothing across the breakdown, for the same reason as the
// bill family: a block printed in two different shapes loses the shared right
// margin that lets a reader compare 8% with 10% at a glance.
//
// `measure` is the CALLER's width function, not the "correct" one. The VAT
// invoice and the debt slip were laid out with rune counting and their column
// positions are baked into slips shops have already filed (see the note on
// Layout / TR-40), so the wrap decision has to use the same arithmetic the
// justifier will use — deciding with display columns and then laying out with
// rune counts produces a row that is neither.
func layoutVatRateRows(columns int, measure func(string) int, prefix string, lines []vatRateLine) [][2]string {
	wrap := false
	if isNarrowSlip(columns) {
		for _, l := range lines {
			if measure(prefix+l.Label)+1+measure(vatRateInline(l)) > columns {
				wrap = true
				break
			}
		}
	}

	rows := make([][2]string, 0, len(lines)*2)
	for _, l := range lines {
		if !wrap {
			rows = append(rows, [2]string{prefix + l.Label, vatRateInline(l)})
			continue
		}
		rows = append(rows, [2]string{prefix + l.Label, l.Taxable})
		rows = append(rows, [2]string{prefix + spaces(taxWrapIndent) + l.SubLabel, l.Tax})
	}
	return rows
}

// vatRateLinesFor builds the per-rate lines of an invoice footer from the
// breakdown frozen onto the invoice at issue.
func vatRateLinesFor(labels taxLabels, cur string, breakdown []VatInvoiceTaxLine) []vatRateLine {
	out := make([]vatRateLine, 0, len(breakdown))
	for _, tl := range breakdown {
		out = append(out, vatRateLine{
			Label:    fmt.Sprintf(labels.RateTarget, formatRatePercent(tl.Rate)),
			SubLabel: labels.IncludedTax,
			Taxable:  cur + formatPrice(tl.Taxable),
			Tax:      cur + formatPrice(tl.Tax),
		})
	}
	return out
}

// vatRateRowsFor is the whole per-rate footer of a VAT invoice, laid out.
//
// The labels follow the locale of the INVOICE, not of the render: an issued
// invoice carries the language it was issued in, and reprinting it on a machine
// set to another locale must not change the words on the document.
func vatRateRowsFor(columns int, measure func(string) int, prefix string, info VatInvoiceInfo, cur string) [][2]string {
	return layoutVatRateRows(columns, measure, prefix,
		vatRateLinesFor(taxLabelsFor(info.Locale), cur, info.TaxBreakdown))
}

// justifyTaxLine pushes label and value to opposite edges, minimum one column
// of gap.
func justifyTaxLine(label, value string, w int) string {
	gap := w - displayWidth(label) - displayWidth(value)
	if gap < 1 {
		gap = 1
	}
	return label + spaces(gap) + value
}
