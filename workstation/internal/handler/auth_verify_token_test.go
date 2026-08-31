package handler

// Tests for AuthMiddleware.VerifyToken — the cache + stale-fallback
// ladder that the WS handshake now reuses. Previously the WS path
// called raw CloudVerifier.Verify, which meant a Cloud outage cut
// every LAN realtime channel even though HTTP stayed up via stale
// cache. These tests pin the three branches:
//   1. fresh cache hit → no Cloud round-trip
//   2. cache miss + Cloud OK → cache populated + returned identity
//   3. cache stale + Cloud unreachable → degraded mode returns stale

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/domain"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// stubMWVerifier emulates service.CloudVerifier without HTTP. Tests
// drive its mode bit to simulate Cloud OK / Cloud unreachable / token
// invalid.
type stubMWVerifier struct {
	id  *service.Identity
	err error
}

func (s *stubMWVerifier) Verify(_ context.Context, _ string) (*service.Identity, error) {
	return s.id, s.err
}

type blockingMWVerifier struct {
	calls    atomic.Int32
	release  <-chan struct{}
	identity *service.Identity
	err      error
}

func (s *blockingMWVerifier) Verify(ctx context.Context, _ string) (*service.Identity, error) {
	s.calls.Add(1)
	select {
	case <-ctx.Done():
		return nil, fmt.Errorf("%w: %v", service.ErrCloudUnreachable, ctx.Err())
	case <-s.release:
		return s.identity, s.err
	}
}

func newAuthMWForTest(t *testing.T, ttl time.Duration) (*AuthMiddleware, *service.AuthCacheStore, *stubMWVerifier) {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "auth.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	cache := service.NewAuthCacheStore(db, ttl)
	stub := &stubMWVerifier{}
	branchFn := func() string { return "branch-A" }
	mw := newAuthMiddlewareWithVerifier(cache, stub, branchFn, nil)
	return mw, cache, stub
}

func TestVerifyToken_FreshCacheHitSkipsCloud(t *testing.T) {
	mw, cache, stub := newAuthMWForTest(t, 5*time.Minute)

	// Pre-populate cache with a fresh entry.
	hash := service.HashToken("good-token")
	id := &service.Identity{
		Type: domain.IdentityTypeDevice, DeviceID: "kds-1",
		DeviceType: "kds", BranchID: "branch-A",
	}
	if err := cache.PutIdentity(hash, id, ""); err != nil {
		t.Fatalf("seed cache: %v", err)
	}

	// If we call Cloud anyway, the stub's err setting would surface —
	// set it to an error so a leak would be detectable.
	stub.err = errors.New("Cloud should not be called")

	res, err := mw.VerifyToken(context.Background(), "good-token")
	if err != nil {
		t.Fatalf("expected success from cache, got %v", err)
	}
	if res.Stale {
		t.Errorf("expected Stale=false on fresh hit")
	}
	if res.Identity.DeviceID != "kds-1" {
		t.Errorf("identity not from cache: %+v", res.Identity)
	}
}

func TestVerifyToken_StaleCachePlusCloudUnreachableServes(t *testing.T) {
	// Use a 1ms TTL so seeded entries become stale immediately —
	// AuthCacheStore stores entries with VerifiedAt = now + ttl,
	// stale lookup matches anything past expiry that still exists
	// in the table. We sleep 5ms to cross the boundary.
	mw, cache, stub := newAuthMWForTest(t, 1*time.Millisecond)

	hash := service.HashToken("dead-token")
	if err := cache.PutIdentity(hash, &service.Identity{
		Type: domain.IdentityTypeDevice, DeviceID: "kds-stale",
		DeviceType: "kds", BranchID: "branch-A",
	}, ""); err != nil {
		t.Fatalf("seed stale (put): %v", err)
	}
	time.Sleep(5 * time.Millisecond) // crosses TTL → stale

	stub.err = service.ErrCloudUnreachable

	res, err := mw.VerifyToken(context.Background(), "dead-token")
	if err != nil {
		t.Fatalf("expected stale-fallback success, got %v", err)
	}
	if !res.Stale {
		t.Errorf("expected Stale=true (degraded mode)")
	}
	if res.Identity.DeviceID != "kds-stale" {
		t.Errorf("expected stale identity, got %+v", res.Identity)
	}
}

func newStatusAuthMWForTest(
	t *testing.T,
	status int,
	ttl time.Duration,
) (*AuthMiddleware, *service.AuthCacheStore) {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "auth-status.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		http.Error(w, "auth upstream unavailable", status)
	}))
	t.Cleanup(cloud.Close)

	cache := service.NewAuthCacheStore(db, ttl)
	verifier := service.NewCloudVerifier(func() string { return cloud.URL })
	mw := newAuthMiddlewareWithVerifier(cache, verifier, func() string { return "branch-A" }, nil)
	return mw, cache
}

func seedStaleAuthIdentity(t *testing.T, cache *service.AuthCacheStore, token string) {
	t.Helper()
	if err := cache.PutIdentity(service.HashToken(token), &service.Identity{
		Type: domain.IdentityTypeDevice, DeviceID: "pos-stale",
		DeviceType: "pos", BranchID: "branch-A",
	}, ""); err != nil {
		t.Fatalf("seed stale identity: %v", err)
	}
	time.Sleep(5 * time.Millisecond)
}

func TestVerifyToken_StaleCachePlusCloud503Serves(t *testing.T) {
	mw, cache := newStatusAuthMWForTest(t, http.StatusServiceUnavailable, time.Millisecond)
	seedStaleAuthIdentity(t, cache, "stale-503")

	result, err := mw.VerifyToken(context.Background(), "stale-503")

	if err != nil {
		t.Fatalf("503 must use bounded stale identity: %v", err)
	}
	if !result.Stale || result.Identity.DeviceID != "pos-stale" {
		t.Fatalf("result = %+v, want stale pos identity", result)
	}
	metrics := mw.Metrics()
	if metrics.UpstreamCalls != 1 || metrics.StaleFallbacks != 1 {
		t.Fatalf("metrics = %+v, want one upstream call and stale fallback", metrics)
	}
}

func TestAuthMiddleware_Cloud503WithStaleCacheSetsDegradedHeader(t *testing.T) {
	mw, cache := newStatusAuthMWForTest(t, http.StatusServiceUnavailable, time.Millisecond)
	seedStaleAuthIdentity(t, cache, "stale-rest")
	called := false
	handler := mw.Wrap(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		called = true
		device, ok := DeviceFromContext(r.Context())
		if !ok || device.ID != "pos-stale" {
			t.Fatalf("handler identity = %+v, ok=%v", device, ok)
		}
		w.WriteHeader(http.StatusNoContent)
	}))
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/orders", nil)
	req.Header.Set("Authorization", "Bearer stale-rest")
	rec := httptest.NewRecorder()

	handler.ServeHTTP(rec, req)

	if !called || rec.Code != http.StatusNoContent {
		t.Fatalf("called=%v status=%d, want local handler 204", called, rec.Code)
	}
	if got := rec.Header().Get("X-Auth-Stale"); got != "true" {
		t.Fatalf("X-Auth-Stale = %q, want true", got)
	}
}

func TestAuthMiddleware_Cloud503WithoutCacheFailsClosed(t *testing.T) {
	mw, _ := newStatusAuthMWForTest(t, http.StatusServiceUnavailable, time.Minute)
	called := false
	handler := mw.Wrap(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {
		called = true
	}))
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/orders", nil)
	req.Header.Set("Authorization", "Bearer never-verified")
	rec := httptest.NewRecorder()

	handler.ServeHTTP(rec, req)

	if called {
		t.Fatal("cache miss must not pass the local auth gate")
	}
	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("status = %d, want 503", rec.Code)
	}
	if got := rec.Header().Get("X-Auth-Stale"); got != "" {
		t.Fatalf("cache miss advertised stale auth: %q", got)
	}
}

func TestVerifyToken_StaleCachePlusCloud401StillRejects(t *testing.T) {
	mw, cache := newStatusAuthMWForTest(t, http.StatusUnauthorized, time.Millisecond)
	token := "revoked-stale"
	seedStaleAuthIdentity(t, cache, token)

	result, err := mw.VerifyToken(context.Background(), token)

	if result != nil || !errors.Is(err, service.ErrUnauthorized) {
		t.Fatalf("result=%+v err=%v, want terminal ErrUnauthorized", result, err)
	}
	if entry, fresh, stale := cache.Get(service.HashToken(token)); entry != nil || fresh || stale {
		t.Fatalf("revoked cache survived: entry=%+v fresh=%v stale=%v", entry, fresh, stale)
	}
}

func TestVerifyToken_CloudRejectsClearsCache(t *testing.T) {
	mw, cache, stub := newAuthMWForTest(t, 5*time.Minute)

	// Seed a cache entry that Cloud will then reject (token revoked).
	hash := service.HashToken("revoked")
	id := &service.Identity{
		Type: domain.IdentityTypeDevice, DeviceID: "kds-x",
		BranchID: "branch-A",
	}
	_ = cache.PutIdentity(hash, id, "")

	// Force the path through Verify by marking the cache stale.
	if err := cache.Delete(hash); err != nil {
		t.Fatalf("seed delete: %v", err)
	}
	stub.err = service.ErrUnauthorized

	_, err := mw.VerifyToken(context.Background(), "revoked")
	if !errors.Is(err, service.ErrUnauthorized) {
		t.Errorf("expected ErrUnauthorized, got %v", err)
	}

	// Cache must NOT contain the revoked token after this.
	if _, fresh, _ := cache.Get(hash); fresh {
		t.Errorf("revoked token must not stay cached")
	}
}

func TestVerifyToken_ConcurrentMissesShareOneCloudVerification(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "auth.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	release := make(chan struct{})
	verifier := &blockingMWVerifier{
		release: release,
		identity: &service.Identity{
			Type: domain.IdentityTypeDevice, DeviceID: "pos-1",
			DeviceType: "pos", BranchID: "branch-A",
		},
	}
	mw := newAuthMiddlewareWithVerifier(
		service.NewAuthCacheStore(db, 5*time.Minute),
		verifier,
		func() string { return "branch-A" },
		nil,
	)

	const workers = 24
	start := make(chan struct{})
	errCh := make(chan error, workers)
	var wg sync.WaitGroup
	for range workers {
		wg.Add(1)
		go func() {
			defer wg.Done()
			<-start
			_, err := mw.VerifyToken(context.Background(), "same-token")
			errCh <- err
		}()
	}
	close(start)

	deadline := time.Now().Add(time.Second)
	for verifier.calls.Load() == 0 && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if got := verifier.calls.Load(); got != 1 {
		t.Fatalf("Cloud calls before release = %d, want exactly 1", got)
	}
	// Give every goroutine time to join the in-flight call. Without the
	// singleflight map this makes the counter jump to workers.
	time.Sleep(50 * time.Millisecond)
	if got := verifier.calls.Load(); got != 1 {
		t.Fatalf("Cloud calls while verification is blocked = %d, want 1", got)
	}
	close(release)
	wg.Wait()
	close(errCh)
	for err := range errCh {
		if err != nil {
			t.Fatalf("shared verification failed: %v", err)
		}
	}

	metrics := mw.Metrics()
	if metrics.UpstreamCalls != 1 {
		t.Errorf("upstream_calls = %d, want 1", metrics.UpstreamCalls)
	}
	if metrics.SharedWaiters == 0 {
		t.Error("shared_waiters = 0, want concurrent callers to join the flight")
	}
}

func TestVerifyToken_StaleFallbackIsBoundedBelowPOSRequestTimeout(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "auth.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	cache := service.NewAuthCacheStore(db, time.Millisecond)
	hash := service.HashToken("stale-token")
	if err := cache.PutIdentity(hash, &service.Identity{
		Type: domain.IdentityTypeDevice, DeviceID: "pos-stale",
		DeviceType: "pos", BranchID: "branch-A",
	}, ""); err != nil {
		t.Fatalf("seed cache: %v", err)
	}
	time.Sleep(5 * time.Millisecond)

	never := make(chan struct{})
	verifier := &blockingMWVerifier{release: never}
	mw := newAuthMiddlewareWithVerifier(cache, verifier, func() string { return "branch-A" }, nil)

	started := time.Now()
	result, err := mw.VerifyToken(context.Background(), "stale-token")
	elapsed := time.Since(started)
	if err != nil {
		t.Fatalf("stale fallback: %v", err)
	}
	if !result.Stale || result.Identity.DeviceID != "pos-stale" {
		t.Fatalf("result = %+v, want bounded stale identity", result)
	}
	if elapsed > 1500*time.Millisecond {
		t.Fatalf("stale auth waited %s; POS abandons the whole LAN request at 3s", elapsed)
	}
	if got := verifier.calls.Load(); got != 1 {
		t.Fatalf("Cloud calls = %d, want 1 bounded attempt", got)
	}
	if got := mw.Metrics().StaleFallbacks; got != 1 {
		t.Fatalf("stale_fallbacks = %d, want 1", got)
	}
}

func TestVerifyToken_DisconnectedLeaderDoesNotCancelSharedVerification(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "auth.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	release := make(chan struct{})
	verifier := &blockingMWVerifier{
		release: release,
		identity: &service.Identity{
			Type: domain.IdentityTypeDevice, DeviceID: "pos-survivor",
			DeviceType: "pos", BranchID: "branch-A",
		},
	}
	mw := newAuthMiddlewareWithVerifier(
		service.NewAuthCacheStore(db, 5*time.Minute),
		verifier,
		func() string { return "branch-A" },
		nil,
	)

	leaderCtx, cancelLeader := context.WithCancel(context.Background())
	leaderErr := make(chan error, 1)
	go func() {
		_, err := mw.VerifyToken(leaderCtx, "shared-token")
		leaderErr <- err
	}()
	waitForVerifierCalls(t, verifier, 1)

	waiterResult := make(chan *AuthResult, 1)
	waiterErr := make(chan error, 1)
	go func() {
		result, err := mw.VerifyToken(context.Background(), "shared-token")
		waiterResult <- result
		waiterErr <- err
	}()

	// The browser that happened to start the flight goes away. The Cloud call
	// is owned by the middleware's bounded background context, so the cashier
	// still waiting on the same token must receive its result.
	cancelLeader()
	if err := <-leaderErr; !errors.Is(err, context.Canceled) {
		t.Fatalf("leader error = %v, want context.Canceled", err)
	}
	close(release)
	if err := <-waiterErr; err != nil {
		t.Fatalf("shared waiter failed after leader disconnected: %v", err)
	}
	if result := <-waiterResult; result == nil || result.Identity.DeviceID != "pos-survivor" {
		t.Fatalf("shared waiter result = %+v, want surviving identity", result)
	}
	if got := verifier.calls.Load(); got != 1 {
		t.Fatalf("Cloud calls = %d, want the original in-flight call only", got)
	}
}

func TestVerifyToken_DifferentTokensDoNotShareAFlight(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "auth.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	release := make(chan struct{})
	verifier := &blockingMWVerifier{
		release: release,
		identity: &service.Identity{
			Type: domain.IdentityTypeDevice, DeviceID: "pos-1",
			DeviceType: "pos", BranchID: "branch-A",
		},
	}
	mw := newAuthMiddlewareWithVerifier(
		service.NewAuthCacheStore(db, 5*time.Minute),
		verifier,
		func() string { return "branch-A" },
		nil,
	)

	errCh := make(chan error, 2)
	for _, token := range []string{"token-A", "token-B"} {
		go func() {
			_, err := mw.VerifyToken(context.Background(), token)
			errCh <- err
		}()
	}
	waitForVerifierCalls(t, verifier, 2)
	close(release)
	for range 2 {
		if err := <-errCh; err != nil {
			t.Fatalf("verification failed: %v", err)
		}
	}
	if got := mw.Metrics().UpstreamCalls; got != 2 {
		t.Fatalf("upstream_calls = %d, want one per distinct token", got)
	}
}

func waitForVerifierCalls(t *testing.T, verifier *blockingMWVerifier, want int32) {
	t.Helper()
	deadline := time.Now().Add(time.Second)
	for verifier.calls.Load() < want && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if got := verifier.calls.Load(); got != want {
		t.Fatalf("Cloud calls = %d, want %d before deadline", got, want)
	}
}
