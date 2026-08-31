package service

import (
	"context"
	"database/sql"
	"net/http"
	"net/http/httptest"
	"testing"
)

// PullTill must preserve the locally-set current_session_id when Cloud
// still reports null. Race: cashier opens shift on LAN → workstation
// writes till_sessions row + sets tills.current_session_id + enqueues
// sync UP. The next PullTill tick may fire before the sync UP reaches
// Cloud, so Cloud's view still has current_session_id = null. Without
// preservation the pointer gets wiped and pos-web's /pos/till/current
// would render "no shift" — prompting the cashier to open a second
// shift on top of the unsynced first one.
func TestPullTill_PreservesLocalSessionWhenCloudLags(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{
			"id":"till-1","branch_id":"b1","code":"MAIN",
			"default_currency_code":"JPY",
			"variance_tolerance_amount":"100.00",
			"current_session_id":null
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)

	// Seed: a LAN-opened, not-yet-synced session + tills row already
	// pointing at it. This mirrors the on-disk state right after
	// handleLocalPosTillOpen returns.
	mustExecPuller(t, db, `INSERT INTO tills (id, branch_id, code, default_currency_code, variance_tolerance_amount, current_session_id, local_synced_at) VALUES ('till-1','b1','MAIN','JPY',100,'sess-local',datetime('now'))`)
	mustExecPuller(t, db, `INSERT INTO till_sessions (id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, till_id, branch_id) VALUES ('sess-local','S001','open','2026-06-15','JPY',1000,'2026-06-15T09:00:00Z','till-1','b1')`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	if err := p.PullTill(context.Background()); err != nil {
		t.Fatalf("PullTill: %v", err)
	}

	var cur sql.NullString
	db.QueryRow(`SELECT current_session_id FROM tills WHERE id = 'till-1'`).Scan(&cur)
	if !cur.Valid || cur.String != "sess-local" {
		t.Errorf("expected current_session_id preserved as 'sess-local', got %q (valid=%v)", cur.String, cur.Valid)
	}
}

// Once the local session is acked (synced_at NOT NULL) or transitions to
// a terminal state, Cloud's null view wins — that's the legitimate
// "shift was closed/abandoned on Cloud" path. Without this branch a
// stale local row would pin current_session_id forever.
func TestPullTill_TrustsCloudWhenLocalSessionAcked(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{
			"id":"till-1","branch_id":"b1","code":"MAIN",
			"default_currency_code":"JPY",
			"variance_tolerance_amount":"100.00",
			"current_session_id":null
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	mustExecPuller(t, db, `INSERT INTO tills (id, branch_id, code, default_currency_code, variance_tolerance_amount, current_session_id, local_synced_at) VALUES ('till-1','b1','MAIN','JPY',100,'sess-old',datetime('now'))`)
	// Acked + settled — Cloud's null is authoritative now.
	mustExecPuller(t, db, `INSERT INTO till_sessions (id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, till_id, branch_id, synced_at) VALUES ('sess-old','S001','settled','2026-06-14','JPY',1000,'2026-06-14T09:00:00Z','till-1','b1',datetime('now'))`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	if err := p.PullTill(context.Background()); err != nil {
		t.Fatalf("PullTill: %v", err)
	}

	var cur sql.NullString
	db.QueryRow(`SELECT current_session_id FROM tills WHERE id = 'till-1'`).Scan(&cur)
	if cur.Valid {
		t.Errorf("expected current_session_id cleared by Cloud, got %q", cur.String)
	}
}

// PullTillSessions upserts Cloud-originated active sessions so a shift
// opened on Cloud becomes visible to LAN mode's /pos/till/current
// handler. Without this puller the till.current_session_id pointer
// would land first but the underlying till_sessions row stays missing,
// loadSession returns nil, and pos-web shows "no shift open" after the
// cashier toggles back to LAN.
func TestPullTillSessions_UpsertsCloudActiveSessions(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":[
			{"id":"sess-cloud","session_code":"C001","status":"open",
			 "business_date":"2026-06-15","default_currency_code":"JPY",
			 "opening_float_amount":2000,"opening_note":"hello",
			 "opened_by_id":"u1","opener_name":"Alice",
			 "opened_at":"2026-06-15T08:00:00Z",
			 "closed_at":null,"closing_note":null,"abandon_reason":null,
			 "till_id":"till-1","branch_id":"b1"}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	if err := p.PullTillSessions(context.Background()); err != nil {
		t.Fatalf("PullTillSessions: %v", err)
	}

	var status, cloudID, name string
	var floatAmt float64
	err := db.QueryRow(`SELECT status, cloud_id, COALESCE(opener_name,''), opening_float_amount FROM till_sessions WHERE id = 'sess-cloud'`).Scan(&status, &cloudID, &name, &floatAmt)
	if err != nil {
		t.Fatalf("expected sess-cloud upserted: %v", err)
	}
	if status != "open" || cloudID != "sess-cloud" || name != "Alice" || floatAmt != 2000 {
		t.Errorf("mismatch: status=%s cloud_id=%s name=%s float=%v", status, cloudID, name, floatAmt)
	}

	var synced sql.NullString
	db.QueryRow(`SELECT synced_at FROM till_sessions WHERE id = 'sess-cloud'`).Scan(&synced)
	if !synced.Valid {
		t.Errorf("synced_at must be stamped for Cloud-originated rows")
	}
}

// LAN-owned local-only sessions (different id) must survive a Cloud
// pull — the cashier's own open shift can't be wiped just because Cloud
// hasn't seen the sync UP yet.
func TestPullTillSessions_PreservesLocalOnlyRows(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":[]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	mustExecPuller(t, db, `INSERT INTO till_sessions (id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, till_id, branch_id) VALUES ('sess-local','L001','open','2026-06-15','JPY',500,'2026-06-15T07:00:00Z','till-1','b1')`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	if err := p.PullTillSessions(context.Background()); err != nil {
		t.Fatalf("PullTillSessions: %v", err)
	}

	var count int
	db.QueryRow(`SELECT COUNT(*) FROM till_sessions WHERE id = 'sess-local'`).Scan(&count)
	if count != 1 {
		t.Errorf("local-only LAN session must survive pull DOWN, got count=%d", count)
	}
}

func mustExecPuller(t *testing.T, db sqlExecer, q string, args ...any) {
	t.Helper()
	if _, err := db.Exec(q, args...); err != nil {
		t.Fatalf("exec %s: %v", q, err)
	}
}

type sqlExecer interface {
	Exec(q string, args ...any) (sql.Result, error)
}

// A locally-SETTLED shift must never be dragged back to open by the active feed.
//
// The workstation owns the shift lifecycle: it settles in SQLite, then syncs UP.
// This feed returns Cloud's open/closing sessions, so in the window between the
// local settle and its sync-UP landing, Cloud still reports the shift as open.
// The upsert used to overwrite status / closed_at / settlement_kind with those
// stale values while leaving counted_cash + settlement_snapshot behind — a
// half-settled row no later pull could repair, because once Cloud DOES settle
// the session it leaves the active feed for good.
//
// The damage was silent and total: chain continuation reads the till's most
// recent TERMINAL session, so with every settle reverted to open no chain ever
// continued — each shift opened a fresh chain at sequence 1 and the final close
// aggregated exactly one member.
func TestPullTillSessions_NeverReopensALocallySettledShift(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Cloud has not yet received the sync-UP, so it still lists the shift
		// as open — the exact race that corrupted the local row.
		w.Write([]byte(`{"data":[
			{"id":"sess-settled","session_code":"C009","status":"open",
			 "business_date":"2026-07-22","default_currency_code":"JPY",
			 "opening_float_amount":10000,"opening_note":null,
			 "opened_by_id":null,"opener_name":null,
			 "opened_at":"2026-07-22T09:57:11Z",
			 "closed_at":null,"closing_note":null,"abandon_reason":null,
			 "till_id":"till-1","branch_id":"b1",
			 "chain_id":null,"chain_sequence":1,"settlement_kind":null}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	if _, err := db.Exec(`
		INSERT INTO till_sessions
			(id, cloud_id, session_code, status, business_date, default_currency_code,
			 opening_float_amount, opened_at, closed_at, counted_cash, cash_variance,
			 till_id, branch_id, chain_id, chain_sequence, settlement_kind,
			 settlement_snapshot)
		VALUES ('sess-settled','sess-settled','C009','settled','2026-07-22','JPY',
			10000,'2026-07-22T09:57:11Z','2026-07-22T09:57:46Z',3946,0,
			'till-1','b1','chain-A',1,'handover','{"cash":{"counted":3946}}')`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	if err := p.PullTillSessions(context.Background()); err != nil {
		t.Fatalf("PullTillSessions: %v", err)
	}

	var status, kind, chainID string
	var closedAt sql.NullString
	if err := db.QueryRow(`
		SELECT status, COALESCE(settlement_kind,''), COALESCE(chain_id,''), closed_at
		FROM till_sessions WHERE id = 'sess-settled'`).
		Scan(&status, &kind, &chainID, &closedAt); err != nil {
		t.Fatalf("read back: %v", err)
	}

	if status != "settled" {
		t.Errorf("status = %q, want settled — the pull reopened a settled shift", status)
	}
	if kind != "handover" {
		t.Errorf("settlement_kind = %q, want handover — without it no chain can continue", kind)
	}
	if chainID != "chain-A" {
		t.Errorf("chain_id = %q, want chain-A", chainID)
	}
	if !closedAt.Valid || closedAt.String == "" {
		t.Error("closed_at was wiped")
	}
}

// The guard must not block the legitimate direction: a shift still open locally
// that Cloud has moved on (a manager force-abandon / the stale-shift reaper)
// still has to land.
func TestPullTillSessions_StillAcceptsCloudTerminalOnAnOpenShift(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":[
			{"id":"sess-open","session_code":"C010","status":"closing",
			 "business_date":"2026-07-22","default_currency_code":"JPY",
			 "opening_float_amount":5000,"opening_note":null,
			 "opened_by_id":null,"opener_name":"Bob",
			 "opened_at":"2026-07-22T09:00:00Z",
			 "closed_at":null,"closing_note":null,"abandon_reason":null,
			 "till_id":"till-1","branch_id":"b1"}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	if _, err := db.Exec(`
		INSERT INTO till_sessions
			(id, cloud_id, session_code, status, business_date, default_currency_code,
			 opening_float_amount, opened_at, till_id, branch_id)
		VALUES ('sess-open','sess-open','C010','open','2026-07-22','JPY',
			5000,'2026-07-22T09:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	if err := p.PullTillSessions(context.Background()); err != nil {
		t.Fatalf("PullTillSessions: %v", err)
	}

	var status, opener string
	db.QueryRow(`SELECT status, COALESCE(opener_name,'') FROM till_sessions WHERE id='sess-open'`).
		Scan(&status, &opener)
	if status != "closing" {
		t.Errorf("status = %q, want closing — a locally-open shift must still track Cloud", status)
	}
	if opener != "Bob" {
		t.Errorf("opener_name = %q, want Bob", opener)
	}
}
