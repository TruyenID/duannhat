package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
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
	req := httptest.NewRequest("GET", "http://localhost:6969/api/status", nil)
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)

	cases := map[string]string{
		"X-Content-Type-Options":     "nosniff",
		"X-Frame-Options":            "SAMEORIGIN",
		"Content-Security-Policy":    "frame-ancestors 'self'",
		"Referrer-Policy":            "strict-origin-when-cross-origin",
		"Cross-Origin-Opener-Policy": "same-origin",
	}
	for header, want := range cases {
		if got := rec.Header().Get(header); got != want {
			t.Errorf("%s: got %q, want %q", header, got, want)
		}
	}
}

// LAN tablets open http://192.168.x.x/pos — browsers ignore COOP there and
// warn. Do not emit the header on that untrustworthy origin.
func TestCorsMiddleware_SkipsCOOPOnLANHTTP(t *testing.T) {
	h := corsMiddleware(dummyHandler)
	req := httptest.NewRequest("GET", "http://192.168.3.11:6969/pos/shop/hongo", nil)
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)

	if got := rec.Header().Get("Cross-Origin-Opener-Policy"); got != "" {
		t.Errorf("COOP on LAN HTTP = %q, want empty", got)
	}
	if got := rec.Header().Get("X-Content-Type-Options"); got != "nosniff" {
		t.Errorf("other security headers must still be set, X-Content-Type-Options=%q", got)
	}
}

// Full trustworthy-origin matrix for COOP. The rule mirrors the browser's
// "potentially trustworthy origin" definition: HTTPS, or http on localhost /
// a loopback literal. Everything else (LAN IPv4, a .local hostname, an empty
// Host) gets no COOP — the browser would ignore it and log a warning on every
// page load of /pos.
func TestCorsMiddleware_COOPOnlyOnTrustworthyOrigins(t *testing.T) {
	cases := []struct {
		name    string
		target  string
		host    string // overrides r.Host when non-empty
		wantSet bool
	}{
		{name: "https LAN ip", target: "https://192.168.3.11:6969/pos", wantSet: true},
		{name: "https public host", target: "https://shop.godx.jp/pos", wantSet: true},
		{name: "http localhost", target: "http://localhost:6969/api/status", wantSet: true},
		{name: "http LOCALHOST uppercase", target: "http://LOCALHOST:6969/api/status", wantSet: true},
		{name: "http localhost no port", target: "http://localhost/api/status", wantSet: true},
		{name: "http 127.0.0.1", target: "http://127.0.0.1:6969/api/status", wantSet: true},
		{name: "http 127.9.9.9 loopback range", target: "http://127.9.9.9:6969/api/status", wantSet: true},
		{name: "http ipv6 loopback", target: "http://[::1]:6969/api/status", wantSet: true},
		{name: "http LAN ipv4", target: "http://192.168.3.11:6969/pos", wantSet: false},
		{name: "http LAN ipv4 no port", target: "http://10.0.0.5/pos", wantSet: false},
		{name: "http mdns hostname", target: "http://ws-app.local:6969/pos", wantSet: false},
		{name: "http tunnel host", target: "http://x.trycloudflare.com/pos", wantSet: false},
		{name: "empty Host header", target: "http://192.168.3.11/pos", host: "-", wantSet: false},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest("GET", tc.target, nil)
			if tc.host == "-" {
				req.Host = ""
			} else if tc.host != "" {
				req.Host = tc.host
			}
			rec := httptest.NewRecorder()
			corsMiddleware(dummyHandler).ServeHTTP(rec, req)

			got := rec.Header().Get("Cross-Origin-Opener-Policy")
			if tc.wantSet && got != "same-origin" {
				t.Errorf("COOP = %q, want \"same-origin\"", got)
			}
			if !tc.wantSet && got != "" {
				t.Errorf("COOP = %q, want empty", got)
			}
			// COOP is the ONLY conditional header — the rest of the baseline
			// must survive on every origin.
			for header, want := range map[string]string{
				"X-Content-Type-Options":  "nosniff",
				"X-Frame-Options":         "SAMEORIGIN",
				"Referrer-Policy":         "strict-origin-when-cross-origin",
				"Content-Security-Policy": "frame-ancestors 'self'",
			} {
				if got := rec.Header().Get(header); got != want {
					t.Errorf("%s = %q, want %q", header, got, want)
				}
			}
		})
	}
}

// The workstation webview loads http://localhost:<port> (cmd/workstation/main.go
// points the Wails window straight at the Go server), so these headers govern
// the app's own pages — including the hidden <iframe src="/vesca-bridge.html">
// that hosts the VescaJS SDK driving the Verifone P400.
//
// DENY / frame-ancestors 'none' blocked that iframe. Nothing logged an error the
// cashier could see: the iframe simply never loaded, never posted READY, and the
// poll then dequeued each card charge and threw it away — the P400 stayed dark
// while pos-web spun on "waiting for the card swipe". Clickjacking is a
// cross-origin attack, and 'self' still stops every bit of it.
func TestCorsMiddleware_AllowsSameOriginFramingForTheCardTerminalBridge(t *testing.T) {
	h := corsMiddleware(dummyHandler)
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, httptest.NewRequest("GET", "/vesca-bridge.html", nil))

	if got := rec.Header().Get("X-Frame-Options"); got == "DENY" {
		t.Error("X-Frame-Options: DENY blocks the app's own vesca-bridge iframe — card payments never reach the P400")
	}
	if csp := rec.Header().Get("Content-Security-Policy"); strings.Contains(csp, "frame-ancestors 'none'") {
		t.Errorf("frame-ancestors 'none' blocks the vesca-bridge iframe: %q", csp)
	}
}

// The SDK is instantiated with `new Worker(URL.createObjectURL(blob))`, and
// worker creation falls back to script-src when worker-src is absent. With
// script-src 'self' and no blob:, the constructor throws inside the iframe and
// the bridge never becomes READY — same dark terminal, different layer.
func TestFrontendCSPAllowsTheVescaBlobWorker(t *testing.T) {
	html := readSource(t, "../../frontend/index.html")

	if !strings.Contains(html, "worker-src 'self' blob:") {
		t.Error("frontend/index.html CSP has no worker-src with blob: — new Worker(blob:) throws and the P400 is never driven")
	}
	if !strings.Contains(html, "frame-src 'self'") {
		t.Error("frontend/index.html CSP has no explicit frame-src — the vesca-bridge iframe then depends on default-src surviving future edits")
	}
}

// TestCorsMiddleware_PreflightAllowsPatch pins PATCH into the
// Access-Control-Allow-Methods list. pos-web's Shift Close auto-save +
// manual Save Draft flows PATCH /api/v1/pos/till/sessions/{id}/draft;
// a regression here would fail the browser preflight and boot the
// cashier back to /pairing (a 401 handler on the client-side tearing
// down the session on a fetch TypeError).
func TestCorsMiddleware_PreflightAllowsPatch(t *testing.T) {
	h := corsMiddleware(dummyHandler)
	req := httptest.NewRequest(http.MethodOptions, "/api/v1/pos/till/sessions/x/draft", nil)
	req.Header.Set("Origin", "http://192.168.1.10:5440")
	req.Header.Set("Access-Control-Request-Method", "PATCH")
	req.RemoteAddr = "192.168.1.10:54321"

	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)

	methods := rec.Header().Get("Access-Control-Allow-Methods")
	for _, want := range []string{"GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"} {
		if !strings.Contains(methods, want) {
			t.Errorf("Access-Control-Allow-Methods = %q, missing %s", methods, want)
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
