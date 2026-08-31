package service

import (
	"path/filepath"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// #1239 — one product on two active menus must be taxed by the menu line the
// cashier tapped, not by whichever row SQLite returns first.
//
// This is not a contrived arrangement. The workstation pulls EVERY menu active
// today (Cloud's MenuController uses listActiveBranchMenusForShopByDay, plural)
// and writes one menu_items row per menu line, deduped ON CONFLICT(id).
// menu_items.sku_id has no unique constraint. And consumption context is
// modelled exactly this way: the takeaway menu carries the REDUCED rate on the
// same product the dine-in menu sells at the standard rate.
//
// The lookup was `WHERE sku_id = ? LIMIT 1` with no ORDER BY, so the rate on a
// sale was decided by row order.
func TestCreateItem_TaxFollowsTheMenuLineTapped(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "twomenu.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	e := NewOrderEngine(db)

	exec := func(q string) {
		t.Helper()
		if _, err := db.Exec(q); err != nil {
			t.Fatalf("seed: %v\n%s", err, q)
		}
	}

	exec(`INSERT INTO shop_settings (key, value) VALUES ('currency_code','JPY')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)
	exec(`INSERT INTO tax_types (id, code, name, rate, is_default, is_active)
		VALUES ('tt-std', 'STANDARD', '標準税率', 10, 1, 1)`)
	exec(`INSERT INTO tax_types (id, code, name, rate, is_default, is_active)
		VALUES ('tt-red', 'REDUCED', '軽減税率', 8, 0, 1)`)

	// ONE product, TWO active menu lines — dine-in at 10%, takeaway at 8%.
	// Same sku_id on both rows, which the schema permits.
	exec(`INSERT INTO menu_items (id, sku_id, name, price, is_active, tax_type_id)
		VALUES ('mi-dinein', 'sku-1', 'Bentō', 1000, 1, 'tt-std')`)
	exec(`INSERT INTO menu_items (id, sku_id, name, price, is_active, tax_type_id)
		VALUES ('mi-takeaway', 'sku-1', 'Bentō', 1000, 1, 'tt-red')`)

	for _, tc := range []struct {
		name       string
		menuItemID string
		wantRate   float64
	}{
		{"tapped the dine-in line", "mi-dinein", 10},
		{"tapped the takeaway line", "mi-takeaway", 8},
	} {
		t.Run(tc.name, func(t *testing.T) {
			o, err := e.Create(CreateOrderInput{
				OrderType: "dine_in",
				Items: []CreateItemInput{
					// Both ids sent, as a real client does. The menu line is the
					// precise one; the SKU alone cannot tell the two apart.
					{ProductSkuID: "sku-1", MenuItemID: tc.menuItemID, Quantity: 1},
				},
			}, nil)
			if err != nil {
				t.Fatalf("create: %v", err)
			}
			if len(o.Items) != 1 {
				t.Fatalf("want 1 item, got %d", len(o.Items))
			}

			var rate float64
			if err := db.QueryRow(
				`SELECT COALESCE(tax_rate, -1) FROM order_items WHERE id = ?`, o.Items[0].ID,
			).Scan(&rate); err != nil {
				t.Fatal(err)
			}
			if rate != tc.wantRate {
				t.Fatalf("tax_rate = %g, want %g — the line was taxed by the wrong menu", rate, tc.wantRate)
			}
		})
	}
}
