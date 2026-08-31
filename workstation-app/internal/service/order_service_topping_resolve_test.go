package service

import (
	"testing"
)

// pos-web LAN payload only ships
// (topping_group_item_id, product_sku_id, quantity, note). Pre-fix, that
// produced order_item_toppings rows with NULL name + 0 unit_price, so the
// cart sidebar rendered the topping_group_item_id UUID and dropped the
// extra-price tag. The resolver hydrates name + group + modifier_type +
// unit_price from the local pos_* menu replica so LAN-mode parity matches
// Cloud.
func TestAddItems_ResolvesToppingSnapshotFromLocalMenu(t *testing.T) {
	eng, db := newOrderEngineForTest(t)

	// Parent product (the dish staff is ordering)
	seedSimpleSku(t, eng, "sku-pho", "Regular", 2300)

	// Topping product + variant — bột-béo + sku
	if _, err := db.Exec(`INSERT INTO pos_products (id, name) VALUES ('p-cheese','Phô mai')`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('tsk-cheese','p-cheese','Large','SKU-cheese',0,1)`); err != nil {
		t.Fatal(err)
	}

	// Topping group + group_item linking the topping product to the group
	if _, err := db.Exec(`
		INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, min_select, max_qty_per_item)
		VALUES ('tg-extras','Phụ thu','multiple','add','flat',0,3)`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_topping_group_items (id, topping_group_id, product_id)
		VALUES ('tgi-cheese','tg-extras','p-cheese')`); err != nil {
		t.Fatal(err)
	}
	// Per-sku surcharge
	if _, err := db.Exec(`
		INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, product_sku_id, extra_price)
		VALUES ('its-cheese','tgi-cheese','tsk-cheese',500)`); err != nil {
		t.Fatal(err)
	}

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}

	// Minimal payload mirroring pos-web's ToppingSelection — no name,
	// no group, no modifier_type, no unit_price.
	if _, err := eng.AddItems(o.ID, []CreateItemInput{{
		ProductSkuID: "sku-pho",
		Quantity:     1,
		Toppings: []ToppingInput{{
			ToppingGroupItemID: "tgi-cheese",
			ProductSkuID:       "tsk-cheese",
			Quantity:           1,
		}},
	}}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	var (
		name, groupID, groupName, modifierType string
		unitPrice                              int
		toppingSubtotal                        int
	)
	if err := db.QueryRow(`
		SELECT
			oit.name, oit.topping_group_id, oit.topping_group_name,
			oit.modifier_type, oit.unit_price,
			oi.topping_subtotal
		FROM order_item_toppings oit
		JOIN order_items oi ON oi.id = oit.order_item_id
		WHERE oi.customer_order_id = ?`, o.ID).
		Scan(&name, &groupID, &groupName, &modifierType, &unitPrice, &toppingSubtotal); err != nil {
		t.Fatalf("scan topping: %v", err)
	}

	if name != "Phô mai · Large" {
		t.Errorf("name: want 'Phô mai · Large', got %q", name)
	}
	if groupID != "tg-extras" {
		t.Errorf("group_id: want 'tg-extras', got %q", groupID)
	}
	if groupName != "Phụ thu" {
		t.Errorf("group_name: want 'Phụ thu', got %q", groupName)
	}
	if modifierType != "add" {
		t.Errorf("modifier_type: want 'add', got %q", modifierType)
	}
	if unitPrice != 500 {
		t.Errorf("unit_price: want 500 (from pos_topping_group_item_skus.extra_price), got %d", unitPrice)
	}
	if toppingSubtotal != 500 {
		t.Errorf("topping_subtotal on order_items: want 500, got %d", toppingSubtotal)
	}
}

// Regression (workstation-app#101): a topping product whose single default
// SKU is named the same as the product must NOT render as "Name · Name".
// The "product · variant" suffix is only meaningful for a distinct variant;
// for a default SKU it just duplicated the name ("Fish sauce · Fish sauce")
// in the pos-web checkout cart.
func TestAddItems_ToppingWithDefaultSkuName_IsNotDoubled(t *testing.T) {
	eng, db := newOrderEngineForTest(t)

	seedSimpleSku(t, eng, "sku-rolls", "2pc", 1200)

	// Topping product "Fish sauce" whose only SKU is also named "Fish sauce"
	// (the default-SKU case that triggered the doubling).
	if _, err := db.Exec(`INSERT INTO pos_products (id, name) VALUES ('p-fs','Fish sauce')`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('tsk-fs','p-fs','Fish sauce','SKU-fs',0,1)`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, min_select, max_qty_per_item)
		VALUES ('tg-sauce','Fish sauce','single','add','flat',0,1)`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_topping_group_items (id, topping_group_id, product_id)
		VALUES ('tgi-fs','tg-sauce','p-fs')`); err != nil {
		t.Fatal(err)
	}

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}

	if _, err := eng.AddItems(o.ID, []CreateItemInput{{
		ProductSkuID: "sku-rolls",
		Quantity:     1,
		Toppings: []ToppingInput{{
			ToppingGroupItemID: "tgi-fs",
			ProductSkuID:       "tsk-fs",
			Quantity:           1,
		}},
	}}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	var name string
	if err := db.QueryRow(`
		SELECT name FROM order_item_toppings WHERE order_item_id IN (
			SELECT id FROM order_items WHERE customer_order_id = ?
		)`, o.ID).Scan(&name); err != nil {
		t.Fatalf("scan topping: %v", err)
	}

	if name != "Fish sauce" {
		t.Errorf("name: want 'Fish sauce' (no doubled suffix), got %q", name)
	}
}

// Client-supplied values must take precedence — the resolver only fills
// blanks. Cloud-side mirror call that already snapshotted everything
// shouldn't get re-written by a stale local replica.
func TestAddItems_PreservesClientSuppliedToppingSnapshot(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-pho", "Regular", 2300)

	if _, err := db.Exec(`INSERT INTO pos_products (id, name) VALUES ('p-cheese','Phô mai')`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, min_select, max_qty_per_item) VALUES ('tg-extras','Phụ thu','multiple','add','flat',0,3)`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`INSERT INTO pos_topping_group_items (id, topping_group_id, product_id) VALUES ('tgi-cheese','tg-extras','p-cheese')`); err != nil {
		t.Fatal(err)
	}

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}

	if _, err := eng.AddItems(o.ID, []CreateItemInput{{
		ProductSkuID: "sku-pho",
		Quantity:     1,
		Toppings: []ToppingInput{{
			ToppingGroupItemID: "tgi-cheese",
			ProductSkuID:       "tsk-cheese-other",
			Name:               "Custom Name",
			ModifierType:       "remove",
			ToppingGroupName:   "Override Group",
			UnitPrice:          999,
			Quantity:           1,
		}},
	}}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	var name, groupName, modifierType string
	var unitPrice int
	if err := db.QueryRow(`
		SELECT name, topping_group_name, modifier_type, unit_price
		FROM order_item_toppings WHERE order_item_id IN (
			SELECT id FROM order_items WHERE customer_order_id = ?
		)`, o.ID).Scan(&name, &groupName, &modifierType, &unitPrice); err != nil {
		t.Fatalf("scan: %v", err)
	}

	if name != "Custom Name" {
		t.Errorf("name should be preserved: got %q", name)
	}
	if groupName != "Override Group" {
		t.Errorf("group_name should be preserved: got %q", groupName)
	}
	if modifierType != "remove" {
		t.Errorf("modifier_type should be preserved: got %q", modifierType)
	}
	if unitPrice != 999 {
		t.Errorf("unit_price should be preserved: got %d", unitPrice)
	}
}

// ─── Menu ↔ order price parity (base SKU + topping tiers + free_up_to_n) ──────

func execSeed(t *testing.T, eng *OrderEngine, q string, args ...any) {
	t.Helper()
	if _, err := eng.db.Exec(q, args...); err != nil {
		t.Fatalf("seed exec failed: %v\n%s", err, q)
	}
}

// firstLine reads the single persisted order line's unit_price + topping_subtotal.
func firstLine(t *testing.T, eng *OrderEngine, orderID string) (unitPrice, toppingSubtotal int) {
	t.Helper()
	if err := eng.db.QueryRow(
		`SELECT unit_price, topping_subtotal FROM order_items WHERE customer_order_id = ?`, orderID,
	).Scan(&unitPrice, &toppingSubtotal); err != nil {
		t.Fatalf("read line: %v", err)
	}
	return
}

func firstToppingUnitPrice(t *testing.T, eng *OrderEngine, orderID string) int {
	t.Helper()
	var p int
	if err := eng.db.QueryRow(`
		SELECT oit.unit_price FROM order_item_toppings oit
		JOIN order_items oi ON oi.id = oit.order_item_id
		WHERE oi.customer_order_id = ?`, orderID).Scan(&p); err != nil {
		t.Fatalf("read topping: %v", err)
	}
	return p
}

// seedToppingItem attaches topping product p-fs (sku tsk-fs) to parent product
// p1 via group tg1 / item tgi1, with a tier-3 base extra_price. The group's
// price_strategy/free_quantity are configurable.
func seedToppingItem(t *testing.T, eng *OrderEngine, strategy string, freeQty, baseExtra int) {
	t.Helper()
	execSeed(t, eng, `INSERT OR IGNORE INTO pos_products (id, name) VALUES ('p-fs','Fish sauce')`)
	execSeed(t, eng, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active) VALUES ('tsk-fs','p-fs','Fish sauce','SKU-fs',0,1)`)
	execSeed(t, eng, `INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, free_quantity, min_select, max_qty_per_item) VALUES ('tg1','Sauce','multiple','add',?,?,0,9)`, strategy, freeQty)
	execSeed(t, eng, `INSERT INTO pos_topping_group_items (id, topping_group_id, product_id) VALUES ('tgi1','tg1','p-fs')`)
	execSeed(t, eng, `INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, product_sku_id, extra_price) VALUES ('its1','tgi1','tsk-fs',?)`, baseExtra)
}

// seedPublishedMenuProduct attaches parent product p1 to menu m1 (status
// param) as menu-product mp1 — the tier-1 override key.
func seedPublishedMenuProduct(t *testing.T, eng *OrderEngine, status string) {
	t.Helper()
	execSeed(t, eng, `INSERT OR IGNORE INTO pos_menus (id, name, status, sort_order) VALUES ('m1','Menu',?,0)`, status)
	execSeed(t, eng, `INSERT INTO pos_menu_products (id, menu_id, product_id, is_active) VALUES ('mp1','m1','p1',1)`)
}

func addOneToppingLine(t *testing.T, eng *OrderEngine, orderID string) {
	t.Helper()
	if _, err := eng.AddItems(orderID, []CreateItemInput{{
		ProductSkuID: "sku-pho",
		Quantity:     1,
		Toppings:     []ToppingInput{{ToppingGroupItemID: "tgi1", ProductSkuID: "tsk-fs", Quantity: 1}},
	}}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}
}

// Fix 1: order base price mirrors the menu tile (pos_product_skus.selling_price),
// NOT a stale legacy menu_items.price.
func TestAddItems_BasePrice_PosProductSkuBeatsStaleMenuItems(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-A", "Regular", 2500) // the price the tile shows
	execSeed(t, eng, `INSERT INTO menu_items (id, sku_id, name, price, printer_group, is_active) VALUES ('mi1','sku-A','Pho',9999,'kitchen',1)`)

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{{ProductSkuID: "sku-A", Quantity: 1}}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}
	if up, _ := firstLine(t, eng, o.ID); up != 2500 {
		t.Errorf("unit_price: want 2500 (pos_product_skus.selling_price), got %d (stale menu_items.price?)", up)
	}
}

// Fix 1 no-regression: legacy kiosk/handy path (no product_sku_id) still prices
// from menu_items.price.
func TestAddItems_BasePrice_LegacyMenuItemsPathUnchanged(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	execSeed(t, eng, `INSERT INTO menu_items (id, name, price, printer_group, is_active) VALUES ('mi-legacy','Combo',1800,'kitchen',1)`)

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{{MenuItemID: "mi-legacy", Quantity: 1}}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}
	if up, _ := firstLine(t, eng, o.ID); up != 1800 {
		t.Errorf("legacy unit_price: want 1800 (menu_items.price), got %d", up)
	}
}

// Fix 1 contract: a client-computed unit price still wins over both sources.
func TestAddItems_BasePrice_ClientUnitPriceWins(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-A", "Regular", 2500)

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{{ProductSkuID: "sku-A", Quantity: 1, UnitPrice: 1234}}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}
	if up, _ := firstLine(t, eng, o.ID); up != 1234 {
		t.Errorf("client unit_price should win: want 1234, got %d", up)
	}
}

// Fix 2: tier-1 (menu) override beats tier-2 (product) beats tier-3 (base).
func TestAddItems_ToppingTier1_BeatsTier2AndTier3(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-pho", "Regular", 2000)
	seedToppingItem(t, eng, "flat", 0, 100) // tier-3 base = 100
	seedPublishedMenuProduct(t, eng, "published")
	execSeed(t, eng, `INSERT INTO pos_product_topping_item_overrides (id, product_id, topping_group_item_id, product_sku_id, override_price) VALUES ('ov2','p1','tgi1','tsk-fs',200)`)                                           // tier-2 = 200
	execSeed(t, eng, `INSERT INTO pos_menu_product_topping_overrides (id, menu_product_id, topping_group_id, topping_group_item_id, product_sku_id, override_price, is_hidden) VALUES ('ov1','mp1','tg1','tgi1','tsk-fs',50,0)`) // tier-1 = 50

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	addOneToppingLine(t, eng, o.ID)
	if up := firstToppingUnitPrice(t, eng, o.ID); up != 50 {
		t.Errorf("topping unit_price: want 50 (tier-1), got %d", up)
	}
	if _, sub := firstLine(t, eng, o.ID); sub != 50 {
		t.Errorf("topping_subtotal: want 50, got %d", sub)
	}
}

// Fix 2 subtle case: a tier-1 ROW with NULL override_price suppresses tier-2
// and leaves the base (tier-3) price — matching resolveToppingItemSkus.
func TestAddItems_ToppingTier1_NullOverrideSuppressesTier2(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-pho", "Regular", 2000)
	seedToppingItem(t, eng, "flat", 0, 100) // base = 100
	seedPublishedMenuProduct(t, eng, "published")
	execSeed(t, eng, `INSERT INTO pos_product_topping_item_overrides (id, product_id, topping_group_item_id, product_sku_id, override_price) VALUES ('ov2','p1','tgi1','tsk-fs',200)`) // tier-2 = 200
	// tier-1 row present, override_price NULL, not hidden.
	execSeed(t, eng, `INSERT INTO pos_menu_product_topping_overrides (id, menu_product_id, topping_group_id, topping_group_item_id, product_sku_id, is_hidden) VALUES ('ov1','mp1','tg1','tgi1','tsk-fs',0)`)

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	addOneToppingLine(t, eng, o.ID)
	if up := firstToppingUnitPrice(t, eng, o.ID); up != 100 {
		t.Errorf("topping unit_price: want 100 (base; tier-1 NULL row suppresses tier-2), got %d", up)
	}
}

// Fix 2: a hidden tier-1 topping is honored (priced 0 — the menu drops it).
func TestAddItems_ToppingTier1_HiddenHonored(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-pho", "Regular", 2000)
	seedToppingItem(t, eng, "flat", 0, 100)
	seedPublishedMenuProduct(t, eng, "published")
	execSeed(t, eng, `INSERT INTO pos_menu_product_topping_overrides (id, menu_product_id, topping_group_id, topping_group_item_id, product_sku_id, is_hidden) VALUES ('ov1','mp1','tg1','tgi1','tsk-fs',1)`)

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	addOneToppingLine(t, eng, o.ID)
	if up := firstToppingUnitPrice(t, eng, o.ID); up != 0 {
		t.Errorf("hidden tier-1 topping unit_price: want 0, got %d", up)
	}
}

// Fix 2: with no tier-1 row, tier-2 (product) override applies.
func TestAddItems_ToppingTier2_WhenNoTier1Row(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-pho", "Regular", 2000)
	seedToppingItem(t, eng, "flat", 0, 100)
	seedPublishedMenuProduct(t, eng, "published")
	execSeed(t, eng, `INSERT INTO pos_product_topping_item_overrides (id, product_id, topping_group_item_id, product_sku_id, override_price) VALUES ('ov2','p1','tgi1','tsk-fs',200)`)

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	addOneToppingLine(t, eng, o.ID)
	if up := firstToppingUnitPrice(t, eng, o.ID); up != 200 {
		t.Errorf("topping unit_price: want 200 (tier-2, no tier-1), got %d", up)
	}
}

// Fix 2: an unpublished menu → menuProductIDForSku returns "" → tier-1 skipped
// gracefully → tier-2 applies (no regression for shops with no published menu).
func TestAddItems_ToppingTier1_SkippedWhenMenuUnpublished(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-pho", "Regular", 2000)
	seedToppingItem(t, eng, "flat", 0, 100)
	seedPublishedMenuProduct(t, eng, "draft") // NOT published
	execSeed(t, eng, `INSERT INTO pos_product_topping_item_overrides (id, product_id, topping_group_item_id, product_sku_id, override_price) VALUES ('ov2','p1','tgi1','tsk-fs',200)`)
	execSeed(t, eng, `INSERT INTO pos_menu_product_topping_overrides (id, menu_product_id, topping_group_id, topping_group_item_id, product_sku_id, override_price) VALUES ('ov1','mp1','tg1','tgi1','tsk-fs',50)`)

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	addOneToppingLine(t, eng, o.ID)
	if up := firstToppingUnitPrice(t, eng, o.ID); up != 200 {
		t.Errorf("topping unit_price: want 200 (tier-1 skipped, tier-2 applies), got %d", up)
	}
}

// Fix 3: free_up_to_n waives the most expensive unit in the group.
func TestAddItems_FreeUpToN_WaivesDearestUnit(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-pho", "Regular", 2000)
	execSeed(t, eng, `INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, free_quantity, min_select, max_qty_per_item) VALUES ('tg1','Extras','multiple','add','free_up_to_n',1,0,9)`)
	execSeed(t, eng, `INSERT INTO pos_products (id, name) VALUES ('p-a','Avocado'),('p-b','Bacon')`)
	execSeed(t, eng, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active) VALUES ('sk-a','p-a','Avocado','A',0,1),('sk-b','p-b','Bacon','B',0,1)`)
	execSeed(t, eng, `INSERT INTO pos_topping_group_items (id, topping_group_id, product_id) VALUES ('ti-a','tg1','p-a'),('ti-b','tg1','p-b')`)
	execSeed(t, eng, `INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, product_sku_id, extra_price) VALUES ('is-a','ti-a','sk-a',500),('is-b','ti-b','sk-b',200)`)

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{{
		ProductSkuID: "sku-pho",
		Quantity:     1,
		Toppings: []ToppingInput{
			{ToppingGroupItemID: "ti-a", ProductSkuID: "sk-a", Quantity: 1},
			{ToppingGroupItemID: "ti-b", ProductSkuID: "sk-b", Quantity: 1},
		},
	}}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}
	if _, sub := firstLine(t, eng, o.ID); sub != 200 {
		t.Errorf("topping_subtotal: want 200 (500 waived by free_up_to_n=1), got %d", sub)
	}
}

// BR-OI06: the recomputed topping_subtotal stays merge-consistent — two
// identical topping lines stack into one row.
func TestAddItems_Merge_ConsistentWithToppingPricing(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedSimpleSku(t, eng, "sku-pho", "Regular", 2000)
	seedToppingItem(t, eng, "flat", 0, 100)

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	addOneToppingLine(t, eng, o.ID)
	addOneToppingLine(t, eng, o.ID)

	var rows, qty int
	if err := eng.db.QueryRow(
		`SELECT COUNT(*), COALESCE(SUM(quantity),0) FROM order_items WHERE customer_order_id = ?`, o.ID,
	).Scan(&rows, &qty); err != nil {
		t.Fatal(err)
	}
	if rows != 1 || qty != 2 {
		t.Errorf("merge: want 1 row qty 2, got rows=%d qty=%d", rows, qty)
	}
}

// Fix 1 fallback: when the ProductSku is absent from the pos_* replica, the
// base-price override's lookup fails and menu_items.price stands.
func TestAddItems_BasePrice_FallsBackToMenuItemsWhenSkuAbsentFromReplica(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	// menu_items has the sku, but pos_product_skus does NOT.
	execSeed(t, eng, `INSERT INTO menu_items (id, sku_id, name, price, printer_group, is_active) VALUES ('mi1','sku-ghost','Ghost',1500,'kitchen',1)`)

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{{ProductSkuID: "sku-ghost", Quantity: 1}}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}
	if up, _ := firstLine(t, eng, o.ID); up != 1500 {
		t.Errorf("unit_price: want 1500 (menu_items fallback, sku not in replica), got %d", up)
	}
}

// Direct coverage for resolveToppingSnapshot's guards: a nil pointer and an
// empty topping-group-item id are both no-ops (a missing row leaves whatever
// the client sent rather than dropping the topping).
func TestResolveToppingSnapshot_Guards(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)

	eng.resolveToppingSnapshot(nil, "", "")

	empty := &ToppingInput{}
	eng.resolveToppingSnapshot(empty, "", "")
	if empty.Name != "" || empty.UnitPrice != 0 {
		t.Errorf("empty-guard should no-op, got name=%q price=%d", empty.Name, empty.UnitPrice)
	}

	// Unknown topping-group-item id → the base query returns sql.ErrNoRows and
	// the client-supplied values are preserved untouched.
	missing := &ToppingInput{ToppingGroupItemID: "does-not-exist", Name: "kept", UnitPrice: 42}
	eng.resolveToppingSnapshot(missing, "", "")
	if missing.Name != "kept" || missing.UnitPrice != 42 {
		t.Errorf("missing-row guard should preserve client values, got name=%q price=%d", missing.Name, missing.UnitPrice)
	}
}
