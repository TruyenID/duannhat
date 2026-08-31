package handler

import (
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"strconv"
	"time"

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

	// Refuse to charge an order the workstation has already collected in full.
	//
	// This is the guard that was missing when a sync bug kept Cloud from ever
	// hearing about card payments: pos-web reads the order from Cloud, saw it
	// still open, and the cashier swiped again — four ¥715 charges on one ¥715
	// order, all genuinely captured. Local records are the authority on what this
	// terminal has taken, so they are what decides.
	//
	// Deliberately a hard 409 rather than a warning: unlike a reprint, a second
	// swipe moves real money off a customer's card, and it cannot be undone from
	// here. A genuine second payment (a split, a top-up) raises the order total
	// first, so this only bites the case it is meant to.
	var paid int
	if err := s.db.QueryRow(
		`SELECT COALESCE(SUM(amount), 0) FROM payments
		 WHERE order_id = ? AND status IN ('succeeded','captured','confirmed')`,
		body.OrderID,
	).Scan(&paid); err != nil {
		writeServerError(w, r, err)
		return
	}
	if paid >= total {
		writeJSON(w, http.StatusConflict, map[string]any{
			"code":    "order_already_paid",
			"message": "this order is already paid in full on the workstation — check the payment list before charging again",
			"order":   map[string]any{"id": body.OrderID, "total": total, "paid": paid},
		})
		return
	}

	sessionID, err := s.terminalBridge.Charge(body.OrderID, total, service.ServiceCredit)
	if err != nil {
		if errors.Is(err, service.ErrTerminalBusy) {
			// Say WHAT is holding the machine. A bare "a transaction is already
			// in progress" gives the cashier nothing to act on — during QA it
			// left the shop with a 409 whose session id nobody could name, and
			// no endpoint that could name it either.
			busy := map[string]any{"message": err.Error(), "code": "terminal_busy"}
			if snap, ok := s.terminalBridge.ActiveSnapshot(); ok {
				busy["active_session"] = terminalSnapshotJSON(snap)
			}
			writeJSON(w, http.StatusConflict, busy)
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

// terminalSnapshotJSON is the single wire shape for a charge session, shared by
// the status poll, the busy 409 and the /current probe so they cannot drift.
func terminalSnapshotJSON(snap service.TerminalSnapshot) map[string]any {
	out := map[string]any{
		"session_id": snap.SessionID,
		"order_id":   snap.OrderID,
		"status":     snap.Status,
		"payment_id": snap.PaymentID,
		"amount":     snap.Amount,
		"error":      snap.Error,
		"expired":    snap.Expired,
	}
	if !snap.StartedAt.IsZero() {
		out["started_at"] = snap.StartedAt.UTC().Format(time.RFC3339)
	}
	if !snap.EndedAt.IsZero() {
		out["ended_at"] = snap.EndedAt.UTC().Format(time.RFC3339)
	}
	return out
}

func (s *Server) handleCardTerminalStatus(w http.ResponseWriter, r *http.Request) {
	snap, ok := s.terminalBridge.Snapshot(r.PathValue("session"))
	if !ok {
		writeError(w, http.StatusNotFound, "session not found")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": terminalSnapshotJSON(snap)})
}

// handleCardTerminalCurrent answers "what is holding the terminal right now?".
// Without it a 409 was a dead end: Snapshot needs an id, and the id of the
// session doing the blocking was exactly what the caller did not have.
func (s *Server) handleCardTerminalCurrent(w http.ResponseWriter, _ *http.Request) {
	snap, ok := s.terminalBridge.ActiveSnapshot()
	if !ok {
		w.WriteHeader(http.StatusNoContent)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": terminalSnapshotJSON(snap)})
}

// handleCardTerminalAbandon force-settles the in-flight session as `unknown`.
//
// posAuth, not localOnly: the cashier pressing this stands at the counter with a
// LAN tablet, and localOnly (loopback) would 403 every one of them — a bug that
// hides on a dev box at localhost and only appears in the shop. Following the
// house rule for destructive-but-necessary money actions (unpair --force, the
// reprint ledger): allow it, and record who. Blocking it just sends staff to
// pull the workstation's power, which loses the audit trail as well.
func (s *Server) handleCardTerminalAbandon(w http.ResponseWriter, r *http.Request) {
	snap, ok := s.terminalBridge.Abandon()
	if !ok {
		writeError(w, http.StatusNotFound, "no card terminal transaction in progress")
		return
	}
	s.auditLogPOS(r, "card_terminal.abandon", "session", snap.SessionID, auditDetails(map[string]any{
		"order_id":      snap.OrderID,
		"amount":        snap.Amount,
		"actor_user_id": r.Header.Get("X-Actor-User-Id"),
	}))
	writeJSON(w, http.StatusOK, map[string]any{"data": terminalSnapshotJSON(snap)})
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
//
// POST, not GET: this call MUTATES — it hands the command out and moves the
// session to processing, so whoever calls it owns driving the P400. As a GET it
// was a trap that Go's ServeMux widened to HEAD as well: one diagnostic curl, a
// prefetch or a link checker consumed the command and the terminal was never
// driven, leaving the machine wedged in processing.
//
// The body carries the session the caller is currently driving (or ""), which is
// the liveness signal the bridge expires against. A webview that reloaded mid
// transaction resumes polling with an empty value, so it stops propping up the
// session it abandoned.
func (s *Server) handleTerminalNext(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Driving string `json:"driving"`
	}
	if r.Body != nil {
		_ = json.NewDecoder(r.Body).Decode(&body) // absent/!json body = idle poller
	}

	cmd, ok := s.terminalBridge.NextCommand(body.Driving)
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
