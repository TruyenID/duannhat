package label

import (
	"bytes"
	"testing"
	"time"
)

func TestFormatEmitsTitleAndStarCut(t *testing.T) {
	exp := time.Date(2026, 7, 12, 9, 30, 0, 0, time.UTC)
	out := Format(Label{
		Title:      "Tare Sauce",
		Lines:      []string{"Qty: 3"},
		PreparedAt: time.Date(2026, 7, 11, 8, 0, 0, 0, time.UTC),
		ExpiresAt:  &exp,
		LotCode:    "A1234",
	}, 32)

	for _, want := range []string{"Tare Sauce", "Prep:", "Exp:", "Qty: 3", "Lot:   A1234"} {
		if !bytes.Contains(out, []byte(want)) {
			t.Errorf("label output missing %q", want)
		}
	}

	// Must use the StarPRNT cut (ESC d 3), never the ESC/POS GS V 0 (see #438).
	if !bytes.HasSuffix(out, []byte{0x1B, 0x64, 0x33}) {
		t.Errorf("label output must end with StarPRNT full cut ESC d 3")
	}
	if bytes.Contains(out, []byte{0x1D, 0x56, 0x00}) {
		t.Errorf("label output must not contain ESC/POS GS V 0 cut")
	}
}

func TestFormatOmitsOptionalFields(t *testing.T) {
	out := Format(Label{Title: "Water"}, 0)
	if bytes.Contains(out, []byte("Exp:")) {
		t.Errorf("expiry line must be omitted when ExpiresAt is nil")
	}
	if bytes.Contains(out, []byte("Lot:")) {
		t.Errorf("lot line must be omitted when LotCode is empty")
	}
}
