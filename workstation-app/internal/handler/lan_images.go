package handler

import (
	"errors"
	"fmt"
	"net/http"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// GET /api/lan/images/{hash}  (no auth)
//
// Serves the cached image bytes for a SHA-256 URL hash that
// ImageFetcher previously downloaded. Returns 404 when the hash isn't
// cached — pos-web's <img onerror> fallback then renders the
// placeholder. No auth: images carry no PII beyond their URL, which
// the LAN tablet already knows by virtue of receiving the
// product/gallery payload.
//
// Why a hash and not the encoded URL? URLs from Cloud carry
// signature query params (e.g. MinIO presigned), can be 200+ chars,
// and embed slashes that confuse the router. A 64-char hex digest is
// stable, slash-free, and Go's ServeMux happily matches it as a
// PathValue.
func (s *Server) handleLANImage(w http.ResponseWriter, r *http.Request) {
	if s.imageFetcher == nil {
		writeError(w, http.StatusServiceUnavailable, "image cache not running")
		return
	}
	hash := strings.TrimSpace(r.PathValue("hash"))
	if !isImageHashValid(hash) {
		writeError(w, http.StatusBadRequest, "invalid image hash")
		return
	}
	body, ct, err := s.imageFetcher.LookupCached(hash)
	if err != nil {
		if errors.Is(err, service.ErrCacheMiss) {
			writeError(w, http.StatusNotFound, "image not cached")
			return
		}
		// `LookupCached` returns sql.ErrNoRows for plain misses; map.
		writeError(w, http.StatusNotFound, "image not cached")
		return
	}
	if ct == "" {
		ct = "application/octet-stream"
	}
	w.Header().Set("Content-Type", ct)
	w.Header().Set("Content-Length", fmt.Sprintf("%d", len(body)))
	// Long cache + immutable hint: the URL hash is content-addressable
	// (different image → different hash) so browsers can cache freely.
	w.Header().Set("Cache-Control", "public, max-age=31536000, immutable")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(body)
}

// isImageHashValid enforces the SHA-256 hex shape so a misrouted
// arbitrary path can never reach LookupCached with attacker-controlled
// input. SHA-256 hex is exactly 64 chars, [0-9a-f].
func isImageHashValid(s string) bool {
	if len(s) != 64 {
		return false
	}
	for _, c := range s {
		switch {
		case c >= '0' && c <= '9':
		case c >= 'a' && c <= 'f':
		default:
			return false
		}
	}
	return true
}

// rewriteResponseImages walks the JSON response tree (map[string]any /
// []any / scalars), looking for the well-known image URL keys and
// rewriting their values via rewriteImageURL. Called from each menu
// handler right before writeJSON. Keeping the rewrite at the handler
// edge (instead of threading r down to every loader signature) means
// the loader helpers stay request-agnostic + the URL rewrite stays
// one centralized hop — easy to disable, swap, or instrument.
//
// Keys rewritten: image_url (product / sku / topping item) + url
// (pos_product_galleries row). Other URL-shaped fields (logo, qr,
// receipt PDFs) are intentionally NOT touched.
func (s *Server) rewriteResponseImages(r *http.Request, node any) {
	switch v := node.(type) {
	case map[string]any:
		for key, val := range v {
			switch key {
			case "image_url", "url":
				if str, ok := val.(string); ok {
					if rewritten := s.rewriteImageURL(r, str); rewritten != str {
						v[key] = rewritten
					}
				}
			}
			s.rewriteResponseImages(r, val)
		}
	case []any:
		for _, e := range v {
			s.rewriteResponseImages(r, e)
		}
	case []map[string]any:
		// Some loaders construct slices of map[string]any explicitly
		// (rather than []any of map[string]any). Visit those too.
		for _, m := range v {
			s.rewriteResponseImages(r, m)
		}
	}
}

// rewriteImageURL maps a Cloud image URL to the LAN-served equivalent
// IF the workstation has already cached it. Returns the original URL
// unchanged when:
//   - the input isn't an HTTP(S) URL,
//   - the workstation has no image fetcher,
//   - the bytes haven't been downloaded yet.
//
// The check is fast (single keyed SELECT) and runs once per image URL
// in the menu response. Cold-start traffic still hits Cloud; once the
// fetcher catches up (5 s tick), subsequent reads serve locally.
//
// Public URL form: `http://{request-host}/api/lan/images/{hash}`. We
// reuse the incoming request's Host so the URL works for whichever
// LAN IP the tablet is browsing — same workstation can advertise on
// 192.168.1.x and 10.0.0.x simultaneously, and the rewritten URL
// follows whichever address the tablet used.
func (s *Server) rewriteImageURL(r *http.Request, original string) string {
	if original == "" || !strings.HasPrefix(original, "http") {
		return original
	}
	if s.imageFetcher == nil {
		return original
	}
	hash := service.URLHash(original)
	// Cheap existence check — separate from LookupCached so we don't
	// touch `last_referenced_at` until the tablet actually fetches.
	var n int
	_ = s.db.QueryRow(`SELECT 1 FROM pos_image_cache WHERE url_hash = ?`, hash).Scan(&n)
	if n == 0 {
		return original
	}
	host := r.Host
	if host == "" {
		host = fmt.Sprintf("localhost:%d", s.port)
	}
	scheme := "http"
	if r.TLS != nil {
		scheme = "https"
	}
	return fmt.Sprintf("%s://%s/api/lan/images/%s", scheme, host, hash)
}
