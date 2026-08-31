package service

import (
	"strings"
	"testing"
)

func sampleShiftOpen() ShiftOpenReportInfo {
	return ShiftOpenReportInfo{
		DeviceName: "POS-01",
		Operator:   "田中",
		OpenedAt:   "2026/07/06 14:00",
		Denominations: []ShiftOpenDenomLine{
			{Value: 10000, Quantity: 1, Subtotal: 10000},
			{Value: 5000, Quantity: 0, Subtotal: 0},
			{Value: 1000, Quantity: 3, Subtotal: 3000},
			{Value: 500, Quantity: 2, Subtotal: 1000},
			{Value: 100, Quantity: 5, Subtotal: 500},
		},
		OpeningFloat: 14500,
		Note:         "Ca sang du tien le",
		Currency:     "JPY",
	}
}

// assertMetaRow verifies a meta line renders the label flush-left and the value
// flush-right on the SAME line (the line ends with the value, and the label
// sits to its left).
func assertMetaRow(t *testing.T, text, label, value string) {
	t.Helper()
	for _, ln := range strings.Split(text, "\n") {
		if strings.Contains(ln, label) && strings.Contains(ln, value) {
			if !strings.HasSuffix(strings.TrimRight(ln, " "), value) {
				t.Errorf("meta %q: value %q should be right-aligned, got %q", label, value, ln)
			}
			if strings.Index(ln, label) >= strings.LastIndex(ln, value) {
				t.Errorf("meta %q: label should be left of value, got %q", label, ln)
			}
			return
		}
	}
	t.Errorf("meta row not found: label=%q value=%q\n---\n%s", label, value, text)
}

func TestFormatShiftOpenReport_Reference(t *testing.T) {
	cfg := PrintJobConfig{StoreName: "ベト屋イベント", PaperWidth: 42, Locale: "ja"}
	text := decodeSJIS(t, FormatShiftOpenReport(cfg, sampleShiftOpen()))

	for _, want := range []string{
		"ベト屋イベント", "開始", // header + title
		"金種", "枚数", "金額", // column header
		"10,000円", "1枚", "1,000円", "500円", "5枚", // denomination rows
		"合計", "14,500円", // total
		"備考", "Ca sang du tien le", // note
	} {
		if !strings.Contains(text, want) {
			t.Errorf("JA slip missing %q\n---\n%s", want, text)
		}
	}
	// Meta rows: label left, value right.
	assertMetaRow(t, text, "端末", "POS-01")
	assertMetaRow(t, text, "担当者", "田中")
	assertMetaRow(t, text, "開始時刻", "2026/07/06 14:00")
}

func TestFormatShiftOpenReport_LocaleEN(t *testing.T) {
	cfg := PrintJobConfig{StoreName: "ベト屋イベント", PaperWidth: 42, Locale: "en"}
	text := decodeSJIS(t, FormatShiftOpenReport(cfg, sampleShiftOpen()))
	for _, want := range []string{"SHIFT OPEN", "Denomination", "Qty", "Amount", "Total", "Note"} {
		if !strings.Contains(text, want) {
			t.Errorf("EN slip missing %q\n---\n%s", want, text)
		}
	}
	assertMetaRow(t, text, "Device", "POS-01")
	assertMetaRow(t, text, "Operator", "田中")
	assertMetaRow(t, text, "Opened at", "2026/07/06 14:00")
	for _, jp := range []string{"開始", "端末", "担当者", "金種", "合計", "備考"} {
		if strings.Contains(text, jp) {
			t.Errorf("EN slip leaked Japanese label %q", jp)
		}
	}
}

// TestFormatShiftOpenReport_LocaleVI checks the Vietnamese labels render and —
// critically — that the slip carries no combining diacritics (Shift_JIS can't
// encode them). The store name (kana) and 円 unit are the only allowed
// non-ASCII glyphs.
func TestFormatShiftOpenReport_LocaleVI(t *testing.T) {
	cfg := PrintJobConfig{StoreName: "ベト屋イベント", PaperWidth: 42, Locale: "vi"}
	text := decodeSJIS(t, FormatShiftOpenReport(cfg, sampleShiftOpen()))
	for _, want := range []string{"MO CA", "Thiet bi", "Nguoi mo ca", "Gio mo ca", "Menh gia", "SL", "So tien", "Tong", "Ghi chu"} {
		if !strings.Contains(text, want) {
			t.Errorf("VI slip missing %q\n---\n%s", want, text)
		}
	}
	for _, r := range text {
		if r > 0x7F && !isAllowedNonASCII(r) {
			t.Errorf("VI slip has non-ASCII rune %q (U+%04X) — Shift_JIS can't print it", r, r)
		}
	}
}

func TestFormatShiftOpenReport_OperatorFallback(t *testing.T) {
	info := sampleShiftOpen()
	info.Operator = "" // unset → locale fallback
	text := decodeSJIS(t, FormatShiftOpenReport(PrintJobConfig{PaperWidth: 42, Locale: "ja"}, info))
	assertMetaRow(t, text, "担当者", "未設定")
}

// TestWrapText exercises the note-wrapping helper directly (measuring rendered
// ESC/POS lines is unreliable — control bytes leak into the decoded text).
func TestWrapText(t *testing.T) {
	// Latin: wraps on spaces, no line exceeds width, all words preserved.
	lines := wrapText(strings.Repeat("word ", 20), 20)
	if len(lines) < 2 {
		t.Fatalf("expected the long note to wrap into multiple lines, got %d", len(lines))
	}
	joined := strings.Join(lines, " ")
	for _, ln := range lines {
		if displayWidth(ln) > 20 {
			t.Errorf("wrapped line exceeds width 20 (%d): %q", displayWidth(ln), ln)
		}
	}
	if strings.Count(joined, "word") != 20 {
		t.Errorf("wrapping lost/duplicated words: %q", joined)
	}

	// CJK (no spaces) must hard-split so it never overflows.
	for _, ln := range wrapText(strings.Repeat("あ", 30), 10) {
		if displayWidth(ln) > 10 {
			t.Errorf("CJK line exceeds width 10 (%d): %q", displayWidth(ln), ln)
		}
	}

	// Explicit newlines start a fresh line.
	if got := wrapText("a\nb", 40); len(got) != 2 || got[0] != "a" || got[1] != "b" {
		t.Errorf("newline handling: got %q", got)
	}
}

func TestFormatShiftOpenReport_NonJPYCurrency(t *testing.T) {
	info := sampleShiftOpen()
	info.Currency = "VND"
	text := decodeSJIS(t, FormatShiftOpenReport(PrintJobConfig{PaperWidth: 42}, info))
	if !strings.Contains(text, "10,000 VND") {
		t.Errorf("non-JPY currency should suffix the ISO code\n---\n%s", text)
	}
	if strings.Contains(text, "円") {
		t.Errorf("non-JPY report should not print the 円 glyph")
	}
}
