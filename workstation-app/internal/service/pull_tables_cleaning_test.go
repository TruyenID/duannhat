package service

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

// PullTables destructively re-mirrors the whole tables set every tick. A table
// the workstation put into `cleaning` locally after a LAN close (before that
// close reached Cloud) must NOT be clobbered back to `free` when the next pull
// runs — otherwise paying ANY other table's order flips a mid-clean table to
// free. A genuine re-occupation on Cloud still wins.
func TestPullTables_PreservesLocalCleaning(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":[
			{"id":"t-clean","code":"T1","status":"free","zone_id":"z1","seat_count":4,"qr_token":"q1"},
			{"id":"t-busy","code":"T2","status":"occupied","zone_id":"z1","seat_count":2,"qr_token":"q2"}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	// Workstation set t-clean to `cleaning` locally after its payment closed;
	// Cloud still reports it `free` (the close hasn't landed there yet).
	if _, err := db.Exec(`INSERT INTO tables (id, name, status) VALUES ('t-clean','T1','cleaning')`); err != nil {
		t.Fatalf("seed: %v", err)
	}

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullTables(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var cleanStatus, busyStatus string
	if err := db.QueryRow("SELECT status FROM tables WHERE id='t-clean'").Scan(&cleanStatus); err != nil {
		t.Fatal(err)
	}
	if err := db.QueryRow("SELECT status FROM tables WHERE id='t-busy'").Scan(&busyStatus); err != nil {
		t.Fatal(err)
	}
	if cleanStatus != "cleaning" {
		t.Fatalf("cleaning must survive a pull when Cloud=free, got %q", cleanStatus)
	}
	if busyStatus != "occupied" {
		t.Fatalf("a re-occupied table must take the Cloud status, got %q", busyStatus)
	}
}
