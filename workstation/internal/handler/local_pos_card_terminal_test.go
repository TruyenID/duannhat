package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

func newTerminalServer(t *testing.T) *Server {
	t.Helper()
	s := newRecorderServer(t)
	s.terminalBridge = service.NewTerminalBridge(s)
	return s
}

func TestCardTerminalHTTP_Flow(t *testing.T) {
	t.Setenv("WS_APP_CARD_TERMINAL_HOST", "192.168.0.77")
	s := newTerminalServer(t)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 3000)`)

	// 1) pos-web starts a charge.
	rr := httptest.NewRecorder()
	s.handleCardTerminalCharge(rr, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/terminal/charge", strings.NewReader(`{"order_id":"o1"}`)))
	if rr.Code != http.StatusAccepted {
		t.Fatalf("charge = %d, body %s, want 202", rr.Code, rr.Body.String())
	}
	var started struct {
		Data struct {
			SessionID string `json:"session_id"`
		} `json:"data"`
	}
	json.Unmarshal(rr.Body.Bytes(), &started)
	sid := started.Data.SessionID
	if sid == "" {
		t.Fatal("no session id")
	}

	// 2) The workstation frontend polls the command (with P400 host/port).
	nr := httptest.NewRecorder()
	s.handleTerminalNext(nr, httptest.NewRequest(http.MethodGet, "/api/terminal/next", nil))
	if nr.Code != http.StatusOK {
		t.Fatalf("next = %d, want 200", nr.Code)
	}
	var next struct {
		Data struct {
			SessionID string         `json:"session_id"`
			Request   map[string]any `json:"request"`
			Host      string         `json:"host"`
			Port      int            `json:"port"`
		} `json:"data"`
	}
	json.Unmarshal(nr.Body.Bytes(), &next)
	if next.Data.SessionID != sid || next.Data.Host != "192.168.0.77" {
		t.Errorf("next = %+v, want session %s host 192.168.0.77", next.Data, sid)
	}
	if _, ok := next.Data.Request["AuthorizeSales"]; !ok {
		t.Errorf("command request = %+v, want an AuthorizeSales", next.Data.Request)
	}

	// 3) The frontend reports an approved terminal result.
	res := httptest.NewRecorder()
	s.handleTerminalResult(res, httptest.NewRequest(http.MethodPost, "/api/terminal/result",
		strings.NewReader(`{"session_id":"`+sid+`","result":{"SlipNumber":"SLIP-1","ApprovalCode":"OK"}}`)))
	if res.Code != http.StatusOK {
		t.Fatalf("result = %d, body %s, want 200", res.Code, res.Body.String())
	}

	// 4) pos-web polls status → approved + a payment id.
	sr := httptest.NewRecorder()
	statusReq := httptest.NewRequest(http.MethodGet, "/api/v1/pos/terminal/charge/"+sid, nil)
	statusReq.SetPathValue("session", sid)
	s.handleCardTerminalStatus(sr, statusReq)
	var snap struct {
		Data struct {
			Status    string `json:"status"`
			PaymentID string `json:"payment_id"`
		} `json:"data"`
	}
	json.Unmarshal(sr.Body.Bytes(), &snap)
	if snap.Data.Status != "approved" || snap.Data.PaymentID == "" {
		t.Fatalf("status = %+v, want approved + payment id", snap.Data)
	}

	// The card payment landed in SQLite and closed the order.
	var oStatus string
	s.db.QueryRow(`SELECT status FROM orders WHERE id='o1'`).Scan(&oStatus)
	if oStatus != "closed" {
		t.Errorf("order status = %q, want closed", oStatus)
	}
}

func TestCardTerminalHTTP_ConfigFromRegistry(t *testing.T) {
	// Primary path: the P400 host/port come from the Cloud-synced peripheral
	// device registry (type payment_terminal), NOT the env var.
	t.Setenv("WS_APP_CARD_TERMINAL_HOST", "") // ensure the env fallback is off
	s := newTerminalServer(t)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 5000)`)
	s.db.Exec(`INSERT INTO peripheral_devices (id, name, type, is_active, metadata)
	           VALUES ('pd1', 'Counter P400', 'payment_terminal', 1, '{"host":"10.0.0.9","port":9100}')`)

	// Charge is allowed (configured via the registry).
	rr := httptest.NewRecorder()
	s.handleCardTerminalCharge(rr, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/terminal/charge", strings.NewReader(`{"order_id":"o1"}`)))
	if rr.Code != http.StatusAccepted {
		t.Fatalf("charge = %d, want 202 (configured from registry)", rr.Code)
	}

	// The frontend command carries the registry host/port.
	nr := httptest.NewRecorder()
	s.handleTerminalNext(nr, httptest.NewRequest(http.MethodGet, "/api/terminal/next", nil))
	var next struct {
		Data struct {
			Host string `json:"host"`
			Port int    `json:"port"`
		} `json:"data"`
	}
	json.Unmarshal(nr.Body.Bytes(), &next)
	if next.Data.Host != "10.0.0.9" || next.Data.Port != 9100 {
		t.Errorf("next host/port = %+v, want 10.0.0.9:9100 from registry", next.Data)
	}
}

func TestCardTerminalHTTP_NoConfig503(t *testing.T) {
	t.Setenv("WS_APP_CARD_TERMINAL_HOST", "")
	s := newTerminalServer(t)
	rr := httptest.NewRecorder()
	s.handleCardTerminalCharge(rr, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/terminal/charge", strings.NewReader(`{"order_id":"o1"}`)))
	if rr.Code != http.StatusServiceUnavailable {
		t.Fatalf("code = %d, want 503 when unconfigured", rr.Code)
	}
}

func TestCardTerminalHTTP_Busy409(t *testing.T) {
	t.Setenv("WS_APP_CARD_TERMINAL_HOST", "192.168.0.77")
	s := newTerminalServer(t)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 1000)`)

	first := httptest.NewRecorder()
	s.handleCardTerminalCharge(first, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/terminal/charge", strings.NewReader(`{"order_id":"o1"}`)))
	if first.Code != http.StatusAccepted {
		t.Fatalf("first = %d, want 202", first.Code)
	}
	// Second charge while the first is unfinished → 409.
	second := httptest.NewRecorder()
	s.handleCardTerminalCharge(second, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/terminal/charge", strings.NewReader(`{"order_id":"o1"}`)))
	if second.Code != http.StatusConflict {
		t.Fatalf("second = %d, want 409 busy", second.Code)
	}
}
