package handler

// local_peripheral_devices.go — the workstation's offline-first peripheral
// registry admin, called by the Wails Settings screen (localOnly).
//
// It is JUST a config store: every write applies to the LOCAL peripheral_devices
// table immediately (works with no network) and enqueues a sync-UP op. The sync
// engine pushes to Cloud when online (peripheral.upsert / peripheral.delete);
// Cloud upserts by the workstation-supplied id so the row keeps its identity.
// Nothing here blocks on connectivity.

import (
	"database/sql"
	"encoding/json"
	"net/http"

	"github.com/google/uuid"
)

// peripheralRow is the wire shape shared by list + write responses.
type peripheralRow struct {
	ID          string         `json:"id"`
	Name        string         `json:"name"`
	Type        string         `json:"type"`
	IsActive    bool           `json:"is_active"`
	Metadata    map[string]any `json:"metadata"`
	BranchID    *string        `json:"branch_id"`
	OrgID       *string        `json:"organization_id"`
	PendingSync bool           `json:"pending_sync"`
}

// handlePeripheralList returns the local peripheral registry for this branch,
// hiding rows tombstoned by an offline delete.
func (s *Server) handlePeripheralList(w http.ResponseWriter, r *http.Request) {
	rows, err := s.db.Query(`
		SELECT id, name, type, is_active, metadata, branch_id, organization_id, pending_sync
		FROM peripheral_devices
		WHERE pending_delete = 0
		ORDER BY type, name`)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()

	out := []peripheralRow{}
	for rows.Next() {
		row, err := scanPeripheral(rows)
		if err != nil {
			writeServerError(w, r, err)
			return
		}
		out = append(out, row)
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": out})
}

// handlePeripheralCreate registers a peripheral locally and queues the sync UP.
func (s *Server) handlePeripheralCreate(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Name     string         `json:"name"`
		Type     string         `json:"type"`
		IsActive *bool          `json:"is_active"`
		Metadata map[string]any `json:"metadata"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		writeError(w, http.StatusBadRequest, "invalid body")
		return
	}
	if msg := validatePeripheral(body.Name, body.Type, body.Metadata); msg != "" {
		writeValidation(w, msg)
		return
	}

	id := uuid.New().String()
	branch, org := s.workstationBranchOrg()
	active := 1
	if body.IsActive != nil && !*body.IsActive {
		active = 0
	}
	meta := metaJSON(body.Metadata)

	if _, err := s.db.Exec(`
		INSERT INTO peripheral_devices
			(id, name, type, is_active, metadata, branch_id, organization_id, pending_sync)
		VALUES (?, ?, ?, ?, ?, ?, ?, 1)`,
		id, body.Name, body.Type, active, meta, nullIf(branch), nullIf(org),
	); err != nil {
		writeServerError(w, r, err)
		return
	}

	s.enqueuePeripheralUpsert(id)
	s.auditLog(r, "peripheral.create", "peripheral", id, auditDetails(map[string]any{"type": body.Type}))
	s.writeOnePeripheral(w, r, id, http.StatusCreated)
}

// handlePeripheralUpdate edits a local peripheral and queues the sync UP.
func (s *Server) handlePeripheralUpdate(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	var existingType string
	if err := s.db.QueryRow(`SELECT type FROM peripheral_devices WHERE id = ? AND pending_delete = 0`, id).
		Scan(&existingType); err != nil {
		writeError(w, http.StatusNotFound, "peripheral not found")
		return
	}

	var body struct {
		Name     *string        `json:"name"`
		Type     *string        `json:"type"`
		IsActive *bool          `json:"is_active"`
		Metadata map[string]any `json:"metadata"`
		hasMeta  bool
	}
	raw := map[string]json.RawMessage{}
	if err := json.NewDecoder(r.Body).Decode(&raw); err != nil {
		writeError(w, http.StatusBadRequest, "invalid body")
		return
	}
	json.Unmarshal(raw["name"], &body.Name)
	json.Unmarshal(raw["type"], &body.Type)
	json.Unmarshal(raw["is_active"], &body.IsActive)
	if v, ok := raw["metadata"]; ok {
		body.hasMeta = true
		json.Unmarshal(v, &body.Metadata)
	}

	effType := existingType
	if body.Type != nil {
		effType = *body.Type
	}
	// Validate the metadata only when it is part of this edit (partial updates,
	// e.g. toggling is_active, are allowed) — mirrors the Cloud contract.
	name := ""
	if body.Name != nil {
		name = *body.Name
	}
	if body.hasMeta {
		if msg := validatePeripheral(orDefault(name, "x"), effType, body.Metadata); msg != "" {
			writeValidation(w, msg)
			return
		}
	}

	set := "pending_sync = 1"
	args := []any{}
	if body.Name != nil {
		set += ", name = ?"
		args = append(args, *body.Name)
	}
	if body.Type != nil {
		set += ", type = ?"
		args = append(args, *body.Type)
	}
	if body.IsActive != nil {
		set += ", is_active = ?"
		args = append(args, boolInt(*body.IsActive))
	}
	if body.hasMeta {
		set += ", metadata = ?"
		args = append(args, metaJSON(body.Metadata))
	}
	args = append(args, id)

	if _, err := s.db.Exec(`UPDATE peripheral_devices SET `+set+` WHERE id = ?`, args...); err != nil {
		writeServerError(w, r, err)
		return
	}

	s.enqueuePeripheralUpsert(id)
	s.auditLog(r, "peripheral.update", "peripheral", id, "{}")
	s.writeOnePeripheral(w, r, id, http.StatusOK)
}

// handlePeripheralDelete tombstones a peripheral locally and queues the delete.
func (s *Server) handlePeripheralDelete(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	res, err := s.db.Exec(`UPDATE peripheral_devices SET pending_delete = 1, pending_sync = 1 WHERE id = ?`, id)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	if n, _ := res.RowsAffected(); n == 0 {
		writeError(w, http.StatusNotFound, "peripheral not found")
		return
	}

	if s.sync != nil {
		_ = s.sync.Enqueue("peripheral", id, "delete", map[string]any{}, 2)
	}
	s.auditLog(r, "peripheral.delete", "peripheral", id, "{}")
	writeJSON(w, http.StatusNoContent, nil)
}

// --- helpers ------------------------------------------------------------

func (s *Server) enqueuePeripheralUpsert(id string) {
	if s.sync != nil {
		_ = s.sync.Enqueue("peripheral", id, "upsert", map[string]any{}, 2)
	}
}

func (s *Server) writeOnePeripheral(w http.ResponseWriter, r *http.Request, id string, status int) {
	row, err := scanPeripheral(s.db.QueryRow(`
		SELECT id, name, type, is_active, metadata, branch_id, organization_id, pending_sync
		FROM peripheral_devices WHERE id = ?`, id))
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	writeJSON(w, status, map[string]any{"data": row})
}

// scanPeripheral reads a peripheral row from a *sql.Row or *sql.Rows.
func scanPeripheral(sc interface{ Scan(...any) error }) (peripheralRow, error) {
	var (
		row      peripheralRow
		active   int
		pending  int
		metadata *string
		branchID *string
		orgID    *string
	)
	if err := sc.Scan(&row.ID, &row.Name, &row.Type, &active, &metadata, &branchID, &orgID, &pending); err != nil {
		return row, err
	}
	row.IsActive = active == 1
	row.PendingSync = pending == 1
	row.BranchID = branchID
	row.OrgID = orgID
	if metadata != nil && *metadata != "" {
		_ = json.Unmarshal([]byte(*metadata), &row.Metadata)
	}
	return row, nil
}

// workstationBranchOrg reads this workstation's paired branch/org from settings.
func (s *Server) workstationBranchOrg() (branch, org string) {
	s.db.QueryRow(`SELECT COALESCE(value,'') FROM settings WHERE key = 'workstation_branch_id'`).Scan(&branch)
	s.db.QueryRow(`SELECT COALESCE(value,'') FROM settings WHERE key = 'organization_id'`).Scan(&org)
	return branch, org
}

// validatePeripheral enforces the same contract as Cloud: name+type required,
// and LAN types (payment_terminal / coin_changer) need metadata.host.
func validatePeripheral(name, typ string, metadata map[string]any) string {
	if name == "" {
		return "name is required"
	}
	if typ == "" {
		return "type is required"
	}
	if isNetworkPeripheralType(typ) {
		host, _ := metadata["host"].(string)
		if host == "" {
			return "metadata.host is required for this device type"
		}
	}
	return ""
}

func isNetworkPeripheralType(typ string) bool {
	return typ == "payment_terminal" || typ == "coin_changer"
}

func metaJSON(m map[string]any) any {
	if len(m) == 0 {
		return nil
	}
	b, err := json.Marshal(m)
	if err != nil {
		return nil
	}
	return string(b)
}

func nullIf(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func boolInt(b bool) int {
	if b {
		return 1
	}
	return 0
}

func orDefault(s, def string) string {
	if s == "" {
		return def
	}
	return s
}

// writeValidation emits the Laravel-style 422 shape the frontend already maps.
func writeValidation(w http.ResponseWriter, msg string) {
	writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
		"message": msg,
		"errors":  map[string]any{"metadata.host": []string{msg}},
	})
}

var _ = sql.ErrNoRows
