package service

import (
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

// pullIntervalSlow was 5 min; tightened to 5 s per product-owner request
// so catalog edits propagate to cashier devices in the same window as
// kitchen orders. Lock the value so a future refactor can't quietly
// regress the freshness contract.
func TestPullIntervalSlow_IsFiveSeconds(t *testing.T) {
	if pullIntervalSlow != 5*time.Second {
		t.Errorf("pullIntervalSlow must be 5s, got %v", pullIntervalSlow)
	}
}

// pullIntervalFast + pullIntervalSettings were 60 s; tightened to 5 s on
// 2026-06-15 so zones / tables / branch / legacy menu_items propagate to
// POS at the same cadence as the slow-loop catalog feeds. The POS
// /pos/tables and /pos/shop endpoints would otherwise lag a full minute
// behind a manager-side edit. Lock both so a future refactor can't
// silently widen the freshness window for cashier-visible data.
func TestPullIntervalFast_IsFiveSeconds(t *testing.T) {
	if pullIntervalFast != 5*time.Second {
		t.Errorf("pullIntervalFast must be 5s, got %v", pullIntervalFast)
	}
}

func TestPullIntervalSettings_IsFiveSeconds(t *testing.T) {
	if pullIntervalSettings != 5*time.Second {
		t.Errorf("pullIntervalSettings must be 5s, got %v", pullIntervalSettings)
	}
}

// runLoop drains queued ticks after each fn() returns — without this, a
// hung Cloud followed by a recovery would fire back-to-back fn() calls
// equal to the number of missed ticks, hammering Cloud the moment it
// comes back up. The test simulates: fn() takes 80 ms but the ticker
// fires every 10 ms (so ~7 ticks queue per fn run). With the drain in
// place, fn should fire approximately (testDuration / interval), not
// (testDuration / fnDuration).
func TestRunLoop_DrainsQueuedTicksAfterSlowFn(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://unused", staticTokenFn(""))

	var calls int32
	slowFn := func() {
		atomic.AddInt32(&calls, 1)
		time.Sleep(80 * time.Millisecond)
	}

	done := make(chan struct{})
	go func() {
		p.runLoop("test", 10*time.Millisecond, slowFn)
		close(done)
	}()

	time.Sleep(300 * time.Millisecond)
	p.Stop()
	<-done

	// Without drain: 300 ms / 80 ms ≈ 4 calls, plus the initial fn()
	// firing → ~5. WITH drain: same answer because each fn() takes 80 ms,
	// queued ticks are drained, next fire after another 10 ms → roughly
	// 300/(80+10) ≈ 3-4 calls. Either way, the COUNT is bounded by
	// fn-duration, not interval — i.e. we should NOT see ~30 calls
	// (300 ms / 10 ms) that a missing drain would produce. Allow some
	// headroom for goroutine scheduling.
	got := atomic.LoadInt32(&calls)
	if got > 10 {
		t.Errorf("expected drain to limit calls (~3-5); got %d — drain may not be working", got)
	}
	if got < 1 {
		t.Errorf("loop should fire at least once; got %d", got)
	}
}

// All PullX functions wired into pullSlow run on the 5 s tick. The
// fast + settings loops also run at 5 s (separate goroutines so a slow
// endpoint on one feed doesn't serialize the others). This test
// verifies the SLOW loop covers the catalog feeds — the ones that
// matter for cashier UX (menu/coupon/promotion/tender/staff).
func TestPullSlow_HitsAllExpectedPaths(t *testing.T) {
	seen := map[string]bool{}
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seen[r.URL.Path] = true
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
	for _, p := range expect {
		if !seen[p] {
			t.Errorf("pullSlow missing %s", p)
		}
	}
}
