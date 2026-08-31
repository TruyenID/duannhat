package service

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// serveOrders spins up a fake Cloud that returns the given orders JSON body for
// the customer-orders pull.
func serveOrders(t *testing.T, body string) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(body))
	}))
}

// A single pull tick returning a BULK of orders (a Cloud re-seed / backfill)
// must still mirror the rows and broadcast them to LAN clients, but must NOT
// auto-fire the arrived/paid print hooks — the bulk-count guard (#141). This is
// the layer the opened_at age gate can't cover (a re-seed stamps opened_at in
// the recent past, and the merge/"add more" path can't be age-gated safely).
func TestPullCustomerOrders_BulkPullSuppressesAutoPrint(t *testing.T) {
	// 6 open orders in one response — over the default bulk max of 5.
	rows := make([]string, 0, 6)
	for i := range 6 {
		rows = append(rows, fmt.Sprintf(`{"id":"seed-%d","status":"open","order_type":"dine_in",
			"opened_at":"2026-07-01T07:00:00Z","updated_at":"2026-07-01T07:05:00Z","branch_id":"branch-1",
			"items":[{"id":"seed-%d-i1","quantity":"1","unit_price":"100.00","subtotal":"100.00","status":"pending"}]}`, i, i))
	}
	cloud := serveOrders(t, `{"data":[`+strings.Join(rows, ",")+`],"count":6}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))

	var arrived, synced int
	p.SetOnOrderArrived(func(_, _, _ string) { arrived++ })
	p.SetOnOrderSynced(func(_, _ string, _ bool) { synced++ })

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	if arrived != 0 {
		t.Fatalf("bulk pull must suppress auto-print; onOrderArrived fired %d times", arrived)
	}
	// The orders are still mirrored to SQLite...
	var n int
	_ = db.QueryRow(`SELECT COUNT(*) FROM orders WHERE id LIKE 'seed-%'`).Scan(&n)
	if n != 6 {
		t.Fatalf("bulk pull must still mirror orders; got %d rows, want 6", n)
	}
	// ...and still broadcast to LAN clients (pos-web / KDS) so nothing goes invisible.
	if synced != 6 {
		t.Fatalf("bulk pull must still broadcast onOrderSynced; got %d, want 6", synced)
	}
}

// A tick packed with plain RE-PULLS (orders already known locally, unchanged
// status) plus ONE genuinely new/transitioning order must NOT be treated as a
// bulk — the guard counts firing events, not raw tick size, so the lone live
// order still auto-prints. Regression for #141 where a live payment riding a
// re-pull-heavy tick was silently suppressed.
func TestPullCustomerOrders_RepullsDontSuppressLiveOrder(t *testing.T) {
	var body string
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(body))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))

	// Round 1: land 7 open orders in SQLite (fresh DB → all new → suppressed,
	// but they persist). We don't assert on this round.
	rows := make([]string, 0, 7)
	for i := range 7 {
		rows = append(rows, fmt.Sprintf(`{"id":"rep-%d","status":"open","order_type":"dine_in",
			"opened_at":"2026-07-01T07:00:00Z","updated_at":"2026-07-01T07:05:00Z","branch_id":"b1",
			"items":[{"id":"rep-%d-i","quantity":"1","unit_price":"100.00","subtotal":"100.00","status":"pending"}]}`, i, i))
	}
	body = `{"data":[` + strings.Join(rows, ",") + `],"count":7}`
	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("round 1: %v", err)
	}

	// Round 2: the SAME 7 re-pulled unchanged + 1 brand-new order → firingCount=1.
	var arrived int
	p.SetOnOrderArrived(func(_, _, _ string) { arrived++ })
	newOrder := `{"id":"live-x","status":"open","order_type":"dine_in",
		"opened_at":"2026-07-01T07:00:00Z","updated_at":"2026-07-01T08:00:00Z","branch_id":"b1",
		"items":[{"id":"live-x-i","quantity":"1","unit_price":"100.00","subtotal":"100.00","status":"pending"}]}`
	body = `{"data":[` + strings.Join(rows, ",") + `,` + newOrder + `],"count":8}`
	_, _ = db.Exec("DELETE FROM settings WHERE key='sync.customer_orders.last_pulled'")
	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("round 2: %v", err)
	}

	if arrived != 1 {
		t.Fatalf("re-pull-heavy tick must not suppress the lone live order; onOrderArrived fired %d, want 1", arrived)
	}
}

// A normal-sized tick (at/under the bulk max) auto-fires as usual — the guard
// only trips on a bulk, so live one-at-a-time orders are unaffected.
func TestPullCustomerOrders_SmallBatchStillAutoPrints(t *testing.T) {
	cloud := serveOrders(t, `{"data":[
		{"id":"live-1","status":"open","order_type":"dine_in",
		 "opened_at":"2026-07-01T07:00:00Z","updated_at":"2026-07-01T07:05:00Z","branch_id":"branch-1",
		 "items":[{"id":"live-1-i1","quantity":"1","unit_price":"100.00","subtotal":"100.00","status":"pending"}]}
	],"count":1}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))

	var arrived int
	p.SetOnOrderArrived(func(_, _, _ string) { arrived++ })

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	if arrived != 1 {
		t.Fatalf("small batch must auto-print; onOrderArrived fired %d times, want 1", arrived)
	}
}

func TestPullCustomerOrders_BatchSnapshotBoundsReadQueries(t *testing.T) {
	// Sixty orders are enough to expose the old per-row ladder (>360 SELECTs),
	// while keeping this regression test cheap. The replacement performs one
	// query per snapshot relation/chunk, independent of order/item count.
	rows := make([]string, 0, 60)
	for i := range 60 {
		rows = append(rows, fmt.Sprintf(`{"id":"budget-%d","status":"open","order_type":"dine_in",
			"opened_at":"2026-07-01T07:00:00Z","updated_at":"2026-07-01T07:05:00Z","branch_id":"branch-1",
			"items":[{"id":"budget-%d-i1","quantity":"1","unit_price":"100.00","subtotal":"100.00","status":"pending"}]}`, i, i))
	}
	cloud := serveOrders(t, `{"data":[`+strings.Join(rows, ",")+`],"count":60}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	before := db.Diagnostics()

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	after := db.Diagnostics()
	readQueries := after.QueryCount - before.QueryCount
	if readQueries > 20 {
		t.Fatalf("60-order pull used %d read queries, want <=20 chunked reads", readQueries)
	}
	// Keep the existing partial-progress contract: one malformed order must not
	// roll back every good row in the page. We optimize reads, not this boundary.
	if writes := after.TransactionCount - before.TransactionCount; writes != 60 {
		t.Fatalf("write transactions = %d, want one isolated transaction per order", writes)
	}
}

// The bulk threshold is configurable via settings['auto_print_bulk_max'];
// lowering it makes a smaller tick count as a bulk.
func TestAutoPrintBulkMax_SettingOverride(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://unused", staticTokenFn("t"))

	if got := p.autoPrintBulkMax(); got != 5 {
		t.Fatalf("unset: want default 5, got %d", got)
	}
	for _, bad := range []string{"", "abc", "0", "-3"} {
		_, _ = db.Exec(`INSERT INTO settings (key,value) VALUES ('auto_print_bulk_max',?)
			ON CONFLICT(key) DO UPDATE SET value=excluded.value`, bad)
		if got := p.autoPrintBulkMax(); got != 5 {
			t.Fatalf("invalid %q: want default 5, got %d", bad, got)
		}
	}
	_, _ = db.Exec(`INSERT INTO settings (key,value) VALUES ('auto_print_bulk_max','2')
		ON CONFLICT(key) DO UPDATE SET value=excluded.value`)
	if got := p.autoPrintBulkMax(); got != 2 {
		t.Fatalf(`"2": want 2, got %d`, got)
	}
}
