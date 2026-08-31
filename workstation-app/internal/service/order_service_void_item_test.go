package service

import (
	"database/sql"
	"errors"
	"testing"
)

func seedSkuAndOrder(t *testing.T, eng *OrderEngine, skuID string, price int) *Order {
	t.Helper()
	if _, err := eng.db.Exec(`INSERT OR IGNORE INTO pos_products (id, name) VALUES ('p1','Pho')`); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.db.Exec(`
		INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES (?, 'p1', 'Regular', ?, ?, 1)`,
		skuID, "SKU-"+skuID, price); err != nil {
		t.Fatal(err)
	}
	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{{ProductSkuID: skuID, Quantity: 2}}); err != nil {
		t.Fatal(err)
	}
	o, err = eng.GetByID(o.ID)
	if err != nil {
		t.Fatal(err)
	}
	return o
}

// Happy path — void a pending item on an open order. Item row stays
// (soft-void), totals recalculate, status = voided.
func TestVoidItem_HappyPath(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 2300)
	itemID := o.Items[0].ID

	got, err := eng.VoidItem(o.ID, itemID, "Customer changed mind")
	if err != nil {
		t.Fatalf("void: %v", err)
	}
	if got.Subtotal != 0 {
		t.Errorf("subtotal must be 0 after voiding the only line, got %d", got.Subtotal)
	}
	// Row preserved with status='voided'.
	var status, voidedAt, reason string
	db.QueryRow(`SELECT status, COALESCE(voided_at,''), COALESCE(void_reason,'') FROM order_items WHERE id = ?`, itemID).
		Scan(&status, &voidedAt, &reason)
	if status != "voided" {
		t.Errorf("status want voided, got %s", status)
	}
	if voidedAt == "" {
		t.Errorf("voided_at must be set")
	}
	if reason != "Customer changed mind" {
		t.Errorf("void_reason want 'Customer changed mind', got %q", reason)
	}
}

// BR-OI05: item already in `preparing` (kitchen picked it up) MUST
// surface ErrItemNotPending → handler maps to 409. The kitchen needs
// the refund flow, not silent void.
func TestVoidItem_BR_OI05_RejectsPreparingItem(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID

	// Mark item as preparing (simulate KDS bump).
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}

	_, err := eng.VoidItem(o.ID, itemID, "test")
	if !errors.Is(err, ErrItemNotPending) {
		t.Errorf("want ErrItemNotPending, got %v", err)
	}
}

func TestVoidItem_BR_OI05_RejectsReadyItem(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'ready' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}
	_, err := eng.VoidItem(o.ID, itemID, "test")
	if !errors.Is(err, ErrItemNotPending) {
		t.Errorf("want ErrItemNotPending for ready item, got %v", err)
	}
}

// BR-OI05: order must be `open`. Voided / closed / paying parents
// freeze their lines.
func TestVoidItem_BR_OI05_RejectsNonOpenOrder(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE orders SET status = 'voided' WHERE id = ?`, o.ID); err != nil {
		t.Fatal(err)
	}
	_, err := eng.VoidItem(o.ID, itemID, "test")
	if !errors.Is(err, ErrOrderNotVoidable) {
		t.Errorf("want ErrOrderNotVoidable, got %v", err)
	}
}

// Idempotent re-void: voiding an already-voided item must not error,
// returns the current order unchanged.
func TestVoidItem_IdempotentOnAlreadyVoided(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := eng.VoidItem(o.ID, itemID, "first"); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.VoidItem(o.ID, itemID, "second"); err != nil {
		t.Errorf("second void should be idempotent, got %v", err)
	}
}

// Unknown item id → sql.ErrNoRows so the handler can 404.
func TestVoidItem_UnknownItem(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	_, err := eng.VoidItem(o.ID, "nonexistent-item-id", "test")
	if !errors.Is(err, sql.ErrNoRows) {
		t.Errorf("unknown item: want sql.ErrNoRows, got %v", err)
	}
}

// DeleteItem must delegate to VoidItem (Cloud parity) — row stays,
// status=voided, void_reason="Removed by staff". Pre-fix
// workstation HARD-deleted the row.
func TestDeleteItem_DelegatesToVoidWithDefaultReason(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID

	if _, err := eng.DeleteItem(o.ID, itemID); err != nil {
		t.Fatalf("delete: %v", err)
	}

	// Row must STILL exist (soft-void).
	var rowExists int
	db.QueryRow(`SELECT COUNT(*) FROM order_items WHERE id = ?`, itemID).Scan(&rowExists)
	if rowExists != 1 {
		t.Errorf("DeleteItem must soft-void (preserve row), got %d rows", rowExists)
	}

	var status, reason string
	db.QueryRow(`SELECT status, COALESCE(void_reason,'') FROM order_items WHERE id = ?`, itemID).Scan(&status, &reason)
	if status != "voided" {
		t.Errorf("status want voided, got %s", status)
	}
	if reason != "Removed by staff" {
		t.Errorf("reason want 'Removed by staff', got %q", reason)
	}
}

// DeleteItem also respects BR-OI05 — can't remove preparing items.
func TestDeleteItem_BR_OI05_RejectsPreparingItem(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}
	_, err := eng.DeleteItem(o.ID, itemID)
	if !errors.Is(err, ErrItemNotPending) {
		t.Errorf("DeleteItem on preparing: want ErrItemNotPending, got %v", err)
	}
}
