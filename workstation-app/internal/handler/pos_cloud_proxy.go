package handler

import (
	"io"
	"log/slog"
	"net/http"
	"net/http/httputil"
	"net/url"
)

// posCloudProxy reverse-proxies requests under /api/v1/pos/* up to the
// paired Cloud instance, preserving the caller's Bearer token and
// X-Shop-Slug header.
//
// Why this exists: pos-web (browser, SSO-authenticated) addresses a single
// namespace /api/v1/pos/* regardless of whether it is talking LAN-first
// to a workstation or directly to Cloud. The workstation owns local
// SQLite for a subset of POS surface (orders, menus, tables, payments).
// For the rest — payment-methods, customers, shop settings, refunds, etc.
// — the workstation has no local data yet, so the correct behavior is to
// transparently forward to Cloud. A dedicated `/api/v1/pos/` catch-all
// loses to specific local handlers via Go 1.22 ServeMux pattern precedence,
// so locally-served paths are unaffected.
//
// Auth: this proxy is mounted under the same posAuth wrapper as the local
// handlers (corsForBrowser + s.authed). s.authed verifies the bearer token
// against Cloud's /api/v1/kiosk/me (with cache); a request that reaches
// the proxy therefore already has a known-valid SSO token. The proxy then
// hands that same token to Cloud, where the /api/v1/pos/* ResolvePosShop
// middleware runs its own IAM check against the X-Shop-Slug header.
//
// Migration path: when a /pos/<resource> grows a local-first implementation,
// register a specific mux.Handle for it BEFORE this catch-all is reached;
// the proxy stops firing for that resource without any change here.
func (s *Server) posCloudProxy() http.Handler {
	return s.newCloudProxy(true)
}

// cloudProxy reverse-proxies to Cloud preserving the caller's Bearer token,
// WITHOUT injecting X-Shop-Slug. Used for the /api/v1/kiosk/* and
// /api/v1/customer/* namespaces: Cloud derives the branch from the kiosk
// device token (not the slug header), and forcing a pos-style slug onto a
// kiosk request is at best ignored, at worst a cross-namespace surprise.
//
// Why this exists at all: any kiosk/customer route the workstation does NOT
// serve locally must still return Cloud's JSON. Without a catch-all proxy the
// request falls through to the SPA static handler mounted at "/" and the
// client receives index.html — `response.json()` then throws
// "Unexpected character: <". Mounting this as a subtree (`/api/v1/kiosk/`)
// loses to the specific local handlers (kiosk/me, kiosk/orders, payments,
// customer/tables/{qrToken}) via Go 1.22 ServeMux precedence, so only the
// unimplemented routes (split-by-items/preview, customer/qr/{token},
// kiosk/audit-logs, …) reach Cloud.
func (s *Server) cloudProxy() http.Handler {
	return s.newCloudProxy(false)
}

// newCloudProxy builds the shared reverse proxy. setShopSlug pins the request
// to the paired branch via X-Shop-Slug — required for /pos/* (Cloud's
// ResolvePosShop middleware), undesirable elsewhere.
func (s *Server) newCloudProxy(setShopSlug bool) http.Handler {
	director := func(r *http.Request) {
		base := s.cloudAPIURL()
		if base == "" {
			return
		}
		target, err := url.Parse(base)
		if err != nil {
			return
		}
		r.URL.Scheme = target.Scheme
		r.URL.Host = target.Host
		r.Host = target.Host
		// r.URL.Path is preserved verbatim (e.g.
		// /api/v1/pos/customers/find-or-create) so Cloud sees the same
		// path the browser sent.

		// Strip any hop-by-hop / proxy-set headers the browser might have
		// sent that would confuse Cloud's middleware stack.
		r.Header.Del("Connection")
		r.Header.Del("X-Forwarded-Host")

		// Lock every proxied request to the workstation's paired branch.
		// Without this, pos-web at /shop/<other-slug> would receive that
		// other branch's data via the proxy while the local handlers
		// continue serving the paired branch — an inconsistent state that
		// silently shows the wrong shop's tables, menus, orders.
		//
		// Empty slug means we cannot resolve the paired branch (unpaired,
		// or `branches` table missing in tests) — leave the caller's
		// header alone so Cloud's ResolvePosShop returns a coherent 4xx.
		if setShopSlug {
			if pairedSlug := s.workstationBranchSlug(); pairedSlug != "" {
				r.Header.Set("X-Shop-Slug", pairedSlug)
			}
		}
	}
	return &httputil.ReverseProxy{
		Director: director,
		ModifyResponse: func(resp *http.Response) error {
			// CORS is owned by corsForBrowser (which wraps this proxy).
			// Drop any CORS headers Cloud may have set so the browser
			// doesn't see duplicates / contradictions.
			resp.Header.Del("Access-Control-Allow-Origin")
			resp.Header.Del("Access-Control-Allow-Credentials")
			resp.Header.Del("Access-Control-Allow-Methods")
			resp.Header.Del("Access-Control-Allow-Headers")
			return nil
		},
		ErrorHandler: func(w http.ResponseWriter, r *http.Request, err error) {
			slog.Warn("pos cloud proxy failed",
				"path", r.URL.Path,
				"method", r.Method,
				"err", err,
			)
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusBadGateway)
			_, _ = io.WriteString(w, `{"message":"Workstation could not reach the cloud API."}`)
		},
	}
}
