package service

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// Regression: with pullIntervalSlow at 5 s and 12 pullers fanning out
// inside pullSlowPos, the per-device throttle on Cloud's
// /api/v1/workstation/* route group must accommodate ~150 req/min OR
// every pull silently 429s and the replica tables stay empty.
//
// We can't run the real throttle middleware here, but we can lock down
// that workstation Go SURFACES a 429 with a debuggable error message
// — pre-fix, the generic "cloud 429 on /path: ..." log was identical
// to a normal-failure message and the operator had no signal that the
// throttle was misconfigured.
func TestCloudGet_429SurfacesRateLimitedError(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Retry-After", "30")
		w.WriteHeader(http.StatusTooManyRequests)
		w.Write([]byte(`{"message":"Too Many Attempts."}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	err := p.PullDenominations(context.Background())
	if err == nil {
		t.Fatal("PullDenominations should surface an error on 429")
	}
	msg := err.Error()
	if !strings.Contains(msg, "429") {
		t.Errorf("error must mention 429: %q", msg)
	}
	if !strings.Contains(strings.ToLower(msg), "rate") {
		t.Errorf("error should mention rate-limited so it's grep-able: %q", msg)
	}
	if !strings.Contains(msg, "30") {
		t.Errorf("Retry-After value should be passed through for visibility: %q", msg)
	}
}

// 200 OK keeps working — make sure the new 429 branch didn't trample
// the happy path.
func TestCloudGet_200StillSucceeds(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":[{"id":"d1","currency_code":"JPY","value":"1000.00","kind":"note","label":"","sort_order":0,"is_active":true}]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullDenominations(context.Background()); err != nil {
		t.Fatalf("happy path must still work: %v", err)
	}
	var n int
	db.QueryRow("SELECT COUNT(*) FROM denominations").Scan(&n)
	if n != 1 {
		t.Errorf("want 1 row, got %d", n)
	}
}
