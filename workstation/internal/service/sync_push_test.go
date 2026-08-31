package service

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"sync/atomic"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func newSyncTestEngine(t *testing.T, cloudURL string) (*SyncEngine, *store.DB) {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	e := NewSyncEngine(db, cloudURL, nil)
	return e, db
}

// TestPaymentCreateSyncForwardsToCloud asserts that pushToCloud calls the
// correct Cloud endpoint with the Bearer token + Idempotency-Key from the
// queue payload, and that the returned cloud_id is persisted to payments.
func TestPaymentCreateSyncForwardsToCloud(t *testing.T) {
	var (
		seenAuth  string
		seenIdem  string
		seenBody  map[string]any
		callCount int32
	)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&callCount, 1)
		if r.URL.Path != "/api/v1/kiosk/payments" || r.Method != "POST" {
			t.Errorf("unexpected request: %s %s", r.Method, r.URL.Path)
		}
		seenAuth = r.Header.Get("Authorization")
		seenIdem = r.Header.Get("Idempotency-Key")
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"cloud-pay-1","status":"pending"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	// Seed the local payment + queue entry
	_, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key)
		VALUES ('local-pay-1', 'order-1', 'card', 1500, 'pending', 'idem-1')`)
	if err != nil {
		t.Fatalf("seed payment: %v", err)
	}
	payload := map[string]any{
		"bearer_token":    "kiosk-token-xyz",
		"idempotency_key": "idem-1",
		"order_id":        "order-1",
		"payment_method":  "card",
		"amount":          1500,
	}
	if err := e.Enqueue("payment", "local-pay-1", "create", payload, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	// Force online so processQueue runs
	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()

	e.processQueue()

	if atomic.LoadInt32(&callCount) != 1 {
		t.Errorf("expected 1 Cloud call, got %d", callCount)
	}
	if seenAuth != "Bearer kiosk-token-xyz" {
		t.Errorf("Authorization header wrong: %q", seenAuth)
	}
	if seenIdem != "idem-1" {
		t.Errorf("Idempotency-Key header wrong: %q", seenIdem)
	}
	if seenBody["order_id"] != "order-1" || seenBody["payment_method"] != "card" {
		t.Errorf("request body wrong: %+v", seenBody)
	}

	// cloud_id should be persisted to payments
	var cloudID string
	db.QueryRow(`SELECT COALESCE(cloud_id, '') FROM payments WHERE id = 'local-pay-1'`).Scan(&cloudID)
	if cloudID != "cloud-pay-1" {
		t.Errorf("expected cloud_id=cloud-pay-1, got %q", cloudID)
	}

	// sync_queue entry should be marked synced
	var syncedAt string
	db.QueryRow(`SELECT COALESCE(synced_at, '') FROM sync_queue WHERE entity_type='payment' AND entity_id='local-pay-1'`).Scan(&syncedAt)
	if syncedAt == "" {
		t.Error("expected sync_queue entry to be marked synced")
	}
}

func TestPaymentCreateSyncForwardsPolicyIdentity(t *testing.T) {
	var seenBody map[string]any
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/payments" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Write([]byte(`{"data":{"payment_id":"cloud-pay-policy","status":"pending"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	_, err := db.Exec(`INSERT INTO payments (
		id, order_id, payment_method, amount, status, idempotency_key,
		payment_option_id, policy_revision, connection_id, connection_option_id, attempt_idempotency_key
	) VALUES ('pay-policy', 'order-1', 'cash', 500, 'pending', 'idem-policy',
		'opt-1', 7, 'conn-1', 'conn-opt-1', 'idem-policy')`)
	if err != nil {
		t.Fatalf("seed payment: %v", err)
	}

	payload := map[string]any{
		"bearer_token":            "ws-token",
		"target":                  "workstation",
		"idempotency_key":         "idem-policy",
		"order_id":                "order-1",
		"payment_method":          "cash",
		"amount":                  500,
		"payment_option_id":       "opt-1",
		"policy_revision":         7,
		"connection_id":           "conn-1",
		"connection_option_id":    "conn-opt-1",
		"attempt_idempotency_key": "idem-policy",
	}
	if err := e.Enqueue("payment", "pay-policy", "create", payload, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if seenBody["payment_option_id"] != "opt-1" {
		t.Errorf("payment_option_id = %v", seenBody["payment_option_id"])
	}
	if seenBody["policy_revision"].(float64) != 7 {
		t.Errorf("policy_revision = %v", seenBody["policy_revision"])
	}
	if seenBody["connection_id"] != "conn-1" {
		t.Errorf("connection_id = %v", seenBody["connection_id"])
	}
	if seenBody["connection_option_id"] != "conn-opt-1" {
		t.Errorf("connection_option_id = %v", seenBody["connection_option_id"])
	}
	if seenBody["attempt_idempotency_key"] != "idem-policy" {
		t.Errorf("attempt_idempotency_key = %v", seenBody["attempt_idempotency_key"])
	}
}

// TestPaymentCreateForwardsMetadataAsObject asserts that the locally-stored
// metadata JSON string is decoded into an object in the Cloud POST body, so
// backend's `metadata` array validation (split_count / amount_per_person)
// accepts it and /split-status works end-to-end.
func TestPaymentCreateForwardsMetadataAsObject(t *testing.T) {
	var seenBody map[string]any
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Write([]byte(`{"data":{"id":"cloud-pay-1","status":"pending"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key)
		VALUES ('lp-1', 'o-1', 'qr', 1000, 'pending', 'idem-md')`)
	e.Enqueue("payment", "lp-1", "create", map[string]any{
		"bearer_token":    "tok",
		"idempotency_key": "idem-md",
		"order_id":        "o-1",
		"payment_method":  "qr",
		"amount":          1000,
		"metadata":        `{"split_count":4,"amount_per_person":1000}`,
	}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	md, ok := seenBody["metadata"].(map[string]any)
	if !ok {
		t.Fatalf("metadata not forwarded as object: %+v", seenBody["metadata"])
	}
	if md["split_count"].(float64) != 4 {
		t.Errorf("split_count = %v, want 4", md["split_count"])
	}
}

func TestPaymentConfirmRequiresCloudID(t *testing.T) {
	// Cloud isn't even hit because cloud_id is empty
	hit := int32(0)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&hit, 1)
		w.Write([]byte(`{"data":{}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	// Payment has NO cloud_id yet (create hasn't synced)
	db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p-1','o-1','card',1000,'pending','idem-x')`)
	e.Enqueue("payment", "p-1", "confirm", map[string]any{"bearer_token": "tok"}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if atomic.LoadInt32(&hit) != 0 {
		t.Errorf("expected NO Cloud call when cloud_id missing, got %d", hit)
	}

	// Queue entry should NOT be marked synced
	var synced string
	db.QueryRow(`SELECT COALESCE(synced_at, '') FROM sync_queue WHERE operation='confirm'`).Scan(&synced)
	if synced != "" {
		t.Error("queue entry shouldn't be synced when push failed")
	}

	// Attempts must NOT be incremented: "no cloud_id yet" is a transient
	// dependency-wait (the payment.create ahead in the queue will set it), not a
	// bad row. Burning attempts here would drive the confirm to max_attempts and
	// permanently drop a payment whose create simply hadn't synced yet.
	var attempts int
	db.QueryRow(`SELECT attempts FROM sync_queue WHERE operation='confirm'`).Scan(&attempts)
	if attempts != 0 {
		t.Errorf("expected attempts=0 (transient dependency-wait, not burned), got %d", attempts)
	}

	// last_error should still be recorded for diagnostics.
	var lastErr string
	db.QueryRow(`SELECT COALESCE(last_error,'') FROM sync_queue WHERE operation='confirm'`).Scan(&lastErr)
	if lastErr == "" {
		t.Error("expected last_error recorded even though attempts not burned")
	}
}

// TestCloudAuthErrorIsRetryableAndDoesNotBurnAttempts asserts that a 403 from
// Cloud (e.g. "Device type not allowed for this endpoint" during a paired-device
// misconfig window) is treated as transient: the payment.create is NOT marked
// synced, NOT burned to max_attempts, and stays in the active pool so it heals
// automatically once auth is fixed. Regression guard for the 403-storm that
// permanently failed good payments.
func TestCloudAuthErrorIsRetryableAndDoesNotBurnAttempts(t *testing.T) {
	for _, status := range []int{http.StatusForbidden, http.StatusUnauthorized} {
		cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			http.Error(w, `{"message":"Device type not allowed for this endpoint."}`, status)
		}))

		e, db := newSyncTestEngine(t, cloud.URL)
		db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p-1','o-1','card',1000,'pending','idem-1')`)
		e.Enqueue("payment", "p-1", "create", map[string]any{
			"bearer_token":    "tok",
			"idempotency_key": "idem-1",
			"order_id":        "o-1",
			"payment_method":  "card",
			"amount":          1000,
		}, 1)

		e.monitor.mu.Lock()
		e.monitor.status = ConnOnline
		e.monitor.mu.Unlock()
		e.processQueue()

		var attempts int
		var synced, lastErr string
		db.QueryRow(`SELECT attempts, COALESCE(synced_at,''), COALESCE(last_error,'') FROM sync_queue WHERE operation='create'`).
			Scan(&attempts, &synced, &lastErr)
		if attempts != 0 {
			t.Errorf("status %d: attempts must stay 0 (auth fault is transient), got %d", status, attempts)
		}
		if synced != "" {
			t.Errorf("status %d: row must not be marked synced on auth failure", status)
		}
		if lastErr == "" {
			t.Errorf("status %d: last_error should be recorded", status)
		}
		cloud.Close()
	}
}

func TestPaymentConfirmUsesCloudID(t *testing.T) {
	var seenPath string
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seenPath = r.URL.Path
		w.Write([]byte(`{"data":{"status":"confirmed"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	// Payment already has cloud_id from a prior create sync
	db.Exec(`INSERT INTO payments (id, cloud_id, order_id, payment_method, amount, status, idempotency_key)
		VALUES ('p-1','cloud-99','o-1','card',1000,'confirmed','idem-x')`)
	e.Enqueue("payment", "p-1", "confirm", map[string]any{"bearer_token": "tok"}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if seenPath != "/api/v1/kiosk/payments/cloud-99/confirm" {
		t.Errorf("expected URL using cloud_id, got %q", seenPath)
	}
}

func TestCloudPostNonRetryable4xxStillIncrementsAttempts(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, `{"error":"invalid"}`, http.StatusUnprocessableEntity)
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p-1','o-1','card',1000,'pending','idem-1')`)
	e.Enqueue("payment", "p-1", "create", map[string]any{
		"bearer_token":    "tok",
		"idempotency_key": "idem-1",
		"order_id":        "o-1",
		"payment_method":  "card",
		"amount":          1000,
	}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	var attempts int
	var lastErr string
	db.QueryRow(`SELECT attempts, COALESCE(last_error, '') FROM sync_queue WHERE operation='create'`).Scan(&attempts, &lastErr)
	if attempts != 1 {
		t.Errorf("expected attempts=1, got %d", attempts)
	}
	if lastErr == "" {
		t.Error("expected last_error to be recorded")
	}
}

// TestDependencyWaitDoesNotBlockIndependentItems is the head-of-line regression
// guard: a confirm whose payment has no cloud_id (its create hasn't synced) must
// NOT stall an independent payment.create queued behind it. Before the fix the
// dependency-wait returned and froze the whole cycle, so a single orphaned
// confirm kept the entire backlog from draining.
func TestDependencyWaitDoesNotBlockIndependentItems(t *testing.T) {
	var createHit int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&createHit, 1)
		w.Write([]byte(`{"data":{"id":"cloud-pay-OK","status":"pending"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	// Stuck confirm: payment has NO cloud_id → dependency-wait, enqueued FIRST
	// so it sits at the head of the queue.
	db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('stuck','o-1','card',1000,'pending','idem-stuck')`)
	e.Enqueue("payment", "stuck", "confirm", map[string]any{"bearer_token": "tok"}, 1)

	// Independent create behind it — must still reach Cloud.
	db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('indep','o-2','card',500,'pending','idem-indep')`)
	e.Enqueue("payment", "indep", "create", map[string]any{
		"bearer_token":    "tok",
		"idempotency_key": "idem-indep",
		"order_id":        "o-2",
		"payment_method":  "card",
		"amount":          500,
	}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	// The independent create must have been pushed despite the stuck confirm ahead.
	if atomic.LoadInt32(&createHit) != 1 {
		t.Fatalf("independent create blocked by dependency-wait head-of-line; cloud hits=%d", createHit)
	}
	var indepSynced string
	db.QueryRow(`SELECT COALESCE(synced_at,'') FROM sync_queue WHERE entity_id='indep'`).Scan(&indepSynced)
	if indepSynced == "" {
		t.Error("independent create should be marked synced")
	}

	// The stuck confirm: not synced, attempts NOT burned (transient dependency-wait).
	var stuckSynced string
	var stuckAttempts int
	db.QueryRow(`SELECT COALESCE(synced_at,''), attempts FROM sync_queue WHERE entity_id='stuck'`).Scan(&stuckSynced, &stuckAttempts)
	if stuckSynced != "" {
		t.Error("stuck confirm should not be synced")
	}
	if stuckAttempts != 0 {
		t.Errorf("dependency-wait must not burn attempts, got %d", stuckAttempts)
	}
}

// TestAuthRejectedRowDoesNotBlockIndependentItems is the head-of-line regression
// guard for row-specific 401/403: a poisoned row (e.g. a till_session carrying a
// stale cashier token) that Cloud rejects with 401 must NOT stall the
// independent rows queued behind it. Before the fix, 401 was a plain retryable
// error so processQueue `return`ed out of the whole cycle — one poisoned row at
// the head froze the entire backlog forever, so order.create never synced and
// every dependent item bump looped on "cloud_id empty".
func TestAuthRejectedRowDoesNotBlockIndependentItems(t *testing.T) {
	var indepHit int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		var body map[string]any
		_ = json.Unmarshal(raw, &body)
		// The poisoned row carries amount=1000 → reject 401. The independent row
		// (amount=500) succeeds.
		if amt, _ := body["amount"].(float64); amt == 1000 {
			http.Error(w, `{"message":"Invalid device token."}`, http.StatusUnauthorized)
			return
		}
		atomic.AddInt32(&indepHit, 1)
		w.Write([]byte(`{"data":{"id":"cloud-pay-OK","status":"pending"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	// Poisoned 401 row enqueued FIRST → sits at the head of the queue.
	db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('poison','o-1','card',1000,'pending','idem-poison')`)
	e.Enqueue("payment", "poison", "create", map[string]any{
		"bearer_token":    "stale-tok",
		"idempotency_key": "idem-poison",
		"order_id":        "o-1",
		"payment_method":  "card",
		"amount":          1000,
	}, 1)

	// Independent create behind it — must still reach Cloud.
	db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('indep','o-2','card',500,'pending','idem-indep')`)
	e.Enqueue("payment", "indep", "create", map[string]any{
		"bearer_token":    "tok",
		"idempotency_key": "idem-indep",
		"order_id":        "o-2",
		"payment_method":  "card",
		"amount":          500,
	}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	// The independent create must have been pushed despite the 401 row ahead.
	if atomic.LoadInt32(&indepHit) != 1 {
		t.Fatalf("independent create blocked by auth-rejected head-of-line; indep cloud hits=%d", indepHit)
	}
	var indepSynced string
	db.QueryRow(`SELECT COALESCE(synced_at,'') FROM sync_queue WHERE entity_id='indep'`).Scan(&indepSynced)
	if indepSynced == "" {
		t.Error("independent create should be marked synced despite poisoned 401 row ahead")
	}

	// The poisoned row: not synced, attempts NOT burned (heals once auth restored).
	var poisonSynced string
	var poisonAttempts int
	db.QueryRow(`SELECT COALESCE(synced_at,''), attempts FROM sync_queue WHERE entity_id='poison'`).Scan(&poisonSynced, &poisonAttempts)
	if poisonSynced != "" {
		t.Error("poisoned 401 row should not be synced")
	}
	if poisonAttempts != 0 {
		t.Errorf("auth-rejected must not burn attempts, got %d", poisonAttempts)
	}
}

// TestFreshOrderCreateNotStarvedByStuckBacklog is the starvation regression
// guard: a just-created order.create must sync even when 50+ older rows are
// stuck in a skip loop (401/dependency-wait that `continue` without draining).
// Before the fix the batch was `ORDER BY created_at LIMIT 50`, so a wall of
// exactly ~50 stuck rows filled the window and the newest order.create (sorted
// last) was never selected — it never synced and its WS-#### code never swapped.
func TestFreshOrderCreateNotStarvedByStuckBacklog(t *testing.T) {
	var orderSynced int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/workstation/orders" {
			atomic.AddInt32(&orderSynced, 1)
			w.Write([]byte(`{"data":{"id":"cloud-fresh","order_code":"ORD-2026-9","status":"pending"}}`))
			return
		}
		// The backlog wall: every kiosk payment 401s (errAuthRejected → skipped,
		// never drains, holds its slot).
		http.Error(w, `{"message":"Invalid device token."}`, http.StatusUnauthorized)
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	// 55 stuck payment.create rows (enqueued FIRST → oldest created_at).
	for i := range 55 {
		pid := fmt.Sprintf("stuck-%d", i)
		db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES (?, 'o', 'card', 100, 'pending', ?)`, pid, pid)
		e.Enqueue("payment", pid, "create", map[string]any{
			"bearer_token": "tok", "idempotency_key": pid,
			"order_id": "o", "payment_method": "card", "amount": 100,
		}, 1)
	}

	// The fresh order.create — enqueued LAST (newest created_at).
	db.Exec(`INSERT INTO orders (id, order_type, status) VALUES ('fresh','dine_in','open')`)
	e.Enqueue("order", "fresh", "create", map[string]any{
		"bearer_token": "tok",
		"order":        map[string]any{"client_order_id": "fresh", "order_type": "dine_in"},
	}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if atomic.LoadInt32(&orderSynced) != 1 {
		t.Fatalf("fresh order.create starved behind the stuck backlog — cloud hits=%d", orderSynced)
	}
	var cloudID string
	db.QueryRow(`SELECT COALESCE(cloud_id,'') FROM orders WHERE id='fresh'`).Scan(&cloudID)
	if cloudID != "cloud-fresh" {
		t.Errorf("fresh order should be synced (cloud_id written), got %q", cloudID)
	}
}

// TestWorkstationPushUsesCurrentDeviceTokenNotStalePayloadBearer is the re-pair
// regression guard: a /workstation/* push must authenticate with the CURRENT
// device token (settings.device_token), NOT the bearer baked into the queue row
// at enqueue time. A device re-pair rotates the token; before the fix every
// already-queued push 401'd ("Invalid device token") while pulls kept working,
// freezing order.create so the WS-#### code never swapped to ORD-####.
func TestWorkstationPushUsesCurrentDeviceTokenNotStalePayloadBearer(t *testing.T) {
	var seenAuth string
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seenAuth = r.Header.Get("Authorization")
		w.Write([]byte(`{"data":{"id":"cloud-1","order_code":"ORD-2026-1","status":"pending"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	// The current (post-re-pair) device token — the same one the puller uses.
	db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token','CURRENT-DEVICE-TOKEN')`)
	db.Exec(`INSERT INTO orders (id, order_type, status) VALUES ('ord-1','dine_in','open')`)
	// Enqueue an order.create carrying a STALE bearer (paired before rotation).
	e.Enqueue("order", "ord-1", "create", map[string]any{
		"bearer_token": "STALE-OLD-TOKEN",
		"order":        map[string]any{"client_order_id": "ord-1", "order_type": "dine_in"},
	}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if seenAuth != "Bearer CURRENT-DEVICE-TOKEN" {
		t.Errorf("workstation push must use the current device token, not the stale payload bearer; Authorization got %q", seenAuth)
	}
	// cloud_id + ORD code written back → the order can finally swap its code.
	var cloudID, code string
	db.QueryRow(`SELECT COALESCE(cloud_id,''), order_code FROM orders WHERE id='ord-1'`).Scan(&cloudID, &code)
	if cloudID != "cloud-1" {
		t.Errorf("cloud_id should be written back after successful push, got %q", cloudID)
	}
	if code != "ORD-2026-1" {
		t.Errorf("order_code should swap to the Cloud ORD-#### value, got %q", code)
	}
}

// TestKioskPushKeepsPayloadBearer guards the other side: a non-/workstation
// push (/kiosk, /pos) must keep the originating terminal's own bearer — the
// device-token override applies ONLY to device.auth /workstation/* routes.
func TestKioskPushKeepsPayloadBearer(t *testing.T) {
	var seenAuth, seenPath string
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seenAuth = r.Header.Get("Authorization")
		seenPath = r.URL.Path
		w.Write([]byte(`{"data":{"payment_id":"pay-1","status":"pending"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token','CURRENT-DEVICE-TOKEN')`)
	db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p-1','o-1','card',1000,'pending','idem-1')`)
	// No target → /api/v1/kiosk/payments; must keep the terminal's own bearer.
	e.Enqueue("payment", "p-1", "create", map[string]any{
		"bearer_token":    "KIOSK-TERMINAL-TOKEN",
		"idempotency_key": "idem-1",
		"order_id":        "o-1",
		"payment_method":  "card",
		"amount":          1000,
	}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if seenPath != "/api/v1/kiosk/payments" {
		t.Fatalf("expected kiosk payment path, got %q", seenPath)
	}
	if seenAuth != "Bearer KIOSK-TERMINAL-TOKEN" {
		t.Errorf("kiosk push must keep the payload bearer, not the device token; Authorization got %q", seenAuth)
	}
}

// TestCustomerCreatePushesViaWorkstationRoute: customer.create posts to the
// DEVICE-AUTHED /api/v1/workstation/customers/find-or-create. The old
// /pos/customers/find-or-create route rejects a workstation device token (403
// DEVICE_TYPE_NOT_ALLOWED) and the baked SSO/terminal token goes stale (401), so
// a device-authed workstation can only sync customers via the workstation route.
// cloudPost re-stamps the CURRENT device token for /api/v1/workstation/*, so the
// stale baked bearer is overridden.
func TestCustomerCreatePushesViaWorkstationRoute(t *testing.T) {
	var seenAuth, seenPath string
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seenAuth = r.Header.Get("Authorization")
		seenPath = r.URL.Path
		w.Write([]byte(`{"data":{"id":"cloud-cust-1"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token','CURRENT-DEVICE-TOKEN')`)
	db.Exec(`INSERT INTO customers (id, first_name, full_name, phone, local_pending_sync)
		VALUES ('cust-1','Customer 4312','Customer 4312','0987654312',1)`)
	e.Enqueue("customer", "cust-1", "create", map[string]any{
		"bearer_token":    "STALE-KIOSK-TOKEN",
		"idempotency_key": "idem-cust-1",
		"phone":           "0987654312",
		"first_name":      "Customer 4312",
	}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if seenPath != "/api/v1/workstation/customers/find-or-create" {
		t.Fatalf("expected device-authed workstation customer route, got %q", seenPath)
	}
	if seenAuth != "Bearer CURRENT-DEVICE-TOKEN" {
		t.Errorf("customer.create must use the current device token, not the stale payload bearer; got %q", seenAuth)
	}
	// local_pending_sync cleared on success.
	var pending int
	db.QueryRow(`SELECT local_pending_sync FROM customers WHERE id='cust-1'`).Scan(&pending)
	if pending != 0 {
		t.Errorf("local_pending_sync should be cleared after successful push, got %d", pending)
	}
}

// TestReconcileUnsyncedOrdersReEnqueuesLostCreate is the self-heal guard: an
// order with no cloud_id and no pending order.create (its create row was lost)
// must get a fresh order.create backfilled so its provisional WS-#### code can
// finally swap to the Cloud ORD-####. Orders that are already synced, already
// have a pending create, or are voided must be left alone — and re-running the
// reconcile must never duplicate a create (client_order_id keeps it idempotent).
func TestReconcileUnsyncedOrdersReEnqueuesLostCreate(t *testing.T) {
	e, db := newSyncTestEngine(t, "")

	// (1) Orphan: no cloud_id, no pending create → must be backfilled.
	db.Exec(`INSERT INTO orders (id, order_type, status, guest_count, note) VALUES ('ord-orphan','dine_in','open',4,'extra spicy')`)
	// (2) Already synced (has cloud_id) → skip.
	db.Exec(`INSERT INTO orders (id, cloud_id, order_type, status) VALUES ('ord-synced','cloud-1','takeaway','open')`)
	// (3) Voided → skip.
	db.Exec(`INSERT INTO orders (id, order_type, status, voided_at) VALUES ('ord-void','dine_in','voided','2026-07-01T10:00:00Z')`)
	// (4) Create already pending in queue → must NOT duplicate.
	db.Exec(`INSERT INTO orders (id, order_type, status) VALUES ('ord-inflight','spot','open')`)
	e.Enqueue("order", "ord-inflight", "create",
		map[string]any{"order": map[string]any{"client_order_id": "ord-inflight"}}, 1)

	e.reconcileUnsyncedOrders()

	// Orphan got exactly one create backfilled.
	var orphanCreates int
	db.QueryRow(`SELECT COUNT(*) FROM sync_queue WHERE entity_type='order' AND operation='create' AND entity_id='ord-orphan'`).Scan(&orphanCreates)
	if orphanCreates != 1 {
		t.Fatalf("orphan order should have 1 create backfilled, got %d", orphanCreates)
	}

	// Payload carries the durable client_order_id + the order fields.
	var payload string
	db.QueryRow(`SELECT payload FROM sync_queue WHERE entity_type='order' AND operation='create' AND entity_id='ord-orphan'`).Scan(&payload)
	var parsed map[string]any
	_ = json.Unmarshal([]byte(payload), &parsed)
	order, _ := parsed["order"].(map[string]any)
	if order["client_order_id"] != "ord-orphan" {
		t.Errorf("client_order_id: want ord-orphan, got %v", order["client_order_id"])
	}
	if order["order_type"] != "dine_in" {
		t.Errorf("order_type: want dine_in, got %v", order["order_type"])
	}
	if order["note"] != "extra spicy" {
		t.Errorf("note: want 'extra spicy', got %v", order["note"])
	}

	// Synced + voided orders got no create.
	for _, id := range []string{"ord-synced", "ord-void"} {
		var n int
		db.QueryRow(`SELECT COUNT(*) FROM sync_queue WHERE entity_type='order' AND operation='create' AND entity_id=?`, id).Scan(&n)
		if n != 0 {
			t.Errorf("%s should have NO create enqueued, got %d", id, n)
		}
	}

	// In-flight order still has exactly ONE create (guard prevented a duplicate).
	var inflightCreates int
	db.QueryRow(`SELECT COUNT(*) FROM sync_queue WHERE entity_type='order' AND operation='create' AND entity_id='ord-inflight'`).Scan(&inflightCreates)
	if inflightCreates != 1 {
		t.Errorf("in-flight order create must not be duplicated, got %d", inflightCreates)
	}

	// Re-running the reconcile must not duplicate the orphan's create either.
	e.reconcileUnsyncedOrders()
	db.QueryRow(`SELECT COUNT(*) FROM sync_queue WHERE entity_type='order' AND operation='create' AND entity_id='ord-orphan'`).Scan(&orphanCreates)
	if orphanCreates != 1 {
		t.Errorf("second reconcile must not duplicate orphan create, got %d", orphanCreates)
	}
}

// TestPaymentCreatePersistsCloudIDFromPaymentIDKey is the root-cause regression
// guard: Cloud's payment-create response keys the id as `payment_id` (not `id`).
// Reading the wrong key left every payment with an empty cloud_id, so all
// dependent confirm/fail ops stalled forever on "create not synced yet".
func TestPaymentCreatePersistsCloudIDFromPaymentIDKey(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{"order_id":"o-cloud","payment_id":"pay-cloud-77","status":"paid","paid_at":"2026-06-25T10:00:00Z"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('lp','o','card',1000,'pending','idem-pid')`)
	e.Enqueue("payment", "lp", "create", map[string]any{
		"bearer_token": "tok", "idempotency_key": "idem-pid", "order_id": "o",
		"payment_method": "card", "amount": 1000,
	}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	var cloudID string
	db.QueryRow(`SELECT COALESCE(cloud_id,'') FROM payments WHERE id='lp'`).Scan(&cloudID)
	if cloudID != "pay-cloud-77" {
		t.Errorf("expected cloud_id from data.payment_id = pay-cloud-77, got %q", cloudID)
	}
}

// TestPaymentCreate409DuplicateBackfillsCloudID asserts that a 409 "duplicate
// idempotency key" (Cloud returns the original response) is treated as success:
// the row is marked synced and cloud_id is captured from the body. This lets a
// re-push backfill cloud_id for payments created on Cloud but never recorded
// locally, and makes idempotent retries self-heal.
func TestPaymentCreate409DuplicateBackfillsCloudID(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusConflict)
		w.Write([]byte(`{"data":{"order_id":"o-cloud","payment_id":"pay-dup-99","status":"paid","paid_at":"2026-06-25T10:00:00Z"}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('lp','o','card',1000,'pending','idem-dup')`)
	e.Enqueue("payment", "lp", "create", map[string]any{
		"bearer_token": "tok", "idempotency_key": "idem-dup", "order_id": "o",
		"payment_method": "card", "amount": 1000,
	}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	var cloudID string
	db.QueryRow(`SELECT COALESCE(cloud_id,'') FROM payments WHERE id='lp'`).Scan(&cloudID)
	if cloudID != "pay-dup-99" {
		t.Errorf("expected cloud_id backfilled from 409 body = pay-dup-99, got %q", cloudID)
	}
	var synced string
	db.QueryRow(`SELECT COALESCE(synced_at,'') FROM sync_queue WHERE entity_id='lp' AND operation='create'`).Scan(&synced)
	if synced == "" {
		t.Error("expected create row marked synced on 409 duplicate (idempotent success)")
	}
}

func TestCloudURLResolverOverridesStatic(t *testing.T) {
	var hits int32
	cloudA := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&hits, 1)
		w.Write([]byte(`{"data":{"id":"a"}}`))
	}))
	defer cloudA.Close()

	e, db := newSyncTestEngine(t, "http://placeholder-static")
	// Override at runtime — like main.go does after pairing
	e.SetCloudURLResolver(func() string { return cloudA.URL })

	db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key) VALUES ('p-1','o-1','cash',100,'pending','idem-1')`)
	e.Enqueue("payment", "p-1", "create", map[string]any{
		"bearer_token":    "tok",
		"idempotency_key": "idem-1",
		"order_id":        "o-1",
		"payment_method":  "cash",
		"amount":          100,
	}, 1)

	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()
	e.processQueue()

	if atomic.LoadInt32(&hits) != 1 {
		t.Errorf("expected dynamic URL to receive 1 hit, got %d", hits)
	}
}

// Wait helper used by tests where we need to give the worker time to drain.
// Not strictly needed here since we call processQueue() synchronously, but
// kept for future async-worker tests.
var _ = time.Second
