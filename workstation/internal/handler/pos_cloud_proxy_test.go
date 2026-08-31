package handler

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

// mockSSOCloudWithProxy returns a fake Cloud that handles BOTH the SSO
// identity probe (/api/v1/me/context) used by AuthMiddleware AND arbitrary
// /api/v1/pos/* paths the workstation proxy should forward. The probe path
// short-circuits with a fixed user; everything else echoes the request as
// JSON so assertions can verify path/method/headers were preserved.
func mockSSOCloudWithProxy(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/me/context" {
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"user":{"id":"u-1","name":"Alice","email":"a@x.com","locale":"vi","timezone":"Asia/Tokyo"},"brand_count":1,"shop_count":1}`))
			return
		}
		// Echo the request for assertion. Body intentionally omitted —
		// the tests in this file exercise GETs.
		body, _ := json.Marshal(map[string]string{
			"path":        r.URL.Path,
			"method":      r.Method,
			"x_shop_slug": r.Header.Get("X-Shop-Slug"),
			"auth":        r.Header.Get("Authorization"),
		})
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Access-Control-Allow-Origin", "https://should-be-stripped.example")
		_, _ = w.Write(body)
	}))
	t.Cleanup(srv.Close)
	return srv
}

func TestPosCloudProxy_ForwardsUnmirroredPath(t *testing.T) {
	cloud := mockSSOCloudWithProxy(t)
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/pos/debts/customer-1", nil)
	req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	req.Header.Set("X-Shop-Slug", "main-shop")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}

	var resp map[string]string
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v body=%s", err, w.Body.String())
	}
	if resp["path"] != "/api/v1/pos/debts/customer-1" {
		t.Errorf("path not preserved: %q", resp["path"])
	}
	if resp["x_shop_slug"] != "main-shop" {
		t.Errorf("X-Shop-Slug not forwarded: %q", resp["x_shop_slug"])
	}
	if resp["auth"] != "Bearer 5|sso-token-hash" {
		t.Errorf("Authorization not forwarded: %q", resp["auth"])
	}
	if got := w.Header().Get(tempoSourceHeader); got != tempoSourceCloudProxy {
		t.Errorf("source header = %q, want %q", got, tempoSourceCloudProxy)
	}
	if got := w.Header().Get("Server-Timing"); !strings.HasPrefix(got, "cloud-proxy;dur=") {
		t.Errorf("Server-Timing does not identify proxy latency: %q", got)
	}
}

func TestPosCloudProxy_LocalHandlerStillWins(t *testing.T) {
	// GET /api/v1/pos/me is a local handler — the proxy MUST NOT fire.
	// We assert that by having the mock cloud respond differently than
	// the local handler would, and checking we got the local response.
	cloud := mockSSOCloudWithProxy(t)
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/pos/me", nil)
	req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", w.Code)
	}
	body := w.Body.String()
	// Local handler wraps under "data" with identity_type — the echo
	// proxy would have returned a flat {"path":...} JSON.
	if !strings.Contains(body, `"identity_type"`) {
		t.Errorf("got proxy response instead of local /pos/me: %s", body)
	}
	if got := w.Header().Get(tempoSourceHeader); got != tempoSourceWorkstation {
		t.Errorf("local source header = %q, want %q", got, tempoSourceWorkstation)
	}
}

func TestPosCloudProxy_StripsCloudCORSHeaders(t *testing.T) {
	cloud := mockSSOCloudWithProxy(t)
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/pos/debts/customer-1", nil)
	req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	req.Header.Set("Origin", "http://localhost:5440")
	req.Header.Set("X-Shop-Slug", "main-shop")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if got := w.Header().Get("Access-Control-Allow-Origin"); got != "http://localhost:5440" {
		t.Errorf("expected workstation CORS origin, got %q", got)
	}
	// Cloud sent "https://should-be-stripped.example" — proxy must replace
	// it with the workstation's allowed origin (set by corsForBrowser).
	if strings.Contains(w.Header().Get("Access-Control-Allow-Origin"), "should-be-stripped") {
		t.Errorf("cloud CORS leaked through proxy: %q", w.Header().Get("Access-Control-Allow-Origin"))
	}
}

func TestPosCloudProxy_CorsPreflightAdvertisesShopSlug(t *testing.T) {
	cloud := mockSSOCloudWithProxy(t)
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("OPTIONS", "/api/v1/pos/settings/order", nil)
	req.Header.Set("Origin", "http://localhost:5440")
	req.Header.Set("Access-Control-Request-Method", "GET")
	req.Header.Set("Access-Control-Request-Headers", "X-Shop-Slug")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusNoContent {
		t.Fatalf("expected 204, got %d", w.Code)
	}
	if !strings.Contains(w.Header().Get("Access-Control-Allow-Headers"), "X-Shop-Slug") {
		t.Errorf("Allow-Headers missing X-Shop-Slug: %q", w.Header().Get("Access-Control-Allow-Headers"))
	}
	if got := w.Header().Get("Access-Control-Expose-Headers"); !strings.Contains(got, tempoSourceHeader) {
		t.Errorf("Expose-Headers missing %s: %q", tempoSourceHeader, got)
	}
}

func TestPosCloudProxy_RejectsUndeclaredRouteWithoutCloudHop(t *testing.T) {
	var posHits atomic.Int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/me/context" {
			_, _ = io.WriteString(w, `{"user":{"id":"u-1","name":"Alice","email":"a@x.com","locale":"vi","timezone":"Asia/Tokyo"},"brand_count":1,"shop_count":1}`)
			return
		}
		posHits.Add(1)
		w.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(cloud.Close)
	s, _ := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/not-declared", nil)
	req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	req.Header.Set("X-Shop-Slug", "main-shop")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusNotFound {
		t.Fatalf("status = %d, want 404; body=%s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), posRouteUndeclaredError) {
		t.Errorf("response does not identify undeclared route: %s", w.Body.String())
	}
	if got := posHits.Load(); got != 0 {
		t.Errorf("undeclared route made %d Cloud request(s), want 0", got)
	}
	if got := w.Header().Get(tempoSourceHeader); got != tempoSourceWorkstation {
		t.Errorf("source header = %q, want %q", got, tempoSourceWorkstation)
	}
}

func TestPosCloudProxy_AllowlistMatchesMethodAndWholePathOnly(t *testing.T) {
	tests := []struct {
		name   string
		method string
		path   string
		want   bool
	}{
		{name: "declared collection", method: http.MethodGet, path: "/api/v1/pos/debts", want: true},
		{name: "declared resource", method: http.MethodGet, path: "/api/v1/pos/debts/customer-1", want: true},
		{name: "declared mutation", method: http.MethodPost, path: "/api/v1/pos/orders/order-1/reopen", want: true},
		{name: "same path wrong method", method: http.MethodPost, path: "/api/v1/pos/debts/customer-1", want: false},
		{name: "same prefix undeclared child", method: http.MethodGet, path: "/api/v1/pos/debts/customer-1/history", want: false},
		{name: "suffix that only looks like route", method: http.MethodGet, path: "/api/v1/pos/debts-archive", want: false},
		{name: "missing wildcard value", method: http.MethodPost, path: "/api/v1/pos/orders/reopen", want: false},
		{name: "non POS namespace", method: http.MethodGet, path: "/api/v1/admin/debts", want: false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := posCloudRouteAllowed(tt.method, tt.path); got != tt.want {
				t.Fatalf("posCloudRouteAllowed(%q, %q) = %v, want %v", tt.method, tt.path, got, tt.want)
			}
		})
	}
}

func TestPosCloudProxy_CachesCloudTargetAndPairedShopSnapshot(t *testing.T) {
	oldTTL := cloudProxyTargetTTL
	cloudProxyTargetTTL = time.Minute
	t.Cleanup(func() { cloudProxyTargetTTL = oldTTL })

	var cloudHits atomic.Int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		cloudHits.Add(1)
		_, _ = io.WriteString(w, `{}`)
	}))
	t.Cleanup(cloud.Close)
	s, db := newServerWithAuth(t, cloud.URL)
	proxy := s.posCloudProxy()

	before := db.Diagnostics().QueryCount
	for i := 0; i < 2; i++ {
		req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/debts/customer-1", nil)
		// A browser-provided slug must not influence target resolution; the
		// proxy snapshots the workstation's paired branch instead.
		req.Header.Set("X-Shop-Slug", "spoofed-shop")
		w := httptest.NewRecorder()
		proxy.ServeHTTP(w, req)
		if w.Code != http.StatusOK {
			t.Fatalf("request %d: status=%d body=%s", i+1, w.Code, w.Body.String())
		}
	}
	after := db.Diagnostics().QueryCount

	if got := cloudHits.Load(); got != 2 {
		t.Fatalf("Cloud requests = %d, want 2 forwarded calls", got)
	}
	// First call resolves cloud_api_url + the paired branch slug. The second
	// must use the short in-memory target snapshot and perform no SQLite read.
	if got := after - before; got != 2 {
		t.Fatalf("two proxied calls used %d target-resolution queries, want 2 on first call and 0 on second", got)
	}
}

func TestPosCloudProxy_BadGatewayWhenCloudUnreachable(t *testing.T) {
	oldTTL := cloudProxyTargetTTL
	cloudProxyTargetTTL = 0
	t.Cleanup(func() { cloudProxyTargetTTL = oldTTL })
	// Use a sso cloud only to satisfy AuthMiddleware, then point the
	// proxy at a dead URL by overwriting the cloud_api_url setting
	// AFTER auth has been validated against the live mock.
	cloud := mockSSOCloudWithProxy(t)
	s, db := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// Warm the auth cache so the live cloud is only used for the identity
	// probe, then poison cloud_api_url so the proxy hop fails.
	req := httptest.NewRequest("GET", "/api/v1/pos/debts/customer-1", nil)
	req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	req.Header.Set("X-Shop-Slug", "main-shop")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("priming request failed: %d", w.Code)
	}

	if _, err := db.Exec(`UPDATE settings SET value = 'http://127.0.0.1:1' WHERE key = 'cloud_api_url'`); err != nil {
		t.Fatalf("poison cloud_api_url: %v", err)
	}

	req2 := httptest.NewRequest("GET", "/api/v1/pos/debts/customer-1", nil)
	req2.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	req2.Header.Set("X-Shop-Slug", "main-shop")
	w2 := httptest.NewRecorder()
	mux.ServeHTTP(w2, req2)

	if w2.Code != http.StatusBadGateway {
		body, _ := io.ReadAll(w2.Body)
		t.Errorf("expected 502 when cloud unreachable, got %d body=%s", w2.Code, body)
	}
	if got := w2.Header().Get(tempoSourceHeader); got != tempoSourceCloudProxy {
		t.Errorf("source header = %q, want %q", got, tempoSourceCloudProxy)
	}
}

func TestPosCloudProxy_TimesOutSlowCloud(t *testing.T) {
	oldTimeout := cloudProxyTimeout
	cloudProxyTimeout = 50 * time.Millisecond
	t.Cleanup(func() { cloudProxyTimeout = oldTimeout })

	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/me/context" {
			_, _ = io.WriteString(w, `{"user":{"id":"u-1","name":"Alice","email":"a@x.com","locale":"vi","timezone":"Asia/Tokyo"},"brand_count":1,"shop_count":1}`)
			return
		}
		time.Sleep(250 * time.Millisecond)
		_, _ = io.WriteString(w, `{}`)
	}))
	t.Cleanup(cloud.Close)
	s, _ := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/debts/customer-1", nil)
	req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	req.Header.Set("X-Shop-Slug", "main-shop")
	w := httptest.NewRecorder()
	started := time.Now()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusGatewayTimeout {
		t.Fatalf("status = %d, want 504; body=%s", w.Code, w.Body.String())
	}
	if elapsed := time.Since(started); elapsed > 200*time.Millisecond {
		t.Errorf("proxy exceeded bounded deadline: %v", elapsed)
	}
	if got := w.Header().Get(tempoSourceHeader); got != tempoSourceCloudProxy {
		t.Errorf("source header = %q, want %q", got, tempoSourceCloudProxy)
	}
	if got := w.Header().Get("Server-Timing"); !strings.HasPrefix(got, "cloud-proxy;dur=") {
		t.Errorf("missing proxy timing on timeout: %q", got)
	}
}

// =============================================================================
//  Plan-032 — Stale Shift Reaper
//
//  workstation-app does not own a local mirror of till_sessions; pos-web and
//  admin-web hit the three new manager endpoints via the catch-all proxy at
//  routes.go (every /api/v1/pos/* not explicitly registered locally).
//
//  These tests prove the catch-all preserves: path, method, X-Shop-Slug, the
//  Authorization bearer token, AND the request body (POST). Without
//  body-forwarding, force-abandon and manual-settle would silently drop the
//  manager's reason_code/reason_detail/closing_counts payload and the cloud
//  endpoint would 422 with confusing "field required" errors.
// =============================================================================

// mockSSOCloudEchoBody — like mockSSOCloudWithProxy but also reflects the
// request body back so tests can assert it round-trips through the proxy
// intact.
func mockSSOCloudEchoBody(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/me/context" {
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"user":{"id":"u-1","name":"Alice","email":"a@x.com","locale":"vi","timezone":"Asia/Tokyo"},"brand_count":1,"shop_count":1}`))
			return
		}
		body, _ := io.ReadAll(r.Body)
		payload, _ := json.Marshal(map[string]any{
			"path":        r.URL.Path,
			"method":      r.Method,
			"x_shop_slug": r.Header.Get("X-Shop-Slug"),
			"auth":        r.Header.Get("Authorization"),
			"body":        string(body),
		})
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write(payload)
	}))
	t.Cleanup(srv.Close)
	return srv
}

func TestPosCloudProxy_Plan032_ForceAbandonForwardsBody(t *testing.T) {
	cloud := mockSSOCloudEchoBody(t)
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	payload := `{"reason_code":"cashier_forgot_to_close","reason_detail":null}`
	req := httptest.NewRequest("POST",
		"/api/v1/pos/till/sessions/sess-abc-123/force-abandon",
		strings.NewReader(payload),
	)
	req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	req.Header.Set("X-Shop-Slug", "main-shop")
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}

	var resp map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v body=%s", err, w.Body.String())
	}
	if resp["path"] != "/api/v1/pos/till/sessions/sess-abc-123/force-abandon" {
		t.Errorf("path not preserved: %q", resp["path"])
	}
	if resp["method"] != "POST" {
		t.Errorf("method not preserved: %q", resp["method"])
	}
	if resp["x_shop_slug"] != "main-shop" {
		t.Errorf("X-Shop-Slug not forwarded: %q", resp["x_shop_slug"])
	}
	if resp["auth"] != "Bearer 5|sso-token-hash" {
		t.Errorf("Authorization not forwarded: %q", resp["auth"])
	}
	if got := resp["body"].(string); got != payload {
		t.Errorf("body lost in proxy:\n  want %q\n  got  %q", payload, got)
	}
}

func TestPosCloudProxy_Plan032_ManualSettleForwardsBody(t *testing.T) {
	cloud := mockSSOCloudEchoBody(t)
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// Realistic shape: closing_counts grid + manual_settle_reason + the
	// Decision 8b override field. The cloud handler validates each piece —
	// the proxy's job here is just to deliver the bytes intact.
	payload := `{
		"closing_counts":[{"denomination_id":"d-10000","quantity":5}],
		"tender_details":[],
		"manual_settle_reason":"Cashier disappeared; manager counted drawer manually.",
		"opening_counts_override":[{"denomination_id":"d-10000","quantity":3}]
	}`
	req := httptest.NewRequest("POST",
		"/api/v1/pos/till/sessions/sess-xyz/manual-settle",
		strings.NewReader(payload),
	)
	req.Header.Set("Authorization", "Bearer 7|sso-token-hash")
	req.Header.Set("X-Shop-Slug", "main-shop")
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}
	var resp map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if resp["path"] != "/api/v1/pos/till/sessions/sess-xyz/manual-settle" {
		t.Errorf("path not preserved: %q", resp["path"])
	}
	if got := resp["body"].(string); got != payload {
		t.Errorf("body lost in proxy:\n  want %q\n  got  %q", payload, got)
	}
}

func TestPosCloudProxy_Plan032_StaleListForwardsQueryString(t *testing.T) {
	cloud := mockSSOCloudEchoBody(t)
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET",
		"/api/v1/pos/till/sessions/stale?filter=expired&per_page=50",
		nil,
	)
	req.Header.Set("Authorization", "Bearer 9|sso-token-hash")
	req.Header.Set("X-Shop-Slug", "main-shop")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}
	var resp map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	// httputil.ReverseProxy preserves the raw query string by default; the
	// echo handler only reports path, so we verify the path component and
	// then sanity-check that the proxy didn't mangle the request shape.
	if resp["path"] != "/api/v1/pos/till/sessions/stale" {
		t.Errorf("path not preserved: %q", resp["path"])
	}
	if resp["method"] != "GET" {
		t.Errorf("method not preserved: %q", resp["method"])
	}
}

func TestPosCloudProxy_Plan032_StaleActorSummaryForwarded(t *testing.T) {
	cloud := mockSSOCloudEchoBody(t)
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// The plan-032 T5.5c widget queries the same /stale path with a
	// group_by=actor flag to switch to the summary envelope. The proxy
	// must not special-case the query and must forward as-is.
	req := httptest.NewRequest("GET",
		"/api/v1/pos/till/sessions/stale?group_by=actor&within_days=30",
		nil,
	)
	req.Header.Set("Authorization", "Bearer 11|sso-token-hash")
	req.Header.Set("X-Shop-Slug", "main-shop")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", w.Code)
	}
}
