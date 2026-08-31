package service

import (
	"encoding/json"
	"fmt"
	"testing"
)

// seedKioskCreateRow mimics local_kiosk's payment.create enqueue: no `target`
// (defaults to the /kiosk route) and a kiosk bearer token baked in the payload.
func seedKioskCreateRow(t *testing.T, e *SyncEngine, paymentID, orderID string) int {
	t.Helper()
	payload := fmt.Sprintf(`{"bearer_token":"kiosk-tok","payment_id":%q,"order_id":%q,"payment_method":"cash","amount":5000,"idempotency_key":"idem-%s"}`,
		paymentID, orderID, paymentID)
	res, err := e.db.Exec(
		`INSERT INTO sync_queue (entity_type, entity_id, operation, payload, priority)
		 VALUES ('payment', ?, 'create', ?, 1)`, paymentID, payload)
	if err != nil {
		t.Fatalf("seed kiosk create row: %v", err)
	}
	id, _ := res.LastInsertId()
	return int(id)
}

func auditRehomeCount(t *testing.T, e *SyncEngine, paymentID string) int {
	t.Helper()
	var n int
	e.db.QueryRow(`SELECT COUNT(*) FROM audit_log
		WHERE action='payment.rehomed_to_workstation' AND entity_id=?`, paymentID).Scan(&n)
	return n
}

func seedReconcileOrder(t *testing.T, e *SyncEngine, id, cloudID string) {
	t.Helper()
	var cid any
	if cloudID != "" {
		cid = cloudID
	}
	_, err := e.db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    total_amount, branch_id, brand_id, organization_id, cloud_id)
		VALUES (?, ?, 'dine_in', 'open', datetime('now'), 1000, 'br-1', 'bd-1', 'or-1', ?)`,
		id, "C-"+id, cid)
	if err != nil {
		t.Fatalf("seed order %s: %v", id, err)
	}
}

func seedReconcilePayment(t *testing.T, e *SyncEngine, id, orderID, syncTarget string) {
	t.Helper()
	var tgt any
	if syncTarget != "" {
		tgt = syncTarget
	}
	_, err := e.db.Exec(`
		INSERT INTO payments (id, order_id, payment_method, amount, status,
		    sync_target, idempotency_key, created_at, updated_at)
		VALUES (?, ?, 'cash', 1000, 'confirmed', ?, ?, datetime('now'), datetime('now'))`,
		id, orderID, tgt, "idem-"+id)
	if err != nil {
		t.Fatalf("seed payment %s: %v", id, err)
	}
}

func paymentCreateQueuePayload(t *testing.T, e *SyncEngine, paymentID string) (map[string]any, bool) {
	t.Helper()
	var raw string
	err := e.db.QueryRow(`SELECT payload FROM sync_queue
		WHERE entity_type='payment' AND operation='create' AND entity_id=? AND synced_at IS NULL
		ORDER BY id DESC LIMIT 1`, paymentID).Scan(&raw)
	if err != nil {
		return nil, false
	}
	var p map[string]any
	if err := json.Unmarshal([]byte(raw), &p); err != nil {
		t.Fatalf("payload unmarshal: %v", err)
	}
	return p, true
}

// plan-818 P4 / pre-existing orphan gap: a workstation payment Cloud never saw
// (cloud_id empty) with no live queue row must be re-enqueued with a fresh
// payment.create whose payload reuses the payment's own idempotency_key.
func TestReconcileUnsyncedPayments_EnqueuesWorkstationPayment(t *testing.T) {
	e, _ := newSyncTestEngine(t, "http://unused")
	seedReconcileOrder(t, e, "o-1", "cloud-o-1") // order already on Cloud
	seedReconcilePayment(t, e, "p-1", "o-1", "workstation")

	e.reconcileUnsyncedPayments()

	payload, ok := paymentCreateQueuePayload(t, e, "p-1")
	if !ok {
		t.Fatal("expected a payment.create queue row for p-1")
	}
	if payload["target"] != "workstation" {
		t.Errorf("target want workstation, got %v", payload["target"])
	}
	if payload["idempotency_key"] != "idem-p-1" {
		t.Errorf("must reuse the payment's idempotency_key, got %v", payload["idempotency_key"])
	}
	if payload["order_id"] != "o-1" {
		t.Errorf("order_id want o-1, got %v", payload["order_id"])
	}
}

func kioskRehomed(t *testing.T, e *SyncEngine, id string) bool {
	t.Helper()
	var n int
	e.db.QueryRow(`SELECT COUNT(*) FROM payments WHERE id = ? AND rehomed_at IS NOT NULL`, id).Scan(&n)
	return n > 0
}

// plan-818 K2: a kiosk-origin payment Cloud never saw is RE-HOMED to the
// workstation route/identity (its baked kiosk token can't be re-stamped). The
// kiosk order lives on Cloud directly, so there's NO local orders row — order_id
// is already a Cloud id. rehomed_at is stamped to cap future re-homes.
func TestReconcileUnsyncedPayments_RehomesKioskPayment(t *testing.T) {
	e, _ := newSyncTestEngine(t, "http://unused")
	seedReconcilePayment(t, e, "p-k", "cloud-order-k", "kiosk") // no local orders row

	e.reconcileUnsyncedPayments()

	payload, ok := paymentCreateQueuePayload(t, e, "p-k")
	if !ok {
		t.Fatal("kiosk payment must be re-homed (enqueued) by the reconciler")
	}
	if payload["target"] != "workstation" {
		t.Errorf("kiosk re-home must target the workstation route, got %v", payload["target"])
	}
	if payload["idempotency_key"] != "idem-p-k" {
		t.Errorf("must reuse idempotency_key, got %v", payload["idempotency_key"])
	}
	if payload["order_id"] != "cloud-order-k" {
		t.Errorf("order_id want cloud-order-k, got %v", payload["order_id"])
	}
	if !kioskRehomed(t, e, "p-k") {
		t.Error("rehomed_at must be set to cap future re-homes")
	}
}

// The kiosk re-home is capped at ONE: a Confirmed order 409s on the workstation
// route and cloudPost misreads that 409 as success (row marked synced, no
// cloud_id). Without the cap the reconciler would re-enqueue every tick forever.
func TestReconcileUnsyncedPayments_KioskRehomeCappedAtOnce(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://unused")
	seedReconcilePayment(t, e, "p-k", "cloud-order-k", "kiosk")

	e.reconcileUnsyncedPayments() // first tick → re-home
	if _, ok := paymentCreateQueuePayload(t, e, "p-k"); !ok {
		t.Fatal("first reconcile must re-home the kiosk payment")
	}
	if !kioskRehomed(t, e, "p-k") {
		t.Fatal("rehomed_at must be set after the first re-home")
	}

	// Simulate the Confirmed-order 409-as-success: the enqueued row is marked
	// synced WITHOUT a cloud_id being written → payment stays cloud_id-NULL with no
	// live row (the exact state that would otherwise loop).
	db.Exec(`UPDATE sync_queue SET synced_at = datetime('now')
		WHERE entity_type='payment' AND operation='create' AND entity_id='p-k'`)

	e.reconcileUnsyncedPayments() // second tick → must NOT re-enqueue
	var active int
	db.QueryRow(`SELECT COUNT(*) FROM sync_queue
		WHERE entity_type='payment' AND operation='create' AND entity_id='p-k' AND synced_at IS NULL`).Scan(&active)
	if active != 0 {
		t.Errorf("kiosk re-home must be capped at one; got %d new active create rows", active)
	}
}

// A payment with an unknown origin (sync_target NULL — a row that predates the
// origin column) is NOT auto-re-homed: guessing the workstation route could push
// it onto the wrong identity. It still blocks unpair via the guard.
func TestReconcileUnsyncedPayments_SkipsNullSyncTarget(t *testing.T) {
	e, _ := newSyncTestEngine(t, "http://unused")
	seedReconcileOrder(t, e, "o-1", "cloud-o-1")
	seedReconcilePayment(t, e, "p-legacy", "o-1", "") // sync_target NULL

	e.reconcileUnsyncedPayments()

	if _, ok := paymentCreateQueuePayload(t, e, "p-legacy"); ok {
		t.Error("NULL sync_target (unknown origin) must not be auto-re-homed")
	}
}

// A payment whose order has NOT reached Cloud yet is left for a later tick
// (nothing to resolve order_id → cloud_id against).
func TestReconcileUnsyncedPayments_SkipsWhenOrderNotOnCloud(t *testing.T) {
	e, _ := newSyncTestEngine(t, "http://unused")
	seedReconcileOrder(t, e, "o-1", "") // no cloud_id
	seedReconcilePayment(t, e, "p-1", "o-1", "workstation")

	e.reconcileUnsyncedPayments()

	if _, ok := paymentCreateQueuePayload(t, e, "p-1"); ok {
		t.Error("payment for a not-yet-synced order must not be enqueued")
	}
}

// plan-818 K2 edge: a kiosk payment with a LIVE create row whose baked kiosk
// token is stale 401s on /kiosk forever (errAuthRejected never dead-letters).
// rehomeKioskPaymentRow re-homes that row in place onto the workstation route +
// device token so it re-pushes under the workstation identity.
func TestRehomeKioskPaymentRow(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://unused")
	db.Exec(`INSERT INTO settings (key, value) VALUES ('device_token','dev-tok')
		ON CONFLICT(key) DO UPDATE SET value='dev-tok'`)
	seedReconcilePayment(t, e, "p-k", "cloud-order-k", "kiosk")
	rowID := seedKioskCreateRow(t, e, "p-k", "cloud-order-k")

	if !e.rehomeKioskPaymentRow(rowID, "payment", "create", "p-k") {
		t.Fatal("expected the kiosk payment row to be re-homed")
	}

	var raw string
	db.QueryRow(`SELECT payload FROM sync_queue WHERE id=?`, rowID).Scan(&raw)
	var p map[string]any
	json.Unmarshal([]byte(raw), &p)
	if p["target"] != "workstation" {
		t.Errorf("target must be rewritten to workstation, got %v", p["target"])
	}
	if p["bearer_token"] != "dev-tok" {
		t.Errorf("bearer_token must be re-stamped to the device token, got %v", p["bearer_token"])
	}
	if p["order_id"] != "cloud-order-k" || p["payment_id"] != "p-k" {
		t.Errorf("payload identity fields must be preserved: %v", p)
	}
	if !kioskRehomed(t, e, "p-k") {
		t.Error("rehomed_at must be stamped (shared cap with the reconciler)")
	}
	if n := auditRehomeCount(t, e, "p-k"); n != 1 {
		t.Errorf("audit payment.rehomed_to_workstation want 1, got %d", n)
	}

	// Cap: a second attempt (rehomed_at now set) must be a no-op.
	if e.rehomeKioskPaymentRow(rowID, "payment", "create", "p-k") {
		t.Error("second re-home must be capped by rehomed_at")
	}
}

// The push-time re-home must NOT touch workstation-origin payments or
// non-payment rows (e.g. a till_session with a stale cashier token).
func TestRehomeKioskPaymentRow_SkipsNonKiosk(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://unused")
	db.Exec(`INSERT INTO settings (key, value) VALUES ('device_token','dev-tok')
		ON CONFLICT(key) DO UPDATE SET value='dev-tok'`)
	seedReconcileOrder(t, e, "o-1", "cloud-o-1")
	seedReconcilePayment(t, e, "p-ws", "o-1", "workstation")
	rowID := seedKioskCreateRow(t, e, "p-ws", "o-1")

	if e.rehomeKioskPaymentRow(rowID, "payment", "create", "p-ws") {
		t.Error("workstation-origin payment must not be re-homed")
	}
	if e.rehomeKioskPaymentRow(rowID, "till_session", "open", "s-1") {
		t.Error("non-payment entity must not be re-homed")
	}
}

// shouldAutoRecover gates the reconcilers against cross-branch contamination.
func TestShouldAutoRecover(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://unused")

	// No prev_branch_id → recover freely.
	if !e.shouldAutoRecover() {
		t.Error("empty prev_branch_id should allow recovery")
	}

	db.Exec(`INSERT INTO settings (key, value) VALUES ('workstation_branch_id','branch-A')
		ON CONFLICT(key) DO UPDATE SET value='branch-A'`)

	// prev == current → same-branch re-pair → recover.
	db.Exec(`INSERT INTO settings (key, value) VALUES ('unpair.prev_branch_id','branch-A')
		ON CONFLICT(key) DO UPDATE SET value='branch-A'`)
	if !e.shouldAutoRecover() {
		t.Error("same-branch prev should allow recovery")
	}

	// prev != current → cross-branch re-pair → do NOT recover.
	db.Exec(`UPDATE settings SET value='branch-B' WHERE key='unpair.prev_branch_id'`)
	if e.shouldAutoRecover() {
		t.Error("cross-branch prev must block recovery")
	}
}
