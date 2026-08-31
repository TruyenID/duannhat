package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"testing/fstest"
)

func TestPosSPAHandler_InjectsCloudURLIntoIndex(t *testing.T) {
	assets := fstest.MapFS{
		"index.html":    &fstest.MapFile{Data: []byte(`<!doctype html><html><head><title>pos</title></head><body></body></html>`)},
		"assets/app.js": &fstest.MapFile{Data: []byte("console.log(1)")},
	}
	h := newPosSPAHandler(assets, func() string { return "https://api.deployed.example" })

	// Root → index.html with the Cloud URL injected.
	w := httptest.NewRecorder()
	h.ServeHTTP(w, httptest.NewRequest("GET", "/", nil))
	if !strings.Contains(w.Body.String(), `<meta name="x-pos-cloud-url" content="https://api.deployed.example">`) {
		t.Errorf("root: Cloud URL not injected:\n%s", w.Body.String())
	}
	if cc := w.Header().Get("Cache-Control"); cc != "no-store" {
		t.Errorf("index must be no-store, got %q", cc)
	}

	// SPA client route → index.html (also injected).
	w2 := httptest.NewRecorder()
	h.ServeHTTP(w2, httptest.NewRequest("GET", "/shop/sjk", nil))
	if !strings.Contains(w2.Body.String(), "x-pos-cloud-url") {
		t.Errorf("SPA route: Cloud URL not injected")
	}

	// A real static asset is served verbatim (never transformed).
	w3 := httptest.NewRecorder()
	h.ServeHTTP(w3, httptest.NewRequest("GET", "/assets/app.js", nil))
	if w3.Code != http.StatusOK || w3.Body.String() != "console.log(1)" {
		t.Errorf("asset altered: code=%d body=%q", w3.Code, w3.Body.String())
	}
	if strings.Contains(w3.Body.String(), "x-pos-cloud-url") {
		t.Errorf("asset must not be transformed")
	}
}

// A missing asset must 404 instead of falling through to index.html: answering
// a .js request with 200 + HTML turns "stale index.html points at a rebuilt
// bundle" into a blank POS screen with a MIME error, instead of a 404 naming
// the missing file. Extension-less unknown paths keep the SPA fallback so deep
// links still survive a reload.
func TestPosSPAHandler_MissingAssetIs404_ClientRouteStillFallsBack(t *testing.T) {
	assets := fstest.MapFS{
		"index.html":    &fstest.MapFile{Data: []byte(`<head></head>`)},
		"assets/app.js": &fstest.MapFile{Data: []byte("console.log(1)")},
	}
	h := newPosSPAHandler(assets, func() string { return "https://api.example" })

	for _, missing := range []string{
		"/assets/index-OLDHASH.js", // the stale-index case
		"/assets/index-OLDHASH.css",
		"/favicon.svg",
		"/sw.js",
	} {
		w := httptest.NewRecorder()
		h.ServeHTTP(w, httptest.NewRequest("GET", missing, nil))
		if w.Code != http.StatusNotFound {
			t.Errorf("%s: status = %d, want 404 (body: %q)", missing, w.Code, w.Body.String())
		}
		if strings.Contains(w.Body.String(), "x-pos-cloud-url") {
			t.Errorf("%s: served index.html instead of 404", missing)
		}
	}

	// Extension-less client routes still get index.html — the SPA fallback is
	// the whole reason a reload on /shop/sjk/shift/open works.
	for _, route := range []string{"/", "/pairing", "/shop/sjk", "/shop/sjk/shift/open"} {
		w := httptest.NewRecorder()
		h.ServeHTTP(w, httptest.NewRequest("GET", route, nil))
		if w.Code != http.StatusOK || !strings.Contains(w.Body.String(), "x-pos-cloud-url") {
			t.Errorf("%s: status = %d, want 200 + injected index.html", route, w.Code)
		}
	}

	// The asset that DOES exist is unaffected.
	w := httptest.NewRecorder()
	h.ServeHTTP(w, httptest.NewRequest("GET", "/assets/app.js", nil))
	if w.Code != http.StatusOK || w.Body.String() != "console.log(1)" {
		t.Errorf("existing asset broke: code=%d body=%q", w.Code, w.Body.String())
	}
}

func TestPosSPAHandler_NoInjectionWhenCloudEmpty(t *testing.T) {
	assets := fstest.MapFS{"index.html": &fstest.MapFile{Data: []byte(`<head></head>`)}}
	h := newPosSPAHandler(assets, func() string { return "" })
	w := httptest.NewRecorder()
	h.ServeHTTP(w, httptest.NewRequest("GET", "/", nil))
	if strings.Contains(w.Body.String(), "x-pos-cloud-url") {
		t.Errorf("must not inject a meta when the cloud URL is empty: %s", w.Body.String())
	}
}

func TestHandlePosBundleVersion_ServesEmbeddedStamp(t *testing.T) {
	s := &Server{posAssets: fstest.MapFS{
		"pos-bundle-version.json": &fstest.MapFile{
			Data: []byte(`{"bundle":"pos-web","version":"0.4.2","commit":"abc1234","builtAt":"2026-08-01T00:00:00.000Z"}` + "\n"),
		},
	}}

	w := httptest.NewRecorder()
	s.handlePosBundleVersion(w, httptest.NewRequest("GET", "/api/lan/pos-bundle/version", nil))

	if w.Code != 200 {
		t.Fatalf("status = %d, want 200", w.Code)
	}
	if ct := w.Header().Get("Content-Type"); ct != "application/json" {
		t.Errorf("Content-Type = %q, want application/json", ct)
	}
	body := w.Body.String()
	if !strings.Contains(body, `"commit":"abc1234"`) {
		t.Errorf("body did not carry the embedded stamp: %s", body)
	}
}

func TestHandlePosBundleVersion_UnknownWhenNoBundle(t *testing.T) {
	s := &Server{} // no posAssets embedded

	w := httptest.NewRecorder()
	s.handlePosBundleVersion(w, httptest.NewRequest("GET", "/api/lan/pos-bundle/version", nil))

	if w.Code != 200 {
		t.Fatalf("status = %d, want 200", w.Code)
	}
	if body := w.Body.String(); !strings.Contains(body, `"version":"unknown"`) {
		t.Errorf("expected unknown version fallback, got: %s", body)
	}
}
