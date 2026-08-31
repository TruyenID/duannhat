package service

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

// syncHandler pushes a single sync_queue entry to Cloud.
// retryable=true means transient failure (5xx, network) — worker keeps trying.
// retryable=false means permanent (4xx) — worker stops after attempts cap.
type syncHandler func(ctx context.Context, entityID string, payload map[string]any) (cloudResp map[string]any, retryable bool, err error)

// errDependencyNotReady marks a transient push failure caused by a LOCAL
// prerequisite — the entity's own create hasn't synced yet, so it has no
// cloud_id to reference — rather than a Cloud-side fault. processQueue treats
// these specially: it skips to independent items instead of stopping the whole
// cycle (so one waiting row can't head-of-line-block the queue) and does not
// burn an attempt (the row is valid; it just has to wait for its create).
var errDependencyNotReady = errors.New("dependency not synced yet")

// errAuthRejected marks an entry-specific 401/403 from Cloud — the bearer on
// THIS row is stale/invalid (e.g. an expired cashier token on a till_session),
// but Cloud itself is up (pulls + other rows succeed). processQueue SKIPs it
// (continue) rather than halting the whole cycle (return): a single poisoned
// row must never head-of-line-block the independent rows behind it — e.g. an
// order.create whose cloud_id every dependent item bump is waiting on. It stays
// retryable and does not burn an attempt, so it heals if auth is restored.
var errAuthRejected = errors.New("cloud rejected bearer (401/403)")

// errDataConflict marks a PERMANENT push failure: Cloud rejected the row
// because an entity it references no longer exists — 404 "order gone", or 422
// "table/customer/sku does not exist / is invalid" (a reseed, DR restore, or
// admin delete diverged Cloud from the local mirror). Unlike a transient fault,
// retrying never helps, so processQueue dead-letters the row immediately
// (plan-042) instead of burning five blind attempts and then dying invisibly at
// attempts>=max_attempts while its children loop forever. The concrete
// dead_letter_reason rides on *dataConflictError.
var errDataConflict = errors.New("cloud rejected: referenced entity gone")

// dataConflictError carries the machine reason (dead_letter_reason) for a
// data-conflict push failure. errors.Is(err, errDataConflict) matches it and
// errors.As extracts the reason.
type dataConflictError struct {
	reason string
	msg    string
}

func (e *dataConflictError) Error() string { return e.msg }

func (e *dataConflictError) Is(target error) bool { return target == errDataConflict }

// errReconcilePending marks a workstation till-session close that Cloud
// deferred (503/425 RECONCILE_PENDING + Retry-After) because the shift's
// drawer-affecting rows (the close manifest) are not all in a terminal status
// on Cloud yet — e.g. a card payment whose /confirm hasn't synced. This is a
// ROW-SPECIFIC wait, not a Cloud-wide outage: Cloud is up (pulls + other rows
// succeed), so processQueue SKIPs the close (continue) instead of stopping the
// cycle, does NOT burn an attempt (a legitimate drain is not a failure), and
// does NOT trip the global cooldown (other sessions keep draining). The row is
// parked via deferred_until until the Retry-After elapses; it is unbounded by
// design (Cloud's server-side defer bound is the terminal backstop, never
// max_attempts). #817 Phase B (WS-4).
var errReconcilePending = errors.New("cloud close deferred: manifest not reconciled (RECONCILE_PENDING)")

// reconcilePendingError carries the Cloud-supplied Retry-After so processQueue
// can park the close for exactly that long instead of tight-polling every tick.
// errors.Is(err, errReconcilePending) matches it; errors.As extracts retryAfter.
type reconcilePendingError struct {
	retryAfter time.Duration
	msg        string
}

func (e *reconcilePendingError) Error() string { return e.msg }

func (e *reconcilePendingError) Is(target error) bool { return target == errReconcilePending }

// classifyDataConflict inspects a Cloud 4xx response and returns a non-empty
// dead_letter_reason when the failure is a permanent data conflict (the
// referenced entity is gone), or "" for ordinary/transient failures.
// Conservative by design: only 404 and 422 bodies carrying an entity-missing
// signature classify here; every other 4xx keeps the existing burn-attempt
// path and is still caught by the attempts / stuck-transient safety-nets, so
// nothing dies invisibly.
func classifyDataConflict(status int, body string) string {
	switch status {
	case http.StatusNotFound:
		// The push handlers only ever address an already-existing Cloud
		// resource (order/payment by cloud_id), so a 404 means that resource
		// is gone — not a missing route.
		return "cloud_404_order_gone"
	case http.StatusUnprocessableEntity:
		b := strings.ToLower(body)
		for _, sig := range []string{"do not exist", "does not exist", "no query results", "is invalid", "selected "} {
			if strings.Contains(b, sig) {
				return "cloud_422_entity_missing"
			}
		}
	}
	return ""
}

type SyncEngine struct {
	db       *store.DB
	monitor  *ConnMonitor
	stopCh   chan struct{}
	stopOnce sync.Once
	// wakeCh lets a producer (e.g. the order-create handler) nudge the worker
	// to drain the queue immediately instead of waiting for the 5s tick.
	// Buffered size 1 + non-blocking send: bursts coalesce into a single
	// extra drain, and Wake() never blocks the HTTP request.
	wakeCh     chan struct{}
	cloudURL   string        // static URL passed to constructor (fallback)
	cloudURLFn func() string // optional dynamic resolver (preferred when set)
	httpClient *http.Client
	handlers   map[string]syncHandler
	// onOrderCodeAssigned is invoked after an order.create sync reconciles the
	// Cloud-minted ORD-#### code back onto the local order, so the WS Hub can
	// broadcast the swap to LAN clients (pos-web/KDS). Optional; nil-safe.
	onOrderCodeAssigned func(orderID, orderCode string)
	// tracer is the shared sync-event ring buffer (see sync_trace.go). The
	// SyncPuller receives the same instance via SetTracer so the UI feed and
	// the logs show one merged UP+DOWN+KDS+conn timeline.
	tracer *SyncTracer

	// rlMu guards the push-side rate-limit / backpressure state (plan-042 G/H).
	// cooldownUntil gates EVERY drain (tick + Wake) after a 429/503 so the loop
	// respects Cloud backpressure instead of re-bursting every 5s. reqTimes /
	// payReqTimes are rolling 60s windows that keep the device under the Cloud
	// per-device budget (300/min general, 30/min payments) so it never
	// self-originates a 429. lastCloudSuccessAt distinguishes a poison row
	// (dead-letter, TH.3) from a Cloud-wide outage (wait).
	rlMu                 sync.Mutex
	cooldownUntil        time.Time
	consecutiveThrottles int
	reqTimes             []time.Time
	payReqTimes          []time.Time
}

// Rate-limit soft caps — set below Cloud's per-device limits (300/min general,
// 30/min payments, keyed by device id in AppServiceProvider.php) so the drain
// never trips the limiter itself. Backoff bounds the wait when Cloud sends a
// 429/503 without a Retry-After.
const (
	rlGeneralPerMin = 250
	rlPaymentPerMin = 25
	rlBackoffBase   = 2 * time.Second
	rlBackoffMax    = 5 * time.Minute
	// rlStuckTransientThreshold is how many consecutive retryable failures a row
	// must accumulate (while Cloud is proven up) before it is dead-lettered as a
	// poison row instead of head-of-line-blocking forever (plan-042 TH.3).
	rlStuckTransientThreshold = 20
)

func NewSyncEngine(database *store.DB, cloudURL string, onStatusChange func(ConnStatus)) *SyncEngine {
	e := &SyncEngine{
		db:         database,
		stopCh:     make(chan struct{}),
		wakeCh:     make(chan struct{}, 1),
		cloudURL:   cloudURL,
		httpClient: &http.Client{Timeout: 15 * time.Second},
		tracer:     NewSyncTracer(300),
	}
	// Wrap the status callback so every connectivity flip is also traced/logged
	// through the shared buffer before the app-level handler runs.
	e.monitor = NewConnMonitor(cloudURL, func(status ConnStatus) {
		e.tracer.conn(status)
		if onStatusChange != nil {
			onStatusChange(status)
		}
	})
	e.handlers = map[string]syncHandler{
		"payment.create":         e.handlePaymentCreate,
		"payment.confirm":        e.handlePaymentConfirm,
		"payment.fail":           e.handlePaymentFail,
		"payment.attribute":      e.handlePaymentAttribute,
		"peripheral.upsert":      e.handlePeripheralUpsert,
		"peripheral.delete":      e.handlePeripheralDelete,
		"order.create":           e.handleOrderCreate,
		"till_session.open":      e.handleTillSessionOpen,
		"till_session.close":     e.handleTillSessionClose,
		"till_session.handover":  e.handleTillSessionHandover,
		"till_session.abandon":   e.handleTillSessionAbandon,
		"till_cash_event.create": e.handleTillCashEventCreate,
		"order.init":             e.handleOrderInit,
		"order.item_add":         e.handleOrderItemAdd,
		"order.update":           e.handleOrderUpdate,
		"order.delete":           e.handleOrderDelete,
		"order.void":             e.handleOrderVoid,
		"order.checkout":         e.handleOrderCheckout,
		"order.item_update":      e.handleOrderItemUpdate,
		"order.item_delete":      e.handleOrderItemDelete,
		"order.item_void":        e.handleOrderItemVoid,
		"order.item_refund":      e.handleOrderItemRefund,
		"order.apply_coupon":     e.handleOrderApplyCoupon,
		"order.release_coupon":   e.handleOrderReleaseCoupon,
		"order.merge_table":      e.handleOrderMergeTable,
		"order.unmerge_table":    e.handleOrderUnmergeTable,
		"payment.refund":         e.handlePaymentRefund,
		"customer.create":        e.handleCustomerCreate,
		"table.status":           e.handleTableStatus,
	}
	return e
}

// RegisterHandler adds or replaces a sync handler for the given key
// (entityType + "." + operation). Called at startup to wire handlers that
// need dependencies not available at NewSyncEngine time (e.g., the WS Hub).
func (e *SyncEngine) RegisterHandler(key string, h syncHandler) {
	e.handlers[key] = h
}

// HasHandler reports whether a dispatch handler is registered for the given
// key (entityType + "." + operation). Exposed so wiring code can be asserted
// in tests — a missing key means pushToCloud silently drains the entry as a
// no-op "success", the class of bug behind #534's dropped revert bumps.
func (e *SyncEngine) HasHandler(key string) bool {
	_, ok := e.handlers[key]
	return ok
}

// SetCloudURLResolver lets the cloud URL be re-read on every push, so changes
// from device pairing take effect without a restart.
func (e *SyncEngine) SetCloudURLResolver(fn func() string) {
	e.cloudURLFn = fn
}

// SetOrderCodeAssignedCallback wires the post-reconcile hook used to broadcast
// the Cloud-assigned ORD-#### code to LAN clients. Set once at startup.
func (e *SyncEngine) SetOrderCodeAssignedCallback(fn func(orderID, orderCode string)) {
	e.onOrderCodeAssigned = fn
}

func (e *SyncEngine) resolveCloudURL() string {
	if e.cloudURLFn != nil {
		if u := e.cloudURLFn(); u != "" {
			return u
		}
	}
	return e.cloudURL
}

func (e *SyncEngine) Start() {
	e.monitor.Start()
	go e.processLoop()
	slog.Info("sync engine started")
}

// Stop is safe to call from multiple owners (main.go's defer + Server.Stop's
// shutdown cascade). The first call closes the stop channel and propagates
// to the embedded ConnMonitor; subsequent calls are no-ops.
func (e *SyncEngine) Stop() {
	e.stopOnce.Do(func() {
		close(e.stopCh)
		e.monitor.Stop()
		slog.Info("sync engine stopped")
	})
}

// Tracer exposes the shared sync-event ring buffer so the SyncPuller can push
// DOWN events into the same feed and the HTTP layer can read it for the UI.
func (e *SyncEngine) Tracer() *SyncTracer { return e.tracer }

// RecentTrace returns up to `limit` most-recent sync events (newest first),
// optionally filtered to one flow ("" = all).
func (e *SyncEngine) RecentTrace(limit int, flow string) []SyncTraceEvent {
	return e.tracer.Recent(limit, flow)
}

func (e *SyncEngine) IsOnline() bool {
	return e.monitor.IsOnline()
}

func (e *SyncEngine) Status() ConnStatus {
	return e.monitor.Status()
}

// Wake nudges the worker to drain the sync queue right now instead of waiting
// for the next 5s tick. Call it right after Enqueue when the caller wants the
// operation pushed to Cloud immediately (e.g. order creation in LAN mode).
//
// Non-blocking: if a wake is already pending (or the worker is mid-drain) the
// signal is dropped — the buffered channel guarantees at least one more drain
// will run after the current one, which will pick up the just-enqueued row.
// Safe to call from any goroutine; never blocks the HTTP request.
func (e *SyncEngine) Wake() {
	select {
	case e.wakeCh <- struct{}{}:
	default:
		// A drain is already scheduled; the enqueued row rides along with it.
	}
}

// Enqueue adds an operation to the sync queue.
func (e *SyncEngine) Enqueue(entityType, entityID, operation string, payload any, priority int) error {
	data, err := json.Marshal(payload)
	if err != nil {
		return err
	}

	idempotencyKey := uuid.New().String()
	now := time.Now().UTC().Format(time.RFC3339)

	_, err = e.db.Exec(`
		INSERT INTO sync_queue (entity_type, entity_id, operation, payload, idempotency_key, priority, created_at)
		VALUES (?, ?, ?, ?, ?, ?, ?)
	`, entityType, entityID, operation, string(data), idempotencyKey, priority, now)
	if err == nil {
		e.tracer.enqueue(idempotencyKey, entityType, operation, entityID)
	}

	return err
}

// FilterNewItemAddIDs drops item ids already covered by an unsynced
// order.item_add row for the same order, returning only the ids that still need
// a fresh enqueue. This stops a repeat add-items call (pos-web resend, double
// tap, BR-OI06 quantity-merge on the same line) from piling a duplicate row
// onto the queue — every such row references the same lines and, if Cloud
// rejects them, fails in lockstep (the "25× item_add error (4x)" symptom).
// Returns the input unchanged on any DB/JSON hiccup so dedup never blocks a
// legitimate add.
func (e *SyncEngine) FilterNewItemAddIDs(orderID string, itemIDs []string) []string {
	if len(itemIDs) == 0 {
		return itemIDs
	}
	rows, err := e.db.Query(`
		SELECT payload FROM sync_queue
		WHERE entity_type = 'order' AND operation = 'item_add'
		  AND entity_id = ? AND synced_at IS NULL`, orderID)
	if err != nil {
		return itemIDs
	}
	defer rows.Close()

	queued := map[string]bool{}
	for rows.Next() {
		var payload string
		if err := rows.Scan(&payload); err != nil {
			continue
		}
		var p struct {
			ItemIDs []any `json:"item_ids"`
		}
		if json.Unmarshal([]byte(payload), &p) != nil {
			continue
		}
		for _, raw := range p.ItemIDs {
			if id, ok := raw.(string); ok {
				queued[id] = true
			}
		}
	}

	out := make([]string, 0, len(itemIDs))
	for _, id := range itemIDs {
		if !queued[id] {
			out = append(out, id)
		}
	}
	return out
}

// PendingCount returns the number of unsynced, still-active operations
// (dead-lettered rows are excluded — they are terminal and surface separately
// via DeadLetterCount).
func (e *SyncEngine) PendingCount() (int, error) {
	var count int
	err := e.db.QueryRow("SELECT COUNT(*) FROM sync_queue WHERE synced_at IS NULL AND dead_lettered_at IS NULL").Scan(&count)
	return count, err
}

// FailedCount returns operations that exceeded max attempts but are not yet
// dead-lettered (kept for backward-compat with the existing /api/sync shape).
func (e *SyncEngine) FailedCount() (int, error) {
	var count int
	err := e.db.QueryRow("SELECT COUNT(*) FROM sync_queue WHERE synced_at IS NULL AND attempts >= max_attempts AND dead_lettered_at IS NULL").Scan(&count)
	return count, err
}

// DeadLetterCount returns the number of unresolved dead-lettered rows (the
// count the recovery banner keys off). plan-042.
func (e *SyncEngine) DeadLetterCount() (int, error) {
	var count int
	err := e.db.QueryRow("SELECT COUNT(*) FROM sync_queue WHERE dead_lettered_at IS NOT NULL AND resolved_at IS NULL").Scan(&count)
	return count, err
}

// PaymentOrphanCount returns the number of unresolved dead-lettered rows that
// represent money the workstation recorded but Cloud never saw (a payment whose
// order is gone). Surfaced with high priority. plan-042.
func (e *SyncEngine) PaymentOrphanCount() (int, error) {
	var count int
	err := e.db.QueryRow(`
		SELECT COUNT(*) FROM sync_queue
		WHERE dead_lettered_at IS NOT NULL AND resolved_at IS NULL
		  AND (entity_type = 'payment' OR dead_letter_reason = 'payment_orphan_order_gone')
	`).Scan(&count)
	return count, err
}

// DeadLetterItem is one unresolved dead-lettered row shown on the recovery page.
type DeadLetterItem struct {
	ID               int    `json:"id"`
	EntityType       string `json:"entity_type"`
	EntityID         string `json:"entity_id"`
	Operation        string `json:"operation"`
	DeadLetteredAt   string `json:"dead_lettered_at"`
	DeadLetterReason string `json:"dead_letter_reason"`
	LastError        string `json:"last_error,omitempty"`
	CreatedAt        string `json:"created_at"`
	IsPayment        bool   `json:"is_payment"`
}

// DeadLetters returns up to `limit` unresolved dead-lettered rows, payment
// orphans first (money needs attention), then newest. plan-042.
func (e *SyncEngine) DeadLetters(limit int) ([]DeadLetterItem, error) {
	rows, err := e.db.Query(`
		SELECT id, entity_type, entity_id, operation,
		       COALESCE(dead_lettered_at, ''), COALESCE(dead_letter_reason, ''),
		       COALESCE(last_error, ''), created_at
		FROM sync_queue
		WHERE dead_lettered_at IS NOT NULL AND resolved_at IS NULL
		ORDER BY (entity_type = 'payment' OR dead_letter_reason = 'payment_orphan_order_gone') DESC,
		         dead_lettered_at DESC
		LIMIT ?
	`, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []DeadLetterItem
	for rows.Next() {
		var it DeadLetterItem
		if err := rows.Scan(&it.ID, &it.EntityType, &it.EntityID, &it.Operation,
			&it.DeadLetteredAt, &it.DeadLetterReason, &it.LastError, &it.CreatedAt); err != nil {
			continue
		}
		it.IsPayment = it.EntityType == "payment" || it.DeadLetterReason == "payment_orphan_order_gone"
		items = append(items, it)
	}
	return items, rows.Err()
}

// PendingHistory returns queue rows that have NOT synced yet (pending or
// failed) — i.e. the actionable backlog. Synced rows are omitted; their live
// timeline lives in the sync trace feed instead. Ordered newest-first.
func (e *SyncEngine) PendingHistory(limit int) ([]QueueItem, error) {
	return e.queryHistory(`
		SELECT id, entity_type, entity_id, operation, attempts, last_error, created_at, synced_at
		FROM sync_queue
		WHERE synced_at IS NULL AND dead_lettered_at IS NULL
		ORDER BY id DESC
		LIMIT ?
	`, limit)
}

// RecentHistory returns recently synced operations.
func (e *SyncEngine) RecentHistory(limit int) ([]QueueItem, error) {
	return e.queryHistory(`
		SELECT id, entity_type, entity_id, operation, attempts, last_error, created_at, synced_at
		FROM sync_queue
		ORDER BY id DESC
		LIMIT ?
	`, limit)
}

func (e *SyncEngine) queryHistory(query string, limit int) ([]QueueItem, error) {
	rows, err := e.db.Query(query, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []QueueItem
	for rows.Next() {
		var item QueueItem
		var lastError, syncedAt *string
		if err := rows.Scan(&item.ID, &item.EntityType, &item.EntityID, &item.Operation,
			&item.Attempts, &lastError, &item.CreatedAt, &syncedAt); err != nil {
			continue
		}
		if lastError != nil {
			item.LastError = *lastError
		}
		if syncedAt != nil {
			item.SyncedAt = *syncedAt
		}
		items = append(items, item)
	}
	return items, nil
}

// RetryFailed resets failed operations for retry.
func (e *SyncEngine) RetryFailed() (int, error) {
	result, err := e.db.Exec(`
		UPDATE sync_queue SET attempts = 0, last_error = NULL
		WHERE synced_at IS NULL AND attempts >= max_attempts
	`)
	if err != nil {
		return 0, err
	}
	n, _ := result.RowsAffected()
	return int(n), nil
}

func (e *SyncEngine) processLoop() {
	for {
		select {
		case <-e.stopCh:
			return
		case <-e.wakeCh:
			// Immediate drain requested (e.g. just-created order). Same
			// online gate as the tick — if offline, the row stays queued
			// for the next online tick; the timer keeps ticking regardless.
			if !e.monitor.IsOnline() {
				continue
			}
			e.processQueue()
		case <-time.After(5 * time.Second):
			if !e.monitor.IsOnline() {
				continue
			}
			// Quiet doomed families FIRST (dead parent → dead-letter children), so
			// the backfill reconcilers below never resurrect them. Then backfill
			// BEFORE draining so freshly-enqueued rows push in this same cycle.
			// Orders first (an item_add needs the order's cloud_id, which only the
			// create backfill can restore), then items, then payments.
			e.reconcileDeadLetterCascade()
			// plan-818: the backfill reconcilers re-enqueue local unsynced rows. Gate
			// them on shouldAutoRecover() so a cross-branch re-pair (after a forced
			// unpair that kept branch-A data) never pushes that data onto branch-B.
			if e.shouldAutoRecover() {
				e.reconcileUnsyncedOrders()
				e.reconcileUnsyncedItems()
				e.reconcileUnsyncedPayments()
				e.reconcilePendingPeripherals()
			}
			e.processQueue()
		}
	}
}

func (e *SyncEngine) processQueue() {
	// Rate-limit gate (plan-042 G): if Cloud told us to back off (429/503
	// Retry-After) or we're mid-exponential-backoff, skip this drain entirely.
	// Covers BOTH the tick and Wake() paths since both funnel through here.
	if e.inCooldown() {
		return
	}
	// Order create/init operations FIRST (they mint the cloud_id every dependent
	// row waits on), then by priority, then oldest-first. Without the create-first
	// key a just-created order sorts LAST by created_at — so a backlog of skipped
	// rows (rows that `continue` without draining: an unresolved 401 before a
	// re-pair, or a dependency-wait) fills the batch and the fresh order.create is
	// never selected, so it never syncs and its WS-#### code never swaps.
	//
	// The batch cap is high (was 50 — a backlog of exactly ~50 stuck rows starved
	// every new order): one restaurant's workstation never has hundreds of
	// genuinely-unsynced rows, and a Cloud-wide 429/5xx still stops the cycle
	// after the first failure, so we never hammer Cloud with the full batch.
	// deferred_until parks a RECONCILE_PENDING till-close (WS-4) until its
	// Retry-After elapses; skip such rows here so a legitimately-draining close
	// doesn't tight-poll Cloud every tick. RFC3339 sorts lexically, so a string
	// compare against now is correct.
	nowRFC := time.Now().UTC().Format(time.RFC3339)
	rows, err := e.db.Query(`
		SELECT id, entity_type, entity_id, operation, payload, idempotency_key, attempts
		FROM sync_queue
		WHERE synced_at IS NULL AND attempts < max_attempts AND dead_lettered_at IS NULL
		  AND (deferred_until IS NULL OR deferred_until <= ?)
		ORDER BY (operation IN ('create', 'init')) DESC, priority DESC, created_at ASC
		LIMIT 500
	`, nowRFC)
	if err != nil {
		slog.Error("query sync queue", "error", err)
		return
	}
	defer rows.Close()

	for rows.Next() {
		var id int
		var entityType, entityID, operation, payload, idempotencyKey string
		var attempts int
		if err := rows.Scan(&id, &entityType, &entityID, &operation, &payload, &idempotencyKey, &attempts); err != nil {
			continue
		}

		// Trace flow: KDS item-status bumps ride the sync_queue too but are their
		// own subsystem — tag them FlowKds so the UI can filter them apart from
		// ordinary order/payment pushes.
		flow := FlowUp
		if entityType == "customer_order_item" {
			flow = FlowKds
		}

		// Per-device drain budget (plan-042 G): skip this row when its class has
		// hit the Cloud per-device rate (300/min general, 30/min payments) so the
		// workstation never self-originates a 429. SKIP (not break) so a throttled
		// class — e.g. payments over their 30/min sub-budget — never starves an
		// independent op behind it (an order.create); the skipped row stays queued
		// and drains next tick.
		if !e.budgetAllows(entityType) {
			slog.Debug("sync drain budget reached — skipping until next tick", "entity", entityType)
			continue
		}
		e.noteRequest(entityType)

		start := time.Now()
		pushErr, retryable := e.pushToCloud(entityType, entityID, operation, payload, idempotencyKey)
		latencyMS := time.Since(start).Milliseconds()
		if pushErr != nil {
			slog.Warn("sync push failed", "id", id, "entity", entityType, "retryable", retryable, "error", pushErr)
			traceStatus := statusError
			switch {
			case errors.Is(pushErr, errDependencyNotReady), errors.Is(pushErr, errAuthRejected), errors.Is(pushErr, errReconcilePending):
				traceStatus = statusSkip
			case retryable:
				traceStatus = statusRetry
			}
			e.tracer.up(flow, idempotencyKey, entityType, operation, entityID, traceStatus, latencyMS, attempts+1, pushErr)
			switch {
			case errors.Is(pushErr, errDependencyNotReady):
				// Local prerequisite missing (this entity's own create hasn't
				// synced → no cloud_id). NOT a Cloud fault, so don't back off the
				// whole cycle — skip to independent items so one waiting row can't
				// head-of-line-block the queue. Don't burn an attempt: the row is
				// valid and heals once its create syncs.
				e.db.Exec("UPDATE sync_queue SET last_error = ? WHERE id = ?", pushErr.Error(), id)
				continue
			case errors.Is(pushErr, errAuthRejected):
				// Row-specific 401/403 (bad/expired bearer on THIS row). Cloud is
				// up — pulls and other rows succeed — so SKIP this row instead of
				// halting the cycle. Otherwise one poisoned row (e.g. a
				// till_session with a stale cashier token) head-of-line-blocks every
				// independent row behind it: order.create never runs, its cloud_id
				// never fills, and dependent item bumps loop forever. Retryable +
				// no attempt burned: it heals once auth is restored.
				//
				// plan-818 K2: a kiosk payment whose baked kiosk token is stale can
				// NEVER push via /kiosk (cloudPost won't re-stamp that route), so it
				// would 401 here forever. Re-home it onto the workstation route in
				// place so it re-pushes under the fresh device token instead.
				if e.rehomeKioskPaymentRow(id, entityType, operation, entityID) {
					continue
				}
				e.db.Exec("UPDATE sync_queue SET last_error = ? WHERE id = ?", pushErr.Error(), id)
				continue
			case errors.Is(pushErr, errReconcilePending):
				// #817 Phase B (WS-4) — Cloud deferred this till-close: its manifest
				// items aren't all terminal yet. Row-specific wait (Cloud is up), so
				// SKIP like a dependency wait — do NOT stop the cycle, do NOT burn an
				// attempt, do NOT trip the global cooldown. Park via deferred_until
				// for the Retry-After so we don't re-poll every 5s tick. Unbounded:
				// Cloud's server-side defer bound is the terminal backstop.
				defer_ := 10 * time.Second
				var rpe *reconcilePendingError
				if errors.As(pushErr, &rpe) && rpe.retryAfter > 0 {
					defer_ = rpe.retryAfter
				}
				until := time.Now().UTC().Add(defer_).Format(time.RFC3339)
				e.db.Exec("UPDATE sync_queue SET last_error = ?, deferred_until = ? WHERE id = ?", pushErr.Error(), until, id)
				continue
			case errors.Is(pushErr, errDataConflict):
				// Permanent data conflict — Cloud rejected the row because an
				// entity it references is gone (404 order / 422 FK). Retry never
				// helps, so move it to the dead-letter terminal state IMMEDIATELY
				// (no five blind attempts) and continue so it never head-of-line-
				// blocks. plan-042.
				reason := "cloud_data_conflict"
				var dce *dataConflictError
				if errors.As(pushErr, &dce) {
					reason = dce.reason
				}
				// A payment whose order is gone is money that never reached Cloud
				// — flag it for the high-priority payment-orphan surfacing.
				if entityType == "payment" && reason == "cloud_404_order_gone" {
					reason = "payment_orphan_order_gone"
				}
				e.db.Exec("UPDATE sync_queue SET last_error = ? WHERE id = ?", pushErr.Error(), id)
				e.deadLetter(id, reason)
				continue
			case retryable:
				// Cloud-wide transient (5xx, network, 408/429): the environment is
				// down/throttling — every row behind would fail too. Record the
				// error + the retryable-failure streak but do NOT burn an attempt (a
				// long outage must not drive a good payment to max_attempts).
				// Row-specific auth faults (401/403) do NOT reach here — they are
				// caught by the errAuthRejected case above and skipped instead.
				now := time.Now().UTC().Format(time.RFC3339)
				e.db.Exec(`UPDATE sync_queue
					SET last_error = ?, transient_failures = transient_failures + 1,
					    first_transient_at = COALESCE(first_transient_at, ?)
					WHERE id = ?`, pushErr.Error(), now, id)
				// Stuck-transient backstop (plan-042 TH.3): if THIS row keeps failing
				// retryably while Cloud has demonstrably succeeded on other work since
				// its streak began, it's row-specific poison — dead-letter it and
				// CONTINUE so it never head-of-line-blocks. A genuine outage (no
				// success since) falls through to STOP the cycle.
				if e.isStuckTransient(id) {
					e.deadLetter(id, "stuck_transient")
					continue
				}
				// STOP the cycle to avoid hammering Cloud; Phase G cooldown paces the
				// wait until the next tick.
				return
			default:
				// Non-retryable (400/422/409 bad data): burn an attempt so a
				// genuinely bad row eventually leaves the active pool instead of
				// looping forever, and continue with independent items so it
				// doesn't stall unrelated syncs.
				e.db.Exec(
					"UPDATE sync_queue SET attempts = attempts + 1, last_error = ? WHERE id = ?",
					pushErr.Error(), id,
				)
				// Safety-net: once a genuinely-bad row that no classifier caught
				// exhausts its attempts, move it to the explicit dead-letter state
				// so it surfaces in the recovery UI instead of dying invisibly at
				// attempts>=max_attempts. plan-042.
				e.deadLetterIfExhausted(id)
				continue
			}
		}

		now := time.Now().UTC().Format(time.RFC3339)
		// Clear the retryable-failure streak on success (plan-042 TH.3) so a row
		// that flapped then recovered carries no stale poison counters.
		e.db.Exec("UPDATE sync_queue SET synced_at = ?, transient_failures = 0, first_transient_at = NULL WHERE id = ?", now, id)
		slog.Debug("synced", "id", id, "entity", entityType, "operation", operation)
		e.tracer.up(flow, idempotencyKey, entityType, operation, entityID, statusOK, latencyMS, attempts, nil)
	}
}

// deadLetter moves a sync_queue row to the explicit dead-letter terminal state
// (plan-042). A dead-lettered row is excluded from the active queue and
// PendingCount, stops looping, and surfaces in the recovery UI for operator
// Discard / Re-resolve / Re-create. Idempotent: only sets the state once.
func (e *SyncEngine) deadLetter(id int, reason string) {
	now := time.Now().UTC().Format(time.RFC3339)
	_, _ = e.db.Exec(
		"UPDATE sync_queue SET dead_lettered_at = ?, dead_letter_reason = ? WHERE id = ? AND dead_lettered_at IS NULL",
		now, reason, id,
	)
	slog.Warn("sync row dead-lettered", "id", id, "reason", reason)
}

// deadLetterIfExhausted dead-letters a row that has burned all its attempts, so
// a genuinely-bad row that no classifier caught still reaches a terminal,
// surfaced state instead of dying invisibly at attempts>=max_attempts.
func (e *SyncEngine) deadLetterIfExhausted(id int) {
	var attempts, maxAttempts int
	if err := e.db.QueryRow("SELECT attempts, max_attempts FROM sync_queue WHERE id = ?", id).Scan(&attempts, &maxAttempts); err != nil {
		return
	}
	if attempts >= maxAttempts {
		e.deadLetter(id, "max_attempts_exhausted")
	}
}

// ─── Rate-limit backpressure (plan-042 Phase G) ──────────────────────────────

// inCooldown reports whether the push loop is currently backing off after a
// Cloud 429/503. Checked before EVERY drain (tick + Wake).
func (e *SyncEngine) inCooldown() bool {
	e.rlMu.Lock()
	defer e.rlMu.Unlock()
	return time.Now().Before(e.cooldownUntil)
}

// noteThrottle extends the cooldown after a Cloud 429/503. It honors a
// Retry-After header (delta-seconds or HTTP-date); absent that, it uses
// exponential backoff with jitter, capped at rlBackoffMax.
func (e *SyncEngine) noteThrottle(retryAfter string) {
	e.rlMu.Lock()
	defer e.rlMu.Unlock()
	e.consecutiveThrottles++
	d := parseRetryAfter(retryAfter)
	if d <= 0 {
		shift := min(e.consecutiveThrottles-1, 8)
		d = min(rlBackoffBase<<uint(shift), rlBackoffMax)
		// jitter 0–500ms so co-located devices don't resync their bursts
		d += time.Duration(time.Now().UnixNano()%500) * time.Millisecond
	}
	if until := time.Now().Add(d); until.After(e.cooldownUntil) {
		e.cooldownUntil = until
	}
	slog.Warn("sync throttled — backing off", "retry_after", retryAfter, "cooldown_until", e.cooldownUntil.Format(time.RFC3339))
}

// noteCloudSuccess records a successful Cloud interaction: it resets the
// exponential backoff and clears any active cooldown so the loop resumes at
// full cadence.
func (e *SyncEngine) noteCloudSuccess() {
	e.rlMu.Lock()
	defer e.rlMu.Unlock()
	e.consecutiveThrottles = 0
	e.cooldownUntil = time.Time{}
}

// budgetAllows reports whether another push of the given entity type fits under
// the rolling 60s per-device budget. Prunes the windows as a side effect.
func (e *SyncEngine) budgetAllows(entityType string) bool {
	e.rlMu.Lock()
	defer e.rlMu.Unlock()
	cut := time.Now().Add(-time.Minute)
	e.reqTimes = pruneTimesBefore(e.reqTimes, cut)
	if len(e.reqTimes) >= rlGeneralPerMin {
		return false
	}
	if entityType == "payment" {
		e.payReqTimes = pruneTimesBefore(e.payReqTimes, cut)
		if len(e.payReqTimes) >= rlPaymentPerMin {
			return false
		}
	}
	return true
}

// noteRequest records a push against the rolling budget windows.
func (e *SyncEngine) noteRequest(entityType string) {
	e.rlMu.Lock()
	defer e.rlMu.Unlock()
	now := time.Now()
	e.reqTimes = append(e.reqTimes, now)
	if entityType == "payment" {
		e.payReqTimes = append(e.payReqTimes, now)
	}
}

// ThrottleState reports whether the loop is in cooldown and, if so, until when
// — surfaced on GET /api/sync so the UI can show "sync paused, resuming in Ns".
func (e *SyncEngine) ThrottleState() (bool, time.Time) {
	e.rlMu.Lock()
	defer e.rlMu.Unlock()
	return time.Now().Before(e.cooldownUntil), e.cooldownUntil
}

// isStuckTransient reports whether a row is poison — it has failed retryably
// enough times WHILE Cloud is reachable — versus a victim of a Cloud-wide
// outage. The discriminator is the ConnMonitor's independent health probe
// (IsOnline), NOT other queue rows succeeding: a persistent-5xx row that sorts
// FIRST (order.create, oldest) returns before anything else runs, so a
// success-based signal could never advance and the row would head-of-line-block
// forever. The monitor pings Cloud on its own timer, so it reports "up" even
// when only this one write keeps 5xx-ing → after the threshold the row
// dead-letters and the queue proceeds. During a true outage the monitor flips
// offline (processLoop then skips draining entirely), so an outage victim is
// never dead-lettered. plan-042 TH.3 / GAP-1 (Critical#2 fix).
func (e *SyncEngine) isStuckTransient(id int) bool {
	var failures int
	if err := e.db.QueryRow(
		"SELECT transient_failures FROM sync_queue WHERE id = ?", id,
	).Scan(&failures); err != nil {
		return false
	}
	return failures >= rlStuckTransientThreshold && e.monitor.IsOnline()
}

func pruneTimesBefore(ts []time.Time, cut time.Time) []time.Time {
	i := 0
	for i < len(ts) && ts[i].Before(cut) {
		i++
	}
	return ts[i:]
}

// parseRetryAfter parses an HTTP Retry-After value (delta-seconds or HTTP-date)
// into a duration. Returns 0 when empty/unparseable.
func parseRetryAfter(v string) time.Duration {
	v = strings.TrimSpace(v)
	if v == "" {
		return 0
	}
	if secs, err := strconv.Atoi(v); err == nil {
		if secs < 0 {
			return 0
		}
		return time.Duration(secs) * time.Second
	}
	if t, err := http.ParseTime(v); err == nil {
		if d := time.Until(t); d > 0 {
			return d
		}
	}
	return 0
}

// ─── Operator recovery actions (plan-042 Phase C) ────────────────────────────

// ErrNotDeadLettered is returned by Discard/ReResolve when the target row is not
// in a dead-lettered, unresolved state (maps to HTTP 409 NOT_DEAD_LETTERED).
var ErrNotDeadLettered = errors.New("row is not dead-lettered")

// ErrOrderStillExistsOnCloud guards the GAP-2 recover flow (Cloud still has the
// order → refuse to duplicate). ErrCloudUnreachable is defined in
// cloud_verifier.go and reused here for the existence-check failure path.
var ErrOrderStillExistsOnCloud = errors.New("order still exists on cloud")

// deadLetterState returns (isDeadLettered, isResolved) for a row; the error is
// sql.ErrNoRows when the row is absent.
func (e *SyncEngine) deadLetterState(id int) (bool, bool, error) {
	var deadAt, resolvedAt sql.NullString
	err := e.db.QueryRow("SELECT dead_lettered_at, resolved_at FROM sync_queue WHERE id = ?", id).Scan(&deadAt, &resolvedAt)
	if err != nil {
		return false, false, err
	}
	return deadAt.Valid && deadAt.String != "", resolvedAt.Valid && resolvedAt.String != "", nil
}

// Discard marks a dead-lettered row reconciled-by-hand: it stops counting toward
// the recovery banner and never retries. plan-042 TC.2.
func (e *SyncEngine) Discard(id int) error {
	dead, resolved, err := e.deadLetterState(id)
	if err != nil {
		return err
	}
	if !dead || resolved {
		return ErrNotDeadLettered
	}
	now := time.Now().UTC().Format(time.RFC3339)
	_, err = e.db.Exec("UPDATE sync_queue SET resolved_at = ?, resolution = 'discarded' WHERE id = ?", now, id)
	return err
}

// ReResolve returns a dead-lettered row to the active queue after the operator
// fixed the underlying Cloud data. It fully resets the row to a clean active
// state and wakes the engine; if the data is still broken the row simply
// re-dead-letters on the next attempt (idempotent, no harm). plan-042 TC.3.
func (e *SyncEngine) ReResolve(id int) error {
	dead, resolved, err := e.deadLetterState(id)
	if err != nil {
		return err
	}
	if !dead || resolved {
		return ErrNotDeadLettered
	}
	_, err = e.db.Exec(`UPDATE sync_queue
		SET dead_lettered_at = NULL, dead_letter_reason = NULL, resolved_at = NULL, resolution = NULL,
		    attempts = 0, transient_failures = 0, first_transient_at = NULL, last_error = NULL
		WHERE id = ?`, id)
	if err != nil {
		return err
	}
	e.Wake()
	return nil
}

// RecoverOrderOnCloud re-creates a 404-gone order on Cloud (plan-042 GAP-2 /
// TC.4). It verifies with Cloud that the order is truly gone (so it never
// duplicates an order Cloud still has), clears the dead cloud_id, resolves the
// family's dead-letter rows, and re-enqueues an idempotent order.create so the
// order + its items + payments sync afresh. Returns sql.ErrNoRows (404),
// errOrderStillExistsOnCloud (409), or errCloudUnreachable (502/503).
func (e *SyncEngine) RecoverOrderOnCloud(ctx context.Context, orderID string) error {
	var cloudID, orderType, note, takeawayName, takeawayPhone, customerID, customerPhone, tableID string
	var guestCount int
	err := e.db.QueryRow(`SELECT COALESCE(cloud_id,''), order_type, COALESCE(guest_count, 0),
		COALESCE(note,''), COALESCE(customer_takeaway_name,''), COALESCE(customer_takeaway_phone,''),
		COALESCE(customer_id,''),
		COALESCE((SELECT phone FROM customers c WHERE c.id = orders.customer_id), ''),
		COALESCE(table_id,'') FROM orders WHERE id = ?`, orderID).
		Scan(&cloudID, &orderType, &guestCount, &note, &takeawayName, &takeawayPhone, &customerID, &customerPhone, &tableID)
	if err != nil {
		return err // sql.ErrNoRows → 404
	}

	// Only re-create when Cloud confirms the order is gone. Refuse on 200
	// (would duplicate) and on any check failure (never re-create on uncertainty).
	if cloudID != "" {
		exists, cerr := e.cloudOrderExists(ctx, cloudID)
		if cerr != nil {
			return ErrCloudUnreachable
		}
		if exists {
			return ErrOrderStillExistsOnCloud
		}
	}

	// Re-home the order: back to "never synced" so the reconcilers treat it fresh.
	e.db.Exec("UPDATE orders SET cloud_id = NULL, synced_at = NULL WHERE id = ?", orderID)
	e.db.Exec("UPDATE order_items SET synced_at = NULL WHERE customer_order_id = ?", orderID)

	now := time.Now().UTC().Format(time.RFC3339)

	// The OLD dead order.create is superseded by the fresh create enqueued below
	// — mark it resolved so it stops surfacing.
	e.db.Exec(`UPDATE sync_queue SET resolved_at = ?, resolution = 'recovered'
		WHERE entity_type = 'order' AND operation = 'create' AND entity_id = ?
		  AND dead_lettered_at IS NOT NULL AND resolved_at IS NULL`, now, orderID)

	// RE-ACTIVATE the rest of the family (payments, KDS bumps, other order ops)
	// instead of resolving them — a payment recorded locally MUST still reach
	// Cloud after recovery (plan-042 Critical#1). Clearing the dead-letter +
	// resetting attempts puts them back in the active queue; each waits on the
	// new cloud_id (errDependencyNotReady) until the fresh create syncs, then
	// pushes. Cloud upserts idempotently, so this never double-charges.
	e.db.Exec(`UPDATE sync_queue
		SET dead_lettered_at = NULL, dead_letter_reason = NULL,
		    attempts = 0, transient_failures = 0, first_transient_at = NULL, last_error = NULL
		WHERE dead_lettered_at IS NOT NULL AND resolved_at IS NULL
		  AND (
		    (entity_type = 'order' AND entity_id = ? AND operation != 'create')
		    OR (entity_type = 'payment' AND entity_id IN (SELECT id FROM payments WHERE order_id = ?))
		    OR (entity_type = 'customer_order_item' AND entity_id IN (SELECT id FROM order_items WHERE customer_order_id = ?))
		  )`, orderID, orderID, orderID)

	orderShape := map[string]any{"client_order_id": orderID, "order_type": orderType, "guest_count": guestCount}
	if note != "" {
		orderShape["note"] = note
	}
	if takeawayName != "" {
		orderShape["customer_takeaway_name"] = takeawayName
	}
	if takeawayPhone != "" {
		orderShape["customer_takeaway_phone"] = takeawayPhone
	}
	if customerID != "" {
		orderShape["customer_id"] = customerID
		// Send the linked customer's phone so Cloud can find-or-create the
		// canonical customer when the LAN-minted customer_id is unknown to it
		// (mirrors local_pos.go — otherwise the re-created order 422s + dies).
		if customerPhone != "" {
			orderShape["customer_phone"] = customerPhone
		}
	}
	tableIDs := e.loadOrderTableIDs(orderID)
	if len(tableIDs) == 0 && tableID != "" {
		tableIDs = []string{tableID}
	}
	if len(tableIDs) > 0 {
		ids := make([]any, len(tableIDs))
		for i, t := range tableIDs {
			ids[i] = t
		}
		orderShape["table_ids"] = ids
		orderShape["table_id"] = tableIDs[0]
	}
	if err := e.Enqueue("order", orderID, "create", map[string]any{
		"bearer_token":    e.deviceToken(),
		"idempotency_key": uuid.NewString(),
		"order":           orderShape,
	}, 1); err != nil {
		return err
	}
	e.Wake()
	return nil
}

// cloudOrderExists checks Cloud for an order by its cloud id (plan-042 GAP-2).
// (true,nil) on 200, (false,nil) on 404, error on any other outcome so the
// caller refuses to re-create on uncertainty.
func (e *SyncEngine) cloudOrderExists(ctx context.Context, cloudID string) (bool, error) {
	base := e.resolveCloudURL()
	if base == "" {
		return false, fmt.Errorf("cloud URL not configured")
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, base+"/api/v1/workstation/orders/"+cloudID, nil)
	if err != nil {
		return false, err
	}
	req.Header.Set("Authorization", "Bearer "+e.deviceToken())
	req.Header.Set("Accept", "application/json")
	resp, err := e.httpClient.Do(req)
	if err != nil {
		return false, err
	}
	defer resp.Body.Close()
	switch resp.StatusCode {
	case http.StatusOK:
		return true, nil
	case http.StatusNotFound:
		return false, nil
	default:
		return false, fmt.Errorf("cloud existence check: unexpected status %d", resp.StatusCode)
	}
}

// pushToCloud dispatches a sync_queue entry to its handler. Unknown handlers
// return (nil, false) (treated as no-op success) so older queue rows from
// prior versions don't block the queue forever.
// Returns (err, retryable): retryable=true means transient (caller should stop
// the cycle); retryable=false means permanent (caller may skip and continue).
func (e *SyncEngine) pushToCloud(entityType, entityID, operation, payload, idempotencyKey string) (error, bool) {
	key := entityType + "." + operation
	handler, ok := e.handlers[key]
	if !ok {
		slog.Warn("sync: no handler", "key", key, "entity_id", entityID)
		return nil, false
	}

	var parsed map[string]any
	if err := json.Unmarshal([]byte(payload), &parsed); err != nil {
		// Permanent parse error — log + treat as success to drain the entry.
		slog.Error("sync: invalid payload", "key", key, "err", err)
		return nil, false
	}

	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()

	cloudResp, retryable, err := handler(ctx, entityID, parsed)
	if err != nil {
		if !retryable {
			// Non-retryable: log loudly but still return error so attempt counter
			// climbs and the row eventually moves out of the active pool.
			slog.Error("sync: non-retryable failure", "key", key, "entity_id", entityID, "err", err)
		}
		return err, retryable
	}

	// Success — persist cloud_id back to the local entity for follow-up ops.
	// The payment-create response keys the id as `payment_id` (see Cloud's
	// {data:{order_id, payment_id, status, paid_at}} shape), NOT `id` like the
	// order-create response. Reading the wrong key here left every payment with
	// an empty cloud_id, so every dependent confirm/fail stalled forever on
	// "create not synced yet". Prefer payment_id, fall back to id for safety.
	if entityType == "payment" && operation == "create" && cloudResp != nil {
		cloudID, _ := cloudResp["payment_id"].(string)
		if cloudID == "" {
			cloudID, _ = cloudResp["id"].(string)
		}
		if cloudID != "" {
			_, _ = e.db.Exec("UPDATE payments SET cloud_id = ? WHERE id = ?", cloudID, entityID)
		}
	}
	if entityType == "order" && operation == "create" && cloudResp != nil {
		cloudID, _ := cloudResp["id"].(string)
		// plan-041 — Cloud is the authority for the order_code. Adopt the
		// minted ORD-#### value back onto the local order (replacing the
		// provisional WS-... code) and stamp synced_at. cloud_id is needed for
		// follow-up ops (payments, status pushes).
		cloudCode, _ := cloudResp["order_code"].(string)
		if cloudID != "" || cloudCode != "" {
			now := time.Now().UTC().Format(time.RFC3339)
			if cloudCode != "" {
				_, _ = e.db.Exec(
					"UPDATE orders SET cloud_id = ?, order_code = ?, synced_at = ? WHERE id = ?",
					cloudID, cloudCode, now, entityID)
				// Notify LAN clients (pos-web/KDS) so the provisional code on
				// screen swaps to the real ORD-#### immediately.
				if e.onOrderCodeAssigned != nil {
					e.onOrderCodeAssigned(entityID, cloudCode)
				}
			} else {
				_, _ = e.db.Exec(
					"UPDATE orders SET cloud_id = ?, synced_at = ? WHERE id = ?",
					cloudID, now, entityID)
			}
		}
	}
	return nil, false
}

// ─── Payment sync handlers ────────────────────────────────────────────────

// paymentRouteBase returns the URL prefix to use for a given enqueued payment
// based on the originating terminal kind. The default (`/api/v1/kiosk/...`)
// preserves the legacy kiosk flow where the workstation forwards the kiosk's
// own bearer token; POS-originated rows set `target: "workstation"` so they
// go through the workstation-typed route under the workstation's bearer.
func paymentRouteBase(payload map[string]any) string {
	if target, _ := payload["target"].(string); target == "workstation" {
		return "/api/v1/workstation/payments"
	}
	return "/api/v1/kiosk/payments"
}

func appendPaymentPolicyFieldsToBody(body, payload map[string]any) {
	for _, key := range []string{
		"payment_option_id",
		"connection_id",
		"connection_option_id",
		"attempt_idempotency_key",
	} {
		if v, ok := payload[key]; ok && v != nil && v != "" {
			body[key] = v
		}
	}
	if rev, ok := payload["policy_revision"]; ok {
		switch v := rev.(type) {
		case float64:
			if v > 0 {
				body["policy_revision"] = int(v)
			}
		case int:
			if v > 0 {
				body["policy_revision"] = v
			}
		case json.Number:
			if i, err := v.Int64(); err == nil && i > 0 {
				body["policy_revision"] = i
			}
		}
	}
}

// handlePaymentCreate forwards POST {route}/payments with the originating
// terminal's Bearer token + Idempotency-Key (both carried in the queue
// payload). Route is selected by `target` field — see paymentRouteBase.
func (e *SyncEngine) handlePaymentCreate(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	bearer, _ := payload["bearer_token"].(string)
	idemKey, _ := payload["idempotency_key"].(string)

	// Cloud identifies the order by ITS own id, not the workstation's local id.
	// A WS-created order is assigned a different cloud_id once its own create
	// syncs up, so a payment carrying the local order_id would hit "order not
	// found" on Cloud and the paid amount would never sync. Resolve the local
	// order's cloud_id here:
	//   - local row + cloud_id  → use the cloud_id (pulled orders store
	//     cloud_id == local id, so this is a no-op for them).
	//   - local row, no cloud_id → the order's own create hasn't reached Cloud
	//     yet (it's enqueued first); retry transiently until it has.
	//   - no local row           → the order_id is already a Cloud id (online
	//     kiosk read the order straight from Cloud); forward it verbatim.
	orderID, _ := payload["order_id"].(string)
	if e.db != nil && orderID != "" {
		var cloudOrderID string
		err := e.db.QueryRow("SELECT COALESCE(cloud_id, '') FROM orders WHERE id = ?", orderID).Scan(&cloudOrderID)
		switch {
		case err == nil && cloudOrderID != "":
			orderID = cloudOrderID
		case err == nil && cloudOrderID == "":
			return nil, true, fmt.Errorf("order %s has no cloud_id yet (order create not synced): %w", orderID, errDependencyNotReady)
		}
	}

	body := map[string]any{
		"order_id":       orderID,
		"payment_method": payload["payment_method"],
		"amount":         payload["amount"],
	}
	// Cash-style methods require tendered_amount >= amount on Cloud; forward it
	// when present so the payment create doesn't 422 (see local_kiosk.go note).
	if t, ok := payload["tendered_amount"]; ok && t != nil {
		body["tendered_amount"] = t
	}
	// #817 Phase B — tip_amount + captured_at are workstation-route only. Cloud's
	// /api/v1/workstation/payments raises the cash auto-tender to amount+tip (B4)
	// and re-attributes the payment to the shift it was captured in via
	// captured_at (B3). The kiosk route's Cloud endpoint takes neither, so gate on
	// the workstation target to avoid a 422 on the kiosk path.
	if target, _ := payload["target"].(string); target == "workstation" {
		if tip, ok := payload["tip_amount"]; ok && tip != nil {
			body["tip_amount"] = tip
		}
		if ca, ok := payload["captured_at"].(string); ok && ca != "" {
			body["captured_at"] = ca
		}
	}
	if tr, ok := payload["terminal_response"].(string); ok && tr != "" {
		body["terminal_response"] = tr
	}
	// metadata is stored locally as a JSON string; Cloud validates it as an
	// object (split_count / amount_per_person etc.), so decode before forwarding.
	if md, ok := payload["metadata"].(string); ok && md != "" {
		var obj map[string]any
		if json.Unmarshal([]byte(md), &obj) == nil {
			body["metadata"] = obj
		}
	}
	// plan-044 R2 (T-R2.D2.2, not-yet-synced path) — a close-gap payment can be
	// CLAIMED (its local till_session_id stamped at the next shift open) before its
	// own create has synced UP. Read the attribution at SEND time so the create
	// carries it and Cloud's workstation store applies R6 (same-branch, in-progress)
	// attribution in one shot — no waiting on a follow-up payment.attribute op. This
	// is workstation-route only: the kiosk Cloud endpoint doesn't accept the field
	// (would 422), so a kiosk gap payment converges via the separate attribute op.
	if target, _ := payload["target"].(string); target == "workstation" && e.db != nil && entityID != "" {
		var sessID string
		_ = e.db.QueryRow("SELECT COALESCE(till_session_id, '') FROM payments WHERE id = ?", entityID).Scan(&sessID)
		if sessID != "" {
			body["till_session_id"] = sessID
		}
	}

	// plan-047 T6.5 — replay the immutable option/connection/revision/attempt
	// identity captured offline so Cloud can validate against the resolver.
	appendPaymentPolicyFieldsToBody(body, payload)
	if body["attempt_idempotency_key"] == nil {
		if key, _ := payload["idempotency_key"].(string); key != "" {
			body["attempt_idempotency_key"] = key
		}
	}

	// #538 — never sync a payment that exceeds the order's outstanding balance.
	// Cloud rejects an over-outstanding payment with 422 ("Payment amount exceeds
	// the outstanding order balance"); the order then never reaches isPaidEnough,
	// never closes on Cloud, and admin/POS stays "Chờ thanh toán" while the
	// workstation shows it paid. The local total is reconciled to Cloud's on item
	// sync-up (#525), so the local outstanding == Cloud's — cap the recorded
	// amount to it so the order settles and the paid status propagates. Covers
	// the race where the cashier pays before the reconcile lands (the amount was
	// derived from the pre-reconcile local total). A genuine overpay is thus
	// recorded as full settlement of what is actually owed.
	if e.db != nil && orderID != "" {
		var amt int64
		switch v := payload["amount"].(type) {
		case float64:
			amt = int64(v)
		case json.Number:
			amt, _ = v.Int64()
		case int64:
			amt = v
		case int:
			amt = int64(v)
		}
		if amt > 0 {
			var total, otherPaid int64
			_ = e.db.QueryRow(
				"SELECT total_amount FROM orders WHERE cloud_id = ? OR id = ?",
				orderID, orderID,
			).Scan(&total)
			_ = e.db.QueryRow(`
				SELECT COALESCE(SUM(amount), 0) FROM payments
				 WHERE order_id IN (SELECT id FROM orders WHERE cloud_id = ? OR id = ?)
				   AND id <> ? AND cloud_id IS NOT NULL AND cloud_id <> ''`,
				orderID, orderID, entityID,
			).Scan(&otherPaid)
			outstanding := total - otherPaid
			if total > 0 && outstanding >= 0 && amt > outstanding {
				// AUDIT FIX 2.3 (2026-07-14): the cap used to be one uniform
				// Warn — a ¥1 rounding cap and an ¥80 real-money cap looked
				// identical in the logs. A delta beyond one currency unit
				// means the cashier physically collected MORE than Cloud will
				// record (LAN/Cloud totals diverged before payment): that is
				// unreconciled till cash and must page ops, not whisper.
				delta := amt - outstanding
				if delta > 1 {
					slog.Error("payment capped by MORE than a rounding unit — cash collected locally exceeds what cloud records; reconcile the till (#538)",
						"order", orderID, "payment", entityID,
						"requested", amt, "outstanding", outstanding, "delta", delta)
				} else {
					slog.Warn("payment amount exceeds order outstanding — capping so the order settles (#538)",
						"order", orderID, "payment", entityID, "requested", amt, "outstanding", outstanding)
				}
				body["amount"] = outstanding
			}
		}
	}

	return e.cloudPost(ctx, paymentRouteBase(payload), bearer, idemKey, body)
}

// handlePaymentConfirm forwards POST {route}/payments/{cloud_id}/confirm.
// If the local payment has no cloud_id yet (create hasn't synced), this is
// transient — worker will retry once create succeeds and writes cloud_id.
func (e *SyncEngine) handlePaymentConfirm(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	return e.transitionPaymentCloud(ctx, entityID, payload, "confirm")
}

// handlePaymentFail forwards POST {route}/payments/{cloud_id}/fail.
func (e *SyncEngine) handlePaymentFail(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	return e.transitionPaymentCloud(ctx, entityID, payload, "fail")
}

func (e *SyncEngine) transitionPaymentCloud(ctx context.Context, entityID string, payload map[string]any, op string) (map[string]any, bool, error) {
	bearer, _ := payload["bearer_token"].(string)

	var cloudID string
	_ = e.db.QueryRow("SELECT COALESCE(cloud_id, '') FROM payments WHERE id = ?", entityID).Scan(&cloudID)
	if cloudID == "" {
		// payment.create hasn't synced yet → transient
		return nil, true, fmt.Errorf("payment %s has no cloud_id (create not synced yet): %w", entityID, errDependencyNotReady)
	}

	// Forward the A6 terminal fields under the same names Cloud expects.
	// confirm: terminal_ref + terminal_data; fail adds reason + error_code.
	// terminal_response is the legacy single field, still forwarded if present.
	body := map[string]any{}
	if tr, ok := payload["terminal_response"].(string); ok && tr != "" {
		body["terminal_response"] = tr
	}
	if ref, ok := payload["terminal_ref"].(string); ok && ref != "" {
		body["terminal_ref"] = ref
	}
	// terminal_data is stored locally as a JSON string; Cloud validates it as a
	// structured value, so decode before forwarding (fall back to the raw
	// string if it isn't valid JSON).
	if td, ok := payload["terminal_data"].(string); ok && td != "" {
		var obj any
		if json.Unmarshal([]byte(td), &obj) == nil {
			body["terminal_data"] = obj
		} else {
			body["terminal_data"] = td
		}
	}
	if op == "fail" {
		if reason, ok := payload["reason"].(string); ok && reason != "" {
			body["reason"] = reason
		}
		if ec, ok := payload["error_code"].(string); ok && ec != "" {
			body["error_code"] = ec
		}
	}
	return e.cloudPost(ctx, paymentRouteBase(payload)+"/"+cloudID+"/"+op, bearer, "", body)
}

// handlePaymentAttribute forwards
// POST /api/v1/workstation/payments/{cloud_id}/attribution with {till_session_id}.
// plan-044 R2 (T-R2.D2.2) — the workstation side of the two-way gap-claim sync. The
// session id is verbatim (Cloud upserts till_sessions by the workstation-supplied id),
// so only the PAYMENT needs a local→cloud remap. If the payment's create hasn't synced
// yet (no cloud_id) this is transient: the worker retries once payment.create writes
// cloud_id — same ladder as confirm/fail. Cloud endpoint D is idempotent, branch-guarded,
// and never 422s, so an early or duplicate attribution converges rather than dead-letters.
func (e *SyncEngine) handlePaymentAttribute(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	bearer, _ := payload["bearer_token"].(string)
	sent, _ := payload["till_session_id"].(string)

	var cloudID string
	_ = e.db.QueryRow("SELECT COALESCE(cloud_id, '') FROM payments WHERE id = ?", entityID).Scan(&cloudID)
	if cloudID == "" {
		return nil, true, fmt.Errorf("payment %s has no cloud_id (create not synced yet): %w", entityID, errDependencyNotReady)
	}

	resp, retryable, err := e.cloudPost(ctx, "/api/v1/workstation/payments/"+cloudID+"/attribution", bearer, "", map[string]any{
		"till_session_id": sent,
	})
	if err != nil {
		return resp, retryable, err
	}

	// plan-044 R2 (T-R2.D2.4) — Cloud is authoritative (R6) and echoes the payment's
	// CURRENT till_session_id even on a no-op. If it does NOT match what we sent, Cloud
	// could not resolve the session yet — its own till_session.open hasn't synced, so
	// the branch guard found no session — retry transiently until it can, rather than
	// declaring success while the two DBs diverge. Once it matches, adopt Cloud's
	// resolved value onto the local mirror so local + Cloud are byte-identical.
	resolved, _ := resp["till_session_id"].(string)
	if resolved != sent {
		return resp, true, fmt.Errorf("cloud has not applied attribution for payment %s yet (session not synced): %w", entityID, errDependencyNotReady)
	}
	if e.db != nil && resolved != "" {
		_, _ = e.db.Exec("UPDATE payments SET till_session_id = ? WHERE id = ?", resolved, entityID)
	}
	return resp, false, nil
}

// handleTableStatus forwards POST /api/v1/workstation/tables/{id}/status with
// {status}. The table id is verbatim (the local `tables` mirror keys on Cloud's
// table id), so no local→cloud remap is needed. Cloud is authoritative — TMS and
// the customer apps read it — so on success we adopt the returned status back
// onto the mirror, keeping both DBs byte-identical. cloudPost re-stamps the
// device token for the /workstation/ route, so no bearer is threaded here.
func (e *SyncEngine) handleTableStatus(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	status, _ := payload["status"].(string)
	if status == "" {
		// Malformed row — nothing to push. Drain it (non-retryable).
		return nil, false, nil
	}
	resp, retryable, err := e.cloudPost(ctx, "/api/v1/workstation/tables/"+entityID+"/status", "", "", map[string]any{
		"status": status,
	})
	if err != nil {
		return resp, retryable, err
	}
	if e.db != nil {
		if applied, _ := resp["status"].(string); applied != "" {
			_, _ = e.db.Exec("UPDATE tables SET status = ? WHERE id = ?", applied, entityID)
		}
	}
	return resp, false, nil
}

// ─── Order sync handlers ──────────────────────────────────────────────────

// handleOrderCreate forwards POST /api/v1/workstation/orders. The workstation
// device's Bearer token + a workstation-generated Idempotency-Key ride in the
// queue payload so retries after network errors hit the Cloud-side idempotency
// cache instead of creating duplicate orders.
func (e *SyncEngine) handleOrderCreate(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	bearer, _ := payload["bearer_token"].(string)
	idemKey, _ := payload["idempotency_key"].(string)

	body, _ := payload["order"].(map[string]any)
	if body == nil {
		body = map[string]any{}
	}

	return e.cloudPost(ctx, "/api/v1/workstation/orders", bearer, idemKey, body)
}

// ─── Cashier-shift sync handlers ─────────────────────────────────────────────
//
// All cloud paths sit under /api/v1/workstation/till/* and accept the
// workstation-supplied row id verbatim (idempotent upsert). The cashier's
// SSO bearer rides in `payload["bearer_token"]` and is required so the
// cloud audit trail records the actual person, not the workstation device.
// Without a bearer we still POST under the device's token + an explicit
// `opened_by_id` so the shift gets attributed to the right user offline.

func (e *SyncEngine) handleTillSessionOpen(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	bearer, _ := payload["bearer_token"].(string)
	body := map[string]any{
		"id":                   entityID,
		"session_code":         payload["session_code"],
		"till_id":              payload["till_id"],
		"branch_id":            payload["branch_id"],
		"currency_code":        payload["currency_code"],
		"opening_float_amount": payload["opening_float_amount"],
		"opening_note":         payload["opening_note"],
		"opened_by_id":         payload["opened_by_id"],
		"opener_name":          payload["opener_name"],
		"opened_at":            payload["opened_at"],
		"opening_counts":       payload["opening_counts"],
		// plan-046 — chain grouping (Cloud stores verbatim, workstation-authoritative).
		"chain_id":       payload["chain_id"],
		"chain_sequence": payload["chain_sequence"],
	}
	resp, retry, err := e.cloudPost(ctx, "/api/v1/workstation/till/sessions", bearer, entityID, body)
	if err == nil && resp != nil {
		if data, ok := resp["data"].(map[string]any); ok {
			if cloudID, ok := data["id"].(string); ok && cloudID != "" {
				_, _ = e.db.Exec("UPDATE till_sessions SET cloud_id = ? WHERE id = ?", cloudID, entityID)
			}
		}
	}
	return resp, retry, err
}

// reconcilePendingPeripherals re-enqueues peripheral rows the workstation mutated
// locally (pending_sync / pending_delete) that have no live queue op — healing an
// enqueue lost to a crash between the local write and Enqueue, so an offline edit
// always converges to Cloud once connectivity returns.
func (e *SyncEngine) reconcilePendingPeripherals() {
	rows, err := e.db.Query(`
		SELECT id, pending_delete FROM peripheral_devices
		WHERE (pending_sync = 1 OR pending_delete = 1)
		  AND NOT EXISTS (
		      SELECT 1 FROM sync_queue q
		      WHERE q.entity_type = 'peripheral' AND q.entity_id = peripheral_devices.id
		        AND q.synced_at IS NULL
		  )`)
	if err != nil {
		slog.Error("reconcile pending peripherals: query", "error", err)
		return
	}
	defer rows.Close()

	type pend struct {
		id       string
		isDelete bool
	}
	var pending []pend
	for rows.Next() {
		var id string
		var del int
		if err := rows.Scan(&id, &del); err != nil {
			continue
		}
		pending = append(pending, pend{id: id, isDelete: del == 1})
	}
	for _, p := range pending {
		op := "upsert"
		if p.isDelete {
			op = "delete"
		}
		_ = e.Enqueue("peripheral", p.id, op, map[string]any{}, 2)
	}
}

// handlePeripheralUpsert pushes a locally-created/edited peripheral UP to Cloud.
// Cloud upserts by the workstation id, so replays are idempotent. On success the
// local row's pending_sync flag is cleared. A row deleted locally before this
// drains is a no-op success (the delete op supersedes it).
func (e *SyncEngine) handlePeripheralUpsert(ctx context.Context, entityID string, _ map[string]any) (map[string]any, bool, error) {
	var (
		name, typ string
		active    int
		metadata  sql.NullString
	)
	err := e.db.QueryRow(`
		SELECT name, type, is_active, metadata
		FROM peripheral_devices WHERE id = ? AND pending_delete = 0`, entityID).
		Scan(&name, &typ, &active, &metadata)
	if err == sql.ErrNoRows {
		return nil, false, nil // gone (or tombstoned) → nothing to push
	}
	if err != nil {
		return nil, false, err
	}

	body := map[string]any{
		"id":        entityID,
		"name":      name,
		"type":      typ,
		"is_active": active == 1,
	}
	if metadata.Valid && metadata.String != "" {
		var m map[string]any
		if json.Unmarshal([]byte(metadata.String), &m) == nil {
			body["metadata"] = m
		}
	}

	resp, retry, err := e.cloudPost(ctx, "/api/v1/workstation/peripheral-devices", "", entityID, body)
	if err == nil {
		_, _ = e.db.Exec(`UPDATE peripheral_devices SET pending_sync = 0 WHERE id = ?`, entityID)
	}
	return resp, retry, err
}

// handlePeripheralDelete pushes a local tombstone UP as a Cloud DELETE, then
// hard-removes the local row. A 404 on Cloud (already gone / never synced) is
// treated as success.
func (e *SyncEngine) handlePeripheralDelete(ctx context.Context, entityID string, _ map[string]any) (map[string]any, bool, error) {
	status, retry, err := e.cloudDelete(ctx, "/api/v1/workstation/peripheral-devices/"+entityID)
	if err != nil {
		return nil, retry, err
	}
	if status == http.StatusNoContent || status == http.StatusOK || status == http.StatusNotFound {
		_, _ = e.db.Exec(`DELETE FROM peripheral_devices WHERE id = ?`, entityID)
		return nil, false, nil
	}
	return nil, true, fmt.Errorf("cloud delete peripheral %s: status %d", entityID, status)
}

// cloudDelete issues an authenticated DELETE to a /workstation/* route with the
// CURRENT device token. Returns the status code so callers can treat 404 as a
// benign already-gone.
func (e *SyncEngine) cloudDelete(ctx context.Context, path string) (int, bool, error) {
	baseURL := e.resolveCloudURL()
	if baseURL == "" {
		return 0, true, fmt.Errorf("cloud URL not configured")
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodDelete, baseURL+path, nil)
	if err != nil {
		return 0, false, err
	}
	req.Header.Set("Accept", "application/json")
	if tok := e.deviceToken(); tok != "" {
		req.Header.Set("Authorization", "Bearer "+tok)
	}
	resp, err := e.httpClient.Do(req)
	if err != nil {
		return 0, true, fmt.Errorf("cloud request: %w", err)
	}
	defer resp.Body.Close()
	_, _ = io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode >= 200 && resp.StatusCode < 300 {
		e.noteCloudSuccess()
	}
	return resp.StatusCode, false, nil
}

// handleTillSessionClose settles a FINAL close on Cloud (plan-046 — settleSyncUp
// with kind=final).
func (e *SyncEngine) handleTillSessionClose(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	return e.settleSyncUp(ctx, entityID, payload, "final", "close")
}

// handleTillSessionHandover settles a HANDOVER on Cloud (plan-046 T5.5). P7-C:
// there is NO Cloud handover route — it POSTs to the SAME /close endpoint with
// settlement_kind=handover; settleFromWorkstation branches on it. P7-D: it mirrors
// the FULL close sync body (pending-deps guard + drawer manifest), not just the
// snapshot write-back — via the shared settleSyncUp.
func (e *SyncEngine) handleTillSessionHandover(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	return e.settleSyncUp(ctx, entityID, payload, "handover", "handover")
}

// settleSyncUp is the shared close/handover sync-UP body (plan-046). The Cloud
// endpoint is the same /close route for both; `kind` sets settlement_kind and
// `opSuffix` the cloudPost idempotency key. On a 2xx it adopts Cloud's authoritative
// settlement_snapshot from the response (R7 adopt-if-present, ACK-regardless).
func (e *SyncEngine) settleSyncUp(ctx context.Context, entityID string, payload map[string]any, kind, opSuffix string) (map[string]any, bool, error) {
	bearer, _ := payload["bearer_token"].(string)

	// WS-5 — per-session ordering guard: the close MUST NOT precede its own
	// drawer rows. If any payment (create OR confirm) or cash-event for this
	// session is still queued, defer the close (errDependencyNotReady → SKIP, no
	// attempt burn, no head-of-line block) until they drain. Without this the
	// close would race ahead, Cloud would 503 RECONCILE_PENDING, and we'd burn a
	// round-trip to learn what we already know locally.
	var pendingDeps int
	if err := e.db.QueryRow(`
		SELECT COUNT(*) FROM sync_queue
		WHERE synced_at IS NULL AND dead_lettered_at IS NULL
		  AND (
		    (entity_type = 'payment' AND entity_id IN (SELECT id FROM payments WHERE till_session_id = ?))
		    OR (entity_type = 'till_cash_event' AND entity_id IN (SELECT id FROM till_cash_events WHERE session_id = ?))
		  )`, entityID, entityID).Scan(&pendingDeps); err == nil && pendingDeps > 0 {
		return nil, true, fmt.Errorf("close %s waiting on %d unsynced drawer row(s): %w", entityID, pendingDeps, errDependencyNotReady)
	}

	// WS-3 — build the drawer manifest at SEND time from the local ledger (not
	// the frozen enqueue payload) so it reflects the final shift state and can
	// resolve Cloud ids. Cloud settles only once every manifest item exists in a
	// reconcile-counted terminal status; a missing/pending item → 503
	// RECONCILE_PENDING → we defer (WS-4). Typed keys per Decision 1:
	//   payments   → {idempotency_key, order_id} (order_id = Cloud customer_order
	//                id; order_payments.idempotency_key is unique only per order)
	//   cash_events→ workstation-supplied id (till_cash_events dedups on id)
	manifestPayments := []map[string]any{}
	prows, err := e.db.Query(`
		SELECT p.idempotency_key, COALESCE(o.cloud_id, ''), p.order_id
		FROM payments p
		LEFT JOIN orders o ON o.id = p.order_id
		WHERE p.till_session_id = ? AND p.status IN ('pending','confirmed')`, entityID)
	if err != nil {
		return nil, true, fmt.Errorf("load close manifest payments: %w", err)
	}
	for prows.Next() {
		var idemKey, cloudOrderID, localOrderID string
		if err := prows.Scan(&idemKey, &cloudOrderID, &localOrderID); err != nil {
			continue
		}
		if cloudOrderID == "" {
			// Order not synced yet (no cloud_id) → the payment can't be terminal on
			// Cloud either. Defer rather than send an unmatchable manifest item.
			prows.Close()
			return nil, true, fmt.Errorf("close %s: order %s has no cloud_id yet: %w", entityID, localOrderID, errDependencyNotReady)
		}
		// Send BOTH order identifiers so the backend matches on whichever it keyed
		// its manifest gate to: order_id = Cloud customer_order_id (the primary,
		// per Decision 1), client_order_id = the workstation-local id Cloud stored
		// on workstation order-create. Extra key is ignored by Laravel validation,
		// so this is robust to either interpretation without a round-trip to
		// confirm the contract.
		manifestPayments = append(manifestPayments, map[string]any{
			"idempotency_key": idemKey,
			"order_id":        cloudOrderID,
			"client_order_id": localOrderID,
		})
	}
	prows.Close()

	manifestCashEvents := []string{}
	crows, err := e.db.Query("SELECT id FROM till_cash_events WHERE session_id = ?", entityID)
	if err == nil {
		for crows.Next() {
			var id string
			if crows.Scan(&id) == nil {
				manifestCashEvents = append(manifestCashEvents, id)
			}
		}
		crows.Close()
	}

	body := map[string]any{
		"closed_at":      payload["closed_at"],
		"closing_counts": payload["closing_counts"],
		"tender_details": payload["tender_details"],
		"closing_note":   payload["closing_note"],
		"counted_cash":   payload["counted_cash"],
		"cash_variance":  payload["cash_variance"],
		// plan-046 — settlement_kind + chain grouping (Cloud stores verbatim,
		// recomputes the authoritative snapshot, returns it in the response).
		"settlement_kind": kind,
		"chain_id":        payload["chain_id"],
		"chain_sequence":  payload["chain_sequence"],
		"manifest": map[string]any{
			"payments":    manifestPayments,
			"cash_events": manifestCashEvents,
		},
	}
	resp, retry, err := e.cloudPost(ctx,
		"/api/v1/workstation/till/sessions/"+entityID+"/close",
		bearer, entityID+":"+opSuffix, body)

	// Plan-046 R7 — adopt-if-present, ACK-regardless. Cloud computes the
	// authoritative snapshot synchronously and returns it INSIDE `data` (G3). If
	// present, overwrite the local provisional; if absent (a new workstation vs an
	// old Cloud during a mis-ordered deploy), keep the provisional and still ACK —
	// no errDependencyNotReady, so the queue never wedges (P7-1/P5-7). The op only
	// re-runs on a genuine transport error/retry from cloudPost.
	if err == nil && resp != nil {
		// cloudPost already unwraps the {data:{…}} envelope and returns the INNER
		// data map (see cloudPost :2938 `return wrap.Data`), so read the snapshot
		// directly off resp — NOT resp["data"] (that would double-unwrap to nil).
		if snap, ok := resp["settlement_snapshot"]; ok && snap != nil {
			if b, merr := json.Marshal(snap); merr == nil {
				_, _ = e.db.Exec("UPDATE till_sessions SET settlement_snapshot = ? WHERE id = ?", string(b), entityID)
			}
		}
	}
	return resp, retry, err
}

func (e *SyncEngine) handleTillSessionAbandon(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	bearer, _ := payload["bearer_token"].(string)
	body := map[string]any{
		"abandon_reason": payload["abandon_reason"],
		"closed_at":      payload["closed_at"],
	}
	return e.cloudPost(ctx,
		"/api/v1/workstation/till/sessions/"+entityID+"/abandon",
		bearer, entityID+":abandon", body)
}

func (e *SyncEngine) handleTillCashEventCreate(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	bearer, _ := payload["bearer_token"].(string)
	sessionID, _ := payload["session_id"].(string)
	body := map[string]any{
		"id":              entityID,
		"event_type":      payload["event_type"],
		"amount":          payload["amount"],
		"currency_code":   payload["currency_code"],
		"reason":          payload["reason"],
		"reference_no":    payload["reference_no"],
		"performed_by_id": payload["performed_by_id"],
		"occurred_at":     payload["occurred_at"],
	}
	resp, retry, err := e.cloudPost(ctx,
		"/api/v1/workstation/till/sessions/"+sessionID+"/cash-events",
		bearer, entityID, body)
	if err == nil && resp != nil {
		if data, ok := resp["data"].(map[string]any); ok {
			if cloudID, ok := data["id"].(string); ok && cloudID != "" {
				_, _ = e.db.Exec("UPDATE till_cash_events SET cloud_id = ? WHERE id = ?", cloudID, entityID)
			}
		}
	}
	return resp, retry, err
}

// ─── Order lifecycle sync handlers ──────────────────────────────────────────
//
// All ops post to /api/v1/workstation/orders/{entityID}/<action>, which
// the WorkstationOrderController on Cloud accepts with idempotent upsert
// semantics: a retry replaying the same payload after a transient network
// failure is a no-op on the second attempt. The workstation's device
// token is the bearer; the cashier identity (when known) rides in the
// payload as `actor_id` so the cloud audit trail still records the user.

// orderCloudPath returns the URL for an order op. When the order hasn't
// been seen by Cloud yet (cloud_id NULL) the handler returns retryable so
// the queue worker waits for the create payload to land first.
func (e *SyncEngine) orderCloudPath(localID string) (string, bool, error) {
	var cloudID string
	if err := e.db.QueryRow("SELECT COALESCE(cloud_id, '') FROM orders WHERE id = ?", localID).Scan(&cloudID); err != nil {
		// Order not found — caller is replaying for a deleted local row,
		// treat as non-retryable so the queue stops looping.
		return "", false, fmt.Errorf("order %s not found locally", localID)
	}
	if cloudID == "" {
		return "", true, fmt.Errorf("order %s has no cloud_id yet (create not synced): %w", localID, errDependencyNotReady)
	}
	return "/api/v1/workstation/orders/" + cloudID, false, nil
}

func (e *SyncEngine) handleOrderInit(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	body := map[string]any{
		"table_ids":   payload["table_ids"],
		"table_id":    payload["table_id"],
		"guest_count": payload["guest_count"],
	}
	return e.cloudPost(ctx, path+"/init", bearer, entityID+":init", body)
}

// handleOrderItemAdd forwards POST /api/v1/workstation/orders/{cloud_id}/items
// for lines appended to an order in LAN mode. The queue payload only carries
// the touched local item IDs (enqueued by handleLocalPosAddItems); the CURRENT
// state of each line — quantity, note, toppings — is read fresh here at push
// time. That matters because a single "add" can BR-OI06-merge into an existing
// pending line (bumping its quantity) rather than insert a new row: re-reading
// picks up the merged quantity, and Cloud's addItems upserts by the workstation
// item id so the same line converges without duplicating.
//
// unit_price is deliberately NOT sent — Cloud resolves the authoritative price
// server-side from the branch menu (plan-040 H17). Topping surcharge prices ARE
// sent because Cloud does not re-resolve toppings for the workstation flow.
func (e *SyncEngine) handleOrderItemAdd(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	idemKey, _ := payload["idempotency_key"].(string)

	rawIDs, _ := payload["item_ids"].([]any)
	pushedIDs := make([]string, 0, len(rawIDs))
	items := make([]map[string]any, 0, len(rawIDs))
	for _, raw := range rawIDs {
		itemID, _ := raw.(string)
		if itemID == "" {
			continue
		}
		item, ok, err := e.readOrderItemForSync(itemID)
		if err != nil {
			// Local DB read hiccup — transient, retry the whole row later.
			return nil, true, err
		}
		if !ok {
			// Line was deleted/voided locally between enqueue and drain; a
			// dedicated void/delete op syncs that transition, so skip here.
			continue
		}
		items = append(items, item)
		pushedIDs = append(pushedIDs, itemID)
	}
	if len(items) == 0 {
		// Every referenced line vanished locally — nothing to push. Mark done.
		return nil, false, nil
	}

	resp, retryable, err := e.cloudPost(ctx, path+"/items", bearer, idemKey, map[string]any{"items": items})
	if err != nil {
		return resp, retryable, err
	}
	// Stamp the pushed lines as synced so the reconciler stops re-enqueuing
	// them. Cloud upserts by item id, so an occasional double-send (stamp lost
	// to a crash) is a harmless no-op.
	e.markItemsSynced(pushedIDs)
	// #525 — adopt the Cloud-authoritative totals for THIS order. A POS-created
	// order is priced locally, but Cloud re-prices it here (promo / the specific
	// menu line), so the local total could exceed the Cloud balance and a "pay
	// all remaining" 422'd ("Payment amount exceeds the outstanding order
	// balance"). Pull the re-priced totals + line prices back down so the bill
	// the cashier settles matches Cloud.
	e.reconcileOrderFromCloud(entityID, resp)
	return resp, false, nil
}

// reconcileOrderFromCloud overwrites an order's local money fields with the
// Cloud-authoritative values returned by the addItems response (#525). Cloud
// serialises money as decimal STRINGS ("2201.00"); the workstation stores
// integer money, so each field is rounded to the nearest unit. Best-effort: a
// malformed/absent field is skipped, never fatal to the sync.
func (e *SyncEngine) reconcileOrderFromCloud(orderID string, resp map[string]any) {
	// cloudPost already UNWRAPS the JSON envelope and returns the `data` object,
	// so `resp` IS the order (total_amount / subtotal / items directly) — there
	// is no nested `resp["data"]`. Reading `resp["data"]` here made this a silent
	// no-op, so the local total never adopted Cloud's re-price and POS payments
	// kept 422'ing (order stuck at Cloud). #525/#538 follow-up.
	data := resp
	if data == nil {
		return
	}

	toInt := func(v any) (int, bool) {
		switch n := v.(type) {
		case string:
			f, err := strconv.ParseFloat(n, 64)
			if err != nil {
				return 0, false
			}
			return int(f + 0.5), true
		case float64:
			return int(n + 0.5), true
		case json.Number:
			f, err := n.Float64()
			if err != nil {
				return 0, false
			}
			return int(f + 0.5), true
		}
		return 0, false
	}

	toFloat := func(v any) (float64, bool) {
		switch n := v.(type) {
		case string:
			f, err := strconv.ParseFloat(n, 64)
			if err != nil {
				return 0, false
			}
			return f, true
		case float64:
			return n, true
		case json.Number:
			f, err := n.Float64()
			if err != nil {
				return 0, false
			}
			return f, true
		}
		return 0, false
	}

	// AUDIT FIX 2.2/2.3 (2026-07-14) — paid-order mismatch alarm: when the
	// order already holds local payments and Cloud's recompute moves the
	// total, the cash the cashier collected no longer matches what Cloud
	// says is owed (the order will sit "awaiting payment" on Cloud or show
	// an over-payment). That divergence used to be completely silent; it is
	// the point where a LAN/Cloud tax difference becomes REAL missing/extra
	// till money, so scream with everything ops needs to reconcile.
	var oldTotal, paidSum int
	_ = e.db.QueryRow(`SELECT COALESCE(total_amount, 0) FROM orders WHERE id = ?`, orderID).Scan(&oldTotal)
	_ = e.db.QueryRow(`
		SELECT COALESCE(SUM(amount), 0) FROM payments
		WHERE order_id = ? AND status IN ('confirmed', 'succeeded', 'paid')`, orderID).Scan(&paidSum)

	// Order-level totals.
	cols := []string{"total_amount", "subtotal", "tax_amount", "service_charge", "discount_amount"}
	sets := make([]string, 0, len(cols)+2)
	args := make([]any, 0, len(cols)+3)
	for _, c := range cols {
		if iv, ok := toInt(data[c]); ok {
			sets = append(sets, c+" = ?")
			args = append(args, iv)
		}
	}
	// plan-045 gap #3 — adopt the rounding SNAPSHOT (not just money), so a
	// locally-priced order converges to Cloud's snapshot and a subsequent local
	// re-price uses the same mode/decimals. A blank/absent field is skipped.
	if mode, ok := data["tax_rounding_mode"].(string); ok && mode != "" {
		sets = append(sets, "tax_rounding_mode = ?")
		args = append(args, mode)
	}
	switch d := data["tax_rounding_decimals"].(type) {
	case float64:
		sets = append(sets, "tax_rounding_decimals = ?")
		args = append(args, int(d))
	case json.Number:
		if iv, err := d.Int64(); err == nil {
			sets = append(sets, "tax_rounding_decimals = ?")
			args = append(args, int(iv))
		}
	case string:
		if iv, err := strconv.Atoi(d); err == nil {
			sets = append(sets, "tax_rounding_decimals = ?")
			args = append(args, iv)
		}
	}
	if len(sets) > 0 {
		args = append(args, orderID)
		if _, err := e.db.Exec("UPDATE orders SET "+strings.Join(sets, ", ")+" WHERE id = ?", args...); err != nil {
			slog.Warn("reconcile order totals from cloud failed", "order", orderID, "err", err)
		}
	}

	if newTotal, ok := toInt(data["total_amount"]); ok && paidSum > 0 && newTotal != oldTotal {
		slog.Error("cloud recompute changed the total of a LOCALLY-PAID order — till cash no longer matches what cloud says is owed; reconcile manually",
			"order", orderID,
			"old_total", oldTotal,
			"cloud_total", newTotal,
			"paid_locally", paidSum,
			"delta", newTotal-oldTotal,
		)
	}

	// Line prices + per-line tax — keep the bill lines consistent with the
	// reconciled total.
	//
	// AUDIT FIX 2.1 (2026-07-14): the per-line tax snapshot (tax_rate /
	// tax_amount / tax_type_id / tax_alcohol_escalated) is now adopted from
	// Cloud too. Cloud re-resolves each line at item_add time (menu overrides
	// and 酒類 escalation can differ from what the workstation resolved
	// offline); only adopting the ORDER-level figures left the local line
	// rates stale, so Σ local line tax ≠ local order.tax_amount and the LAN
	// Z-report (which recomputes group-once from the stored rates) used the
	// wrong rate.
	items, _ := data["items"].([]any)
	for _, raw := range items {
		it, ok := raw.(map[string]any)
		if !ok {
			continue
		}
		id, _ := it["id"].(string)
		if id == "" {
			continue
		}

		lineSets := make([]string, 0, 6)
		lineArgs := make([]any, 0, 7)
		if unit, ok := toInt(it["unit_price"]); ok {
			lineSets = append(lineSets, "unit_price = ?")
			lineArgs = append(lineArgs, unit)
		}
		if sub, ok := toInt(it["subtotal"]); ok {
			lineSets = append(lineSets, "subtotal = ?")
			lineArgs = append(lineArgs, sub)
		}
		if rate, ok := toFloat(it["tax_rate"]); ok {
			lineSets = append(lineSets, "tax_rate = ?")
			lineArgs = append(lineArgs, rate)
		}
		if taxAmt, ok := toInt(it["tax_amount"]); ok {
			lineSets = append(lineSets, "tax_amount = ?")
			lineArgs = append(lineArgs, taxAmt)
		}
		if typeID, ok := it["tax_type_id"].(string); ok && typeID != "" {
			lineSets = append(lineSets, "tax_type_id = ?")
			lineArgs = append(lineArgs, typeID)
		}
		if esc, ok := jsonToBool(it["tax_alcohol_escalated"]); ok {
			lineSets = append(lineSets, "tax_alcohol_escalated = ?")
			lineArgs = append(lineArgs, boolToInt(esc))
		}
		if len(lineSets) == 0 {
			continue
		}
		lineArgs = append(lineArgs, id)
		if _, err := e.db.Exec("UPDATE order_items SET "+strings.Join(lineSets, ", ")+" WHERE id = ?", lineArgs...); err != nil {
			slog.Warn("reconcile order line from cloud failed", "order", orderID, "item", id, "err", err)
		}
	}
}

// markItemsSynced stamps order_items.synced_at for the given local item ids.
func (e *SyncEngine) markItemsSynced(itemIDs []string) {
	if len(itemIDs) == 0 {
		return
	}
	now := time.Now().UTC().Format(time.RFC3339)
	for _, id := range itemIDs {
		_, _ = e.db.Exec("UPDATE order_items SET synced_at = ? WHERE id = ?", now, id)
	}
}

// reconcileUnsyncedItems is the self-healing backfill for item sync. It finds
// every order that already has a cloud_id but still owns non-voided lines with
// synced_at IS NULL — i.e. items that were added at order-creation time, or
// that never enqueued an item_add on an older build, or whose push has not yet
// landed — and enqueues one order.item_add per order to push them up.
//
// It skips an order that already has an unsynced item_add queued so a slow
// Cloud can't make the queue grow without bound; Cloud's idempotent upsert
// keeps a redundant send safe regardless. Runs on the periodic sync tick.
// loadOrderTableIDs returns every table bound to an order via the order_tables
// pivot (orders.table_id only holds the primary). Best-effort: returns nil on
// any error so the caller falls back to the primary table_id.
func (e *SyncEngine) loadOrderTableIDs(orderID string) []string {
	rows, err := e.db.Query(`SELECT table_id FROM order_tables WHERE order_id = ? ORDER BY table_id`, orderID)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var out []string
	for rows.Next() {
		var t string
		if err := rows.Scan(&t); err != nil {
			continue
		}
		out = append(out, t)
	}
	return out
}

// reconcileUnsyncedOrders re-enqueues an order.create for any local order that
// still has no cloud_id AND has no pending order.create already in the queue.
// This self-heals an order whose original create row was lost — dropped on an
// old build, cleared by hand, or never enqueued. Without it such an order is
// orphaned forever: its provisional WS-#### code never swaps to the Cloud
// ORD-#### and every dependent item_add / KDS bump loops on "cloud_id empty".
//
// Idempotent by construction: client_order_id (the local order id) is the
// durable key (plan-041), so Cloud maps a duplicate create back to the SAME
// order and returns the same ORD-#### rather than minting a second number. The
// NOT EXISTS guard means an order whose create is merely blocked/in-flight (row
// present, synced_at NULL) is left alone — this only fires when the row is gone.
// reconcileDeadLetterCascade dead-letters the still-active dependent rows of a
// permanently-dead parent, so a doomed family goes quiet in ONE place instead
// of every child looping on "dependency not synced" forever (plan-042 GAP-3).
// A child can never succeed once its parent create is permanently gone:
//   - order family: order.* ops for the order, payment.create for its payments,
//     customer_order_item.update_status (KDS bumps) for its items.
//   - till family:  till_session.{close,abandon} for the session and
//     till_cash_event.create for its cash events.
//
// Only children of an ALREADY dead-lettered parent are swept — a dependency
// waiter whose parent is still active is left to heal normally (TB.3). Runs on
// the tick before the backfill reconcilers.
func (e *SyncEngine) reconcileDeadLetterCascade() {
	now := time.Now().UTC().Format(time.RFC3339)

	for _, orderID := range e.deadLetteredEntityIDs("order", "create") {
		res, err := e.db.Exec(`
			UPDATE sync_queue SET dead_lettered_at = ?, dead_letter_reason = 'parent_order_dead'
			WHERE synced_at IS NULL AND dead_lettered_at IS NULL
			  AND (
			    (entity_type = 'order' AND entity_id = ? AND operation != 'create')
			    OR (entity_type = 'payment' AND entity_id IN (SELECT id FROM payments WHERE order_id = ?))
			    OR (entity_type = 'customer_order_item' AND entity_id IN (SELECT id FROM order_items WHERE customer_order_id = ?))
			  )`, now, orderID, orderID, orderID)
		if err != nil {
			slog.Error("cascade dead-letter (order)", "order_id", orderID, "error", err)
			continue
		}
		if n, _ := res.RowsAffected(); n > 0 {
			slog.Warn("cascade dead-lettered order children", "order_id", orderID, "rows", n)
		}
	}

	for _, sessionID := range e.deadLetteredEntityIDs("till_session", "open") {
		res, err := e.db.Exec(`
			UPDATE sync_queue SET dead_lettered_at = ?, dead_letter_reason = 'parent_session_dead'
			WHERE synced_at IS NULL AND dead_lettered_at IS NULL
			  AND (
			    (entity_type = 'till_session' AND entity_id = ? AND operation != 'open')
			    OR (entity_type = 'till_cash_event' AND entity_id IN (SELECT id FROM till_cash_events WHERE session_id = ?))
			  )`, now, sessionID, sessionID)
		if err != nil {
			slog.Error("cascade dead-letter (till)", "session_id", sessionID, "error", err)
			continue
		}
		if n, _ := res.RowsAffected(); n > 0 {
			slog.Warn("cascade dead-lettered till children", "session_id", sessionID, "rows", n)
		}
	}
}

// deadLetteredEntityIDs returns the entity ids of unresolved dead-lettered rows
// for the given entity_type + operation (the dead parents to cascade from).
func (e *SyncEngine) deadLetteredEntityIDs(entityType, operation string) []string {
	rows, err := e.db.Query(`
		SELECT DISTINCT entity_id FROM sync_queue
		WHERE entity_type = ? AND operation = ?
		  AND dead_lettered_at IS NOT NULL AND resolved_at IS NULL`, entityType, operation)
	if err != nil {
		slog.Error("query dead-lettered entities", "entity_type", entityType, "error", err)
		return nil
	}
	defer rows.Close()
	var ids []string
	for rows.Next() {
		var id string
		if err := rows.Scan(&id); err == nil {
			ids = append(ids, id)
		}
	}
	return ids
}

func (e *SyncEngine) reconcileUnsyncedOrders() {
	rows, err := e.db.Query(`
		SELECT o.id, o.order_type, o.guest_count,
		       COALESCE(o.note, ''), COALESCE(o.customer_takeaway_name, ''),
		       COALESCE(o.customer_takeaway_phone, ''), COALESCE(o.customer_id, ''),
		       COALESCE((SELECT phone FROM customers c WHERE c.id = o.customer_id), ''),
		       COALESCE(o.table_id, '')
		FROM orders o
		WHERE (o.cloud_id IS NULL OR o.cloud_id = '')
		  AND o.status != 'voided' AND o.voided_at IS NULL
		  AND NOT EXISTS (
		      SELECT 1 FROM sync_queue q
		      WHERE q.entity_type = 'order' AND q.operation = 'create'
		        AND q.entity_id = o.id AND q.synced_at IS NULL
		  )
		  -- plan-042 TH.1: never resurrect an order whose create is dead-lettered.
		  -- A legitimately-lost create (no dead-letter row) still self-heals here;
		  -- only an unresolved dead one is skipped (operator must Re-create/Re-resolve).
		  AND NOT EXISTS (
		      SELECT 1 FROM sync_queue q2
		      WHERE q2.entity_type = 'order' AND q2.operation = 'create'
		        AND q2.entity_id = o.id
		        AND q2.dead_lettered_at IS NOT NULL AND q2.resolved_at IS NULL
		  )
		ORDER BY o.created_at`)
	if err != nil {
		slog.Error("reconcile unsynced orders: query", "error", err)
		return
	}
	defer rows.Close()

	type pendingOrder struct {
		id, orderType, note, takeawayName, takeawayPhone, customerID, customerPhone, tableID string
		guestCount                                                                           int
	}
	var pend []pendingOrder
	for rows.Next() {
		var p pendingOrder
		if err := rows.Scan(&p.id, &p.orderType, &p.guestCount, &p.note,
			&p.takeawayName, &p.takeawayPhone, &p.customerID, &p.customerPhone, &p.tableID); err != nil {
			continue
		}
		pend = append(pend, p)
	}
	if err := rows.Err(); err != nil {
		slog.Error("reconcile unsynced orders: scan", "error", err)
		return
	}

	token := e.deviceToken()
	for _, p := range pend {
		orderShape := map[string]any{
			"client_order_id": p.id,
			"order_type":      p.orderType,
			"guest_count":     p.guestCount,
		}
		if p.note != "" {
			orderShape["note"] = p.note
		}
		if p.takeawayName != "" {
			orderShape["customer_takeaway_name"] = p.takeawayName
		}
		if p.takeawayPhone != "" {
			orderShape["customer_takeaway_phone"] = p.takeawayPhone
		}
		if p.customerID != "" {
			orderShape["customer_id"] = p.customerID
			// Phone lets Cloud resolve a LAN-minted customer_id it has never
			// seen (see local_pos.go / RecoverOrderOnCloud).
			if p.customerPhone != "" {
				orderShape["customer_phone"] = p.customerPhone
			}
		}
		tableIDs := e.loadOrderTableIDs(p.id)
		if len(tableIDs) == 0 && p.tableID != "" {
			tableIDs = []string{p.tableID}
		}
		if len(tableIDs) > 0 {
			ids := make([]any, len(tableIDs))
			for i, t := range tableIDs {
				ids[i] = t
			}
			orderShape["table_ids"] = ids
			orderShape["table_id"] = tableIDs[0] // legacy single-table contract
		}
		payload := map[string]any{
			"bearer_token":    token,
			"idempotency_key": uuid.NewString(),
			"order":           orderShape,
		}
		if err := e.Enqueue("order", p.id, "create", payload, 1); err != nil {
			slog.Warn("reconcile unsynced orders: enqueue", "order_id", p.id, "error", err)
			continue
		}
		slog.Info("reconcile: enqueued order.create backfill", "order_id", p.id)
	}
}

func (e *SyncEngine) reconcileUnsyncedItems() {
	rows, err := e.db.Query(`
		SELECT o.id, oi.id
		FROM orders o
		JOIN order_items oi ON oi.customer_order_id = o.id
		WHERE o.cloud_id IS NOT NULL AND o.cloud_id != ''
		  AND oi.synced_at IS NULL
		  AND (oi.status IS NULL OR oi.status != 'voided')
		  AND NOT EXISTS (
		      SELECT 1 FROM sync_queue q
		      WHERE q.entity_type = 'order' AND q.operation = 'item_add'
		        AND q.entity_id = o.id AND q.synced_at IS NULL
		  )
		  -- plan-042 TH.2: don't re-enqueue item_add for an order whose create is
		  -- dead-lettered (its cloud_id points at a gone Cloud order → every push
		  -- would 404). Operator Re-creates the order to recover it.
		  AND NOT EXISTS (
		      SELECT 1 FROM sync_queue q2
		      WHERE q2.entity_type = 'order' AND q2.operation = 'create'
		        AND q2.entity_id = o.id
		        AND q2.dead_lettered_at IS NOT NULL AND q2.resolved_at IS NULL
		  )
		ORDER BY o.id`)
	if err != nil {
		slog.Error("reconcile unsynced items: query", "error", err)
		return
	}
	defer rows.Close()

	byOrder := map[string][]string{}
	order := []string{}
	for rows.Next() {
		var orderID, itemID string
		if err := rows.Scan(&orderID, &itemID); err != nil {
			continue
		}
		if _, seen := byOrder[orderID]; !seen {
			order = append(order, orderID)
		}
		byOrder[orderID] = append(byOrder[orderID], itemID)
	}
	if err := rows.Err(); err != nil {
		slog.Error("reconcile unsynced items: scan", "error", err)
		return
	}

	token := e.deviceToken()
	for _, orderID := range order {
		itemIDs := byOrder[orderID]
		ids := make([]any, len(itemIDs))
		for i, id := range itemIDs {
			ids[i] = id
		}
		payload := map[string]any{
			"bearer_token":    token,
			"idempotency_key": uuid.NewString(),
			"order_id":        orderID,
			"item_ids":        ids,
		}
		if err := e.Enqueue("order", orderID, "item_add", payload, 1); err != nil {
			slog.Warn("reconcile unsynced items: enqueue", "order_id", orderID, "error", err)
			continue
		}
		slog.Info("reconcile: enqueued item_add backfill", "order_id", orderID, "items", len(itemIDs))
	}
}

// shouldAutoRecover reports whether the backfill reconcilers may push local
// unsynced rows up to Cloud. It guards against cross-branch contamination
// (plan-818): after a FORCED unpair that KEPT unsynced data, the handler records
// 'unpair.prev_branch_id' = the branch that data belongs to. If the workstation
// later re-pairs to a DIFFERENT branch, the reconcilers must NOT push branch-A's
// orders/payments onto branch-B. An empty prev — normal operation, or a
// same-branch re-pair that cleared it — means recover freely.
func (e *SyncEngine) shouldAutoRecover() bool {
	if e.db == nil {
		return true
	}
	var prev string
	_ = e.db.QueryRow("SELECT COALESCE(value, '') FROM settings WHERE key = 'unpair.prev_branch_id'").Scan(&prev)
	if prev == "" {
		return true
	}
	var cur string
	_ = e.db.QueryRow("SELECT COALESCE(value, '') FROM settings WHERE key = 'workstation_branch_id'").Scan(&cur)
	return prev == cur
}

// reconcileUnsyncedPayments re-enqueues a fresh payment.create for any locally
// recorded payment that Cloud never acknowledged (cloud_id empty) and that has
// NO live create row left in the queue to drain — e.g. its original enqueue
// failed (payment committed, Enqueue errored, request NOT failed), or the row
// was kept across a forced unpair (plan-818). Without this, that cash sits on
// disk forever with no push path.
//
// Both origins recover via the /api/v1/workstation/payments route under the
// workstation device token (cloudPost keeps it fresh):
//   - workstation-origin (POS): its baked token IS the device token.
//   - kiosk-origin (plan-818 K2): its baked kiosk token can't be re-stamped on the
//     /kiosk route, so recovery RE-HOMES the push to the workstation route. Cloud
//     accepts a workstation payment on a kiosk order (same branch/org, no channel
//     guard) and dedups by (order_id, idempotency_key) so it never double-charges.
//     A kiosk order still in `Confirmed` 409s on the workstation route until the
//     Cloud-side auto-promotion fix lands (godx-jp/godx-tempo#859); cloudPost
//     misreads that 409 as success and marks the row synced WITHOUT a cloud_id, so
//     `rehomed_at` caps kiosk re-homes at ONE to avoid re-enqueuing every tick.
//     Such a payment then parks (still counted by the unpair guard, never silently
//     lost) until the Cloud fix ships.
func (e *SyncEngine) reconcileUnsyncedPayments() {
	now := time.Now().UTC().Format(time.RFC3339Nano)
	rows, err := e.db.Query(`
		SELECT p.id, p.order_id, p.payment_method, p.amount, COALESCE(p.sync_target, ''),
		       p.tendered_amount, COALESCE(p.terminal_response, ''),
		       COALESCE(p.metadata, ''), COALESCE(p.idempotency_key, '')
		FROM payments p
		WHERE (p.cloud_id IS NULL OR p.cloud_id = '')
		  AND (
		        p.sync_target = 'workstation'
		     OR (p.sync_target = 'kiosk' AND p.rehomed_at IS NULL)  -- re-home kiosk at most once
		  )
		  AND p.status IN ('pending', 'confirmed', 'succeeded')
		  AND (
		        p.status IN ('confirmed', 'succeeded')
		     OR p.expires_at IS NULL OR p.expires_at = ''
		     OR datetime(p.expires_at) > datetime(?)
		  )
		  -- Order must resolve to a Cloud id: either a local order row that already
		  -- synced (has cloud_id), or NO local row at all (a kiosk order_id is itself
		  -- a Cloud id — handlePaymentCreate forwards it verbatim). Skip only when a
		  -- local order row exists but hasn't synced yet. (For workstation payments,
		  -- which always have a local row, this is equivalent to the prior
		  -- cloud_id-NOT-NULL join.)
		  AND NOT EXISTS (
		      SELECT 1 FROM orders o
		      WHERE o.id = p.order_id AND (o.cloud_id IS NULL OR o.cloud_id = '')
		  )
		  -- Skip if a create row is still live in the queue (it will drain itself).
		  AND NOT EXISTS (
		      SELECT 1 FROM sync_queue q
		      WHERE q.entity_type = 'payment' AND q.operation = 'create'
		        AND q.entity_id = p.id AND q.synced_at IS NULL
		  )
		  -- Never resurrect a payment whose create is dead-lettered (mirrors the
		  -- order reconciler): operator must Re-resolve it via the recovery page.
		  AND NOT EXISTS (
		      SELECT 1 FROM sync_queue q2
		      WHERE q2.entity_type = 'payment' AND q2.operation = 'create'
		        AND q2.entity_id = p.id
		        AND q2.dead_lettered_at IS NOT NULL AND q2.resolved_at IS NULL
		  )
		ORDER BY p.created_at`, now)
	if err != nil {
		slog.Error("reconcile unsynced payments: query", "error", err)
		return
	}
	defer rows.Close()

	type pendingPay struct {
		id, orderID, method, syncTarget, terminalResp, metadata, idemKey string
		amount                                                           int
		tendered                                                         sql.NullInt64
	}
	var pend []pendingPay
	for rows.Next() {
		var p pendingPay
		if err := rows.Scan(&p.id, &p.orderID, &p.method, &p.amount, &p.syncTarget,
			&p.tendered, &p.terminalResp, &p.metadata, &p.idemKey); err != nil {
			continue
		}
		pend = append(pend, p)
	}
	if err := rows.Err(); err != nil {
		slog.Error("reconcile unsynced payments: scan", "error", err)
		return
	}

	token := e.deviceToken()
	for _, p := range pend {
		payload := map[string]any{
			"bearer_token":   token,
			"target":         "workstation", // re-home kiosk rows onto the workstation route/identity
			"payment_id":     p.id,
			"order_id":       p.orderID,
			"payment_method": p.method,
			"amount":         p.amount,
		}
		// Reuse the payment's own idempotency key so a retry dedups into the same
		// Cloud row. Fall back to a fresh UUID only if the column is somehow empty.
		if p.idemKey != "" {
			payload["idempotency_key"] = p.idemKey
		} else {
			payload["idempotency_key"] = uuid.NewString()
		}
		if p.tendered.Valid {
			payload["tendered_amount"] = p.tendered.Int64
		}
		if p.terminalResp != "" {
			payload["terminal_response"] = p.terminalResp
		}
		if p.metadata != "" {
			payload["metadata"] = p.metadata
		}
		if err := e.Enqueue("payment", p.id, "create", payload, 1); err != nil {
			slog.Warn("reconcile unsynced payments: enqueue", "payment_id", p.id, "error", err)
			continue
		}
		// Cap kiosk re-homes at one (see rehomed_at rationale above): a Confirmed
		// kiosk order 409s on the workstation route and cloudPost's 409-as-success
		// would otherwise leave it cloud_id-NULL with no row → re-enqueued forever.
		// Workstation-origin rows keep the plan-818 behaviour (no cap — their orders
		// are payable so they don't hit the 409-as-success trap).
		if p.syncTarget == "kiosk" {
			e.db.Exec("UPDATE payments SET rehomed_at = ? WHERE id = ?", now, p.id)
			e.auditRehome(p.id, p.orderID, "orphan_reconcile")
			slog.Info("reconcile: re-homed kiosk payment to workstation route", "payment_id", p.id)
		} else {
			slog.Info("reconcile: enqueued payment.create backfill", "payment_id", p.id)
		}
	}
}

// auditRehome records a kiosk payment being re-homed onto the workstation route
// (plan-818 K2) in the audit_log so an operator can trace which cash Cloud
// received under the workstation identity instead of the kiosk's. `trigger` is
// "orphan_reconcile" (no queue row → reconciler) or "auth_reject" (a live kiosk
// row whose baked token was rejected → re-homed in place at push time).
func (e *SyncEngine) auditRehome(paymentID, orderID, trigger string) {
	e.db.Exec(
		`INSERT INTO audit_log (actor, action, entity_type, entity_id, details)
		 VALUES ('system', 'payment.rehomed_to_workstation', 'payment', ?, ?)`,
		paymentID,
		fmt.Sprintf(`{"origin":"kiosk","order_id":%q,"trigger":%q}`, orderID, trigger),
	)
}

// rehomeKioskPaymentRow converts a kiosk-origin payment.create queue row that
// just failed auth — its baked kiosk token is stale/revoked and cloudPost can't
// re-stamp the /kiosk route — onto the workstation route + fresh device token, in
// place, so it re-pushes under the workstation identity (plan-818 K2, the
// force-keep / dead-kiosk-token case). Returns true when it re-homed the row (the
// caller then skips the default last_error update). Capped by payments.rehomed_at
// — shared with reconcileUnsyncedPayments — so a Confirmed order's 409-as-success
// can never loop it.
func (e *SyncEngine) rehomeKioskPaymentRow(id int, entityType, operation, entityID string) bool {
	if entityType != "payment" || operation != "create" {
		return false
	}
	var syncTarget, orderID, rehomedAt string
	if err := e.db.QueryRow(
		`SELECT COALESCE(sync_target, ''), COALESCE(order_id, ''), COALESCE(rehomed_at, '')
		   FROM payments WHERE id = ?`, entityID).Scan(&syncTarget, &orderID, &rehomedAt); err != nil {
		return false
	}
	if syncTarget != "kiosk" || rehomedAt != "" {
		return false // not kiosk-origin, or already re-homed (cap)
	}
	token := e.deviceToken()
	if token == "" {
		return false // no workstation identity to re-home under
	}
	if _, err := e.db.Exec(
		`UPDATE sync_queue
		    SET payload = json_set(payload, '$.target', 'workstation', '$.bearer_token', ?),
		        last_error = 'kiosk auth reject → re-homed to workstation route'
		  WHERE id = ?`, token, id); err != nil {
		slog.Warn("rehome kiosk payment row", "id", id, "error", err)
		return false
	}
	e.db.Exec("UPDATE payments SET rehomed_at = ? WHERE id = ?",
		time.Now().UTC().Format(time.RFC3339Nano), entityID)
	e.auditRehome(entityID, orderID, "auth_reject")
	slog.Info("re-homed kiosk payment to workstation route after auth reject", "payment_id", entityID)
	return true
}

// readOrderItemForSync builds the Cloud addItems payload for one local line
// from its current SQLite state (+ toppings). Returns ok=false when the row no
// longer exists. product_sku_id is required by Cloud; an empty value (local-
// only line without a SKU) is skipped by returning ok=false so it can't 422 the
// whole batch.
func (e *SyncEngine) readOrderItemForSync(itemID string) (map[string]any, bool, error) {
	var (
		productSkuID string
		quantity     int
		note         string
	)
	err := e.db.QueryRow(`
		SELECT COALESCE(product_sku_id, ''), quantity, COALESCE(note, '')
		FROM order_items WHERE id = ?`, itemID,
	).Scan(&productSkuID, &quantity, &note)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, false, nil
	}
	if err != nil {
		return nil, false, err
	}
	if productSkuID == "" {
		return nil, false, nil
	}

	item := map[string]any{
		"id":             itemID,
		"product_sku_id": productSkuID,
		"quantity":       quantity,
	}
	if note != "" {
		item["note"] = note
	}

	toppings, err := e.readItemToppingsForSync(itemID)
	if err != nil {
		return nil, false, err
	}
	if len(toppings) > 0 {
		item["toppings"] = toppings
	}
	return item, true, nil
}

// readItemToppingsForSync returns the topping selections for a line in the
// shape Cloud's addItems endpoint accepts. Unit price is the workstation's
// stored surcharge (Cloud does not re-resolve toppings for this flow).
func (e *SyncEngine) readItemToppingsForSync(itemID string) ([]map[string]any, error) {
	rows, err := e.db.Query(`
		SELECT topping_group_item_id, COALESCE(product_sku_id, ''),
		       quantity, unit_price, COALESCE(note, '')
		FROM order_item_toppings WHERE order_item_id = ? ORDER BY rowid`, itemID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var toppings []map[string]any
	for rows.Next() {
		var (
			toppingGroupItemID string
			productSkuID       string
			quantity           int
			unitPrice          int
			note               string
		)
		if err := rows.Scan(&toppingGroupItemID, &productSkuID, &quantity, &unitPrice, &note); err != nil {
			return nil, err
		}
		t := map[string]any{
			"topping_group_item_id": toppingGroupItemID,
			"quantity":              quantity,
			"unit_price":            unitPrice,
		}
		if productSkuID != "" {
			t["product_sku_id"] = productSkuID
		}
		if note != "" {
			t["note"] = note
		}
		toppings = append(toppings, t)
	}
	return toppings, rows.Err()
}

func (e *SyncEngine) handleOrderUpdate(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	body := map[string]any{
		"guest_count": payload["guest_count"],
		"note":        payload["note"],
		"customer_id": payload["customer_id"],
		"order_type":  payload["order_type"],
	}
	return e.cloudPost(ctx, path+"/update", bearer, entityID+":update", body)
}

func (e *SyncEngine) handleOrderDelete(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	return e.cloudPost(ctx, path+"/delete", bearer, entityID+":delete", map[string]any{})
}

func (e *SyncEngine) handleOrderVoid(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	body := map[string]any{"void_reason": payload["void_reason"]}
	return e.cloudPost(ctx, path+"/void", bearer, entityID+":void", body)
}

func (e *SyncEngine) handleOrderCheckout(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	body := map[string]any{
		"discount_amount": payload["discount_amount"],
	}
	return e.cloudPost(ctx, path+"/checkout", bearer, entityID+":checkout", body)
}

func (e *SyncEngine) handleOrderItemUpdate(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	itemID, _ := payload["item_id"].(string)

	// AUDIT FIX (2026-07-14, found while fixing 2.4): the POS call site
	// enqueues the edit NESTED under "patch" ({"item_id", "patch": {...}} —
	// local_pos_phase1.go handleLocalPosUpdateItem) but this handler only read
	// the FLAT payload["quantity"]/["note"] keys — so every LAN qty/note edit
	// synced UP as {"quantity": null, "note": null} and Cloud patched nothing.
	// The regression test used the flat shape and never caught it. Read both
	// shapes (flat first for old queued rows, then the nested patch), and
	// forward toppings when present (2.4 forward-compat — Cloud's updateItem
	// accepts + re-resolves them; the LAN has no topping-edit UI yet, but the
	// pipe must not drop them when it grows one).
	patch, _ := payload["patch"].(map[string]any)
	pick := func(key string) any {
		if v, ok := payload[key]; ok && v != nil {
			return v
		}
		if patch != nil {
			if v, ok := patch[key]; ok && v != nil {
				return v
			}
		}
		return nil
	}

	body := map[string]any{}
	if q := pick("quantity"); q != nil {
		body["quantity"] = q
	}
	if n := pick("note"); n != nil {
		body["note"] = n
	}
	if tp := pick("toppings"); tp != nil {
		body["toppings"] = tp
	}
	return e.cloudPost(ctx, path+"/items/"+itemID, bearer, itemID+":update", body)
}

func (e *SyncEngine) handleOrderItemDelete(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	itemID, _ := payload["item_id"].(string)
	return e.cloudPost(ctx, path+"/items/"+itemID+"/delete", bearer, itemID+":delete", map[string]any{})
}

func (e *SyncEngine) handleOrderItemVoid(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	itemID, _ := payload["item_id"].(string)
	body := map[string]any{"void_reason": payload["void_reason"]}
	return e.cloudPost(ctx, path+"/items/"+itemID+"/void", bearer, itemID+":void", body)
}

// handleOrderItemRefund forwards
// POST /api/v1/workstation/orders/{cloudOrderID}/items/{itemID}/refund — the LAN
// mirror of the pos refund endpoint (plan-045 EP 4). {itemID} is the ORIGINAL
// line (Cloud upserts items by workstation local id, so the local id IS the
// Cloud item id). Cloud runs the same RefundService and returns reconciled
// totals + the negative line, which we adopt back down.
//
// Idempotency: the local refund-line UUID (client_order_item_id) is the anchor —
// a re-drain re-POSTs with the same key and Cloud's guard (refunded_quantity)
// makes it a no-op, so an offline refund never double-applies. Dependency-ordered
// on the order's cloud_id (orderCloudPath returns errDependencyNotReady until the
// parent order create has synced); dead-letter cascades with the parent order.
//
// TODO(plan-045): the Cloud workstation refund route
// (routes/api/workstation.php → POST /orders/{order}/items/{item}/refund) may not
// exist yet. Until it ships, this op will 404 (non-retryable) and dead-letter.
// The Go side + queue op are complete; wiring the Cloud endpoint is the remaining
// follow-up dependency (see plan-045 EP 4 / TASKS T14.1).
func (e *SyncEngine) handleOrderItemRefund(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	itemID, _ := payload["item_id"].(string)
	if itemID == "" {
		return nil, false, fmt.Errorf("item_refund missing item_id")
	}
	refundLineID, _ := payload["refund_line_id"].(string)

	body := map[string]any{
		"quantity": payload["quantity"],
		"reason":   payload["reason"],
	}
	// client_order_item_id lets Cloud upsert the refund line by the workstation's
	// UUID (idempotent — a re-drain converges instead of appending twice).
	if refundLineID != "" {
		body["client_order_item_id"] = refundLineID
	}

	// Idempotency-Key = the refund-line UUID so retries hit the same server-side
	// dedup slot.
	idemKey := refundLineID
	if idemKey == "" {
		idemKey = itemID + ":refund"
	}

	resp, retryable, err := e.cloudPost(ctx, path+"/items/"+itemID+"/refund", bearer, idemKey, body)
	if err != nil {
		return resp, retryable, err
	}
	// Adopt the Cloud-authoritative reconciled totals + the rounding snapshot
	// (gap #3) for this order.
	e.reconcileOrderFromCloud(entityID, resp)
	return resp, false, nil
}

func (e *SyncEngine) handleOrderApplyCoupon(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	body := map[string]any{
		"code":                           payload["coupon_code"],
		"customer_id":                    payload["customer_id"],
		"downgrade_exclusive_promotions": payload["downgrade_exclusive_promotions"],
	}
	return e.cloudPost(ctx, path+"/apply-coupon", bearer, entityID+":apply-coupon", body)
}

func (e *SyncEngine) handleOrderReleaseCoupon(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	return e.cloudPost(ctx, path+"/release-coupon", bearer, entityID+":release-coupon", map[string]any{})
}

func (e *SyncEngine) handleOrderMergeTable(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	tableID, _ := payload["table_id"].(string)
	body := map[string]any{"table_id": tableID}
	return e.cloudPost(ctx, path+"/merge-table", bearer, entityID+":merge:"+tableID, body)
}

func (e *SyncEngine) handleOrderUnmergeTable(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	path, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		return nil, retry, err
	}
	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	tableID, _ := payload["table_id"].(string)
	body := map[string]any{"table_id": tableID}
	return e.cloudPost(ctx, path+"/unmerge-table", bearer, entityID+":unmerge:"+tableID, body)
}

// handlePaymentRefund forwards
// POST /api/v1/workstation/orders/{cloudOrderID}/payments/{cloudPaymentID}/refund.
//
// The Cloud refund route is nested under the order (see backend
// routes/api/workstation.php) — there is NO top-level
// /api/v1/workstation/payments/{id}/refund, so posting there 404s and, since
// cloudPost treats 4xx as non-retryable, the refund is silently dropped and
// never reaches Cloud (#520 Bug B). The enqueued entityID is the local ORDER
// id; resolve both the order's and the payment's cloud_id before building the
// nested path. Either being unsynced is transient — the create payloads are
// enqueued ahead of us and the worker retries once they land.
func (e *SyncEngine) handlePaymentRefund(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	paymentID, _ := payload["payment_id"].(string)
	if paymentID == "" {
		return nil, false, fmt.Errorf("payment_refund missing payment_id")
	}

	orderPath, retry, err := e.orderCloudPath(entityID)
	if err != nil {
		// cloud_id NULL → retryable (order create not synced yet);
		// order missing → non-retryable. orderCloudPath sets both.
		return nil, retry, err
	}

	var cloudPaymentID string
	_ = e.db.QueryRow("SELECT COALESCE(cloud_id, '') FROM payments WHERE id = ?", paymentID).Scan(&cloudPaymentID)
	if cloudPaymentID == "" {
		// payment.create still queued ahead of us — wait.
		return nil, true, fmt.Errorf("payment %s has no cloud_id yet: %w", paymentID, errDependencyNotReady)
	}

	bearer, _ := payload["bearer_token"].(string)
	if bearer == "" {
		bearer = e.deviceToken()
	}
	body := map[string]any{
		"refund_id": payload["refund_id"],
		"amount":    payload["amount"],
		"note":      payload["note"],
	}
	refundID, _ := payload["refund_id"].(string)
	return e.cloudPost(ctx,
		orderPath+"/payments/"+cloudPaymentID+"/refund",
		bearer, refundID, body)
}

// ─── Customer sync handler ────────────────────────────────────────────────

// handleCustomerCreate forwards a locally-created customer (phone + name)
// to Cloud's find-or-create endpoint. Cloud dedupes by phone within the
// brand — so if the same phone already exists there, the response's id
// is the canonical one and we'd hit a duplicate next PullCustomers tick.
//
// Strategy: POST /api/v1/workstation/customers/find-or-create (device-authed)
// then clear the local row's `local_pending_sync` flag on success. We do
// NOT rewrite the local id even when Cloud returns a different one;
// the next PullCustomers replace-all replaces the local row with
// Cloud's canonical row (FK on customer_id in orders cascades the
// re-link). Keeping the local id stable in the meantime means any
// in-flight orders pointing at this customer don't break their FK
// inside the same sync tick.
//
// Pre-fix gap: this was enqueued at local_pos_phase2.go:191 but had no
// handler registered → silently dropped, Cloud never saw any customer
// the cashier created via "find-or-create" while LAN-only.
func (e *SyncEngine) handleCustomerCreate(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	phone, _ := payload["phone"].(string)
	firstName, _ := payload["first_name"].(string)
	if phone == "" {
		// No phone → Cloud's find-or-create can't dedupe. Drop the
		// row as non-retryable; cashier will need to retry from
		// pos-web with a phone.
		return nil, false, fmt.Errorf("customer.create missing phone")
	}

	// Push to the DEVICE-AUTHED workstation route. Cloud's /pos/* customer route
	// rejects a workstation device token (403 DEVICE_TYPE_NOT_ALLOWED), and the
	// SSO/terminal token baked at enqueue time goes stale (401) — so a
	// device-authed workstation can't use /pos at all. /api/v1/workstation/* is
	// device.auth on Cloud, and cloudPost auto re-stamps the CURRENT device token
	// for that prefix, so we just pass the baked bearer through: it's overridden
	// to the device token while paired, and used as-is only if the device is
	// unpaired (deviceToken empty).
	bearer, _ := payload["bearer_token"].(string)
	idemKey, _ := payload["idempotency_key"].(string)

	body := map[string]any{
		"phone":      phone,
		"first_name": firstName,
	}
	resp, retry, err := e.cloudPost(ctx,
		"/api/v1/workstation/customers/find-or-create", bearer, idemKey, body)
	if err != nil {
		return nil, retry, err
	}

	// Clear local_pending_sync so the row stops marking itself as in-
	// flight. PullCustomers will replace the row with Cloud's canonical
	// version on the next tick if the id differs.
	_, _ = e.db.Exec(
		`UPDATE customers SET local_pending_sync = 0 WHERE id = ?`,
		entityID,
	)
	return resp, false, nil
}

// deviceToken reads the cached workstation device token. Falls back to
// empty string when the device hasn't been paired yet — callers above
// only use it when payload["bearer_token"] is missing.
//
// The settings key is `device_token` (underscore) — the same key pairing
// writes and the handler's GetDeviceToken() reads. It used to read the
// non-existent `device.token` (dot) here, so this always returned "": the
// order-lifecycle fallbacks (handleOrderInit/update/void/...) and the item
// backfill reconciler would push with no Bearer and 401. The bug stayed
// hidden because every enqueued payload also carries an explicit bearer_token
// from GetDeviceToken(), so the fallback was never exercised — until the
// reconciler, which has no enqueue-site token, relied on it.
func (e *SyncEngine) deviceToken() string {
	if e.db == nil {
		return ""
	}
	var token string
	_ = e.db.QueryRow("SELECT COALESCE(value, '') FROM settings WHERE key = 'device_token'").Scan(&token)
	return token
}

// cloudPost performs a Cloud POST and classifies the response into
// (data, retryable, error). 4xx (except 408/429) → retryable=false.
func (e *SyncEngine) cloudPost(ctx context.Context, path, bearer, idemKey string, body map[string]any) (map[string]any, bool, error) {
	baseURL := e.resolveCloudURL()
	if baseURL == "" {
		return nil, true, fmt.Errorf("cloud URL not configured")
	}

	bodyBytes, err := json.Marshal(body)
	if err != nil {
		return nil, false, fmt.Errorf("marshal body: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, baseURL+path, bytes.NewReader(bodyBytes))
	if err != nil {
		return nil, false, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	// Every /api/v1/workstation/* route authenticates the DEVICE (device.auth),
	// so Cloud expects the CURRENT device token — read it fresh here, exactly
	// like the puller does via its GetDeviceToken callback. The bearer baked into
	// a queue row at enqueue time goes stale the instant the device re-pairs
	// (token rotates): every already-queued /workstation/* push then 401s with
	// "Invalid device token" even while pulls keep working, which freezes
	// order.create so its provisional WS-#### code never swaps to the Cloud
	// ORD-####. (till_session also historically baked the cashier's SSO token
	// here — a device.auth route rejects that outright; the real operator rides
	// in the request body via opened_by_id, not the bearer.) Non-workstation
	// routes (/kiosk, /pos) keep their originating terminal's token untouched.
	if strings.HasPrefix(path, "/api/v1/workstation/") {
		if devToken := e.deviceToken(); devToken != "" {
			bearer = devToken
		}
	}
	if bearer != "" {
		req.Header.Set("Authorization", "Bearer "+bearer)
	}
	if idemKey != "" {
		req.Header.Set("Idempotency-Key", idemKey)
	}

	resp, err := e.httpClient.Do(req)
	if err != nil {
		return nil, true, fmt.Errorf("cloud request: %w", err)
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))

	if resp.StatusCode >= 200 && resp.StatusCode < 300 {
		e.noteCloudSuccess() // plan-042 G: Cloud is up — clear backoff, stamp success.
		var wrap struct {
			Data map[string]any `json:"data"`
		}
		_ = json.Unmarshal(respBody, &wrap)
		return wrap.Data, false, nil
	}

	// #817 Phase B — workstation till-session close outcomes, matched by the
	// machine code the backend embeds in the body (same body-signature approach
	// as classifyDataConflict). These MUST be checked before the generic 409 and
	// 5xx branches below: SHIFT_REAPED may arrive as a 409 (which the generic
	// branch would mis-read as an idempotent success), and RECONCILE_PENDING as a
	// 503 (which the generic branch would treat as a Cloud-wide outage and trip
	// the global cooldown, stalling every other session). Cloud is UP in all
	// three cases, so none should back off the whole drain.
	if bodyStr := string(respBody); bodyStr != "" {
		switch {
		case strings.Contains(bodyStr, "RECONCILE_PENDING"):
			// Row-specific wait for the manifest to drain — retryable, parked via
			// deferred_until, no attempt burn, no global cooldown (see
			// errReconcilePending). Honor Retry-After for the park duration.
			return nil, true, &reconcilePendingError{
				retryAfter: parseRetryAfter(resp.Header.Get("Retry-After")),
				msg:        fmt.Sprintf("cloud %d RECONCILE_PENDING: %s", resp.StatusCode, bodyStr),
			}
		case strings.Contains(bodyStr, "VARIANCE_REASON_REQUIRED"):
			// Out-of-tolerance variance with no reason — Cloud-authoritative
			// enforcement fired (the local pre-close gate and Cloud's reconcile
			// diverged). Fatal: dead-letter immediately + surface to the operator
			// (never silent, never infinite-retry).
			return nil, false, &dataConflictError{
				reason: "close_variance_reason_required",
				msg:    fmt.Sprintf("cloud %d VARIANCE_REASON_REQUIRED: %s", resp.StatusCode, bodyStr),
			}
		case strings.Contains(bodyStr, "SHIFT_REAPED"):
			// The reaper flipped this shift to Expired mid-drain. Non-fatal by
			// design but not auto-settleable from here — dead-letter + surface so a
			// manager resolves it via manualSettle (preserves plan-032 manager-only
			// overrides). #817 Phase B locked decision.
			return nil, false, &dataConflictError{
				reason: "close_shift_reaped",
				msg:    fmt.Sprintf("cloud %d SHIFT_REAPED: %s", resp.StatusCode, bodyStr),
			}
		}
	}

	// 409 = duplicate idempotency key: Cloud already processed this row and
	// returns the ORIGINAL response (with the canonical ids) in the body. That's
	// success, not a conflict — surface the data so create writeback can capture
	// cloud_id. This also lets a re-push backfill cloud_id for entities that were
	// created on Cloud but never recorded their id locally (the payment_id-key
	// bug), and makes idempotent retries after a network blip self-heal.
	if resp.StatusCode == http.StatusConflict {
		e.noteCloudSuccess() // idempotent replay = Cloud processed it — treat as success.
		var wrap struct {
			Data map[string]any `json:"data"`
		}
		_ = json.Unmarshal(respBody, &wrap)
		return wrap.Data, false, nil
	}

	// 401/403 are transient auth/config faults (expired/rotated token, device
	// type not yet allowed for the route) — the queued row itself is valid, the
	// environment isn't. Treat them as retryable so a misconfigured Cloud window
	// never burns a good payment's attempts to permanent failure; the row heals
	// automatically once auth is fixed server-side (re-pair / device retype).
	// Wrap errAuthRejected so processQueue SKIPs this row instead of halting the
	// whole cycle: the fault is row-specific (bad bearer), NOT Cloud-wide, so a
	// single poisoned row must not block independent rows (order.create → the
	// cloud_id every dependent item bump waits on) behind it.
	if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden {
		return nil, true, fmt.Errorf("cloud %d: %s: %w", resp.StatusCode, string(respBody), errAuthRejected)
	}

	// 404 / entity-missing 422 are PERMANENT data conflicts — the row references
	// a Cloud entity that no longer exists (reseed, DR restore, admin delete).
	// Retry never helps, so return errDataConflict (non-retryable) and let
	// processQueue dead-letter the row immediately (plan-042) rather than burning
	// attempts and looping. The reason drives dead_letter_reason + the recovery
	// UI's order/payment grouping.
	if reason := classifyDataConflict(resp.StatusCode, string(respBody)); reason != "" {
		return nil, false, &dataConflictError{
			reason: reason,
			msg:    fmt.Sprintf("cloud %d (data conflict: %s): %s", resp.StatusCode, reason, string(respBody)),
		}
	}

	// 5xx / 408 / 429 are Cloud-wide transients — the whole environment is
	// down/throttling, so processQueue stops the cycle (everything behind would
	// fail too) rather than hammering Cloud row by row.
	retryable := resp.StatusCode >= 500 ||
		resp.StatusCode == http.StatusRequestTimeout ||
		resp.StatusCode == http.StatusTooManyRequests
	if retryable {
		// plan-042 G: honor Cloud backpressure. Set the cooldown from Retry-After
		// (429/503) or exponential backoff (bare 5xx) so the loop waits exactly as
		// long as asked instead of re-bursting every 5s tick.
		e.noteThrottle(resp.Header.Get("Retry-After"))
	}
	return nil, retryable, fmt.Errorf("cloud %d: %s", resp.StatusCode, string(respBody))
}

type QueueItem struct {
	ID         int    `json:"id"`
	EntityType string `json:"entity_type"`
	EntityID   string `json:"entity_id"`
	Operation  string `json:"operation"`
	Attempts   int    `json:"attempts"`
	LastError  string `json:"last_error,omitempty"`
	CreatedAt  string `json:"created_at"`
	SyncedAt   string `json:"synced_at,omitempty"`
}
