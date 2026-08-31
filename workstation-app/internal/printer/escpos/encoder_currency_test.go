package escpos

import (
	"bytes"
	"testing"
)

// ₫ (Vietnamese đồng) has no Shift-JIS glyph. It must be printed as the ASCII
// "d" — never the raw UTF-8 bytes (0xE2 0x82 0xAB) that garbled the receipt.
func TestEncodeDongToAsciiD(t *testing.T) {
	out, err := encodeShiftJIS("₫50,000")
	if err != nil {
		t.Fatalf("encodeShiftJIS returned error: %v", err)
	}
	if !bytes.Equal(out, []byte("d50,000")) {
		t.Fatalf("₫50,000 → %q, want %q", out, "d50,000")
	}
	if bytes.Contains(out, []byte{0xE2, 0x82, 0xAB}) {
		t.Fatalf("output must not contain raw UTF-8 ₫ bytes")
	}
}

// The yen sign still maps to the JIS 0x5C.
func TestEncodeYenToJis(t *testing.T) {
	out, err := encodeShiftJIS("¥1,000")
	if err != nil {
		t.Fatalf("encodeShiftJIS returned error: %v", err)
	}
	if !bytes.Equal(out, []byte{0x5C, '1', ',', '0', '0', '0'}) {
		t.Fatalf("¥1,000 → %v, want yen 0x5C prefix", out)
	}
}

// An unsupported rune must not blow up the whole line into raw-UTF-8 garbage —
// it's substituted, and the surrounding ASCII survives intact.
func TestEncodeUnsupportedRuneSubstituted(t *testing.T) {
	out, err := encodeShiftJIS("A€B")
	if err != nil {
		t.Fatalf("encodeShiftJIS returned error: %v", err)
	}
	if out[0] != 'A' || out[len(out)-1] != 'B' {
		t.Fatalf("ASCII around an unsupported rune should survive, got %q", out)
	}
	if bytes.Contains(out, []byte("€")) {
		t.Fatalf("unsupported € should have been substituted, not raw UTF-8")
	}
}
