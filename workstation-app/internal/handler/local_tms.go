package handler

import (
	"database/sql"
	"net/http"

	"github.com/dxs-platform/workstation-app/internal/domain"
)

// GET /api/v1/tms/zones
func (s *Server) handleLocalListZones(w http.ResponseWriter, r *http.Request) {
	rows, err := s.db.Query(`
		SELECT id, name, COALESCE(sort_order, 0), cloud_updated_at, local_synced_at
		FROM zones ORDER BY sort_order ASC, name ASC`)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	defer rows.Close()

	zones := make([]domain.Zone, 0)
	for rows.Next() {
		var z domain.Zone
		var cloudUpdated sql.NullString
		var localSynced string
		if err := rows.Scan(&z.ID, &z.Name, &z.SortOrder, &cloudUpdated, &localSynced); err != nil {
			continue
		}
		if cloudUpdated.Valid {
			t := parseTime(cloudUpdated.String)
			z.CloudUpdatedAt = &t
		}
		z.LocalSyncedAt = parseTime(localSynced)
		zones = append(zones, z)
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": zones})
}

// GET /api/v1/tms/tables?zone_id=<id>
func (s *Server) handleLocalListTables(w http.ResponseWriter, r *http.Request) {
	query := `SELECT id, COALESCE(qr_token, ''), name, COALESCE(zone_id, ''), status, COALESCE(capacity, 0),
	          cloud_updated_at, local_synced_at FROM tables`
	args := []any{}

	if zoneID := r.URL.Query().Get("zone_id"); zoneID != "" {
		query += " WHERE zone_id = ?"
		args = append(args, zoneID)
	}
	query += " ORDER BY name"

	rows, err := s.db.Query(query, args...)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	defer rows.Close()

	tables := make([]domain.Table, 0)
	for rows.Next() {
		t, err := scanTableFromRows(rows)
		if err != nil {
			continue
		}
		tables = append(tables, *t)
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": tables})
}

// ─── shared table scanners ────────────────────────────────────────────────

type rowScanner interface {
	Scan(dest ...any) error
}

func scanTable(row rowScanner) (*domain.Table, error) {
	var t domain.Table
	var status string
	var cloudUpdated sql.NullString
	var localSynced string
	if err := row.Scan(&t.ID, &t.QRToken, &t.Name, &t.ZoneID, &status, &t.Capacity,
		&cloudUpdated, &localSynced); err != nil {
		return nil, err
	}
	t.Status = domain.TableStatus(status)
	if cloudUpdated.Valid {
		ct := parseTime(cloudUpdated.String)
		t.CloudUpdatedAt = &ct
	}
	t.LocalSyncedAt = parseTime(localSynced)
	return &t, nil
}

func scanTableFromRows(rows *sql.Rows) (*domain.Table, error) {
	return scanTable(rows)
}
