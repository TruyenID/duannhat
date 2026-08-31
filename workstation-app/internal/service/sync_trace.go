package service

// Sync observability — a small in-memory ring buffer of the most recent sync
// events across ALL flows (UP push, DOWN pull, KDS bump, connectivity). Every
// event is also emitted to slog so it lands in the terminal / log file; the
// ring buffer additionally lets the Wails UI surface a live "Nhật ký đồng bộ"
// (sync activity log) on the Đồng bộ page without a new DB table.
//
// Each event carries a trace_id — for UP/KDS pushes this is the sync_queue
// row's idempotency_key, so the SAME id appears in the workstation log, in the
// UI feed, AND in Cloud's request log (sent as the Idempotency-Key header),
// giving an end-to-end correlation id across the two systems.

import (
	"log/slog"
	"sync"
	"time"
)

// SyncFlow tags which sync direction/subsystem an event belongs to so the UI
// can filter the unified feed.
type SyncFlow string

const (
	FlowUp   SyncFlow = "up"   // workstation → Cloud (sync_queue push)
	FlowDown SyncFlow = "down" // Cloud → workstation (pull replica)
	FlowKds  SyncFlow = "kds"  // KDS item-status bump push
	FlowConn SyncFlow = "conn" // connectivity online/offline transitions
	FlowLan  SyncFlow = "lan"  // incoming LAN client request (kiosk/pos/kds)
)

// Event status values (kept as plain strings so the UI can color them).
const (
	statusOK    = "ok"
	statusError = "error"
	statusSkip  = "skip"  // head-of-line skip (dependency/auth not ready)
	statusRetry = "retry" // transient cloud fault — cycle backed off
)

// SyncTraceEvent is one entry in the ring buffer. Field tags are the JSON shape
// the frontend consumes.
type SyncTraceEvent struct {
	Seq        int64  `json:"seq"`         // monotonic, for stable React keys / ordering
	At         string `json:"at"`          // RFC3339 timestamp
	Flow       string `json:"flow"`        // up | down | kds | conn
	Phase      string `json:"phase"`       // enqueue | push | pull | conn
	TraceID    string `json:"trace_id"`    // correlation id (idempotency_key for pushes)
	EntityType string `json:"entity_type"` // order, payment, till_session, zones…
	Operation  string `json:"operation"`   // create, confirm, update_status…
	EntityID   string `json:"entity_id"`
	Status     string `json:"status"` // ok | error | skip | retry
	LatencyMS  int64  `json:"latency_ms,omitempty"`
	Attempt    int    `json:"attempt,omitempty"`
	Count      int    `json:"count,omitempty"`       // rows affected (pull-down)
	StatusCode int    `json:"status_code,omitempty"` // HTTP status (lan flow)
	Error      string `json:"error,omitempty"`
}

// SyncTracer is a bounded, concurrency-safe ring buffer. A single instance is
// shared by the SyncEngine and the SyncPuller so the UI sees one merged feed.
type SyncTracer struct {
	mu   sync.RWMutex
	buf  []SyncTraceEvent
	cap  int
	seq  int64
	head int // next write index
	size int // number of valid entries
}

// NewSyncTracer builds a tracer that retains the last `capacity` events.
func NewSyncTracer(capacity int) *SyncTracer {
	if capacity <= 0 {
		capacity = 300
	}
	return &SyncTracer{buf: make([]SyncTraceEvent, capacity), cap: capacity}
}

// record stamps seq + timestamp, stores the event in the ring, and mirrors it
// to slog at a level chosen by status.
func (t *SyncTracer) record(ev SyncTraceEvent) {
	if t == nil {
		return
	}
	t.mu.Lock()
	t.seq++
	ev.Seq = t.seq
	ev.At = time.Now().UTC().Format(time.RFC3339)
	t.buf[t.head] = ev
	t.head = (t.head + 1) % t.cap
	if t.size < t.cap {
		t.size++
	}
	t.mu.Unlock()

	args := []any{
		"trace_id", ev.TraceID,
		"flow", ev.Flow,
		"entity", ev.EntityType,
		"op", ev.Operation,
		"entity_id", ev.EntityID,
		"status", ev.Status,
	}
	if ev.LatencyMS > 0 {
		args = append(args, "latency_ms", ev.LatencyMS)
	}
	if ev.Attempt > 0 {
		args = append(args, "attempt", ev.Attempt)
	}
	if ev.Count > 0 {
		args = append(args, "count", ev.Count)
	}
	if ev.Error != "" {
		args = append(args, "error", ev.Error)
	}
	switch ev.Status {
	case statusError:
		slog.Warn("sync.trace", args...)
	case statusOK:
		slog.Debug("sync.trace", args...)
	default:
		slog.Info("sync.trace", args...)
	}
}

// Recent returns up to `limit` most-recent events (newest first), optionally
// filtered to a single flow ("" = all flows).
func (t *SyncTracer) Recent(limit int, flow string) []SyncTraceEvent {
	if t == nil {
		return nil
	}
	t.mu.RLock()
	defer t.mu.RUnlock()
	if limit <= 0 || limit > t.size {
		limit = t.size
	}
	out := make([]SyncTraceEvent, 0, limit)
	// Walk backwards from the most recently written slot.
	for i := 0; i < t.size && len(out) < limit; i++ {
		idx := (t.head - 1 - i + t.cap) % t.cap
		ev := t.buf[idx]
		if flow != "" && ev.Flow != flow {
			continue
		}
		out = append(out, ev)
	}
	return out
}

// ── Convenience recorders (keep call sites terse) ──────────────────────────

// up records a workstation→Cloud push outcome. traceID is the sync_queue
// idempotency_key so it lines up with Cloud's Idempotency-Key request log.
func (t *SyncTracer) up(flow SyncFlow, traceID, entityType, operation, entityID, status string, latencyMS int64, attempt int, err error) {
	ev := SyncTraceEvent{
		Flow:       string(flow),
		Phase:      "push",
		TraceID:    traceID,
		EntityType: entityType,
		Operation:  operation,
		EntityID:   entityID,
		Status:     status,
		LatencyMS:  latencyMS,
		Attempt:    attempt,
	}
	if err != nil {
		ev.Error = err.Error()
	}
	t.record(ev)
}

// enqueue records that an operation entered the sync queue.
func (t *SyncTracer) enqueue(traceID, entityType, operation, entityID string) {
	t.record(SyncTraceEvent{
		Flow:       string(FlowUp),
		Phase:      "enqueue",
		TraceID:    traceID,
		EntityType: entityType,
		Operation:  operation,
		EntityID:   entityID,
		Status:     statusOK,
	})
}

// down records a Cloud→workstation pull outcome for a named feed.
func (t *SyncTracer) down(feed string, latencyMS int64, count int, err error) {
	ev := SyncTraceEvent{
		Flow:      string(FlowDown),
		Phase:     "pull",
		Operation: feed,
		LatencyMS: latencyMS,
		Count:     count,
		Status:    statusOK,
	}
	if err != nil {
		ev.Status = statusError
		ev.Error = err.Error()
	}
	t.record(ev)
}

// LAN records an incoming mutating request from a LAN client (kiosk/pos/kds).
// Exported because it is called from the handler package's trace middleware.
// client is the subsystem ("kiosk"|"pos"|"kds"); route is "METHOD /path".
func (t *SyncTracer) LAN(client, route, entityID string, statusCode int, latencyMS int64) {
	ev := SyncTraceEvent{
		Flow:       string(FlowLan),
		Phase:      "request",
		EntityType: client,
		Operation:  route,
		EntityID:   entityID,
		StatusCode: statusCode,
		LatencyMS:  latencyMS,
		Status:     statusOK,
	}
	if statusCode >= 400 {
		ev.Status = statusError
	}
	t.record(ev)
}

// conn records a connectivity transition.
func (t *SyncTracer) conn(status ConnStatus) {
	t.record(SyncTraceEvent{
		Flow:      string(FlowConn),
		Phase:     "conn",
		Operation: string(status),
		Status:    statusOK,
	})
}
