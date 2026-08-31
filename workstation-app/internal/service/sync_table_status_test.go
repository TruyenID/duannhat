package service

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// The `table.status` sync op forwards a LAN table status change UP via
// POST /api/v1/workstation/tables/{id}/status. The table id is verbatim (the
// mirror keys on Cloud's id), and Cloud is authoritative — the handler adopts
// the returned status back onto the local mirror.
func TestHandleTableStatus_PostsToCloudAndAdopts(t *testing.T) {
	var gotPath string
	var gotBody map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		raw, _ := io.ReadAll(r.Body)
		gotBody = map[string]any{}
		_ = json.Unmarshal(raw, &gotBody)
		w.Header().Set("Content-Type", "application/json")
		// Cloud echoes its authoritative status.
		_, _ = w.Write([]byte(`{"data":{"id":"t1","code":"A-01","status":"cleaning"}}`))
	}))
	t.Cleanup(srv.Close)

	db, err := store.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	// Local mirror holds the optimistic value; Cloud will confirm 'cleaning'.
	if _, err := db.Exec(`INSERT INTO tables (id, name, status) VALUES ('t1','A-01','reserved')`); err != nil {
		t.Fatalf("seed table: %v", err)
	}

	engine := NewSyncEngine(db, srv.URL, nil)

	_, retryable, err := engine.handleTableStatus(t.Context(), "t1", map[string]any{"status": "cleaning"})
	if err != nil {
		t.Fatalf("handleTableStatus: err=%v retryable=%v", err, retryable)
	}
	if !strings.HasSuffix(gotPath, "/api/v1/workstation/tables/t1/status") {
		t.Errorf("path = %q, want .../tables/t1/status", gotPath)
	}
	if gotBody["status"] != "cleaning" {
		t.Errorf("body status = %v, want cleaning", gotBody["status"])
	}

	// Cloud's authoritative status is adopted onto the local mirror.
	var status string
	if err := db.QueryRow("SELECT status FROM tables WHERE id='t1'").Scan(&status); err != nil {
		t.Fatal(err)
	}
	if status != "cleaning" {
		t.Errorf("local mirror status = %q, want cleaning (adopted from Cloud)", status)
	}
}
