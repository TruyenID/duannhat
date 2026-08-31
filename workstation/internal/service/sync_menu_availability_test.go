package service

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// plan-056 — the sync-UP half of the POS availability screen, and the pull-side
// reconciliation that decides which local edits survive a catalog replace-all.
//
// The reconciler is the piece the feature depends on. `PullMenuCatalog` DELETEs
// every pos_menu_* row and re-inserts them, so a toggle the shop made while
// offline lives only in the override table — and an unrelated HQ edit is enough
// to trigger the pull that would otherwise erase it.

// mustExecAvail is local to this file — `mustExecDB` already exists in
// sync_plan045_test.go and the package shares one namespace.
func mustExecAvail(t *testing.T, db *store.DB, q string, args ...any) {
	t.Helper()
	if _, err := db.Exec(q, args...); err != nil {
		t.Fatalf("exec %q: %v", q, err)
	}
}

func seedAvailabilityReplica(t *testing.T, db *store.DB) {
	t.Helper()
	mustExecAvail(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m1','Lunch','published',0)`)
	mustExecAvail(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExecAvail(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES ('sk1','p1','S',1000)`)
	mustExecAvail(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, is_active, display_order) VALUES ('mp1','m1','p1',1,1)`)
	mustExecAvail(t, db, `INSERT INTO pos_menu_product_skus (id, menu_product_id, product_sku_id, is_active, selling_price) VALUES ('mps1','mp1','sk1',1,1000)`)
}

func insertOverride(t *testing.T, db *store.DB, entityType, entityID string, isActive, pending int) {
	t.Helper()
	mustExecAvail(t, db, `
		INSERT INTO pos_menu_availability_overrides
			(entity_type, entity_id, is_active, reason, acted_at, pending_sync)
		VALUES (?, ?, ?, 'Hết hàng', '2026-08-13T00:00:00Z', ?)`,
		entityType, entityID, isActive, pending)
}

func overrideCount(t *testing.T, db *store.DB, entityType, entityID string) int {
	t.Helper()
	var n int
	if err := db.QueryRow(`
		SELECT COUNT(*) FROM pos_menu_availability_overrides
		WHERE entity_type = ? AND entity_id = ?`, entityType, entityID).Scan(&n); err != nil {
		t.Fatalf("count: %v", err)
	}

	return n
}

// runReconcile drives the reconciler through a real transaction, the same way
// PullMenuCatalog does at the end of its atomic block.
func runReconcile(t *testing.T, db *store.DB) {
	t.Helper()
	if err := db.Transaction(reconcileAvailabilityOverrides); err != nil {
		t.Fatalf("reconcile: %v", err)
	}
}

// =========================================================================
//  Reconciliation — the four rules
// =========================================================================

func TestReconcile_KeepsPendingOverrideThroughAPull(t *testing.T) {
	// RULE 1, and the reason the override table exists at all.
	//
	// The shop turned a dish off while offline. The op is still in the queue, so
	// Cloud's copy is simply OLDER than ours — not authoritative yet. Dropping
	// the row here is the "we turned that dish off an hour ago and it is on sale
	// again" bug, triggered by an HQ edit that had nothing to do with this dish.
	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	seedAvailabilityReplica(t, db)
	insertOverride(t, db, "menu_product", "mp1", 0, 1)

	runReconcile(t, db)

	if overrideCount(t, db, "menu_product", "mp1") != 1 {
		t.Fatal("a not-yet-synced toggle was erased by a catalog pull")
	}
}

func TestReconcile_DropsSyncedOverrideOnceCloudAgrees(t *testing.T) {
	// RULE 2. Converged — the row is now noise, and a cache that keeps rows
	// past their purpose is a cache nobody can reason about.
	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	seedAvailabilityReplica(t, db)
	mustExecAvail(t, db, `UPDATE pos_menu_products SET is_active = 0 WHERE id = 'mp1'`)
	insertOverride(t, db, "menu_product", "mp1", 0, 0)

	runReconcile(t, db)

	if overrideCount(t, db, "menu_product", "mp1") != 0 {
		t.Fatal("a converged override outlived its purpose")
	}
}

func TestReconcile_DropsSyncedOverrideWhenCloudDisagrees(t *testing.T) {
	// RULE 3. Our push landed, then somebody re-enabled the dish in admin-web.
	// Cloud is the arbiter; keeping the local value would let one shop tablet
	// quietly override head office forever.
	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	seedAvailabilityReplica(t, db) // replica says ON
	insertOverride(t, db, "menu_product", "mp1", 0, 0)

	runReconcile(t, db)

	if overrideCount(t, db, "menu_product", "mp1") != 0 {
		t.Fatal("a synced override kept overriding Cloud after Cloud changed its mind")
	}
}

func TestReconcile_DropsOrphansInBothSyncStates(t *testing.T) {
	// RULE 4. HQ removed the dish from the menu. A pending override for it can
	// never be pushed either (Cloud answers 404), so leaving it would grow the
	// table forever and re-park a dead row on every pull.
	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	seedAvailabilityReplica(t, db)
	insertOverride(t, db, "menu_product", "ghost-pending", 0, 1)
	insertOverride(t, db, "menu_product", "ghost-synced", 0, 0)
	insertOverride(t, db, "menu_product_sku", "ghost-sku", 0, 1)

	runReconcile(t, db)

	for _, id := range []string{"ghost-pending", "ghost-synced"} {
		if overrideCount(t, db, "menu_product", id) != 0 {
			t.Errorf("orphan %q survived", id)
		}
	}
	if overrideCount(t, db, "menu_product_sku", "ghost-sku") != 0 {
		t.Error("orphan variant override survived")
	}
}

func TestReconcile_LeavesVariantOverridesAloneWhilePending(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	seedAvailabilityReplica(t, db)
	insertOverride(t, db, "menu_product_sku", "mps1", 0, 1)

	runReconcile(t, db)

	if overrideCount(t, db, "menu_product_sku", "mps1") != 1 {
		t.Fatal("a pending variant toggle was erased by a catalog pull")
	}
}

// =========================================================================
//  Dispatch wiring
// =========================================================================

func TestMenuAvailabilityHandlersAreRegistered(t *testing.T) {
	// A key missing from the dispatch map is NOT a loud failure: pushToCloud
	// drains the row as a no-op "success", so the shop's "we are out of this"
	// silently never reaches Cloud. That is the #534 class of bug, and this
	// assertion is the only thing standing between it and production.
	e, _ := newSyncTestEngine(t, "http://unused")

	for _, key := range []string{
		"menu_product.availability",
		"menu_product_sku.availability",
		"menu_availability.bulk",
	} {
		if !e.HasHandler(key) {
			t.Errorf("no sync handler registered for %q", key)
		}
	}
}

// =========================================================================
//  Push
// =========================================================================

func TestPushMenuProductAvailability_ForwardsValueAndClearsPending(t *testing.T) {
	var seenPath, seenMethod string
	var seenBody map[string]any

	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seenPath = r.URL.Path
		seenMethod = r.Method
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"mp1","is_active":false}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	seedAvailabilityReplica(t, db)
	insertOverride(t, db, "menu_product", "mp1", 0, 1)

	_, retryable, err := e.handleMenuProductAvailability(context.Background(), "mp1", map[string]any{
		"is_active":   false,
		"reason":      "Hết hàng",
		"actor_name":  "Ann",
		"occurred_at": "2026-08-13T00:00:00Z",
	})
	if err != nil {
		t.Fatalf("push: %v (retryable=%v)", err, retryable)
	}

	if seenMethod != http.MethodPost || seenPath != "/api/v1/workstation/menu-products/mp1/availability" {
		t.Fatalf("called %s %s", seenMethod, seenPath)
	}
	if seenBody["is_active"] != false {
		t.Errorf("is_active = %v, want false — the op must carry a VALUE, never a flip", seenBody["is_active"])
	}
	if seenBody["reason"] != "Hết hàng" {
		t.Errorf("reason = %v", seenBody["reason"])
	}
	// The operator's timestamp, forwarded verbatim. For an offline shop this is
	// hours before the push, and stamping arrival time instead would pile a
	// whole disconnected shift onto the minute the link came back.
	if seenBody["occurred_at"] != "2026-08-13T00:00:00Z" {
		t.Errorf("occurred_at = %v, want the operator timestamp", seenBody["occurred_at"])
	}

	var pending int
	db.QueryRow(`SELECT pending_sync FROM pos_menu_availability_overrides WHERE entity_id='mp1'`).Scan(&pending)
	if pending != 0 {
		t.Error("pending_sync survived a successful push — the reconciler would protect this row forever")
	}
}

func TestPushMenuProductAvailability_KeepsPendingWhenCloudFails(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
		w.Write([]byte(`{"message":"boom"}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	seedAvailabilityReplica(t, db)
	insertOverride(t, db, "menu_product", "mp1", 0, 1)

	_, retryable, err := e.handleMenuProductAvailability(context.Background(), "mp1", map[string]any{
		"is_active": false,
	})
	if err == nil {
		t.Fatal("want an error on 500")
	}
	if !retryable {
		t.Error("a 5xx must be retryable — Cloud being down is not the shop's mistake")
	}

	var pending int
	db.QueryRow(`SELECT pending_sync FROM pos_menu_availability_overrides WHERE entity_id='mp1'`).Scan(&pending)
	if pending != 1 {
		t.Fatal("pending_sync was cleared on a FAILED push — the next pull would erase the toggle")
	}
}

func TestPushMenuProductAvailability_DrainsMalformedRow(t *testing.T) {
	// No `is_active` means there is nothing to push and retrying cannot fix a
	// payload. Draining it beats letting it block the queue head forever.
	e, db := newSyncTestEngine(t, "http://unused")
	seedAvailabilityReplica(t, db)

	_, retryable, err := e.handleMenuProductAvailability(context.Background(), "mp1", map[string]any{})
	if err != nil || retryable {
		t.Fatalf("want a silent non-retryable drain, got err=%v retryable=%v", err, retryable)
	}
}

func TestPushVariantAvailability_UsesTheVariantEndpoint(t *testing.T) {
	var seenPath string
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seenPath = r.URL.Path
		w.Write([]byte(`{"data":{}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	seedAvailabilityReplica(t, db)
	insertOverride(t, db, "menu_product_sku", "mps1", 0, 1)

	if _, _, err := e.handleMenuProductSkuAvailability(context.Background(), "mps1", map[string]any{
		"is_active": false,
	}); err != nil {
		t.Fatalf("push: %v", err)
	}

	if seenPath != "/api/v1/workstation/menu-product-skus/mps1/availability" {
		t.Fatalf("called %s", seenPath)
	}

	var pending int
	db.QueryRow(`SELECT pending_sync FROM pos_menu_availability_overrides WHERE entity_id='mps1'`).Scan(&pending)
	if pending != 0 {
		t.Error("pending_sync survived a successful variant push")
	}
}

func TestPushBulk_SendsExplicitIDsAndClearsEveryRow(t *testing.T) {
	var seenBody map[string]any
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &seenBody)
		w.Write([]byte(`{"data":{}}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	seedAvailabilityReplica(t, db)
	mustExecAvail(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, is_active, display_order) VALUES ('mp2','m1','p1',1,2)`)
	insertOverride(t, db, "menu_product", "mp1", 0, 1)
	insertOverride(t, db, "menu_product", "mp2", 0, 1)

	if _, _, err := e.handleMenuAvailabilityBulk(context.Background(), "m1:sec1", map[string]any{
		"menu_id":          "m1",
		"menu_product_ids": []any{"mp1", "mp2"},
		"is_active":        false,
		"reason":           "Đóng bếp",
	}); err != nil {
		t.Fatalf("push: %v", err)
	}

	ids, _ := seenBody["menu_product_ids"].([]any)
	if len(ids) != 2 {
		t.Fatalf("menu_product_ids = %v — the op must carry EXPLICIT ids, never a section name", seenBody["menu_product_ids"])
	}

	var stillPending int
	db.QueryRow(`SELECT COUNT(*) FROM pos_menu_availability_overrides WHERE pending_sync = 1`).Scan(&stillPending)
	if stillPending != 0 {
		t.Errorf("%d rows still pending after a successful bulk push", stillPending)
	}
}

func TestPushBulk_DrainsEmptyIDList(t *testing.T) {
	e, db := newSyncTestEngine(t, "http://unused")
	seedAvailabilityReplica(t, db)

	_, retryable, err := e.handleMenuAvailabilityBulk(context.Background(), "m1:sec1", map[string]any{
		"menu_id":          "m1",
		"menu_product_ids": []any{},
		"is_active":        false,
	})
	if err != nil || retryable {
		t.Fatalf("want a silent non-retryable drain, got err=%v retryable=%v", err, retryable)
	}
}
