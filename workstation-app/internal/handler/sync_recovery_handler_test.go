package handler

import (
	"net/http"
	"net/http/httptest"
	"strconv"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
)

// newRecoveryServer builds a Server wired to a real (empty) sync engine + DB for
// HTTP-handler-level tests of the plan-042 recovery endpoints.
func newRecoveryServer(t *testing.T) (*Server, *store.DB, *service.SyncEngine) {
	t.Helper()
	db := newTestDB(t)
	e := service.NewSyncEngine(db, "http://127.0.0.1:0", nil)
	return &Server{db: db, sync: e}, db, e
}

func deadLetteredRow(t *testing.T, db *store.DB, e *service.SyncEngine, entityType, entityID string) int {
	t.Helper()
	if err := e.Enqueue(entityType, entityID, "create", map[string]any{}, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}
	var id int
	if err := db.QueryRow("SELECT id FROM sync_queue WHERE entity_id = ? ORDER BY id DESC LIMIT 1", entityID).Scan(&id); err != nil {
		t.Fatalf("id: %v", err)
	}
	db.Exec("UPDATE sync_queue SET dead_lettered_at = datetime('now'), dead_letter_reason = 'cloud_422_entity_missing' WHERE id = ?", id)
	return id
}

func TestHandleSyncDiscard_StatusCodes(t *testing.T) {
	s, db, e := newRecoveryServer(t)
	id := deadLetteredRow(t, db, e, "order", "o1")

	// happy → 200
	req := httptest.NewRequest(http.MethodPost, "/api/sync/1/discard", nil)
	req.SetPathValue("id", strconv.Itoa(id))
	w := httptest.NewRecorder()
	s.handleSyncDiscard(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("discard happy want 200, got %d (%s)", w.Code, w.Body.String())
	}

	// second discard on the now-resolved row → 409
	req2 := httptest.NewRequest(http.MethodPost, "/api/sync/1/discard", nil)
	req2.SetPathValue("id", strconv.Itoa(id))
	w2 := httptest.NewRecorder()
	s.handleSyncDiscard(w2, req2)
	if w2.Code != http.StatusConflict {
		t.Fatalf("re-discard want 409, got %d", w2.Code)
	}

	// non-existent id → 404
	req3 := httptest.NewRequest(http.MethodPost, "/api/sync/99999/discard", nil)
	req3.SetPathValue("id", "99999")
	w3 := httptest.NewRecorder()
	s.handleSyncDiscard(w3, req3)
	if w3.Code != http.StatusNotFound {
		t.Fatalf("discard unknown want 404, got %d", w3.Code)
	}

	// non-numeric id → 400
	req4 := httptest.NewRequest(http.MethodPost, "/api/sync/abc/discard", nil)
	req4.SetPathValue("id", "abc")
	w4 := httptest.NewRecorder()
	s.handleSyncDiscard(w4, req4)
	if w4.Code != http.StatusBadRequest {
		t.Fatalf("discard bad id want 400, got %d", w4.Code)
	}
}

func TestHandleSyncReResolve_StatusCodes(t *testing.T) {
	s, db, e := newRecoveryServer(t)
	id := deadLetteredRow(t, db, e, "order", "o1")

	req := httptest.NewRequest(http.MethodPost, "/api/sync/1/re-resolve", nil)
	req.SetPathValue("id", strconv.Itoa(id))
	w := httptest.NewRecorder()
	s.handleSyncReResolve(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("re-resolve happy want 200, got %d (%s)", w.Code, w.Body.String())
	}
	// row is active again → second re-resolve is 409
	req2 := httptest.NewRequest(http.MethodPost, "/api/sync/1/re-resolve", nil)
	req2.SetPathValue("id", strconv.Itoa(id))
	w2 := httptest.NewRecorder()
	s.handleSyncReResolve(w2, req2)
	if w2.Code != http.StatusConflict {
		t.Fatalf("re-resolve of active row want 409, got %d", w2.Code)
	}
}

func TestHandleSyncRecoverOrder_NotFound(t *testing.T) {
	s, _, _ := newRecoveryServer(t)
	req := httptest.NewRequest(http.MethodPost, "/api/sync/orders/nope/recover", nil)
	req.SetPathValue("orderId", "nope")
	w := httptest.NewRecorder()
	s.handleSyncRecoverOrder(w, req)
	if w.Code != http.StatusNotFound {
		t.Fatalf("recover unknown order want 404, got %d", w.Code)
	}
}

// The recovery endpoints are wrapped in localOnly — a non-loopback (LAN) caller
// is rejected before the handler runs; a loopback caller passes through.
func TestRecoveryEndpointsAreLoopbackOnly(t *testing.T) {
	s, db, e := newRecoveryServer(t)
	id := deadLetteredRow(t, db, e, "order", "o1")
	h := localOnly(http.HandlerFunc(s.handleSyncDiscard))

	// Non-loopback LAN IP → 403, handler never runs (row stays dead-lettered).
	req := httptest.NewRequest(http.MethodPost, "/api/sync/1/discard", nil)
	req.SetPathValue("id", strconv.Itoa(id))
	req.RemoteAddr = "192.168.1.50:5555"
	w := httptest.NewRecorder()
	h.ServeHTTP(w, req)
	if w.Code != http.StatusForbidden {
		t.Fatalf("non-loopback caller should be 403, got %d", w.Code)
	}
	var dead *string
	db.QueryRow("SELECT dead_lettered_at FROM sync_queue WHERE id = ?", id).Scan(&dead)
	if dead == nil {
		t.Fatal("handler must NOT have run for a rejected non-loopback request")
	}

	// Loopback → reaches the handler (200).
	req2 := httptest.NewRequest(http.MethodPost, "/api/sync/1/discard", nil)
	req2.SetPathValue("id", strconv.Itoa(id))
	req2.RemoteAddr = "127.0.0.1:5555"
	w2 := httptest.NewRecorder()
	h.ServeHTTP(w2, req2)
	if w2.Code != http.StatusOK {
		t.Fatalf("loopback caller should reach handler (200), got %d", w2.Code)
	}
}
