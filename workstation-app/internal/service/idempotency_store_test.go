package service

import (
	"encoding/json"
	"testing"
	"time"
)

func TestIdempotencyStore_PutAndGet(t *testing.T) {
	db := freshDB(t)
	s := NewIdempotencyStore(db)

	payload := map[string]any{"ok": true, "status": "preparing"}
	body, _ := json.Marshal(payload)
	if err := s.Put("key-1", "dev-a", "hash-x", string(body)); err != nil {
		t.Fatalf("put: %v", err)
	}

	cached, ok, err := s.Get("key-1", "dev-a")
	if err != nil || !ok {
		t.Fatalf("get: ok=%v err=%v", ok, err)
	}
	if cached != string(body) {
		t.Fatalf("mismatch: got %s", cached)
	}
}

func TestIdempotencyStore_CleanupOldEntries(t *testing.T) {
	db := freshDB(t)
	s := NewIdempotencyStore(db)

	// Insert one fresh + one stale
	_ = s.Put("fresh", "dev-a", "h", "{}")
	_, _ = db.Exec(`INSERT INTO idempotency_keys (key, device_id, request_hash, response, created_at) VALUES ('stale', 'dev-a', 'h', '{}', ?)`, time.Now().Add(-48*time.Hour))

	deleted, err := s.CleanupOlderThan(24 * time.Hour)
	if err != nil {
		t.Fatalf("cleanup: %v", err)
	}
	if deleted != 1 {
		t.Fatalf("expected 1 deleted, got %d", deleted)
	}
}
