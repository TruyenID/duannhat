package service

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

// PullStaff hits /api/v1/workstation/staff and replace-alls the local
// `staff` table. Lock down both the happy path and the empty-list case
// (which should leave the table empty, NOT preserve stale rows like
// PullPaymentMethods does — staff list is small enough to trust an
// authoritative wipe).
func TestPullStaff_ReplacesAtomically(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[
			{"id":"u1","name":"Alice","email":"a@x","avatar_url":""},
			{"id":"u2","name":"Bob","email":"b@x","avatar_url":""}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	// Pre-seed a stale row that PullStaff must wipe.
	db.Exec(`INSERT INTO staff (id, full_name) VALUES ('stale','Old')`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullStaff(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var n int
	db.QueryRow("SELECT COUNT(*) FROM staff").Scan(&n)
	if n != 2 {
		t.Errorf("expected 2 rows after pull, got %d", n)
	}

	db.QueryRow("SELECT COUNT(*) FROM staff WHERE id = 'stale'").Scan(&n)
	if n != 0 {
		t.Errorf("stale row should be wiped, found %d", n)
	}
}

func TestPullStaff_EmptyListWipesTable(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":[]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	db.Exec(`INSERT INTO staff (id, full_name) VALUES ('s1','x')`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullStaff(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var n int
	db.QueryRow("SELECT COUNT(*) FROM staff").Scan(&n)
	if n != 0 {
		t.Errorf("empty list should wipe table, got %d rows", n)
	}
}

// pullSlow must fire PullStaff alongside the rest of the pos replicas
// so the 5 s tick covers it.
func TestPullSlow_IncludesStaff(t *testing.T) {
	var hitStaff bool
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/v1/workstation/staff":
			hitStaff = true
			w.Write([]byte(`{"data":[]}`))
		default:
			w.Write([]byte(`{"data":[]}`))
		}
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	p.pullSlow()

	if !hitStaff {
		t.Errorf("pullSlow did not call /workstation/staff")
	}
}
