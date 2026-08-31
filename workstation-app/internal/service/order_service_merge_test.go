package service

import (
	"testing"
)

func seedSimpleSku(t *testing.T, eng *OrderEngine, skuID, name string, price int) {
	t.Helper()
	if _, err := eng.db.Exec(`INSERT OR IGNORE INTO pos_products (id, name) VALUES ('p1','Pho')`); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.db.Exec(`
		INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES (?, 'p1', ?, ?, ?, 1)`,
		skuID, name, "SKU-"+name, price); err != nil {
		t.Fatal(err)
	}
}

// BR-OI06: two add-item calls for the same SKU + same price + no
// toppings + no note → one cart line, quantity = 2. Pre-fix produced
// two separate rows and pos-web rendered duplicate cards.
func TestAddItems_BR_OI06_MergesIdenticalSkus(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-A", "Regular", 2300)

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}

	if _, err := eng.AddItems(o.ID, []CreateItemInput{{
		ProductSkuID: "sku-A",
		Quantity:     1,
	}}); err != nil {
		t.Fatalf("first add: %v", err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{{
		ProductSkuID: "sku-A",
		Quantity:     1,
	}}); err != nil {
		t.Fatalf("second add: %v", err)
	}

	var rowCount, totalQty int
	db.QueryRow(`SELECT COUNT(*), COALESCE(SUM(quantity),0) FROM order_items WHERE customer_order_id = ?`, o.ID).
		Scan(&rowCount, &totalQty)
	if rowCount != 1 {
		t.Errorf("BR-OI06 merge: want 1 row, got %d", rowCount)
	}
	if totalQty != 2 {
		t.Errorf("BR-OI06 merge: want total qty=2, got %d", totalQty)
	}
}

// Same batch — adding two identical inputs in ONE AddItems call must
// also merge. Pre-fix the second input saw the first's just-inserted
// row mid-transaction but still created a duplicate.
func TestAddItems_BR_OI06_MergesWithinSameBatch(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-A", "Regular", 2300)
	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}

	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: "sku-A", Quantity: 1},
		{ProductSkuID: "sku-A", Quantity: 1},
	}); err != nil {
		t.Fatalf("AddItems batch: %v", err)
	}

	var rowCount int
	db.QueryRow(`SELECT COUNT(*) FROM order_items WHERE customer_order_id = ?`, o.ID).Scan(&rowCount)
	if rowCount != 1 {
		t.Errorf("batch merge: want 1 row, got %d", rowCount)
	}
}

// Different notes → separate lines (BR-OI06 includes note in merge key).
func TestAddItems_BR_OI06_KeepsDifferentNotesSeparate(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-A", "Regular", 2300)
	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}

	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: "sku-A", Quantity: 1, Note: "ít cay"},
		{ProductSkuID: "sku-A", Quantity: 1, Note: "no onion"},
	}); err != nil {
		t.Fatal(err)
	}

	var rowCount int
	db.QueryRow(`SELECT COUNT(*) FROM order_items WHERE customer_order_id = ?`, o.ID).Scan(&rowCount)
	if rowCount != 2 {
		t.Errorf("different notes: want 2 rows, got %d", rowCount)
	}
}

// Different toppings → separate lines. Sorted-tuple merge key means
// order of toppings in payload doesn't matter — same set of
// (group_item, sku, qty) tuples merges.
func TestAddItems_BR_OI06_MergesIdenticalToppingsTuple(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-A", "Regular", 2300)
	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}

	// Payload A: [cheese, beef] in this order
	// Payload B: [beef, cheese] reversed order — same set
	addCheese := ToppingInput{ToppingGroupItemID: "tgi-cheese", ProductSkuID: "tsk-cheese", Quantity: 1, UnitPrice: 100}
	addBeef := ToppingInput{ToppingGroupItemID: "tgi-beef", ProductSkuID: "tsk-beef", Quantity: 1, UnitPrice: 200}

	if _, err := eng.AddItems(o.ID, []CreateItemInput{{
		ProductSkuID: "sku-A", Quantity: 1,
		Toppings: []ToppingInput{addCheese, addBeef},
	}}); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{{
		ProductSkuID: "sku-A", Quantity: 1,
		Toppings: []ToppingInput{addBeef, addCheese}, // reversed
	}}); err != nil {
		t.Fatal(err)
	}

	var rowCount, totalQty int
	db.QueryRow(`SELECT COUNT(*), COALESCE(SUM(quantity),0) FROM order_items WHERE customer_order_id = ?`, o.ID).
		Scan(&rowCount, &totalQty)
	if rowCount != 1 {
		t.Errorf("topping merge (sorted tuple): want 1 row, got %d", rowCount)
	}
	if totalQty != 2 {
		t.Errorf("merged qty want 2, got %d", totalQty)
	}

	// Subtotal must respect topping cost: qty * (unit_price + topping_subtotal)
	// = 2 * (2300 + 300) = 5200
	var subtotal int
	db.QueryRow(`SELECT subtotal FROM order_items WHERE customer_order_id = ?`, o.ID).Scan(&subtotal)
	if subtotal != 5200 {
		t.Errorf("merged subtotal want 5200 (=2*(2300+300)), got %d", subtotal)
	}
}

// Subtotal formula must include topping cost from the FIRST insert too
// — backend: subtotal = qty * (unit_price + topping_subtotal).
// Pre-fix workstation used qty * unit_price, dropping topping cost.
func TestAddItems_SubtotalIncludesToppingCost(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-A", "Regular", 1000)
	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}

	if _, err := eng.AddItems(o.ID, []CreateItemInput{{
		ProductSkuID: "sku-A", Quantity: 2,
		Toppings: []ToppingInput{
			{ToppingGroupItemID: "tgi-cheese", ProductSkuID: "tsk-cheese", Quantity: 1, UnitPrice: 150},
		},
	}}); err != nil {
		t.Fatal(err)
	}

	var subtotal, toppingSub int
	db.QueryRow(`SELECT subtotal, COALESCE(topping_subtotal,0) FROM order_items WHERE customer_order_id = ?`, o.ID).
		Scan(&subtotal, &toppingSub)
	// qty=2 * (unit=1000 + topping=150) = 2300
	if subtotal != 2300 {
		t.Errorf("subtotal want 2300, got %d", subtotal)
	}
	if toppingSub != 150 {
		t.Errorf("topping_subtotal want 150, got %d", toppingSub)
	}
}
