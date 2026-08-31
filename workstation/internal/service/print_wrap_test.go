package service

import (
	"strings"
	"testing"
)

// widths mirrors printWrappedName: firstW is narrower (price on the right).
func TestWrapNameLines(t *testing.T) {
	t.Run("short name single line", func(t *testing.T) {
		got := wrapNameLines("Pho bo", 20, 26)
		if len(got) != 1 || got[0] != "Pho bo" {
			t.Fatalf("want single line 'Pho bo', got %#v", got)
		}
	})

	t.Run("word wrap does not split mid-word", func(t *testing.T) {
		got := wrapNameLines("Tra sua tran chau duong den", 12, 18)
		for _, ln := range got {
			if len(ln) > 18 {
				t.Fatalf("line exceeds contW: %q", ln)
			}
		}
		// Rejoining with spaces must reproduce the words in order (no mid-word cut).
		if joined := strings.Join(got, " "); joined != "Tra sua tran chau duong den" {
			t.Fatalf("words altered on wrap: %q", joined)
		}
	})

	t.Run("no 1-column widow from char split", func(t *testing.T) {
		// A spaceless token 1 column wider than the first line used to drop a
		// single char onto its own line. Widow control must prevent that.
		name := strings.Repeat("A", 13) // 13 cols; firstW=12 → 1 char would orphan
		got := wrapNameLines(name, 12, 20)
		last := got[len(got)-1]
		if len([]rune(last)) < 2 {
			t.Fatalf("final line is a 1-char widow: %#v", got)
		}
	})

	t.Run("long spaceless CJK token char-splits within width", func(t *testing.T) {
		name := strings.Repeat("メ", 20) // 40 display cols
		got := wrapNameLines(name, 12, 18)
		for _, ln := range got {
			if displayWidth(ln) > 18 {
				t.Fatalf("CJK line exceeds contW: %q (%d cols)", ln, displayWidth(ln))
			}
		}
	})
}
