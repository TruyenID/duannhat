package service

import (
	"strings"
	"testing"
)

func sampleTablePaid() TablePaidInfo {
	return TablePaidInfo{
		TableNumber: "12",
		OrderCode:   "WS-019e-20260608-004",
		PaidAt:      "2026/07/20 14:32",
	}
}

func TestFormatTablePaid_LocaleJA(t *testing.T) {
	cfg := PrintJobConfig{StoreName: "ベト屋", PaperWidth: 42, Locale: "ja"}
	text := decodeSJIS(t, FormatTablePaid(cfg, sampleTablePaid()))
	for _, want := range []string{
		"ベト屋",              // store header
		"2026/07/20 14:32", // date/time line
		"会計済 テーブル 12",      // headline message (single row)
	} {
		if !strings.Contains(text, want) {
			t.Errorf("JA slip missing %q\n---\n%s", want, text)
		}
	}
	// So HD is a right-aligned footer row (label left, code right), like the receipt.
	assertMetaRow(t, text, "伝票:", "004")
}

func TestFormatTablePaid_LocaleEN(t *testing.T) {
	cfg := PrintJobConfig{PaperWidth: 42, Locale: "en"}
	text := decodeSJIS(t, FormatTablePaid(cfg, sampleTablePaid()))
	for _, want := range []string{"2026/07/20 14:32", "PAID TABLE 12"} {
		if !strings.Contains(text, want) {
			t.Errorf("EN slip missing %q\n---\n%s", want, text)
		}
	}
	assertMetaRow(t, text, "Order#:", "004")
}

func TestFormatTablePaid_LocaleVI(t *testing.T) {
	cfg := PrintJobConfig{PaperWidth: 42, Locale: "vi"}
	text := decodeSJIS(t, FormatTablePaid(cfg, sampleTablePaid()))
	// Vietnamese is ASCII-folded (no diacritics — Shift_JIS can't encode them).
	if !strings.Contains(text, "DA THANH TOAN BAN 12") {
		t.Errorf("VI slip missing headline message\n---\n%s", text)
	}
	assertMetaRow(t, text, "So HD:", "004")
	if strings.Contains(text, "ĐÃ") || strings.Contains(text, "BÀN") {
		t.Errorf("VI slip should be ASCII-folded, got diacritics\n---\n%s", text)
	}
}

// An empty/unknown locale must fall back to Japanese — the SAME default as the
// receipt resolver printLabelsFor — so the slip's language always matches the
// receipt (both read rememberedPrintLocale, which is empty until a print).
func TestFormatTablePaid_DefaultsToJapaneseLikeReceipt(t *testing.T) {
	for _, locale := range []string{"", "  ", "xx"} {
		cfg := PrintJobConfig{PaperWidth: 42, Locale: locale}
		text := decodeSJIS(t, FormatTablePaid(cfg, sampleTablePaid()))
		if !strings.Contains(text, "会計済 テーブル 12") {
			t.Errorf("locale %q should default to Japanese (match receipt), got\n---\n%s", locale, text)
		}
	}
}

// On a wider printer (80mm / 48 cols) the 42-col content is indented by
// (48-42)/2 = 3 cols so the left and right margins match — no more left<right.
func TestFormatTablePaid_CentersOnWidePaper(t *testing.T) {
	narrow := decodeSJIS(t, FormatTablePaid(
		PrintJobConfig{PaperWidth: 42, Locale: "vi"}, sampleTablePaid()))
	wide := decodeSJIS(t, FormatTablePaid(
		PrintJobConfig{PaperWidth: 42, PhysicalWidth: 48, Locale: "vi"}, sampleTablePaid()))

	// Without a physical width there's no indent; with 48 the date line gains a
	// 3-space left margin.
	if strings.Contains(narrow, "   2026/07/20 14:32") {
		t.Errorf("narrow slip should not be indented\n---\n%s", narrow)
	}
	if !strings.Contains(wide, "   2026/07/20 14:32") {
		t.Errorf("wide slip should indent content by 3 cols to balance margins\n---\n%s", wide)
	}
}

// A blank table number degrades to "-" instead of an empty big line.
func TestFormatTablePaid_EmptyTable(t *testing.T) {
	cfg := PrintJobConfig{PaperWidth: 42, Locale: "en"}
	info := sampleTablePaid()
	info.TableNumber = ""
	text := decodeSJIS(t, FormatTablePaid(cfg, info))
	if !strings.Contains(text, "TABLE -") {
		t.Errorf("empty table should render placeholder, got\n---\n%s", text)
	}
}

// A very long table label must not panic and must still render in the headline;
// double-height is ×1 width so the whole message stays on the 42-col paper.
func TestFormatTablePaid_LongTableFallback(t *testing.T) {
	cfg := PrintJobConfig{PaperWidth: 42, Locale: "en"}
	info := sampleTablePaid()
	info.TableNumber = "TERRACE-OUTDOOR-15"
	text := decodeSJIS(t, FormatTablePaid(cfg, info))
	if !strings.Contains(text, "TERRACE-OUTDOOR-15") {
		t.Errorf("long table label missing\n---\n%s", text)
	}
}

// PaidAt is optional — omitting it must not leave a dangling blank line crash.
func TestFormatTablePaid_NoPaidAt(t *testing.T) {
	cfg := PrintJobConfig{PaperWidth: 42, Locale: "en"}
	info := sampleTablePaid()
	info.PaidAt = ""
	text := decodeSJIS(t, FormatTablePaid(cfg, info))
	if !strings.Contains(text, "TABLE 12") {
		t.Errorf("slip should still render without paid time\n---\n%s", text)
	}
}
