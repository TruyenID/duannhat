package service

import (
	"strconv"
	"strings"
)

// plan-043 T3.8 — per-rate consumption-tax print labels (軽減税率 / インボイス).
//
// These labels drive the per-rate breakdown blocks the receipt (print_service.go)
// and the VAT invoice (print_vat_invoice.go) print, plus the ※ reduced-rate
// marker + legend and the T+13 registration-number slot (§1.3 インボイス).
//
// Encoding constraint (SAME as print_shift_report_i18n.go): the thermal path
// encodes every string with japanese.ShiftJIS. Shift_JIS covers ASCII +
// kana/kanji but NOT Vietnamese combining diacritics, so the Vietnamese label
// set is intentionally ASCII-FOLDED (no dấu) — "内消費税" → "thue trong",
// "軽減税率対象" → "chiu thue giam". Do not add diacritics here.
//
// The Japanese labels reproduce the reference receipt mockup (SYSTEM-FLOW §7.6):
//
//	 8%対象  ¥1,000 (内消費税 ¥80)
//	10%対象    ¥500 (内消費税 ¥50)
//	※は軽減税率対象
//	登録番号: T1234567890123
type taxLabels struct {
	// RateTarget is the "{rate}%対象" prefix — the label that heads one
	// per-rate breakdown block. It's a Sprintf template consuming the rate.
	RateTarget string // "%s%%対象" (ja) — "{rate}%% target" style
	// IncludedTax labels the parenthesised consumption-tax figure inside a
	// per-rate block: "(内消費税 ¥80)". It's the amount of tax contained in
	// (excluded mode: added on top of) that rate group.
	IncludedTax string // 内消費税
	// ReducedMarker is the ※ glyph stamped next to a reduced-rate (8%) item
	// name, per インボイス 適格簡易請求書 requirement §1.3.2.
	ReducedMarker string // ※
	// ReducedLegend is the footer legend explaining the ※ marker.
	ReducedLegend string // ※は軽減税率対象
	// RegistrationNumber labels the T+13 seller registration number slot —
	// printed ONLY when the invoice/order carries a non-empty value (Q5).
	RegistrationNumber string // 登録番号
}

// The ※ marker is a Shift_JIS-encodable JIS glyph (U+203B, KOME), so it prints
// on the thermal head across every locale — no ASCII fold needed for it.
const reducedRateMarker = "※"

var taxLabelsJA = taxLabels{
	RateTarget:         "%s%%対象",
	IncludedTax:        "内消費税",
	ReducedMarker:      reducedRateMarker,
	ReducedLegend:      "※は軽減税率対象",
	RegistrationNumber: "登録番号",
}

var taxLabelsEN = taxLabels{
	RateTarget:         "%s%% taxable",
	IncludedTax:        "incl. tax",
	ReducedMarker:      reducedRateMarker,
	ReducedLegend:      "※ = reduced tax rate",
	RegistrationNumber: "Reg. No.",
}

// Vietnamese — ASCII-folded (no diacritics — see type doc).
var taxLabelsVI = taxLabels{
	RateTarget:         "Chiu thue %s%%",
	IncludedTax:        "thue trong",
	ReducedMarker:      reducedRateMarker,
	ReducedLegend:      "※ = chiu thue giam",
	RegistrationNumber: "So dang ky",
}

// taxLabelsFor returns the per-rate tax label set for a locale, defaulting to
// Japanese for empty/unknown input (mirrors labelsFor on the shift-report side).
func taxLabelsFor(locale string) taxLabels {
	switch strings.ToLower(strings.TrimSpace(locale)) {
	case "en":
		return taxLabelsEN
	case "vi":
		return taxLabelsVI
	default:
		return taxLabelsJA
	}
}

// formatRatePercent renders a rate for the "{rate}%対象" label the same way the
// engine's rateKey does — an integer-valued rate prints without a decimal point
// ("8", "10"), a fractional one keeps its digits ("8.5"). Keeps the printed
// block aligned with the grouping key so 8 and 8.0 never split into two blocks.
func formatRatePercent(rate float64) string {
	return strconv.FormatFloat(rate, 'f', -1, 64)
}
