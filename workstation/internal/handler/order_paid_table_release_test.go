package handler

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/audit"
	"github.com/dxs-platform/workstation-app/internal/monitor"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// #2564 — a dine-in order paid through a gateway webhook (PayPay/Stripe) is
// confirmed entirely on Cloud: the POS confirm request never lands locally
// (it falls through the /api/v1/pos/ catch-all proxy to Cloud), so the ONLY
// place the workstation learns the order closed is the pull-DOWN loop's
// onOrderPaid hook (wired in New(), server.go). Before this fix that hook
// only drove auto-print — the table stayed "occupied" and no WS event told
// pos-web to refetch, so the cashier saw a paid table sitting occupied
// indefinitely.
//
// Unlike pullServer() in lan_print_receipt_guard_test.go — which REPLACES
// s.puller with a bare SyncPuller and so never exercises New()'s wiring —
// this test builds the server through New() itself and lets the real 5s
// kitchen-loop tick fire the hook, so it actually covers server.go:301-320.
func TestOnOrderPaid_PullDown_ReleasesTableAndBroadcasts(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "order-paid.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/api/v1/workstation/orders" {
			fmt.Fprint(w, `{"data":[{
				"id":"ord-paypay","order_code":"O-PP","order_type":"dine_in",
				"status":"closed","opened_at":"2026-07-31T03:00:00Z","updated_at":"2026-07-31T03:00:00Z",
				"branch_id":"branch-A","table_id":"table-1","paid_amount":"3000","total_amount":"3000",
				"tables":[{"id":"table-1"}]
			}],"count":1,"generated_at":"2026-07-31T04:00:00Z"}`)
			return
		}
		fmt.Fprint(w, `{"data":{"id":"kiosk-1","type":"kiosk","branch_id":"branch-A","status":"active"}}`)
	}))
	t.Cleanup(cloud.Close)

	for _, seed := range []struct{ key, value string }{
		{"cloud_api_url", cloud.URL},
		{"workstation_branch_id", "branch-A"},
		{"device_token", "WS-TOKEN"},
	} {
		if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)`, seed.key, seed.value); err != nil {
			t.Fatalf("seed %s: %v", seed.key, err)
		}
	}
	if _, err := db.Exec(`INSERT INTO tables (id, name, status) VALUES ('table-1','B-01','occupied')`); err != nil {
		t.Fatalf("seed table: %v", err)
	}

	s := New(Dependencies{
		DB:      db,
		Orders:  service.NewOrderEngine(db),
		Sync:    service.NewSyncEngine(db, cloud.URL, func(service.ConnStatus) {}),
		Audit:   audit.NewLogger(db),
		Monitor: monitor.New(nil),
	})
	t.Cleanup(s.stopBackground)

	s.startBackground() // starts s.hub.Run() + s.puller.Start() (5s kitchen-loop tick)

	// A pos-web client bound to branch-A — what order_paid must reach.
	// Registered only after hub.Run() is live so the register channel has a reader.
	client := &Client{authedBy: "user:cashier-1", branchID: "branch-A", send: make(chan []byte, 4)}
	s.hub.register <- client

	// New() also wires alert-centre + settings broadcasts that can land on this
	// same client before order_paid does (e.g. alert.resolved on startup) — drain
	// until the frame we care about shows up, ignoring unrelated event types.
	deadline := time.After(8 * time.Second)
	found := false
	for !found {
		select {
		case msg := <-client.send:
			if strings.Contains(string(msg), `"type":"order_paid"`) {
				found = true
			}
		case <-deadline:
			t.Fatal("timed out waiting for order_paid broadcast after pull-down close")
		}
	}

	if got := tableStatus(t, s, "table-1"); got != "free" {
		t.Fatalf("table status after pull-down paid: want free, got %q", got)
	}
}
