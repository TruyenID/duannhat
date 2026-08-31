package handler

import (
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"strconv"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Verifone P400 (VescaJS) card-terminal endpoints. pos-web can't reach the P400
// directly (browser mixed-content), so the workstation bridges it: pos-web POSTs
// a charge; the workstation's Wails frontend polls /api/terminal/next, runs
// VescaJS against the P400, and reports back to /api/terminal/result. See
// docs/guide/pos-card-terminal-p400-vesca.md.
//
//	pos-web  (posAuth):
//	  POST /api/v1/pos/terminal/charge              { order_id } → { session_id }
//	  GET  /api/v1/pos/terminal/charge/{session}     → snapshot
//	  POST /api/v1/pos/terminal/charge/{session}/cancel
//	frontend (localOnly):
//	  GET  /api/terminal/next     → { session_id, request, host, port } | 204
//	  POST /api/terminal/result   { session_id, result?, error? }

// cardTerminalConfig resolves the P400's LAN host/port. The source of truth is
// the Cloud-configured peripheral device registry (type `payment_terminal`),
// synced DOWN into `peripheral_devices` (metadata.host / metadata.port) — the
// admin links the device on Cloud, it flows to every workstation. The
// WS_APP_CARD_TERMINAL_* env vars are only a local-dev fallback for testing
// without a paired device. Returns configured=false when neither is set.
func (s *Server) cardTerminalConfig() (host string, port int, configured bool) {
	var meta sql.NullString
	err := s.db.QueryRow(
		`SELECT metadata FROM peripheral_devices
		 WHERE type = 'payment_terminal' AND is_active = 1
		 ORDER BY updated_at DESC LIMIT 1`,
	).Scan(&meta)
	if err == nil && meta.Valid && meta.String != "" {
		var m map[string]any
		if json.Unmarshal([]byte(meta.String), &m) == nil {
			if h := metaFirstString(m, "host", "ip", "address"); h != "" {
				return h, metaPort(m, "port", 8888), true
			}
		}
	}

	// Local-dev fallback.
	if h := os.Getenv("WS_APP_CARD_TERMINAL_HOST"); h != "" {
		port := 8888
		if p, e := strconv.Atoi(os.Getenv("WS_APP_CARD_TERMINAL_PORT")); e == nil && p > 0 {
			port = p
		}
		return h, port, true
	}
	return "", 0, false
}

// metaFirstString returns the first non-empty string value among keys.
func metaFirstString(m map[string]any, keys ...string) string {
	for _, k := range keys {
		if v, ok := m[k].(string); ok && v != "" {
			return v
		}
	}
	return ""
}

// metaPort reads a port from metadata (JSON number or numeric string), defaulting.
func metaPort(m map[string]any, key string, def int) int {
	switch v := m[key].(type) {
	case float64:
		if v > 0 {
			return int(v)
		}
	case string:
		if p, err := strconv.Atoi(v); err == nil && p > 0 {
			return p
		}
	}
	return def
}

// handleCardTerminalCharge starts a card charge for an order on the P400.
func (s *Server) handleCardTerminalCharge(w http.ResponseWriter, r *http.Request) {
	if _, _, ok := s.cardTerminalConfig(); !ok {
		writeError(w, http.StatusServiceUnavailable, "no card terminal configured")
		return
	}
	var body struct {
		OrderID string `json:"order_id"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.OrderID == "" {
		writeError(w, http.StatusBadRequest, "order_id is required")
		return
	}

	var total int
	if err := s.db.QueryRow(
		`SELECT COALESCE(total_amount, 0) FROM orders WHERE id = ?`, body.OrderID,
	).Scan(&total); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		writeServerError(w, r, err)
		return
	}
	if total <= 0 {
		writeError(w, http.StatusUnprocessableEntity, "order total must be positive")
		return
	}

	sessionID, err := s.terminalBridge.Charge(body.OrderID, total, service.ServiceCredit)
	if err != nil {
		if errors.Is(err, service.ErrTerminalBusy) {
			writeError(w, http.StatusConflict, err.Error())
			return
		}
		writeServerError(w, r, err)
		return
	}
	s.auditLogPOS(r, "card_terminal.charge", "order", body.OrderID, `{"session":"`+sessionID+`"}`)
	writeJSON(w, http.StatusAccepted, map[string]any{
		"data": map[string]any{"session_id": sessionID, "order_id": body.OrderID, "total": total},
	})
}

func (s *Server) handleCardTerminalStatus(w http.ResponseWriter, r *http.Request) {
	snap, ok := s.terminalBridge.Snapshot(r.PathValue("session"))
	if !ok {
		writeError(w, http.StatusNotFound, "session not found")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{
		"session_id": snap.SessionID,
		"order_id":   snap.OrderID,
		"status":     snap.Status,
		"payment_id": snap.PaymentID,
		"amount":     snap.Amount,
		"error":      snap.Error,
	}})
}

func (s *Server) handleCardTerminalCancel(w http.ResponseWriter, r *http.Request) {
	session := r.PathValue("session")
	if err := s.terminalBridge.Cancel(session); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	s.auditLogPOS(r, "card_terminal.cancel", "session", session, "{}")
	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{"session_id": session, "canceling": true}})
}

// handleTerminalNext is polled by the workstation frontend. It returns the
// pending VescaJS command enriched with the P400 host/port, or 204 when idle.
func (s *Server) handleTerminalNext(w http.ResponseWriter, _ *http.Request) {
	cmd, ok := s.terminalBridge.NextCommand()
	if !ok {
		w.WriteHeader(http.StatusNoContent)
		return
	}
	host, port, _ := s.cardTerminalConfig()
	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{
		"session_id": cmd.SessionID,
		"cancel":     cmd.Cancel,
		"request":    cmd.Request,
		"host":       host,
		"port":       port,
	}})
}

// handleTerminalResult is called by the workstation frontend with the P400
// result: `result` (approved OutputCompleteEvent) → record; `error` → fail.
func (s *Server) handleTerminalResult(w http.ResponseWriter, r *http.Request) {
	var body struct {
		SessionID string         `json:"session_id"`
		Result    map[string]any `json:"result"`
		Error     string         `json:"error"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.SessionID == "" {
		writeError(w, http.StatusBadRequest, "session_id is required")
		return
	}

	if body.Error != "" || body.Result == nil {
		reason := body.Error
		if reason == "" {
			reason = "terminal returned no result"
		}
		if err := s.terminalBridge.Fail(body.SessionID, reason); err != nil {
			writeError(w, http.StatusBadRequest, err.Error())
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{"status": "failed"}})
		return
	}

	if err := s.terminalBridge.Complete(r.Context(), body.SessionID, body.Result); err != nil {
		// Money-critical (captured but not recorded) or unknown session.
		writeServerError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{"status": "recorded"}})
}
