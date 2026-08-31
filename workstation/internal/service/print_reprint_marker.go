package service

import (
	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// plan-052 P-10b [HARD] (#1166) — THE MARK ON THE PAPER.
//
// The owner ruled on 2026-07-28 that the system may never refuse a print: a
// cashier at the counter with a jammed printer and a waiting customer has to be
// able to reprint, full stop. This line is what replaced the refusal, and it is
// the only control in the whole design that actually prevents the thing the
// refusal was aiming at — two sheets that both look like an original.
//
// So it is deliberately NOT conditional on role, reason, connectivity or
// anything a shop can configure: the `reprint_marker` block is `locked` in
// every template (plan-053), and copy 1 never carries it — otherwise the mark
// would say nothing.
//
// One function, used by BOTH the hard-coded formatters and the template
// renderer, precisely so the two can never drift into printing a different
// mark; the golden gate compares them byte for byte.
//
// Text: `BAN IN #N` in vi/en, 「再印刷 #N」 in ja (#1890) — one wording per
// locale, from the label catalog, so every money document a shop files that
// evening uses the SAME words for "this is a copy". vi/en stay ASCII on purpose:
// half the fleet's printers have no kanji ROM (DESIGN §3b) and would print boxes
// where the mark should be, which is worse than the mark being in English.
//
// Width is measured in DISPLAY COLUMNS, not runes. A full-width Japanese glyph
// occupies TWO columns on a thermal printer, so 「再印刷 #2」 is 7 runes but 9
// columns; right-aligning on the rune count would push the mark 3 columns off
// the edge of every ja slip.
func printReprintMarker(e *escpos.Encoder, w, reprintNo int, locale string) {
	if reprintNo < 2 {
		return
	}
	mark := printLabelsFor(locale).ReprintMark + " #" + itoa(reprintNo)
	e.Line(spaces(w-displayWidth(mark)) + mark)
}
