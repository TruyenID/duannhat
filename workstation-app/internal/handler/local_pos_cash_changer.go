package handler

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"os"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// cashChangerURL resolves the Glory 釣銭機 adapter URL for the current request.
// Primary source: the Cloud peripheral registry (type coin_changer) synced DOWN
// into peripheral_devices — metadata.url wins, else metadata.host[:port]
// (port 80 default, TLS-less like the adapter). Env WS_APP_CASH_CHANGER_URL is a
// dev-only fallback. Returns ("", false) when no machine is configured, which the
// glory client turns into ErrNotConfigured → the LAN endpoints 503.
func (s *Server) cashChangerURL() (string, bool) {
	var meta sql.NullString
	err := s.db.QueryRow(
		`SELECT metadata FROM peripheral_devices
		 WHERE type = 'coin_changer' AND is_active = 1
		 ORDER BY updated_at DESC LIMIT 1`,
	).Scan(&meta)
	if err == nil && meta.Valid && meta.String != "" {
		var m map[string]any
		if json.Unmarshal([]byte(meta.String), &m) == nil {
			if u := metaFirstString(m, "url"); u != "" {
				return u, true
			}
			if h := metaFirstString(m, "host", "ip", "address"); h != "" {
				port := metaPort(m, "port", 80)
				if port == 80 {
					return "http://" + h, true
				}
				return fmt.Sprintf("http://%s:%d", h, port), true
			}
		}
	}

	// Local-dev fallback.
	if u := os.Getenv("WS_APP_CASH_CHANGER_URL"); u != "" {
		return u, true
	}
	return "", false
}

// LAN 釣銭機 (Glory) cash-collection endpoints, called by pos-web. A collection
// takes 30–300s so it is asynchronous: start returns a session id, the POS polls
// status, and the cancel button aborts it. See
// docs/guide/cash-changer-glory-adapter.md.
//
//	POST /api/v1/pos/cash-changer/collect              { order_id } → { session_id }
//	GET  /api/v1/pos/cash-changer/collect/{session}     → snapshot
//	POST /api/v1/pos/cash-changer/collect/{session}/cancel

// handleCashChangerCollect starts a cash collection for an order. The amount is
// the order's total (server-authoritative — never trust a client amount for
// cash the machine will physically count).
func (s *Server) handleCashChangerCollect(w http.ResponseWriter, r *http.Request) {
	if _, ok := s.cashChangerURL(); !ok {
		writeError(w, http.StatusServiceUnavailable, "no cash changer configured")
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

	sessionID, err := s.cashChanger.Begin(body.OrderID, total)
	if err != nil {
		if errors.Is(err, service.ErrMachineBusy) {
			writeError(w, http.StatusConflict, err.Error())
			return
		}
		writeServerError(w, r, err)
		return
	}

	s.auditLogPOS(r, "cash_changer.collect", "order", body.OrderID, `{"session":"`+sessionID+`"}`)
	writeJSON(w, http.StatusAccepted, map[string]any{
		"data": map[string]any{"session_id": sessionID, "order_id": body.OrderID, "total": total},
	})
}

// handleCashChangerStatus returns the pollable state of a collection.
func (s *Server) handleCashChangerStatus(w http.ResponseWriter, r *http.Request) {
	// Status is a pure in-memory snapshot; no machine round-trip, so no config
	// gate — an unconfigured workstation simply has no sessions (404 below).
	snap, ok := s.cashChanger.Snapshot(r.PathValue("session"))
	if !ok {
		writeError(w, http.StatusNotFound, "session not found")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": snapshotJSON(snap)})
}

// handleCashChangerCancel asks the machine to return the deposited cash.
func (s *Server) handleCashChangerCancel(w http.ResponseWriter, r *http.Request) {
	session := r.PathValue("session")
	if err := s.cashChanger.CancelSession(r.Context(), session); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	s.auditLogPOS(r, "cash_changer.cancel", "session", session, "{}")
	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{"session_id": session, "canceling": true}})
}

func snapshotJSON(snap service.SessionSnapshot) map[string]any {
	return map[string]any{
		"session_id": snap.SessionID,
		"order_id":   snap.OrderID,
		"running":    snap.Running,
		"status":     string(snap.Status),
		"payment_id": snap.PaymentID,
		"total":      snap.Total,
		"tendered":   snap.Tendered,
		"change":     snap.Change,
		"error":      snap.Error,
	}
}
