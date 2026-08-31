package service

import (
	"errors"
	"testing"
)

// A `confirmed` order is a Cloud-origin counter-pay takeaway mirrored down to
// the workstation. Staff adjusts the cart at the counter before taking
// payment, so the POS engine accepts item mutations on it exactly like an
// `open` order (mirrors Cloud's addItems/updateItem/voidItem gates).

func setOrderStatusConfirmed(t *testing.T, eng *OrderEngine, orderID string) {
	t.Helper()
	if _, err := eng.db.Exec(`UPDATE orders SET status = 'confirmed' WHERE id = ?`, orderID); err != nil {
		t.Fatal(err)
	}
}

func TestUpdateItem_ConfirmedOrder_EditsPendingLine(t *testing.T) {
	eng, db := newOrderEngineForTest(t)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	setOrderStatusConfirmed(t, eng, o.ID)

	newQty := 5
	if _, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Quantity: &newQty}); err != nil {
		t.Fatalf("want qty edit to succeed on a confirmed order, got %v", err)
	}
	var qty int
	db.QueryRow(`SELECT quantity FROM order_items WHERE id = ?`, itemID).Scan(&qty)
	if qty != 5 {
		t.Errorf("quantity want 5, got %d", qty)
	}
}

func TestVoidItem_ConfirmedOrder_VoidsPendingLine(t *testing.T) {
	eng, db := newOrderEngineForTest(t)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	setOrderStatusConfirmed(t, eng, o.ID)

	if _, err := eng.VoidItem(o.ID, itemID, "khách đổi ý tại quầy"); err != nil {
		t.Fatalf("want void to succeed on a confirmed order, got %v", err)
	}
	var itemStatus, orderStatus string
	db.QueryRow(`SELECT status FROM order_items WHERE id = ?`, itemID).Scan(&itemStatus)
	db.QueryRow(`SELECT status FROM orders WHERE id = ?`, o.ID).Scan(&orderStatus)
	if itemStatus != "voided" {
		t.Errorf("item status want voided, got %s", itemStatus)
	}
	if orderStatus != "confirmed" {
		t.Errorf("order must stay confirmed after a line void, got %s", orderStatus)
	}
}

func TestAddItems_ConfirmedOrder_AppendsLine(t *testing.T) {
	eng, db := newOrderEngineForTest(t)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	setOrderStatusConfirmed(t, eng, o.ID)

	// A second SKU so BR-OI06 doesn't merge the add into the seeded line.
	if _, err := db.Exec(`
		INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-B', 'p1', 'Large', 'SKU-sku-B', 1500, 1)`); err != nil {
		t.Fatal(err)
	}

	if _, err := eng.AddItems(o.ID, []CreateItemInput{{ProductSkuID: "sku-B", Quantity: 1}}); err != nil {
		t.Fatalf("want add-items to succeed on a confirmed order, got %v", err)
	}
	var count int
	db.QueryRow(`SELECT COUNT(*) FROM order_items WHERE customer_order_id = ?`, o.ID).Scan(&count)
	if count != 2 {
		t.Errorf("want 2 lines after adding a distinct SKU, got %d", count)
	}
}

// The #1148 pending-only edit gate holds on confirmed orders too — only the
// ORDER gate is relaxed, never the item gate.
func TestUpdateItem_ConfirmedOrder_StillRejectsCookedLine(t *testing.T) {
	eng, db := newOrderEngineForTest(t)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	setOrderStatusConfirmed(t, eng, o.ID)
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}

	newQty := 5
	if _, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Quantity: &newQty}); !errors.Is(err, ErrItemEditRequiresPending) {
		t.Fatalf("want ErrItemEditRequiresPending on a preparing line, got %v", err)
	}
}
