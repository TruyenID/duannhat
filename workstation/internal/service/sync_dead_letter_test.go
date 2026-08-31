package service

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

func setOnline(e *SyncEngine) {
	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
}

func deadLetterOf(t *testing.T, db *store.DB, id int) (reason string, resolved string) {
	t.Helper()
	var r, res *string
	err := db.QueryRow("SELECT dead_letter_reason, resolution FROM sync_queue WHERE id = ? AND dead_lettered_at IS NOT NULL", id).Scan(&r, &res)
	if err != nil {
		return "", ""
	}
	if r != nil {
		reason = *r
	}
	if res != nil {
		resolved = *res
	}
	return reason, resolved
}

func queueID(t *testing.T, db *store.DB, entityType, entityID string) int {
	t.Helper()
	var id int
	if err := db.QueryRow("SELECT id FROM sync_queue WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1", entityType, entityID).Scan(&id); err != nil {
		t.Fatalf("queueID(%s,%s): %v", entityType, entityID, err)
	}
	return id
}

// 422 entity-missing on order.create → dead-lettered immediately, not maxed out.
func TestDataConflict422DeadLettersImmediately(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, `{"message":"One or more tables do not exist."}`, http.StatusUnprocessableEntity)
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO orders (id, order_type, status) VALUES ('o1','dine_in','open')`)
	e.Enqueue("order", "o1", "create", map[string]any{
		"bearer_token": "t", "order": map[string]any{"client_order_id": "o1", "order_type": "dine_in"},
	}, 1)
	setOnline(e)
	e.processQueue()

	id := queueID(t, db, "order", "o1")
	reason, _ := deadLetterOf(t, db, id)
	if reason != "cloud_422_entity_missing" {
		t.Fatalf("want cloud_422_entity_missing, got %q", reason)
	}
	var attempts, maxA int
	db.QueryRow("SELECT attempts, max_attempts FROM sync_queue WHERE id = ?", id).Scan(&attempts, &maxA)
	if attempts >= maxA {
		t.Fatalf("attempts should NOT be maxed for immediate dead-letter, got %d/%d", attempts, maxA)
	}
}

// 404 on item_add → dead-lettered cloud_404_order_gone.
func TestDataConflict404DeadLetters(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, `{"message":"No query results for model [App\\Models\\CustomerOrder] x"}`, http.StatusNotFound)
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO orders (id, cloud_id, order_type, status) VALUES ('o1','o1','dine_in','open')`)
	db.Exec(`INSERT INTO order_items (id, customer_order_id, product_sku_id, quantity) VALUES ('i1','o1','sku1',1)`)
	e.Enqueue("order", "o1", "item_add", map[string]any{"bearer_token": "t", "item_ids": []any{"i1"}}, 1)
	setOnline(e)
	e.processQueue()

	id := queueID(t, db, "order", "o1")
	if reason, _ := deadLetterOf(t, db, id); reason != "cloud_404_order_gone" {
		t.Fatalf("want cloud_404_order_gone, got %q", reason)
	}
}

// 503 is transient — never dead-lettered.
func TestTransient503NotDeadLettered(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Retry-After", "1")
		http.Error(w, `{"message":"maintenance"}`, http.StatusServiceUnavailable)
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO orders (id, order_type, status) VALUES ('o1','dine_in','open')`)
	e.Enqueue("order", "o1", "create", map[string]any{"bearer_token": "t", "order": map[string]any{"client_order_id": "o1"}}, 1)
	setOnline(e)
	e.processQueue()

	id := queueID(t, db, "order", "o1")
	if reason, _ := deadLetterOf(t, db, id); reason != "" {
		t.Fatalf("503 must NOT dead-letter, got reason %q", reason)
	}
	if !e.inCooldown() {
		t.Fatal("503 with Retry-After should set a cooldown")
	}
}

// Cascade: a dead order.create dead-letters its child KDS bump (parent_order_dead).
func TestCascadeDeadLettersChildren(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://127.0.0.1:0")
	db.Exec(`INSERT INTO orders (id, order_type, status) VALUES ('o1','dine_in','open')`)
	db.Exec(`INSERT INTO order_items (id, customer_order_id, product_sku_id, quantity) VALUES ('i1','o1','sku1',1)`)
	// Parent create already dead-lettered.
	e.Enqueue("order", "o1", "create", map[string]any{"order": map[string]any{"client_order_id": "o1"}}, 1)
	parentID := queueID(t, db, "order", "o1")
	e.deadLetter(parentID, "cloud_422_entity_missing")
	// Child KDS bump still active.
	e.Enqueue("customer_order_item", "i1", "update_status", map[string]any{"order_id": "o1", "item_id": "i1", "status": "preparing"}, 2)
	childID := queueID(t, db, "customer_order_item", "i1")

	e.reconcileDeadLetterCascade()

	if reason, _ := deadLetterOf(t, db, childID); reason != "parent_order_dead" {
		t.Fatalf("child should cascade to parent_order_dead, got %q", reason)
	}
}

// Discard marks resolved + drops from the dead-letter count.
func TestDiscardResolves(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://127.0.0.1:0")
	e.Enqueue("order", "o1", "create", map[string]any{}, 1)
	id := queueID(t, db, "order", "o1")
	e.deadLetter(id, "cloud_422_entity_missing")

	if err := e.Discard(id); err != nil {
		t.Fatalf("Discard: %v", err)
	}
	if _, res := deadLetterOf(t, db, id); res != "discarded" {
		t.Fatalf("want discarded, got %q", res)
	}
	if n, _ := e.DeadLetterCount(); n != 0 {
		t.Fatalf("discarded row must not count, got %d", n)
	}
	// Second discard → not dead-lettered anymore.
	if err := e.Discard(id); err != ErrNotDeadLettered {
		t.Fatalf("want ErrNotDeadLettered on re-discard, got %v", err)
	}
}

// Re-resolve returns the row to the active queue.
func TestReResolveRequeues(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://127.0.0.1:0")
	e.Enqueue("order", "o1", "create", map[string]any{}, 1)
	id := queueID(t, db, "order", "o1")
	e.deadLetter(id, "cloud_422_entity_missing")

	if err := e.ReResolve(id); err != nil {
		t.Fatalf("ReResolve: %v", err)
	}
	var dead *string
	db.QueryRow("SELECT dead_lettered_at FROM sync_queue WHERE id = ?", id).Scan(&dead)
	if dead != nil {
		t.Fatalf("re-resolved row must clear dead_lettered_at")
	}
	if n, _ := e.PendingCount(); n != 1 {
		t.Fatalf("re-resolved row should be active again, PendingCount=%d", n)
	}
}

// Stuck-transient: a poison row (persistent 5xx) dead-letters once Cloud is
// proven up; the queue continues past it.
func TestStuckTransientDeadLetters(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://127.0.0.1:0")
	e.Enqueue("payment", "p1", "create", map[string]any{}, 1)
	id := queueID(t, db, "payment", "p1")
	// Seed a long retryable streak that began in the past.
	db.Exec("UPDATE sync_queue SET transient_failures = 20, first_transient_at = '2020-01-01T00:00:00Z' WHERE id = ?", id)
	// Cloud reachable (monitor online) but this one row keeps 5xx-ing → poison.
	setOnline(e)

	if !e.isStuckTransient(id) {
		t.Fatal("row with 20 failures while Cloud is online should be stuck-transient")
	}
	e.deadLetter(id, "stuck_transient")
	if reason, _ := deadLetterOf(t, db, id); reason != "stuck_transient" {
		t.Fatalf("want stuck_transient, got %q", reason)
	}
}

// Full outage: no success since the streak began → NOT stuck (never dead-letter).
func TestFullOutageNotStuckTransient(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://127.0.0.1:0")
	e.Enqueue("payment", "p1", "create", map[string]any{}, 1)
	id := queueID(t, db, "payment", "p1")
	db.Exec("UPDATE sync_queue SET transient_failures = 50, first_transient_at = '2020-01-01T00:00:00Z' WHERE id = ?", id)
	// Monitor offline = Cloud-wide outage → never dead-letter an outage victim,
	// no matter how many failures.
	e.monitor.mu.Lock()
	e.monitor.status = ConnOffline
	e.monitor.mu.Unlock()
	if e.isStuckTransient(id) {
		t.Fatal("during a full outage (monitor offline) a row must NOT be stuck-transient")
	}
}

// Recover-order: refuses when Cloud still has the order (409), re-creates when gone.
func TestRecoverOrderGuards(t *testing.T) {
	var exists atomic.Bool
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodGet {
			if exists.Load() {
				w.Write([]byte(`{"data":{"id":"o1"}}`))
			} else {
				http.Error(w, `{"message":"gone"}`, http.StatusNotFound)
			}
			return
		}
		w.Write([]byte(`{"data":{"id":"o1","order_code":"ORD-1"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO orders (id, cloud_id, order_type, status) VALUES ('o1','o1','dine_in','open')`)

	// Cloud still has it → refuse.
	exists.Store(true)
	if err := e.RecoverOrderOnCloud(context.Background(), "o1"); err != ErrOrderStillExistsOnCloud {
		t.Fatalf("want ErrOrderStillExistsOnCloud, got %v", err)
	}

	// Cloud lost it → re-create (clears cloud_id, enqueues a fresh create).
	exists.Store(false)
	if err := e.RecoverOrderOnCloud(context.Background(), "o1"); err != nil {
		t.Fatalf("recover: %v", err)
	}
	var cloudID *string
	db.QueryRow("SELECT cloud_id FROM orders WHERE id = 'o1'").Scan(&cloudID)
	if cloudID != nil && *cloudID != "" {
		t.Fatalf("recover should clear the dead cloud_id, got %v", *cloudID)
	}
	var n int
	db.QueryRow("SELECT COUNT(*) FROM sync_queue WHERE entity_type='order' AND operation='create' AND entity_id='o1' AND synced_at IS NULL AND dead_lettered_at IS NULL").Scan(&n)
	if n == 0 {
		t.Fatal("recover should enqueue a fresh order.create")
	}
}

// Soak: a mix of syncable + doomed rows converges — active queue empties, every
// row reaches synced | dead_lettered | resolved.
func TestConvergenceSoak(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// order.create for "good" → 200; everything else (doomed) → 422.
		if r.URL.Path == "/api/v1/workstation/orders" && r.Method == http.MethodPost {
			w.Write([]byte(`{"data":{"id":"good","order_code":"ORD-9"}}`))
			return
		}
		http.Error(w, `{"message":"does not exist"}`, http.StatusUnprocessableEntity)
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO orders (id, order_type, status) VALUES ('good','dine_in','open')`)
	e.Enqueue("order", "good", "create", map[string]any{"order": map[string]any{"client_order_id": "good"}}, 1)
	for _, id := range []string{"d1", "d2", "d3"} {
		db.Exec(`INSERT INTO orders (id, order_type, status) VALUES (?, 'dine_in','open')`, id)
		e.Enqueue("order", id, "create", map[string]any{"order": map[string]any{"client_order_id": id}}, 1)
	}
	setOnline(e)

	// A few drain cycles (cooldown may pause between; clear it to keep the soak moving).
	for range 5 {
		e.cooldownUntil = time.Time{}
		e.processQueue()
	}

	var active int
	db.QueryRow("SELECT COUNT(*) FROM sync_queue WHERE synced_at IS NULL AND dead_lettered_at IS NULL").Scan(&active)
	if active != 0 {
		t.Fatalf("soak: active queue should drain to 0, got %d", active)
	}
}

// Soak (terminal-state invariant): the headline convergence proof. Seeds the
// FULL mix the plan advertises — a syncable row, doomed rows (422 entity-missing),
// a persistent-5xx poison row, and a Cloud that 429-throttles for an opening
// window then recovers — then runs the engine to quiescence and asserts the
// three parts of the invariant the shorter soak only half-checks:
//
//	(1) the active queue (synced_at IS NULL AND dead_lettered_at IS NULL) is empty,
//	(2) EVERY row is positively in a terminal state — synced | dead_lettered |
//	    resolved (no row sits with all three markers NULL), and
//	(3) NO row exceeded a bounded attempt count — nothing spun blindly to
//	    max_attempts; doomed rows leave via classification, not exhaustion.
//
// It also pins the per-row terminal reason so the categories are provably
// distinct (good→synced, doomed→cloud_422_entity_missing, poison→stuck_transient)
// and proves the good row only syncs AFTER the 429 window lifts.
func TestConvergenceSoakTerminalInvariant(t *testing.T) {
	var throttling atomic.Bool
	throttling.Store(true) // opening 429 window
	var served429 atomic.Int32

	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if throttling.Load() {
			served429.Add(1)
			w.Header().Set("Retry-After", "1")
			http.Error(w, `{"message":"Too Many Attempts."}`, http.StatusTooManyRequests)
			return
		}
		body, _ := io.ReadAll(r.Body)
		s := string(body)
		switch {
		case strings.Contains(s, "poison"):
			// Persistent Cloud-wide-looking 5xx on THIS row while other rows
			// succeed → row-specific poison → stuck_transient.
			http.Error(w, `{"message":"boom"}`, http.StatusInternalServerError)
		case strings.Contains(s, "good"):
			w.Write([]byte(`{"data":{"id":"good","order_code":"ORD-9"}}`))
		default:
			// Doomed: references an entity that no longer exists.
			http.Error(w, `{"message":"does not exist"}`, http.StatusUnprocessableEntity)
		}
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	// Syncable row.
	db.Exec(`INSERT INTO orders (id, order_type, status) VALUES ('good','dine_in','open')`)
	e.Enqueue("order", "good", "create", map[string]any{"order": map[string]any{"client_order_id": "good"}}, 1)
	// Doomed rows (422 entity-missing).
	for _, id := range []string{"d1", "d2", "d3"} {
		db.Exec(`INSERT INTO orders (id, order_type, status) VALUES (?, 'dine_in','open')`, id)
		e.Enqueue("order", id, "create", map[string]any{"order": map[string]any{"client_order_id": id}}, 1)
	}
	// Persistent-5xx poison row, one failure short of the stuck-transient
	// threshold so the first push under a proven-up Cloud trips it.
	db.Exec(`INSERT INTO orders (id, order_type, status) VALUES ('poison','dine_in','open')`)
	e.Enqueue("order", "poison", "create", map[string]any{"order": map[string]any{"client_order_id": "poison"}}, 1)
	poisonID := queueID(t, db, "order", "poison")
	db.Exec("UPDATE sync_queue SET transient_failures = ? WHERE id = ?", rlStuckTransientThreshold-1, poisonID)
	setOnline(e)

	// Run to quiescence. Cycle 0 is spent entirely inside the 429 window (the
	// throttle STOPs the cycle after the first row); lift it afterwards so the
	// queue can drain. Clear the cooldown each cycle so the soak keeps moving.
	for i := range 12 {
		e.cooldownUntil = time.Time{}
		e.processQueue()
		if i == 0 {
			if served429.Load() == 0 {
				t.Fatal("opening window should have served at least one 429")
			}
			throttling.Store(false) // Cloud recovers
		}
	}

	// (1) active queue empty.
	var active int
	db.QueryRow("SELECT COUNT(*) FROM sync_queue WHERE synced_at IS NULL AND dead_lettered_at IS NULL").Scan(&active)
	if active != 0 {
		t.Fatalf("active queue should drain to 0, got %d", active)
	}

	// (2) every row positively terminal (synced | dead_lettered | resolved).
	var stateless int
	db.QueryRow("SELECT COUNT(*) FROM sync_queue WHERE synced_at IS NULL AND dead_lettered_at IS NULL AND resolved_at IS NULL").Scan(&stateless)
	if stateless != 0 {
		t.Fatalf("every row must be synced|dead_lettered|resolved, %d row(s) in no terminal state", stateless)
	}

	// (3) no row exceeded a bounded attempt count — nothing spun to max_attempts.
	var maxSeen, attemptCap int
	db.QueryRow("SELECT COALESCE(MAX(attempts),0), COALESCE(MAX(max_attempts),0) FROM sync_queue").Scan(&maxSeen, &attemptCap)
	if maxSeen >= attemptCap {
		t.Fatalf("no row should reach the blind attempt cap (max attempts seen %d, cap %d) — doomed rows must leave via classification", maxSeen, attemptCap)
	}

	// Per-category terminal reasons — the invariant's categories are distinct.
	var goodSynced *string
	db.QueryRow("SELECT synced_at FROM sync_queue WHERE entity_id='good'").Scan(&goodSynced)
	if goodSynced == nil {
		t.Fatal("syncable row must be synced after the 429 window lifts")
	}
	for _, id := range []string{"d1", "d2", "d3"} {
		if reason, _ := deadLetterOf(t, db, queueID(t, db, "order", id)); reason != "cloud_422_entity_missing" {
			t.Fatalf("doomed row %s want cloud_422_entity_missing, got %q", id, reason)
		}
	}
	if reason, _ := deadLetterOf(t, db, poisonID); reason != "stuck_transient" {
		t.Fatalf("persistent-5xx poison row want stuck_transient, got %q", reason)
	}
}

// Critical#1 proof: recover-order RE-ACTIVATES the family's payments (money must
// still reach Cloud), it does NOT resolve them terminally.
func TestRecoverOrderReactivatesPayments(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodGet {
			http.Error(w, `{"message":"gone"}`, http.StatusNotFound)
			return
		}
		w.Write([]byte(`{"data":{"id":"o1","order_code":"ORD-1"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO orders (id, cloud_id, order_type, guest_count, status) VALUES ('o1','o1','dine_in',2,'open')`)
	db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p1','o1','card',500,'pending','p1')`)
	e.Enqueue("order", "o1", "create", map[string]any{"order": map[string]any{"client_order_id": "o1"}}, 1)
	oid := queueID(t, db, "order", "o1")
	e.deadLetter(oid, "cloud_404_order_gone")
	e.Enqueue("payment", "p1", "create", map[string]any{"order_id": "o1"}, 1)
	pid := queueID(t, db, "payment", "p1")
	e.deadLetter(pid, "payment_orphan_order_gone")

	if err := e.RecoverOrderOnCloud(context.Background(), "o1"); err != nil {
		t.Fatalf("recover: %v", err)
	}

	var dead, resolved *string
	db.QueryRow("SELECT dead_lettered_at, resolved_at FROM sync_queue WHERE id = ?", pid).Scan(&dead, &resolved)
	if dead != nil {
		t.Fatal("payment must be re-activated (dead_lettered_at cleared), not left dead")
	}
	if resolved != nil {
		t.Fatal("payment must NOT be resolved-terminal — that would silently lose money")
	}
	// The superseded old create IS resolved.
	if _, res := deadLetterOf(t, db, oid); res != "recovered" {
		t.Fatalf("old order.create should be resolved 'recovered', got %q", res)
	}
}

// Critical#2 proof: a head-of-line order.create that persistently 5xx's while
// the monitor reports online dead-letters as stuck_transient through the real
// processQueue path (not a seeded shortcut).
func TestStuckTransientViaProcessQueue(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, `{"message":"boom"}`, http.StatusInternalServerError)
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO orders (id, order_type, guest_count, status) VALUES ('o1','dine_in',1,'open')`)
	e.Enqueue("order", "o1", "create", map[string]any{"order": map[string]any{"client_order_id": "o1"}}, 1)
	id := queueID(t, db, "order", "o1")
	// One failure away from the threshold; Cloud (monitor) reachable.
	db.Exec("UPDATE sync_queue SET transient_failures = 19 WHERE id = ?", id)
	setOnline(e)

	e.processQueue()

	if reason, _ := deadLetterOf(t, db, id); reason != "stuck_transient" {
		t.Fatalf("persistent-5xx head-of-line create should dead-letter stuck_transient, got %q", reason)
	}
}

// TH.1/TH.2: a dead order.create is never resurrected; a genuinely-lost create
// (no queue row) still self-heals.
func TestReconcilerSkipsDeadFamily(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://127.0.0.1:0")
	db.Exec(`INSERT INTO orders (id, order_type, guest_count, status) VALUES ('dead','dine_in',1,'open')`)
	e.Enqueue("order", "dead", "create", map[string]any{"order": map[string]any{"client_order_id": "dead"}}, 1)
	e.deadLetter(queueID(t, db, "order", "dead"), "cloud_422_entity_missing")

	// Lost create: order row exists, no queue row at all.
	db.Exec(`INSERT INTO orders (id, order_type, guest_count, status) VALUES ('lost','dine_in',1,'open')`)

	e.reconcileUnsyncedOrders()

	var deadN, lostN int
	db.QueryRow("SELECT COUNT(*) FROM sync_queue WHERE entity_type='order' AND operation='create' AND entity_id='dead'").Scan(&deadN)
	db.QueryRow("SELECT COUNT(*) FROM sync_queue WHERE entity_type='order' AND operation='create' AND entity_id='lost'").Scan(&lostN)
	if deadN != 1 {
		t.Fatalf("dead family must NOT be resurrected (want 1 create row), got %d", deadN)
	}
	if lostN != 1 {
		t.Fatalf("lost create must self-heal (want 1 re-enqueued), got %d", lostN)
	}
}

// Phase G: a cooldown (from 429 Retry-After) gates the drain — no Cloud calls.
func TestCooldownGatesDrain(t *testing.T) {
	var calls int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&calls, 1)
		w.Write([]byte(`{"data":{"id":"o1"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO orders (id, order_type, guest_count, status) VALUES ('o1','dine_in',1,'open')`)
	e.Enqueue("order", "o1", "create", map[string]any{"order": map[string]any{"client_order_id": "o1"}}, 1)
	setOnline(e)
	e.noteThrottle("60") // 60s cooldown

	e.processQueue()

	if atomic.LoadInt32(&calls) != 0 {
		t.Fatalf("processQueue must not hit Cloud during cooldown, got %d calls", calls)
	}
}

// TB.4: a dead till_session.open cascades to its close/cash_event children.
func TestTillCascadeDeadLettersChildren(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://127.0.0.1:0")
	e.Enqueue("till_session", "sess1", "open", map[string]any{}, 1)
	e.deadLetter(queueID(t, db, "till_session", "sess1"), "cloud_422_entity_missing")
	e.Enqueue("till_session", "sess1", "close", map[string]any{}, 1)
	closeID := queueID(t, db, "till_session", "sess1") // close is newest for sess1
	e.reconcileDeadLetterCascade()
	if reason, _ := deadLetterOf(t, db, closeID); reason != "parent_session_dead" {
		t.Fatalf("till_session.close should cascade to parent_session_dead, got %q", reason)
	}
}

// A dead till_session.open with an active OTHER session's child → no cascade.
func TestTillCascadeLeavesLiveSession(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://127.0.0.1:0")
	// sess1 open is dead; sess2 open is active with a close.
	e.Enqueue("till_session", "sess1", "open", map[string]any{}, 1)
	e.deadLetter(queueID(t, db, "till_session", "sess1"), "cloud_422_entity_missing")
	e.Enqueue("till_session", "sess2", "close", map[string]any{}, 1)
	liveClose := queueID(t, db, "till_session", "sess2")
	e.reconcileDeadLetterCascade()
	if reason, _ := deadLetterOf(t, db, liveClose); reason != "" {
		t.Fatalf("sess2 close (parent alive) must NOT cascade, got %q", reason)
	}
}

// Generic 422 (no entity-missing signature) burns attempts then the safety-net
// dead-letters it max_attempts_exhausted — nothing dies invisibly.
func TestGeneric422SafetyNetDeadLetters(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, `{"message":"validation failed for some field"}`, http.StatusUnprocessableEntity)
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO orders (id, order_type, guest_count, status) VALUES ('o1','dine_in',1,'open')`)
	e.Enqueue("order", "o1", "create", map[string]any{"order": map[string]any{"client_order_id": "o1"}}, 1)
	id := queueID(t, db, "order", "o1")
	setOnline(e)
	for range 6 { // burn through max_attempts (5)
		e.cooldownUntil = time.Time{}
		e.processQueue()
	}
	if reason, _ := deadLetterOf(t, db, id); reason != "max_attempts_exhausted" {
		t.Fatalf("generic 422 should hit max-attempts safety-net, got %q", reason)
	}
}

// 429 with Retry-After sets a cooldown; the row is NOT dead-lettered.
func TestThrottle429SetsCooldown(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Retry-After", "30")
		http.Error(w, `{"message":"Too Many Attempts."}`, http.StatusTooManyRequests)
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO orders (id, order_type, guest_count, status) VALUES ('o1','dine_in',1,'open')`)
	e.Enqueue("order", "o1", "create", map[string]any{"order": map[string]any{"client_order_id": "o1"}}, 1)
	setOnline(e)
	e.processQueue()

	if reason, _ := deadLetterOf(t, db, queueID(t, db, "order", "o1")); reason != "" {
		t.Fatalf("429 must not dead-letter, got %q", reason)
	}
	throttled, until := e.ThrottleState()
	if !throttled {
		t.Fatal("429 should set throttled")
	}
	if d := time.Until(until); d < 20*time.Second || d > 40*time.Second {
		t.Fatalf("cooldown should be ~30s from Retry-After, got %v", d)
	}
}

// Per-device drain budget: payment sub-budget (25/min) caps the class.
func TestDrainBudgetCapsClass(t *testing.T) {
	e, _ := newSyncTestEngine(t, "http://127.0.0.1:0")
	allowed := 0
	for range rlPaymentPerMin + 10 {
		if e.budgetAllows("payment") {
			allowed++
			e.noteRequest("payment")
		}
	}
	if allowed != rlPaymentPerMin {
		t.Fatalf("payment budget should cap at %d, allowed %d", rlPaymentPerMin, allowed)
	}
	// A non-payment op still counts against the (much larger) general budget.
	if !e.budgetAllows("order") {
		t.Fatal("order should still be within the general budget after payments capped")
	}
}

// PaymentOrphanCount + DeadLetters ordering (payments first).
func TestPaymentOrphanCountAndListOrder(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://127.0.0.1:0")
	e.Enqueue("order", "o1", "create", map[string]any{}, 1)
	e.deadLetter(queueID(t, db, "order", "o1"), "cloud_422_entity_missing")
	e.Enqueue("payment", "p1", "create", map[string]any{}, 1)
	e.deadLetter(queueID(t, db, "payment", "p1"), "payment_orphan_order_gone")

	if n, _ := e.PaymentOrphanCount(); n != 1 {
		t.Fatalf("payment orphan count want 1, got %d", n)
	}
	dls, _ := e.DeadLetters(10)
	if len(dls) != 2 {
		t.Fatalf("want 2 dead-letters, got %d", len(dls))
	}
	if !dls[0].IsPayment {
		t.Fatal("payment orphan should sort FIRST")
	}
}

// TH.2: reconcileUnsyncedItems does not re-enqueue for an order whose create is dead.
func TestReconcileItemsSkipsDeadFamily(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://127.0.0.1:0")
	db.Exec(`INSERT INTO orders (id, cloud_id, order_type, guest_count, status) VALUES ('o1','o1','dine_in',1,'open')`)
	db.Exec(`INSERT INTO order_items (id, customer_order_id, product_sku_id, quantity) VALUES ('i1','o1','sku1',1)`)
	e.Enqueue("order", "o1", "create", map[string]any{}, 1)
	e.deadLetter(queueID(t, db, "order", "o1"), "cloud_404_order_gone")

	e.reconcileUnsyncedItems()

	var n int
	db.QueryRow("SELECT COUNT(*) FROM sync_queue WHERE entity_type='order' AND operation='item_add' AND entity_id='o1'").Scan(&n)
	if n != 0 {
		t.Fatalf("item_add must NOT be enqueued for a dead-family order, got %d", n)
	}
}

// Discard/Re-resolve validation errors.
func TestRecoveryActionValidation(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://127.0.0.1:0")
	// Non-existent id.
	if err := e.Discard(9999); err == nil {
		t.Fatal("discard of non-existent id should error")
	}
	// Active (not dead) row → NOT_DEAD_LETTERED.
	e.Enqueue("order", "o1", "create", map[string]any{}, 1)
	id := queueID(t, db, "order", "o1")
	if err := e.ReResolve(id); err != ErrNotDeadLettered {
		t.Fatalf("re-resolve of an active row should be ErrNotDeadLettered, got %v", err)
	}
}

// Recover: no local order → sql.ErrNoRows; Cloud check unreachable → ErrCloudUnreachable.
func TestRecoverErrors(t *testing.T) {
	// no local order
	e, _ := newSyncTestEngine(t, "http://127.0.0.1:0")
	if err := e.RecoverOrderOnCloud(context.Background(), "nope"); err == nil {
		t.Fatal("recover of unknown local order should error (404)")
	}

	// Cloud existence check returns 500 → unreachable/uncertain → refuse.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, `{"message":"boom"}`, http.StatusInternalServerError)
	}))
	defer cloud.Close()
	e2, db2 := newSyncTestEngine(t, cloud.URL)
	db2.Exec(`INSERT INTO orders (id, cloud_id, order_type, guest_count, status) VALUES ('o1','o1','dine_in',1,'open')`)
	if err := e2.RecoverOrderOnCloud(context.Background(), "o1"); err != ErrCloudUnreachable {
		t.Fatalf("recover with a failing existence check should be ErrCloudUnreachable, got %v", err)
	}
}

// A success clears the retryable streak counters.
func TestSuccessResetsTransientStreak(t *testing.T) {
	var fail atomic.Bool
	fail.Store(true)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if fail.Load() {
			http.Error(w, `{"message":"blip"}`, http.StatusBadGateway)
			return
		}
		w.Write([]byte(`{"data":{"id":"o1","order_code":"ORD-1"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO orders (id, order_type, guest_count, status) VALUES ('o1','dine_in',1,'open')`)
	e.Enqueue("order", "o1", "create", map[string]any{"order": map[string]any{"client_order_id": "o1"}}, 1)
	id := queueID(t, db, "order", "o1")
	setOnline(e)
	e.processQueue() // 502 → transient_failures=1
	var tf int
	db.QueryRow("SELECT transient_failures FROM sync_queue WHERE id=?", id).Scan(&tf)
	if tf != 1 {
		t.Fatalf("want 1 transient failure, got %d", tf)
	}
	fail.Store(false)
	e.cooldownUntil = time.Time{}
	e.processQueue() // 200 → synced, streak reset
	db.QueryRow("SELECT transient_failures FROM sync_queue WHERE id=?", id).Scan(&tf)
	if tf != 0 {
		t.Fatalf("success should reset transient_failures to 0, got %d", tf)
	}
}

// Exp-backoff (429/5xx without Retry-After) grows the cooldown on each throttle.
func TestExpBackoffGrows(t *testing.T) {
	e, _ := newSyncTestEngine(t, "http://127.0.0.1:0")
	e.noteThrottle("") // 1st: ~base
	_, until1 := e.ThrottleState()
	d1 := time.Until(until1)

	e.rlMu.Lock()
	e.cooldownUntil = time.Time{} // clear the window but keep the streak counter
	e.rlMu.Unlock()

	e.noteThrottle("") // 2nd: ~2× base
	_, until2 := e.ThrottleState()
	d2 := time.Until(until2)

	if d2 <= d1 {
		t.Fatalf("exp backoff should grow (1st=%v, 2nd=%v)", d1, d2)
	}
}
