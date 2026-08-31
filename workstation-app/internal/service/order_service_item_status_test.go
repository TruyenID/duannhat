package service

import (
	"database/sql"
	"errors"
	"testing"
)

// Status transitions: any of pending→preparing→ready→served (and
// arbitrary jumps between the 4 active enums) must succeed — Cloud's
// validateItemTransition (CustomerOrderService.php:1603) allows free
// movement between active statuses. Only `voided` is gated to the
// dedicated void flow.
func TestUpdateItem_StatusTransition_PendingToPreparing(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID

	preparing := "preparing"
	got, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Status: &preparing})
	if err != nil {
		t.Fatalf("preparing: %v", err)
	}
	if got.Items[0].Status != "preparing" {
		t.Errorf("status want preparing, got %q", got.Items[0].Status)
	}
	// served_at must NOT be stamped on non-served transitions.
	var servedAt sql.NullString
	db.QueryRow(`SELECT served_at FROM order_items WHERE id = ?`, itemID).Scan(&servedAt)
	if servedAt.Valid {
		t.Errorf("served_at must stay null for preparing, got %q", servedAt.String)
	}
}

func TestUpdateItem_StatusTransition_StampsServedAt(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID

	served := "served"
	got, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Status: &served})
	if err != nil {
		t.Fatalf("served: %v", err)
	}
	if got.Items[0].Status != "served" {
		t.Errorf("status want served, got %q", got.Items[0].Status)
	}
	if got.Items[0].ServedAt == nil {
		t.Errorf("served_at must be stamped when transitioning to served")
	}
	var servedAt sql.NullString
	db.QueryRow(`SELECT served_at FROM order_items WHERE id = ?`, itemID).Scan(&servedAt)
	if !servedAt.Valid || servedAt.String == "" {
		t.Errorf("DB served_at must be set, got %v", servedAt)
	}
}

func TestUpdateItem_StatusTransition_AcceptsAnyActiveEnum(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID

	for _, s := range []string{"preparing", "ready", "served", "pending"} {
		st := s
		if _, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Status: &st}); err != nil {
			t.Errorf("transition to %s: %v", s, err)
		}
	}
}

// Cloud validation: status must be in the active set. `voided` is a
// dedicated flow; an unknown enum is a client bug. Both surface as 422.
func TestUpdateItem_RejectsVoidedStatus(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID

	voided := "voided"
	_, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Status: &voided})
	if !errors.Is(err, ErrInvalidItemStatus) {
		t.Errorf("voided via status: want ErrInvalidItemStatus, got %v", err)
	}
}

func TestUpdateItem_RejectsUnknownStatus(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID

	bogus := "frozen"
	_, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Status: &bogus})
	if !errors.Is(err, ErrInvalidItemStatus) {
		t.Errorf("unknown enum: want ErrInvalidItemStatus, got %v", err)
	}
}

// Status changes must work even when the parent order has left
// `open`. KDS bumps an item from "ready" → "served" while cashier is
// at checkout/paying; Cloud's updateItem allows that path explicitly.
func TestUpdateItem_StatusOnNonOpenOrder_Allowed(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE orders SET status = 'paying' WHERE id = ?`, o.ID); err != nil {
		t.Fatal(err)
	}
	served := "served"
	if _, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Status: &served}); err != nil {
		t.Errorf("status change on paying order must be allowed, got %v", err)
	}
}

// BR-OI05 edit gate: header edits (qty / note) require the item still
// pending AND the order still open. Cloud's body: "Can only change
// quantity/note/toppings when item is pending."
func TestUpdateItem_QuantityRejectedOnPreparingItem(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}
	five := 5
	_, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Quantity: &five})
	if !errors.Is(err, ErrItemEditRequiresPending) {
		t.Errorf("qty on preparing: want ErrItemEditRequiresPending, got %v", err)
	}
}

func TestUpdateItem_NoteRejectedOnNonOpenOrder(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE orders SET status = 'closed' WHERE id = ?`, o.ID); err != nil {
		t.Fatal(err)
	}
	note := "x"
	_, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Note: &note})
	if !errors.Is(err, ErrOrderNotOpen) {
		t.Errorf("note on closed order: want ErrOrderNotOpen, got %v", err)
	}
}

// Header edit on pending item / open order keeps working — qty change
// recalculates totals on the order.
func TestUpdateItem_QuantityHappyPath(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID

	five := 5
	got, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Quantity: &five})
	if err != nil {
		t.Fatalf("qty: %v", err)
	}
	if got.Items[0].Quantity != 5 {
		t.Errorf("qty want 5, got %d", got.Items[0].Quantity)
	}
	if got.Subtotal != 5*1000 {
		t.Errorf("order subtotal want %d, got %d", 5*1000, got.Subtotal)
	}
}
