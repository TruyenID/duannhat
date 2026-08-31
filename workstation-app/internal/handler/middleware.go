package handler

import (
	"net"
	"net/http"
	"net/url"
)

// lanOnly rejects requests from non-private IP addresses. Wraps the whole
// mux as defense in depth — the server binds 0.0.0.0:8080 to advertise via
// mDNS for LAN tablets, but any reachable public network must be refused
// before auth/CORS layers see the request.
func lanOnly(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		host, _, err := net.SplitHostPort(r.RemoteAddr)
		if err != nil {
			host = r.RemoteAddr
		}

		ip := net.ParseIP(host)
		if ip == nil || !isPrivateIP(ip) {
			http.Error(w, "Forbidden: LAN access only", http.StatusForbidden)
			return
		}

		next.ServeHTTP(w, r)
	})
}

// localOnly rejects any request not originating from the loopback interface.
// Used for admin endpoints (device unpair, audit dump, monitor snapshot)
// that the Wails frontend calls from http://localhost — LAN tablets must
// not be able to reach them even with a stolen device token.
func localOnly(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		host, _, err := net.SplitHostPort(r.RemoteAddr)
		if err != nil {
			host = r.RemoteAddr
		}

		ip := net.ParseIP(host)
		if ip == nil || !ip.IsLoopback() {
			http.Error(w, "Forbidden: loopback only", http.StatusForbidden)
			return
		}

		next.ServeHTTP(w, r)
	})
}

// isPrivateIP returns true for any IPv4 RFC1918 / IPv6 ULA (RFC 4193)
// range, plus loopback and link-local. Uses stdlib `ip.IsPrivate()`
// (Go 1.17+) which gets the IPv6 ULA range right — the previous hand-
// rolled table only covered IPv4 RFC1918 and IPv6 link-local, silently
// 403'ing kiosks on corp networks that hand out fc00::/7.
func isPrivateIP(ip net.IP) bool {
	return ip.IsLoopback() || ip.IsLinkLocalUnicast() || ip.IsPrivate()
}

// corsMiddleware adds CORS headers for LAN access + a baseline of security
// response headers that should be present on every response regardless of
// CORS state. Without these:
//   - X-Content-Type-Options: a JSON endpoint echoing attacker-controlled
//     content could be MIME-sniffed as HTML in Chrome.
//   - X-Frame-Options: a malicious LAN page could iframe the Wails-served
//     SPA + clickjack it.
//   - Referrer-Policy: outgoing fetches to fonts.googleapis or any other
//     external host would leak the workstation URL + path.
//   - Cross-Origin-Opener-Policy: a malicious popup keeps a handle to
//     window.opener — same-origin policy doesn't help inside our SPA.
func corsMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Security headers — unconditional, every response carries them.
		w.Header().Set("X-Content-Type-Options", "nosniff")
		w.Header().Set("X-Frame-Options", "DENY")
		w.Header().Set("Referrer-Policy", "strict-origin-when-cross-origin")
		w.Header().Set("Cross-Origin-Opener-Policy", "same-origin")

		origin := r.Header.Get("Origin")
		if origin != "" && isAllowedOrigin(origin) {
			w.Header().Set("Access-Control-Allow-Origin", origin)
			w.Header().Set("Vary", "Origin")
			w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
			// Idempotency-Key is sent by all gen-2 KDS + POS mutation
			// endpoints; missing from the allow-list previously stripped
			// the header silently and made dedup fail on browser clients.
			// X-Shop-Slug is sent by pos-web on every /api/v1/pos/* request so
			// the workstation (and Cloud, via the pos cloud proxy) can resolve
			// the active shop without putting the slug in the URL.
			w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization, Idempotency-Key, Accept-Language, X-Shop-Slug")
			w.Header().Set("Access-Control-Max-Age", "3600")
		}

		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}

		next.ServeHTTP(w, r)
	})
}

// isAllowedOrigin enforces the global LAN CORS policy. Only browsers on
// private/loopback hosts may have credentials echoed back. The previous
// `.local` mDNS suffix was dropped: any LAN attacker can advertise an
// arbitrary `.local` hostname via mDNS, so accepting the suffix as a
// trust signal was effectively wildcard. Tablets that use mDNS for
// discovery hit the workstation by IP after resolution, so dropping the
// suffix doesn't break the legitimate flow.
//
// Uses net/url to parse so IPv6 bracket forms (`https://[::1]:5440`) are
// handled — the old strings.Split(":") logic returned "[" and broke loopback.
func isAllowedOrigin(origin string) bool {
	u, err := url.Parse(origin)
	if err != nil {
		return false
	}
	host := u.Hostname()
	if host == "" {
		return false
	}
	if ip := net.ParseIP(host); ip != nil {
		return isPrivateIP(ip)
	}
	// Only loopback hostname form — NOT arbitrary mDNS .local hosts.
	if host == "localhost" {
		return true
	}
	// A tunnel/reverse proxy in front of the gateway serves pos-web / kds-web
	// from its own hostname. Opt-in only (WS_APP_TUNNEL_HOSTS); unset keeps the
	// credentialed-origin check as strict as before. See tunnel_hosts.go.
	return isTunnelHost(host)
}
