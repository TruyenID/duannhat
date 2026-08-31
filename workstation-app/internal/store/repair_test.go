package store

import (
	"testing"
)

// On a freshly-migrated DB the repair function must be a no-op — every
// column + table it would create already exists. The smoke test catches
// regressions where someone adds a non-idempotent statement to
// repairLegacySchema (e.g. an unconditional ALTER).
func TestRepairLegacySchema_NoOpOnFreshDB(t *testing.T) {
	db := openTestDB(t)
	if err := db.repairLegacySchema(); err != nil {
		t.Fatalf("repair must be idempotent on fresh DB: %v", err)
	}
	if err := db.repairLegacySchema(); err != nil {
		t.Fatalf("second repair must be idempotent: %v", err)
	}
}

// Simulates the pre-aa436c3 layout where menu_items shipped without
// sku_id (and several other columns added in the rewritten 004_menu.sql)
// + handy_menu_cache / menu_meta / menu_schedules never existed. After
// repair: PullMenu's INSERT into menu_items.sku_id must succeed, and
// PullHandyMenu's INSERT into handy_menu_cache must find the table.
func TestRepairLegacySchema_RestoresLegacyTablesAndColumns(t *testing.T) {
	db := openTestDB(t)

	// Force the legacy state: drop the new columns + tables that
	// pre-aa436c3 DBs lack. ALTER TABLE DROP COLUMN landed in SQLite
	// 3.35; modernc.org/sqlite ships a recent enough engine. Drop the
	// dependent index first or the engine rejects the column-drop.
	if _, err := db.conn.Exec("DROP INDEX IF EXISTS idx_menu_items_sku_id"); err != nil {
		t.Fatalf("simulate legacy: drop idx_menu_items_sku_id: %v", err)
	}
	for _, col := range []string{"sku_id", "name_ja", "discount_price", "discount_pct", "printer_group", "image_url", "cloud_updated_at"} {
		if _, err := db.conn.Exec("ALTER TABLE menu_items DROP COLUMN " + col); err != nil {
			t.Fatalf("simulate legacy: drop %s: %v", col, err)
		}
	}
	for _, tbl := range []string{"handy_menu_cache", "menu_meta", "menu_schedules", "kitchen_ticket_counters", "order_item_toppings"} {
		if _, err := db.conn.Exec("DROP TABLE IF EXISTS " + tbl); err != nil {
			t.Fatalf("simulate legacy: drop %s: %v", tbl, err)
		}
	}
	// And drop order_items.sku_variant_name to simulate the
	// 012_idempotency_composite_pk vs 012_order_item_sku_variant
	// version-collision the migration cleanup created.
	if _, err := db.conn.Exec(`ALTER TABLE order_items DROP COLUMN sku_variant_name`); err != nil {
		t.Fatalf("simulate legacy: drop order_items.sku_variant_name: %v", err)
	}

	// Confirm the simulated regression: sku_id INSERT must fail.
	if _, err := db.conn.Exec(`INSERT INTO menu_items (id, name, sku_id) VALUES ('x', 'n', 'sk')`); err == nil {
		t.Fatalf("setup invariant: expected sku_id INSERT to fail before repair")
	}

	if err := db.repairLegacySchema(); err != nil {
		t.Fatalf("repair: %v", err)
	}

	// After repair the column + tables are back.
	if _, err := db.conn.Exec(`INSERT INTO menu_items (id, name, sku_id) VALUES ('m1', 'Pho', 'sk1')`); err != nil {
		t.Errorf("post-repair menu_items.sku_id INSERT: %v", err)
	}
	if _, err := db.conn.Exec(`INSERT INTO handy_menu_cache (day_of_week, payload) VALUES (3, '[]')`); err != nil {
		t.Errorf("post-repair handy_menu_cache INSERT: %v", err)
	}
	if _, err := db.conn.Exec(`INSERT INTO menu_meta (cloud_menu_id) VALUES ('cm1')`); err != nil {
		t.Errorf("post-repair menu_meta INSERT: %v", err)
	}
	if _, err := db.conn.Exec(`INSERT INTO menu_schedules (id, menu_id, day_of_week) VALUES ('s1','m1',3)`); err != nil {
		t.Errorf("post-repair menu_schedules INSERT: %v", err)
	}

	// Plan-extension: AddItems writes sku_variant_name on every line
	// and inserts into order_item_toppings when the cart carries
	// topping selections. Both must round-trip after repair so
	// `POST /pos/orders/{id}/items` stops returning 500.
	if _, err := db.conn.Exec(`
		INSERT INTO orders (id, order_code, order_number, order_type, status,
		    opened_at, guest_count, subtotal, discount_amount, service_charge,
		    tax_amount, total_tip, total_amount, paid_amount,
		    organization_id, brand_id, branch_id, created_at, updated_at)
		VALUES ('o1','ORD-1',1,'spot','open', datetime('now'), 1, 0, 0, 0, 0, 0, 0, 0,
		    'org','brand','branch', datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.conn.Exec(`
		INSERT INTO order_items (id, customer_order_id, menu_item_name, sku_variant_name,
		    quantity, unit_price, subtotal, printer_group, status, print_status,
		    topping_subtotal, created_at, updated_at)
		VALUES ('it1','o1','Pho','Regular',1,1000,1000,'kitchen','pending','pending',0,
		    datetime('now'), datetime('now'))`); err != nil {
		t.Errorf("post-repair order_items.sku_variant_name INSERT: %v", err)
	}
	if _, err := db.conn.Exec(`
		INSERT INTO order_item_toppings (id, order_item_id, topping_group_item_id,
		    product_sku_id, modifier_type)
		VALUES ('t1','it1','tgi1','sk1','add')`); err != nil {
		t.Errorf("post-repair order_item_toppings INSERT: %v", err)
	}
	if _, err := db.conn.Exec(`INSERT INTO kitchen_ticket_counters (date, counter) VALUES ('2026-06-15', 1)`); err != nil {
		t.Errorf("post-repair kitchen_ticket_counters INSERT: %v", err)
	}
}

// pos_menu_sections must accept the same (section_id) shared across two
// menus — this is the natural shape Cloud's MenuCatalog feed emits and
// the pre-020 single-column PK silently aborted the entire menu_catalog
// sync tx with `UNIQUE constraint failed: pos_menu_sections.id`.
func TestPosMenuSections_CompositePKAcceptsSharedSection(t *testing.T) {
	db := openTestDB(t)

	if _, err := db.conn.Exec(`INSERT INTO pos_menus (id, name) VALUES ('mA','A'), ('mB','B')`); err != nil {
		t.Fatalf("seed pos_menus: %v", err)
	}
	if _, err := db.conn.Exec(`
		INSERT INTO pos_menu_sections (id, menu_id, name) VALUES ('sec1','mA','Mains')`); err != nil {
		t.Fatalf("first section row: %v", err)
	}
	// Same section_id, different menu_id — must succeed under the
	// composite PK that migration 020 introduces.
	if _, err := db.conn.Exec(`
		INSERT INTO pos_menu_sections (id, menu_id, name) VALUES ('sec1','mB','Mains')`); err != nil {
		t.Errorf("shared section across menus must be accepted: %v", err)
	}
	// Re-insert (sec1, mA) must still fail — composite still enforces
	// uniqueness per (id, menu_id).
	if _, err := db.conn.Exec(`
		INSERT INTO pos_menu_sections (id, menu_id, name) VALUES ('sec1','mA','Mains')`); err == nil {
		t.Errorf("composite PK must reject duplicate (id, menu_id)")
	}
}
