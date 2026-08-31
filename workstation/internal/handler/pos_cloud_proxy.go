package handler

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"strings"
	"sync"
	"time"
)

const (
	tempoSourceHeader       = "X-Tempo-Source"
	tempoSourceWorkstation  = "workstation"
	tempoSourceCloudProxy   = "cloud-proxy"
	posRouteUndeclaredError = "POS_ROUTE_UNDECLARED"
)

var (
	cloudProxyTimeout   = 1750 * time.Millisecond
	cloudProxyTargetTTL = 2 * time.Second
)

// cloudOnlyPOSRoutes is the explicit boundary between the LAN replica and
// Cloud. A new POS call must be added here (and to the manifest) deliberately;
// the namespace catch-all must never silently turn a missing local handler into
// a slow Cloud request.
var cloudOnlyPOSRoutes = []struct {
	method  string
	pattern string
}{
	{http.MethodGet, "/api/v1/pos/debts"},
	{http.MethodGet, "/api/v1/pos/debts/{customer}"},
	{http.MethodGet, "/api/v1/pos/debts/part-paid"},
	{http.MethodPost, "/api/v1/pos/orders/{id}/reopen"},
	{http.MethodPatch, "/api/v1/pos/settings/order"},
	{http.MethodGet, "/api/v1/pos/till/chains/{chain}/summary"},
	{http.MethodGet, "/api/v1/pos/till/payment-terminals"},

	// Manager-only shift recovery calls are not emitted by pos-web's API
	// manifest yet, but are intentionally relayed by workstation admin flows.
	{http.MethodGet, "/api/v1/pos/till/sessions/stale"},
	{http.MethodPost, "/api/v1/pos/till/sessions/{id}/force-abandon"},
	{http.MethodPost, "/api/v1/pos/till/sessions/{id}/manual-settle"},
}

// posCloudProxy only relays the small, reviewed set of POS operations that
// still require Cloud authority. Everything else fails locally so accidental
// fallback cannot disguise a missing LAN implementation as "the LAN is slow".
func (s *Server) posCloudProxy() http.Handler {
	proxy := s.newCloudProxy(s.cloudAPIURL, true)
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !posCloudRouteAllowed(r.Method, r.URL.Path) {
			w.Header().Set(tempoSourceHeader, tempoSourceWorkstation)
			writeJSON(w, http.StatusNotFound, map[string]string{
				"message": "POS route is not declared for Cloud fallback.",
				"code":    posRouteUndeclaredError,
			})
			return
		}
		proxy.ServeHTTP(w, r)
	})
}

func posCloudRouteAllowed(method, path string) bool {
	for _, route := range cloudOnlyPOSRoutes {
		if route.method == method && routePatternMatches(route.pattern, path) {
			return true
		}
	}
	return false
}

func routePatternMatches(pattern, path string) bool {
	patternSegs := strings.Split(strings.Trim(pattern, "/"), "/")
	pathSegs := strings.Split(strings.Trim(path, "/"), "/")
	if len(patternSegs) != len(pathSegs) {
		return false
	}
	for i, segment := range patternSegs {
		if strings.HasPrefix(segment, "{") && strings.HasSuffix(segment, "}") {
			continue
		}
		if segment != pathSegs[i] {
			return false
		}
	}
	return true
}

// cloudProxy relays non-POS namespaces that have their own Cloud contracts.
func (s *Server) cloudProxy() http.Handler {
	return s.newCloudProxy(s.cloudAPIURL, false)
}

type cachedProxyTarget struct {
	base      *url.URL
	shopSlug  string
	refreshed time.Time
}

// newCloudProxy builds the shared reverse proxy with a strict end-to-end
// deadline and a short target snapshot. The snapshot removes two SQLite reads
// (Cloud URL + paired branch slug) from every proxied request while still
// allowing a settings change to take effect within a couple of seconds.
func (s *Server) newCloudProxy(baseURL func() string, setShopSlug bool) http.Handler {
	var targetMu sync.Mutex
	var cached cachedProxyTarget

	resolveTarget := func() (*url.URL, string) {
		targetMu.Lock()
		defer targetMu.Unlock()

		now := time.Now()
		if cached.base != nil && cloudProxyTargetTTL > 0 && now.Sub(cached.refreshed) < cloudProxyTargetTTL {
			return cached.base, cached.shopSlug
		}

		base, err := url.Parse(baseURL())
		if err != nil || base.Scheme == "" || base.Host == "" {
			cached = cachedProxyTarget{refreshed: now}
			return nil, ""
		}
		slug := ""
		if setShopSlug {
			slug = s.workstationBranchSlug()
		}
		cached = cachedProxyTarget{base: base, shopSlug: slug, refreshed: now}
		return base, slug
	}

	director := func(r *http.Request) {
		target, pairedSlug := resolveTarget()
		if target == nil {
			// Leave the request without a scheme so Transport returns a
			// controlled proxy error handled below.
			r.URL.Scheme = ""
			r.URL.Host = ""
			return
		}
		r.URL.Scheme = target.Scheme
		r.URL.Host = target.Host
		r.Host = target.Host
		r.Header.Del("Connection")
		r.Header.Del("X-Forwarded-Host")
		if setShopSlug && pairedSlug != "" {
			r.Header.Set("X-Shop-Slug", pairedSlug)
		}
	}

	proxy := &httputil.ReverseProxy{
		Director: director,
		ModifyResponse: func(resp *http.Response) error {
			resp.Header.Del("Access-Control-Allow-Origin")
			resp.Header.Del("Access-Control-Allow-Credentials")
			resp.Header.Del("Access-Control-Allow-Methods")
			resp.Header.Del("Access-Control-Allow-Headers")
			markCloudProxyResponse(resp.Header, resp.Request)
			return nil
		},
		ErrorHandler: func(w http.ResponseWriter, r *http.Request, err error) {
			status := http.StatusBadGateway
			var netErr net.Error
			if errors.Is(err, context.DeadlineExceeded) || (errors.As(err, &netErr) && netErr.Timeout()) {
				status = http.StatusGatewayTimeout
			}
			slog.Warn("cloud proxy failed",
				"path", r.URL.Path,
				"method", r.Method,
				"status", status,
				"err", err,
			)
			markCloudProxyResponse(w.Header(), r)
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(status)
			_, _ = io.WriteString(w, `{"message":"Workstation could not reach the cloud API."}`)
		},
	}

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		ctx, cancel := context.WithTimeout(
			context.WithValue(r.Context(), cloudProxyStartedKey{}, time.Now()),
			cloudProxyTimeout,
		)
		defer cancel()
		proxy.ServeHTTP(w, r.WithContext(ctx))
	})
}

type cloudProxyStartedKey struct{}

func markCloudProxyResponse(header http.Header, r *http.Request) {
	header.Set(tempoSourceHeader, tempoSourceCloudProxy)
	if started, ok := r.Context().Value(cloudProxyStartedKey{}).(time.Time); ok {
		header.Set("Server-Timing", fmt.Sprintf("cloud-proxy;dur=%.1f", float64(time.Since(started).Microseconds())/1000))
	}
}

func withWorkstationSource(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set(tempoSourceHeader, tempoSourceWorkstation)
		next.ServeHTTP(w, r)
	})
}
