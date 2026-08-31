package handler

import (
	"database/sql"
	"errors"
	"net/http"

	"github.com/dxs-platform/workstation-app/internal/domain"
)

// GET /api/v1/customer/tables/{qrToken}
//
// Resolves a QR token (printed on physical tables) to a table record.
// Reads from local `tables` table (synced DOWN from Cloud).
func (s *Server) handleLocalCustomerTable(w http.ResponseWriter, r *http.Request) {
	qrToken := r.PathValue("qrToken")
	if qrToken == "" {
		writeError(w, http.StatusBadRequest, "qr_token required")
		return
	}

	t, err := s.getTableByQR(qrToken)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "table not found")
			return
		}
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": t})
}

func (s *Server) getTableByQR(qr string) (*domain.Table, error) {
	return scanTable(s.db.QueryRow(`
		SELECT id, COALESCE(qr_token, ''), name, COALESCE(zone_id, ''), status, COALESCE(capacity, 0),
		       cloud_updated_at, local_synced_at
		FROM tables WHERE qr_token = ?`, qr))
}
