package service

// Issue #1055 — the workstation → Cloud leg of the offline-first peripheral
// config store. The local CRUD handler and the sync-DOWN pull were both covered,
// but the UP handlers were not, which is where the shipped bug lived: the local
// handler enqueued "peripheral.upsert" as the OPERATION, and the dispatcher
// builds its key as entityType + "." + operation, so the key became
// "peripheral.peripheral.upsert" — no handler, and pushToCloud drains an
// unroutable entry as a silent success. These tests pin the wiring plus the four
// non-obvious contracts of the handlers themselves.

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func newPeripheralTestDB(t *testing.T) *store.DB {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return db
}

// The keys the local handler enqueues must be the keys the engine registered.
// Asserting the composed key (not the handler func) is what catches the
// double-prefix class of bug.
func TestPeripheralSyncHandlersAreRegistered(t *testing.T) {
	engine := NewSyncEngine(newPeripheralTestDB(t), "http://cloud.invalid", nil)

	for _, key := range []string{"peripheral.upsert", "peripheral.delete"} {
		if !engine.HasHandler(key) {
			t.Errorf("no handler registered for %q", key)
		}
	}
	// The operation must not be pre-prefixed at the enqueue site.
	if engine.HasHandler("peripheral.peripheral.upsert") {
		t.Error("handler registered under the double-prefixed key")
	}
}

func TestHandlePeripheralUpsert_PostsRowAndClearsPendingSync(t *testing.T) {
	var gotPath string
	var gotBody map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		raw, _ := io.ReadAll(r.Body)
		gotBody = map[string]any{}
		_ = json.Unmarshal(raw, &gotBody)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"data":{"id":"p-1"}}`))
	}))
	t.Cleanup(srv.Close)

	db := newPeripheralTestDB(t)
	if _, err := db.Exec(`INSERT INTO peripheral_devices
		(id, name, type, is_active, metadata, branch_id, organization_id, pending_sync)
		VALUES ('p-1','Glory 釣銭機','coin_changer',1,'{"host":"192.168.1.50","port":9100,"model":"RAD-300"}','b-1','o-1',1)`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	engine := NewSyncEngine(db, srv.URL, nil)

	if _, retryable, err := engine.handlePeripheralUpsert(t.Context(), "p-1", nil); err != nil {
		t.Fatalf("handlePeripheralUpsert: err=%v retryable=%v", err, retryable)
	}

	if gotPath != "/api/v1/workstation/peripheral-devices" {
		t.Errorf("path = %q", gotPath)
	}
	// The client id travels with the body — Cloud upserts on it, so a replay
	// converges to one row instead of duplicating.
	if gotBody["id"] != "p-1" || gotBody["type"] != "coin_changer" || gotBody["is_active"] != true {
		t.Errorf("body = %v", gotBody)
	}
	// metadata must survive as a nested object, model included: Cloud's
	// FormRequest keeps the full array precisely so free-form keys aren't lost.
	meta, ok := gotBody["metadata"].(map[string]any)
	if !ok || meta["host"] != "192.168.1.50" || meta["model"] != "RAD-300" {
		t.Errorf("metadata = %v, want host+model preserved", gotBody["metadata"])
	}

	var pending int
	_ = db.QueryRow(`SELECT pending_sync FROM peripheral_devices WHERE id='p-1'`).Scan(&pending)
	if pending != 0 {
		t.Errorf("pending_sync = %d after a confirmed push, want 0", pending)
	}
}

// A row edited while its op sat in the queue must push the NEWEST values: the
// handler re-reads the row at send time rather than trusting the queue payload.
func TestHandlePeripheralUpsert_ReadsRowAtSendTime(t *testing.T) {
	var gotBody map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		gotBody = map[string]any{}
		_ = json.Unmarshal(raw, &gotBody)
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"data":{"id":"p-1"}}`))
	}))
	t.Cleanup(srv.Close)

	db := newPeripheralTestDB(t)
	if _, err := db.Exec(`INSERT INTO peripheral_devices
		(id, name, type, is_active, branch_id, organization_id, pending_sync)
		VALUES ('p-1','Old name','payment_terminal',1,'b-1','o-1',1)`); err != nil {
		t.Fatalf("seed: %v", err)
	}
	// Offline re-edit after the op was queued.
	if _, err := db.Exec(`UPDATE peripheral_devices SET name='New name' WHERE id='p-1'`); err != nil {
		t.Fatalf("edit: %v", err)
	}

	engine := NewSyncEngine(db, srv.URL, nil)
	if _, _, err := engine.handlePeripheralUpsert(t.Context(), "p-1", map[string]any{"name": "Old name"}); err != nil {
		t.Fatalf("handlePeripheralUpsert: %v", err)
	}

	if gotBody["name"] != "New name" {
		t.Errorf("pushed name = %v, want the value at send time", gotBody["name"])
	}
}

// A tombstoned row is awaiting a DELETE. If a stale upsert op were still queued
// it must not resurrect the row on Cloud.
func TestHandlePeripheralUpsert_SkipsTombstonedRow(t *testing.T) {
	called := false
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		called = true
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{}`))
	}))
	t.Cleanup(srv.Close)

	db := newPeripheralTestDB(t)
	if _, err := db.Exec(`INSERT INTO peripheral_devices
		(id, name, type, branch_id, organization_id, pending_sync, pending_delete)
		VALUES ('p-1','Gone','receipt_printer','b-1','o-1',1,1)`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	engine := NewSyncEngine(db, srv.URL, nil)
	_, retryable, err := engine.handlePeripheralUpsert(t.Context(), "p-1", nil)
	if err != nil || retryable {
		t.Fatalf("want a clean no-op, got err=%v retryable=%v", err, retryable)
	}
	if called {
		t.Error("pushed a tombstoned row to Cloud")
	}
}

// Cloud returning 404 means the row is already gone there — the delete has
// converged, so it must count as success and drop the local row. Treating it as
// an error would leave the tombstone retrying forever.
func TestHandlePeripheralDelete_TreatsCloud404AsDone(t *testing.T) {
	for _, status := range []int{http.StatusNoContent, http.StatusNotFound} {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if r.Method != http.MethodDelete {
				t.Errorf("method = %s, want DELETE", r.Method)
			}
			if r.URL.Path != "/api/v1/workstation/peripheral-devices/p-1" {
				t.Errorf("path = %q", r.URL.Path)
			}
			w.WriteHeader(status)
		}))

		db := newPeripheralTestDB(t)
		if _, err := db.Exec(`INSERT INTO peripheral_devices
			(id, name, type, branch_id, organization_id, pending_delete)
			VALUES ('p-1','Gone','receipt_printer','b-1','o-1',1)`); err != nil {
			t.Fatalf("seed: %v", err)
		}

		engine := NewSyncEngine(db, srv.URL, nil)
		if _, retryable, err := engine.handlePeripheralDelete(t.Context(), "p-1", nil); err != nil {
			t.Fatalf("status %d: err=%v retryable=%v", status, err, retryable)
		}

		var count int
		_ = db.QueryRow(`SELECT COUNT(*) FROM peripheral_devices WHERE id='p-1'`).Scan(&count)
		if count != 0 {
			t.Errorf("status %d: local row survived a converged delete", status)
		}
		srv.Close()
	}
}

func TestHandlePeripheralDelete_RetriesOnServerError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	t.Cleanup(srv.Close)

	db := newPeripheralTestDB(t)
	if _, err := db.Exec(`INSERT INTO peripheral_devices
		(id, name, type, branch_id, organization_id, pending_delete)
		VALUES ('p-1','Gone','receipt_printer','b-1','o-1',1)`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	engine := NewSyncEngine(db, srv.URL, nil)
	_, retryable, err := engine.handlePeripheralDelete(t.Context(), "p-1", nil)
	if err == nil || !retryable {
		t.Fatalf("want a retryable error on 500, got err=%v retryable=%v", err, retryable)
	}

	var count int
	_ = db.QueryRow(`SELECT COUNT(*) FROM peripheral_devices WHERE id='p-1'`).Scan(&count)
	if count != 1 {
		t.Error("dropped the local row before Cloud confirmed the delete")
	}
}

// The convergence guarantee: a row mutated locally whose enqueue was lost (crash
// between the local write and Enqueue) gets re-queued on the next tick.
func TestReconcilePendingPeripherals_ReEnqueuesOrphanedMutations(t *testing.T) {
	db := newPeripheralTestDB(t)
	if _, err := db.Exec(`INSERT INTO peripheral_devices
		(id, name, type, branch_id, organization_id, pending_sync, pending_delete) VALUES
		('p-edit','Edited','payment_terminal','b-1','o-1',1,0),
		('p-gone','Tombstoned','receipt_printer','b-1','o-1',0,1),
		('p-clean','Synced','kitchen_printer','b-1','o-1',0,0)`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	engine := NewSyncEngine(db, "http://cloud.invalid", nil)
	engine.reconcilePendingPeripherals()

	ops := map[string]string{}
	rows, err := db.Query(`SELECT entity_id, operation FROM sync_queue WHERE entity_type='peripheral' AND synced_at IS NULL`)
	if err != nil {
		t.Fatalf("query queue: %v", err)
	}
	defer rows.Close()
	for rows.Next() {
		var id, op string
		if err := rows.Scan(&id, &op); err != nil {
			t.Fatalf("scan: %v", err)
		}
		ops[id] = op
	}

	if ops["p-edit"] != "upsert" {
		t.Errorf("p-edit op = %q, want upsert", ops["p-edit"])
	}
	if ops["p-gone"] != "delete" {
		t.Errorf("p-gone op = %q, want delete", ops["p-gone"])
	}
	if _, found := ops["p-clean"]; found {
		t.Error("re-enqueued a fully synced row")
	}

	// Idempotent: a second pass must not double-queue rows that now have a live op.
	engine.reconcilePendingPeripherals()
	var queued int
	_ = db.QueryRow(`SELECT COUNT(*) FROM sync_queue WHERE entity_type='peripheral' AND synced_at IS NULL`).Scan(&queued)
	if queued != 2 {
		t.Errorf("queue rows after a second reconcile = %d, want 2", queued)
	}
}
