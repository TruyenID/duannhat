package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"testing/fstest"
)

func spaTestAssets() fstest.MapFS {
	return fstest.MapFS{
		"index.html":    &fstest.MapFile{Data: []byte(`<!doctype html><html><body>ws-app</body></html>`)},
		"assets/app.js": &fstest.MapFile{Data: []byte("console.log(1)")},
		"wails.png":     &fstest.MapFile{Data: []byte("\x89PNG")},
	}
}

// spaHandler is mounted at "/" — the catch-all — so an unconditional
// index.html fallback answered mistyped and not-yet-implemented API paths with
// 200 + HTML (#1746). The caller saw 2xx and only died later, parsing HTML as
// JSON. API namespaces must 404 in JSON instead.
func TestSPAHandler_ApiPathsNeverFallBackToHTML(t *testing.T) {
	h := newSPAHandler(spaTestAssets())

	for _, p := range []string{
		"/api/lan/print/khong-ton-tai",
		"/api/lan/print/kitchen-tickettypo",
		"/api/v1/kds/khong-ton-tai",
		"/api/khong-ton-tai-gi-ca",
		"/api",
		"/ws/khong-ton-tai",
	} {
		for _, method := range []string{"GET", "POST"} {
			w := httptest.NewRecorder()
			h.ServeHTTP(w, httptest.NewRequest(method, p, nil))
			if w.Code != http.StatusNotFound {
				t.Errorf("%s %s: status = %d, want 404 (body %q)", method, p, w.Code, w.Body.String())
			}
			if ct := w.Header().Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
				t.Errorf("%s %s: Content-Type = %q, want application/json", method, p, ct)
			}
			if strings.Contains(w.Body.String(), "<html") {
				t.Errorf("%s %s: served HTML to an API path: %s", method, p, w.Body.String())
			}
		}
	}
}

// Same rule as the /pos mount (#1735): a path that looks like a file 404s when
// it is missing, so a webview holding a stale index.html after an app update
// gets an honest 404 instead of HTML-where-JavaScript-was-expected.
func TestSPAHandler_MissingAssetIs404(t *testing.T) {
	h := newSPAHandler(spaTestAssets())

	for _, p := range []string{"/assets/app-OLDHASH.js", "/assets/style.css", "/missing.png"} {
		w := httptest.NewRecorder()
		h.ServeHTTP(w, httptest.NewRequest("GET", p, nil))
		if w.Code != http.StatusNotFound {
			t.Errorf("%s: status = %d, want 404 (body %q)", p, w.Code, w.Body.String())
		}
	}
}

// The SPA fallback itself must survive — it is what makes a reload on a client
// route work — and real files must still be served byte-for-byte.
func TestSPAHandler_ClientRoutesAndRealFilesUnchanged(t *testing.T) {
	h := newSPAHandler(spaTestAssets())

	for _, p := range []string{"/", "/settings", "/orders/abc123", "/printers/setup"} {
		w := httptest.NewRecorder()
		h.ServeHTTP(w, httptest.NewRequest("GET", p, nil))
		if w.Code != http.StatusOK || !strings.Contains(w.Body.String(), "ws-app") {
			t.Errorf("%s: status = %d body = %q, want 200 + index.html", p, w.Code, w.Body.String())
		}
	}

	w := httptest.NewRecorder()
	h.ServeHTTP(w, httptest.NewRequest("GET", "/assets/app.js", nil))
	if w.Code != http.StatusOK || w.Body.String() != "console.log(1)" {
		t.Errorf("real asset broke: code=%d body=%q", w.Code, w.Body.String())
	}

	w2 := httptest.NewRecorder()
	h.ServeHTTP(w2, httptest.NewRequest("GET", "/wails.png", nil))
	if w2.Code != http.StatusOK {
		t.Errorf("real png broke: code=%d", w2.Code)
	}
}
