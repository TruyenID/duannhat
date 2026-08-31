package service

import (
	"net/http"
	"net/http/httptest"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// #1175 — the manifest tick replaced the three per-loop 5 s tickers, but the
// 5 s FRESHNESS SLA is unchanged (2026-06-15 product decision: cloud edits
// must reach cashier devices within the same window as kitchen orders). Lock
// the value so a future refactor can't quietly regress the freshness
// contract — a manifest 304 is nearly free, so there is no load excuse to
// widen this.
func TestPullIntervalManifest_IsFiveSeconds(t *testing.T) {
	if pullIntervalManifest != 5*time.Second {
		t.Errorf("pullIntervalManifest must be 5s, got %v", pullIntervalManifest)
	}
}

// The kitchen orders loop is the realtime domain and is deliberately NOT
// manifest-gated (#1175). Pin its cadence too.
func TestPullIntervalKitchen_IsFiveSeconds(t *testing.T) {
	if pullIntervalKitchen != 5*time.Second {
		t.Errorf("pullIntervalKitchen must be 5s, got %v", pullIntervalKitchen)
	}
}

// loopWithKick drains queued ticks after each fn() returns — without this, a
// hung Cloud followed by a recovery would fire back-to-back fn() calls equal
// to the number of missed ticks, hammering Cloud the moment it comes back
// up. The test simulates: fn() takes 80 ms but the ticker fires every 10 ms
// (so ~7 ticks queue per fn run). With the drain in place, fn should fire
// approximately (testDuration / interval), not (testDuration / fnDuration).
func TestLoopWithKick_DrainsQueuedTicksAfterSlowFn(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://unused", staticTokenFn(""))

	var calls int32
	slowFn := func() {
		atomic.AddInt32(&calls, 1)
		time.Sleep(80 * time.Millisecond)
	}

	done := make(chan struct{})
	go func() {
		p.loopWithKick(10*time.Millisecond, slowFn)
		close(done)
	}()

	time.Sleep(300 * time.Millisecond)
	p.Stop()
	<-done

	// Without drain: ~30 calls (300 ms / 10 ms). WITH drain the count is
	// bounded by fn-duration: roughly 300/(80+10) ≈ 3-5 calls. Allow
	// headroom for goroutine scheduling.
	got := atomic.LoadInt32(&calls)
	if got > 10 {
		t.Errorf("expected drain to limit calls (~3-5); got %d — drain may not be working", got)
	}
	if got < 1 {
		t.Errorf("loop should fire at least once; got %d", got)
	}
}

// A kick runs the same fn early — but kicks within kickDebounce of the last
// run are dropped (a poke burst collapses into at most one early check; the
// periodic ticker remains the safety net for a dropped kick).
func TestLoopWithKick_KickTriggersEarlyRunWithDebounce(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://unused", staticTokenFn(""))

	var calls int32
	fn := func() { atomic.AddInt32(&calls, 1) }

	done := make(chan struct{})
	go func() {
		p.loopWithKick(time.Hour, fn) // ticker effectively never fires
		close(done)
	}()
	defer func() { p.Stop(); <-done }()

	// Initial immediate pass.
	waitForInt32(t, &calls, 1, 2*time.Second, "initial fn() pass")

	// A kick inside the debounce window is dropped.
	p.Kick()
	time.Sleep(200 * time.Millisecond)
	if got := atomic.LoadInt32(&calls); got != 1 {
		t.Fatalf("kick within debounce must be dropped; calls = %d", got)
	}

	// After the debounce window a kick runs fn early (the ticker is 1h away).
	time.Sleep(kickDebounce)
	p.Kick()
	waitForInt32(t, &calls, 2, 2*time.Second, "kick-triggered early run")
}

// waitForInt32 polls an atomic counter until it reaches want or the deadline
// expires.
func waitForInt32(t *testing.T, v *int32, want int32, timeout time.Duration, what string) {
	t.Helper()
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		if atomic.LoadInt32(v) >= want {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatalf("timed out waiting for %s (want %d, got %d)", what, want, atomic.LoadInt32(v))
}

// All PullX functions wired into pullSlow run when the tick is on the LEGACY
// fallback path (#1175: old Cloud without the manifest endpoint). This test
// verifies the slow group still covers the catalog feeds — the ones that
// matter for cashier UX (menu/coupon/promotion/tender/staff) — so the
// manifest refactor can't silently drop a feed from the fallback.
func TestPullSlow_HitsAllExpectedPaths(t *testing.T) {
	var mu sync.Mutex
	seen := map[string]bool{}
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		seen[r.URL.Path] = true
		mu.Unlock()
		switch r.URL.Path {
		case "/api/v1/workstation/till":
			w.Write([]byte(`{"data":{"id":"t1","branch_id":"b1","code":"MAIN","default_currency_code":"JPY"}}`))
		case "/api/v1/workstation/lots":
			w.Write([]byte(`{"lots":[],"generated_at":"2026-01-01T00:00:00Z"}`))
		default:
			w.Write([]byte(`{"data":[]}`))
		}
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	p.pullSlow()

	expect := []string{
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
	mu.Lock()
	defer mu.Unlock()
	for _, p := range expect {
		if !seen[p] {
			t.Errorf("pullSlow missing %s", p)
		}
	}
}
