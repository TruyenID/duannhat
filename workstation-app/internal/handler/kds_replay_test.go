package handler

import (
	"strings"
	"sync"
	"testing"
	"time"
)

func TestKDSReplay_PushAndSince(t *testing.T) {
	b := newKDSReplayBuffer()
	cutoff := time.Now().UTC().Add(-1 * time.Second)

	b.Push("order.kitchen_printed", "br-1", []byte(`{"a":1}`))
	b.Push("order.kitchen_printed", "br-2", []byte(`{"b":2}`))
	b.Push("order.kitchen_printed", "br-1", []byte(`{"c":3}`))

	got := b.Since(cutoff, "br-1")
	if len(got) != 2 {
		t.Fatalf("br-1: want 2 events, got %d", len(got))
	}
	if !strings.Contains(string(got[0]), `"a":1`) ||
		!strings.Contains(string(got[1]), `"c":3`) {
		t.Errorf("br-1: wrong payloads: %s / %s", got[0], got[1])
	}

	all := b.Since(cutoff, "")
	if len(all) != 3 {
		t.Errorf("unscoped: want 3, got %d", len(all))
	}
}

func TestKDSReplay_SinceFiltersOldEvents(t *testing.T) {
	b := newKDSReplayBuffer()
	b.Push("e", "br-1", []byte(`{"x":1}`))
	time.Sleep(20 * time.Millisecond)
	cutoff := time.Now().UTC()
	time.Sleep(20 * time.Millisecond)
	b.Push("e", "br-1", []byte(`{"y":2}`))

	got := b.Since(cutoff, "br-1")
	if len(got) != 1 {
		t.Fatalf("want 1 event after cutoff, got %d", len(got))
	}
	if !strings.Contains(string(got[0]), `"y":2`) {
		t.Errorf("expected the post-cutoff payload, got %s", got[0])
	}
}

func TestKDSReplay_EvictsBeyondWindow(t *testing.T) {
	b := newKDSReplayBuffer()
	b.mu.Lock()
	b.events = append(b.events, replayEvent{
		at:        time.Now().UTC().Add(-2 * kdsReplayWindow),
		eventType: "old",
		branchID:  "br-1",
		data:      []byte(`{"old":true}`),
	})
	b.mu.Unlock()

	b.Push("new", "br-1", []byte(`{"new":true}`))

	if b.Len() != 1 {
		t.Errorf("expected aged-out event to be evicted, buffer has %d", b.Len())
	}
}

func TestKDSReplay_HardCapDropsOldest(t *testing.T) {
	b := newKDSReplayBuffer()
	for i := 0; i < kdsReplayMaxEntries+50; i++ {
		b.Push("e", "br-1", []byte(`{}`))
	}
	if b.Len() > kdsReplayMaxEntries {
		t.Errorf("buffer exceeded cap: %d > %d", b.Len(), kdsReplayMaxEntries)
	}
}

func TestKDSReplay_ConcurrentPushAndSince(t *testing.T) {
	b := newKDSReplayBuffer()
	const N = 200
	var wg sync.WaitGroup
	wg.Add(2)
	go func() {
		defer wg.Done()
		for i := 0; i < N; i++ {
			b.Push("e", "br-1", []byte(`{}`))
		}
	}()
	go func() {
		defer wg.Done()
		for i := 0; i < N; i++ {
			_ = b.Since(time.Time{}, "br-1")
		}
	}()
	wg.Wait()
	if b.Len() == 0 {
		t.Errorf("expected events to be present after concurrent run")
	}
}

func TestKDSReplay_PushBroadcastSerialisesMessageEnvelope(t *testing.T) {
	b := newKDSReplayBuffer()
	data, err := b.pushBroadcast("order.kitchen_printed", "br-1",
		map[string]any{"order_id": "ord-1"})
	if err != nil {
		t.Fatalf("pushBroadcast: %v", err)
	}
	if !strings.Contains(string(data), `"type":"order.kitchen_printed"`) {
		t.Errorf("envelope missing type: %s", data)
	}
	if !strings.Contains(string(data), `"order_id":"ord-1"`) {
		t.Errorf("envelope missing payload: %s", data)
	}
}
