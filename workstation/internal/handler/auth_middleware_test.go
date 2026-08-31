package handler

import (
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"sync/atomic"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func newTestDB(t *testing.T) *store.DB {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return db
}

func newTestMiddleware(t *testing.T, cloudURL, wsBranch string, ttl time.Duration) (*AuthMiddleware, *service.AuthCacheStore) {
	t.Helper()
	cache := service.NewAuthCacheStore(newTestDB(t), ttl)
	verifier := service.NewCloudVerifier(func() string { return cloudURL })
	mw := NewAuthMiddleware(cache, verifier, func() string { return wsBranch }, nil)
	return mw, cache
}

// echoHandler returns a 200 handler that captures the authenticated device into out.
func echoHandler(out *DeviceContext) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if d, ok := DeviceFromContext(r.Context()); ok {
			*out = *d
		}
		w.WriteHeader(http.StatusOK)
	})
}

func TestAuthMiddlewareMissingToken(t *testing.T) {
	mw, _ := newTestMiddleware(t, "", "", 5*time.Minute)
	rec := httptest.NewRecorder()
	mw.Wrap(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("handler should not be called when token missing")
	})).ServeHTTP(rec, httptest.NewRequest("GET", "/api/v1/kiosk/me", nil))

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("expected 401, got %d", rec.Code)
	}
}

func TestAuthMiddlewareCacheMissForwardsToCloud(t *testing.T) {
	var cloudHits int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&cloudHits, 1)
		if r.Header.Get("Authorization") == "Bearer good-token" {
			w.Write([]byte(`{"data":{"id":"dev-1","type":"kiosk","branch_id":"branch-1"}}`))
			return
		}
		w.WriteHeader(http.StatusUnauthorized)
	}))
	defer cloud.Close()

	mw, _ := newTestMiddleware(t, cloud.URL, "branch-1", 5*time.Minute)
	var got DeviceContext

	req := httptest.NewRequest("GET", "/api/v1/kiosk/me", nil)
	req.Header.Set("Authorization", "Bearer good-token")
	rec := httptest.NewRecorder()
	mw.Wrap(echoHandler(&got)).ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	if got.ID != "dev-1" {
		t.Errorf("device not set in context: %+v", got)
	}
	if atomic.LoadInt32(&cloudHits) != 1 {
		t.Errorf("expected 1 cloud hit, got %d", cloudHits)
	}
}

func TestAuthMiddlewareCacheHitNoCloudCall(t *testing.T) {
	var cloudHits int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&cloudHits, 1)
		w.Write([]byte(`{"data":{"id":"dev-1","type":"kiosk","branch_id":"branch-1"}}`))
	}))
	defer cloud.Close()

	mw, _ := newTestMiddleware(t, cloud.URL, "branch-1", 5*time.Minute)
	var got DeviceContext

	// 1st call → Cloud (warm cache)
	req := httptest.NewRequest("GET", "/api/v1/kiosk/me", nil)
	req.Header.Set("Authorization", "Bearer good-token")
	mw.Wrap(echoHandler(&got)).ServeHTTP(httptest.NewRecorder(), req)

	// 2nd call with same token → cache, should NOT hit Cloud
	req2 := httptest.NewRequest("GET", "/api/v1/kiosk/orders", nil)
	req2.Header.Set("Authorization", "Bearer good-token")
	rec := httptest.NewRecorder()
	mw.Wrap(echoHandler(&got)).ServeHTTP(rec, req2)

	if rec.Code != http.StatusOK {
		t.Errorf("expected 200 on cache hit, got %d", rec.Code)
	}
	if atomic.LoadInt32(&cloudHits) != 1 {
		t.Errorf("expected exactly 1 cloud hit total, got %d", cloudHits)
	}
}

func TestAuthMiddlewareCloudRejects401(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
	}))
	defer cloud.Close()

	// A PAIRED workstation — otherwise the #2442 gate short-circuits before
	// Cloud is ever asked, and this test would pass without testing anything.
	mw, _ := newTestMiddleware(t, cloud.URL, "branch-1", 5*time.Minute)
	req := httptest.NewRequest("GET", "/api/v1/kiosk/me", nil)
	req.Header.Set("Authorization", "Bearer bad")
	rec := httptest.NewRecorder()
	mw.Wrap(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("handler should not be reached")
	})).ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("expected 401, got %d", rec.Code)
	}
}

func TestAuthMiddlewareStaleCacheWhenCloudDown(t *testing.T) {
	// Cloud URL points to a closed port → unreachable
	mw, cache := newTestMiddleware(t, "http://127.0.0.1:1", "branch-1", 50*time.Millisecond)

	// Seed cache with short TTL
	if err := cache.Put(service.HashToken("any"), "dev-stale", "kiosk", "branch-1", ""); err != nil {
		t.Fatalf("seed: %v", err)
	}
	time.Sleep(120 * time.Millisecond) // wait past TTL

	req := httptest.NewRequest("GET", "/api/v1/kiosk/me", nil)
	req.Header.Set("Authorization", "Bearer any")
	rec := httptest.NewRecorder()

	var got DeviceContext
	mw.Wrap(echoHandler(&got)).ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("expected 200 with stale fallback, got %d body=%s", rec.Code, rec.Body.String())
	}
	if rec.Header().Get("X-Auth-Stale") != "true" {
		t.Error("expected X-Auth-Stale: true header")
	}
	if got.ID != "dev-stale" {
		t.Errorf("expected stale device in context, got %+v", got)
	}
}

func TestAuthMiddlewareCloudDownNoCacheReturns503(t *testing.T) {
	// Paired, so the 503 under test is "Cloud unreachable", not "#2442 gate".
	mw, _ := newTestMiddleware(t, "http://127.0.0.1:1", "branch-1", 5*time.Minute)
	req := httptest.NewRequest("GET", "/api/v1/kiosk/me", nil)
	req.Header.Set("Authorization", "Bearer unknown")
	rec := httptest.NewRecorder()
	mw.Wrap(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("handler should not be reached")
	})).ServeHTTP(rec, req)

	if rec.Code != http.StatusServiceUnavailable {
		t.Errorf("expected 503, got %d", rec.Code)
	}
}

func TestAuthMiddlewareBranchMismatch(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Device is from branch-OTHER but workstation is branch-OURS
		w.Write([]byte(`{"data":{"id":"dev-1","type":"kiosk","branch_id":"branch-OTHER"}}`))
	}))
	defer cloud.Close()

	mw, _ := newTestMiddleware(t, cloud.URL, "branch-OURS", 5*time.Minute)
	req := httptest.NewRequest("GET", "/api/v1/kiosk/me", nil)
	req.Header.Set("Authorization", "Bearer any")
	rec := httptest.NewRecorder()
	mw.Wrap(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("handler should not be reached on branch mismatch")
	})).ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Errorf("expected 403, got %d", rec.Code)
	}
}

func TestExtractBearer(t *testing.T) {
	cases := []struct {
		header string
		want   string
	}{
		{"Bearer abc", "abc"},
		{"bearer xyz", "xyz"},
		{"BEARER ABC", "ABC"},
		{"abc", ""},
		{"", ""},
		{"Basic foo", ""},
	}
	for _, c := range cases {
		r := httptest.NewRequest("GET", "/", nil)
		if c.header != "" {
			r.Header.Set("Authorization", c.header)
		}
		got := extractBearer(r)
		if got != c.want {
			t.Errorf("header=%q: want %q, got %q", c.header, c.want, got)
		}
	}
}
