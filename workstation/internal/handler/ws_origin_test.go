package handler

import (
	"net/http/httptest"
	"testing"
)

// TestCheckWSOrigin locks the #86 fix: the WS upgrade no longer accepts every
// Origin. Native apps (no Origin) still connect; browsers must be on the
// allow-list; and the Host must be LAN/loopback (anti-DNS-rebinding).
func TestCheckWSOrigin(t *testing.T) {
	cases := []struct {
		name   string
		origin string
		host   string
		want   bool
	}{
		{"native app no origin, loopback host", "", "localhost:8080", true},
		{"native app no origin, lan host", "", "192.168.1.50:8080", true},
		{"pos-web dev origin, lan host", "http://localhost:5440", "192.168.1.50:8080", true},
		{"kds prod origin, lan host", "https://kds.godx.jp", "192.168.1.50:8080", true},
		{"lan-ip origin, lan host", "http://192.168.1.5:5440", "192.168.1.50:8080", true},
		// refused: cross-site browser origin
		{"evil origin, lan host", "https://evil.example", "192.168.1.50:8080", false},
		{"bare godx.jp origin", "https://godx.jp", "192.168.1.50:8080", false},
		// refused: DNS-rebinding — attacker domain pointed at the LAN IP
		{"no origin, public host", "", "evil.example:8080", false},
		{"no origin, public ip host", "", "8.8.8.8:8080", false},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			r := httptest.NewRequest("GET", "/ws", nil)
			r.Host = tc.host
			if tc.origin != "" {
				r.Header.Set("Origin", tc.origin)
			}
			if got := checkWSOrigin(r); got != tc.want {
				t.Errorf("checkWSOrigin(origin=%q host=%q) = %v, want %v", tc.origin, tc.host, got, tc.want)
			}
		})
	}
}
