package handler

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestRateLimiter_AllowsBurstThenBlocks(t *testing.T) {
	// 60/min, burst 3 — first 3 hits pass, 4th must 429.
	p := newRateLimiterPool(60, 3)
	t.Cleanup(p.Stop)
	h := p.Middleware(dummyHandler)

	for i := 0; i < 3; i++ {
		req := httptest.NewRequest("POST", "/", nil)
		req.RemoteAddr = "192.168.1.10:5040"
		rec := httptest.NewRecorder()
		h.ServeHTTP(rec, req)
		if rec.Code != http.StatusOK {
			t.Fatalf("burst hit %d: expected 200, got %d", i+1, rec.Code)
		}
	}

	// 4th immediate hit is over budget.
	req := httptest.NewRequest("POST", "/", nil)
	req.RemoteAddr = "192.168.1.10:5040"
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	if rec.Code != http.StatusTooManyRequests {
		t.Errorf("over-budget: expected 429, got %d", rec.Code)
	}
	if rec.Header().Get("Retry-After") == "" {
		t.Errorf("expected Retry-After header on 429")
	}
}

func TestRateLimiter_PerIPIsolation(t *testing.T) {
	// One IP burning the budget must not throttle another IP.
	p := newRateLimiterPool(60, 1)
	t.Cleanup(p.Stop)
	h := p.Middleware(dummyHandler)

	// IP A exhausts its budget.
	for i := 0; i < 5; i++ {
		req := httptest.NewRequest("POST", "/", nil)
		req.RemoteAddr = "192.168.1.10:5040"
		h.ServeHTTP(httptest.NewRecorder(), req)
	}

	// IP B's first hit must still pass.
	req := httptest.NewRequest("POST", "/", nil)
	req.RemoteAddr = "192.168.1.20:5040"
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Errorf("IP B should be independent: expected 200, got %d", rec.Code)
	}
}

func TestRateLimiter_IgnoresXForwardedFor(t *testing.T) {
	// A LAN client must not be able to lie about its IP by setting XFF —
	// the pool keys on RemoteAddr, never on the header. Confirm by burning
	// one IP's budget then sending the next request with a fake XFF
	// claiming to be a different IP.
	p := newRateLimiterPool(60, 1)
	t.Cleanup(p.Stop)
	h := p.Middleware(dummyHandler)

	for i := 0; i < 5; i++ {
		req := httptest.NewRequest("POST", "/", nil)
		req.RemoteAddr = "192.168.1.10:5040"
		h.ServeHTTP(httptest.NewRecorder(), req)
	}

	req := httptest.NewRequest("POST", "/", nil)
	req.RemoteAddr = "192.168.1.10:5040"
	req.Header.Set("X-Forwarded-For", "192.168.1.99")
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	if rec.Code != http.StatusTooManyRequests {
		t.Errorf("XFF spoofing must not reset budget: expected 429, got %d", rec.Code)
	}
}

func TestRateLimiter_StopIdempotent(t *testing.T) {
	p := newRateLimiterPool(60, 5)
	p.Stop()
	p.Stop() // second Stop must not panic on double close(stopCh)
}
