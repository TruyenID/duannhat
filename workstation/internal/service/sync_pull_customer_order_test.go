package service

import (
	"database/sql"
	"encoding/json"
	"testing"
)

// Coverage for the customer-web → workstation → pos-web realtime path.
//
// A customer-web order (QR dine-in or takeaway) is created in Cloud and reaches
// the workstation only through the 5 s `GET /workstation/orders?updated_since=`
// pull. Everything pos-web renders for that order therefore has to survive
// upsertOrder: the takeaway contact, the extras surcharge, the table binding —
// and, critically, a LAN broadcast, because pos-web disables list polling while
// the workstation socket is up.

func mkCustomerOrder() cloudOrderPayload {
	return cloudOrderPayload{
		ID: "cw-1", OrderCode: "ORD-0001", OrderType: "takeaway", Status: "confirmed",
		OpenedAt: "2026-07-21T10:00:00Z", UpdatedAt: "2026-07-21T10:00:00Z",
		BranchID: "br-1", BrandID: "bd-1", OrgID: "org-1",
		CustomerTakeawayName:  "Nguyen Van A",
		CustomerTakeawayPhone: "09012345678",
		CustomerID:            "cus-9",
		Subtotal:              json.Number("2080"),
		TotalAmount:           json.Number("2080"),
		Items: []cloudOrderItemPayload{{
			ID: "it-1", ProductSkuID: "sku-1",
			Quantity: json.Number("1"), UnitPrice: json.Number("1930"),
			ToppingSubtotal: json.Number("150"),
			Subtotal:        json.Number("2080"),
			Status:          "pending",
			UpdatedAt:       "2026-07-21T10:00:00Z",
		}},
	}
}

// The takeaway card in pos-web renders the customer's name + phone and its
// search box filters on them. Cloud has always serialized both; the pull used
// to drop them, so every customer-web takeaway order reached the counter
// anonymous.
func TestUpsertOrder_MirrorsTakeawayContactAndCustomer(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	if err := p.upsertOrder(mkCustomerOrder(), true); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}

	var name, phone, customerID sql.NullString
	if err := db.QueryRow(`
		SELECT customer_takeaway_name, customer_takeaway_phone, customer_id
		FROM orders WHERE id='cw-1'`).Scan(&name, &phone, &customerID); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if name.String != "Nguyen Van A" {
		t.Errorf("customer_takeaway_name = %q, want %q", name.String, "Nguyen Van A")
	}
	if phone.String != "09012345678" {
		t.Errorf("customer_takeaway_phone = %q, want %q", phone.String, "09012345678")
	}
	if customerID.String != "cus-9" {
		t.Errorf("customer_id = %q, want %q", customerID.String, "cus-9")
	}
}

// A later pull where Cloud omits the contact must not blank out what the
// workstation already shows on the counter screen.
func TestUpsertOrder_TakeawayContactNotBlankedByEmptyPayload(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	if err := p.upsertOrder(mkCustomerOrder(), true); err != nil {
		t.Fatalf("seed: %v", err)
	}

	stripped := mkCustomerOrder()
	stripped.CustomerTakeawayName = ""
	stripped.CustomerTakeawayPhone = ""
	stripped.CustomerID = ""
	stripped.UpdatedAt = "2026-07-21T10:05:00Z"
	if err := p.upsertOrder(stripped, true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}

	var name, phone, customerID sql.NullString
	db.QueryRow(`
		SELECT customer_takeaway_name, customer_takeaway_phone, customer_id
		FROM orders WHERE id='cw-1'`).Scan(&name, &phone, &customerID)
	if name.String != "Nguyen Van A" || phone.String != "09012345678" || customerID.String != "cus-9" {
		t.Errorf("contact was clobbered: %q / %q / %q", name.String, phone.String, customerID.String)
	}
}

// topping_subtotal is the per-unit extras surcharge the LAN cart shape and the
// tax breakdown rebuild the gross line total from. Dropping it made the POS
// cart disagree with the bill on any order with extras.
func TestUpsertOrder_MirrorsToppingSubtotal(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	if err := p.upsertOrder(mkCustomerOrder(), true); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}

	var toppingSubtotal int
	if err := db.QueryRow(`SELECT topping_subtotal FROM order_items WHERE id='it-1'`).
		Scan(&toppingSubtotal); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if toppingSubtotal != 150 {
		t.Errorf("topping_subtotal = %d, want 150", toppingSubtotal)
	}
}

// reconcileUnsyncedItems keys on (orders.cloud_id is neither NULL nor the
// empty string, AND
// order_items.synced_at IS NULL). A pulled-DOWN order satisfies the first half
// by construction (id == cloud_id), so leaving synced_at NULL echoed every
// cloud-origin line straight back UP to Cloud as an order.item_add.
func TestUpsertOrder_StampsItemSyncedAt(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	if err := p.upsertOrder(mkCustomerOrder(), true); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}

	var syncedAt sql.NullString
	if err := db.QueryRow(`SELECT synced_at FROM order_items WHERE id='it-1'`).Scan(&syncedAt); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if !syncedAt.Valid || syncedAt.String == "" {
		t.Fatal("pulled-down item left synced_at NULL — it will be echoed back UP to Cloud")
	}
}

// pos-web reads `tables[]` off the order_tables pivot (loadOrderTables), NOT
// orders.table_id. Before this the pivot was never written on pull, so a
// customer-web QR order rendered "Chưa có bàn" in the cart despite carrying the
// right table_id.
func TestUpsertOrder_MirrorsOrderTablesPivot(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	o := mkCustomerOrder()
	o.OrderType = "dine_in"
	o.TableID = "tbl-1"
	o.Tables = &[]cloudOrderTablePayload{{ID: "tbl-1"}, {ID: "tbl-2"}}
	if err := p.upsertOrder(o, true); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}

	var n int
	db.QueryRow(`SELECT COUNT(*) FROM order_tables WHERE order_id='cw-1'`).Scan(&n)
	if n != 2 {
		t.Fatalf("order_tables rows = %d, want 2", n)
	}

	// Tables released at checkout must disappear (replace-all mirror).
	o.Tables = &[]cloudOrderTablePayload{}
	o.UpdatedAt = "2026-07-21T10:10:00Z"
	if err := p.upsertOrder(o, true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}
	db.QueryRow(`SELECT COUNT(*) FROM order_tables WHERE order_id='cw-1'`).Scan(&n)
	if n != 0 {
		t.Errorf("released tables still bound: %d rows", n)
	}
}

// Nil `tables` (an older Cloud that omits the key) must leave a POS-created
// merge alone rather than unbinding its tables.
func TestUpsertOrder_NilTablesPreservesLocalPivot(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	o := mkCustomerOrder()
	o.Tables = &[]cloudOrderTablePayload{{ID: "tbl-1"}}
	if err := p.upsertOrder(o, true); err != nil {
		t.Fatalf("seed: %v", err)
	}

	o.Tables = nil
	o.UpdatedAt = "2026-07-21T10:10:00Z"
	if err := p.upsertOrder(o, true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}

	var n int
	db.QueryRow(`SELECT COUNT(*) FROM order_tables WHERE order_id='cw-1'`).Scan(&n)
	if n != 1 {
		t.Errorf("nil tables wiped the local pivot: %d rows", n)
	}
}

// The headline fix. pos-web turns list polling OFF while the workstation socket
// is up, so without a WS event a customer-web order lands in SQLite invisibly.
func TestUpsertOrder_BroadcastsOrderSyncedOnArrivalAndRealChange(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	type call struct {
		orderID, branchID string
		isNew             bool
	}
	var calls []call
	p.SetOnOrderSynced(func(orderID, branchID string, isNew bool) {
		calls = append(calls, call{orderID, branchID, isNew})
	})

	// 1. First arrival → order_created.
	if err := p.upsertOrder(mkCustomerOrder(), true); err != nil {
		t.Fatalf("arrival: %v", err)
	}
	if len(calls) != 1 {
		t.Fatalf("arrival: got %d callbacks, want 1", len(calls))
	}
	if !calls[0].isNew {
		t.Error("arrival should report isNew=true")
	}
	if calls[0].orderID != "cw-1" || calls[0].branchID != "br-1" {
		t.Errorf("arrival scoped wrong: %+v", calls[0])
	}

	// 2. Cursor-boundary re-pull with an unchanged updated_at → silence. The
	// cursor is second-precision and inclusive, so the newest order comes back
	// on every 5 s tick; re-broadcasting it would never stop.
	if err := p.upsertOrder(mkCustomerOrder(), true); err != nil {
		t.Fatalf("re-pull: %v", err)
	}
	if len(calls) != 1 {
		t.Fatalf("unchanged re-pull broadcast again: %d callbacks", len(calls))
	}

	// 3. A real change (customer added a round / paid) → order_updated.
	changed := mkCustomerOrder()
	changed.Status = "open"
	changed.UpdatedAt = "2026-07-21T10:07:00Z"
	if err := p.upsertOrder(changed, true); err != nil {
		t.Fatalf("update: %v", err)
	}
	if len(calls) != 2 {
		t.Fatalf("real change: got %d callbacks, want 2", len(calls))
	}
	if calls[1].isNew {
		t.Error("update should report isNew=false")
	}
}

// Cloud owns the order code for a cloud-origin order, but a partial payload
// must never blank the code pos-web is already showing on its tab.
func TestUpsertOrder_AdoptsOrderCodeButNeverBlanksIt(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	if err := p.upsertOrder(mkCustomerOrder(), true); err != nil {
		t.Fatalf("seed: %v", err)
	}

	renamed := mkCustomerOrder()
	renamed.OrderCode = "ORD-0042"
	renamed.UpdatedAt = "2026-07-21T10:05:00Z"
	if err := p.upsertOrder(renamed, true); err != nil {
		t.Fatalf("rename: %v", err)
	}
	var code string
	db.QueryRow(`SELECT order_code FROM orders WHERE id='cw-1'`).Scan(&code)
	if code != "ORD-0042" {
		t.Errorf("order_code = %q, want ORD-0042", code)
	}

	blank := mkCustomerOrder()
	blank.OrderCode = ""
	blank.UpdatedAt = "2026-07-21T10:06:00Z"
	if err := p.upsertOrder(blank, true); err != nil {
		t.Fatalf("blank: %v", err)
	}
	db.QueryRow(`SELECT order_code FROM orders WHERE id='cw-1'`).Scan(&code)
	if code != "ORD-0042" {
		t.Errorf("blank payload clobbered order_code: %q", code)
	}
}
