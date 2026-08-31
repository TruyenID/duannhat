package handler

import (
	"net/http"
	"net/url"
	"strings"
)

// allowedOrigins lists origins permitted to call workstation's POS endpoints.
// Browser fetch from pos-web triggers a preflight OPTIONS — without CORS
// headers the browser blocks the response. Production deploy will inject
// the HQ-hosted pos-web origin via env var.
var allowedOrigins = map[string]bool{
	"http://localhost:5440": true, // pos-web Vite dev server
	"http://localhost:5430": true, // admin-web Next.js dev server (for debugging)
	"http://localhost:5460": true, // kds-web Vite dev server
	"http://127.0.0.1:5440": true,
	"http://127.0.0.1:5430": true,
	"http://127.0.0.1:5460": true,
}

// corsForBrowser sets CORS response headers and short-circuits preflight
// OPTIONS requests. Apply to LAN endpoints that browsers will hit directly
// (i.e. /api/v1/pos/* — kiosk/tms are native apps and don't need CORS).
func corsForBrowser(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		origin := r.Header.Get("Origin")
		if origin != "" && originAllowed(origin) {
			w.Header().Set("Access-Control-Allow-Origin", origin)
			w.Header().Set("Vary", "Origin")
			w.Header().Set("Access-Control-Allow-Credentials", "true")
			w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
			w.Header().Set("Access-Control-Allow-Headers",
				"Authorization, Content-Type, Idempotency-Key, Accept-Language, X-Shop-Slug")
			w.Header().Set("Access-Control-Expose-Headers", "X-Tempo-Source, Server-Timing")
			w.Header().Set("Access-Control-Max-Age", "600")
		}

		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		next.ServeHTTP(w, r)
	})
}

// originAllowed accepts exact matches from allowedOrigins plus any *.godx.jp
// subdomain over HTTPS. Parses the origin via net/url so the hostname is
// extracted correctly even for IPv6 bracket forms like `https://[::1]:5440`
// (the previous string.Split logic broke on the inner colons). Anchors the
// suffix on the leading dot so `evilgodx.jp` doesn't match.
func originAllowed(origin string) bool {
	if allowedOrigins[origin] {
		return true
	}
	u, err := url.Parse(origin)
	if err != nil || u.Scheme != "https" {
		return false
	}
	// url.Hostname() strips port and brackets — handles `[::1]` and
	// `pos.godx.jp:443` uniformly.
	return strings.HasSuffix(u.Hostname(), ".godx.jp") || isTunnelHost(u.Hostname())
}
