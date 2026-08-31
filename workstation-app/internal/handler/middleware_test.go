package handler

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

// dummyHandler returns 200 OK so middleware tests assert on the gate, not the
// downstream handler.
var dummyHandler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
	w.WriteHeader(http.StatusOK)
})

func TestLanOnlyAllowsPrivateAndLoopback(t *testing.T) {
	h := lanOnly(dummyHandler)

	cases := []string{
		"127.0.0.1:54321",   // loopback
		"[::1]:54321",       // ipv6 loopback
		"192.168.1.42:5040", // RFC1918
		"10.0.0.5:5040",     // RFC1918
		"172.16.2.3:5040",   // RFC1918
		"[fd00::1]:5040",    // IPv6 ULA (RFC4193 — corp LAN)
		"[fe80::1]:5040",    // IPv6 link-local
	}
	for _, addr := range cases {
		t.Run(addr, func(t *testing.T) {
			req := httptest.NewRequest("GET", "/whatever", nil)
			req.RemoteAddr = addr
			rec := httptest.NewRecorder()
			h.ServeHTTP(rec, req)
			if rec.Code != http.StatusOK {
				t.Errorf("expected 200 for %s, got %d", addr, rec.Code)
			}
		})
	}
}

func TestLanOnlyRejectsPublicIP(t *testing.T) {
	h := lanOnly(dummyHandler)

	cases := []string{
		"8.8.8.8:5040",      // public DNS
		"203.0.113.5:5040",  // TEST-NET-3
		"172.32.0.1:5040",   // just outside RFC1918 172.16/12
		"[2001:db8::1]:443", // TEST-NET-V6
		"not-an-ip:5040",    // malformed
		// Edge case: empty RemoteAddr (unix socket / test artefact).
		// SplitHostPort errs → handler uses the raw string as host →
		// ParseIP fails → 403. Pinning the deny side here so we don't
		// quietly let unparseable RemoteAddrs through.
		"",
	}
	for _, addr := range cases {
		t.Run(addr, func(t *testing.T) {
			req := httptest.NewRequest("GET", "/whatever", nil)
			req.RemoteAddr = addr
			rec := httptest.NewRecorder()
			h.ServeHTTP(rec, req)
			if rec.Code != http.StatusForbidden {
				t.Errorf("expected 403 for %s, got %d", addr, rec.Code)
			}
		})
	}
}

func TestIsAllowedOrigin(t *testing.T) {
	cases := []struct {
		origin string
		want   bool
		why    string
	}{
		{"http://localhost:5440", true, "loopback hostname"},
		{"http://192.168.1.10:5440", true, "RFC1918"},
		{"http://10.0.0.5", true, "RFC1918 no port"},
		{"https://[::1]:5440", true, "ipv6 loopback bracket form (was broken by Split-on-colon)"},
		{"http://[fd00::1]:5440", true, "ipv6 ULA bracket form"},
		{"http://shop1.local:5440", false, "mDNS .local must NOT pass — spoofable on LAN"},
		{"http://evil.local", false, "any .local is rejected"},
		{"http://8.8.8.8:5440", false, "public IP"},
		{"https://attacker.com", false, "public hostname"},
		{"", false, "empty"},
	}
	for _, tc := range cases {
		t.Run(tc.origin+"_"+tc.why, func(t *testing.T) {
			if got := isAllowedOrigin(tc.origin); got != tc.want {
				t.Errorf("isAllowedOrigin(%q) = %v, want %v (%s)", tc.origin, got, tc.want, tc.why)
			}
		})
	}
}

func TestOriginAllowed_GodxJpAnchoring(t *testing.T) {
	cases := []struct {
		origin string
		want   bool
		why    string
	}{
		{"https://pos.godx.jp", true, "legitimate subdomain"},
		{"https://kiosk.shop1.godx.jp", true, "deep subdomain"},
		{"https://pos.godx.jp:443", true, "subdomain with port (port stripped by url.Hostname)"},
		{"http://pos.godx.jp", false, "http on production hostname must be rejected"},
		{"https://evilgodx.jp", false, "must not match — no anchored dot"},
		{"https://godx.jp", false, "bare domain — only subdomains are allowed"},
		{"http://localhost:5440", true, "still allowed via allowedOrigins map"},
		{"http://127.0.0.1:5430", true, "loopback also allowed"},
		{"not-a-url", false, "malformed origin"},
	}
	for _, tc := range cases {
		t.Run(tc.origin+"_"+tc.why, func(t *testing.T) {
			if got := originAllowed(tc.origin); got != tc.want {
				t.Errorf("originAllowed(%q) = %v, want %v (%s)", tc.origin, got, tc.want, tc.why)
			}
		})
	}
}

func TestCorsMiddleware_AddsSecurityHeaders(t *testing.T) {
	h := corsMiddleware(dummyHandler)
	req := httptest.NewRequest("GET", "/api/status", nil)
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)

	cases := map[string]string{
		"X-Content-Type-Options":     "nosniff",
		"X-Frame-Options":            "DENY",
		"Referrer-Policy":            "strict-origin-when-cross-origin",
		"Cross-Origin-Opener-Policy": "same-origin",
	}
	for header, want := range cases {
		if got := rec.Header().Get(header); got != want {
			t.Errorf("%s: got %q, want %q", header, got, want)
		}
	}
}

func TestLocalOnlyAllowsLoopbackOnly(t *testing.T) {
	h := localOnly(dummyHandler)

	allow := []string{
		"127.0.0.1:54321",
		"[::1]:54321",
	}
	deny := []string{
		"192.168.1.42:5040", // LAN — not loopback
		"10.0.0.5:5040",
		"8.8.8.8:5040",
	}
	for _, addr := range allow {
		t.Run("allow_"+addr, func(t *testing.T) {
			req := httptest.NewRequest("GET", "/admin", nil)
			req.RemoteAddr = addr
			rec := httptest.NewRecorder()
			h.ServeHTTP(rec, req)
			if rec.Code != http.StatusOK {
				t.Errorf("expected 200 for %s, got %d", addr, rec.Code)
			}
		})
	}
	for _, addr := range deny {
		t.Run("deny_"+addr, func(t *testing.T) {
			req := httptest.NewRequest("GET", "/admin", nil)
			req.RemoteAddr = addr
			rec := httptest.NewRecorder()
			h.ServeHTTP(rec, req)
			if rec.Code != http.StatusForbidden {
				t.Errorf("expected 403 for %s, got %d", addr, rec.Code)
			}
		})
	}
}
