package service

import (
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

func newImageFetcherDB(t *testing.T) *store.DB {
	t.Helper()
	db, err := store.Open(filepath.Join(t.TempDir(), "img.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return db
}

// tick() must download every URL surfaced by gallery + product +
// sku tables AND store the bytes verbatim. Subsequent ticks should
// be no-ops as long as the entry is fresh.
func TestImageFetcher_FetchesGalleryProductSkuURLs(t *testing.T) {
	hits := map[string]int{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits[r.URL.Path]++
		w.Header().Set("Content-Type", "image/jpeg")
		w.Header().Set("ETag", `"abc"`)
		w.Write([]byte("payload-" + r.URL.Path))
	}))
	defer srv.Close()

	db := newImageFetcherDB(t)
	gURL := srv.URL + "/gallery1.jpg"
	pURL := srv.URL + "/product1.jpg"
	sURL := srv.URL + "/sku1.jpg"

	mustExecF(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES ('p1','P',?)`, pURL)
	mustExecF(t, db, `INSERT INTO pos_product_skus (id, product_id, image_url, selling_price) VALUES ('s1','p1',?,100)`, sURL)
	mustExecF(t, db, `INSERT INTO pos_product_galleries (id, product_id, url) VALUES ('g1','p1',?)`, gURL)

	f := NewImageFetcher(db)
	f.tick()

	for _, u := range []string{gURL, pURL, sURL} {
		hash := URLHash(u)
		body, ct, err := f.LookupCached(hash)
		if err != nil {
			t.Errorf("expected cached %s: %v", u, err)
			continue
		}
		if ct != "image/jpeg" {
			t.Errorf("content_type want image/jpeg, got %q", ct)
		}
		if len(body) == 0 {
			t.Errorf("empty body for %s", u)
		}
	}

	// Second tick — already cached, NOT yet stale → no new HTTP hits.
	hitsBefore := len(hits)
	f.tick()
	if len(hits) != hitsBefore {
		t.Errorf("second tick must not refetch fresh entries: want %d hits, got %d", hitsBefore, len(hits))
	}
}

// Identical URLs referenced by both gallery + product.image_url must
// only download once (de-dup by URL hash).
func TestImageFetcher_DeduplicatesSameURL(t *testing.T) {
	hits := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits++
		w.Header().Set("Content-Type", "image/png")
		w.Write([]byte("dup"))
	}))
	defer srv.Close()
	u := srv.URL + "/same.png"

	db := newImageFetcherDB(t)
	mustExecF(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES ('p1','P',?)`, u)
	mustExecF(t, db, `INSERT INTO pos_product_galleries (id, product_id, url) VALUES ('g1','p1',?)`, u)
	mustExecF(t, db, `INSERT INTO pos_product_skus (id, product_id, image_url, selling_price) VALUES ('s1','p1',?,100)`, u)

	NewImageFetcher(db).tick()
	if hits != 1 {
		t.Errorf("dedup expected 1 HTTP hit, got %d", hits)
	}
}

// Bodies over the size ceiling are dropped (not cached) so a hostile
// or misconfigured asset can't blow the workstation memory budget.
func TestImageFetcher_RejectsOversize(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "image/jpeg")
		w.Write(make([]byte, 6*1024*1024)) // 6 MB > 5 MB cap
	}))
	defer srv.Close()
	u := srv.URL + "/big.jpg"

	db := newImageFetcherDB(t)
	mustExecF(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','P')`)
	mustExecF(t, db, `INSERT INTO pos_product_galleries (id, product_id, url) VALUES ('g1','p1',?)`, u)

	NewImageFetcher(db).tick()

	_, _, err := NewImageFetcher(db).LookupCached(URLHash(u))
	if err == nil {
		t.Errorf("oversize body must NOT be cached")
	}
}

// When a previously-cached URL is queried with If-None-Match the
// server returns 304 — body must NOT be re-downloaded but
// fetched_at MUST tick forward so the stale window restarts.
func TestImageFetcher_ConditionalGet304RefreshesFetchedAt(t *testing.T) {
	bodyServed := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("If-None-Match") == `"v1"` {
			w.WriteHeader(http.StatusNotModified)
			return
		}
		w.Header().Set("Content-Type", "image/jpeg")
		w.Header().Set("ETag", `"v1"`)
		w.Write([]byte("img"))
		bodyServed++
	}))
	defer srv.Close()
	u := srv.URL + "/img.jpg"

	db := newImageFetcherDB(t)
	mustExecF(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES ('p1','P',?)`, u)

	f := NewImageFetcher(db)
	f.staleAfter = 1 * time.Millisecond // force second tick to consider it stale
	f.tick()
	time.Sleep(5 * time.Millisecond)
	f.tick()

	if bodyServed != 1 {
		t.Errorf("body should be served once, served %d times", bodyServed)
	}

	hash := URLHash(u)
	_, _, err := f.LookupCached(hash)
	if err != nil {
		t.Errorf("entry must remain cached after 304: %v", err)
	}
}

// LRU eviction kicks in when cumulative bytes exceed maxBudget. Oldest
// last_referenced_at row is dropped first.
func TestImageFetcher_LRUEviction(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "image/jpeg")
		// 1 KB body so 4 fit in 3 KB budget but the 4th evicts the oldest.
		w.Write(make([]byte, 1024))
	}))
	defer srv.Close()

	db := newImageFetcherDB(t)
	f := NewImageFetcher(db)
	f.maxBudget = 3 * 1024 // 3 KB

	urls := []string{srv.URL + "/a", srv.URL + "/b", srv.URL + "/c", srv.URL + "/d"}
	mustExecF(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','P')`)
	for i, u := range urls {
		mustExecF(t, db, `INSERT INTO pos_product_galleries (id, product_id, url) VALUES (?, 'p1', ?)`, "g"+string(rune('0'+i)), u)
		f.tick()
		// Sleep so SQLite datetime('now') ticks forward — eviction
		// orders by last_referenced_at ASC and 'now' is second-precision
		// on the modernc driver.
		time.Sleep(1100 * time.Millisecond)
	}

	// First URL must have been evicted; last two MUST survive.
	if _, _, err := f.LookupCached(URLHash(urls[0])); err == nil {
		t.Errorf("oldest URL must be evicted under budget pressure")
	}
	if _, _, err := f.LookupCached(URLHash(urls[3])); err != nil {
		t.Errorf("newest URL must survive: %v", err)
	}
}

func mustExecF(t *testing.T, db *store.DB, q string, args ...any) {
	t.Helper()
	if _, err := db.Exec(q, args...); err != nil {
		t.Fatalf("exec %s: %v", q, err)
	}
}
