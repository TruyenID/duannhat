package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestPeripheralList_HidesTombstonedRows(t *testing.T) {
	s := newRecorderServer(t)
	s.db.Exec(`INSERT INTO peripheral_devices (id, name, type, is_active, metadata, pending_sync, pending_delete)
	           VALUES ('p1', 'Counter P400', 'payment_terminal', 1, '{"host":"192.168.0.77","port":8888}', 0, 0)`)
	s.db.Exec(`INSERT INTO peripheral_devices (id, name, type, is_active, pending_sync, pending_delete)
	           VALUES ('p2', 'Deleted', 'coin_changer', 1, 1, 1)`)

	rr := httptest.NewRecorder()
	s.handlePeripheralList(rr, httptest.NewRequest(http.MethodGet, "/api/peripheral-devices", nil))
	if rr.Code != http.StatusOK {
		t.Fatalf("list = %d, want 200", rr.Code)
	}
	var out struct {
		Data []struct {
			ID       string         `json:"id"`
			Metadata map[string]any `json:"metadata"`
		} `json:"data"`
	}
	json.Unmarshal(rr.Body.Bytes(), &out)
	if len(out.Data) != 1 || out.Data[0].ID != "p1" {
		t.Fatalf("list = %+v, want only the non-tombstoned p1", out.Data)
	}
	if out.Data[0].Metadata["host"] != "192.168.0.77" {
		t.Errorf("metadata not parsed: %+v", out.Data[0].Metadata)
	}
}

func TestPeripheralCreate_WritesLocallyOffline(t *testing.T) {
	// No cloud, no sync engine — the create must still succeed (offline-first)
	// and land a pending_sync row.
	s := newRecorderServer(t)
	s.db.Exec(`INSERT INTO settings (key, value) VALUES ('workstation_branch_id','b1'), ('organization_id','o1')`)

	rr := httptest.NewRecorder()
	s.handlePeripheralCreate(rr, httptest.NewRequest(http.MethodPost, "/api/peripheral-devices",
		strings.NewReader(`{"name":"Counter P400","type":"payment_terminal","metadata":{"host":"192.168.0.77","port":8888}}`)))
	if rr.Code != http.StatusCreated {
		t.Fatalf("create = %d, body %s, want 201 offline", rr.Code, rr.Body.String())
	}
	var out struct {
		Data struct {
			ID          string `json:"id"`
			PendingSync bool   `json:"pending_sync"`
		} `json:"data"`
	}
	json.Unmarshal(rr.Body.Bytes(), &out)
	if out.Data.ID == "" || !out.Data.PendingSync {
		t.Fatalf("create payload = %+v, want an id + pending_sync", out.Data)
	}

	var branch, pending int
	s.db.QueryRow(`SELECT pending_sync, (branch_id='b1') FROM peripheral_devices WHERE id=?`, out.Data.ID).
		Scan(&pending, &branch)
	if pending != 1 || branch != 1 {
		t.Errorf("row pending=%d branchMatch=%d, want 1/1 (stamped from settings)", pending, branch)
	}
}

func TestPeripheralCreate_RejectsMissingHost(t *testing.T) {
	s := newRecorderServer(t)
	rr := httptest.NewRecorder()
	s.handlePeripheralCreate(rr, httptest.NewRequest(http.MethodPost, "/api/peripheral-devices",
		strings.NewReader(`{"name":"x","type":"coin_changer"}`)))
	if rr.Code != http.StatusUnprocessableEntity {
		t.Fatalf("code = %d, want 422 for a LAN device with no host", rr.Code)
	}
	if !strings.Contains(rr.Body.String(), "metadata.host") {
		t.Errorf("body = %s, want a metadata.host error", rr.Body.String())
	}
}

func TestPeripheralDelete_TombstonesLocally(t *testing.T) {
	s := newRecorderServer(t)
	s.db.Exec(`INSERT INTO peripheral_devices (id, name, type, is_active, pending_sync, pending_delete)
	           VALUES ('p1', 'P400', 'payment_terminal', 1, 0, 0)`)

	req := httptest.NewRequest(http.MethodDelete, "/api/peripheral-devices/p1", nil)
	req.SetPathValue("id", "p1")
	rr := httptest.NewRecorder()
	s.handlePeripheralDelete(rr, req)
	if rr.Code != http.StatusNoContent {
		t.Fatalf("delete = %d, want 204", rr.Code)
	}

	var pendingDelete int
	s.db.QueryRow(`SELECT pending_delete FROM peripheral_devices WHERE id='p1'`).Scan(&pendingDelete)
	if pendingDelete != 1 {
		t.Errorf("row not tombstoned (pending_delete=%d) — should be kept until synced", pendingDelete)
	}

	// And it disappears from the list immediately.
	lr := httptest.NewRecorder()
	s.handlePeripheralList(lr, httptest.NewRequest(http.MethodGet, "/api/peripheral-devices", nil))
	if strings.Contains(lr.Body.String(), "p1") {
		t.Errorf("tombstoned row still listed: %s", lr.Body.String())
	}
}
