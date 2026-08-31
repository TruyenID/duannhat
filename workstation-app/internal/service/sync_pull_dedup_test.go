package service

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

// TestPullSkipsLocallyCreatedOrder is the plan-041 regression for "order shows
// up twice in the local mirror". A workstation-created order keeps its LOCAL
// uuid as the primary key and gets cloud_id set on sync UP. The periodic pull
// keys rows by the CLOUD id, so without the dedup guard it inserts a SECOND row
// — duplicating the order (same order_code, two rows).
func TestPullSkipsLocallyCreatedOrder(t *testing.T) {
	_, db := newSyncTestEngine(t, "")
	p := NewSyncPuller(db, "", nil)

	// Locally-created + synced order: local pk != cloud id.
	mustExecF(t, db, `INSERT INTO orders (id, cloud_id, order_code, status, opened_at, created_at, updated_at)
		VALUES ('local-uuid','cloud-id','ORD-2026-0009','open',datetime('now'),datetime('now'),datetime('now'))`)

	// Periodic pull sees the same order by its CLOUD id — must NOT duplicate.
	if err := p.upsertOrder(cloudOrderPayload{ID: "cloud-id", OrderCode: "ORD-2026-0009", Status: "open", UpdatedAt: "2026-06-25T00:00:00Z"}, true); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}

	var n int
	db.QueryRow(`SELECT count(*) FROM orders WHERE order_code='ORD-2026-0009'`).Scan(&n)
	if n != 1 {
		t.Fatalf("got %d rows for ORD-2026-0009, want 1 (no duplicate)", n)
	}
	// The canonical row is the original local-created one (local uuid pk).
	var id string
	db.QueryRow(`SELECT id FROM orders WHERE order_code='ORD-2026-0009'`).Scan(&id)
	if id != "local-uuid" {
		t.Errorf("canonical row id = %q, want local-uuid (the local-created row)", id)
	}
}

// Cloud-origin orders (no local-created counterpart) must still pull normally
// under the periodic mirror, and the on-demand force-pull must always write.
func TestPullWritesCloudOriginAndForcePull(t *testing.T) {
	_, db := newSyncTestEngine(t, "")
	p := NewSyncPuller(db, "", nil)

	// (a) cloud-origin order (id == cloud id, no local row) — periodic pull
	//     writes it; the dedup guard does not match (id == order.ID).
	if err := p.upsertOrder(cloudOrderPayload{ID: "cloud-A", OrderCode: "ORD-2026-0010", Status: "open", UpdatedAt: "2026-06-25T00:00:00Z"}, true); err != nil {
		t.Fatalf("upsertOrder cloud-origin: %v", err)
	}
	var nA int
	db.QueryRow(`SELECT count(*) FROM orders WHERE id='cloud-A'`).Scan(&nA)
	if nA != 1 {
		t.Fatalf("cloud-origin order not written: %d rows, want 1", nA)
	}

	// (b) force-pull (skipLocallyOwned=false) for a genuinely-missing order —
	//     the print path relies on this always materialising the row.
	if err := p.upsertOrder(cloudOrderPayload{ID: "cloud-B", OrderCode: "ORD-2026-0011", Status: "open", UpdatedAt: "2026-06-25T00:00:00Z"}, false); err != nil {
		t.Fatalf("force-pull: %v", err)
	}
	var nB int
	db.QueryRow(`SELECT count(*) FROM orders WHERE id='cloud-B'`).Scan(&nB)
	if nB != 1 {
		t.Errorf("force-pull did not materialise cloud-B: %d rows, want 1", nB)
	}
}

// TestRecoverSkipsLocallyCreatedOrder — the re-pair recovery path has its own
// insert (not upsertOrder); it must apply the same dedup guard so restoring a
// crashed workstation doesn't duplicate orders it still holds locally.
func TestRecoverSkipsLocallyCreatedOrder(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[
			{"id":"cloud-1","order_code":"ORD-2026-0020","status":"open","order_type":"spot",
			 "total_amount":"0.00","subtotal":"0.00","tax_amount":"0.00","discount_amount":"0.00",
			 "service_charge":"0.00","total_tip":"0.00","paid_amount":"0.00",
			 "opened_at":"2026-06-25T08:00:00Z","created_at":"2026-06-25T08:00:00Z","updated_at":"2026-06-25T08:00:00Z",
			 "organization_id":"org-1","brand_id":"brand-1","branch_id":"branch-1"},
			{"id":"cloud-2","order_code":"ORD-2026-0021","status":"open","order_type":"spot",
			 "total_amount":"0.00","subtotal":"0.00","tax_amount":"0.00","discount_amount":"0.00",
			 "service_charge":"0.00","total_tip":"0.00","paid_amount":"0.00",
			 "opened_at":"2026-06-25T08:01:00Z","created_at":"2026-06-25T08:01:00Z","updated_at":"2026-06-25T08:01:00Z",
			 "organization_id":"org-1","brand_id":"brand-1","branch_id":"branch-1"}
		],"count":2}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	// Local-created twin of cloud-1 (local pk != cloud id).
	mustExecF(t, db, `INSERT INTO orders (id, cloud_id, order_code, status, opened_at, created_at, updated_at)
		VALUES ('local-1','cloud-1','ORD-2026-0020','open',datetime('now'),datetime('now'),datetime('now'))`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	n, err := p.Recover(context.Background(), 30*24*60*60*1_000_000_000) // 30 days in ns
	if err != nil {
		t.Fatalf("Recover: %v", err)
	}
	// cloud-1 skipped (local twin), only cloud-2 restored.
	if n != 1 {
		t.Fatalf("restored=%d, want 1 (cloud-1 skipped as local twin)", n)
	}
	var cnt int
	db.QueryRow(`SELECT count(*) FROM orders WHERE cloud_id='cloud-1'`).Scan(&cnt)
	if cnt != 1 {
		t.Errorf("cloud-1 rows=%d, want 1 (no duplicate)", cnt)
	}
	var id string
	db.QueryRow(`SELECT id FROM orders WHERE cloud_id='cloud-1'`).Scan(&id)
	if id != "local-1" {
		t.Errorf("cloud-1 canonical id=%q, want local-1 (local-created row preserved)", id)
	}
}
