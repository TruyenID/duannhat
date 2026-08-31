package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Plan-044 R2 — GET /api/v1/pos/till/gap-preview (local replica).

func TestGapPreview_ListsGapPaymentsAfterPrevClose(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	// A terminal (settled) session closed at 11:00.
	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('prev','S001','settled','2026-07-15','JPY',0,'2026-07-15T09:00:00Z','2026-07-15T11:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed session: %v", err)
	}

	// A cash gap payment AFTER the close (NULL attribution) — must be listed.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('pay1','o1','cash',800,'confirmed','2026-07-15T11:30:00Z')`); err != nil {
		t.Fatalf("seed gap payment: %v", err)
	}
	// A payment BEFORE the close (inside the prior shift) — must be excluded.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('pay0','o1','cash',500,'confirmed','2026-07-15T10:00:00Z')`); err != nil {
		t.Fatalf("seed pre-close payment: %v", err)
	}
	// An already-attributed gap payment — must be excluded (till_session_id NOT NULL).
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at, till_session_id)
		VALUES ('pay2','o1','card',300,'confirmed','2026-07-15T11:40:00Z','some-session')`); err != nil {
		t.Fatalf("seed stamped payment: %v", err)
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/gap-preview", nil)
	s.handleLocalPosTillGapPreview(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d, body %s", w.Code, w.Body.String())
	}

	var resp struct {
		Data struct {
			PreviousSession map[string]any `json:"previous_session"`
			Totals          struct {
				Count      int     `json:"count"`
				CashAmount float64 `json:"cash_amount"`
			} `json:"totals"`
			Payments []struct {
				ID     string  `json:"id"`
				IsCash bool    `json:"is_cash"`
				Amount float64 `json:"amount"`
			} `json:"payments"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v — body %s", err, w.Body.String())
	}

	if resp.Data.Totals.Count != 1 {
		t.Fatalf("count = %d, want 1 (only the after-close NULL payment)", resp.Data.Totals.Count)
	}
	if len(resp.Data.Payments) != 1 || resp.Data.Payments[0].ID != "pay1" || !resp.Data.Payments[0].IsCash {
		t.Fatalf("payment mismatch: %+v", resp.Data.Payments)
	}
	if resp.Data.Totals.CashAmount != 800 {
		t.Fatalf("cash_amount = %v, want 800", resp.Data.Totals.CashAmount)
	}
	if resp.Data.PreviousSession == nil || resp.Data.PreviousSession["id"] != "prev" {
		t.Fatalf("previous_session = %+v, want id=prev", resp.Data.PreviousSession)
	}
}

func TestGapPreview_EmptyWhenNoPriorSession(t *testing.T) {
	s := newFireTestServer(t)

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/gap-preview", nil)
	s.handleLocalPosTillGapPreview(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d", w.Code)
	}
	var resp struct {
		Data struct {
			PreviousSession *map[string]any `json:"previous_session"`
			Totals          struct {
				Count int `json:"count"`
			} `json:"totals"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}
	if resp.Data.PreviousSession != nil {
		t.Errorf("previous_session should be nil when no prior terminal session")
	}
	if resp.Data.Totals.Count != 0 {
		t.Errorf("count = %d, want 0", resp.Data.Totals.Count)
	}
}

// #2730 — the same space-vs-T defect #2722 fixed for unresolved-orders lived on
// in gap-preview: the SQL compared raw `substr(p.created_at,1,19)` against an
// already-normalized bound. A payment stored as a DATETIME with a space, taken
// AFTER a close stamped with T, loses at character 11 (' ' 0x20 < 'T' 0x54) and
// drops out of the gap list — the cashier is never offered the chance to
// attribute it, so real money silently misses the till.
func TestGapPreview_IncludesSpaceFormattedPaymentAfterTFormattedClose(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('prev','S001','settled','2026-07-15','JPY',0,'2026-07-15T09:00:00Z','2026-07-15T11:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	// AFTER the close, space format — MUST be listed.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('space-after','o1','cash',900,'confirmed','2026-07-15 11:30:00')`); err != nil {
		t.Fatalf("seed space payment: %v", err)
	}
	// BEFORE the close, space format — must stay excluded (proves the bound is
	// still enforced and the fix did not simply pass everything through).
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('space-before','o1','cash',400,'confirmed','2026-07-15 10:00:00')`); err != nil {
		t.Fatalf("seed pre-close space payment: %v", err)
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/gap-preview", nil)
	s.handleLocalPosTillGapPreview(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d, body %s", w.Code, w.Body.String())
	}
	var resp struct {
		Data struct {
			Payments []struct {
				ID string `json:"id"`
			} `json:"payments"`
			Totals struct {
				Count      int     `json:"count"`
				CashAmount float64 `json:"cash_amount"`
			} `json:"totals"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v — body %s", err, w.Body.String())
	}
	if resp.Data.Totals.Count != 1 {
		t.Fatalf("count = %d, want 1 (only space-after), payments=%+v", resp.Data.Totals.Count, resp.Data.Payments)
	}
	if resp.Data.Payments[0].ID != "space-after" {
		t.Fatalf("listed %q, want space-after", resp.Data.Payments[0].ID)
	}
	if resp.Data.Totals.CashAmount != 900 {
		t.Fatalf("cash_amount = %v, want 900", resp.Data.Totals.CashAmount)
	}
}

// #2730 — `ORDER BY substr(closed_at,1,19) DESC` picked the previous session
// lexically, so a T-formatted close sorted above every space-formatted close of
// the same day regardless of the actual clock. PullTillSessions upserts Cloud
// rows next to local-born ones, so mixed formats are reachable in one table.
func TestGapPreview_PicksLatestCloseByInstantNotByStringForm(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	// The genuinely LATEST close (14:00) is space-formatted.
	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('late','S002','settled','2026-07-15','JPY',0,'2026-07-15 12:00:00','2026-07-15 14:00:00','till-1','b1')`); err != nil {
		t.Fatalf("seed late session: %v", err)
	}
	// An EARLIER close (11:00) stamped with T — lexically it sorts higher.
	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('early','S001','settled','2026-07-15','JPY',0,'2026-07-15T09:00:00Z','2026-07-15T11:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed early session: %v", err)
	}
	// Payment between the two closes: inside the WRONG window, outside the right one.
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('between','o1','cash',700,'confirmed','2026-07-15T12:30:00Z')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/gap-preview", nil)
	s.handleLocalPosTillGapPreview(w, req)

	var resp struct {
		Data struct {
			PreviousSession map[string]any `json:"previous_session"`
			Totals          struct {
				Count int `json:"count"`
			} `json:"totals"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v — body %s", err, w.Body.String())
	}
	if resp.Data.PreviousSession == nil || resp.Data.PreviousSession["id"] != "late" {
		t.Fatalf("previous_session = %+v, want the 14:00 close (id=late)", resp.Data.PreviousSession)
	}
	if resp.Data.Totals.Count != 0 {
		t.Fatalf("count = %d, want 0 — the 12:30 payment predates the real previous close", resp.Data.Totals.Count)
	}
}
