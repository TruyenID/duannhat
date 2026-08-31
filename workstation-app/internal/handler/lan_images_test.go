package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// /api/lan/images/{hash} returns 404 when the hash isn't cached + the
// body bytes when it is. Hash form is strictly checked so a bogus path
// can't reach the SQL layer.
func TestHandleLANImage_ServesCachedBytes(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.imageFetcher = service.NewImageFetcher(db)

	// Seed a cache row directly.
	url := "https://cdn.example.com/test.jpg"
	hash := service.URLHash(url)
	body := []byte("imgbytes")
	mustExec(t, db, `INSERT INTO pos_image_cache (url_hash, source_url, content_type, bytes, size) VALUES (?, ?, ?, ?, ?)`,
		hash, url, "image/jpeg", body, len(body))

	req := httptest.NewRequest("GET", "/api/lan/images/"+hash, nil)
	req.SetPathValue("hash", hash)
	w := httptest.NewRecorder()
	srv.handleLANImage(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d", w.Code)
	}
	if string(w.Body.Bytes()) != "imgbytes" {
		t.Errorf("body mismatch: %q", w.Body.String())
	}
	if w.Header().Get("Content-Type") != "image/jpeg" {
		t.Errorf("content-type want image/jpeg, got %q", w.Header().Get("Content-Type"))
	}
	if !strings.Contains(w.Header().Get("Cache-Control"), "max-age=") {
		t.Errorf("Cache-Control header missing max-age: %q", w.Header().Get("Cache-Control"))
	}
}

func TestHandleLANImage_404OnMiss(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.imageFetcher = service.NewImageFetcher(srv.db)
	hash := service.URLHash("https://nothere/")
	req := httptest.NewRequest("GET", "/api/lan/images/"+hash, nil)
	req.SetPathValue("hash", hash)
	w := httptest.NewRecorder()
	srv.handleLANImage(w, req)
	if w.Code != http.StatusNotFound {
		t.Errorf("uncached hash should 404, got %d", w.Code)
	}
}

func TestHandleLANImage_400OnInvalidHash(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.imageFetcher = service.NewImageFetcher(srv.db)
	req := httptest.NewRequest("GET", "/api/lan/images/not-a-real-hash", nil)
	req.SetPathValue("hash", "not-a-real-hash")
	w := httptest.NewRecorder()
	srv.handleLANImage(w, req)
	if w.Code != http.StatusBadRequest {
		t.Errorf("invalid hash should 400, got %d", w.Code)
	}
}

// rewriteResponseImages must walk the menu response tree and swap any
// `image_url` / `url` whose source URL is in the cache for the
// /api/lan/images/{hash} form. URLs not cached pass through.
func TestRewriteResponseImages_SwapsCachedURLsOnly(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.imageFetcher = service.NewImageFetcher(db)

	cachedURL := "https://cdn.example.com/cached.jpg"
	mustExec(t, db, `INSERT INTO pos_image_cache (url_hash, source_url, content_type, bytes, size) VALUES (?, ?, ?, ?, ?)`,
		service.URLHash(cachedURL), cachedURL, "image/jpeg", []byte("x"), 1)

	uncachedURL := "https://cdn.example.com/not-yet.jpg"

	node := map[string]any{
		"product": map[string]any{
			"image_url": cachedURL,
			"gallery": []any{
				map[string]any{"url": cachedURL},
				map[string]any{"url": uncachedURL},
			},
			"topping_groups": []any{
				map[string]any{
					"items": []any{
						map[string]any{"image_url": cachedURL},
					},
				},
			},
		},
		"skus": []any{
			map[string]any{"image_url": cachedURL},
		},
	}
	r := httptest.NewRequest("GET", "/", nil)
	r.Host = "tablet.local:8080"
	srv.rewriteResponseImages(r, node)

	product := node["product"].(map[string]any)
	if !strings.HasPrefix(product["image_url"].(string), "http://tablet.local:8080/api/lan/images/") {
		t.Errorf("cached product image_url not rewritten: %v", product["image_url"])
	}

	gallery := product["gallery"].([]any)
	if !strings.HasPrefix(gallery[0].(map[string]any)["url"].(string), "http://tablet.local:8080/api/lan/images/") {
		t.Errorf("cached gallery[0] url not rewritten")
	}
	if gallery[1].(map[string]any)["url"] != uncachedURL {
		t.Errorf("uncached gallery[1] url should pass through, got %v", gallery[1])
	}

	tgs := product["topping_groups"].([]any)
	items := tgs[0].(map[string]any)["items"].([]any)
	if !strings.HasPrefix(items[0].(map[string]any)["image_url"].(string), "http://tablet.local:8080/api/lan/images/") {
		t.Errorf("cached topping item image_url not rewritten")
	}

	skus := node["skus"].([]any)
	if !strings.HasPrefix(skus[0].(map[string]any)["image_url"].(string), "http://tablet.local:8080/api/lan/images/") {
		t.Errorf("cached sku image_url not rewritten")
	}
}
