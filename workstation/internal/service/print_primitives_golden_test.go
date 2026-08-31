package service

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"strconv"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// plan-053 M5 (#1171) TR-34 — the PRIMITIVES half of the Go↔PHP parity gate.
//
// The slip-level gate compares whole ESC/POS streams, which is the assertion
// that matters but the worst possible place to debug a port: an off-by-one in
// `displayWidth` surfaces as "receipt|ja|32 hash differs" and nothing more.
//
// So the geometry and encoding primitives every emitter is built on get their
// own recorded fixture, and the PHP renderer's unit tests read the SAME file.
// When the port drifts, the failure names the function.
//
// Regenerate deliberately:
//
//	go test ./internal/service/ -run Primitives_Golden -args -update-print-primitives
var updatePrimitives = flag.Bool("update-print-primitives", false, "rewrite testdata/print_primitives_golden.json")

const primitivesGoldenPath = "testdata/print_primitives_golden.json"

// primitiveSamples is chosen for the ways these functions break, not for
// coverage percentage: fullwidth vs halfwidth, combining marks (NFD Vietnamese,
// which measures wrong if marks are not zero-width), ※ (which measures ONE
// despite looking wide), a token longer than any line, the empty string, and
// text outside the Shift_JIS repertoire.
func primitiveSamples() []string {
	return []string{
		"",
		" ",
		"Item",
		"商品",
		"商品                          金額",
		"ベト屋",
		"緑茶",
		"Bun bo Hue dac biet thap cam",
		"Chi nhánh Hà Nội", // precomposed
		"Chi nhánh Hà Nội", // NFD — combining marks must measure zero
		"phở đặc biệt",     // đ has no canonical decomposition
		"※",                // measures ONE column
		"※軽減税率対象",          // legend
		"精算（チェーン）",         // fullwidth parens
		"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA", // unbreakable ASCII
		"あああああああああああああああああああああああああああああああああ",                        // unbreakable fullwidth
		"one two three four five six seven eight nine ten eleven",
		"line one\nline two\n\nline four",
		"  leading and trailing  ",
		"\t\n ",
		"€100 £50 ₩900 ฿20", // outside Shift_JIS → substitution
		"—— Them rau mui",   // em dash is NOT in Shift_JIS
		"¥1,000",            // ¥ is pre-mapped to 0x5C
		"₫50.000",           // ₫ is pre-mapped to "d"
		"Khong hanh, it cay",
		"田中",
		"T1234567890123",
	}
}

func primitiveWidths() []int { return []int{1, 2, 8, 16, 32, 42, 48} }

func TestPrintPrimitives_Golden(t *testing.T) {
	produced := map[string]any{}

	// displayWidth + runeLen
	widths := map[string]int{}
	runeLens := map[string]int{}
	for _, s := range primitiveSamples() {
		widths[s] = displayWidth(s)
		runeLens[s] = runeLen(s)
	}
	produced["display_width"] = widths
	produced["rune_len"] = runeLens

	// formatPrice
	prices := map[string]string{}
	for _, n := range []int{0, 1, 9, 10, 100, 999, 1000, 1234, 999999, 1000000, -1, -1234} {
		prices[strconv.Itoa(n)] = formatPrice(n)
	}
	produced["format_price"] = prices

	// dashedLine + padRight
	dashes := map[string]string{}
	for _, w := range primitiveWidths() {
		dashes[strconv.Itoa(w)] = dashedLine(w)
	}
	produced["dashed_line"] = dashes

	pads := map[string]string{}
	for _, s := range primitiveSamples() {
		for _, w := range []int{0, 8, 16, 32} {
			pads[s+"|"+strconv.Itoa(w)] = padRight(s, w)
		}
	}
	produced["pad_right"] = pads

	// wrapText
	wraps := map[string][]string{}
	for _, s := range primitiveSamples() {
		for _, w := range primitiveWidths() {
			wraps[s+"|"+strconv.Itoa(w)] = wrapText(s, w)
		}
	}
	produced["wrap_text"] = wraps

	// wrapNameLines — the item-table wrap, with its widow control
	names := map[string][]string{}
	for _, s := range primitiveSamples() {
		for _, fw := range []int{4, 10, 20} {
			for _, cw := range []int{6, 16, 30} {
				names[s+"|"+strconv.Itoa(fw)+"|"+strconv.Itoa(cw)] = wrapNameLines(s, fw, cw)
			}
		}
	}
	produced["wrap_name_lines"] = names

	// columnHeaderText — the two-column header split
	headers := map[string][]string{}
	for _, s := range primitiveSamples() {
		l, r := columnHeaderText(s)
		headers[s] = []string{l, r}
	}
	produced["column_header_text"] = headers

	// Shift_JIS encoding + accent folding, as HEX so the fixture stays JSON-safe
	encoded := map[string]string{}
	stripped := map[string]string{}
	for _, s := range primitiveSamples() {
		e := escpos.New()
		e.Text(s)
		// Drop the ESC @ the constructor wrote — we want the text bytes only.
		encoded[s] = hex.EncodeToString(e.Bytes()[len(escpos.Init):])
		stripped[s] = escpos.StripAccents(s)
	}
	produced["shift_jis_hex"] = encoded
	produced["strip_accents"] = stripped

	// Finishing — the end-of-job epilogue, keyed `cut_mode|feed|auto_cut`.
	//
	// #1950 made the cut come from the printer's capability profile instead of a
	// blind `FullCut()`, on BOTH sides: `printRenderCtx.finish()` here, and
	// `PrintRenderContext::finish()` in Cloud's `CloudPrntJobRenderer`. Nothing
	// held those two to each other. The slip-level parity gate cannot: it renders
	// with an EMPTY profile on both sides, so it only ever exercises the
	// no-profile `ESC d 3` path and every configured machine falls outside it.
	//
	// So the epilogue gets its own fixture, like every other primitive here. The
	// two rows that carry the bug are `gs_v_partial` (what an `epson_tm_i`
	// declares so the slip stays hanging in the mechanism rather than dropping on
	// the floor) and `none` (a tear-bar machine: still fed, never sent a cut).
	finishing := map[string]string{}
	for _, f := range []escpos.Finishing{
		{CutMode: escpos.CutGsVFull, FeedBeforeCut: 4},                      // escpos_generic
		{CutMode: escpos.CutGsVPartial, FeedBeforeCut: 4},                   // epson_tm_i
		{CutMode: escpos.CutEscD, FeedBeforeCut: 3},                         // star_mcprint
		{CutMode: escpos.CutNone, FeedBeforeCut: 4},                         // tear-bar
		{CutMode: escpos.CutNone, FeedBeforeCut: 0},                         // tear-bar, feed floored to 2
		{CutMode: escpos.CutGsVFull, FeedBeforeCut: 4, AutoCutPerJob: true}, // machine cuts itself
		{CutMode: escpos.CutEscD, FeedBeforeCut: 3, AutoCutPerJob: true},    // ditto, Star
		{CutMode: escpos.CutNone, FeedBeforeCut: 3, AutoCutPerJob: true},    // none beats auto
		{CutMode: escpos.CutGsVPartial, FeedBeforeCut: 0},                   // partial with no feed
		{CutMode: escpos.CutGsVFull, FeedBeforeCut: 12},                     // a long-throat chassis
	} {
		e := escpos.New()
		e.Finish(f)
		key := fmt.Sprintf("%s|%d|%t", f.CutMode, f.FeedBeforeCut, f.AutoCutPerJob)
		// Drop the ESC @ the constructor wrote, exactly as shift_jis_hex does:
		// an ESC @ after a document would RESET the printer, so it can never be
		// part of an epilogue and must not be part of the recorded bytes.
		finishing[key] = hex.EncodeToString(e.Bytes()[len(escpos.Init):])
	}
	produced["finishing_hex"] = finishing

	// Whole-repertoire digest. The samples above catch the characters a slip
	// actually uses; this catches the ones a BRAND types into a footer. PHP's
	// SJIS codec and Go's disagree on 456 code points — 〜 (Japanese opening
	// hours), ①, ℡, the IBM extension kanji — and a sample list would never
	// have found them. Hashing every code point is total coverage in one
	// number, and small enough to commit.
	// #1957 piece A — the raster bit-image header, pinned across both repos.
	//
	// A logo is the first thing a shop notices and the last thing a test covers:
	// it either appears or it does not, and "it appeared but shifted by one dot
	// row" is invisible in review and obvious on paper. The header carries the
	// dimensions in two little-endian pairs, so an endianness slip in either
	// language produces a picture that is subtly the wrong shape rather than an
	// error.
	//
	// Cases chosen for the edges that actually bite: a width that is NOT a
	// multiple of 8 (padding), a byte count that is not a whole number of rows
	// (must emit nothing), and a zero width (must emit nothing).
	raster := map[string]string{}
	for name, tc := range map[string]struct {
		width int
		data  []byte
	}{
		"8x1_solid":   {8, []byte{0xFF}},
		"8x2_stripes": {8, []byte{0xAA, 0x55}},
		"12x2_padded": {12, []byte{0xFF, 0xF0, 0x0F, 0xF0}},
		"1x8_column":  {1, []byte{0x80, 0x80, 0x80, 0x80, 0x80, 0x80, 0x80, 0x80}},
		// width 12 -> 2 bytes/row, so 3 bytes is one and a HALF rows. The first
		// version used width 8 (1 byte/row), where 3 bytes is three VALID rows
		// — the case was named ragged and was not, so it pinned the opposite of
		// what its name claimed.
		"ragged_rows": {12, []byte{0xFF, 0xF0, 0x0F}},
		"zero_width":  {0, []byte{0xFF}},
		"empty_data":  {8, nil},
	} {
		e := escpos.New()
		ok := e.Raster(tc.width, tc.data)
		// The whole buffer, not just the command: the Init prefix proves the
		// no-op cases really wrote nothing rather than writing something the
		// slice hid.
		raster[name] = fmt.Sprintf("%t:%s", ok, hex.EncodeToString(e.Bytes()))
	}
	produced["raster_hex"] = raster

	produced["shift_jis_repertoire_sha256"] = shiftJISRepertoireDigest()

	if *updatePrimitives {
		body, err := json.MarshalIndent(produced, "", "  ")
		if err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(primitivesGoldenPath, append(body, '\n'), 0o644); err != nil {
			t.Fatal(err)
		}
		t.Logf("rewrote %s", primitivesGoldenPath)
		return
	}

	raw, err := os.ReadFile(primitivesGoldenPath)
	if err != nil {
		t.Fatalf("read %s: %v — rerun with -update-print-primitives", primitivesGoldenPath, err)
	}
	var recorded map[string]any
	if err := json.Unmarshal(raw, &recorded); err != nil {
		t.Fatalf("parse %s: %v", primitivesGoldenPath, err)
	}

	// Compare through JSON on both sides so map ordering never matters.
	gotJSON, _ := json.Marshal(produced)
	wantJSON, _ := json.Marshal(recorded)
	var gotAny, wantAny any
	_ = json.Unmarshal(gotJSON, &gotAny)
	_ = json.Unmarshal(wantJSON, &wantAny)
	if !jsonEqual(gotAny, wantAny) {
		t.Fatalf("print primitives changed — if this is deliberate, rerun with -update-print-primitives\n(and re-check the PHP renderer, which reads the same fixture)")
	}
}

// shiftJISRepertoireDigest hashes the encoding of every BMP code point, so the
// PHP port cannot claim parity on the strength of a lucky sample.
func shiftJISRepertoireDigest() string {
	var b bytes.Buffer
	for cp := 0x20; cp <= 0xFFFF; cp++ {
		if cp >= 0xD800 && cp <= 0xDFFF {
			continue // lone surrogates are not characters
		}
		e := escpos.New()
		e.Text(string(rune(cp)))
		b.Write(e.Bytes()[len(escpos.Init):])
		b.WriteByte(0x00) // separator, so two adjacent encodings cannot alias
	}
	sum := sha256.Sum256(b.Bytes())
	return hex.EncodeToString(sum[:])
}

func jsonEqual(a, b any) bool {
	ja, _ := json.Marshal(a)
	jb, _ := json.Marshal(b)
	return string(ja) == string(jb)
}
