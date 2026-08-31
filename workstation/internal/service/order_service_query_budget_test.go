package service

import (
	"fmt"
	"testing"
)

func TestGetByID_QueryBudgetDoesNotGrowWithItemsAndToppings(t *testing.T) {
	engine, db := newOrderEngineForTest(t)
	const orderID = "order-query-budget"
	if _, err := db.Exec(`INSERT INTO orders
		(id, order_code, order_type, status, opened_at, organization_id, brand_id, branch_id, created_at, updated_at)
		VALUES (?, 'WS-QB', 'dine_in', 'open', '2026-08-16T00:00:00Z', 'org', 'brand', 'branch',
		        '2026-08-16T00:00:00Z', '2026-08-16T00:00:00Z')`, orderID); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	seedItem := func(i int) {
		t.Helper()
		itemID := fmt.Sprintf("item-%03d", i)
		if _, err := db.Exec(`INSERT INTO order_items
			(id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, created_at, updated_at)
			VALUES (?, ?, 'Pho', 1, 100, 100, '2026-08-16T00:00:00Z', '2026-08-16T00:00:00Z')`, itemID, orderID); err != nil {
			t.Fatalf("seed item %d: %v", i, err)
		}
		for j := 0; j < 2; j++ {
			if _, err := db.Exec(`INSERT INTO order_item_toppings
				(id, order_item_id, topping_group_item_id, product_sku_id, name, quantity, unit_price, created_at)
				VALUES (?, ?, ?, ?, 'Topping', 1, 10, '2026-08-16T00:00:00Z')`,
				fmt.Sprintf("top-%03d-%d", i, j), itemID, fmt.Sprintf("tgi-%d", j), fmt.Sprintf("sku-%d", j)); err != nil {
				t.Fatalf("seed topping %d/%d: %v", i, j, err)
			}
		}
	}

	seedItem(0)
	baselineBefore := db.Diagnostics().QueryCount
	baseline, err := engine.GetByID(orderID)
	if err != nil {
		t.Fatalf("GetByID one-item baseline: %v", err)
	}
	baselineQueries := db.Diagnostics().QueryCount - baselineBefore
	if len(baseline.Items) != 1 || len(baseline.Items[0].Toppings) != 2 {
		t.Fatalf("baseline hydration changed: items=%d toppings=%d", len(baseline.Items), len(baseline.Items[0].Toppings))
	}
	if baselineQueries != 3 {
		t.Fatalf("one-item GetByID used %d queries, want 3", baselineQueries)
	}

	for i := 1; i < 100; i++ {
		seedItem(i)
	}

	before := db.Diagnostics().QueryCount
	order, err := engine.GetByID(orderID)
	if err != nil {
		t.Fatalf("GetByID: %v", err)
	}
	if len(order.Items) != 100 || len(order.Items[99].Toppings) != 2 {
		t.Fatalf("hydration changed: items=%d last_toppings=%d", len(order.Items), len(order.Items[99].Toppings))
	}
	if got := db.Diagnostics().QueryCount - before; got != baselineQueries {
		t.Errorf("100-item GetByID used %d queries, want same %d-query budget as one item", got, baselineQueries)
	}
}
