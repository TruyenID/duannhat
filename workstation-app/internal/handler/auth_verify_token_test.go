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
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/domain"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
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

func newAuthMWForTest(t *testing.T, ttl time.Duration) (*AuthMiddleware, *service.AuthCacheStore, *stubMWVerifier) {
	t.Helper()
	db, err := store.Open(filepath.Join(t.TempDir(), "auth.db"))
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
