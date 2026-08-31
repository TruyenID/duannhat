package handler

import "testing"

func TestNormalizePrintLocale(t *testing.T) {
	cases := []struct {
		in, want string
	}{
		{"", "ja"},                  // empty → store default
		{"ja", "ja"},                // clean tag
		{"en", "en"},                // clean tag
		{"vi", "vi"},                // clean tag
		{"EN", "en"},                // case-insensitive
		{"en-US", "en"},             // region subtag stripped
		{"vi-VN", "vi"},             // region subtag stripped
		{"en-US,en;q=0.9,vi", "en"}, // full browser header → first tag
		{"  vi  ", "vi"},            // trimmed
		{"fr", "ja"},                // unsupported → fallback
		{"zh-CN,zh;q=0.9", "ja"},    // unsupported first tag → fallback
		{"vi;q=0.8", "vi"},          // quality weight stripped
	}
	for _, c := range cases {
		if got := normalizePrintLocale(c.in); got != c.want {
			t.Errorf("normalizePrintLocale(%q) = %q, want %q", c.in, got, c.want)
		}
	}
}
