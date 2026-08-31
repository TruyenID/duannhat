package service

import (
	"context"
	"database/sql"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Plan-044 R2 (T-R2.D2.1) — the gap-window lower bound (prev_end) is the most recent
// terminal (settled/abandoned) session's closed_at. PullTillSessions pulls only the
// ACTIVE feed (/till-sessions/active → open/closing), so a locally-settled session is
// NEVER in the Cloud response. The upsert is ON CONFLICT(id) DO UPDATE (no replace-all
// / DELETE), so that settled row must survive a pull cycle — otherwise the next shift's
// gap-preview loses its lower bound and re-offers the entire prior day as "gap".
func TestPullTillSessions_RetainsSettledSessionForGapWindow(t *testing.T) {
	// Cloud's active feed reports ONE open session — NOT the settled one.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":[{
			"id":"sess-open","session_code":"S002","status":"open",
			"business_date":"2026-07-16","default_currency_code":"JPY",
			"opening_float_amount":1000,"opened_at":"2026-07-16T09:00:00Z",
			"till_id":"till-1","branch_id":"b1"
		}]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)

	// A prior shift, settled locally at 11:00 (the gap-window lower bound).
	mustExecPuller(t, db, `INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('sess-settled','S001','settled','2026-07-15','JPY',0,'2026-07-15T09:00:00Z','2026-07-15T11:00:00Z','till-1','b1')`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	if err := p.PullTillSessions(context.Background()); err != nil {
		t.Fatalf("PullTillSessions: %v", err)
	}

	// The settled session must still exist (untouched by the active-feed upsert).
	var status, closedAt string
	err := db.QueryRow(`SELECT status, COALESCE(closed_at,'') FROM till_sessions WHERE id = 'sess-settled'`).Scan(&status, &closedAt)
	if err == sql.ErrNoRows {
		t.Fatal("settled session was wiped by PullTillSessions — gap window lost its lower bound")
	}
	if err != nil {
		t.Fatalf("query settled: %v", err)
	}
	if status != "settled" || closedAt != "2026-07-15T11:00:00Z" {
		t.Errorf("settled session mutated: status=%q closed_at=%q", status, closedAt)
	}

	// The freshly-pulled open session must also be present.
	var openStatus string
	if err := db.QueryRow(`SELECT status FROM till_sessions WHERE id = 'sess-open'`).Scan(&openStatus); err != nil {
		t.Fatalf("open session not upserted: %v", err)
	}

	// prev_end still resolves to the settled session's close (gap-preview's query).
	var prevEnd string
	if err := db.QueryRow(`
		SELECT closed_at FROM till_sessions
		WHERE status IN ('settled','abandoned') AND closed_at IS NOT NULL AND closed_at != ''
		ORDER BY substr(closed_at,1,19) DESC LIMIT 1`).Scan(&prevEnd); err != nil {
		t.Fatalf("prev_end no longer resolves after pull: %v", err)
	}
	if prevEnd != "2026-07-15T11:00:00Z" {
		t.Errorf("prev_end = %q, want 2026-07-15T11:00:00Z", prevEnd)
	}
}
