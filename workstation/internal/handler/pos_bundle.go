package handler

import (
	"bytes"
	"html/template"
	"io/fs"
	"net/http"
	"path"
	"strings"
)

// posSPAHandler serves the embedded pos-web SPA at /pos AND injects the Cloud
// backend URL into index.html at serve time. base-url-resolver reads
// `<meta name="x-pos-cloud-url">` for CLOUD_URL when served at /pos, so a SINGLE
// WS_APP_CLOUD_URL on the workstation drives BOTH pairing (via the relay) and
// pos-web's Cloud mode — with no pos-web rebuild to change the backend.
//
// Static assets are served verbatim; only index.html (root + SPA client routes)
// is transformed. cloudURL resolves the backend via cloudURLForPairing —
// settings row first, then config.json / WS_APP_CLOUD_URL — so it is non-empty
// before the workstation has ever paired, and follows the operator afterwards
// if they repoint Cloud in Settings without a restart (#2431).
type posSPAHandler struct {
	assets   fs.FS
	fileSrv  http.Handler
	cloudURL func() string
}

func newPosSPAHandler(assets fs.FS, cloudURL func() string) *posSPAHandler {
	return &posSPAHandler{
		assets:   assets,
		fileSrv:  http.FileServerFS(assets),
		cloudURL: cloudURL,
	}
}

func (h *posSPAHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	p := strings.TrimPrefix(r.URL.Path, "/")
	// A real static asset (hashed js/css, fonts, images…) → serve verbatim.
	if p != "" && p != "index.html" {
		if _, err := fs.Stat(h.assets, p); err == nil {
			h.fileSrv.ServeHTTP(w, r)
			return
		}
		// A MISSING asset must 404, not fall through to index.html. The blanket
		// fallback answered `/pos/assets/index-OLD.js` with 200 + HTML, so a
		// tablet holding a cached index.html after a bundle swap got HTML where
		// it asked for JavaScript — the browser reports a MIME/syntax error deep
		// in the console and the POS just shows a blank screen, instead of the
		// 404 that names the real cause (stale index → rebuilt bundle).
		//
		// "Looks like a file" = has an extension. pos-web's client routes carry
		// none (/, /pairing, /pos, /shop/:slug/...), and everything the bundle
		// emits does (assets/*.js|css, favicon.svg, pos-bundle-version.json, and
		// the PWA's sw.js / manifest if it is ever enabled here). Extension-less
		// unknown paths keep the SPA fallback — that is what makes deep links
		// work on reload.
		if path.Ext(p) != "" {
			http.NotFound(w, r)
			return
		}
	}
	// Root or an SPA client route → index.html with the Cloud URL injected.
	data, err := fs.ReadFile(h.assets, "index.html")
	if err != nil {
		http.Error(w, "pos-web index not found", http.StatusNotFound)
		return
	}
	if cloud := strings.TrimSpace(h.cloudURL()); cloud != "" {
		meta := `<meta name="x-pos-cloud-url" content="` + template.HTMLEscapeString(cloud) + `">`
		data = bytes.Replace(data, []byte("</head>"), []byte(meta+"</head>"), 1)
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	// index.html must NEVER be cached: it carries the runtime Cloud URL, which
	// changes with the workstation .env.
	w.Header().Set("Cache-Control", "no-store")
	_, _ = w.Write(data)
}

// handlePosBundleVersion reports the identity of the embedded pos-web bundle
// served at /pos (#1169). pos-web's `--mode workstation` build stamps
// pos-web/dist/pos-bundle-version.json ({bundle, version, commit, builtAt});
// the workstation reads it straight from the embedded FS (s.posAssets is the
// dist sub-tree). This is the hook that a future v2 (auto-pull a newer bundle
// from Cloud) reads to decide "is my embedded bundle stale?".
//
// A binary built WITHOUT the real bundle carries the committed placeholder,
// which reports {"version":"placeholder"} — so an artifact that shipped without
// the bundle is visible here rather than silently serving a stub at /pos.
//
// Public (no auth), same as /api/lan/health: it exposes only a build id, and
// LAN ops / the bundle updater may need it before a token exists.
func (s *Server) handlePosBundleVersion(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")

	if s.posAssets != nil {
		if data, err := fs.ReadFile(s.posAssets, "pos-bundle-version.json"); err == nil {
			_, _ = w.Write(data)
			return
		}
	}
	// No bundle embedded, or the stamp is missing — report unknown rather than 404.
	_, _ = w.Write([]byte(`{"bundle":"pos-web","version":"unknown"}` + "\n"))
}
