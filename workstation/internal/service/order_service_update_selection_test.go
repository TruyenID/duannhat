package service

import (
	"encoding/json"
	"errors"
	"testing"
)

func TestItemPatchJSON_PreservesExplicitClearValues(t *testing.T) {
	var patch ItemPatch
	if err := json.Unmarshal(
		[]byte(`{"note":null,"toppings":[]}`),
		&patch,
	); err != nil {
		t.Fatal(err)
	}
	if patch.Note == nil || *patch.Note != "" {
		t.Fatalf("explicit null note must decode as a clear operation: %+v", patch.Note)
	}
	if patch.Toppings == nil || len(*patch.Toppings) != 0 {
		t.Fatalf("explicit empty toppings must remain present: %+v", patch.Toppings)
	}
}

func TestUpdateItem_SkuEditIsRejected_1148(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	// #2188 — lines must stamp a snapshot (0% default); unstamped lines are
	// dropped from the recompute, which would zero this order's subtotal.
	if _, err := db.Exec(`INSERT OR IGNORE INTO tax_types (id, code, name, rate, is_default, is_active)
		VALUES ('tt-fixture-zero','ZERO','Zero',0,1,1)`); err != nil {
		t.Fatal(err)
	}
	seedSimpleSku(t, eng, "sku-regular", "Regular", 1000)
	seedSimpleSku(t, eng, "sku-large", "Large", 1600)

	if _, err := db.Exec(`INSERT INTO pos_products (id, name) VALUES ('p-cheese','Cheese')`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-cheddar','p-cheese','Cheddar','CHEDDAR',0,1)`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_topping_groups (
			id, name, selection_type, modifier_type, price_strategy,
			min_select, max_qty_per_item, is_active
		) VALUES ('tg-extra','Extras','multiple','add','flat',0,3,1)`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_topping_group_items (id, topping_group_id, product_id)
		VALUES ('tgi-cheese','tg-extra','p-cheese')`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_topping_group_item_skus (
			id, topping_group_item_id, product_sku_id, extra_price
		) VALUES ('tgis-cheese','tgi-cheese','sku-cheddar',50)`); err != nil {
		t.Fatal(err)
	}

	order, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	created, err := eng.AddItems(order.ID, []CreateItemInput{{
		ProductSkuID: "sku-regular",
		Quantity:     2,
		Note:         "old note",
	}})
	if err != nil {
		t.Fatal(err)
	}

	newSkuID := "sku-large"
	newNote := "new note"
	toppings := []ToppingInput{{
		ToppingGroupItemID: "tgi-cheese",
		ProductSkuID:       "sku-cheddar",
		Quantity:           1,
	}}
	// #1148 — the SKU key alone must reject the whole patch atomically.
	if _, err := eng.UpdateItem(order.ID, created[0].ID, ItemPatch{
		ProductSkuID: &newSkuID,
		Note:         &newNote,
		Toppings:     &toppings,
	}); !errors.Is(err, ErrItemSKUImmutable) {
		t.Fatalf("want ErrItemSKUImmutable, got %v", err)
	}

	// Toppings + note edits WITHOUT the SKU key keep working.
	updated, err := eng.UpdateItem(order.ID, created[0].ID, ItemPatch{
		Note:     &newNote,
		Toppings: &toppings,
	})
	if err != nil {
		t.Fatalf("UpdateItem: %v", err)
	}
	item := updated.Items[0]
	if item.ProductSkuID != "sku-regular" {
		t.Errorf("product_sku_id must be untouched: got %q", item.ProductSkuID)
	}
	if item.UnitPrice != 1000 || item.ToppingSubtotal != 50 || item.Subtotal != 2100 {
		t.Errorf("prices: unit=%d topping=%d subtotal=%d, want 1000/50/2100",
			item.UnitPrice, item.ToppingSubtotal, item.Subtotal)
	}
	if item.Note != "new note" {
		t.Errorf("note: want new note, got %q", item.Note)
	}
	if updated.Subtotal != 2100 {
		t.Errorf("order subtotal: want 2100, got %d", updated.Subtotal)
	}
}

func TestUpdateItem_RejectsSkuFromAnotherProduct(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-regular", "Regular", 1000)
	if _, err := db.Exec(`INSERT INTO pos_products (id, name) VALUES ('p-other','Other')`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-other','p-other','Other','OTHER',900,1)`); err != nil {
		t.Fatal(err)
	}

	order, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	created, err := eng.AddItems(order.ID, []CreateItemInput{{
		ProductSkuID: "sku-regular",
		Quantity:     1,
	}})
	if err != nil {
		t.Fatal(err)
	}

	otherSkuID := "sku-other"
	_, err = eng.UpdateItem(order.ID, created[0].ID, ItemPatch{ProductSkuID: &otherSkuID})
	if !errors.Is(err, ErrItemSKUImmutable) {
		t.Fatalf("want ErrItemSKUImmutable, got %v", err)
	}

	var persistedSkuID string
	if err := db.QueryRow(
		`SELECT product_sku_id FROM order_items WHERE id = ?`,
		created[0].ID,
	).Scan(&persistedSkuID); err != nil {
		t.Fatal(err)
	}
	if persistedSkuID != "sku-regular" {
		t.Errorf("failed edit mutated SKU: got %q", persistedSkuID)
	}
}
