package service

import (
	"context"
	"encoding/json"
	"errors"
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

// ─── stub hub ────────────────────────────────────────────────────────────────

type stubBroadcastHub struct {
	events []map[string]any
}

func (h *stubBroadcastHub) BroadcastEventScoped(eventType string, payload any, branchID string) {
	p, _ := payload.(map[string]any)
	e := map[string]any{
		"type":     eventType,
		"branchID": branchID,
		"payload":  p,
	}
	h.events = append(h.events, e)
}

// ─── helpers ─────────────────────────────────────────────────────────────────

// newKdsTestDB opens a temp SQLite DB and seeds one order + one order_item.
// Returns db, itemID, orderID, origUpdatedAt.
func newKdsTestDB(t *testing.T, itemStatus string) (*store.DB, string, string, string) {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "kds_test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	now := time.Now().UTC().Format(time.RFC3339)
	orderID := "order-kds-1"
	itemID := "item-kds-1"

	_, err = db.Exec(`
		INSERT INTO orders (
			id, cloud_id, order_code, order_number, order_type, status,
			opened_at, guest_count,
			subtotal, discount_amount, service_charge, tax_amount,
			total_tip, total_amount, paid_amount,
			organization_id, brand_id, branch_id,
			created_at, updated_at
		) VALUES (?, ?, 'WS-000001', 1, 'dine_in', 'open',
			?, 2, 0, 0, 0, 0, 0, 0, 0, 'org', 'brand', 'branch-1', ?, ?)
	`, orderID, orderID, now, now, now)
	if err != nil {
		t.Fatalf("seed order: %v", err)
	}

	origUpdatedAt := "2026-05-26T10:00:00Z"
	_, err = db.Exec(`
		INSERT INTO order_items (
			id, customer_order_id, menu_item_name,
			quantity, unit_price, subtotal,
			printer_group, status, print_status,
			created_at, updated_at
		) VALUES (?, ?, 'Ramen', 1, 1000, 1000, 'kitchen', ?, 'pending', ?, ?)
	`, itemID, orderID, itemStatus, origUpdatedAt, origUpdatedAt)
	if err != nil {
		t.Fatalf("seed item: %v", err)
	}

	return db, itemID, orderID, origUpdatedAt
}

func newKdsSyncHandler(t *testing.T, cloudURL string, db *store.DB, hub BroadcastHub) *KdsBumpSyncHandler {
	t.Helper()
	return NewKdsBumpSyncHandler(
		db,
		func() string { return cloudURL },
		func() string { return "ws-device-token" },
		hub,
		func() string { return "branch-1" },
	)
}

// ─── tests ───────────────────────────────────────────────────────────────────

func TestKdsBumpSyncHandler_Success(t *testing.T) {
	var (
		seenMethod string
		seenPath   string
		seenAuth   string
		seenIdem   string
		seenBody   map[string]any
		callCount  int32
	)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&callCount, 1)
		seenMethod = r.Method
		seenPath = r.URL.Path
		seenAuth = r.Header.Get("Authorization")
		seenIdem = r.Header.Get("Idempotency-Key")
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.WriteHeader(http.StatusOK)
		w.Write([]byte(`{"data":{"id":"item-kds-1","status":"preparing"}}`))
	}))
	defer cloud.Close()

	db, itemID, orderID, origUpdatedAt := newKdsTestDB(t, "pending")
	hub := &stubBroadcastHub{}
	h := newKdsSyncHandler(t, cloud.URL, db, hub)

	payload := map[string]any{
		"order_id":            orderID,
		"item_id":             itemID,
		"status":              "preparing",
		"idempotency_key":     "idem-kds-1",
		"previous_status":     "pending",
		"original_updated_at": origUpdatedAt,
		"actor_kds_device_id": "kds-device-1",
	}

	resp, retryable, err := h.Handle(context.Background(), itemID, payload)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if retryable {
		t.Error("expected retryable=false on success")
	}
	if resp != nil {
		t.Errorf("expected nil resp on success, got %v", resp)
	}

	// Verify cloud was called correctly.
	if atomic.LoadInt32(&callCount) != 1 {
		t.Errorf("expected 1 cloud call, got %d", callCount)
	}
	if seenMethod != http.MethodPatch {
		t.Errorf("expected PATCH, got %s", seenMethod)
	}
	wantPath := "/api/v1/workstation/orders/" + orderID + "/items/" + itemID + "/status"
	if seenPath != wantPath {
		t.Errorf("path: want %q, got %q", wantPath, seenPath)
	}
	if seenAuth != "Bearer ws-device-token" {
		t.Errorf("Authorization: want %q, got %q", "Bearer ws-device-token", seenAuth)
	}
	if seenIdem != "idem-kds-1" {
		t.Errorf("Idempotency-Key: want %q, got %q", "idem-kds-1", seenIdem)
	}
	if seenBody["status"] != "preparing" {
		t.Errorf("body.status: want preparing, got %v", seenBody["status"])
	}
	if seenBody["actor_kds_device_id"] != "kds-device-1" {
		t.Errorf("body.actor_kds_device_id: want kds-device-1, got %v", seenBody["actor_kds_device_id"])
	}

	// No revert broadcast on success.
	if len(hub.events) != 0 {
		t.Errorf("expected no broadcast events on success, got %d", len(hub.events))
	}

	// Local DB status should remain as-is (we don't touch it on success).
	var dbStatus string
	db.QueryRow("SELECT status FROM order_items WHERE id = ?", itemID).Scan(&dbStatus)
	if dbStatus != "pending" {
		// "pending" because we seeded with "pending" and on success we don't update
		t.Errorf("expected db status unchanged (pending), got %q", dbStatus)
	}
}

// A bump whose parent order has no cloud_id yet must return an error that
// wraps errDependencyNotReady (retryable), so processQueue SKIPs it (continue)
// and still reaches the lower-priority order.create in the same cycle. If the
// error did not wrap errDependencyNotReady, processQueue would treat it as a
// Cloud-wide outage and `return` out of the cycle — starving the priority-1
// order.create forever so cloud_id never fills (the head-of-line deadlock).
func TestKdsBumpSyncHandler_EmptyCloudID_WrapsDependencyNotReady(t *testing.T) {
	var callCount int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&callCount, 1)
		w.WriteHeader(http.StatusOK)
	}))
	defer cloud.Close()

	db, itemID, orderID, origUpdatedAt := newKdsTestDB(t, "pending")
	// Simulate the order's own create push not having synced yet.
	if _, err := db.Exec("UPDATE orders SET cloud_id = '' WHERE id = ?", orderID); err != nil {
		t.Fatalf("clear cloud_id: %v", err)
	}
	h := newKdsSyncHandler(t, cloud.URL, db, &stubBroadcastHub{})

	payload := map[string]any{
		"order_id":            orderID,
		"item_id":             itemID,
		"status":              "preparing",
		"idempotency_key":     "idem-kds-nocloud",
		"previous_status":     "pending",
		"original_updated_at": origUpdatedAt,
		"actor_kds_device_id": "kds-device-1",
	}

	_, retryable, err := h.Handle(context.Background(), itemID, payload)
	if err == nil {
		t.Fatal("expected error when cloud_id empty")
	}
	if !retryable {
		t.Error("expected retryable=true when cloud_id empty")
	}
	if !errors.Is(err, errDependencyNotReady) {
		t.Errorf("error must wrap errDependencyNotReady (else processQueue halts the cycle), got: %v", err)
	}
	// Must not have hit Cloud — the guard fires before any HTTP call.
	if atomic.LoadInt32(&callCount) != 0 {
		t.Errorf("expected 0 cloud calls, got %d", callCount)
	}
}

func TestKdsBumpSyncHandler_Conflict409_RevertsLocal(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, `{"error":"conflict"}`, http.StatusConflict)
	}))
	defer cloud.Close()

	// Seed with status='preparing' (as if Task 2.6 already applied the bump locally).
	db, itemID, orderID, origUpdatedAt := newKdsTestDB(t, "preparing")
	hub := &stubBroadcastHub{}
	h := newKdsSyncHandler(t, cloud.URL, db, hub)

	payload := map[string]any{
		"order_id":            orderID,
		"item_id":             itemID,
		"status":              "preparing", // the bump workstation applied
		"idempotency_key":     "idem-kds-conflict",
		"previous_status":     "pending", // what it was before the bump
		"original_updated_at": origUpdatedAt,
		"actor_kds_device_id": "kds-device-1",
	}

	resp, retryable, err := h.Handle(context.Background(), itemID, payload)
	// 409 is terminal — no error returned, no retry.
	if err != nil {
		t.Fatalf("409 should be terminal (no error), got: %v", err)
	}
	if retryable {
		t.Error("409 must be non-retryable (terminal)")
	}
	if resp != nil {
		t.Errorf("expected nil resp on 409, got %v", resp)
	}

	// Local status must be reverted to previous_status.
	var dbStatus, dbUpdatedAt string
	db.QueryRow("SELECT status, updated_at FROM order_items WHERE id = ?", itemID).Scan(&dbStatus, &dbUpdatedAt)
	if dbStatus != "pending" {
		t.Errorf("revert: want status=pending, got %q", dbStatus)
	}
	if dbUpdatedAt != origUpdatedAt {
		t.Errorf("revert: want updated_at=%q, got %q", origUpdatedAt, dbUpdatedAt)
	}

	// Corrective WS event must be broadcast.
	if len(hub.events) != 1 {
		t.Fatalf("expected 1 broadcast event, got %d", len(hub.events))
	}
	evt := hub.events[0]
	if evt["type"] != "order_item.status_changed" {
		t.Errorf("event type: want order_item.status_changed, got %v", evt["type"])
	}
	if evt["branchID"] != "branch-1" {
		t.Errorf("event branchID: want branch-1, got %v", evt["branchID"])
	}
	evtPayload, _ := evt["payload"].(map[string]any)
	if evtPayload["status"] != "pending" {
		t.Errorf("event payload.status: want pending (reverted), got %v", evtPayload["status"])
	}
	if evtPayload["previous_status"] != "preparing" {
		t.Errorf("event payload.previous_status: want preparing (rejected attempt), got %v", evtPayload["previous_status"])
	}
	if evtPayload["source"] != "revert" {
		t.Errorf("event payload.source: want revert, got %v", evtPayload["source"])
	}
	if evtPayload["reason"] != "sync_conflict" {
		t.Errorf("event payload.reason: want sync_conflict, got %v", evtPayload["reason"])
	}
}

func TestKdsBumpSyncHandler_NetworkError_Retryable(t *testing.T) {
	// Point at a closed server to simulate connection refused.
	closed := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	closed.Close()

	db, itemID, orderID, origUpdatedAt := newKdsTestDB(t, "preparing")
	hub := &stubBroadcastHub{}
	h := newKdsSyncHandler(t, closed.URL, db, hub)

	payload := map[string]any{
		"order_id":            orderID,
		"item_id":             itemID,
		"status":              "preparing",
		"idempotency_key":     "idem-net-err",
		"previous_status":     "pending",
		"original_updated_at": origUpdatedAt,
		"actor_kds_device_id": "kds-device-1",
	}

	_, retryable, err := h.Handle(context.Background(), itemID, payload)
	if err == nil {
		t.Fatal("expected error on network failure")
	}
	if !retryable {
		t.Error("network error must be retryable=true")
	}

	// No revert on network error — local state might still be correct.
	if len(hub.events) != 0 {
		t.Errorf("expected no broadcast on network error, got %d", len(hub.events))
	}

	// Local status stays as seeded.
	var dbStatus string
	db.QueryRow("SELECT status FROM order_items WHERE id = ?", itemID).Scan(&dbStatus)
	if dbStatus != "preparing" {
		t.Errorf("expected status unchanged on network error, got %q", dbStatus)
	}
}

func TestKdsBumpSyncHandler_Server503_Retryable(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, `{"error":"service unavailable"}`, http.StatusServiceUnavailable)
	}))
	defer cloud.Close()

	db, itemID, orderID, origUpdatedAt := newKdsTestDB(t, "preparing")
	hub := &stubBroadcastHub{}
	h := newKdsSyncHandler(t, cloud.URL, db, hub)

	payload := map[string]any{
		"order_id":            orderID,
		"item_id":             itemID,
		"status":              "preparing",
		"idempotency_key":     "idem-503",
		"previous_status":     "pending",
		"original_updated_at": origUpdatedAt,
		"actor_kds_device_id": "kds-device-1",
	}

	_, retryable, err := h.Handle(context.Background(), itemID, payload)
	if err == nil {
		t.Fatal("expected error on 503")
	}
	if !retryable {
		t.Error("503 must be retryable=true")
	}

	// No revert on 503.
	if len(hub.events) != 0 {
		t.Errorf("expected no broadcast on 503, got %d", len(hub.events))
	}
}
