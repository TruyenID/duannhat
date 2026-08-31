package service

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// ImageFetcher downloads remote image URLs surfaced by the menu replica
// (pos_product_galleries.url, pos_products.image_url,
// pos_product_skus.image_url) and stores the bytes in `pos_image_cache`
// so the LAN HTTP server can serve them without round-tripping to Cloud.
//
// Without local caching, every <img src> on a LAN-mode pos-web tile
// resolves to a Cloud URL (MinIO / S3 path) — fine while the device
// has internet, broken the moment it doesn't. Even with internet, the
// LAN tablet pulls hundreds of MB per shift through the workstation's
// uplink. Caching once + serving locally is bandwidth-cheap + outage-
// proof.
//
// Tick cadence matches the menu catalog pull (5 s) so a new image
// uploaded HQ-side becomes available on the cashier device inside the
// same window as any other catalog edit. The fetcher is idempotent:
// already-cached URLs are skipped unless `staleAfter` has elapsed,
// avoiding redundant HTTP traffic.
type ImageFetcher struct {
	db          *store.DB
	httpClient  *http.Client
	staleAfter  time.Duration
	maxBodyByte int64
	maxBudget   int64 // total cache bytes ceiling; oldest-referenced rows evicted past this.
	// Background downloads may finish together and each persist a BLOB. Keep
	// that writer fan-in below the foreground POS pool pressure.
	maxConcurrency int

	touchMu       sync.Mutex
	lastTouched   map[string]time.Time
	touchInterval time.Duration

	// Failed fetches back off exponentially instead of retrying every tick.
	// Cloud data can reference an image that was never uploaded (measured at
	// Tsukiji 2026-08-18: two such URLs → a 404 retried every 5 s, ~34k
	// requests and WARN lines per day, forever). In-memory on purpose: a
	// restart retries once and re-arms, and a URL that starts existing is
	// picked up at the next retry window without any cache invalidation.
	failMu    sync.Mutex
	failures  map[string]fetchFailure // url_hash → backoff state
	retryBase time.Duration
	retryMax  time.Duration
	stopCh    chan struct{}
	stopOnce  sync.Once
}

// fetchFailure tracks one URL's consecutive failures and when the next
// attempt is allowed.
type fetchFailure struct {
	attempts  int
	nextRetry time.Time
}

// NewImageFetcher wires the fetcher with sensible defaults:
//
//	staleAfter  : 24h — even when ETag isn't returned, refetch daily so
//	              shop-side image edits propagate.
//	maxBodyByte : 5 MB per image. Larger payloads are skipped + logged.
//	maxBudget   : 200 MB. Past this the LRU eviction (oldest
//	              last_referenced_at) trims aggressively.
func NewImageFetcher(db *store.DB) *ImageFetcher {
	return &ImageFetcher{
		db: db,
		httpClient: &http.Client{
			Timeout: 15 * time.Second,
		},
		staleAfter:     24 * time.Hour,
		maxBodyByte:    5 * 1024 * 1024,
		maxBudget:      200 * 1024 * 1024,
		maxConcurrency: 2,
		lastTouched:    make(map[string]time.Time),
		touchInterval:  10 * time.Minute,
		failures:       make(map[string]fetchFailure),
		retryBase:      30 * time.Second,
		retryMax:       time.Hour,
		stopCh:         make(chan struct{}),
	}
}

// Start launches the fetcher goroutine. Same cadence as the slow
// puller, with a small offset so the two loops don't both grab a
// SQLite writer in the same millisecond — keeps the 5 s sync contract
// visible while staying under the conn pool.
func (f *ImageFetcher) Start() {
	go f.loop()
}

// Stop is idempotent — safe to call from app shutdown or test
// teardown.
func (f *ImageFetcher) Stop() {
	f.stopOnce.Do(func() { close(f.stopCh) })
}

func (f *ImageFetcher) loop() {
	// Offset boot slightly past the slow puller so PullMenuCatalog has
	// landed at least one row of URLs before we sweep.
	select {
	case <-f.stopCh:
		return
	case <-time.After(1500 * time.Millisecond):
	}
	t := time.NewTicker(5 * time.Second)
	defer t.Stop()
	f.tick()
	for {
		select {
		case <-f.stopCh:
			return
		case <-t.C:
			f.tick()
		}
	}
}

// tick performs one sweep:
//   - collect URLs from gallery + product + sku columns
//   - drop those already cached and not yet stale
//   - download + insert the rest with bounded concurrency
//   - run LRU eviction if total cache size exceeds budget
func (f *ImageFetcher) tick() {
	urls, err := f.collectURLs()
	if err != nil {
		slog.Warn("image_fetcher collect", "err", err)
		return
	}
	if len(urls) == 0 {
		return
	}

	pending, err := f.filterToFetch(urls)
	if err != nil {
		slog.Warn("image_fetcher filter", "err", err)
		return
	}
	pending = f.dropBackedOff(pending)
	if len(pending) == 0 {
		_ = f.evictIfOverBudget()
		return
	}

	// Bounded background work: downloads do not hold SQLite connections, but
	// their BLOB writes land together. Two workers keep the uplink moving while
	// leaving most of the 8-connection pool to cashier traffic.
	concurrency := f.maxConcurrency
	if concurrency < 1 {
		concurrency = 1
	}
	sem := make(chan struct{}, concurrency)
	var wg sync.WaitGroup
	for _, u := range pending {
		wg.Add(1)
		sem <- struct{}{}
		go func(url string) {
			defer wg.Done()
			defer func() { <-sem }()
			if err := f.fetchOne(url); err != nil {
				retryIn := f.noteFailure(url)
				slog.Warn("image_fetcher fetchOne", "url", url, "err", err, "retry_in", retryIn)
			} else {
				f.clearFailure(url)
			}
		}(u)
	}
	wg.Wait()

	_ = f.evictIfOverBudget()
}

// collectURLs walks the three tables that emit image URLs into the
// LAN handler response. De-dupes; the same Cloud URL referenced by
// product.image_url + gallery + sku.image_url only fetches once.
func (f *ImageFetcher) collectURLs() ([]string, error) {
	seen := map[string]struct{}{}
	out := []string{}

	rowsFn := func(query string) error {
		rows, err := f.db.Query(query)
		if err != nil {
			return err
		}
		defer rows.Close()
		for rows.Next() {
			var u string
			if err := rows.Scan(&u); err != nil {
				return err
			}
			u = strings.TrimSpace(u)
			if u == "" || !isHTTP(u) {
				continue
			}
			if _, ok := seen[u]; ok {
				continue
			}
			seen[u] = struct{}{}
			out = append(out, u)
		}
		return rows.Err()
	}

	for _, q := range []string{
		`SELECT url FROM pos_product_galleries WHERE url IS NOT NULL AND url != ''`,
		`SELECT image_url FROM pos_products WHERE image_url IS NOT NULL AND image_url != ''`,
		`SELECT image_url FROM pos_product_skus WHERE image_url IS NOT NULL AND image_url != ''`,
	} {
		if err := rowsFn(q); err != nil {
			return nil, err
		}
	}
	return out, nil
}

// filterToFetch returns only the URLs that are NOT cached or whose
// cached entry is older than staleAfter.
func (f *ImageFetcher) filterToFetch(urls []string) ([]string, error) {
	const lookupBatchSize = 400

	hashToURL := make(map[string]string, len(urls))
	hashes := make([]string, 0, len(urls))
	for _, u := range urls {
		hash := URLHash(u)
		if _, exists := hashToURL[hash]; exists {
			continue
		}
		hashToURL[hash] = u
		hashes = append(hashes, hash)
	}

	fetchedAt := make(map[string]string, len(hashes))
	for start := 0; start < len(hashes); start += lookupBatchSize {
		end := start + lookupBatchSize
		if end > len(hashes) {
			end = len(hashes)
		}
		batch := hashes[start:end]
		placeholders := strings.TrimSuffix(strings.Repeat("?,", len(batch)), ",")
		args := make([]any, len(batch))
		for i, hash := range batch {
			args[i] = hash
		}
		rows, err := f.db.Query(
			`SELECT url_hash, fetched_at FROM pos_image_cache WHERE url_hash IN (`+placeholders+`)`,
			args...,
		)
		if err != nil {
			return nil, err
		}
		for rows.Next() {
			var hash, fetched string
			if err := rows.Scan(&hash, &fetched); err != nil {
				rows.Close()
				return nil, err
			}
			fetchedAt[hash] = fetched
		}
		if err := rows.Err(); err != nil {
			rows.Close()
			return nil, err
		}
		rows.Close()
	}

	out := make([]string, 0, len(urls))
	staleCutoff := time.Now().Add(-f.staleAfter).UTC().Format(time.RFC3339)
	for _, hash := range hashes {
		fetched, cached := fetchedAt[hash]
		if !cached || fetched < staleCutoff {
			out = append(out, hashToURL[hash])
		}
	}
	return out, nil
}

// dropBackedOff removes URLs whose last fetch failed and whose retry
// window has not elapsed yet. It also prunes failure state for URLs the
// catalog no longer references, so the map cannot grow past the URL set.
func (f *ImageFetcher) dropBackedOff(urls []string) []string {
	now := time.Now()
	f.failMu.Lock()
	defer f.failMu.Unlock()

	live := make(map[string]struct{}, len(urls))
	out := urls[:0]
	for _, u := range urls {
		hash := URLHash(u)
		live[hash] = struct{}{}
		if fail, ok := f.failures[hash]; ok && now.Before(fail.nextRetry) {
			continue
		}
		out = append(out, u)
	}
	for hash := range f.failures {
		if _, ok := live[hash]; !ok {
			delete(f.failures, hash)
		}
	}
	return out
}

// noteFailure records one failed attempt and returns how long the URL is
// parked: retryBase doubled per consecutive failure, capped at retryMax.
func (f *ImageFetcher) noteFailure(url string) time.Duration {
	hash := URLHash(url)
	f.failMu.Lock()
	defer f.failMu.Unlock()

	fail := f.failures[hash]
	fail.attempts++
	delay := f.retryBase << (fail.attempts - 1)
	if delay > f.retryMax || delay <= 0 {
		delay = f.retryMax
	}
	fail.nextRetry = time.Now().Add(delay)
	f.failures[hash] = fail
	return delay
}

// clearFailure re-arms a URL after a successful fetch (or 304).
func (f *ImageFetcher) clearFailure(url string) {
	hash := URLHash(url)
	f.failMu.Lock()
	delete(f.failures, hash)
	f.failMu.Unlock()
}

// fetchOne issues an HTTP GET (with If-None-Match when we already have
// an ETag for this URL) and persists the body into pos_image_cache.
func (f *ImageFetcher) fetchOne(url string) error {
	hash := URLHash(url)

	var prevETag string
	_ = f.db.QueryRow(`SELECT COALESCE(etag, '') FROM pos_image_cache WHERE url_hash = ?`, hash).
		Scan(&prevETag)

	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return err
	}
	if prevETag != "" {
		req.Header.Set("If-None-Match", prevETag)
	}
	resp, err := f.httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusNotModified {
		// Refresh fetched_at so the staleAfter window restarts.
		_, err := f.db.Exec(`UPDATE pos_image_cache SET fetched_at = datetime('now') WHERE url_hash = ?`, hash)
		return err
	}
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("non-200 from %s: %d", url, resp.StatusCode)
	}

	body, err := io.ReadAll(io.LimitReader(resp.Body, f.maxBodyByte+1))
	if err != nil {
		return err
	}
	if int64(len(body)) > f.maxBodyByte {
		return fmt.Errorf("image exceeds max body size (%d): %s", f.maxBodyByte, url)
	}

	ct := resp.Header.Get("Content-Type")
	etag := resp.Header.Get("ETag")

	_, err = f.db.Exec(`
		INSERT INTO pos_image_cache (url_hash, source_url, content_type, etag, bytes, size,
		    fetched_at, last_referenced_at)
		VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
		ON CONFLICT(url_hash) DO UPDATE SET
		    source_url   = excluded.source_url,
		    content_type = excluded.content_type,
		    etag         = excluded.etag,
		    bytes        = excluded.bytes,
		    size         = excluded.size,
		    fetched_at   = datetime('now')`,
		hash, url, ct, etag, body, len(body))
	return err
}

// evictIfOverBudget keeps the cache below `maxBudget` total bytes by
// deleting the least-recently-referenced rows. Reads are unaffected
// because we never block on the budget check — eviction runs out of
// band after each tick.
func (f *ImageFetcher) evictIfOverBudget() error {
	var total int64
	if err := f.db.QueryRow(`SELECT COALESCE(SUM(size), 0) FROM pos_image_cache`).Scan(&total); err != nil {
		return err
	}
	if total <= f.maxBudget {
		return nil
	}
	// Delete oldest-referenced rows until under budget.
	rows, err := f.db.Query(`
		SELECT url_hash, size FROM pos_image_cache
		ORDER BY last_referenced_at ASC`)
	if err != nil {
		return err
	}
	type victim struct {
		hash string
		size int64
	}
	victims := []victim{}
	for rows.Next() {
		var v victim
		if err := rows.Scan(&v.hash, &v.size); err != nil {
			rows.Close()
			return err
		}
		victims = append(victims, v)
	}
	rows.Close()

	remain := total
	for _, v := range victims {
		if remain <= f.maxBudget {
			break
		}
		if _, err := f.db.Exec(`DELETE FROM pos_image_cache WHERE url_hash = ?`, v.hash); err != nil {
			return err
		}
		remain -= v.size
	}
	if remain != total {
		slog.Info("image_fetcher evicted",
			"freed_bytes", total-remain,
			"remaining_bytes", remain)
	}
	return nil
}

// URLHash returns the SHA-256 hex digest of a URL — the cache key.
// Exposed so the handler can compute the same hash for serving.
func URLHash(url string) string {
	sum := sha256.Sum256([]byte(url))
	return hex.EncodeToString(sum[:])
}

// LookupCached returns the cached bytes + Content-Type for a URL hash.
// Bumps last_referenced_at at most once per touchInterval so eviction respects
// real traffic without turning every browser cache miss into a SQLite write.
// Returns ErrCacheMiss when the hash isn't cached so callers can branch
// without importing database/sql for the sentinel comparison.
func (f *ImageFetcher) LookupCached(hash string) ([]byte, string, error) {
	var (
		body []byte
		ct   string
	)
	err := f.db.QueryRow(`SELECT bytes, COALESCE(content_type, '') FROM pos_image_cache WHERE url_hash = ?`, hash).
		Scan(&body, &ct)
	if err != nil {
		// sql.ErrNoRows is the expected path on a clean miss; any
		// other error gets the same external signal because callers
		// can't recover from either (they just 404).
		return nil, "", ErrCacheMiss
	}
	f.touchReference(hash)
	return body, ct, nil
}

func (f *ImageFetcher) touchReference(hash string) {
	now := time.Now()
	f.touchMu.Lock()
	if last, ok := f.lastTouched[hash]; ok && now.Sub(last) < f.touchInterval {
		f.touchMu.Unlock()
		return
	}
	f.lastTouched[hash] = now
	f.touchMu.Unlock()

	if _, err := f.db.Exec(`UPDATE pos_image_cache SET last_referenced_at = datetime('now') WHERE url_hash = ?`, hash); err != nil {
		// Allow the next hit to retry; a failed touch must not suppress LRU
		// tracking for the whole cooldown window.
		f.touchMu.Lock()
		delete(f.lastTouched, hash)
		f.touchMu.Unlock()
	}
}

func isHTTP(u string) bool {
	return strings.HasPrefix(u, "http://") || strings.HasPrefix(u, "https://")
}

// ErrCacheMiss surfaces a clean "not cached" signal callers can branch
// on without importing database/sql.
var ErrCacheMiss = errors.New("image cache miss")
