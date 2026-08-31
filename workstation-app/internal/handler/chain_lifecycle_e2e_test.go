package handler

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// End-to-end proof of the operator's five requirements for the shift chain,
// driven through the REAL handlers (open → pay → handover → … → final close):
//
//  1. a handover slip is numbered by its position in the chain (Ca 1, 2, 3…)
//  2. each handover slip covers ONLY that shift — its own open→handover window
//  3. the final close settles the last shift AND yields the chain aggregate
//  4. the chain report totals every shift from the first to the one being closed
//  5. after a final close the chain ends — the next open starts a fresh one
//
// This is the regression net for the bug that made all of it silently fail: the
// active-feed pull reopened locally-settled shifts, so no chain ever continued.
func TestChainLifecycle_EndToEnd(t *testing.T) {
	s := newFireTestServer(t)
	db := s.db.Conn()

	mustExec := func(q string, args ...any) {
		t.Helper()
		if _, err := db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v\n%s", err, q)
		}
	}
	mustExec(`INSERT INTO tills (id, branch_id, code, default_currency_code, variance_tolerance_amount, current_session_id)
		VALUES ('till-1','b1','MAIN','JPY',0,NULL)`)
	mustExec(`INSERT INTO denominations (id, currency_code, value, kind) VALUES ('d1000','JPY',1000,'note')`)
	mustExec(`INSERT INTO payment_methods (id, code, name) VALUES ('pm-cash','cash','現金')`)
	// A cashier who picked THEMSELVES at open: pos-web sends opened_by_id and
	// no opener_name, so the slip has to resolve the name through the staff
	// mirror (exactly what the shift-OPEN report already does).
	mustExec(`INSERT INTO staff (id, full_name) VALUES ('u-alice','Alice Tanaka')`)

	// ── helpers driving the real endpoints ──
	openShift := func() (id string, chainID string, seq int, continues bool) {
		t.Helper()
		body, _ := json.Marshal(map[string]any{
			"opening_counts": []map[string]any{{"denomination_id": "d1000", "quantity": 1}},
			"opened_by_id":   "u-alice",
		})
		w := httptest.NewRecorder()
		s.handleLocalPosTillOpenSession(w,
			httptest.NewRequest(http.MethodPost, "/api/v1/pos/till/sessions", bytes.NewReader(body)))
		if w.Code != http.StatusCreated {
			t.Fatalf("open: status %d — %s", w.Code, w.Body.String())
		}
		var resp struct {
			Data struct {
				ID             string `json:"id"`
				ChainID        string `json:"chain_id"`
				ChainSequence  any    `json:"chain_sequence"`
				ContinuesChain bool   `json:"continues_chain"`
			} `json:"data"`
		}
		if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
			t.Fatalf("open decode: %v — %s", err, w.Body.String())
		}
		// chain_sequence may arrive as a JSON number or string.
		switch v := resp.Data.ChainSequence.(type) {
		case float64:
			seq = int(v)
		case string:
			fmt.Sscanf(v, "%d", &seq)
		}
		return resp.Data.ID, resp.Data.ChainID, seq, resp.Data.ContinuesChain
	}

	// A paid order attributed to the shift, exactly as a LAN payment would be.
	sell := func(sessionID, orderID string, total, tax int) {
		t.Helper()
		mustExec(`INSERT INTO orders (id, order_code, status, opened_at, guest_count,
			subtotal, discount_amount, tax_amount, total_amount, created_at)
			VALUES (?,?, 'closed', datetime('now'), 1, ?, 0, ?, ?, datetime('now'))`,
			orderID, "WS-"+orderID, total-tax, tax, total)
		mustExec(`INSERT INTO order_items (id, customer_order_id, quantity, unit_price,
			subtotal, tax_rate, tax_amount, status)
			VALUES (?,?,1,?,?,10,?, 'served')`,
			"i-"+orderID, orderID, total-tax, total-tax, tax)
		mustExec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at, till_session_id)
			VALUES (?,?, 'cash', ?, 'confirmed', datetime('now'), ?)`,
			"p-"+orderID, orderID, total, sessionID)
	}

	settle := func(sessionID string, handover bool) {
		t.Helper()
		body, _ := json.Marshal(map[string]any{
			"closing_counts": []map[string]any{{"denomination_id": "d1000", "quantity": 1}},
			"tender_details": []map[string]any{},
		})
		w := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodPost, "/x", bytes.NewReader(body))
		req.SetPathValue("id", sessionID)
		if handover {
			s.handleLocalPosTillHandover(w, req)
		} else {
			s.handleLocalPosTillClose(w, req)
		}
		if w.Code != http.StatusOK && w.Code != http.StatusCreated {
			t.Fatalf("settle(handover=%v): status %d — %s", handover, w.Code, w.Body.String())
		}
	}

	// ── shift 1 ──
	s1, chain1, seq1, cont1 := openShift()
	if seq1 != 1 || cont1 {
		t.Fatalf("shift 1: seq=%d continues=%v, want 1/false", seq1, cont1)
	}
	sell(s1, "o1", 1100, 100)
	settle(s1, true)

	// THE RACE that broke this in production: the background pull runs before
	// the settle has synced UP, so Cloud still lists the shift as OPEN. It must
	// not drag the local row back — doing so wiped settlement_kind, and chain
	// continuation (which looks for the till's most recent TERMINAL session)
	// then started a brand-new chain on every open.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		fmt.Fprintf(w, `{"data":[{"id":%q,"session_code":"C1","status":"open",
			"business_date":"2026-07-22","default_currency_code":"JPY",
			"opening_float_amount":1000,"opening_note":null,
			"opened_by_id":null,"opener_name":null,
			"opened_at":"2026-07-22T09:00:00Z","closed_at":null,
			"closing_note":null,"abandon_reason":null,
			"till_id":"till-1","branch_id":"b1"}]}`, s1)
	}))
	defer cloud.Close()
	puller := service.NewSyncPuller(s.db, cloud.URL, func() string { return "WS" })
	if err := puller.PullTillSessions(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	var st, kind string
	db.QueryRow(`SELECT status, COALESCE(settlement_kind,'') FROM till_sessions WHERE id = ?`, s1).
		Scan(&st, &kind)
	if st != "settled" || kind != "handover" {
		t.Fatalf("the pull reopened shift 1: status=%q kind=%q — chain continuation is dead", st, kind)
	}

	// Requirement 1 + 2: the slip is numbered, and carries ONLY this shift.
	r1, err := s.buildShiftReport(s1)
	if err != nil {
		t.Fatalf("shift 1 report: %v", err)
	}
	if r1.ChainSequence != 1 {
		t.Errorf("shift 1 slip: ChainSequence = %d, want 1", r1.ChainSequence)
	}
	if r1.GrossSales != 1100 {
		t.Errorf("shift 1 slip: gross = %d, want 1100 (its own window only)", r1.GrossSales)
	}
	// 担当者 — resolved from opened_by_id via the staff mirror.
	if r1.Operator != "Alice Tanaka" {
		t.Errorf("shift 1 slip: Operator = %q, want %q", r1.Operator, "Alice Tanaka")
	}
	// 金種 — the closing count must reach the slip.
	if len(r1.Denominations) == 0 {
		t.Error("shift 1 slip: no 金種 rows")
	}

	// ── shift 2 — THE assertion the bug destroyed: the chain must continue ──
	s2, chain2, seq2, cont2 := openShift()
	if chain2 != chain1 {
		t.Fatalf("shift 2 started a NEW chain (%s != %s) — the chain did not continue", chain2, chain1)
	}
	if seq2 != 2 || !cont2 {
		t.Fatalf("shift 2: seq=%d continues=%v, want 2/true", seq2, cont2)
	}
	sell(s2, "o2", 2200, 200)
	settle(s2, true)

	r2, err := s.buildShiftReport(s2)
	if err != nil {
		t.Fatalf("shift 2 report: %v", err)
	}
	if r2.ChainSequence != 2 {
		t.Errorf("shift 2 slip: ChainSequence = %d, want 2", r2.ChainSequence)
	}
	if r2.GrossSales != 2200 {
		t.Errorf("shift 2 slip: gross = %d, want 2200 (NOT the chain total)", r2.GrossSales)
	}

	// ── shift 3 → final close ──
	s3, chain3, seq3, _ := openShift()
	if chain3 != chain1 || seq3 != 3 {
		t.Fatalf("shift 3: chain=%s seq=%d, want %s/3", chain3, seq3, chain1)
	}
	sell(s3, "o3", 3300, 300)
	settle(s3, false) // final close

	// Requirement 3: the final shift's own slip still describes only that shift.
	r3, err := s.buildShiftReport(s3)
	if err != nil {
		t.Fatalf("shift 3 report: %v", err)
	}
	if r3.ChainSequence != 3 || r3.GrossSales != 3300 {
		t.Errorf("shift 3 slip: seq=%d gross=%d, want 3/3300", r3.ChainSequence, r3.GrossSales)
	}

	// Requirement 4: the chain report totals shift 1 → shift 3.
	chainReport, err := s.buildChainReport(chain1)
	if err != nil {
		t.Fatalf("chain report: %v", err)
	}
	if chainReport.ShiftCount != 3 {
		t.Fatalf("chain report: %d shifts, want 3", chainReport.ShiftCount)
	}
	if got, want := chainReport.Gross, 1100+2200+3300; got != want {
		t.Errorf("chain gross = %d, want %d (sum of all three shifts)", got, want)
	}
	for i, want := range []int{1100, 2200, 3300} {
		if chainReport.Shifts[i].Sequence != i+1 {
			t.Errorf("chain index[%d].Sequence = %d, want %d", i, chainReport.Shifts[i].Sequence, i+1)
		}
		if chainReport.Shifts[i].Gross != want {
			t.Errorf("chain index[%d].Gross = %d, want %d", i, chainReport.Shifts[i].Gross, want)
		}
	}
	// Requirement: the chain slip carries the operator and the denomination
	// mix, exactly like the close slip it mirrors.
	if chainReport.Operator != "Alice Tanaka" {
		t.Errorf("chain slip: Operator = %q, want %q", chainReport.Operator, "Alice Tanaka")
	}
	for i, cs := range chainReport.Shifts {
		if cs.Operator != "Alice Tanaka" {
			t.Errorf("chain index[%d]: Operator = %q, want it named", i, cs.Operator)
		}
	}
	if len(chainReport.Denominations) == 0 {
		t.Error("chain slip: no 金種 rows")
	}

	// The last member is the final close; the earlier two are handovers.
	if k := chainReport.Shifts[2].Kind; k != "final" {
		t.Errorf("last member kind = %q, want final", k)
	}

	// Requirement 5: the chain ended — the next open starts a fresh one.
	_, chain4, seq4, cont4 := openShift()
	if chain4 == chain1 {
		t.Errorf("shift 4 continued the CLOSED chain %s", chain1)
	}
	if seq4 != 1 || cont4 {
		t.Errorf("shift 4: seq=%d continues=%v, want 1/false", seq4, cont4)
	}
}
