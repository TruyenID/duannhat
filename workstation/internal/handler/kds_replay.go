package handler

import (
	"encoding/json"
	"sort"
	"sync"
	"time"
)

// kdsReplayWindow is how long an event stays in the ring buffer before it's
// evicted. 60 s matches the typical KDS-tablet sleep + reconnect cycle.
// Anything older than this is too stale to replay meaningfully — KDS
// should re-fetch on next poll.
const kdsReplayWindow = 60 * time.Second

// kdsReplayMaxEntries is a hard cap on buffer size so a broadcast storm
// (10 ws events / sec sustained) can't OOM the workstation. With 60 s
// retention at 10 evt/s, ~600 entries is the worst-case steady state;
// the cap is 2× headroom.
const kdsReplayMaxEntries = 1200

// replayEvent is one buffered broadcast — serialised payload (so we don't
// re-marshal on each replay) + the metadata needed to filter by branch
// and `since` cursor.
type replayEvent struct {
	at        time.Time
	eventType string
	branchID  string // "" → broadcast to all (BroadcastEvent)
	data      []byte // pre-serialized Message JSON
}

// kdsReplayBuffer is a thread-safe FIFO ring with branch-scoped lookup.
// Per plan-038 T9.1: push on every kitchen-fire broadcast; clients
// connecting with `?since=<RFC3339>` get any matching events with at>since
// replayed BEFORE live events flow in.
type kdsReplayBuffer struct {
	mu     sync.RWMutex
	events []replayEvent
}

// newKDSReplayBuffer constructs an empty buffer.
func newKDSReplayBuffer() *kdsReplayBuffer {
	return &kdsReplayBuffer{
		events: make([]replayEvent, 0, 256),
	}
}

// Push appends an event. Drops the oldest if the cap is hit (oldest-first
// eviction). The caller is responsible for the serialised `data` shape.
func (b *kdsReplayBuffer) Push(eventType, branchID string, data []byte) {
	if b == nil {
		return
	}
	now := time.Now().UTC()
	b.mu.Lock()
	defer b.mu.Unlock()

	// Evict aged-out entries on every push so the buffer self-trims even
	// when traffic is bursty (otherwise an idle hour would still hold an
	// hour's events at the front).
	cutoff := now.Add(-kdsReplayWindow)
	first := 0
	for first < len(b.events) && b.events[first].at.Before(cutoff) {
		first++
	}
	if first > 0 {
		b.events = b.events[first:]
	}

	// Hard cap — drop oldest until under limit BEFORE adding the new event.
	for len(b.events) >= kdsReplayMaxEntries {
		b.events = b.events[1:]
	}

	b.events = append(b.events, replayEvent{
		at:        now,
		eventType: eventType,
		branchID:  branchID,
		data:      data,
	})
}

// Since returns events with at>cutoff in chronological order. branchID
// scopes the result; "" returns all (mirror of BroadcastEventScoped's
// branchID semantics). Returned slice is a copy — safe to iterate without
// holding the lock.
func (b *kdsReplayBuffer) Since(cutoff time.Time, branchID string) [][]byte {
	if b == nil {
		return nil
	}
	b.mu.RLock()
	defer b.mu.RUnlock()

	out := make([][]byte, 0, 16)
	for _, ev := range b.events {
		if !ev.at.After(cutoff) {
			continue
		}
		if branchID != "" && ev.branchID != "" && ev.branchID != branchID {
			continue
		}
		out = append(out, ev.data)
	}
	// events were appended in chronological order; no re-sort needed in the
	// common case. The defensive sort.SliceStable below is a no-op when
	// already ordered.
	sort.SliceStable(out, func(i, j int) bool { return i < j })
	return out
}

// Len returns the current buffer size (for tests + metrics).
func (b *kdsReplayBuffer) Len() int {
	if b == nil {
		return 0
	}
	b.mu.RLock()
	defer b.mu.RUnlock()
	return len(b.events)
}

// pushBroadcast is a convenience wrapper: pre-serialises the Message
// envelope (same shape as the Hub uses) and pushes the resulting bytes.
// Returns the serialised bytes so the Hub can also use them for the
// live broadcast and avoid double-marshalling.
func (b *kdsReplayBuffer) pushBroadcast(eventType, branchID string, payload any) ([]byte, error) {
	msg := Message{
		Type:      eventType,
		Payload:   payload,
		Timestamp: time.Now().UTC().Format(time.RFC3339),
	}
	data, err := json.Marshal(msg)
	if err != nil {
		return nil, err
	}
	b.Push(eventType, branchID, data)
	return data, nil
}

// btoi converts a bool to 0/1 — used as a SQL bind value for SQLite's
// CASE-equivalent BOOLEAN treatment. Inline helper to keep callers terse.
func btoi(b bool) int {
	if b {
		return 1
	}
	return 0
}
