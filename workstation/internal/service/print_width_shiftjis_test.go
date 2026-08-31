package service

import (
	"strings"
	"testing"

	"golang.org/x/text/encoding/japanese"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// The width table and the encoder are two descriptions of the same physical
// fact — how many columns the head puts down — so they are gated against each
// other rather than against a hand-written expectation. A hand-written one is
// what let ※ measure a single column for as long as it did: it looks narrow in
// every editor, and Unicode agrees with the editor (East-Asian AMBIGUOUS).
// Only the encoder knows it goes out as 0x81 0xA6.
//
// The check is deliberately ONE-DIRECTIONAL — two bytes implies two columns,
// not the converse. Emoji and the Hangul/Yi/CJK-Ext blocks measure 2 while
// Shift_JIS cannot represent them at all; those print through the raster path
// or get substituted, and folding them into this rule would assert something
// about bytes that never exist.
func TestRuneDisplayWidth_MatchesShiftJISEncoder(t *testing.T) {
	enc := japanese.ShiftJIS.NewEncoder()

	for r := rune(0x20); r <= 0xFFFF; r++ {
		// Surrogate halves are not characters; encoding one is meaningless.
		if r >= 0xD800 && r <= 0xDFFF {
			continue
		}

		b, err := enc.Bytes([]byte(string(r)))
		if err != nil || len(b) != 2 {
			continue
		}

		if got := runeDisplayWidth(r); got != 2 {
			t.Errorf("U+%04X %q encodes to two Shift_JIS bytes (% X) but measures %d column(s)", r, r, b, got)
		}
	}
}

// inShiftJISWideRange binary-searches, so a table that is unsorted or has
// overlapping entries silently answers "narrow" for real characters. Both are
// easy to introduce by hand-editing the list and impossible to see by reading
// it.
func TestShiftJISWideRanges_SortedAndDisjoint(t *testing.T) {
	for i, rg := range shiftJISWideRanges {
		if rg[0] > rg[1] {
			t.Errorf("range %d is inverted: U+%04X > U+%04X", i, rg[0], rg[1])
		}
		if i > 0 && rg[0] <= shiftJISWideRanges[i-1][1] {
			t.Errorf("range %d (U+%04X-U+%04X) overlaps or precedes range %d (U+%04X-U+%04X)",
				i, rg[0], rg[1], i-1, shiftJISWideRanges[i-1][0], shiftJISWideRanges[i-1][1])
		}
	}
}

// The symptom that started this: an item name carrying the 軽減税率 mark laid
// out one column narrower than every other line, so its price hung one place
// to the right of the money column.
func TestPrintWrappedName_ReducedTaxMarkKeepsTheMoneyColumn(t *testing.T) {
	const cols = 48

	for _, name := range []string{"緑茶※", "Cafe sua da", "Bun bo Hue dac biet thap cam", "紅茶"} {
		e := escpos.New()
		printWrappedName(e, cols, "1", name, "¥300")

		// Measured on the DECODED bytes, which is what the head receives —
		// measuring the Go string instead would just re-assert the table
		// against itself.
		for _, line := range strings.Split(decodeSJIS(t, stripESCPOSCommands(e.Bytes())), "\n") {
			if strings.TrimSpace(line) == "" {
				continue
			}
			if w := displayWidth(line); w != cols {
				t.Errorf("item line for %q is %d columns, want %d: %q", name, w, cols, line)
			}
		}
	}
}
