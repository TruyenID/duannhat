package escpos

import (
	"bytes"
	"testing"
)

// SetLeftMargin must indent left-aligned lines by exactly n columns so a narrow
// layout prints centered on wider paper.
func TestSetLeftMargin_IndentsLeftAlignedLines(t *testing.T) {
	e := New()
	e.SetLeftMargin(3)
	e.Align(AlignLeft)
	e.Line("Hello")
	out := e.Bytes()
	if !bytes.Contains(out, []byte("   Hello")) {
		t.Fatalf("left-aligned line should be indented by 3 spaces, got %q", out)
	}
}

// A margin of 0 must not change output (legacy behavior / back-compat).
func TestSetLeftMargin_ZeroIsNoOp(t *testing.T) {
	a := New().Align(AlignLeft).Line("Hello").Bytes()
	b := New().SetLeftMargin(0).Align(AlignLeft).Line("Hello").Bytes()
	if !bytes.Equal(a, b) {
		t.Fatalf("margin 0 changed output:\n a=%q\n b=%q", a, b)
	}
}

// Centered lines must NOT be indented — the printer centers them on the full
// paper width already, so an extra left margin would push them off-center.
func TestSetLeftMargin_SkipsCenteredLines(t *testing.T) {
	e := New()
	e.SetLeftMargin(3)
	e.Align(AlignCenter)
	e.Line("TITLE")
	out := e.Bytes()
	if bytes.Contains(out, []byte("   TITLE")) {
		t.Fatalf("centered line must not be indented by the left margin, got %q", out)
	}
	if !bytes.Contains(out, []byte("TITLE")) {
		t.Fatalf("centered line content missing, got %q", out)
	}
}

// The margin applies per line: switching back to left alignment re-indents.
func TestSetLeftMargin_ReindentsAfterCenter(t *testing.T) {
	e := New()
	e.SetLeftMargin(2)
	e.Align(AlignCenter)
	e.Line("MID")
	e.Align(AlignLeft)
	e.Line("Body")
	out := e.Bytes()
	if !bytes.Contains(out, []byte("  Body")) {
		t.Fatalf("left line after a centered line should be indented, got %q", out)
	}
}
