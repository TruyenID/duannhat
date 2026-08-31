package service

import "testing"

// TestPullDoesNotReopenLocallyClosedOrder is the regression for "local status
// reverted to open": the workstation closes an order on a full local payment,
// but the 60s customer-order mirror still sees Cloud's stale 'open'. Order
// lifecycle is WS-owned, so the pull must keep the local 'closed'. Any other
// transition from Cloud is still adopted.
func TestPullDoesNotReopenLocallyClosedOrder(t *testing.T) {
	_, db := newSyncTestEngine(t, "")
	p := NewSyncPuller(db, "", nil)

	mustExecF(t, db, `INSERT INTO orders (id, cloud_id, order_code, status, opened_at, created_at, updated_at)
		VALUES ('o1','o1','ORD-1','closed',datetime('now'),datetime('now'),datetime('now'))`)

	// Cloud mirror still says 'open' → must NOT reopen the locally-closed order.
	if err := p.upsertOrder(cloudOrderPayload{ID: "o1", OrderCode: "ORD-1", Status: "open", UpdatedAt: "2026-01-01T00:00:00Z"}, true); err != nil {
		t.Fatalf("upsertOrder open: %v", err)
	}
	var status string
	db.QueryRow(`SELECT status FROM orders WHERE id='o1'`).Scan(&status)
	if status != "closed" {
		t.Fatalf("status = %q after pull 'open', want closed (pull must not reopen)", status)
	}

	// Cloud 'voided' IS a legitimate downgrade and must be adopted.
	if err := p.upsertOrder(cloudOrderPayload{ID: "o1", OrderCode: "ORD-1", Status: "voided", UpdatedAt: "2026-01-02T00:00:00Z"}, true); err != nil {
		t.Fatalf("upsertOrder voided: %v", err)
	}
	db.QueryRow(`SELECT status FROM orders WHERE id='o1'`).Scan(&status)
	if status != "voided" {
		t.Errorf("status = %q after pull 'voided', want voided (legit transition adopted)", status)
	}
}
