package service

// #555 M5 — split-by-items must reconcile with the order's grand total.
// Bucket sums are raw item subtotals, but TotalAmount also carries
// tax/service (and any discount): ComputeSplitBill now allocates that
// difference proportionally to each bucket's subtotal share, so
// Σ bill_totals == total_amount exactly on every taxed order.

import (
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func seedSplitOrder(t *testing.T, dbName string, subtotal, tax, service, total int, items [][2]int) (*OrderEngine, string, []string) {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), dbName))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := db.Exec(`
		INSERT INTO orders (
			id, order_code, order_number, order_type, status,
			opened_at, guest_count,
			subtotal, discount_amount, service_charge, tax_amount,
			total_tip, total_amount, paid_amount,
			organization_id, brand_id, branch_id,
			created_at, updated_at
		) VALUES ('order-split', 'WS-000042', 42, 'dine_in', 'checkout',
			?, 2, ?, 0, ?, ?, 0, ?, 0, 'org', 'brand', 'branch', ?, ?)
	`, now, subtotal, service, tax, total, now, now); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	ids := make([]string, len(items))
	for i, it := range items {
		id := "item-" + string(rune('a'+i))
		ids[i] = id
		if _, err := db.Exec(`
			INSERT INTO order_items (
				id, customer_order_id, menu_item_name,
				quantity, unit_price, subtotal,
				printer_group, status, print_status,
				created_at, updated_at
			) VALUES (?, 'order-split', ?, ?, ?, ?, 'kitchen', 'served', 'printed', ?, ?)
		`, id, "Item "+id, it[0], it[1], it[0]*it[1], now, now); err != nil {
			t.Fatalf("seed item %s: %v", id, err)
		}
	}
	return NewOrderEngine(db), "order-split", ids
}

// Two items 1000 + 3000, 10% tax → total 4400. Pre-fix the buckets held the
// bare subtotals (1000/3000, Σ 4000 ≠ 4400); now the 400 tax spreads 100/300.
func TestComputeSplitBill_ByItemsAllocatesTaxProportionally(t *testing.T) {
	engine, orderID, ids := seedSplitOrder(t, "split_tax.db",
		4000, 400, 0, 4400, [][2]int{{1, 1000}, {1, 3000}})

	res, err := engine.ComputeSplitBill(orderID, SplitBillRequest{
		Mode:        SplitByItems,
		SplitCount:  2,
		ItemBuckets: [][]string{{ids[0]}, {ids[1]}},
	})
	if err != nil {
		t.Fatalf("ComputeSplitBill: %v", err)
	}

	if got := res.BillTotals[0] + res.BillTotals[1]; got != 4400 {
		t.Fatalf("Σ bills must equal total_amount: want 4400, got %d (%v)", got, res.BillTotals)
	}
	if res.BillTotals[0] != 1100 || res.BillTotals[1] != 3300 {
		t.Errorf("proportional allocation: want [1100 3300], got %v", res.BillTotals)
	}
}

// Indivisible remainder: 3 equal items, total carries +100 tax that does not
// divide by 3. Cumulative rounding keeps every bucket within one unit and the
// sum exact.
func TestComputeSplitBill_ByItemsRoundingStaysExact(t *testing.T) {
	engine, orderID, ids := seedSplitOrder(t, "split_round.db",
		3000, 100, 0, 3100, [][2]int{{1, 1000}, {1, 1000}, {1, 1000}})

	res, err := engine.ComputeSplitBill(orderID, SplitBillRequest{
		Mode:        SplitByItems,
		SplitCount:  3,
		ItemBuckets: [][]string{{ids[0]}, {ids[1]}, {ids[2]}},
	})
	if err != nil {
		t.Fatalf("ComputeSplitBill: %v", err)
	}

	sum := 0
	for i, bt := range res.BillTotals {
		sum += bt
		if bt < 1033 || bt > 1034 {
			t.Errorf("bucket %d drifted beyond one unit: %d (%v)", i, bt, res.BillTotals)
		}
	}
	if sum != 3100 {
		t.Errorf("Σ bills: want 3100, got %d (%v)", sum, res.BillTotals)
	}
}

// No tax/service (total == Σ subtotals) — behaviour is unchanged.
func TestComputeSplitBill_ByItemsNoAdjustmentKeepsSubtotals(t *testing.T) {
	engine, orderID, ids := seedSplitOrder(t, "split_plain.db",
		4000, 0, 0, 4000, [][2]int{{1, 1000}, {1, 3000}})

	res, err := engine.ComputeSplitBill(orderID, SplitBillRequest{
		Mode:        SplitByItems,
		SplitCount:  2,
		ItemBuckets: [][]string{{ids[0]}, {ids[1]}},
	})
	if err != nil {
		t.Fatalf("ComputeSplitBill: %v", err)
	}
	if res.BillTotals[0] != 1000 || res.BillTotals[1] != 3000 {
		t.Errorf("want [1000 3000], got %v", res.BillTotals)
	}
}
