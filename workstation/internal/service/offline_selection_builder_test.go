package service

// #1114 — BuildOfflineSelection assembles the signable selection from the
// final local rows, claiming the catalog gate stamped at create. Refusals
// (ErrOrderNotSignable) route the order to the legacy sync path.

import (
	"errors"
	"path/filepath"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func newBuilderDB(t *testing.T) *store.DB {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "builder.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return db
}

func seedSignableOrder(t *testing.T, db *store.DB, orderID string, revision int) {
	t.Helper()
	mustExecT(t, db, `INSERT INTO menu_items (id, cloud_id, name, price, menu_product_sku_id, is_active)
		VALUES ('mi-1', 'mp1', 'Phở', 1000, 'mps-11', 1)`)
	mustExecT(t, db, `
		INSERT INTO orders (id, order_code, order_number, order_type, status, opened_at,
			guest_count, subtotal, discount_amount, service_charge, tax_amount,
			total_tip, total_amount, paid_amount, catalog_revision, catalog_has_toppings,
			organization_id, brand_id, branch_id, created_at, updated_at)
		VALUES (?, 'WS-000001', 1, 'dine_in', 'closed', '2026-07-26T10:00:00Z',
			2, 0, 0, 0, 0, 0, 0, 0, ?, 1,
			'org', 'brand', 'branch', '2026-07-26T10:00:00Z', '2026-07-26T10:00:00Z')`,
		orderID, revision)
	mustExecT(t, db, `
		INSERT INTO order_items (id, customer_order_id, menu_item_id, product_sku_id,
			menu_item_name, quantity, unit_price, subtotal, status, created_at, updated_at)
		VALUES ('item-1', ?, 'mi-1', 'sku-1', 'Phở', 2, 1000, 2000, 'served',
			'2026-07-26T10:00:01Z', '2026-07-26T10:00:01Z')`, orderID)
	mustExecT(t, db, `
		INSERT INTO order_item_toppings (id, order_item_id, topping_group_item_id,
			product_sku_id, quantity, unit_price)
		VALUES ('top-1', 'item-1', 'tgi-1', 'sku-t', 1, 100)`)
	mustExecT(t, db, `INSERT INTO order_tables (order_id, table_id) VALUES (?, 'tbl-1')`, orderID)
}

func mustExecT(t *testing.T, db *store.DB, query string, args ...any) {
	t.Helper()
	if _, err := db.Exec(query, args...); err != nil {
		t.Fatalf("exec %.60s…: %v", query, err)
	}
}

func TestBuildOfflineSelection_AssemblesTheFinalRows(t *testing.T) {
	db := newBuilderDB(t)
	seedSignableOrder(t, db, "ord-1", 7)

	sel, revision, hasToppings, err := BuildOfflineSelection(db, "ord-1")
	if err != nil {
		t.Fatalf("build: %v", err)
	}
	if revision != 7 || !hasToppings {
		t.Errorf("catalog gate: revision=%d hasToppings=%v", revision, hasToppings)
	}
	if len(sel.Lines) != 1 {
		t.Fatalf("lines: %d", len(sel.Lines))
	}
	line := sel.Lines[0]
	if line.LineID != "item-1" || line.MenuProductSkuID == nil || *line.MenuProductSkuID != "mps-11" ||
		line.Quantity != 2 || line.ProductSkuID == nil || *line.ProductSkuID != "sku-1" {
		t.Errorf("line wrong: %+v", line)
	}
	if len(line.Toppings) != 1 || line.Toppings[0].ToppingGroupItemID != "tgi-1" ||
		line.Toppings[0].ProductSkuID != "sku-t" || line.Toppings[0].Quantity != 1 {
		t.Errorf("toppings wrong: %+v", line.Toppings)
	}
	if sel.OrderType != "dine_in" || sel.Channel != "workstation" || sel.PickupType != "immediate" {
		t.Errorf("selection scalars wrong: %+v", sel)
	}
	if len(sel.TableIDs) != 1 || sel.TableIDs[0] != "tbl-1" {
		t.Errorf("table ids wrong: %v", sel.TableIDs)
	}
	if sel.GuestCount == nil || *sel.GuestCount != 2 {
		t.Errorf("guest count wrong: %v", sel.GuestCount)
	}
}

func TestBuildOfflineSelection_VoidedLinesAreExcluded(t *testing.T) {
	db := newBuilderDB(t)
	seedSignableOrder(t, db, "ord-1", 7)
	mustExecT(t, db, `
		INSERT INTO order_items (id, customer_order_id, menu_item_id, menu_item_name,
			quantity, unit_price, subtotal, status, voided_at, created_at, updated_at)
		VALUES ('item-void', 'ord-1', 'mi-1', 'Huỷ', 1, 1000, 1000, 'voided',
			'2026-07-26T10:05:00Z', '2026-07-26T10:00:02Z', '2026-07-26T10:05:00Z')`)

	sel, _, _, err := BuildOfflineSelection(db, "ord-1")
	if err != nil {
		t.Fatalf("build: %v", err)
	}
	if len(sel.Lines) != 1 || sel.Lines[0].LineID != "item-1" {
		t.Errorf("voided line must be excluded: %+v", sel.Lines)
	}
}

func TestBuildOfflineSelection_Refusals(t *testing.T) {
	t.Run("no catalog revision stamped", func(t *testing.T) {
		db := newBuilderDB(t)
		seedSignableOrder(t, db, "ord-1", 0)
		if _, _, _, err := BuildOfflineSelection(db, "ord-1"); !errors.Is(err, ErrOrderNotSignable) {
			t.Errorf("want ErrOrderNotSignable, got %v", err)
		}
	})

	t.Run("line without a menu anchor", func(t *testing.T) {
		db := newBuilderDB(t)
		seedSignableOrder(t, db, "ord-1", 7)
		mustExecT(t, db, `
			INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity,
				unit_price, subtotal, status, created_at, updated_at)
			VALUES ('item-ghost', 'ord-1', 'Off menu', 1, 500, 500, 'served',
				'2026-07-26T10:00:03Z', '2026-07-26T10:00:03Z')`)
		if _, _, _, err := BuildOfflineSelection(db, "ord-1"); !errors.Is(err, ErrOrderNotSignable) {
			t.Errorf("want ErrOrderNotSignable, got %v", err)
		}
	})

	t.Run("applied coupon", func(t *testing.T) {
		db := newBuilderDB(t)
		seedSignableOrder(t, db, "ord-1", 7)
		mustExecT(t, db, `INSERT INTO coupons (id, code, name, discount_type, discount_value)
			VALUES ('c-1', 'OFF10', 'Off', 'fixed', 100)`)
		mustExecT(t, db, `INSERT INTO order_coupons (id, order_id, coupon_id, coupon_code, discount_applied)
			VALUES ('oc-1', 'ord-1', 'c-1', 'OFF10', 100)`)
		if _, _, _, err := BuildOfflineSelection(db, "ord-1"); !errors.Is(err, ErrOrderNotSignable) {
			t.Errorf("want ErrOrderNotSignable, got %v", err)
		}
	})

	t.Run("no billable lines", func(t *testing.T) {
		db := newBuilderDB(t)
		seedSignableOrder(t, db, "ord-1", 7)
		mustExecT(t, db, `UPDATE order_items SET voided_at = '2026-07-26T10:05:00Z' WHERE id = 'item-1'`)
		if _, _, _, err := BuildOfflineSelection(db, "ord-1"); !errors.Is(err, ErrOrderNotSignable) {
			t.Errorf("want ErrOrderNotSignable, got %v", err)
		}
	})
}
