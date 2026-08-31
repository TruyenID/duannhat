package service

// #1175 — manifest-driven sync DOWN ("revision-gated 304 + manifest").
//
// One GET /api/v1/workstation/sync-manifest per 5 s tick replaces the ~11
// unconditional full-payload GETs. Cloud returns an opaque manifest_version
// (also as an ETag header) plus a per-feed version map; the workstation sends
// If-None-Match with the stored version, so the idle steady state is a single
// 304 with an empty body.
//
// Contract (frozen 2026-07-28, built in parallel with the backend half):
//
//	GET /api/v1/workstation/sync-manifest        (device-token auth)
//	  → 200 {"data":{"manifest_version":"…","feeds":{"menu":"…", …}}}
//	    with header ETag:"<manifest_version>"
//	  → If-None-Match:"<manifest_version>" matches → 304, empty body
//
// Rules:
//   - 304 → nothing else pulls this tick (the common case).
//   - 200 → run the existing Pull* func for each feed whose version differs
//     from the stored KV (sync.feed_version.<feed>; missing = changed), and
//     stamp the new feed version ONLY after that feed's pull succeeded, so a
//     failing feed retries next tick without blocking the others.
//   - The manifest version itself is stored ONLY when every changed feed
//     succeeded — otherwise the next tick re-reads the same diff.
//   - Any manifest-endpoint failure (old Cloud 404, network, 5xx, malformed
//     body) → this tick falls back to the legacy full pull, logged once per
//     state change. New workstation + old Cloud keeps working unchanged.
//
// Feeds OUTSIDE the manifest contract keep their 5 s cadence via
// pullUnmanagedPos. Since #2712 that is three, not ten: till-sessions
// (Cloud-side force-abandon/expire, plan-032), effective-payment-options (a
// policy evaluation with no honest row-aggregate version) and customers
// (incremental cursor with a page cap). Every other replica feed now has a
// manifest feed version — see pullUnmanagedPos for the reasoning per feed.
//
// The kick channel lets the poke client (sync_poke.go) run the SAME manifest
// check early. A kick is a pure hint: debounced to ≥1 s after the last check,
// dropped when the loop is busy, and never required for correctness — the
// 5 s ticker remains the safety net.

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"sync"
	"time"
)

const (
	pullPathSyncManifest = "/api/v1/workstation/sync-manifest"

	// KV keys in the local `settings` table. Cleared on device pair
	// (handleDevicePair) so a fresh/re-pair always bootstraps a full pull
	// instead of 304-ing against a wiped replica.
	manifestVersionKey   = "sync.manifest.version"
	feedVersionKeyPrefix = "sync.feed_version."

	// kickDebounce drops poke kicks arriving within this window of the last
	// manifest check, collapsing a poke burst into one early check.
	kickDebounce = 1 * time.Second

	manifestFetchTimeout = 15 * time.Second
	manifestFeedTimeout  = 30 * time.Second
)

func feedVersionKey(feed string) string { return feedVersionKeyPrefix + feed }

// errManifestNotPaired marks the boot-time "no device token yet" state —
// neither the manifest nor the legacy pulls could do anything, so the tick is
// a silent no-op (mirrors cloudGet's empty-token behavior).
var errManifestNotPaired = errors.New("workstation not paired")

type syncManifest struct {
	Version string
	Feeds   map[string]string
}

// manifestFeed binds one frozen-contract feed name to its existing Pull*
// func. trace preserves the pre-#1175 tracedPull telemetry name.
type manifestFeed struct {
	name  string
	trace string
	pull  func(context.Context) error
}

// manifestFeeds is the feed → Pull* mapping of the frozen contract. Order is
// the legacy pull order (fast → settings → slow) so relative feed freshness
// within a tick is unchanged. Kitchen orders are deliberately absent —
// realtime domain, own 5 s loop.
func (p *SyncPuller) manifestFeeds() []manifestFeed {
	return []manifestFeed{
		{"zones", "zones", p.PullZones},
		{"tables", "tables", p.PullTables},
		{"menu", "menu", p.PullMenu},
		{"handy_menu", "handy_menu", p.PullHandyMenu},
		{"branch_settings", "branch", p.PullBranch},
		{"lots", "lots", p.PullLots},
		{"menu_catalog", "menu_catalog", p.PullMenuCatalog},
		{"menu_schedules", "menu_schedules", p.PullMenuSchedules},
		{"promotions", "promotions", p.PullPromotions},
		{"coupons", "coupons", p.PullCoupons},
		{"staff", "staff", p.PullStaff},
		// #2712 — appended. The first three had NO caller in manifest mode:
		// their only call site was the legacy full pull, so a shop running
		// against a Cloud that serves the manifest (i.e. all of them) never
		// pulled a new print template, a new print image, or the expected
		// build at all. The rest were pulled unconditionally every 5 s by
		// pullUnmanagedPos; they are static masters and belong behind the gate.
		{"print_templates", "print_templates", p.PullPrintTemplates},
		{"print_images", "print_images", p.PullPrintImages},
		{"expected_build", "expected_build", p.PullExpectedBuild},
		{"payment_methods", "payment_methods", p.PullPaymentMethods},
		{"peripheral_devices", "peripheral_devices", p.PullPeripheralDevices},
		{"printers", "printers", p.PullPrinters},
		{"till", "till", p.PullTill},
		{"till_denominations", "denominations", p.PullDenominations},
		{"tender_categories", "tender_categories", p.PullTenderCategories},
		{"tender_types", "tender_types", p.PullTenderTypes},
	}
}

// Kick requests an early manifest check. Non-blocking + coalescing (buffered
// 1): safe to call from any goroutine at any rate; a dropped kick only means
// a check is already pending. This is the poke client's ONLY entry point into
// the pull side.
func (p *SyncPuller) Kick() {
	select {
	case p.kickCh <- struct{}{}:
	default:
	}
	if p.kitchenKickCh == nil {
		return
	}
	select {
	case p.kitchenKickCh <- struct{}{}:
	default:
	}
}

// loopWithKick executes fn immediately, then on every tick OR on a kick that
// arrives ≥kickDebounce after the last run. If fn takes longer than
// `interval`, the ticker queues missed ticks; we drain them after fn returns
// so the next iteration waits a full interval instead of firing back-to-back
// (a hung Cloud + tight interval would otherwise hammer itself the moment
// Cloud recovers, multiplying retry pressure).
func (p *SyncPuller) loopWithKick(interval time.Duration, fn func()) {
	fn()
	last := time.Now()
	t := time.NewTicker(interval)
	defer t.Stop()
	drain := func() {
		for {
			select {
			case <-t.C:
			default:
				return
			}
		}
	}
	for {
		select {
		case <-p.stopCh:
			return
		case <-t.C:
			fn()
			last = time.Now()
			drain()
		case <-p.kickCh:
			if time.Since(last) < kickDebounce {
				continue
			}
			fn()
			last = time.Now()
			drain()
		}
	}
}

// manifestTick is one pull pass: manifest-gated feeds when Cloud supports the
// manifest, the full legacy pull when it doesn't.
func (p *SyncPuller) manifestTick() {
	if p.pullViaManifest() {
		// Manifest mode: the gated feeds are settled. The three feeds the
		// manifest contract does NOT cover keep their 5 s cadence regardless
		// of 304/200 — none of them has a feed version that could speak for
		// it honestly (see pullUnmanagedPos).
		p.pullUnmanagedPos()
		return
	}
	p.fullPullWithoutManifest()
}

// fullPullWithoutManifest reproduces the pre-#1175 tick: the three pull groups
// run concurrently (as their dedicated goroutines used to) so a slow endpoint in
// one group doesn't serialize the others. Used only when the manifest endpoint
// is unavailable.
//
// #2692 — tên cũ là `legacyFullPull`, và cái tên đó nói sai việc. Đây KHÔNG
// phải đường tương thích với build cũ: vế "old Cloud" của nó đã hết (route
// `/workstation/sync-manifest` có từ #1175, `backend/routes/api/workstation.php`),
// còn vế OUTAGE thì phải giữ vĩnh viễn — Cloud không trả lời được thì máy trạm
// vẫn phải kéo được dữ liệu. Đường chịu lỗi mang tên "legacy" là lời mời xoá
// nhầm; ruling #2188 cấm định danh này chính vì nó lẫn hai thứ khác hẳn nhau.
func (p *SyncPuller) fullPullWithoutManifest() {
	var wg sync.WaitGroup
	for _, fn := range []func(){p.pullFast, p.pullSettings, p.pullSlow} {
		wg.Add(1)
		go func() {
			defer wg.Done()
			fn()
		}()
	}
	wg.Wait()
}

// pullViaManifest runs one manifest-gated pull pass. Returns false ONLY when
// the manifest endpoint itself is unusable — the caller then falls back to
// the legacy full pull for this tick. Per-feed pull failures do NOT trip the
// fallback: the failing feed's version stays unstamped so it retries next
// tick, and the manifest version is stored only when every changed feed
// succeeded.
func (p *SyncPuller) pullViaManifest() bool {
	ctx, cancel := context.WithTimeout(context.Background(), manifestFetchTimeout)
	stored := p.getCursor(manifestVersionKey)
	m, notModified, err := p.fetchSyncManifest(ctx, stored)
	cancel()
	if errors.Is(err, errManifestNotPaired) {
		return true // no token — every legacy pull would no-op too
	}
	if err != nil {
		if !p.manifestDown {
			p.manifestDown = true
			slog.Warn("sync manifest unavailable — falling back to legacy full pull (old Cloud?)", "err", err)
		}
		return false
	}
	if p.manifestDown {
		p.manifestDown = false
		slog.Info("sync manifest available — manifest-driven pull active")
	}
	if notModified {
		return true // idle steady state: nothing changed anywhere
	}

	allOK := true
	for _, f := range p.manifestFeeds() {
		v, published := m.Feeds[f.name]
		if published && v != "" && v == p.getCursor(feedVersionKey(f.name)) {
			continue // feed unchanged since last successful pull
		}
		// Changed (or missing-KV = changed, or not published by this Cloud
		// = can't attest → pull to be safe). Per-feed timeout mirrors the
		// legacy per-group 30 s context.
		fctx, fcancel := context.WithTimeout(context.Background(), manifestFeedTimeout)
		pullErr := p.tracedPullErr(f.trace, func() error { return f.pull(fctx) })
		fcancel()
		if pullErr != nil {
			allOK = false
			continue
		}
		if published && v != "" {
			// Stamp AFTER success only. Worst race: Cloud moved to a newer
			// version between manifest and pull — we stamp the older manifest
			// value while holding newer data, so the next tick re-pulls once
			// and converges. Never the other way around.
			if err := p.setCursor(feedVersionKey(f.name), v); err != nil {
				slog.Warn("stamp feed version", "feed", f.name, "err", err)
				allOK = false
			}
		}
	}
	if allOK {
		if err := p.setCursor(manifestVersionKey, m.Version); err != nil {
			slog.Warn("stamp manifest version", "err", err)
		}
	}
	return true
}

// fetchSyncManifest performs the conditional GET. Returns notModified=true on
// a 304. Every other non-2xx (including the old-Cloud 404), transport error,
// or malformed body is an error → legacy fallback.
func (p *SyncPuller) fetchSyncManifest(ctx context.Context, ifNoneMatch string) (*syncManifest, bool, error) {
	token := ""
	if p.tokenFn != nil {
		token = p.tokenFn()
	}
	if token == "" {
		return nil, false, errManifestNotPaired
	}
	baseURL := p.resolveURL()
	if baseURL == "" {
		return nil, false, fmt.Errorf("cloud URL not configured")
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, baseURL+pullPathSyncManifest, nil)
	if err != nil {
		return nil, false, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)
	if ifNoneMatch != "" {
		req.Header.Set("If-None-Match", `"`+ifNoneMatch+`"`)
	}

	resp, err := p.httpClient.Do(req)
	if err != nil {
		return nil, false, fmt.Errorf("cloud GET %s: %w", pullPathSyncManifest, err)
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))

	switch {
	case resp.StatusCode == http.StatusNotModified:
		return nil, true, nil
	case resp.StatusCode == http.StatusUnauthorized:
		if p.onUnauthorized != nil {
			p.onUnauthorized()
		}
		return nil, false, fmt.Errorf("cloud 401 on %s: %s", pullPathSyncManifest, string(body))
	case resp.StatusCode < 200 || resp.StatusCode >= 300:
		return nil, false, fmt.Errorf("cloud %d on %s: %s", resp.StatusCode, pullPathSyncManifest, string(body))
	}

	var payload struct {
		Data struct {
			ManifestVersion string            `json:"manifest_version"`
			Feeds           map[string]string `json:"feeds"`
		} `json:"data"`
	}
	if err := json.Unmarshal(body, &payload); err != nil {
		return nil, false, fmt.Errorf("decode sync-manifest: %w", err)
	}
	if payload.Data.ManifestVersion == "" {
		return nil, false, fmt.Errorf("sync-manifest missing manifest_version")
	}
	return &syncManifest{Version: payload.Data.ManifestVersion, Feeds: payload.Data.Feeds}, false, nil
}
