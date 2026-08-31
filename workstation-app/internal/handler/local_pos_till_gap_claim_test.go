package handler

import (
	"bytes"
	"database/sql"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Plan-044 R2 (T-R2.10) — at shift open, the cashier-confirmed close-gap payments
// are stamped to the new session locally, but ONLY the eligible subset (mirrors
// GET /pos/till/gap-preview): NULL attribution + status pending/confirmed + created
// in the window (prev_end, opened_at]. Cash is claimable (held separately). Rows out
// of window or already attributed are never touched.

func seedGapPayment(t *testing.T, db *sql.DB, id, method, status, createdAt string, sessionID *string) {
	t.Helper()
	if sessionID == nil {
		if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
			VALUES (?, 'o1', ?, 100, ?, ?)`, id, method, status, createdAt); err != nil {
			t.Fatalf("seed payment %s: %v", id, err)
		}
		return
	}
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at, till_session_id)
		VALUES (?, 'o1', ?, 100, ?, ?, ?)`, id, method, status, createdAt, *sessionID); err != nil {
		t.Fatalf("seed payment %s: %v", id, err)
	}
}

func tillSessionOf(t *testing.T, db *sql.DB, id string) string {
	t.Helper()
	var v sql.NullString
	if err := db.QueryRow(`SELECT till_session_id FROM payments WHERE id = ?`, id).Scan(&v); err != nil {
		t.Fatalf("read payment %s: %v", id, err)
	}
	if v.Valid {
		return v.String
	}
	return ""
}

func TestOpenSession_ClaimsEligibleGapPaymentsOnly(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	// Till with no open shift + a denomination for the opening float.
	if _, err := db.Exec(`INSERT INTO tills (id, branch_id, code, default_currency_code, variance_tolerance_amount, current_session_id)
		VALUES ('till-1','b1','MAIN','JPY',0,NULL)`); err != nil {
		t.Fatalf("seed till: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO denominations (id, currency_code, value, kind)
		VALUES ('d1000','JPY',1000,'note')`); err != nil {
		t.Fatalf("seed denomination: %v", err)
	}

	// Prior settled shift closed at 11:00 → gap window is (11:00, now].
	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('prev','S001','settled','2026-07-15','JPY',0,'2026-07-15T09:00:00Z','2026-07-15T11:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed prior session: %v", err)
	}

	// Eligible: NULL + confirmed + cash + in-window → claimable.
	seedGapPayment(t, db, "gapCash", "cash", "confirmed", "2026-07-15T11:30:00Z", nil)
	// Out of window (before prev close) → must stay NULL even though claimed.
	seedGapPayment(t, db, "preClose", "cash", "confirmed", "2026-07-15T10:00:00Z", nil)
	// Already attributed → must never be re-stamped.
	other := "other-session"
	seedGapPayment(t, db, "already", "card", "confirmed", "2026-07-15T11:40:00Z", &other)

	body, _ := json.Marshal(map[string]any{
		"opening_counts":               []map[string]any{{"denomination_id": "d1000", "quantity": 5}},
		"claimed_gap_payment_ids":      []string{"gapCash", "preClose", "already"},
		"gap_cash_held_separately_ack": true,
	})
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/pos/till/sessions", bytes.NewReader(body))
	s.handleLocalPosTillOpenSession(w, req)

	if w.Code != http.StatusCreated {
		t.Fatalf("status = %d, body %s", w.Code, w.Body.String())
	}
	var resp struct {
		Data struct {
			ID string `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v — %s", err, w.Body.String())
	}
	newSession := resp.Data.ID
	if newSession == "" {
		t.Fatal("no session id in response")
	}

	if got := tillSessionOf(t, db, "gapCash"); got != newSession {
		t.Errorf("gapCash till_session_id = %q, want new session %q", got, newSession)
	}
	if got := tillSessionOf(t, db, "preClose"); got != "" {
		t.Errorf("preClose till_session_id = %q, want NULL (out of window)", got)
	}
	if got := tillSessionOf(t, db, "already"); got != "other-session" {
		t.Errorf("already till_session_id = %q, want unchanged other-session", got)
	}
}

func TestOpenSession_NoClaimWhenListEmpty(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	if _, err := db.Exec(`INSERT INTO tills (id, branch_id, code, default_currency_code, variance_tolerance_amount, current_session_id)
		VALUES ('till-1','b1','MAIN','JPY',0,NULL)`); err != nil {
		t.Fatalf("seed till: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO denominations (id, currency_code, value, kind)
		VALUES ('d1000','JPY',1000,'note')`); err != nil {
		t.Fatalf("seed denomination: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('prev','S001','settled','2026-07-15','JPY',0,'2026-07-15T09:00:00Z','2026-07-15T11:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed prior session: %v", err)
	}
	// A gap payment exists but is NOT claimed → stays NULL.
	seedGapPayment(t, db, "gapCash", "cash", "confirmed", "2026-07-15T11:30:00Z", nil)

	body, _ := json.Marshal(map[string]any{
		"opening_counts": []map[string]any{{"denomination_id": "d1000", "quantity": 1}},
	})
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/pos/till/sessions", bytes.NewReader(body))
	s.handleLocalPosTillOpenSession(w, req)

	if w.Code != http.StatusCreated {
		t.Fatalf("status = %d, body %s", w.Code, w.Body.String())
	}
	if got := tillSessionOf(t, db, "gapCash"); got != "" {
		t.Errorf("unclaimed gap payment was stamped to %q, want NULL", got)
	}
}
