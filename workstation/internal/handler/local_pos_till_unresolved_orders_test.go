package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// #2696/#2716 — GET /api/v1/pos/till/unresolved-orders (local replica).

type unresolvedResp struct {
	Data struct {
		PreviousSession map[string]any `json:"previous_session"`
		Orders          []struct {
			ID                string  `json:"id"`
			OrderCode         *string `json:"order_code"`
			Status            string  `json:"status"`
			TotalAmount       float64 `json:"total_amount"`
			PaidAmount        float64 `json:"paid_amount"`
			OutstandingAmount float64 `json:"outstanding_amount"`
			TableReleased     bool    `json:"table_released"`
		} `json:"orders"`
		Totals struct {
			Count             int     `json:"count"`
			OutstandingAmount float64 `json:"outstanding_amount"`
		} `json:"totals"`
	} `json:"data"`
}

func TestUnresolvedOrders_ListsPayingCreatedBeforePrevClose(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('prev','S001','settled','2026-07-15','JPY',0,'2026-07-15T09:00:00Z','2026-07-15T11:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed session: %v", err)
	}

	// Stuck bill: paying, opened during the previous shift, short ¥1000.
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, status, total_amount, table_id, created_at, opened_at)
		VALUES ('stuck','ORD-0217','paying',4720,'t-a5','2026-07-15T09:30:00Z','2026-07-15T09:30:00Z')`); err != nil {
		t.Fatalf("seed stuck order: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('pay1','stuck','cash',3720,'succeeded','2026-07-15T10:00:00Z')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}

	// Closed order from the same window — must not appear.
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, status, total_amount, created_at, opened_at)
		VALUES ('done','ORD-DONE','closed',800,'2026-07-15T09:45:00Z','2026-07-15T09:45:00Z')`); err != nil {
		t.Fatalf("seed closed order: %v", err)
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/unresolved-orders", nil)
	s.handleLocalPosTillUnresolvedOrders(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d, body %s", w.Code, w.Body.String())
	}
	var resp unresolvedResp
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v — body %s", err, w.Body.String())
	}
	if resp.Data.Totals.Count != 1 {
		t.Fatalf("count = %d, want 1, orders=%+v", resp.Data.Totals.Count, resp.Data.Orders)
	}
	o := resp.Data.Orders[0]
	if o.ID != "stuck" || o.Status != "paying" || o.OutstandingAmount != 1000 || o.PaidAmount != 3720 {
		t.Fatalf("order mismatch: %+v", o)
	}
	if o.TableReleased {
		t.Fatalf("table still held, table_released should be false")
	}
	if resp.Data.PreviousSession == nil || resp.Data.PreviousSession["id"] != "prev" {
		t.Fatalf("previous_session = %+v", resp.Data.PreviousSession)
	}
}

func TestUnresolvedOrders_ExcludesOrderCreatedAfterClose_SpaceVsT(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	// Close stamped with T (ISO). Order created AFTER with a space datetime.
	// Lexical substr would include it (` ` < `T`); normalizeInstant must not.
	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('prev','S001','settled','2026-07-15','JPY',0,'2026-07-15T09:00:00Z','2026-07-15T11:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, status, total_amount, created_at, opened_at)
		VALUES ('after','ORD-AFTER','checkout',700,'2026-07-15 12:00:00','2026-07-15 12:00:00')`); err != nil {
		t.Fatalf("seed after-close order: %v", err)
	}
	// Control: before-close, also space-formatted, must still appear.
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, status, total_amount, created_at, opened_at)
		VALUES ('before','ORD-BEFORE','paying',500,'2026-07-15 10:00:00','2026-07-15 10:00:00')`); err != nil {
		t.Fatalf("seed before-close order: %v", err)
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/unresolved-orders", nil)
	s.handleLocalPosTillUnresolvedOrders(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d, body %s", w.Code, w.Body.String())
	}
	var resp unresolvedResp
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}
	if resp.Data.Totals.Count != 1 || resp.Data.Orders[0].ID != "before" {
		t.Fatalf("want only before-close order, got %+v", resp.Data.Orders)
	}
}

func TestUnresolvedOrders_TableReleasedWhenNoTable(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('prev','S001','settled','2026-07-15','JPY',0,'2026-07-15T09:00:00Z','2026-07-15T11:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, status, total_amount, table_id, created_at, opened_at)
		VALUES ('orphan','ORD-0191','checkout',700,NULL,'2026-07-15T09:00:00Z','2026-07-15T09:00:00Z')`); err != nil {
		t.Fatalf("seed orphan: %v", err)
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/unresolved-orders", nil)
	s.handleLocalPosTillUnresolvedOrders(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d, body %s", w.Code, w.Body.String())
	}
	var resp unresolvedResp
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}
	if len(resp.Data.Orders) != 1 || !resp.Data.Orders[0].TableReleased {
		t.Fatalf("orphan should set table_released, got %+v", resp.Data.Orders)
	}
}

func TestUnresolvedOrders_EmptyWhenNoPriorSession(t *testing.T) {
	s := newFireTestServer(t)

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/unresolved-orders", nil)
	s.handleLocalPosTillUnresolvedOrders(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d", w.Code)
	}
	var resp unresolvedResp
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

// #2736 — unresolved-orders picked its "previous session" with a lexical
// ORDER BY while gap-preview (fixed in #2730) picked it by instant. Two adjacent
// screens of ONE shift-open flow would name two different previous sessions the
// moment till_sessions carries mixed formats — and PullTillSessions upserts Cloud
// rows next to local-born ones, so mixed is reachable.
func TestUnresolvedOrders_PicksLatestCloseByInstantNotByStringForm(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	// Genuinely latest close (14:00) is space-formatted.
	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('late','S002','settled','2026-07-15','JPY',0,'2026-07-15 12:00:00','2026-07-15 14:00:00','till-1','b1')`); err != nil {
		t.Fatalf("seed late session: %v", err)
	}
	// Earlier close (11:00) stamped with T — lexically it sorts ABOVE the above.
	if _, err := db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, closed_at, till_id, branch_id)
		VALUES ('early','S001','settled','2026-07-15','JPY',0,'2026-07-15T09:00:00Z','2026-07-15T11:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed early session: %v", err)
	}
	// Order opened 12:30 — before the REAL previous close (14:00), after the wrong one.
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, status, total_amount, created_at, opened_at)
		VALUES ('mid','ORD-MID','paying',900,'2026-07-15T12:30:00Z','2026-07-15T12:30:00Z')`); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/till/unresolved-orders", nil)
	s.handleLocalPosTillUnresolvedOrders(w, req)

	var resp unresolvedResp
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v — body %s", err, w.Body.String())
	}
	if resp.Data.PreviousSession == nil || resp.Data.PreviousSession["id"] != "late" {
		t.Fatalf("previous_session = %+v, want the 14:00 close (id=late)", resp.Data.PreviousSession)
	}
	// With the right previous session the 12:30 order IS unresolved (before 14:00).
	if resp.Data.Totals.Count != 1 || resp.Data.Orders[0].ID != "mid" {
		t.Fatalf("want the 12:30 order listed against the 14:00 close, got %+v", resp.Data.Orders)
	}
}
