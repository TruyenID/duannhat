package escpos

import (
	"bytes"
	"strings"
	"unicode"

	"golang.org/x/text/encoding"
	"golang.org/x/text/encoding/japanese"
	"golang.org/x/text/transform"
	"golang.org/x/text/unicode/norm"
)

// ESC/POS command bytes
var (
	Init = []byte{0x1B, 0x40} // ESC @ - Initialize printer
	// StarPRNT cut commands (ESC d n). The project targets Star mC-Print3 in
	// StarPRNT emulation mode, which ignores the ESC/POS GS V cut — see #438.
	// ESC d n feeds n lines and then cuts, so callers do NOT need a separate feed.
	Cut            = []byte{0x1B, 0x64, 0x33}             // ESC d 3 - feed 3 lines + full cut
	PartialCut     = []byte{0x1B, 0x64, 0x32}             // ESC d 2 - feed 3 lines + partial cut
	OpenCashDrawer = []byte{0x1B, 0x70, 0x00, 0x19, 0xFA} // ESC p 0 25 250
	LineFeed       = []byte{0x0A}                         // LF
	KanjiModeOn    = []byte{0x1C, 0x26}                   // FS & - Select Kanji mode
	KanjiModeOff   = []byte{0x1C, 0x2E}                   // FS . - Cancel Kanji mode
)

// Text alignment — StarPRNT uses ESC GS a (1B 1D 61), not Epson ESC a (1B 61).
var (
	AlignLeft   = []byte{0x1B, 0x1D, 0x61, 0x00}
	AlignCenter = []byte{0x1B, 0x1D, 0x61, 0x01}
	AlignRight  = []byte{0x1B, 0x1D, 0x61, 0x02}
)

// Text style
var (
	BoldOn  = []byte{0x1B, 0x45, 0x01}
	BoldOff = []byte{0x1B, 0x45, 0x00}
)

// Character expansion — StarPRNT / Star Line Mode use "ESC i n1 n2"
// (1B 69 n1 n2): n1 = height expansion, n2 = width expansion (0 = ×1, 1 = ×2).
//
// This is NOT the Epson "ESC ! n" (1B 21 n) print-mode command. On a Star
// printer ESC ! is not a size command: it is dropped and the trailing parameter
// byte prints literally — which is why DoubleSize's old 0x30 leaked a stray "0"
// onto the slip and no text ever actually enlarged. These constants are the
// only sizing used by any template (currently just the 精算 shift report).
var (
	NormalSize   = []byte{0x1B, 0x69, 0x00, 0x00} // ESC i 0 0 — normal (×1 / ×1)
	DoubleHeight = []byte{0x1B, 0x69, 0x01, 0x00} // ESC i 1 0 — ×2 height
	DoubleWidth  = []byte{0x1B, 0x69, 0x00, 0x01} // ESC i 0 1 — ×2 width
	DoubleSize   = []byte{0x1B, 0x69, 0x01, 0x01} // ESC i 1 1 — ×2 height + width
)

type Encoder struct {
	buf         bytes.Buffer
	leftMargin  int  // blank columns prepended to each left-aligned line
	atLineStart bool // true when the next Text() begins a fresh line
	leftAligned bool // current justification is left (margin only applies then)
}

func New() *Encoder {
	e := &Encoder{atLineStart: true, leftAligned: true}
	e.buf.Write(Init)
	// FS & (KanjiModeOn) is NOT sent here — Star mC-Print3 does not support it
	// and enters a stuck state waiting for multi-byte input, swallowing all
	// subsequent bytes including the cut command.
	return e
}

// SetLeftMargin indents every subsequent left-aligned line by n blank columns.
// It lets content laid out for a narrow width (e.g. 42 cols) print centered on
// wider paper (e.g. 48 cols) by giving it an equal left/right margin. Centered
// and right-aligned lines are left untouched — the printer already places those
// against the full printable width, so they stay centered on the paper.
func (e *Encoder) SetLeftMargin(n int) *Encoder {
	if n < 0 {
		n = 0
	}
	e.leftMargin = n
	return e
}

func (e *Encoder) Text(s string) *Encoder {
	if e.atLineStart && s != "" {
		if e.leftMargin > 0 && e.leftAligned {
			e.buf.WriteString(strings.Repeat(" ", e.leftMargin))
		}
		e.atLineStart = false
	}
	encoded, err := encodeShiftJIS(s)
	if err != nil {
		// Fallback to raw bytes if encoding fails
		e.buf.WriteString(s)
	} else {
		e.buf.Write(encoded)
	}
	return e
}

func (e *Encoder) Line(s string) *Encoder {
	return e.Text(s).Feed(1)
}

func (e *Encoder) Feed(lines int) *Encoder {
	for range lines {
		e.buf.Write(LineFeed)
	}
	e.atLineStart = true
	return e
}

func (e *Encoder) Bold(on bool) *Encoder {
	if on {
		e.buf.Write(BoldOn)
	} else {
		e.buf.Write(BoldOff)
	}
	return e
}

func (e *Encoder) Align(align []byte) *Encoder {
	e.buf.Write(align)
	e.leftAligned = bytes.Equal(align, AlignLeft)
	return e
}

func (e *Encoder) Size(size []byte) *Encoder {
	e.buf.Write(size)
	return e
}

func (e *Encoder) Separator(width int) *Encoder {
	for range width {
		e.buf.WriteByte('-')
	}
	e.buf.Write(LineFeed)
	return e
}

func (e *Encoder) DoubleSeparator(width int) *Encoder {
	for range width {
		e.buf.WriteByte('=')
	}
	e.buf.Write(LineFeed)
	return e
}

func (e *Encoder) DashSeparator(width int) *Encoder {
	for i := range width {
		if i%2 == 0 {
			e.buf.WriteByte('-')
		} else {
			e.buf.WriteByte(' ')
		}
	}
	e.buf.Write(LineFeed)
	return e
}

// FullCut sends the StarPRNT cut command ESC d 3 (0x1B 0x64 0x33), which feeds
// 3 lines then cuts in a single command. Star mC-Print3 (StarPRNT emulation)
// ignores the ESC/POS GS V cut and never ejects paper — see #438 — so this is
// the only cut that works on the target hardware.
func (e *Encoder) FullCut() *Encoder {
	e.buf.Write(Cut)
	return e
}

// PartCut sends the StarPRNT partial-cut command ESC d 2 (0x1B 0x64 0x32),
// which likewise feeds 3 lines before cutting.
func (e *Encoder) PartCut() *Encoder {
	e.buf.Write(PartialCut)
	return e
}

func (e *Encoder) OpenDrawer() *Encoder {
	e.buf.Write(OpenCashDrawer)
	return e
}

// QRCode prints a QR code using StarPRNT ESC GS y commands (1B 1D 79).
// This is the correct command set for Star mC-Print3 (StarPRNT emulation only —
// the printer does not support Epson GS ( k).
// size: cell size in dots 1–8 (3 = ~medium, 4–5 = recommended for scanning).
func (e *Encoder) QRCode(data string, size byte) *Encoder {
	if size < 1 {
		size = 1
	}
	if size > 8 {
		size = 8
	}

	d := []byte(data)
	nL := byte(len(d) & 0xFF)
	nH := byte((len(d) >> 8) & 0xFF)

	// ESC GS y S 0 2 — Select model 2
	e.buf.Write([]byte{0x1B, 0x1D, 0x79, 0x53, 0x30, 0x02})
	// ESC GS y S 1 1 — Error correction level M (~15%)
	e.buf.Write([]byte{0x1B, 0x1D, 0x79, 0x53, 0x31, 0x01})
	// ESC GS y S 2 n — Cell size
	e.buf.Write([]byte{0x1B, 0x1D, 0x79, 0x53, 0x32, size})
	// ESC GS y D 1 0 nL nH d... — Store data (auto-detect encoding, m=0)
	e.buf.Write([]byte{0x1B, 0x1D, 0x79, 0x44, 0x31, 0x00, nL, nH})
	e.buf.Write(d)
	// ESC GS y P — Print QR code
	e.buf.Write([]byte{0x1B, 0x1D, 0x79, 0x50})
	return e
}

func (e *Encoder) Raw(data []byte) *Encoder {
	e.buf.Write(data)
	return e
}

func (e *Encoder) Bytes() []byte {
	return e.buf.Bytes()
}

// StripAccents folds Latin/Vietnamese diacritics down to plain ASCII so a
// thermal printer with no Vietnamese glyph support prints readable text
// ("Chi nhánh Hà Nội" → "Chi nhanh Ha Noi", "phở đặc biệt" → "pho dac biet").
//
// Only Latin-script letters are folded; CJK text is passed through untouched —
// NFD-decomposing Japanese would split voiced kana (が → か + ゛) and corrupt the
// store name. Vietnamese đ/Đ and the horn/breve/circumflex vowels are all
// covered: đ/Đ have no canonical decomposition so they are mapped explicitly,
// every other accented vowel decomposes (NFD) into a base letter + combining
// marks that are then dropped.
func StripAccents(s string) string {
	// Fast path: pure ASCII (the common case for order codes, prices) needs no work.
	ascii := true
	for i := 0; i < len(s); i++ {
		if s[i] >= 0x80 {
			ascii = false
			break
		}
	}
	if ascii {
		return s
	}

	var b strings.Builder
	b.Grow(len(s))
	for _, r := range s {
		switch {
		// Standalone combining marks (already-decomposed / NFD input) print nothing.
		case unicode.Is(unicode.Mn, r) || unicode.Is(unicode.Me, r):
			continue
		case r == 'đ':
			b.WriteByte('d')
		case r == 'Đ':
			b.WriteByte('D')
		case r < 0x80:
			b.WriteRune(r)
		case unicode.Is(unicode.Latin, r):
			// Decompose the precomposed letter and keep only the base rune(s),
			// dropping the combining marks (acute, grave, hook, tilde, dot…).
			for _, dr := range norm.NFD.String(string(r)) {
				if unicode.Is(unicode.Mn, dr) || unicode.Is(unicode.Me, dr) {
					continue
				}
				b.WriteRune(dr)
			}
		default:
			// Non-Latin (CJK, currency symbols, punctuation) is left as-is.
			b.WriteRune(r)
		}
	}
	return b.String()
}

func encodeShiftJIS(s string) ([]byte, error) {
	// Fold Vietnamese/Latin accents to ASCII first — the printer codepage has no
	// Vietnamese glyphs, so "á" must become "a" to render at all. Japanese is
	// untouched (StripAccents only folds Latin script).
	s = StripAccents(s)
	// U+00A5 (¥) is not in Shift-JIS, but 0x5C is the yen sign in
	// JIS/Shift-JIS codepages used by Star/Epson thermal printers.
	// Replace before encoding so it renders correctly on the receipt.
	s = strings.ReplaceAll(s, "¥", "\x5C")
	// U+20AB (₫) Vietnamese đồng has no Shift-JIS glyph — print the ASCII "d"
	// (đồng) so a VND receipt shows a readable currency mark instead of the
	// garbled bytes it fell back to when the whole string failed to encode.
	s = strings.ReplaceAll(s, "₫", "d")
	var buf bytes.Buffer
	// ReplaceUnsupported: any remaining rune outside Shift-JIS (exotic currency
	// symbols, stray diacritics) is substituted rather than erroring — the old
	// error path fell back to raw UTF-8 for the WHOLE line, which printed as
	// garbage even for the ASCII parts around the bad rune.
	enc := encoding.ReplaceUnsupported(japanese.ShiftJIS.NewEncoder())
	writer := transform.NewWriter(&buf, enc)
	_, err := writer.Write([]byte(s))
	if err != nil {
		return nil, err
	}
	if err := writer.Close(); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}
