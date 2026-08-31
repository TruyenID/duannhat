package service

import (
	"database/sql"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// #1392 — the order path for spotlight ("Khung giờ ưu đãi") lines.
//
// Every test here sells the SAME SKU twice in the SAME order, once from the
// menu and once from the spotlight, and asserts the two lines differ. Asserting
// the pair in one test is the point: a single-surface assertion passes just as
// well when the resolver ignores the surface entirely and happens to return the
// value that test expected.

// seedSpotlight builds a spotlight around one SKU:
//   - a menu-priced SKU (`sku`, `menuPrice`) via seedSimpleSku
//   - a section + membership `fsp-1` carrying `fspTaxTypeID` ("" → NULL/inherit)
//   - a promo price row at `promoPrice` (skipped when promoPrice < 0)
func seedSpotlight(t *testing.T, eng *OrderEngine, sku string, menuPrice, promoPrice int, fspTaxTypeID string) {
	t.Helper()
	seedSimpleSku(t, eng, sku, "Regular", menuPrice)

	if _, err := eng.db.Exec(`
		INSERT INTO pos_floating_sections (id, name, priority, is_active)
		VALUES ('fs-1','Khung giờ ưu đãi',0,1)`); err != nil {
		t.Fatal(err)
	}
	var taxType any
	if fspTaxTypeID != "" {
		taxType = fspTaxTypeID
	}
	if _, err := eng.db.Exec(`
		INSERT INTO pos_floating_section_products (id, floating_section_id, product_id, tax_type_id, is_active)
		VALUES ('fsp-1','fs-1','p1',?,1)`, taxType); err != nil {
		t.Fatal(err)
	}
	if promoPrice >= 0 {
		if _, err := eng.db.Exec(`
			INSERT INTO pos_floating_section_product_skus
				(id, floating_section_product_id, product_sku_id, selling_price, is_active)
			VALUES ('fss-1','fsp-1',?,?,1)`, sku, promoPrice); err != nil {
			t.Fatal(err)
		}
	}
}

// seedTwoTaxTypes mirrors a brand with 標準 10% (the branch/brand default) and
// 軽減 8% (what the spotlight charges).
func seedTwoTaxTypes(t *testing.T, eng *OrderEngine) {
	t.Helper()
	if _, err := eng.db.Exec(`
		INSERT INTO tax_types (id, code, name, rate, is_default, is_active) VALUES
			('tt-std','standard','標準',10,1,1),
			('tt-red','reduced','軽減',8,0,1)`); err != nil {
		t.Fatal(err)
	}
}

// The headline acceptance: one SKU, two surfaces, two prices — in one order.
func TestAddItems_SpotlightLineTakesPromoPriceAndMenuLineDoesNot(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedSpotlight(t, eng, "sku-A", 2300, 1800, "")

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: "sku-A", Quantity: 1},
		{ProductSkuID: "sku-A", Quantity: 1, FloatingSectionProductID: "fsp-1"},
	}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	rows, err := db.Query(`
		SELECT COALESCE(floating_section_product_id, ''), unit_price
		FROM order_items WHERE customer_order_id = ? ORDER BY unit_price DESC`, o.ID)
	if err != nil {
		t.Fatal(err)
	}
	defer rows.Close()

	got := map[string]int{}
	for rows.Next() {
		var fsp string
		var price int
		if err := rows.Scan(&fsp, &price); err != nil {
			t.Fatal(err)
		}
		got[fsp] = price
	}
	if len(got) != 2 {
		t.Fatalf("want two distinct lines (menu + spotlight), got %d: %v", len(got), got)
	}
	if got[""] != 2300 {
		t.Errorf("menu line: want 2300 (pos_product_skus.selling_price), got %d", got[""])
	}
	if got["fsp-1"] != 1800 {
		t.Errorf("spotlight line: want 1800 (promo price), got %d", got["fsp-1"])
	}
}

// The tax counterpart, asserted the same way. The spotlight's collapsed type is
// what GET /pos/floating-sections emits and what CustomerMenuService displays
// (`fsp.tax_type_id ?? product.tax_type_id`), so charging it is charging the
// rate the guest was shown; the menu line keeps the branch/brand default.
func TestAddItems_SpotlightLineTakesSectionTaxTypeAndMenuLineDoesNot(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedTwoTaxTypes(t, eng)
	seedSpotlight(t, eng, "sku-A", 2300, 1800, "tt-red")

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: "sku-A", Quantity: 1},
		{ProductSkuID: "sku-A", Quantity: 1, FloatingSectionProductID: "fsp-1"},
	}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	assertLineTax(t, db, o.ID, "", "tt-std", 10)
	assertLineTax(t, db, o.ID, "fsp-1", "tt-red", 8)
}

// A membership with NO tax type of its own inherits, exactly as a menu line
// with no override does — NULL means "carry on down the chain", not 0%.
func TestAddItems_SpotlightWithoutTaxTypeInheritsBrandDefault(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedTwoTaxTypes(t, eng)
	seedSpotlight(t, eng, "sku-A", 2300, 1800, "")

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: "sku-A", Quantity: 1, FloatingSectionProductID: "fsp-1"},
	}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	assertLineTax(t, db, o.ID, "fsp-1", "tt-std", 10)
}

// The spotlight's own tier-1 topping override wins over the menu's for a
// spotlight line — and the menu's still wins for the menu line. Same topping,
// same order, two prices.
func TestAddItems_SpotlightToppingUsesSectionOverride(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedSpotlight(t, eng, "sku-A", 2300, 1800, "")
	seedToppingCatalogue(t, eng)

	// Menu tier-1: 500. Spotlight tier-1: 100. Catalogue base: 900.
	if _, err := db.Exec(`
		INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m-1','Menu','published',0)`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_menu_products (id, menu_id, product_id, is_active) VALUES ('mp-1','m-1','p1',1)`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_menu_product_topping_overrides
			(id, menu_product_id, topping_group_id, topping_group_item_id, product_sku_id, override_price, is_hidden)
		VALUES ('mo-1','mp-1','tg-x','tgi-x','tsk-x',500,0)`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_floating_section_topping_overrides
			(id, floating_section_product_id, topping_group_id, topping_group_item_id, product_sku_id, override_price, is_hidden)
		VALUES ('fo-1','fsp-1','tg-x','tgi-x','tsk-x',100,0)`); err != nil {
		t.Fatal(err)
	}

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	// A slice per line: resolveToppingSnapshot fills UnitPrice in place, so a
	// shared backing array would let the first line's resolved price stand in
	// for the second's and hide the very thing under test.
	topping := func() []ToppingInput {
		return []ToppingInput{{ToppingGroupItemID: "tgi-x", ProductSkuID: "tsk-x", Quantity: 1}}
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: "sku-A", Quantity: 1, Toppings: topping()},
		{ProductSkuID: "sku-A", Quantity: 1, FloatingSectionProductID: "fsp-1", Toppings: topping()},
	}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	rows, err := db.Query(`
		SELECT COALESCE(oi.floating_section_product_id, ''), oit.unit_price
		FROM order_item_toppings oit
		JOIN order_items oi ON oi.id = oit.order_item_id
		WHERE oi.customer_order_id = ?`, o.ID)
	if err != nil {
		t.Fatal(err)
	}
	defer rows.Close()
	got := map[string]int{}
	for rows.Next() {
		var fsp string
		var price int
		if err := rows.Scan(&fsp, &price); err != nil {
			t.Fatal(err)
		}
		got[fsp] = price
	}
	if got[""] != 500 {
		t.Errorf("menu line topping: want 500 (menu tier-1), got %d", got[""])
	}
	if got["fsp-1"] != 100 {
		t.Errorf("spotlight line topping: want 100 (spotlight tier-1), got %d", got["fsp-1"])
	}
}

// A client-supplied id that names no membership for this SKU is DROPPED, not
// trusted: the line prices, rates and stores exactly as an ordinary menu line.
// Without the ownership join a stale id would apply another product's promo
// tier and leave a line that looks perfectly ordinary afterwards.
func TestAddItems_UnknownOrMismatchedSpotlightIDIsIgnored(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedTwoTaxTypes(t, eng)
	seedSpotlight(t, eng, "sku-A", 2300, 1800, "tt-red")

	// A second product, NOT in the spotlight.
	if _, err := db.Exec(`INSERT INTO pos_products (id, name) VALUES ('p2','Bún')`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-B','p2','Regular','SKU-B',3000,1)`); err != nil {
		t.Fatal(err)
	}

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: "sku-B", Quantity: 1, FloatingSectionProductID: "fsp-1"},
		{ProductSkuID: "sku-A", Quantity: 1, FloatingSectionProductID: "does-not-exist"},
	}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	var n int
	if err := db.QueryRow(`
		SELECT COUNT(*) FROM order_items
		WHERE customer_order_id = ? AND floating_section_product_id IS NOT NULL`, o.ID).Scan(&n); err != nil {
		t.Fatal(err)
	}
	if n != 0 {
		t.Errorf("want 0 lines carrying an unvalidated attribution, got %d", n)
	}

	// …and neither line took the promo price or the promo rate.
	var price int
	if err := db.QueryRow(
		`SELECT unit_price FROM order_items WHERE customer_order_id = ? AND product_sku_id = 'sku-B'`, o.ID,
	).Scan(&price); err != nil {
		t.Fatal(err)
	}
	if price != 3000 {
		t.Errorf("cross-product id must not price the line: want 3000, got %d", price)
	}
	assertLineTax(t, db, o.ID, "", "tt-std", 10)
}

// A membership priced ABOVE the menu never raises the line — Cloud's rule is
// "floating-section price if LOWER (never higher)", and a spotlight that made
// things dearer would be a promotion that charges more for being tapped.
func TestAddItems_SpotlightPriceNeverRaisesTheLine(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedSpotlight(t, eng, "sku-A", 2300, 9900, "")

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: "sku-A", Quantity: 1, FloatingSectionProductID: "fsp-1"},
	}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	var price int
	if err := db.QueryRow(
		`SELECT unit_price FROM order_items WHERE customer_order_id = ?`, o.ID,
	).Scan(&price); err != nil {
		t.Fatal(err)
	}
	if price != 2300 {
		t.Errorf("want the menu price 2300 (spotlight is dearer), got %d", price)
	}
}

// Two lines of the same SKU at the same price must not merge across surfaces:
// they can carry different rates and different topping tiers, so stacking them
// would silently re-attribute one of the two.
func TestAddItems_DoesNotMergeAcrossSurfaces(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	// Promo price == menu price: the merge key's unit_price no longer separates
	// the two lines, so only the surface can.
	seedSpotlight(t, eng, "sku-A", 2300, 2300, "")

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: "sku-A", Quantity: 1},
		{ProductSkuID: "sku-A", Quantity: 1, FloatingSectionProductID: "fsp-1"},
	}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	var n int
	if err := db.QueryRow(
		`SELECT COUNT(*) FROM order_items WHERE customer_order_id = ?`, o.ID,
	).Scan(&n); err != nil {
		t.Fatal(err)
	}
	if n != 2 {
		t.Errorf("want 2 lines (menu + spotlight kept apart), got %d", n)
	}
}

// ─── helpers ────────────────────────────────────────────────────────────────

// assertLineTax asserts the stamped snapshot of the line carrying this
// attribution ("" = the menu line).
func assertLineTax(t *testing.T, db *store.DB, orderID, fsp, wantType string, wantRate float64) {
	t.Helper()
	var (
		gotType string
		gotRate sql.NullFloat64
	)
	if err := db.QueryRow(`
		SELECT COALESCE(tax_type_id, ''), tax_rate
		FROM order_items
		WHERE customer_order_id = ? AND COALESCE(floating_section_product_id, '') = ?`,
		orderID, fsp,
	).Scan(&gotType, &gotRate); err != nil {
		t.Fatalf("scan tax for line fsp=%q: %v", fsp, err)
	}
	if gotType != wantType {
		t.Errorf("line fsp=%q: tax_type_id = %q, want %q", fsp, gotType, wantType)
	}
	if !gotRate.Valid || gotRate.Float64 != wantRate {
		t.Errorf("line fsp=%q: tax_rate = %v (valid=%v), want %v", fsp, gotRate.Float64, gotRate.Valid, wantRate)
	}
}

func seedToppingCatalogue(t *testing.T, eng *OrderEngine) {
	t.Helper()
	if _, err := eng.db.Exec(`INSERT INTO pos_products (id, name) VALUES ('p-top','Trứng')`); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.db.Exec(`
		INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('tsk-x','p-top','Regular','SKU-top',0,1)`); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.db.Exec(`
		INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, min_select, max_qty_per_item)
		VALUES ('tg-x','Thêm','multiple','add','flat',0,3)`); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.db.Exec(`
		INSERT INTO pos_topping_group_items (id, topping_group_id, product_id)
		VALUES ('tgi-x','tg-x','p-top')`); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.db.Exec(`
		INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, product_sku_id, extra_price)
		VALUES ('its-x','tgi-x','tsk-x',900)`); err != nil {
		t.Fatal(err)
	}
}

// ─── the WINDOW (review round 1) ─────────────────────────────────────────────
//
// Every test above seeds a section with NO schedule rows, which means "open all
// day" — so none of them could ever fail on a closed window. These are that
// missing half.
//
// The case that matters is not an operator switching a spotlight off; it is the
// window closing on schedule, which happens every single day. A POS panel
// loaded at 18:55 still shows the 17:00-19:00 tile at 19:05 because the catalog
// is cached client-side, and the tap must NOT be priced at the promo price —
// Cloud (FloatingSectionPriceResolver) will not price it that way either, and
// two books for one sale is the actual damage.
//
// Each case is deterministic without freezing a clock: `days_of_week = 0`
// matches no day at all, an expired date range is in the past, and is_active=0
// is a flag. No test here depends on what time it is when it runs.

// sellOneOfEach adds the same SKU twice — once as an ordinary menu line, once
// naming the spotlight — and returns unit_price × quantity keyed by the
// recorded surface. Quantity matters: when both taps resolve to the SAME
// surface the two lines MERGE into one row of quantity 2 (BR-OI06), so summing
// unit_price alone would read a merged pair as a single sale.
func sellOneOfEach(t *testing.T, eng *OrderEngine, db *store.DB, sku string) map[string]int {
	t.Helper()
	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: sku, Quantity: 1},
		{ProductSkuID: sku, Quantity: 1, FloatingSectionProductID: "fsp-1"},
	}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	rows, err := db.Query(`
		SELECT COALESCE(floating_section_product_id, ''), unit_price, quantity
		FROM order_items WHERE customer_order_id = ?`, o.ID)
	if err != nil {
		t.Fatal(err)
	}
	defer rows.Close()

	got := map[string]int{}
	for rows.Next() {
		var fsp string
		var price, qty int
		if err := rows.Scan(&fsp, &price, &qty); err != nil {
			t.Fatal(err)
		}
		got[fsp] += price * qty
	}

	return got
}

func TestAddItems_SpotlightClosedWindowGetsNoPromoPrice(t *testing.T) {
	cases := []struct {
		name string
		seed string
	}{
		{
			// The daily case: a real schedule that matches no day, standing in
			// for "the happy hour is over" without depending on the wall clock.
			name: "schedule matches no day",
			seed: `INSERT INTO pos_floating_section_schedules
			       (id, floating_section_id, days_of_week, start_time, end_time, is_active)
			       VALUES ('sch-1','fs-1',0,'17:00','19:00',1)`,
		},
		{
			name: "date range already over",
			seed: `UPDATE pos_floating_sections
			       SET start_date = '2020-01-01', end_date = '2020-01-31' WHERE id = 'fs-1'`,
		},
		{
			name: "section switched off",
			seed: `UPDATE pos_floating_sections SET is_active = 0 WHERE id = 'fs-1'`,
		},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			eng, db := newOrderEngineForTest(t)
			seedSpotlight(t, eng, "sku-A", 2300, 1800, "")
			if _, err := eng.db.Exec(c.seed); err != nil {
				t.Fatal(err)
			}

			got := sellOneOfEach(t, eng, db, "sku-A")

			// Both lines are ordinary menu lines now, so they MERGE: one row,
			// no recorded surface, two units at the menu price.
			if _, attributed := got["fsp-1"]; attributed {
				t.Errorf("a sale outside the window must record no surface, got %v", got)
			}
			if got[""] != 4600 {
				t.Errorf("both units must price at the menu price (2×2300), got %v", got)
			}
		})
	}
}

// The other half of the same coin: with the window OPEN the promo still
// applies. Without this, deleting the whole feature would leave the tests above
// green — they only prove the promo can be withheld.
func TestAddItems_SpotlightOpenWindowStillGetsPromoPrice(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedSpotlight(t, eng, "sku-A", 2300, 1800, "")
	// Every day, and a window wide enough that no run time falls outside it:
	// 00:00 → 00:00 is the full-24h shape FloatingSectionOpenAt treats as a wrap.
	if _, err := eng.db.Exec(`
		INSERT INTO pos_floating_section_schedules
		    (id, floating_section_id, days_of_week, start_time, end_time, is_active)
		VALUES ('sch-1','fs-1',127,'00:00','00:00',1)`); err != nil {
		t.Fatal(err)
	}

	got := sellOneOfEach(t, eng, db, "sku-A")

	if got["fsp-1"] != 1800 {
		t.Errorf("open window: spotlight line must take the promo price 1800, got %v", got)
	}
	if got[""] != 2300 {
		t.Errorf("open window: menu line must stay at 2300, got %v", got)
	}
}

// #1392 review round 2 — the EDIT path, which round 2 broke by teaching only
// half of it about time.
//
// Round 2 made resolveFloatingLine window-aware. The edit path read it for the
// PRICE and the TOPPING tier but took the TAX from floatingTaxTypeIDTx, which
// is deliberately window-blind (a snapshot must outlive its surface). While the
// resolver was time-blind the two always agreed; afterwards they split, and a
// line came out of an ordinary topping edit carrying a MENU price with a PROMO
// rate.
//
// Concretely: 18:50 in happy hour the guest buys at 1800 / 8%. At 19:05 they
// ask for a topping. Before this fix the line became 2300 / 8% AND kept its
// floating_section_product_id, so every later reResolveOrderLines re-stamped 8%
// while Cloud — which re-derives from the menu, since sync-UP carries no
// membership id — booked 10%. Two books, one sale.
func TestUpdateItem_SpotlightLineAfterWindowClosesFallsBackWholly(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedTwoTaxTypes(t, eng)
	seedSpotlight(t, eng, "sku-A", 2300, 1800, "tt-red")
	// Open now: every day, 00:00→00:00 is the full-24h wrap shape.
	if _, err := eng.db.Exec(`
		INSERT INTO pos_floating_section_schedules
		    (id, floating_section_id, days_of_week, start_time, end_time, is_active)
		VALUES ('sch-1','fs-1',127,'00:00','00:00',1)`); err != nil {
		t.Fatal(err)
	}

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: "sku-A", Quantity: 1, FloatingSectionProductID: "fsp-1"},
	}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}
	// Sold inside the window: promo price, promo rate, surface recorded.
	assertLineTax(t, db, o.ID, "fsp-1", "tt-red", 8)

	var itemID string
	if err := db.QueryRow(
		`SELECT id FROM order_items WHERE customer_order_id = ?`, o.ID,
	).Scan(&itemID); err != nil {
		t.Fatalf("scan item id: %v", err)
	}

	// The window shuts. Same deterministic trick the AddItems cases use — a
	// schedule matching no day — so the test needs no clock control.
	if _, err := eng.db.Exec(
		`UPDATE pos_floating_section_schedules SET days_of_week = 0 WHERE id = 'sch-1'`,
	); err != nil {
		t.Fatal(err)
	}

	// An ordinary topping edit — NOT a SKU swap, which #1148 refuses anyway.
	empty := []ToppingInput{}
	if _, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Toppings: &empty}); err != nil {
		t.Fatalf("UpdateItem: %v", err)
	}

	// Price, tax AND attribution must all tell the same story: this is no
	// longer a spotlight sale.
	var (
		gotPrice int
		gotFsp   string
	)
	if err := db.QueryRow(`
		SELECT unit_price, COALESCE(floating_section_product_id, '')
		FROM order_items WHERE id = ?`, itemID,
	).Scan(&gotPrice, &gotFsp); err != nil {
		t.Fatalf("scan line: %v", err)
	}
	if gotPrice != 2300 {
		t.Errorf("closed window: unit_price = %d, want the menu price 2300", gotPrice)
	}
	if gotFsp != "" {
		t.Errorf("closed window: line still flagged as spotlight %q — reResolveOrderLines "+
			"would keep re-stamping the promo rate while Cloud books the menu one", gotFsp)
	}
	// The whole point: the rate must follow the price, not the dead surface.
	assertLineTax(t, db, o.ID, "", "tt-std", 10)
}

// The other half — a spotlight still OPEN keeps everything through an edit.
// Without this, deleting the feature entirely would still pass the test above.
func TestUpdateItem_SpotlightLineInsideWindowKeepsPromoPriceAndRate(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedTwoTaxTypes(t, eng)
	seedSpotlight(t, eng, "sku-A", 2300, 1800, "tt-red")
	if _, err := eng.db.Exec(`
		INSERT INTO pos_floating_section_schedules
		    (id, floating_section_id, days_of_week, start_time, end_time, is_active)
		VALUES ('sch-1','fs-1',127,'00:00','00:00',1)`); err != nil {
		t.Fatal(err)
	}

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: "sku-A", Quantity: 1, FloatingSectionProductID: "fsp-1"},
	}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	var itemID string
	if err := db.QueryRow(
		`SELECT id FROM order_items WHERE customer_order_id = ?`, o.ID,
	).Scan(&itemID); err != nil {
		t.Fatalf("scan item id: %v", err)
	}

	empty := []ToppingInput{}
	if _, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Toppings: &empty}); err != nil {
		t.Fatalf("UpdateItem: %v", err)
	}

	var (
		gotPrice int
		gotFsp   string
	)
	if err := db.QueryRow(`
		SELECT unit_price, COALESCE(floating_section_product_id, '')
		FROM order_items WHERE id = ?`, itemID,
	).Scan(&gotPrice, &gotFsp); err != nil {
		t.Fatalf("scan line: %v", err)
	}
	if gotPrice != 1800 {
		t.Errorf("open window: unit_price = %d, want the promo price 1800", gotPrice)
	}
	if gotFsp != "fsp-1" {
		t.Errorf("open window: attribution must survive an edit, got %q", gotFsp)
	}
	assertLineTax(t, db, o.ID, "fsp-1", "tt-red", 8)
}

// A RETIRED spotlight is not a closed one. The membership row is gone from the
// replica (the catalog pull wipes and rewrites), and audit-fix B5 says the line
// keeps its stamped type rather than dropping to the branch default — a
// snapshot outlives the surface it came from. Collapsing this into the
// window-closed branch would silently re-rate history.
func TestUpdateItem_RetiredSpotlightKeepsStampedTaxType(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	seedTwoTaxTypes(t, eng)
	seedSpotlight(t, eng, "sku-A", 2300, 1800, "tt-red")
	if _, err := eng.db.Exec(`
		INSERT INTO pos_floating_section_schedules
		    (id, floating_section_id, days_of_week, start_time, end_time, is_active)
		VALUES ('sch-1','fs-1',127,'00:00','00:00',1)`); err != nil {
		t.Fatal(err)
	}

	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if _, err := eng.AddItems(o.ID, []CreateItemInput{
		{ProductSkuID: "sku-A", Quantity: 1, FloatingSectionProductID: "fsp-1"},
	}); err != nil {
		t.Fatalf("AddItems: %v", err)
	}

	var itemID string
	if err := db.QueryRow(
		`SELECT id FROM order_items WHERE customer_order_id = ?`, o.ID,
	).Scan(&itemID); err != nil {
		t.Fatalf("scan item id: %v", err)
	}

	// The whole spotlight is wiped by a catalog pull — membership included.
	if _, err := eng.db.Exec(`DELETE FROM pos_floating_section_products WHERE id = 'fsp-1'`); err != nil {
		t.Fatal(err)
	}

	empty := []ToppingInput{}
	if _, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Toppings: &empty}); err != nil {
		t.Fatalf("UpdateItem: %v", err)
	}

	// B5: the stamped 8% stands; the attribution stays so the history reads.
	assertLineTax(t, db, o.ID, "fsp-1", "tt-red", 8)
}
