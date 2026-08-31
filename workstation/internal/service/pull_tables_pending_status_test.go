package service

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

// A pos-web LAN table status change is applied to the local `tables` mirror
// optimistically and enqueued as a `table.status` op. Until that op syncs UP to
// Cloud, the destructive PullTables re-mirror (which reflects Cloud's still-old
// value) must NOT revert it. Once the op is marked synced, Cloud wins again.
func TestPullTables_PreservesUnsyncedStatusChange(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		// Cloud still reports the OLD status — the UP hasn't landed yet.
		w.Write([]byte(`{"data":[
			{"id":"t-pending","code":"T1","status":"free","zone_id":"z1","seat_count":4,"qr_token":"q1"}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	// Cashier set it to out_of_service locally; the sync op is still pending.
	if _, err := db.Exec(`INSERT INTO tables (id, name, status) VALUES ('t-pending','T1','out_of_service')`); err != nil {
		t.Fatalf("seed table: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO sync_queue (entity_type, entity_id, operation, payload, priority)
		VALUES ('table','t-pending','status','{"status":"out_of_service"}', 2)`); err != nil {
		t.Fatalf("seed sync_queue: %v", err)
	}

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullTables(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var status string
	if err := db.QueryRow("SELECT status FROM tables WHERE id='t-pending'").Scan(&status); err != nil {
		t.Fatal(err)
	}
	if status != "out_of_service" {
		t.Fatalf("unsynced LAN status must survive the pull, got %q", status)
	}

	// Mark the op synced — the preserve no longer applies, Cloud's value wins.
	if _, err := db.Exec("UPDATE sync_queue SET synced_at = datetime('now') WHERE entity_id='t-pending'"); err != nil {
		t.Fatal(err)
	}
	if err := p.PullTables(context.Background()); err != nil {
		t.Fatalf("pull 2: %v", err)
	}
	if err := db.QueryRow("SELECT status FROM tables WHERE id='t-pending'").Scan(&status); err != nil {
		t.Fatal(err)
	}
	if status != "free" {
		t.Fatalf("after the op syncs, Cloud status must win, got %q", status)
	}
}
