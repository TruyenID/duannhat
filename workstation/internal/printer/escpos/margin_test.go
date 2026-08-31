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

// A ×2-WIDTH expansion must not double the left margin.
//
// The margin is written lazily by the first Text() of a line, so it lands
// INSIDE whatever expansion is active. Under DoubleSize each margin space cost
// two columns, indenting the line by twice the configured margin and pushing
// every column on it out from under the header it belonged to — measured on a
// real kitchen ticket, whose value row started at column 6 of a 3-column margin.
//
// DoubleHeight leaves the character cell one column wide, so it needs no
// correction and must not receive one: every existing caller's bytes depend on
// that.
func TestEncoder_LeftMarginIsNotScaledByDoubleWidth(t *testing.T) {
	cases := []struct {
		name       string
		size       []byte
		wantNormal bool // margin wrapped back to normal size
	}{
		{"double size", DoubleSize, true},
		{"double width", DoubleWidth, true},
		{"double height", DoubleHeight, false},
		{"normal", NormalSize, false},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			e := New()
			e.SetLeftMargin(3)
			e.Size(tc.size)
			e.Text("X")

			out := e.Bytes()
			margin := append(append([]byte{}, NormalSize...), []byte("   ")...)
			margin = append(margin, tc.size...)
			if got := bytes.Contains(out, margin); got != tc.wantNormal {
				t.Errorf("margin wrapped in NormalSize = %v, want %v (bytes %q)", got, tc.wantNormal, out)
			}
			if !bytes.Contains(out, []byte("   X")) && !tc.wantNormal {
				t.Errorf("margin missing entirely: %q", out)
			}
		})
	}
}
