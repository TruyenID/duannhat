package handler

// cloud_proxy.go — reverse proxies for /api/v1/pos/* endpoints that the
// workstation does not natively own (no local SQLite mirror). The
// workstation simply forwards the request to the cloud REST API and
// streams the response back.
//
// Plan 030 — first consumer is GET /api/v1/pos/staff, used by pos-web
// to populate the "Người mở ca" dropdown on the Open Shift form.
//
// Auth model: the request from pos-web carries an SSO bearer token in
// `Authorization` and a shop identifier in `X-Shop-Slug`. Both headers
// are forwarded verbatim — the workstation does NOT swap in its
// device_token here. The cloud authenticates the cashier directly so
// audit logs identify the actual operator, not "the workstation".
//
// Query strings are preserved. Request bodies (when proxying POST/PATCH
// in future) are streamed through. 4xx/5xx responses from cloud are
// surfaced as-is so pos-web's ApiError handling stays consistent.

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"time"
)

// cloudBaseURL returns the upstream URL configured for this workstation.
//
// Delegates to cloudAPIURL() — settings row first, then config.json /
// WS_APP_CLOUD_URL. It used to carry its own config-FIRST ladder, the exact
// inverse of every other caller, which is how a workstation ended up paired
// against one host while proxied requests went to another after an operator
// repointed Cloud in Settings (#2431). There is ONE ladder; do not grow a
// second one here.
//
// Empty string means the workstation isn't pointed at a cloud yet — proxy
// paths should 503 rather than 502 in that case.
func (s *Server) cloudBaseURL() string {
	return s.cloudAPIURL()
}

// proxyToCloud forwards method+path (relative to the cloud root) to the
// upstream and writes the response. It is intentionally NOT a generic
// catch-all — each public endpoint is wired explicitly so the surface
// area is reviewable. The request body is streamed through verbatim.
func (s *Server) proxyToCloud(w http.ResponseWriter, r *http.Request, method, path string) {
	var body io.Reader
	if r.Body != nil && method != http.MethodGet && method != http.MethodHead {
		body = r.Body
	}
	s.cloudProxyCore(w, r, method, path, body)
}

// proxyToCloudBody is proxyToCloud with a caller-supplied body — used when the
// workstation has to REWRITE the request before forwarding it (e.g. translating
// a local order/payment id in the body to its cloud id). A *bytes.Reader lets
// net/http set Content-Length, so Cloud sees a normal sized POST, not a chunked
// stream.
func (s *Server) proxyToCloudBody(w http.ResponseWriter, r *http.Request, method, path string, body []byte) {
	var reader io.Reader
	if len(body) > 0 && method != http.MethodGet && method != http.MethodHead {
		reader = bytes.NewReader(body)
	}
	s.cloudProxyCore(w, r, method, path, reader)
}

// cloudProxyCore is the shared forward-and-mirror body for proxyToCloud /
// proxyToCloudBody. Behaviour is identical to the original proxyToCloud; only
// the body source differs.
func (s *Server) cloudProxyCore(w http.ResponseWriter, r *http.Request, method, path string, body io.Reader) {
	base := s.cloudBaseURL()
	if base == "" {
		writeError(w, http.StatusServiceUnavailable, "cloud_api_url not configured on this workstation")
		return
	}

	// Append the original query string so filters/pagination keep working.
	upstream := base + path
	if r.URL.RawQuery != "" {
		upstream = upstream + "?" + r.URL.RawQuery
	}

	// 12s gives the cloud enough time on slow links while still failing
	// fast enough that pos-web's network-error fallback kicks in.
	ctx, cancel := context.WithTimeout(r.Context(), 12*time.Second)
	defer cancel()

	req, err := http.NewRequestWithContext(ctx, method, upstream, body)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	// Forward auth + shop context untouched. Content-Type and Accept
	// follow so JSON-vs-form etc. are honored.
	for _, h := range []string{
		"Authorization",
		"X-Shop-Slug",
		"Accept",
		"Accept-Language",
		"Content-Type",
		"X-App-Locale",
	} {
		if v := r.Header.Get(h); v != "" {
			req.Header.Set(h, v)
		}
	}

	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		if errors.Is(err, context.DeadlineExceeded) {
			writeError(w, http.StatusGatewayTimeout, fmt.Sprintf("cloud API timeout: %v", err))
			return
		}
		writeError(w, http.StatusBadGateway, fmt.Sprintf("cloud API unreachable: %v", err))
		return
	}
	defer resp.Body.Close()

	// Mirror content-type + status. Cap body at 4 MiB defensively — the
	// staff list and tender catalogs are kilobytes, anything more is
	// likely an error page that we don't need to stream verbatim.
	if ct := resp.Header.Get("Content-Type"); ct != "" {
		w.Header().Set("Content-Type", ct)
	}
	w.WriteHeader(resp.StatusCode)
	io.Copy(w, io.LimitReader(resp.Body, 4<<20))
}

// handlePosStaff — GET /api/v1/pos/staff. LAN-served read from the local
// `staff` replica synced DOWN via PullStaff. Open Shift form's "Người
// mở ca" dropdown reads this; without LAN coverage, a flaky uplink
// would block cashiers from even opening their shift.
//
// Shape matches Cloud's PosStaffController: { data: [{id, name, email,
// avatar_url}] }. email + avatar_url are NULL in the replica today —
// the dropdown only renders the name, so the placeholders keep the
// JSON contract stable.
func (s *Server) handlePosStaff(w http.ResponseWriter, r *http.Request) {
	rows, err := s.db.Query(`
		SELECT id, full_name FROM staff
		WHERE is_active = 1
		ORDER BY full_name`)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id, name string
		if err := rows.Scan(&id, &name); err != nil {
			writeServerError(w, r, err)
			return
		}
		out = append(out, map[string]any{
			"id":         id,
			"name":       name,
			"email":      nil,
			"avatar_url": nil,
		})
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": out})
}

// proxyPosPassThrough forwards the current request to the same path on
// cloud, preserving method + body + auth headers. Used by every plan-030
// cashier-shift endpoint so pos-web can run the workflow over LAN.
//
// Pattern (Go 1.22 servemux) lets the handler register the path with
// {param} placeholders for routing; r.URL.Path here is the fully-resolved
// concrete URL (e.g. /api/v1/pos/till/sessions/abc-123/close) which we
// forward to cloud unchanged.
func (s *Server) proxyPosPassThrough(w http.ResponseWriter, r *http.Request) {
	s.proxyToCloud(w, r, r.Method, r.URL.Path)
}
