package service

import "strings"

// shiftOpenLabels is the label set for one language of the shift-OPEN slip (the
// cash-drawer opening report printed when a cashier opens a shift). Same typed-
// struct + Shift_JIS constraints as shiftLabels: a forgotten label is a COMPILE
// error, and the Vietnamese set is ASCII-folded (no dấu) because the thermal
// encoder is japanese.ShiftJIS, which can't encode Vietnamese diacritics.
type shiftOpenLabels struct {
	Title        string // big centered header
	Device       string // 端末{name} — device prefix
	Operator     string // 担当者
	OperatorNone string // 未設定 — operator fallback when unset
	OpenedAt     string // 開始時刻 — open-time prefix

	DenomHeader  string // 金種 — denomination column header
	QtyHeader    string // 枚数 — quantity column header
	AmountHeader string // 金額 — amount column header
	QtyUnit      string // 枚 — trails a per-denomination count

	Total string // 合計 — opening float total
	Note  string // 備考 — opening note section header
}

var shiftOpenLabelsJA = shiftOpenLabels{
	Title:        "開始",
	Device:       "端末",
	Operator:     "担当者",
	OperatorNone: "未設定",
	OpenedAt:     "開始時刻",

	DenomHeader:  "金種",
	QtyHeader:    "枚数",
	AmountHeader: "金額",
	QtyUnit:      "枚",

	Total: "合計",
	Note:  "備考",
}

var shiftOpenLabelsEN = shiftOpenLabels{
	Title:        "SHIFT OPEN",
	Device:       "Device",
	Operator:     "Operator",
	OperatorNone: "(not set)",
	OpenedAt:     "Opened at",

	DenomHeader:  "Denomination",
	QtyHeader:    "Qty",
	AmountHeader: "Amount",
	QtyUnit:      "x",

	Total: "Total",
	Note:  "Note",
}

// shiftOpenLabelsVI — Vietnamese, ASCII-folded (no diacritics — see type doc).
var shiftOpenLabelsVI = shiftOpenLabels{
	Title:        "MO CA",
	Device:       "Thiet bi",
	Operator:     "Nguoi mo ca",
	OperatorNone: "(chua dat)",
	OpenedAt:     "Gio mo ca",

	DenomHeader:  "Menh gia",
	QtyHeader:    "SL",
	AmountHeader: "So tien",
	QtyUnit:      "x",

	Total: "Tong",
	Note:  "Ghi chu",
}

// openLabelsFor returns the label set for a locale, defaulting to Japanese for
// empty/unknown input (mirrors labelsFor for the close report).
func openLabelsFor(locale string) shiftOpenLabels {
	switch strings.ToLower(strings.TrimSpace(locale)) {
	case "en":
		return shiftOpenLabelsEN
	case "vi":
		return shiftOpenLabelsVI
	default:
		return shiftOpenLabelsJA
	}
}
