package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// POST /api/v1/pos/tables/{id}/status updates the local mirror AND enqueues a
// table.status sync op (so the change reaches Cloud immediately in LAN mode).
func TestHandleLocalPosTableStatus_UpdatesMirrorAndEnqueues(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")
	srv.hub = NewHub()
	if _, err := db.Exec(`INSERT INTO tables (id, name, status) VALUES ('t1','B-01','free')`); err != nil {
		t.Fatal(err)
	}

	req := httptest.NewRequest("POST", "/api/v1/pos/tables/t1/status", strings.NewReader(`{"status":"cleaning"}`))
	req.SetPathValue("table", "t1")
	w := httptest.NewRecorder()
	srv.handleLocalPosTableStatus(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d: %s", w.Code, w.Body.String())
	}

	var status string
	if err := db.QueryRow("SELECT status FROM tables WHERE id='t1'").Scan(&status); err != nil {
		t.Fatal(err)
	}
	if status != "cleaning" {
		t.Fatalf("mirror status want cleaning, got %q", status)
	}

	var n int
	if err := db.QueryRow(`SELECT COUNT(*) FROM sync_queue
		WHERE entity_type='table' AND operation='status' AND entity_id='t1'`).Scan(&n); err != nil {
		t.Fatal(err)
	}
	if n != 1 {
		t.Fatalf("want 1 table.status sync op enqueued, got %d", n)
	}

	if !strings.Contains(w.Body.String(), `"status":"cleaning"`) {
		t.Fatalf("response must echo the new status: %s", w.Body.String())
	}
}

// An invalid status is rejected (422) and neither the mirror nor the queue change.
func TestHandleLocalPosTableStatus_RejectsInvalid(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")
	srv.hub = NewHub()
	if _, err := db.Exec(`INSERT INTO tables (id, name, status) VALUES ('t1','B-01','free')`); err != nil {
		t.Fatal(err)
	}

	req := httptest.NewRequest("POST", "/api/v1/pos/tables/t1/status", strings.NewReader(`{"status":"bogus"}`))
	req.SetPathValue("table", "t1")
	w := httptest.NewRecorder()
	srv.handleLocalPosTableStatus(w, req)

	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("want 422, got %d", w.Code)
	}
	var status string
	_ = db.QueryRow("SELECT status FROM tables WHERE id='t1'").Scan(&status)
	if status != "free" {
		t.Fatalf("status must be unchanged, got %q", status)
	}
	var n int
	_ = db.QueryRow(`SELECT COUNT(*) FROM sync_queue WHERE entity_id='t1'`).Scan(&n)
	if n != 0 {
		t.Fatalf("no sync op should be enqueued on reject, got %d", n)
	}
}

// An unknown table id → 404.
func TestHandleLocalPosTableStatus_404OnUnknown(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://cloud.invalid")
	srv.hub = NewHub()

	req := httptest.NewRequest("POST", "/api/v1/pos/tables/nope/status", strings.NewReader(`{"status":"cleaning"}`))
	req.SetPathValue("table", "nope")
	w := httptest.NewRecorder()
	srv.handleLocalPosTableStatus(w, req)

	if w.Code != http.StatusNotFound {
		t.Fatalf("want 404, got %d", w.Code)
	}
}
