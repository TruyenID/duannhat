package service

import (
	"testing"
)

// Pending-local-mutation guard — the "served flips back to pending" bug.
//
// A cashier bumps a line (or edits qty/note) on a Cloud-origin order; the
// change sits in sync_queue for up to a few seconds before it lands on Cloud.
// The 5s pullCustomerOrders tick used to re-adopt Cloud's PRE-edit copy in
// that window, clobbering the local edit and broadcasting the stale status —
// pos-web rendered the revert almost instantly. upsertOrder must preserve the
// locally-edited fields while (and only while) the edit's queue row is
// unsynced and alive.

func seedPreserveOrder(t *testing.T, p *SyncPuller) {
	t.Helper()
	if err := p.upsertOrder(mkCustomerOrder(), true); err != nil {
		t.Fatalf("seed upsertOrder: %v", err)
	}
}

func queueRow(t *testing.T, p *SyncPuller, entityType, entityID, operation, payload, idem string) {
	t.Helper()
	if _, err := p.db.Exec(`
		INSERT INTO sync_queue (entity_type, entity_id, operation, payload, idempotency_key, priority)
		VALUES (?, ?, ?, ?, ?, 2)`,
		entityType, entityID, operation, payload, idem); err != nil {
		t.Fatalf("insert sync_queue: %v", err)
	}
}

func TestUpsertOrder_PreservesLocalStatusWhileBumpUnsynced(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))
	seedPreserveOrder(t, p)

	// Cashier bumps the line to served; the bump's queue row is still unsynced.
	if _, err := db.Exec(`
		UPDATE order_items SET status='served', served_at='2026-07-21T10:01:00Z' WHERE id='it-1'`); err != nil {
		t.Fatal(err)
	}
	queueRow(t, p, "customer_order_item", "it-1", "update_status",
		`{"order_id":"cw-1","item_id":"it-1","status":"served"}`, "idem-p1")

	// Next tick still carries Cloud's pre-bump copy (pending).
	stale := mkCustomerOrder()
	stale.UpdatedAt = "2026-07-21T10:02:00Z"
	if err := p.upsertOrder(stale, true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}

	var status, servedAt string
	db.QueryRow(`SELECT status, COALESCE(served_at,'') FROM order_items WHERE id='it-1'`).
		Scan(&status, &servedAt)
	if status != "served" {
		t.Errorf("local served must survive the pull while the bump is unsynced, got %q", status)
	}
	if servedAt == "" {
		t.Errorf("served_at must survive alongside the preserved status")
	}
}

func TestUpsertOrder_AdoptsCloudStatusOnceBumpSynced(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))
	seedPreserveOrder(t, p)

	if _, err := db.Exec(`UPDATE order_items SET status='served' WHERE id='it-1'`); err != nil {
		t.Fatal(err)
	}
	queueRow(t, p, "customer_order_item", "it-1", "update_status",
		`{"order_id":"cw-1","item_id":"it-1","status":"served"}`, "idem-p2")
	if _, err := db.Exec(`UPDATE sync_queue SET synced_at='2026-07-21T10:03:00Z' WHERE idempotency_key='idem-p2'`); err != nil {
		t.Fatal(err)
	}

	// Op synced → Cloud is authoritative again, even if this payload is stale.
	stale := mkCustomerOrder()
	stale.UpdatedAt = "2026-07-21T10:04:00Z"
	if err := p.upsertOrder(stale, true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}

	var status string
	db.QueryRow(`SELECT status FROM order_items WHERE id='it-1'`).Scan(&status)
	if status != "pending" {
		t.Errorf("once the op synced Cloud wins: want pending, got %q", status)
	}
}

// A Cloud-side void always wins — the in-flight bump will 409 on Cloud and the
// KDS sync handler reverts the local row, so refusing the void here would only
// delay the inevitable and hide a cancelled dish from the counter.
func TestUpsertOrder_CloudVoidBeatsUnsyncedBump(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))
	seedPreserveOrder(t, p)

	if _, err := db.Exec(`UPDATE order_items SET status='served' WHERE id='it-1'`); err != nil {
		t.Fatal(err)
	}
	queueRow(t, p, "customer_order_item", "it-1", "update_status",
		`{"order_id":"cw-1","item_id":"it-1","status":"served"}`, "idem-p3")

	voided := mkCustomerOrder()
	voided.Items[0].Status = "voided"
	voided.Items[0].VoidedAt = "2026-07-21T10:05:00Z"
	voided.UpdatedAt = "2026-07-21T10:05:00Z"
	if err := p.upsertOrder(voided, true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}

	var status string
	db.QueryRow(`SELECT status FROM order_items WHERE id='it-1'`).Scan(&status)
	if status != "voided" {
		t.Errorf("cloud void must override the preservation guard, got %q", status)
	}
}

func TestUpsertOrder_PreservesQtyNoteWhileItemUpdateUnsynced(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))
	seedPreserveOrder(t, p)

	if _, err := db.Exec(`
		UPDATE order_items SET quantity=5, note='it cay' WHERE id='it-1'`); err != nil {
		t.Fatal(err)
	}
	queueRow(t, p, "order", "cw-1", "item_update",
		`{"item_id":"it-1","patch":{"quantity":5,"note":"it cay"}}`, "idem-p4")

	stale := mkCustomerOrder() // Cloud still has qty=1, no note
	stale.UpdatedAt = "2026-07-21T10:06:00Z"
	if err := p.upsertOrder(stale, true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}

	var qty int
	var note string
	db.QueryRow(`SELECT quantity, COALESCE(note,'') FROM order_items WHERE id='it-1'`).
		Scan(&qty, &note)
	if qty != 5 {
		t.Errorf("locally-edited quantity must survive the pull, got %d", qty)
	}
	if note != "it cay" {
		t.Errorf("locally-edited note must survive the pull, got %q", note)
	}
	// Fields the patch does NOT touch stay Cloud-authoritative: status was
	// never edited locally, so Cloud's value rules.
	var status string
	db.QueryRow(`SELECT status FROM order_items WHERE id='it-1'`).Scan(&status)
	if status != "pending" {
		t.Errorf("untouched fields adopt Cloud: want pending, got %q", status)
	}
}

func TestUpsertOrder_MergesSeveralPendingPatchesForOneItem(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))
	seedPreserveOrder(t, p)

	if _, err := db.Exec(`UPDATE order_items SET quantity=5, note='it cay' WHERE id='it-1'`); err != nil {
		t.Fatal(err)
	}
	// Two queue rows may touch disjoint fields before the next push tick. The
	// batch snapshot must OR their guards, never let the later row clear a flag
	// discovered on the earlier one.
	queueRow(t, p, "order", "cw-1", "item_update",
		`{"item_id":"it-1","patch":{"quantity":5}}`, "idem-p4-qty")
	queueRow(t, p, "order", "cw-1", "item_update",
		`{"item_id":"it-1","patch":{"note":"it cay"}}`, "idem-p4-note")

	stale := mkCustomerOrder()
	stale.UpdatedAt = "2026-07-21T10:06:30Z"
	if err := p.upsertOrder(stale, true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}

	var qty int
	var note string
	db.QueryRow(`SELECT quantity, COALESCE(note,'') FROM order_items WHERE id='it-1'`).
		Scan(&qty, &note)
	if qty != 5 || note != "it cay" {
		t.Fatalf("merged pending guards lost a field: qty=%d note=%q", qty, note)
	}
}

// The accepted order itself: local `open` (order.confirm unsynced) must not
// snap back to `confirmed` — that hides the checkout CTA mid-flow.
func TestUpsertOrder_KeepsOpenWhileConfirmUnsynced(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))
	seedPreserveOrder(t, p) // Cloud copy arrives `confirmed`

	if _, err := db.Exec(`UPDATE orders SET status='open' WHERE id='cw-1'`); err != nil {
		t.Fatal(err)
	}
	queueRow(t, p, "order", "cw-1", "confirm", `{}`, "idem-p5")

	stale := mkCustomerOrder() // still `confirmed` on Cloud
	stale.UpdatedAt = "2026-07-21T10:07:00Z"
	if err := p.upsertOrder(stale, true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}

	var status string
	db.QueryRow(`SELECT status FROM orders WHERE id='cw-1'`).Scan(&status)
	if status != "open" {
		t.Errorf("accepted order must stay open while order.confirm is unsynced, got %q", status)
	}

	// Once the confirm op lands, Cloud rules again (it reports open by then;
	// a genuinely stale confirmed payload may briefly win — and the next tick
	// carries Cloud's true state, so the mirror converges).
	if _, err := db.Exec(`UPDATE sync_queue SET synced_at='2026-07-21T10:08:00Z' WHERE idempotency_key='idem-p5'`); err != nil {
		t.Fatal(err)
	}
	again := mkCustomerOrder()
	again.Status = "open"
	again.UpdatedAt = "2026-07-21T10:09:00Z"
	if err := p.upsertOrder(again, true); err != nil {
		t.Fatalf("re-pull 2: %v", err)
	}
	db.QueryRow(`SELECT status FROM orders WHERE id='cw-1'`).Scan(&status)
	if status != "open" {
		t.Errorf("post-sync pull with Cloud open must keep open, got %q", status)
	}
}

// A locally-voided line (order.item_void unsynced) must not be resurrected by
// Cloud's pre-void copy.
func TestUpsertOrder_PreservesLocalVoidWhileOpUnsynced(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))
	seedPreserveOrder(t, p)

	if _, err := db.Exec(`
		UPDATE order_items SET status='voided', voided_at='2026-07-21T10:01:00Z',
		       void_reason='khach doi mon' WHERE id='it-1'`); err != nil {
		t.Fatal(err)
	}
	queueRow(t, p, "order", "cw-1", "item_void",
		`{"item_id":"it-1","void_reason":"khach doi mon"}`, "idem-p7")

	stale := mkCustomerOrder() // Cloud still has the line active (pending)
	stale.UpdatedAt = "2026-07-21T10:02:30Z"
	if err := p.upsertOrder(stale, true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}

	var status, voidedAt string
	db.QueryRow(`SELECT status, COALESCE(voided_at,'') FROM order_items WHERE id='it-1'`).
		Scan(&status, &voidedAt)
	if status != "voided" {
		t.Errorf("local void must survive the pull, got %q", status)
	}
	if voidedAt == "" {
		t.Errorf("voided_at must survive alongside the preserved void")
	}
}

// The same guard covers the checkout transition: local `checkout` with an
// unsynced order.checkout op must not snap back to Cloud's `open`.
func TestUpsertOrder_KeepsCheckoutWhileOpUnsynced(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))
	seedPreserveOrder(t, p)

	if _, err := db.Exec(`UPDATE orders SET status='checkout' WHERE id='cw-1'`); err != nil {
		t.Fatal(err)
	}
	queueRow(t, p, "order", "cw-1", "checkout", `{}`, "idem-p8")

	stale := mkCustomerOrder()
	stale.Status = "open" // Cloud lags behind the local checkout
	stale.UpdatedAt = "2026-07-21T10:03:30Z"
	if err := p.upsertOrder(stale, true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}

	var status string
	db.QueryRow(`SELECT status FROM orders WHERE id='cw-1'`).Scan(&status)
	if status != "checkout" {
		t.Errorf("local checkout must survive while the op is unsynced, got %q", status)
	}

	// Cloud's terminal states always win regardless of the guard.
	closed := mkCustomerOrder()
	closed.Status = "closed"
	closed.UpdatedAt = "2026-07-21T10:04:30Z"
	if err := p.upsertOrder(closed, true); err != nil {
		t.Fatalf("re-pull closed: %v", err)
	}
	db.QueryRow(`SELECT status FROM orders WHERE id='cw-1'`).Scan(&status)
	if status != "closed" {
		t.Errorf("cloud closed must override the guard, got %q", status)
	}
}

// Guard against JSON shape drift: the queue payload written by
// handleLocalPosUpdateItem nests the patch under "patch" — make sure a
// toppings-carrying patch flags the item so replace-all does not wipe the
// local toppings while the edit is unsynced.
func TestUpsertOrder_PreservesToppingsWhileItemUpdateUnsynced(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))
	seedPreserveOrder(t, p)

	// Local topping row from an unsynced edit.
	if _, err := db.Exec(`
		INSERT INTO order_item_toppings (id, order_item_id, topping_group_item_id, product_sku_id, quantity, unit_price, created_at)
		VALUES ('top-1', 'it-1', 'tgi-1', 'sku-top', 1, 150, '2026-07-21T10:00:30Z')`); err != nil {
		t.Fatal(err)
	}
	queueRow(t, p, "order", "cw-1", "item_update",
		`{"item_id":"it-1","patch":{"toppings":[{"topping_group_item_id":"tgi-1","product_sku_id":"sku-top","quantity":1}]}}`, "idem-p6")

	// Cloud payload carries an EMPTY toppings list (pre-edit state).
	stale := mkCustomerOrder()
	empty := []cloudOrderItemToppingPayload{}
	stale.Items[0].Toppings = &empty
	stale.UpdatedAt = "2026-07-21T10:07:30Z"
	if err := p.upsertOrder(stale, true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}

	var count int
	db.QueryRow(`SELECT COUNT(*) FROM order_item_toppings WHERE order_item_id='it-1'`).Scan(&count)
	if count != 1 {
		t.Errorf("local topping must survive replace-all while the edit is unsynced, got %d rows", count)
	}
}
