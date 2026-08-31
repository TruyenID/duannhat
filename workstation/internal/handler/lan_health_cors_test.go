package handler

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

// pos-web probes /api/lan/health with a plain cross-origin fetch (Vite dev
// server on :5440 → workstation on :8080). The route used to be registered
// bare, so the response carried no Access-Control-Allow-Origin and the browser
// discarded it — probeWorkstation() fell into its catch and reported the
// workstation permanently unreachable even while it was serving fine.
func newHealthMux(t *testing.T) *http.ServeMux {
	t.Helper()
	cloud := mockSSOCloudWithProxy(t)
	s, _ := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)
	return mux
}

func TestLANHealth_AllowedOriginGetsCORSHeaders(t *testing.T) {
	mux := newHealthMux(t)

	req := httptest.NewRequest("GET", "/api/lan/health", nil)
	req.Header.Set("Origin", "http://localhost:5440")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", w.Code)
	}
	if got := w.Header().Get("Access-Control-Allow-Origin"); got != "http://localhost:5440" {
		t.Errorf("Access-Control-Allow-Origin = %q, want the pos-web origin echoed back", got)
	}
	if got := w.Header().Get("Vary"); got != "Origin" {
		t.Errorf("Vary = %q, want Origin", got)
	}
}

// The health route must not become a blanket-open endpoint: an origin outside
// the allow-list still gets a 200 body (it is public) but no CORS grant, so a
// browser on an attacker page cannot read it.
func TestLANHealth_DisallowedOriginGetsNoCORSGrant(t *testing.T) {
	mux := newHealthMux(t)

	req := httptest.NewRequest("GET", "/api/lan/health", nil)
	req.Header.Set("Origin", "https://evil.example.com")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if got := w.Header().Get("Access-Control-Allow-Origin"); got != "" {
		t.Errorf("Access-Control-Allow-Origin = %q, want empty for a disallowed origin", got)
	}
}

func TestLANHealth_PreflightShortCircuits(t *testing.T) {
	mux := newHealthMux(t)

	req := httptest.NewRequest("OPTIONS", "/api/lan/health", nil)
	req.Header.Set("Origin", "http://localhost:5440")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusNoContent {
		t.Fatalf("status = %d, want 204", w.Code)
	}
	if got := w.Header().Get("Access-Control-Allow-Origin"); got != "http://localhost:5440" {
		t.Errorf("Access-Control-Allow-Origin = %q, want the pos-web origin echoed back", got)
	}
}
