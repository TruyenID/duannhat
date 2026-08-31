package escpos

import (
	"testing"

	"golang.org/x/text/unicode/norm"
)

func TestStripAccents(t *testing.T) {
	cases := []struct {
		name string
		in   string
		want string
	}{
		{"plain ascii unchanged", "Chi nhanh Ha Noi", "Chi nhanh Ha Noi"},
		{"branch name", "Chi nhánh Hà Nội", "Chi nhanh Ha Noi"},
		{"dish name with horn/dot", "phở đặc biệt", "pho dac biet"},
		{"d-stroke upper and lower", "Đường Đỏ đen", "Duong Do den"},
		{"all tone marks", "à á ả ã ạ", "a a a a a"},
		{"circumflex breve horn", "â ă ê ô ơ ư", "a a e o o u"},
		{"uppercase vowels", "ĐÀO ÂU Ơ Ư", "DAO AU O U"},
		{"empty", "", ""},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := StripAccents(c.in); got != c.want {
				t.Errorf("StripAccents(%q) = %q, want %q", c.in, got, c.want)
			}
		})
	}
}

// NFD (decomposed) input — base letter + standalone combining marks — must fold
// identically to the precomposed (NFC) form.
func TestStripAccents_NFDInput(t *testing.T) {
	nfd := norm.NFD.String("Chi nhánh Hà Nội")
	if got := StripAccents(nfd); got != "Chi nhanh Ha Noi" {
		t.Errorf("StripAccents(NFD) = %q, want %q", got, "Chi nhanh Ha Noi")
	}
}

// Japanese store names must pass through untouched — folding must not split
// voiced kana (が → か) or drop kanji.
func TestStripAccents_JapaneseUntouched(t *testing.T) {
	cases := []string{"ベト屋", "がぎぐげご", "東京都渋谷区"}
	for _, in := range cases {
		if got := StripAccents(in); got != in {
			t.Errorf("StripAccents(%q) = %q, want unchanged", in, got)
		}
	}
}

// End-to-end: a Vietnamese line encodes to clean ASCII (no raw UTF-8 fallback).
func TestEncodeShiftJIS_VietnameseFoldedToAscii(t *testing.T) {
	out, err := encodeShiftJIS("Chi nhánh Hà Nội")
	if err != nil {
		t.Fatalf("encodeShiftJIS returned error: %v", err)
	}
	if string(out) != "Chi nhanh Ha Noi" {
		t.Fatalf("encoded = %q, want %q", out, "Chi nhanh Ha Noi")
	}
}
