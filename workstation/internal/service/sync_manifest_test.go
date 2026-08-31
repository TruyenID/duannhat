package service

// #1175 — manifest-driven pull tests. Pin the frozen contract behavior:
// 304 = zero feed pulls; 200 = only changed feeds; per-feed stamp-on-success
// (a failing feed retries next tick without blocking the others); manifest
// version stored only on full success; and old-Cloud 404 → legacy full pull
// (deploy-order independence).

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
)

// manifestFeedPathByFeed maps the frozen manifest feed names to the Cloud
// endpoints their Pull* funcs hit.
var manifestFeedPathByFeed = map[string]string{
	"zones":           "/api/v1/tms/zones",
	"tables":          "/api/v1/tms/tables",
	"menu":            "/api/v1/workstation/menu",
	"handy_menu":      "/api/v1/workstation/menu/handy",
	"branch_settings": "/api/v1/workstation/branch",
	"lots":            "/api/v1/workstation/lots",
	"menu_catalog":    "/api/v1/workstation/menu-catalog",
	"menu_schedules":  "/api/v1/workstation/menu-schedules",
	"promotions":      "/api/v1/workstation/menu-promotions",
	"coupons":         "/api/v1/workstation/coupons",
	"staff":           "/api/v1/workstation/staff",
	// #2712 — appended feeds. The first three had no caller at all in manifest
	// mode; the rest were polled unconditionally every 5 s.
	"print_templates":    "/api/v1/workstation/print-templates",
	"print_images":       "/api/v1/workstation/print-images",
	"expected_build":     "/api/v1/workstation/expected-build",
	"payment_methods":    "/api/v1/workstation/payment-methods",
	"peripheral_devices": "/api/v1/workstation/peripheral-devices",
	"printers":           "/api/v1/workstation/printers",
	"till":               "/api/v1/workstation/till",
	"till_denominations": "/api/v1/workstation/till-denominations",
	"tender_categories":  "/api/v1/workstation/till-tender-categories",
	"tender_types":       "/api/v1/workstation/till-tender-types",
}

// ungatedPaths keep their 5 s cadence on purpose — a 304 tick MUST still hit
// them (see pullUnmanagedPos).
var ungatedPaths = []string{
	"/api/v1/workstation/till-sessions/active",
	"/api/v1/workstation/effective-payment-options/matrix",
	"/api/v1/workstation/customers",
}

// feedCannedResponse serves a minimal valid body for every pull endpoint.
func feedCannedResponse(path string) string {
	switch path {
	case "/api/v1/workstation/lots":
		return `{"lots":[],"generated_at":"2026-01-01T00:00:00Z"}`
	case "/api/v1/workstation/menu", "/api/v1/workstation/menu/handy", "/api/v1/workstation/branch":
		return `{"data":null}`
	case "/api/v1/workstation/menu-catalog":
		return `{"data":{"menus":[]}}`
	case "/api/v1/workstation/till":
		return `{"data":{"id":"t1","branch_id":"b1","code":"MAIN","default_currency_code":"JPY"}}`
	case "/api/v1/workstation/expected-build":
		return `{"data":{"version":null}}`
	default:
		return `{"data":[]}`
	}
}

// manifestCloud is a fake Cloud: counts hits per path, serves the manifest
// endpoint (with If-None-Match handling) and canned feed bodies. failPaths
// entries return 500.
type manifestCloud struct {
	mu        sync.Mutex
	hits      map[string]int
	manifest  func(w http.ResponseWriter, r *http.Request) // nil → 404 (old Cloud)
	failPaths map[string]bool
	srv       *httptest.Server
}

func newManifestCloud(t *testing.T) *manifestCloud {
	t.Helper()
	c := &manifestCloud{hits: map[string]int{}, failPaths: map[string]bool{}}
	c.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c.mu.Lock()
		c.hits[r.URL.Path]++
		fail := c.failPaths[r.URL.Path]
		mf := c.manifest
		c.mu.Unlock()

		if r.URL.Path == pullPathSyncManifest {
			if mf == nil {
				w.WriteHeader(http.StatusNotFound)
				return
			}
			mf(w, r)
			return
		}
		if fail {
			w.WriteHeader(http.StatusInternalServerError)
			return
		}
		w.Write([]byte(feedCannedResponse(r.URL.Path)))
	}))
	t.Cleanup(c.srv.Close)
	return c
}

func (c *manifestCloud) hitCount(path string) int {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.hits[path]
}

func (c *manifestCloud) setFail(path string, fail bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.failPaths[path] = fail
}

func (c *manifestCloud) setManifest(fn func(w http.ResponseWriter, r *http.Request)) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.manifest = fn
}

func (c *manifestCloud) allHits() map[string]int {
	c.mu.Lock()
	defer c.mu.Unlock()
	out := make(map[string]int, len(c.hits))
	for k, v := range c.hits {
		out[k] = v
	}

	return out
}

func (c *manifestCloud) resetHits() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.hits = map[string]int{}
}

// serveManifest builds a manifest handler for the given version + feeds that
// honours If-None-Match with a 304.
func serveManifest(version string, feeds map[string]string) func(w http.ResponseWriter, r *http.Request) {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("If-None-Match") == `"`+version+`"` {
			w.WriteHeader(http.StatusNotModified)
			return
		}
		body, _ := json.Marshal(map[string]any{
			"data": map[string]any{"manifest_version": version, "feeds": feeds},
		})
		w.Header().Set("ETag", `"`+version+`"`)
		w.Write(body)
	}
}

// allFeedsAt returns a manifest feed map with every frozen feed at version v.
func allFeedsAt(v string) map[string]string {
	m := map[string]string{}
	for feed := range manifestFeedPathByFeed {
		m[feed] = v
	}
	return m
}

func stampAllFeeds(t *testing.T, p *SyncPuller, v string) {
	t.Helper()
	for feed := range manifestFeedPathByFeed {
		if err := p.setCursor(feedVersionKey(feed), v); err != nil {
			t.Fatalf("stamp %s: %v", feed, err)
		}
	}
}

func Test_Manifest304_RunsZeroFeedPulls(t *testing.T) {
	cloud := newManifestCloud(t)
	cloud.setManifest(serveManifest("v1", allFeedsAt("1")))

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.srv.URL, staticTokenFn("WS"))
	if err := p.setCursor(manifestVersionKey, "v1"); err != nil {
		t.Fatal(err)
	}

	p.manifestTick()

	if got := cloud.hitCount(pullPathSyncManifest); got != 1 {
		t.Errorf("expected exactly 1 manifest GET, got %d", got)
	}
	for feed, path := range manifestFeedPathByFeed {
		if n := cloud.hitCount(path); n != 0 {
			t.Errorf("304 tick must not pull feed %s (%s), hit %d times", feed, path, n)
		}
	}
	// Deliberate deviation from "nothing else runs": the three feeds that have
	// no honest manifest version keep their 5 s cadence (#2712 cut this list
	// from ten to three — see pullUnmanagedPos).
	for _, path := range ungatedPaths {
		if cloud.hitCount(path) == 0 {
			t.Errorf("ungated feed %s must still pull on a 304 tick", path)
		}
	}
	// …and nothing else does. Every static master is behind the gate now, so
	// an idle tick is 1 manifest GET + these three, not 1 + ten.
	for path, n := range cloud.allHits() {
		if path == pullPathSyncManifest || contains(ungatedPaths, path) {
			continue
		}
		if path == pullPathEffectivePaymentOptions {
			continue // legacy flat fallback, only reached if the matrix 404s
		}
		t.Errorf("304 tick hit %s %d times — it must be manifest-gated or listed as ungated", path, n)
	}
}

func contains(hay []string, needle string) bool {
	for _, h := range hay {
		if h == needle {
			return true
		}
	}

	return false
}

func Test_Manifest200_PullsOnlyChangedFeed(t *testing.T) {
	feeds := allFeedsAt("1")
	feeds["coupons"] = "2"

	cloud := newManifestCloud(t)
	cloud.setManifest(serveManifest("v2", feeds))

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.srv.URL, staticTokenFn("WS"))
	if err := p.setCursor(manifestVersionKey, "v1"); err != nil {
		t.Fatal(err)
	}
	stampAllFeeds(t, p, "1")

	p.manifestTick()

	for feed, path := range manifestFeedPathByFeed {
		want := 0
		if feed == "coupons" {
			want = 1
		}
		if n := cloud.hitCount(path); n != want {
			t.Errorf("feed %s: want %d pulls, got %d", feed, want, n)
		}
	}
	if got := p.getCursor(feedVersionKey("coupons")); got != "2" {
		t.Errorf("coupons feed version must be stamped to 2, got %q", got)
	}
	if got := p.getCursor(manifestVersionKey); got != "v2" {
		t.Errorf("manifest version must advance to v2, got %q", got)
	}
}

// #2712 B — print_images had a PHP feed version since #1957 but no entry in
// manifestFeeds(), so HQ publishing a new logo reached nobody: the only caller
// of PullPrintImages was the legacy full pull. Same story for print_templates
// and expected_build.
func Test_Manifest200_WiresTheFormerlyLegacyOnlyFeeds(t *testing.T) {
	for _, feed := range []string{"print_images", "print_templates", "expected_build"} {
		t.Run(feed, func(t *testing.T) {
			feeds := allFeedsAt("1")
			feeds[feed] = "2"

			cloud := newManifestCloud(t)
			cloud.setManifest(serveManifest("v2", feeds))

			db := newPullerTestDB(t)
			p := NewSyncPuller(db, cloud.srv.URL, staticTokenFn("WS"))
			if err := p.setCursor(manifestVersionKey, "v1"); err != nil {
				t.Fatal(err)
			}
			stampAllFeeds(t, p, "1")

			p.manifestTick()

			for other, path := range manifestFeedPathByFeed {
				want := 0
				if other == feed {
					want = 1
				}
				if n := cloud.hitCount(path); n != want {
					t.Errorf("feed %s: want %d pulls, got %d", other, want, n)
				}
			}
			if got := p.getCursor(feedVersionKey(feed)); got != "2" {
				t.Errorf("%s feed version must be stamped to 2, got %q", feed, got)
			}
		})
	}
}

func Test_Manifest_MissingFeedKV_MeansChanged(t *testing.T) {
	cloud := newManifestCloud(t)
	cloud.setManifest(serveManifest("v1", allFeedsAt("1")))

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.srv.URL, staticTokenFn("WS"))
	// No stored manifest version, no stored feed versions → bootstrap:
	// every feed pulls once, then everything is stamped.
	p.manifestTick()

	for feed, path := range manifestFeedPathByFeed {
		if n := cloud.hitCount(path); n != 1 {
			t.Errorf("bootstrap: feed %s want 1 pull, got %d", feed, n)
		}
		if got := p.getCursor(feedVersionKey(feed)); got != "1" {
			t.Errorf("bootstrap: feed %s must be stamped to 1, got %q", feed, got)
		}
	}
	if got := p.getCursor(manifestVersionKey); got != "v1" {
		t.Errorf("bootstrap: manifest version must be stored, got %q", got)
	}

	// Second tick: 304 → no feed pulls.
	cloud.resetHits()
	p.manifestTick()
	for feed, path := range manifestFeedPathByFeed {
		if n := cloud.hitCount(path); n != 0 {
			t.Errorf("post-bootstrap 304 tick must not pull %s, hit %d", feed, n)
		}
	}
}

func Test_Manifest_PerFeedStampOnSuccess_FailingFeedRetries(t *testing.T) {
	feeds := allFeedsAt("1")
	feeds["zones"] = "2"
	feeds["coupons"] = "2"

	cloud := newManifestCloud(t)
	cloud.setManifest(serveManifest("v2", feeds))
	cloud.setFail("/api/v1/workstation/coupons", true)

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.srv.URL, staticTokenFn("WS"))
	if err := p.setCursor(manifestVersionKey, "v1"); err != nil {
		t.Fatal(err)
	}
	stampAllFeeds(t, p, "1")

	// Tick 1: zones succeeds and is stamped; coupons fails and is NOT
	// stamped; the manifest version must NOT advance.
	p.manifestTick()
	if got := p.getCursor(feedVersionKey("zones")); got != "2" {
		t.Errorf("zones must be stamped despite coupons failing, got %q", got)
	}
	if got := p.getCursor(feedVersionKey("coupons")); got != "2" {
		if got != "1" {
			t.Errorf("coupons stamp unexpected: %q", got)
		}
	} else {
		t.Errorf("coupons must NOT be stamped on a failed pull")
	}
	if got := p.getCursor(manifestVersionKey); got != "v1" {
		t.Errorf("manifest version must stay v1 while a changed feed is failing, got %q", got)
	}

	// Tick 2 (Cloud healed): only coupons re-pulls; zones stays settled;
	// the manifest version now advances.
	cloud.setFail("/api/v1/workstation/coupons", false)
	cloud.resetHits()
	p.manifestTick()
	if n := cloud.hitCount("/api/v1/tms/zones"); n != 0 {
		t.Errorf("zones already stamped — must not re-pull, hit %d", n)
	}
	if n := cloud.hitCount("/api/v1/workstation/coupons"); n != 1 {
		t.Errorf("coupons must retry exactly once, hit %d", n)
	}
	if got := p.getCursor(feedVersionKey("coupons")); got != "2" {
		t.Errorf("coupons must be stamped after the retry, got %q", got)
	}
	if got := p.getCursor(manifestVersionKey); got != "v2" {
		t.Errorf("manifest version must advance once all changed feeds succeeded, got %q", got)
	}

	// Tick 3: steady state — 304, zero feed pulls.
	cloud.resetHits()
	p.manifestTick()
	for feed, path := range manifestFeedPathByFeed {
		if n := cloud.hitCount(path); n != 0 {
			t.Errorf("steady state must 304 with zero pulls; %s hit %d", feed, n)
		}
	}
}

func Test_ManifestUnavailable_FallsBackToFullPullWithoutManifest(t *testing.T) {
	cloud := newManifestCloud(t) // manifest = nil → 404 like an old Cloud

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.srv.URL, staticTokenFn("WS"))

	p.manifestTick()

	if !p.manifestDown {
		t.Errorf("manifestDown must be set after a 404 (log-once state)")
	}
	// The legacy full pull must hit every feed — manifest-gated AND not.
	legacyMustHit := []string{
		"/api/v1/tms/zones",
		"/api/v1/tms/tables",
		"/api/v1/workstation/menu",
		"/api/v1/workstation/menu/handy",
		"/api/v1/workstation/branch",
		"/api/v1/workstation/lots",
		"/api/v1/workstation/payment-methods",
		"/api/v1/workstation/customers",
		"/api/v1/workstation/menu-schedules",
		"/api/v1/workstation/till",
		"/api/v1/workstation/till-sessions/active",
		"/api/v1/workstation/till-denominations",
		"/api/v1/workstation/till-tender-categories",
		"/api/v1/workstation/till-tender-types",
		"/api/v1/workstation/coupons",
		"/api/v1/workstation/menu-promotions",
		"/api/v1/workstation/menu-catalog",
		"/api/v1/workstation/staff",
	}
	for _, path := range legacyMustHit {
		if cloud.hitCount(path) == 0 {
			t.Errorf("legacy fallback missing %s", path)
		}
	}

	// Cloud upgrades mid-flight: the next tick recovers to manifest mode.
	cloud.setManifest(serveManifest("v1", allFeedsAt("1")))
	cloud.resetHits()
	p.manifestTick()
	if p.manifestDown {
		t.Errorf("manifestDown must clear once the endpoint appears")
	}
	if got := p.getCursor(manifestVersionKey); got != "v1" {
		t.Errorf("manifest version must be adopted after recovery, got %q", got)
	}
}

func Test_ManifestVersion_NotStoredWhenStampWouldLie(t *testing.T) {
	// Malformed manifest (missing manifest_version) must be treated as an
	// endpoint failure → legacy fallback, nothing stamped.
	cloud := newManifestCloud(t)
	cloud.setManifest(func(w http.ResponseWriter, r *http.Request) {
		fmt.Fprint(w, `{"data":{"feeds":{}}}`)
	})

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.srv.URL, staticTokenFn("WS"))
	p.manifestTick()

	if got := p.getCursor(manifestVersionKey); got != "" {
		t.Errorf("malformed manifest must not be stored, got %q", got)
	}
	if !p.manifestDown {
		t.Errorf("malformed manifest must count as endpoint-down (fallback)")
	}
	if cloud.hitCount("/api/v1/tms/zones") == 0 {
		t.Errorf("malformed manifest must fall back to the legacy pull")
	}
}

func Test_Manifest_NotPaired_TickIsSilentNoop(t *testing.T) {
	cloud := newManifestCloud(t)
	cloud.setManifest(serveManifest("v1", allFeedsAt("1")))

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.srv.URL, staticTokenFn("")) // no device token

	p.manifestTick()

	if got := cloud.hitCount(pullPathSyncManifest); got != 0 {
		t.Errorf("unpaired workstation must not hit the manifest endpoint, got %d", got)
	}
	if p.manifestDown {
		t.Errorf("unpaired is not a fallback state")
	}
}
