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
	s.rewriteResponseImagesBase(s.imageBaseFromRequest(r), node)
}

// rewriteResponseImagesBase is rewriteResponseImages with the base URL already
// decided, so a request-free caller (a broadcast) can use the same walk.
func (s *Server) rewriteResponseImagesBase(base string, node any) {
	if base == "" || s.imageFetcher == nil {
		return
	}
	urls := map[string]bool{}
	collectResponseImageURLs(node, urls)
	if len(urls) == 0 {
		return
	}
	hashes := make([]string, 0, len(urls))
	for original := range urls {
		hashes = append(hashes, service.URLHash(original))
	}
	cached := s.cachedImageHashes(hashes)
	rewriteResponseImagesFromCache(base, node, cached)
}

func collectResponseImageURLs(node any, urls map[string]bool) {
	switch v := node.(type) {
	case map[string]any:
		for key, val := range v {
			switch key {
			case "image_url", "url":
				if str, ok := val.(string); ok && strings.HasPrefix(str, "http") {
					urls[str] = true
				}
			}
			collectResponseImageURLs(val, urls)
		}
	case []any:
		for _, e := range v {
			collectResponseImageURLs(e, urls)
		}
	case []map[string]any:
		for _, m := range v {
			collectResponseImageURLs(m, urls)
		}
	}
}

func rewriteResponseImagesFromCache(base string, node any, cached map[string]bool) {
	switch v := node.(type) {
	case map[string]any:
		for key, val := range v {
			if (key == "image_url" || key == "url") && val != nil {
				if original, ok := val.(string); ok {
					hash := service.URLHash(original)
					if cached[hash] {
						v[key] = fmt.Sprintf("%s/api/lan/images/%s", base, hash)
					}
				}
			}
			rewriteResponseImagesFromCache(base, val, cached)
		}
	case []any:
		for _, value := range v {
			rewriteResponseImagesFromCache(base, value, cached)
		}
	case []map[string]any:
		for _, value := range v {
			rewriteResponseImagesFromCache(base, value, cached)
		}
	}
}

func (s *Server) cachedImageHashes(hashes []string) map[string]bool {
	const lookupBatchSize = 400
	out := make(map[string]bool, len(hashes))
	for start := 0; start < len(hashes); start += lookupBatchSize {
		end := start + lookupBatchSize
		if end > len(hashes) {
			end = len(hashes)
		}
		ph, args := inPlaceholders(hashes[start:end])
		rows, err := s.db.Query(`SELECT url_hash FROM pos_image_cache WHERE url_hash IN (`+ph+`)`, args...)
		if err != nil {
			continue
		}
		for rows.Next() {
			var hash string
			if rows.Scan(&hash) == nil {
				out[hash] = true
			}
		}
		rows.Close()
	}
	return out
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
// imageBaseFromRequest is the scheme+host a REQUESTING client should be told to
// fetch cached images from — whatever host it dialled us on.
func (s *Server) imageBaseFromRequest(r *http.Request) string {
	host := r.Host
	if host == "" {
		host = fmt.Sprintf("localhost:%d", s.port)
	}
	scheme := "http"
	if r.TLS != nil {
		scheme = "https"
	}
	return scheme + "://" + host
}

// imageBaseForBroadcast is the same thing for a payload with NO requester — a
// WebSocket fan-out reaches many devices at once, so `localhost` (the
// request-path fallback) would be wrong for every one of them. GetLANAddress()
// is the address this workstation already hands to tablets for exactly this
// reason.
func (s *Server) imageBaseForBroadcast() string {
	host := GetLANAddress()
	if host == "" {
		return ""
	}
	return fmt.Sprintf("http://%s:%d", host, s.port)
}

func (s *Server) rewriteImageURL(r *http.Request, original string) string {
	return s.rewriteImageURLBase(s.imageBaseFromRequest(r), original)
}

// rewriteImageURLBase is rewriteImageURL with the base URL already decided.
// An empty base means "leave the URL alone" — better an un-rewritten Cloud URL
// than one pointing at an address the recipient cannot reach.
func (s *Server) rewriteImageURLBase(base, original string) string {
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
	if base == "" {
		return original
	}
	return fmt.Sprintf("%s/api/lan/images/%s", base, hash)
}
