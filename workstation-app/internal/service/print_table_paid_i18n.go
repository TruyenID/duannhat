package service

import "strings"

// tablePaidLabels is the label set for one language of the "table paid" staff
// slip — a tiny notification printed to the kitchen / hold station so floor
// staff see at a glance which dine-in table just self-paid (kiosk / QR online
// payment). Same typed-struct + Shift_JIS constraints as the other print
// catalogs: a forgotten label is a COMPILE error, and the Vietnamese set is
// ASCII-folded (no dấu) because the thermal encoder is japanese.ShiftJIS, which
// can't encode Vietnamese diacritics.
type tablePaidLabels struct {
	Title string // big centered header — "会計済" / "PAID" / "DA THANH TOAN"
	Table string // テーブル / TABLE / BAN — prefix before the table number
	// Order-code prefix. Matches the receipt/kitchen OrderNo label EXACTLY
	// ("伝票" / "Order#" / "So HD") so a runner can cross-reference this slip
	// against the customer receipt by the same number.
	Order string
}

var tablePaidLabelsJA = tablePaidLabels{
	Title: "会計済",
	Table: "テーブル",
	Order: "伝票",
}

var tablePaidLabelsEN = tablePaidLabels{
	Title: "PAID",
	Table: "TABLE",
	Order: "Order#",
}

// tablePaidLabelsVI — Vietnamese, ASCII-folded (no diacritics — see type doc).
var tablePaidLabelsVI = tablePaidLabels{
	Title: "DA THANH TOAN",
	Table: "BAN",
	Order: "So HD",
}

// tablePaidLabelsFor returns the label set for a locale. It mirrors the
// receipt/kitchen resolver printLabelsFor EXACTLY — explicit "en"/"vi", default
// Japanese — so this slip always prints in the same language as the payment
// receipt (both read the operator locale from rememberedPrintLocale, which is
// empty until a print happens; when unresolved, both fall back to Japanese).
func tablePaidLabelsFor(locale string) tablePaidLabels {
	switch strings.ToLower(strings.TrimSpace(locale)) {
	case "en":
		return tablePaidLabelsEN
	case "vi":
		return tablePaidLabelsVI
	default:
		return tablePaidLabelsJA
	}
}
