package service

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func newImageFetcherDB(t *testing.T) *store.DB {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "img.db"))
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
	// tick() downloads with 4 workers in parallel, so this handler runs on
	// several connections at once: an unguarded map write here is
	// `fatal error: concurrent map writes`, which kills the whole test binary
	// and reds the merge gate for a change that touched nothing near it.
	var mu sync.Mutex
	hits := map[string]int{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		hits[r.URL.Path]++
		mu.Unlock()
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

	hitCount := func() int {
		mu.Lock()
		defer mu.Unlock()
		return len(hits)
	}

	// Second tick — already cached, NOT yet stale → no new HTTP hits.
	hitsBefore := hitCount()
	f.tick()
	if got := hitCount(); got != hitsBefore {
		t.Errorf("second tick must not refetch fresh entries: want %d hits, got %d", hitsBefore, got)
	}
}

// Identical URLs referenced by both gallery + product.image_url must
// only download once (de-dup by URL hash).
func TestImageFetcher_DeduplicatesSameURL(t *testing.T) {
	// Same reason as above: if de-dup ever regresses — the very thing this
	// test guards — the counter is bumped from parallel workers.
	var mu sync.Mutex
	hits := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		hits++
		mu.Unlock()
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
	mu.Lock()
	got := hits
	mu.Unlock()
	if got != 1 {
		t.Errorf("dedup expected 1 HTTP hit, got %d", got)
	}
}

func TestImageFetcher_FilterUsesBatchedCacheLookup(t *testing.T) {
	db := newImageFetcherDB(t)
	f := NewImageFetcher(db)
	urls := make([]string, 1001)
	for i := range urls {
		urls[i] = fmt.Sprintf("https://assets.example.test/%d.jpg", i)
	}
	for _, u := range urls[:2] {
		mustExecF(t, db, `INSERT INTO pos_image_cache
			(url_hash, source_url, bytes, size, fetched_at)
			VALUES (?, ?, x'01', 1, datetime('now'))`, URLHash(u), u)
	}

	before := db.Diagnostics().QueryCount
	pending, err := f.filterToFetch(urls)
	if err != nil {
		t.Fatalf("filterToFetch: %v", err)
	}
	if got, want := len(pending), len(urls)-2; got != want {
		t.Errorf("pending = %d, want %d", got, want)
	}
	// lookupBatchSize=400 => ceil(1001/400) = 3 queries, not 1001.
	if got := db.Diagnostics().QueryCount - before; got != 3 {
		t.Errorf("cache lookup query count = %d, want 3 batched queries", got)
	}
}

func TestImageFetcher_LookupCoalescesReferenceWrites(t *testing.T) {
	db := newImageFetcherDB(t)
	f := NewImageFetcher(db)
	u := "https://assets.example.test/hot.jpg"
	hash := URLHash(u)
	mustExecF(t, db, `INSERT INTO pos_image_cache
		(url_hash, source_url, bytes, size, last_referenced_at)
		VALUES (?, ?, x'01', 1, '2000-01-01 00:00:00')`, hash, u)

	if _, _, err := f.LookupCached(hash); err != nil {
		t.Fatalf("first lookup: %v", err)
	}
	// Put a marker back after the first touch. A second hot-cache hit inside
	// touchInterval must not overwrite it with another SQLite write.
	mustExecF(t, db, `UPDATE pos_image_cache SET last_referenced_at = '2001-01-01 00:00:00' WHERE url_hash = ?`, hash)
	before := db.Diagnostics().ExecCount
	if _, _, err := f.LookupCached(hash); err != nil {
		t.Fatalf("second lookup: %v", err)
	}
	if got := db.Diagnostics().ExecCount - before; got != 0 {
		t.Errorf("second lookup performed %d reference write(s), want 0", got)
	}
	var referenced string
	if err := db.QueryRow(`SELECT last_referenced_at FROM pos_image_cache WHERE url_hash = ?`, hash).Scan(&referenced); err != nil {
		t.Fatalf("read marker: %v", err)
	}
	if referenced != "2001-01-01 00:00:00" {
		t.Errorf("reference marker changed on coalesced hit: %q", referenced)
	}
}

func TestImageFetcher_BoundsBackgroundConcurrency(t *testing.T) {
	var mu sync.Mutex
	active, peak := 0, 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		active++
		if active > peak {
			peak = active
		}
		mu.Unlock()
		time.Sleep(20 * time.Millisecond)
		mu.Lock()
		active--
		mu.Unlock()
		_, _ = w.Write([]byte("img"))
	}))
	defer srv.Close()

	db := newImageFetcherDB(t)
	for i := 0; i < 8; i++ {
		mustExecF(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES (?, ?, ?)`,
			fmt.Sprintf("p-%d", i), "P", fmt.Sprintf("%s/%d.jpg", srv.URL, i))
	}
	NewImageFetcher(db).tick()

	mu.Lock()
	gotPeak := peak
	mu.Unlock()
	if gotPeak == 0 || gotPeak > 2 {
		t.Errorf("background concurrency peak = %d, want 1..2", gotPeak)
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

// A URL whose fetch fails (404, network error, …) must NOT be retried on
// every 5 s tick — Cloud data can reference an image that was never
// uploaded, and before the backoff two such URLs at one shop produced
// ~34k requests + WARN lines per day. Failures park the URL with
// exponential backoff and a later success re-arms it.
func TestImageFetcher_FailedFetchBacksOffInsteadOfRetryingEveryTick(t *testing.T) {
	var mu sync.Mutex
	hits := 0
	broken := true
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		hits++
		if broken {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("Content-Type", "image/jpeg")
		w.Write([]byte("img"))
	}))
	defer srv.Close()
	u := srv.URL + "/missing.jpg"

	db := newImageFetcherDB(t)
	mustExecF(t, db, `INSERT INTO pos_products (id, name, image_url) VALUES ('p1','P',?)`, u)

	f := NewImageFetcher(db)
	f.tick()

	hitCount := func() int {
		mu.Lock()
		defer mu.Unlock()
		return hits
	}
	if got := hitCount(); got != 1 {
		t.Fatalf("first tick should hit once, got %d", got)
	}

	// Ticks inside the backoff window must not touch the server at all.
	f.tick()
	f.tick()
	if got := hitCount(); got != 1 {
		t.Errorf("backed-off URL was refetched: want 1 hit, got %d", got)
	}

	// Second failure doubles the delay.
	hash := URLHash(u)
	f.failMu.Lock()
	f.failures[hash] = fetchFailure{attempts: 1, nextRetry: time.Now().Add(-time.Second)}
	f.failMu.Unlock()
	f.tick()
	if got := hitCount(); got != 2 {
		t.Fatalf("expired backoff should refetch: want 2 hits, got %d", got)
	}
	f.failMu.Lock()
	gotAttempts := f.failures[hash].attempts
	f.failMu.Unlock()
	if gotAttempts != 2 {
		t.Errorf("attempts want 2, got %d", gotAttempts)
	}

	// Once the image exists, the next allowed attempt caches it and clears
	// the failure state — no cache invalidation needed on the Cloud side.
	mu.Lock()
	broken = false
	mu.Unlock()
	f.failMu.Lock()
	f.failures[hash] = fetchFailure{attempts: 2, nextRetry: time.Now().Add(-time.Second)}
	f.failMu.Unlock()
	f.tick()

	if _, _, err := f.LookupCached(hash); err != nil {
		t.Errorf("expected cached after recovery: %v", err)
	}
	f.failMu.Lock()
	_, stillFailed := f.failures[hash]
	f.failMu.Unlock()
	if stillFailed {
		t.Errorf("success must clear the failure state")
	}
}

// The backoff delay ladder: base doubled per consecutive failure, capped
// at retryMax, and immune to shift overflow on absurd attempt counts.
func TestImageFetcher_FailureBackoffLadder(t *testing.T) {
	f := NewImageFetcher(newImageFetcherDB(t))
	f.retryBase = time.Second
	f.retryMax = 8 * time.Second

	u := "https://cloud.example/img.jpg"
	for i, want := range []time.Duration{
		1 * time.Second, 2 * time.Second, 4 * time.Second,
		8 * time.Second, 8 * time.Second,
	} {
		if got := f.noteFailure(u); got != want {
			t.Errorf("failure #%d: delay want %v, got %v", i+1, want, got)
		}
	}

	// Force an attempt count past the shift width — must clamp, not wrap.
	hash := URLHash(u)
	f.failMu.Lock()
	f.failures[hash] = fetchFailure{attempts: 100}
	f.failMu.Unlock()
	if got := f.noteFailure(u); got != f.retryMax {
		t.Errorf("overflowed shift must clamp to retryMax, got %v", got)
	}
}

// Failure state must not outlive the catalog reference: a URL removed
// from every image column gets its backoff entry pruned on the next
// sweep, keeping the map bounded by the live URL set.
func TestImageFetcher_PrunesFailureStateForDroppedURLs(t *testing.T) {
	db := newImageFetcherDB(t)
	f := NewImageFetcher(db)

	gone := "https://cloud.example/gone.jpg"
	f.noteFailure(gone)

	kept := "https://cloud.example/kept.jpg"
	out := f.dropBackedOff([]string{kept})
	if len(out) != 1 || out[0] != kept {
		t.Fatalf("kept URL must pass through, got %v", out)
	}

	f.failMu.Lock()
	_, still := f.failures[URLHash(gone)]
	f.failMu.Unlock()
	if still {
		t.Errorf("failure state for a no-longer-referenced URL must be pruned")
	}
}
