package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Plan-044 (R1 kept in R2 — DESIGN Decision 6) — LAN POS order + payment creation
// is gated on an in-progress shift (status open|closing). Kiosk/customer/handy
// surfaces stay ungated (they may transact during the close→open gap; those become
// gap payments reconciled at the next open).

func seedSession(t *testing.T, s *Server, status string) {
	t.Helper()
	if _, err := s.db.Conn().Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, till_id, branch_id)
		VALUES ('s1','S1',?, '2026-07-16','JPY',0,'2026-07-16T09:00:00Z','till-1','b1')`, status); err != nil {
		t.Fatalf("seed session %q: %v", status, err)
	}
}

// seedOpenShift inserts an in-progress cashier shift so the NO_OPEN_SHIFT gate
// on LAN POS order/payment creation (plan-044 DESIGN Decision 6) passes.
// Idempotent (INSERT OR IGNORE, fixed id) — safe from shared setup AND a test body.
func seedOpenShift(t *testing.T, s *Server) {
	t.Helper()
	if _, err := s.db.Conn().Exec(`INSERT OR IGNORE INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, till_id, branch_id)
		VALUES ('gate-open-shift','GATE','open','2026-07-16','JPY',0,'2026-07-16T09:00:00Z','till-1','b1')`); err != nil {
		t.Fatalf("seed open shift: %v", err)
	}
}

func TestHasInProgressShift(t *testing.T) {
	cases := []struct {
		status string
		want   bool
	}{
		{"open", true},
		{"closing", true},
		{"settled", false},
		{"abandoned", false},
	}
	for _, c := range cases {
		t.Run(c.status, func(t *testing.T) {
			s := newFireTestServer(t)
			seedSession(t, s, c.status)
			if got := s.hasInProgressShift(); got != c.want {
				t.Errorf("status %q → hasInProgressShift = %v, want %v", c.status, got, c.want)
			}
		})
	}
	t.Run("no session", func(t *testing.T) {
		s := newFireTestServer(t)
		if s.hasInProgressShift() {
			t.Error("no session → want false")
		}
	})
}

func decodeCode(t *testing.T, body []byte) string {
	t.Helper()
	var resp struct {
		Code string `json:"code"`
	}
	_ = json.Unmarshal(body, &resp)
	return resp.Code
}

func TestPosCreateOrder_GatedWhenNoOpenShift(t *testing.T) {
	s := newFireTestServer(t)
	seedSession(t, s, "settled") // terminal → not in progress → blocked

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/pos/orders", bytes.NewReader([]byte(`{}`)))
	s.handleLocalPosCreateOrder(w, req)

	if w.Code != http.StatusConflict {
		t.Fatalf("status = %d, want 409; body %s", w.Code, w.Body.String())
	}
	if code := decodeCode(t, w.Body.Bytes()); code != "NO_OPEN_SHIFT" {
		t.Errorf("code = %q, want NO_OPEN_SHIFT", code)
	}
}

func TestPosCreatePayment_GatedWhenNoOpenShift(t *testing.T) {
	s := newFireTestServer(t) // no session at all → blocked

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/pos/orders/o1/payments", bytes.NewReader([]byte(`{}`)))
	req.SetPathValue("id", "o1")
	s.handleLocalPosCreatePayment(w, req)

	if w.Code != http.StatusConflict {
		t.Fatalf("status = %d, want 409; body %s", w.Code, w.Body.String())
	}
	if code := decodeCode(t, w.Body.Bytes()); code != "NO_OPEN_SHIFT" {
		t.Errorf("code = %q, want NO_OPEN_SHIFT", code)
	}
}

func TestPosCreateOrder_PassesWhenShiftInProgress(t *testing.T) {
	for _, status := range []string{"open", "closing"} {
		t.Run(status, func(t *testing.T) {
			s := newFireTestServer(t)
			s.hub = NewHub()
			seedSession(t, s, status)

			w := httptest.NewRecorder()
			req := httptest.NewRequest(http.MethodPost, "/api/v1/pos/orders",
				bytes.NewReader([]byte(`{"order_type":"spot","items":[]}`)))
			s.handleLocalPosCreateOrder(w, req)

			// The gate must let it through — status must NOT be a NO_OPEN_SHIFT 409.
			if w.Code == http.StatusConflict && decodeCode(t, w.Body.Bytes()) == "NO_OPEN_SHIFT" {
				t.Fatalf("gate blocked despite %s shift: %s", status, w.Body.String())
			}
		})
	}
}
