package service

import (
	"errors"
	"testing"
)

// setAllowItemEditAnyStatus flips the shop_settings key the engine reads.
func setAllowItemEditAnyStatus(t *testing.T, eng *OrderEngine, on bool) {
	t.Helper()
	val := "false"
	if on {
		val = "true"
	}
	if _, err := eng.db.Exec(
		`INSERT INTO shop_settings (key, value) VALUES ('allow_item_edit_any_status', ?)
		 ON CONFLICT(key) DO UPDATE SET value = excluded.value`, val,
	); err != nil {
		t.Fatal(err)
	}
}

// Setting ON → a preparing item (kitchen already has it) can still be voided.
func TestVoidItem_AllowAnyStatus_VoidsPreparingItem(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	setAllowItemEditAnyStatus(t, eng, true)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}

	if _, err := eng.VoidItem(o.ID, itemID, "any-status void"); err != nil {
		t.Fatalf("want success voiding a preparing item with the setting ON, got %v", err)
	}
	var status string
	db.QueryRow(`SELECT status FROM order_items WHERE id = ?`, itemID).Scan(&status)
	if status != "voided" {
		t.Errorf("status want voided, got %s", status)
	}
}

// Setting ON → a served item can be voided too.
func TestVoidItem_AllowAnyStatus_VoidsServedItem(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	setAllowItemEditAnyStatus(t, eng, true)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'served' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}

	if _, err := eng.VoidItem(o.ID, itemID, "void served"); err != nil {
		t.Fatalf("want success voiding a served item with the setting ON, got %v", err)
	}
}

// Setting ON relaxes the ITEM-status gate but NOT the ORDER gate — a
// non-open order still freezes its lines.
func TestVoidItem_AllowAnyStatus_StillRequiresOpenOrder(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	setAllowItemEditAnyStatus(t, eng, true)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'served' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`UPDATE orders SET status = 'checkout' WHERE id = ?`, o.ID); err != nil {
		t.Fatal(err)
	}

	if _, err := eng.VoidItem(o.ID, itemID, "order locked"); !errors.Is(err, ErrOrderNotOpen) {
		t.Errorf("want ErrOrderNotOpen (order gate stays even when setting ON), got %v", err)
	}
}

// Setting ON → a qty edit on a preparing item succeeds.
func TestUpdateItem_AllowAnyStatus_EditsPreparingQty(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	setAllowItemEditAnyStatus(t, eng, true)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}

	newQty := 5
	if _, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Quantity: &newQty}); err != nil {
		t.Fatalf("want success editing qty on a preparing item with the setting ON, got %v", err)
	}
	var qty int
	db.QueryRow(`SELECT quantity FROM order_items WHERE id = ?`, itemID).Scan(&qty)
	if qty != 5 {
		t.Errorf("quantity want 5, got %d", qty)
	}
}

// Setting OFF (default) → the pending-only rule still holds for void + edit.
func TestVoidAndEdit_DefaultOff_StillPendingOnly(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	setAllowItemEditAnyStatus(t, eng, false)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}

	if _, err := eng.VoidItem(o.ID, itemID, "should reject"); !errors.Is(err, ErrItemNotPending) {
		t.Errorf("void: want ErrItemNotPending with setting OFF, got %v", err)
	}
	newQty := 3
	if _, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Quantity: &newQty}); !errors.Is(err, ErrItemEditRequiresPending) {
		t.Errorf("edit: want ErrItemEditRequiresPending with setting OFF, got %v", err)
	}
}
