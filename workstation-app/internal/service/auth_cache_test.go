package service

import (
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

func freshDB(t *testing.T) *store.DB {
	t.Helper()
	db, err := store.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return db
}

func TestAuthCachePutGet(t *testing.T) {
	cache := NewAuthCacheStore(freshDB(t), 5*time.Minute)
	hash := HashToken("plaintoken-abc")

	if err := cache.Put(hash, "dev-1", "kiosk", "branch-1", `{"app_version":"1.0"}`); err != nil {
		t.Fatalf("put: %v", err)
	}

	entry, fresh, stale := cache.Get(hash)
	if !fresh {
		t.Fatal("expected fresh hit")
	}
	if stale {
		t.Error("expected stale=false")
	}
	if entry.DeviceID != "dev-1" || entry.DeviceType != "kiosk" || entry.BranchID != "branch-1" {
		t.Errorf("entry mismatch: %+v", entry)
	}
}

func TestAuthCacheMiss(t *testing.T) {
	cache := NewAuthCacheStore(freshDB(t), 5*time.Minute)
	entry, fresh, stale := cache.Get("nonexistent-hash")
	if fresh || stale || entry != nil {
		t.Errorf("expected pure miss, got entry=%v fresh=%v stale=%v", entry, fresh, stale)
	}
}

func TestAuthCacheExpiry(t *testing.T) {
	cache := NewAuthCacheStore(freshDB(t), 50*time.Millisecond)
	hash := HashToken("short-lived")
	if err := cache.Put(hash, "dev-1", "kiosk", "branch-1", ""); err != nil {
		t.Fatalf("put: %v", err)
	}

	time.Sleep(100 * time.Millisecond)

	entry, fresh, stale := cache.Get(hash)
	if fresh {
		t.Error("expected expired entry to be !fresh")
	}
	if !stale {
		t.Error("expected stale=true for expired entry")
	}
	if entry == nil || entry.DeviceID != "dev-1" {
		t.Errorf("expected entry still returned for stale fallback, got %v", entry)
	}
}

func TestAuthCacheRefreshOnPut(t *testing.T) {
	cache := NewAuthCacheStore(freshDB(t), 5*time.Minute)
	hash := HashToken("plain")

	cache.Put(hash, "dev-1", "kiosk", "branch-1", "")
	cache.Put(hash, "dev-1", "kiosk", "branch-1", "") // upsert

	// Should be a single row
	var n int
	freshDB := cache.db
	if err := freshDB.QueryRow("SELECT COUNT(*) FROM auth_token_cache WHERE token_hash = ?", hash).Scan(&n); err != nil {
		t.Fatalf("count: %v", err)
	}
	if n != 1 {
		t.Errorf("expected 1 row after upsert, got %d", n)
	}
}

func TestAuthCacheDelete(t *testing.T) {
	cache := NewAuthCacheStore(freshDB(t), 5*time.Minute)
	hash := HashToken("plain")
	cache.Put(hash, "dev-1", "kiosk", "branch-1", "")

	if err := cache.Delete(hash); err != nil {
		t.Fatalf("delete: %v", err)
	}
	_, fresh, stale := cache.Get(hash)
	if fresh || stale {
		t.Error("expected entry to be gone after delete")
	}
}

func TestAuthCacheDeleteByDeviceID(t *testing.T) {
	cache := NewAuthCacheStore(freshDB(t), 5*time.Minute)
	cache.Put(HashToken("t1"), "dev-1", "kiosk", "branch-1", "")
	cache.Put(HashToken("t2"), "dev-1", "kiosk", "branch-1", "") // same device, different tokens
	cache.Put(HashToken("t3"), "dev-2", "pos", "branch-1", "")

	n, err := cache.DeleteByDeviceID("dev-1")
	if err != nil {
		t.Fatalf("delete by device: %v", err)
	}
	if n != 2 {
		t.Errorf("expected 2 deleted for dev-1, got %d", n)
	}

	// dev-2 should still be there
	_, fresh, _ := cache.Get(HashToken("t3"))
	if !fresh {
		t.Error("dev-2 entry should not have been deleted")
	}
}

func TestAuthCacheCleanup(t *testing.T) {
	cache := NewAuthCacheStore(freshDB(t), 50*time.Millisecond)
	cache.Put(HashToken("t1"), "dev-1", "kiosk", "branch-1", "")
	cache.Put(HashToken("t2"), "dev-2", "kiosk", "branch-1", "")

	time.Sleep(100 * time.Millisecond)

	// Cleanup entries older than 50ms past expiry
	n, err := cache.Cleanup(0)
	if err != nil {
		t.Fatalf("cleanup: %v", err)
	}
	if n != 2 {
		t.Errorf("expected 2 cleaned up, got %d", n)
	}
}

func TestHashToken(t *testing.T) {
	h1 := HashToken("same")
	h2 := HashToken("same")
	h3 := HashToken("different")
	if h1 != h2 {
		t.Error("same input should produce same hash")
	}
	if h1 == h3 {
		t.Error("different inputs should produce different hashes")
	}
	if len(h1) != 64 {
		t.Errorf("SHA-256 hex should be 64 chars, got %d", len(h1))
	}
}
