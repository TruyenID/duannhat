package handler

// Tests for Phase 5 plan-028 KDS operation endpoints:
//   POST /api/v1/kds/orders/{customerOrder}/items/{item}/mark-preparing
//   POST /api/v1/kds/orders/{customerOrder}/items/{item}/mark-ready
//   POST /api/v1/kds/orders/{customerOrder}/items/{item}/mark-served
//   POST /api/v1/kds/orders/{customerOrder}/items/{item}/revert
//   POST /api/v1/kds/orders/{customerOrder}/bump-all
//
// Each has: happy path, missing idem key (422), replay (200), cross-branch (403),
// voided order (409). bump-all also verifies all pending/preparing items advance.

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

// ─── Test infrastructure helpers ─────────────────────────────────────────────

// seedKdsOpsData inserts one open order (branch-A) with items of given statuses.
// Returns (orderID, []itemID) in insertion order.
func seedKdsOpsData(t *testing.T, s *Server, orderID string, itemStatuses []string) []string {
	t.Helper()
	seedKdsTables(t, s)
	_, err := s.db.Exec(`
		INSERT INTO orders (id, order_code, branch_id, status, opened_at)
		VALUES (?, 'ORD-OPS', 'branch-A', 'open', datetime('now'))
	`, orderID)
	if err != nil {
		t.Fatalf("seed order %s: %v", orderID, err)
	}
	itemIDs := make([]string, len(itemStatuses))
	for i, st := range itemStatuses {
		id := orderID + "-item-" + string(rune('a'+i))
		itemIDs[i] = id
		_, err = s.db.Exec(`
			INSERT INTO order_items
			  (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status)
			VALUES (?, ?, 'TestItem', 1, 1000, 1000, ?)
		`, id, orderID, st)
		if err != nil {
			t.Fatalf("seed item %s: %v", id, err)
		}
	}
	return itemIDs
}

// doPost sends a POST to path with the given Authorization and Idempotency-Key headers.
func doPost(t *testing.T, mux *http.ServeMux, path, token, idemKey string) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest(http.MethodPost, path, nil)
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	if idemKey != "" {
		req.Header.Set("Idempotency-Key", idemKey)
	}
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)
	return w
}

// doPostBody is doPost + a JSON body. Use for endpoints that require one
// (revert with `{to}`, bump-all with `{scope}`).
func doPostBody(t *testing.T, mux *http.ServeMux, path, token, idemKey, body string) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest(http.MethodPost, path, strings.NewReader(body))
	req.ContentLength = int64(len(body))
	req.Header.Set("Content-Type", "application/json")
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	if idemKey != "" {
		req.Header.Set("Idempotency-Key", idemKey)
	}
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)
	return w
}

// getItemStatus reads item status from DB.
func getItemStatus(t *testing.T, s *Server, itemID string) string {
	t.Helper()
	var st string
	if err := s.db.QueryRow(`SELECT status FROM order_items WHERE id = ?`, itemID).Scan(&st); err != nil {
		t.Fatalf("getItemStatus %s: %v", itemID, err)
	}
	return st
}

// ─── mark-preparing ───────────────────────────────────────────────────────────

func TestKdsMarkPreparing_HappyPath(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-p1", []string{"pending"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-p1/items/"+itemIDs[0]+"/mark-preparing",
		"kds-device-token", "idem-preparing-1")

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}

	var resp map[string]any
	if err := json.NewDecoder(w.Body).Decode(&resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	data := resp["data"].(map[string]any)
	if data["status"] != "preparing" {
		t.Errorf("expected status=preparing in response, got %v", data["status"])
	}
	if getItemStatus(t, s, itemIDs[0]) != "preparing" {
		t.Errorf("expected DB status=preparing")
	}
}

func TestKdsMarkPreparing_MissingIdempotencyKey(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-p2", []string{"pending"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// No Idempotency-Key header
	w := doPost(t, mux, "/api/v1/kds/orders/order-p2/items/"+itemIDs[0]+"/mark-preparing",
		"kds-device-token", "")

	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestKdsMarkPreparing_IdempotencyReplay(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-p3", []string{"pending"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	path := "/api/v1/kds/orders/order-p3/items/" + itemIDs[0] + "/mark-preparing"
	const idemKey = "idem-preparing-replay"

	w1 := doPost(t, mux, path, "kds-device-token", idemKey)
	if w1.Code != http.StatusOK {
		t.Fatalf("first call: expected 200, got %d", w1.Code)
	}

	// Second call with same idem key — replay
	w2 := doPost(t, mux, path, "kds-device-token", idemKey)
	if w2.Code != http.StatusOK {
		t.Fatalf("replay: expected 200, got %d body=%s", w2.Code, w2.Body.String())
	}

	// Body should be identical
	if w1.Body.String() != w2.Body.String() {
		t.Errorf("replay body mismatch: first=%s second=%s", w1.Body.String(), w2.Body.String())
	}

	// Status remains preparing (not double-advanced)
	if getItemStatus(t, s, itemIDs[0]) != "preparing" {
		t.Errorf("expected status to remain preparing after replay")
	}
}

func TestKdsMarkPreparing_CrossBranch403(t *testing.T) {
	// KDS device is branch-A, order is branch-B
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, err := db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-cross', 'ORD-X', 'branch-B', 'open', datetime('now'))`)
	if err != nil {
		t.Fatalf("seed order: %v", err)
	}
	_, err = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status) VALUES ('item-cross', 'order-cross', 'Ramen', 1, 1000, 1000, 'pending')`)
	if err != nil {
		t.Fatalf("seed item: %v", err)
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-cross/items/item-cross/mark-preparing",
		"kds-device-token", "idem-cross-1")

	if w.Code != http.StatusForbidden {
		t.Fatalf("expected 403, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestKdsMarkPreparing_VoidedOrder409(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, err := db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-void', 'ORD-V', 'branch-A', 'voided', datetime('now'))`)
	if err != nil {
		t.Fatalf("seed order: %v", err)
	}
	_, err = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status) VALUES ('item-void', 'order-void', 'Soba', 1, 800, 800, 'pending')`)
	if err != nil {
		t.Fatalf("seed item: %v", err)
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-void/items/item-void/mark-preparing",
		"kds-device-token", "idem-void-1")

	if w.Code != http.StatusConflict {
		t.Fatalf("expected 409 for voided order, got %d body=%s", w.Code, w.Body.String())
	}
}

// ─── mark-ready ───────────────────────────────────────────────────────────────

func TestKdsMarkReady_HappyPath(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-r1", []string{"preparing"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-r1/items/"+itemIDs[0]+"/mark-ready",
		"kds-device-token", "idem-ready-1")

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}
	if getItemStatus(t, s, itemIDs[0]) != "ready" {
		t.Errorf("expected DB status=ready")
	}
}

func TestKdsMarkReady_MissingIdemKey(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-r2", []string{"preparing"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-r2/items/"+itemIDs[0]+"/mark-ready",
		"kds-device-token", "")
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d", w.Code)
	}
}

func TestKdsMarkReady_CrossBranch403(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, _ = db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-r-cross', 'ORD-RC', 'branch-B', 'open', datetime('now'))`)
	_, _ = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status) VALUES ('item-r-cross', 'order-r-cross', 'Curry', 1, 1200, 1200, 'preparing')`)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-r-cross/items/item-r-cross/mark-ready",
		"kds-device-token", "idem-r-cross")
	if w.Code != http.StatusForbidden {
		t.Fatalf("expected 403, got %d", w.Code)
	}
}

func TestKdsMarkReady_VoidedOrder409(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, _ = db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-r-void', 'ORD-RV', 'branch-A', 'closed', datetime('now'))`)
	_, _ = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status) VALUES ('item-r-void', 'order-r-void', 'Gyoza', 1, 500, 500, 'preparing')`)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-r-void/items/item-r-void/mark-ready",
		"kds-device-token", "idem-r-void")
	if w.Code != http.StatusConflict {
		t.Fatalf("expected 409, got %d body=%s", w.Code, w.Body.String())
	}
}

// ─── mark-served ─────────────────────────────────────────────────────────────

func TestKdsMarkServed_HappyPath(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-s1", []string{"ready"})
	// Anti-misclick guard (KDS_E003) requires the item to have been ready for
	// at least 30s — stamp ready_at well into the past.
	if _, err := s.db.Exec(`UPDATE order_items SET ready_at = ? WHERE id = ?`,
		time.Now().UTC().Add(-60*time.Second).Format(time.RFC3339), itemIDs[0]); err != nil {
		t.Fatalf("set ready_at: %v", err)
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-s1/items/"+itemIDs[0]+"/mark-served",
		"kds-device-token", "idem-served-1")

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}
	if getItemStatus(t, s, itemIDs[0]) != "served" {
		t.Errorf("expected DB status=served")
	}

	// served_at should be set in DB
	var servedAt string
	if err := s.db.QueryRow(`SELECT COALESCE(served_at,'') FROM order_items WHERE id = ?`, itemIDs[0]).Scan(&servedAt); err != nil {
		t.Fatalf("read served_at: %v", err)
	}
	if servedAt == "" {
		t.Errorf("expected served_at to be set after mark-served")
	}
}

// Regression (plan-028 logic-risk): mark-served must enforce the 30s
// anti-misclick window (KDS_E003) the cloud enforces. A ready item marked
// served immediately after reaching ready must 409, not silently succeed.
func TestKdsMarkServed_TooSoonAfterReady409(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-s-soon", []string{"ready"})
	// ready_at 5s ago — inside the 30s window.
	if _, err := s.db.Exec(`UPDATE order_items SET ready_at = ? WHERE id = ?`,
		time.Now().UTC().Add(-5*time.Second).Format(time.RFC3339), itemIDs[0]); err != nil {
		t.Fatalf("set ready_at: %v", err)
	}

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-s-soon/items/"+itemIDs[0]+"/mark-served",
		"kds-device-token", "idem-s-soon")
	if w.Code != http.StatusConflict {
		t.Fatalf("expected 409 (too soon after ready), got %d body=%s", w.Code, w.Body.String())
	}
	if getItemStatus(t, s, itemIDs[0]) != "ready" {
		t.Errorf("expected status to remain ready after rejected mark-served")
	}
}

// Regression: mark-served on a ready item with no ready_at (never legitimately
// reached ready) must 409, mirroring cloud's assertMarkServedAllowed.
func TestKdsMarkServed_NoReadyAt409(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-s-nord", []string{"ready"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-s-nord/items/"+itemIDs[0]+"/mark-served",
		"kds-device-token", "idem-s-nord")
	if w.Code != http.StatusConflict {
		t.Fatalf("expected 409 (no ready_at), got %d body=%s", w.Code, w.Body.String())
	}
}

// Regression: forward-transition guard (KDS_E002). mark-preparing on an item
// already ready must NOT drag it backward to preparing — it must 409.
func TestKdsMarkPreparing_ForwardGuardRejectsBackward409(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-fg1", []string{"ready"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-fg1/items/"+itemIDs[0]+"/mark-preparing",
		"kds-device-token", "idem-fg1")
	if w.Code != http.StatusConflict {
		t.Fatalf("expected 409 (forward guard), got %d body=%s", w.Code, w.Body.String())
	}
	if getItemStatus(t, s, itemIDs[0]) != "ready" {
		t.Errorf("expected status to remain ready, got %s", getItemStatus(t, s, itemIDs[0]))
	}
}

// Regression: forward-transition guard (KDS_E002). mark-ready on a pending item
// must NOT skip preparing — it must 409.
func TestKdsMarkReady_ForwardGuardRejectsSkip409(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-fg2", []string{"pending"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-fg2/items/"+itemIDs[0]+"/mark-ready",
		"kds-device-token", "idem-fg2")
	if w.Code != http.StatusConflict {
		t.Fatalf("expected 409 (forward guard skip), got %d body=%s", w.Code, w.Body.String())
	}
	if getItemStatus(t, s, itemIDs[0]) != "pending" {
		t.Errorf("expected status to remain pending, got %s", getItemStatus(t, s, itemIDs[0]))
	}
}

// Regression (plan-028 wrong): served advertises NO transitions. Advertising
// "revert" while the revert handler rejects served (KDS_E002) is a dead FE
// button — allowed_transitions must match the enforced guard.
func TestKdsAllowedTransitions_ServedHasNoRevert(t *testing.T) {
	got := allowedKdsTransitions("served")
	if len(got) != 0 {
		t.Fatalf("expected served to advertise no transitions, got %v", got)
	}
}

func TestKdsMarkServed_MissingIdemKey(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-s2", []string{"ready"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-s2/items/"+itemIDs[0]+"/mark-served",
		"kds-device-token", "")
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d", w.Code)
	}
}

func TestKdsMarkServed_CrossBranch403(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, _ = db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-s-cross', 'ORD-SC', 'branch-B', 'open', datetime('now'))`)
	_, _ = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status) VALUES ('item-s-cross', 'order-s-cross', 'Ramen', 1, 1000, 1000, 'ready')`)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-s-cross/items/item-s-cross/mark-served",
		"kds-device-token", "idem-s-cross")
	if w.Code != http.StatusForbidden {
		t.Fatalf("expected 403, got %d", w.Code)
	}
}

func TestKdsMarkServed_VoidedOrder409(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, _ = db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-s-void', 'ORD-SV', 'branch-A', 'voided', datetime('now'))`)
	_, _ = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status) VALUES ('item-s-void', 'order-s-void', 'Tea', 1, 300, 300, 'ready')`)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-s-void/items/item-s-void/mark-served",
		"kds-device-token", "idem-s-void")
	if w.Code != http.StatusConflict {
		t.Fatalf("expected 409, got %d", w.Code)
	}
}

// ─── revert ───────────────────────────────────────────────────────────────────

func TestKdsRevert_HappyPath(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	// Item in 'ready' → should revert to 'preparing'
	itemIDs := seedKdsOpsData(t, s, "order-rv1", []string{"ready"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPostBody(t, mux, "/api/v1/kds/orders/order-rv1/items/"+itemIDs[0]+"/revert",
		"kds-device-token", "idem-revert-1", `{"to":"preparing"}`)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}
	if getItemStatus(t, s, itemIDs[0]) != "preparing" {
		t.Errorf("expected DB status=preparing after revert from ready")
	}
}

func TestKdsRevert_HonorsToBody(t *testing.T) {
	// ready item, FE asks `to: preparing` — workstation respects body (not
	// the previous internal one-step-back derivation).
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-rv-body", []string{"ready"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// to=pending: skip-back-two — cloud allows the body, workstation now does too.
	w := doPostBody(t, mux, "/api/v1/kds/orders/order-rv-body/items/"+itemIDs[0]+"/revert",
		"kds-device-token", "idem-rv-body", `{"to":"pending"}`)
	if w.Code != http.StatusOK {
		t.Fatalf("expected 200 for ready→pending body, got %d body=%s", w.Code, w.Body.String())
	}
	if getItemStatus(t, s, itemIDs[0]) != "pending" {
		t.Errorf("expected status=pending when body.to=pending")
	}
}

func TestKdsRevert_InvalidToBody422(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-rv-bad", []string{"ready"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPostBody(t, mux, "/api/v1/kds/orders/order-rv-bad/items/"+itemIDs[0]+"/revert",
		"kds-device-token", "idem-rv-bad", `{"to":"served"}`)
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422 for invalid to=served, got %d", w.Code)
	}
}

// Served is terminal per cloud KDS_E002 (post-Phase-5.4 — workstation aligns
// with cloud rejection instead of accepting served → ready locally).
func TestKdsRevert_FromServedRejected(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, _ = db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-rv2', 'ORD-RV2', 'branch-A', 'open', datetime('now'))`)
	_, _ = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status, served_at) VALUES ('item-rv2', 'order-rv2', 'Noodle', 1, 1000, 1000, 'served', datetime('now'))`)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-rv2/items/item-rv2/revert",
		"kds-device-token", "idem-rv2-served")
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422 (served terminal), got %d body=%s", w.Code, w.Body.String())
	}
	if getItemStatus(t, s, "item-rv2") != "served" {
		t.Errorf("expected status to remain served after rejected revert")
	}
	// served_at must remain populated
	var servedAt string
	_ = db.QueryRow(`SELECT COALESCE(served_at,'') FROM order_items WHERE id = 'item-rv2'`).Scan(&servedAt)
	if servedAt == "" {
		t.Errorf("expected served_at to remain set, got NULL")
	}
}

func TestKdsRevert_MissingIdemKey(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-rv3", []string{"preparing"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-rv3/items/"+itemIDs[0]+"/revert",
		"kds-device-token", "")
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d", w.Code)
	}
}

func TestKdsRevert_IdempotencyReplay(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-rv4", []string{"preparing"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	path := "/api/v1/kds/orders/order-rv4/items/" + itemIDs[0] + "/revert"
	w1 := doPostBody(t, mux, path, "kds-device-token", "idem-rv-replay", `{"to":"pending"}`)
	if w1.Code != http.StatusOK {
		t.Fatalf("first call: expected 200, got %d body=%s", w1.Code, w1.Body.String())
	}

	w2 := doPost(t, mux, path, "kds-device-token", "idem-rv-replay")
	if w2.Code != http.StatusOK {
		t.Fatalf("replay: expected 200, got %d", w2.Code)
	}
	if w1.Body.String() != w2.Body.String() {
		t.Errorf("replay body mismatch")
	}
}

func TestKdsRevert_CrossBranch403(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, _ = db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-rv-cross', 'ORD-RVC', 'branch-B', 'open', datetime('now'))`)
	_, _ = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status) VALUES ('item-rv-cross', 'order-rv-cross', 'Rice', 1, 600, 600, 'preparing')`)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-rv-cross/items/item-rv-cross/revert",
		"kds-device-token", "idem-rv-cross")
	if w.Code != http.StatusForbidden {
		t.Fatalf("expected 403, got %d", w.Code)
	}
}

func TestKdsRevert_VoidedOrder409(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, _ = db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-rv-void', 'ORD-RVV', 'branch-A', 'voided', datetime('now'))`)
	_, _ = db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, status) VALUES ('item-rv-void', 'order-rv-void', 'Cake', 1, 400, 400, 'preparing')`)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-rv-void/items/item-rv-void/revert",
		"kds-device-token", "idem-rv-void")
	if w.Code != http.StatusConflict {
		t.Fatalf("expected 409, got %d", w.Code)
	}
}

func TestKdsRevert_PendingReturns422(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	itemIDs := seedKdsOpsData(t, s, "order-rv5", []string{"pending"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-rv5/items/"+itemIDs[0]+"/revert",
		"kds-device-token", "idem-rv-pending")
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422 when reverting from pending, got %d body=%s", w.Code, w.Body.String())
	}
}

// ─── bump-all ─────────────────────────────────────────────────────────────────

func TestKdsBumpAll_HappyPath_AdvancesAllBumpable(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	// pending → preparing, preparing → ready, served stays served
	itemIDs := seedKdsOpsData(t, s, "order-ba1", []string{"pending", "preparing", "served"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-ba1/bump-all",
		"kds-device-token", "idem-ba-1")

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}

	// Verify DB states
	if getItemStatus(t, s, itemIDs[0]) != "preparing" {
		t.Errorf("item[0] (pending) should now be preparing")
	}
	if getItemStatus(t, s, itemIDs[1]) != "ready" {
		t.Errorf("item[1] (preparing) should now be ready")
	}
	if getItemStatus(t, s, itemIDs[2]) != "served" {
		t.Errorf("item[2] (served) should stay served")
	}

	// Verify response body
	var resp map[string]any
	if err := json.NewDecoder(w.Body).Decode(&resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	data := resp["data"].(map[string]any)
	bumped := data["bumped_count"].(float64)
	if bumped != 2 {
		t.Errorf("expected bumped_count=2, got %v", bumped)
	}
}

func TestKdsBumpAll_MissingIdemKey(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	seedKdsOpsData(t, s, "order-ba2", []string{"pending"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-ba2/bump-all", "kds-device-token", "")
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d", w.Code)
	}
}

func TestKdsBumpAll_IdempotencyReplay(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	seedKdsOpsData(t, s, "order-ba3", []string{"pending"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	path := "/api/v1/kds/orders/order-ba3/bump-all"
	w1 := doPost(t, mux, path, "kds-device-token", "idem-ba-replay")
	if w1.Code != http.StatusOK {
		t.Fatalf("first call: expected 200, got %d", w1.Code)
	}

	w2 := doPost(t, mux, path, "kds-device-token", "idem-ba-replay")
	if w2.Code != http.StatusOK {
		t.Fatalf("replay: expected 200, got %d", w2.Code)
	}
	if w1.Body.String() != w2.Body.String() {
		t.Errorf("replay body mismatch")
	}
}

func TestKdsBumpAll_CrossBranch403(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, _ = db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-ba-cross', 'ORD-BAC', 'branch-B', 'open', datetime('now'))`)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-ba-cross/bump-all", "kds-device-token", "idem-ba-cross")
	if w.Code != http.StatusForbidden {
		t.Fatalf("expected 403, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestKdsBumpAll_VoidedOrder409(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	seedKdsTables(t, s)

	_, _ = db.Exec(`INSERT INTO orders (id, order_code, branch_id, status, opened_at) VALUES ('order-ba-void', 'ORD-BAV', 'branch-A', 'voided', datetime('now'))`)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-ba-void/bump-all", "kds-device-token", "idem-ba-void")
	if w.Code != http.StatusConflict {
		t.Fatalf("expected 409, got %d body=%s", w.Code, w.Body.String())
	}
}
