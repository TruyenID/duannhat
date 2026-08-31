package service

// Regression tests for the composite-PK contract.
//
// Migration 010 used `key TEXT PRIMARY KEY` but Get/Put filter by
// (key, device_id). Two devices that happened to derive the same
// idempotency key — bump-all of the same order from KDS tablet A and
// kiosk-side B, or any collision in 32-bit hex — would: device B
// `Get` misses (filtered by device_id), device B re-executes the bump,
// device B's `Put` hits `ON CONFLICT(key) DO NOTHING` and the cache
// write disappears with no error. Migration 012 changes PK to
// (key, device_id); these tests pin the contract from both sides.

import (
	"testing"
)

func TestIdempotencyStore_SameKeyDifferentDevicesCoexist(t *testing.T) {
	db := freshDB(t)
	s := NewIdempotencyStore(db)

	if err := s.Put("shared-key", "dev-A", "h-a", `{"who":"A"}`); err != nil {
		t.Fatalf("put A: %v", err)
	}
	if err := s.Put("shared-key", "dev-B", "h-b", `{"who":"B"}`); err != nil {
		t.Fatalf("put B: %v", err)
	}

	a, ok, err := s.Get("shared-key", "dev-A")
	if err != nil || !ok {
		t.Fatalf("get A: ok=%v err=%v", ok, err)
	}
	if a != `{"who":"A"}` {
		t.Errorf("A response: got %q, want A's", a)
	}

	b, ok, err := s.Get("shared-key", "dev-B")
	if err != nil || !ok {
		t.Fatalf("get B: ok=%v err=%v", ok, err)
	}
	if b != `{"who":"B"}` {
		t.Errorf("B response: got %q, want B's", b)
	}
}

func TestIdempotencyStore_SameKeySameDeviceReplayReturnsFirstResponse(t *testing.T) {
	db := freshDB(t)
	s := NewIdempotencyStore(db)

	// First write: real handler completes, caches `first`.
	if err := s.Put("k", "dev-A", "h1", `first`); err != nil {
		t.Fatalf("put 1: %v", err)
	}
	// Retry from same device: ON CONFLICT(key, device_id) DO NOTHING,
	// the cached `first` must persist.
	if err := s.Put("k", "dev-A", "h2", `second`); err != nil {
		t.Fatalf("put 2: %v", err)
	}

	got, ok, err := s.Get("k", "dev-A")
	if err != nil || !ok {
		t.Fatalf("get: ok=%v err=%v", ok, err)
	}
	if got != "first" {
		t.Errorf("idempotency violated: got %q, want first", got)
	}
}
