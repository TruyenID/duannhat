package service

// #2035 — NARROW PAPER (58mm).
//
// Every slip in this package was laid out against 42 or 48 content columns: 42
// is what all the LAN print handlers send, 48 is the `defaultWidth` of every
// kind. 32 columns (58mm roll) was reachable through configuration the whole
// time and nothing was ever measured against it, so three blocks emit lines
// wider than the paper there — the gap arithmetic floors at one column and then
// prints a line the printer wraps mid-figure.
//
// The fix is per-block narrow VARIANTS, and they are gated on this threshold
// rather than on "does it fit". The gate is deliberately blunt:
//
//   - The 42- and 48-column slips shops print today must not change by one
//     byte. A fit-based trigger relies on measuring every possible line to prove
//     that; a width gate makes the narrow branch UNREACHABLE from those widths,
//     which is the same claim without the measurement.
//   - It also keeps the two repos honest. PHP and Go must agree byte-for-byte
//     (SlipByteParityTest, G3), and one shared integer comparison is far easier
//     to keep in step than two independently-drifting fit heuristics.
//
// Being under the threshold is necessary, not sufficient: each variant still
// checks that the normal form actually fails to fit, so a short 32-column line
// keeps its one-line shape.
const printNarrowColumns = 42

// isNarrowSlip reports whether `columns` is below the width the slips were
// designed against — i.e. whether narrow variants are allowed at all.
func isNarrowSlip(columns int) bool { return columns < printNarrowColumns }

// ─── the two non-tax overflows on the GTGT invoice (#2035) ────────────────

// vatNumericColumns is the fixed right-hand block of the invoice item table:
// SL (3) + Don gia (11) + Thanh tien (11). The món-name column is whatever the
// paper leaves over, which on 58mm is SEVEN columns — one short of the word
// "San pham" in the header. The item ROWS were always fine (names are truncated
// to the column); only the header word overflowed, by exactly one column.
const vatNumericColumns = 25

// vatColumnHeaderLines returns the item-table header for `w` columns.
//
// Normally one line, "San pham" padded out to the numeric block. On narrow paper
// where the label no longer fits its own column it becomes two: the label on its
// own line, then the three numeric headings still sitting exactly above the
// figures they name.
//
// Shortening the label instead was rejected. Truncating gives "San pha", and
// dropping "Don gia" to make room takes a column heading off a tax document —
// the brief for this change is that no information may be cut, so the only free
// variable is the line break.
func vatColumnHeaderLines(w int) []string {
	nameWidth := w - vatNumericColumns
	numeric := padLeft("SL", 3) + padLeft("Don gia", 11) + padLeft("Thanh tien", 11)

	if !isNarrowSlip(w) || displayWidth("San pham") <= nameWidth {
		return []string{padRight("San pham", nameWidth) + numeric}
	}
	return []string{"San pham", spaces(maxInt(nameWidth, 0)) + numeric}
}

// vatDisclaimerLines returns the closing legal notice for `w` columns.
//
// "KHONG THAY THE HDDT CUA CO QUAN THUE" is 36 columns and does not fit 58mm
// paper, and it is the one line on the slip that may not lose a word: it is the
// sentence saying this sheet is NOT the tax authority's e-invoice. So on narrow
// paper it breaks at the phrase boundary — subject on one line, qualifier on the
// next — rather than being shortened.
//
// The split is written out rather than computed by the greedy wrapper because
// greedy wrapping puts "THUE" alone on the second line of a centered block; the
// break point here is a reading decision, not an arithmetic one. Both halves are
// slices of the same sentence, so a future edit that changes the wording has to
// change all three constants or the parity test will say so.
const (
	vatDisclaimer        = "KHONG THAY THE HDDT CUA CO QUAN THUE"
	vatDisclaimerNarrowA = "KHONG THAY THE HDDT"
	vatDisclaimerNarrowB = "CUA CO QUAN THUE"
)

func vatDisclaimerLines(w int) []string {
	if !isNarrowSlip(w) || displayWidth(vatDisclaimer) <= w {
		return []string{vatDisclaimer}
	}
	return []string{vatDisclaimerNarrowA, vatDisclaimerNarrowB}
}
