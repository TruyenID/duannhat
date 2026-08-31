package handler

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// seedShiftOpen inserts an OPEN session with an opening denomination count so
// buildShiftOpenReport has a realistic dataset to aggregate.
func seedShiftOpen(t *testing.T, s *Server) string {
	t.Helper()
	db := s.db.Conn()
	const (
		sessionID = "osess-1"
		tillID    = "otill-1"
		opened    = "2026-07-06T05:00:00Z" // 14:00 in +07 / 12:00 UTC+... — display via shopLocation
	)
	exec := func(q string, args ...any) {
		if _, err := db.Exec(q, args...); err != nil {
			t.Fatalf("seed exec: %v\n%s", err, q)
		}
	}

	exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code,
		 opening_float_amount, opening_note, opener_name, opened_at, till_id, branch_id)
		VALUES (?, 'OPEN-1', 'open', '2026-07-06', 'JPY',
		 14500, 'Ca sang du tien le', '田中', ?, ?, 'br-1')`,
		sessionID, opened, tillID)

	// Denominations + opening counts (10,000×1, 1,000×3, 500×2, 100×5, 5,000×0).
	denoms := []struct {
		id  string
		val int
		qty int
		sub int
	}{
		{"d10000", 10000, 1, 10000},
		{"d5000", 5000, 0, 0},
		{"d1000", 1000, 3, 3000},
		{"d500", 500, 2, 1000},
		{"d100", 100, 5, 500},
	}
	for i, d := range denoms {
		exec(`INSERT INTO denominations (id, currency_code, value, kind, sort_order)
			VALUES (?, 'JPY', ?, 'note', ?)`, d.id, d.val, i)
		// pos-web only sends counted (>0) denominations — the 5,000 with qty 0
		// gets NO count row, so the report must still list it (LEFT JOIN → 0).
		if d.qty > 0 {
			exec(`INSERT INTO till_cash_denomination_counts
				(id, session_id, denomination_id, phase, quantity, subtotal_amount)
				VALUES (?, ?, ?, 'opening', ?, ?)`,
				"c-"+d.id, sessionID, d.id, d.qty, d.sub)
		}
	}
	// A closing-phase row that must NOT appear on the open report.
	exec(`INSERT INTO till_cash_denomination_counts
		(id, session_id, denomination_id, phase, quantity, subtotal_amount)
		VALUES ('c-closing', ?, 'd10000', 'closing', 9, 90000)`, sessionID)

	return sessionID
}

func TestBuildShiftOpenReport_Aggregates(t *testing.T) {
	s := newLANPrintTestServer(t)
	sessionID := seedShiftOpen(t, s)

	info, err := s.buildShiftOpenReport(sessionID, "POS-01")
	if err != nil {
		t.Fatalf("buildShiftOpenReport: %v", err)
	}
	if info.DeviceName != "POS-01" {
		t.Errorf("DeviceName = %q, want POS-01 (the POS terminal, not workstation)", info.DeviceName)
	}
	if info.Operator != "田中" {
		t.Errorf("Operator = %q, want 田中", info.Operator)
	}
	if info.OpeningFloat != 14500 {
		t.Errorf("OpeningFloat = %d, want 14500", info.OpeningFloat)
	}
	if info.Note != "Ca sang du tien le" {
		t.Errorf("Note = %q", info.Note)
	}
	if info.OpenedAt == "" {
		t.Errorf("OpenedAt should be formatted, got empty")
	}
	// ALL 5 active denominations listed (sort_order asc), closing row excluded.
	if len(info.Denominations) != 5 {
		t.Fatalf("Denominations len = %d, want 5 — every denomination must be listed (%+v)",
			len(info.Denominations), info.Denominations)
	}
	if info.Denominations[0].Value != 10000 || info.Denominations[0].Quantity != 1 || info.Denominations[0].Subtotal != 10000 {
		t.Errorf("Denominations[0] = %+v, want 10000/1/10000", info.Denominations[0])
	}
	// The 5,000 (no count row entered) must still appear with 0 / 0.
	if info.Denominations[1].Value != 5000 || info.Denominations[1].Quantity != 0 || info.Denominations[1].Subtotal != 0 {
		t.Errorf("Denominations[1] = %+v, want 5000/0/0 (uncounted denomination listed as 0)", info.Denominations[1])
	}
	if info.Denominations[4].Value != 100 || info.Denominations[4].Quantity != 5 {
		t.Errorf("Denominations[4] = %+v, want value 100 qty 5", info.Denominations[4])
	}
	// Sum of subtotals == opening float.
	sum := 0
	for _, d := range info.Denominations {
		sum += d.Subtotal
	}
	if sum != info.OpeningFloat {
		t.Errorf("denomination subtotals sum %d != OpeningFloat %d", sum, info.OpeningFloat)
	}
}

// TestBuildShiftOpenReport_ResolvesStaffName covers the case the bug report hit:
// a shift opened by a staff picked from the list carries only opened_by_id (no
// opener_name), so the operator must be resolved through the local staff mirror
// instead of printing the "(not set)" fallback.
func TestBuildShiftOpenReport_ResolvesStaffName(t *testing.T) {
	s := newLANPrintTestServer(t)
	db := s.db.Conn()
	exec := func(q string, args ...any) {
		if _, err := db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v\n%s", err, q)
		}
	}
	exec(`INSERT INTO staff (id, full_name) VALUES ('u1', '山田太郎')`)
	exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code,
		 opening_float_amount, opened_by_id, opened_at, till_id, branch_id)
		VALUES ('s2','OPEN-2','open','2026-07-06','JPY',
		 0, 'u1', '2026-07-06T05:00:00Z', 't2', 'br-1')`)

	info, err := s.buildShiftOpenReport("s2", "POS-02")
	if err != nil {
		t.Fatalf("buildShiftOpenReport: %v", err)
	}
	if info.Operator != "山田太郎" {
		t.Errorf("Operator = %q, want 山田太郎 (resolved from opened_by_id via staff table)", info.Operator)
	}
}

func TestBuildShiftOpenReport_404OnMissingSession(t *testing.T) {
	s := newLANPrintTestServer(t)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/shift-open-report",
		bytes.NewBufferString(`{"session_id":"nope"}`))
	req = stubAuth(req)
	s.handleLANPrintShiftOpenReport(w, req)
	if w.Code != http.StatusNotFound {
		t.Errorf("want 404 for unknown session, got %d (%s)", w.Code, w.Body.String())
	}
}

// TestHandleShiftOpenReport_GatedOffBySetting verifies the shop-setting gate:
// when shop_settings.print_shift_open_report is "false", the handler prints
// nothing and returns a non-fatal "disabled" status.
func TestHandleShiftOpenReport_GatedOffBySetting(t *testing.T) {
	s := newLANPrintTestServer(t)
	if _, err := s.db.Conn().Exec(
		`INSERT INTO shop_settings (key, value) VALUES ('print_shift_open_report','false')`); err != nil {
		t.Fatalf("seed setting: %v", err)
	}
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/shift-open-report",
		bytes.NewBufferString(`{"session_id":"any"}`))
	req = stubAuth(req)
	s.handleLANPrintShiftOpenReport(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d (%s)", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), `"disabled"`) {
		t.Errorf("setting off → expected status \"disabled\", got %s", w.Body.String())
	}
}

func TestHandleShiftOpenReport_400OnMissingSessionID(t *testing.T) {
	s := newLANPrintTestServer(t)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/shift-open-report",
		bytes.NewBufferString(`{}`))
	req = stubAuth(req)
	s.handleLANPrintShiftOpenReport(w, req)
	if w.Code != http.StatusBadRequest {
		t.Errorf("want 400, got %d (%s)", w.Code, w.Body.String())
	}
}
