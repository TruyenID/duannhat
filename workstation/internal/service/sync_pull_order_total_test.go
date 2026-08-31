package service

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

// The 5s pullCustomerOrders tick must persist the order header's monetary
// fields, not just the items. It used to drop total_amount/subtotal/tax/paid,
// so cloud-synced (kiosk/customer) orders landed with a 0 total and receipts
// printed 0.
func TestPullCustomerOrders_PersistsHeaderTotals(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/orders" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[
			{"id":"ord-1","order_code":"O-1","status":"closed","order_type":"dine_in",
			 "total_amount":"4595.00","subtotal":"3995.00","tax_amount":"400.00",
			 "discount_amount":"0.00","total_tip":"0.00","paid_amount":"4595.00",
			 "opened_at":"2026-07-01T07:18:00Z","updated_at":"2026-07-01T07:19:00Z",
			 "organization_id":"org-1","brand_id":"brand-1","branch_id":"branch-1",
			 "items":[{"id":"it-1","quantity":"2","unit_price":"1997.00","subtotal":"3995.00","status":"pending"}]}
		],"count":1}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pullCustomerOrders: %v", err)
	}

	var total, subtotal, tax, paid int
	if err := db.QueryRow(
		`SELECT total_amount, subtotal, tax_amount, paid_amount FROM orders WHERE id='ord-1'`,
	).Scan(&total, &subtotal, &tax, &paid); err != nil {
		t.Fatalf("query order: %v", err)
	}
	if total != 4595 || subtotal != 3995 || tax != 400 || paid != 4595 {
		t.Fatalf("header monetary fields not persisted: total=%d subtotal=%d tax=%d paid=%d (want 4595/3995/400/4595)",
			total, subtotal, tax, paid)
	}

	var itemCount int
	_ = db.QueryRow(`SELECT COUNT(*) FROM order_items WHERE customer_order_id='ord-1'`).Scan(&itemCount)
	if itemCount != 1 {
		t.Fatalf("expected 1 item ingested, got %d", itemCount)
	}
}

// A paid (closed) order arriving via pull-down must fire onOrderPaid exactly
// once — on the transition into closed — so the workstation auto-prints the
// receipt for a Cloud-confirmed kiosk/customer payment without reprinting it
// on every re-pull.
func TestPullCustomerOrders_AutoPrintsReceiptOnceOnClose(t *testing.T) {
	body := `{"data":[
		{"id":"ord-paid","status":"closed","order_type":"dine_in",
		 "total_amount":"1173.00","paid_amount":"1173.00","subtotal":"1080.00","tax_amount":"93.00",
		 "opened_at":"2026-07-01T07:00:00Z","updated_at":"2026-07-01T07:05:00Z","branch_id":"branch-1",
		 "items":[{"id":"i1","quantity":"1","unit_price":"1080.00","subtotal":"1080.00","status":"served"}]}
	],"count":1}`
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(body))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))

	var printed []string
	var printedAmt int
	var printedBranch string
	p.SetOnOrderPaid(func(orderID, branchID string, amount int) {
		printed = append(printed, orderID)
		printedAmt = amount
		printedBranch = branchID
	})

	// First pull: brand-new closed order → auto-print fires once.
	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull 1: %v", err)
	}
	if len(printed) != 1 || printed[0] != "ord-paid" {
		t.Fatalf("expected 1 auto-print for ord-paid, got %v", printed)
	}
	if printedAmt != 1173 {
		t.Fatalf("expected paid amount 1173, got %d", printedAmt)
	}
	// #2564 — branchID must ride along so the callback can scope its table
	// release + WS broadcast to the paid order's own branch.
	if printedBranch != "branch-1" {
		t.Fatalf("expected branch_id branch-1, got %q", printedBranch)
	}

	// Re-pull the same (already-closed) order — must NOT reprint.
	_, _ = db.Exec("DELETE FROM settings WHERE key='sync.customer_orders.last_pulled'")
	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull 2: %v", err)
	}
	if len(printed) != 1 {
		t.Fatalf("re-pull of an already-closed order must not reprint; printed=%v", printed)
	}
}
