package service

import (
	"encoding/json"
	"testing"
)

func mkToppingItem(id string, toppings *[]cloudOrderItemToppingPayload) cloudOrderItemPayload {
	return cloudOrderItemPayload{
		ID:           id,
		MenuItemName: "Tra sua",
		Quantity:     json.Number("1"),
		UnitPrice:    json.Number("1930"),
		Subtotal:     json.Number("1930"),
		Status:       "pending",
		UpdatedAt:    "2026-06-25T10:00:00Z",
		Toppings:     toppings,
	}
}

func mkToppingOrder(item cloudOrderItemPayload) cloudOrderPayload {
	return cloudOrderPayload{
		ID: "ord-1", OrderCode: "ORD-1", OrderType: "dine_in", Status: "open",
		OpenedAt: "2026-06-25T10:00:00Z", UpdatedAt: "2026-06-25T10:00:00Z",
		BranchID: "br-1", BrandID: "bd-1", OrgID: "org-1",
		Items: []cloudOrderItemPayload{item},
	}
}

// TestUpsertOrder_MirrorsToppings asserts that when Cloud sends a non-nil
// toppings list, the pull replaces order_item_toppings so the bill/kitchen
// ticket can render extras.
func TestUpsertOrder_MirrorsToppings(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	toppings := []cloudOrderItemToppingPayload{
		{ID: "t1", Name: "Extra cheese", ModifierType: "add", Quantity: json.Number("1"), UnitPrice: json.Number("150")},
		{ID: "t2", Name: "No sugar", ModifierType: "remove", Quantity: json.Number("1"), UnitPrice: json.Number("0")},
	}
	if err := p.upsertOrder(mkToppingOrder(mkToppingItem("it-1", &toppings)), false); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}

	var n int
	db.QueryRow(`SELECT COUNT(*) FROM order_item_toppings WHERE order_item_id='it-1'`).Scan(&n)
	if n != 2 {
		t.Fatalf("expected 2 toppings mirrored, got %d", n)
	}
	var name, mod string
	db.QueryRow(`SELECT name, modifier_type FROM order_item_toppings WHERE id='t1'`).Scan(&name, &mod)
	if name != "Extra cheese" || mod != "add" {
		t.Errorf("topping t1 wrong: %q/%q", name, mod)
	}

	// Re-pull with a shorter list → replace-all reflects the removal.
	shorter := []cloudOrderItemToppingPayload{
		{ID: "t1", Name: "Extra cheese", ModifierType: "add", Quantity: json.Number("1"), UnitPrice: json.Number("150")},
	}
	if err := p.upsertOrder(mkToppingOrder(mkToppingItem("it-1", &shorter)), false); err != nil {
		t.Fatalf("re-upsert: %v", err)
	}
	db.QueryRow(`SELECT COUNT(*) FROM order_item_toppings WHERE order_item_id='it-1'`).Scan(&n)
	if n != 1 {
		t.Errorf("replace-all should reflect removal: expected 1, got %d", n)
	}
}

// TestUpsertOrder_NilToppingsPreservesLocal is the safety guard: when Cloud
// omits the toppings field (nil pointer), the pull must NOT wipe toppings that
// exist locally (e.g. a POS-created order that owns its toppings).
func TestUpsertOrder_NilToppingsPreservesLocal(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	// Seed the item + a local topping first.
	withToppings := []cloudOrderItemToppingPayload{
		{ID: "local-top", Name: "House special", ModifierType: "add", Quantity: json.Number("1"), UnitPrice: json.Number("200")},
	}
	if err := p.upsertOrder(mkToppingOrder(mkToppingItem("it-1", &withToppings)), false); err != nil {
		t.Fatalf("seed: %v", err)
	}

	// Now a pull where Cloud omits toppings (nil) — local topping must survive.
	if err := p.upsertOrder(mkToppingOrder(mkToppingItem("it-1", nil)), false); err != nil {
		t.Fatalf("upsertOrder nil: %v", err)
	}

	var n int
	db.QueryRow(`SELECT COUNT(*) FROM order_item_toppings WHERE order_item_id='it-1'`).Scan(&n)
	if n != 1 {
		t.Errorf("nil toppings must not wipe local toppings: expected 1, got %d", n)
	}
}
