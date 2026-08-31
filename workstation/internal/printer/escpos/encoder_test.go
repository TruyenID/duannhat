package escpos

import (
	"bytes"
	"testing"
)

// Star mC-Print3 (StarPRNT emulation) ignores the ESC/POS GS V cut and never
// ejects paper. The encoder must emit the StarPRNT cut ESC d 3 / ESC d 2. See #438.
func TestCutCommandsAreStarPRNT(t *testing.T) {
	starFullCut := []byte{0x1B, 0x64, 0x33} // ESC d 3
	starPartCut := []byte{0x1B, 0x64, 0x32} // ESC d 2
	escposCut := []byte{0x1D, 0x56, 0x00}   // GS V 0 - must NOT appear

	if !bytes.Equal(Cut, starFullCut) {
		t.Errorf("Cut = %#v, want StarPRNT ESC d 3 %#v", Cut, starFullCut)
	}
	if !bytes.Equal(PartialCut, starPartCut) {
		t.Errorf("PartialCut = %#v, want StarPRNT ESC d 2 %#v", PartialCut, starPartCut)
	}

	full := New().Text("hi").FullCut().Bytes()
	if !bytes.HasSuffix(full, starFullCut) {
		t.Errorf("FullCut output must end with ESC d 3, got tail %#v", tail(full, 3))
	}
	if bytes.Contains(full, escposCut) {
		t.Errorf("FullCut output must not contain ESC/POS GS V 0 cut")
	}

	part := New().Text("hi").PartCut().Bytes()
	if !bytes.HasSuffix(part, starPartCut) {
		t.Errorf("PartCut output must end with ESC d 2, got tail %#v", tail(part, 3))
	}
}

func tail(b []byte, n int) []byte {
	if len(b) < n {
		return b
	}
	return b[len(b)-n:]
}
