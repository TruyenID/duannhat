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

// itemTaxableSubtotal is the taxable base of one line: qty×unit_price plus the
// topping subtotal, matching the engine's group-1 accumulation. Uses the stored
// ToppingSubtotal when present, else sums the topping rows.
func itemTaxableSubtotal(it Item) int {
	base := it.UnitPrice * it.Quantity
	if it.ToppingSubtotal != 0 {
		return base + it.ToppingSubtotal
	}
	for _, t := range it.Toppings {
		base += t.UnitPrice * t.Quantity
	}
	return base
}

// isReducedLine reports whether an item line carries a resolved reduced-rate
// snapshot relative to the order's rate spread — i.e. its rate is present and
// strictly below the highest rate in the order. This is the defined truth for
// the thermal ※ marker (§1.3.2): 8% is marked when a 10% line coexists; a
// single-rate order marks nothing. An escalated alcohol line snapshots at the
// standard rate, so it correctly does NOT get ※.
func isReducedLine(it Item, maxRate float64) bool {
	if it.TaxRate == nil {
		return false
	}
	return *it.TaxRate < maxRate
}

// buildReceiptTaxSummary groups the non-void, tax-stamped items by snapshot rate
// and computes the printed taxable + tax per group, honouring the order's
// tax-included vs tax-excluded mode (fix bug #3 — the mode comes from
// order.IsTaxIncluded, never a hardcoded 10%). Returns an empty summary (no
// blocks) when no line carries a rate snapshot, so a legacy order falls back to
// the single-line Thue path in formatBillTicket.
func buildReceiptTaxSummary(order *Order, items []Item, step float64) receiptTaxSummary {
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
	res := priceGroups(rateSubtotals, 0, 0, 0, order.IsTaxIncluded, step, tStep, taxMode)

	summary := receiptTaxSummary{MaxRate: maxRate}
	for _, g := range res.Groups {
		summary.Blocks = append(summary.Blocks, receiptTaxBlock{
			Rate:    g.Rate,
			Taxable: int(g.Taxable),
			Tax:     int(g.Tax),
		})
	}
	sort.Slice(summary.Blocks, func(i, j int) bool { return summary.Blocks[i].Rate < summary.Blocks[j].Rate })

	// Reduced tier = the lowest rate when more than one distinct rate is present.
	if len(summary.Blocks) >= 2 && summary.Blocks[0].Rate < maxRate {
		summary.HasReduced = true
		summary.ReducedRate = summary.Blocks[0].Rate
	}
	return summary
}

// formatRateBlockLine renders one per-rate block line the way the mockup shows:
//
//	8%対象  ¥1,000 (内消費税 ¥80)
//
// label = the localized "{rate}%対象" prefix, cur = currency symbol. The value
// column ("{cur}{taxable} ({内消費税} {cur}{tax})") is right-aligned so blocks of
// different rates line up.
func formatRateBlockLine(w int, labels taxLabels, cur string, block receiptTaxBlock) string {
	label := fmt.Sprintf(labels.RateTarget, formatRatePercent(block.Rate))
	value := fmt.Sprintf("%s%s (%s %s%s)",
		cur, formatPrice(block.Taxable),
		labels.IncludedTax, cur, formatPrice(block.Tax))
	gap := w - displayWidth(label) - displayWidth(value)
	if gap < 1 {
		gap = 1
	}
	return label + spaces(gap) + value
}
