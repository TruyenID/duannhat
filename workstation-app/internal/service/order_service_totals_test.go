package service

import (
	"testing"
)

// seedShopRates seeds shop_settings with the rates pos-web's cart
// breakdown displays. Returns a helper that callers can fan into Create
// without re-typing the SKU INSERTs.
func seedShopRates(t *testing.T, eng *OrderEngine, taxRate, serviceRate string) {
	t.Helper()
	if _, err := eng.db.Exec(`
		INSERT INTO shop_settings (key, value) VALUES
		  ('tax_rate', ?),
		  ('service_charge_rate', ?)
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
		taxRate, serviceRate); err != nil {
		t.Fatalf("seed rates: %v", err)
	}
}

// computeLegacySingleRate wraps the plan-043 §8 engine as a single-group
// (legacy branch-rate) compute, reproducing the pre-plan-043 single-rate
// numbers so the existing cart-breakdown expectations still hold. This is the
// path taken for an order whose lines carry no per-line tax snapshot (nothing
// synced) — the engine groups them at the legacy rate.
func (e *OrderEngine) computeLegacySingleRate(subtotal, discount int) (tax float64, serviceCharge, total int) {
	rateSubtotals := map[string]float64{}
	if subtotal > 0 {
		rateSubtotals[rateKey(e.legacyTaxRate())] = float64(subtotal)
	}
	return e.priceRateSubtotals(rateSubtotals, discount, e.pricesIncludeTax())
}

// Verifies the additive math matches Cloud's recalculateTotals
// (subtotal=7400 → tax=740 + service=370 → total=8510) for the legacy
// single-rate path.
func TestComputeOrderTotals_MirrorsCloudAdditive(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedShopRates(t, eng, "10.00", "5.00")

	tax, svc, total := eng.computeLegacySingleRate(7400, 0)
	if tax != 740 {
		t.Errorf("tax want 740, got %g", tax)
	}
	if svc != 370 {
		t.Errorf("service_charge want 370, got %d", svc)
	}
	if total != 8510 {
		t.Errorf("total want 8510 (7400+740+370), got %d", total)
	}
}

func TestComputeOrderTotals_AppliesDiscountBeforeRate(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedShopRates(t, eng, "10.00", "5.00")

	// discounted = 7400-400 = 7000; tax=700; svc=350; total=7000+700+350-0=8050
	tax, svc, total := eng.computeLegacySingleRate(7400, 400)
	if tax != 700 {
		t.Errorf("tax want 700 (10%% of 7000), got %g", tax)
	}
	if svc != 350 {
		t.Errorf("service_charge want 350 (5%% of 7000), got %d", svc)
	}
	if total != 8050 {
		t.Errorf("total want 8050 (7400-400+700+350), got %d", total)
	}
}

func TestComputeOrderTotals_ZeroServiceRateProducesZeroFee(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedShopRates(t, eng, "10.00", "0.00")

	_, svc, total := eng.computeLegacySingleRate(1000, 0)
	if svc != 0 {
		t.Errorf("zero rate must produce zero fee, got %d", svc)
	}
	if total != 1100 {
		t.Errorf("total want 1100 (no service charge), got %d", total)
	}
}

func TestComputeOrderTotals_MissingRatesFallBackToZero(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	// No shop_settings rows + engine built with rate 0. plan-043 (T3.4)
	// REMOVED the hard 10% fallback — an un-synced shop prices at 0% tax,
	// NEVER an invented 10%.
	tax, svc, total := eng.computeLegacySingleRate(1000, 0)
	if tax != 0 {
		t.Errorf("no-config tax want 0 (no hard-10%% fallback), got %g", tax)
	}
	if svc != 0 {
		t.Errorf("missing service_rate must produce 0, got %d", svc)
	}
	if total != 1000 {
		t.Errorf("total want 1000 (no tax, no service), got %d", total)
	}
}

// End-to-end: Create → AddItems → check that the order's stored
// service_charge column reflects the rates synced into shop_settings.
// Pre-fix workstation hard-coded service_charge=0 in every code path.
func TestCreateAddItems_StampsServiceChargeFromShopSettings(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedShopRates(t, eng, "10.00", "5.00")

	o := seedSkuAndOrder(t, eng, "sku-X", 1000) // qty=2 → subtotal=2000
	if o.ServiceCharge != 100 {
		t.Errorf("service_charge want 100 (5%% of 2000), got %d", o.ServiceCharge)
	}
	if o.TaxAmount != 200 {
		t.Errorf("tax_amount want 200 (10%% of 2000), got %g", o.TaxAmount)
	}
	if o.TotalAmount != 2300 {
		t.Errorf("total_amount want 2300 (2000+200+100), got %d", o.TotalAmount)
	}
}

// recalcOrderTotals path — qty edit must keep service_charge consistent
// with the new subtotal.
func TestRecalcOrderTotals_AfterQtyEdit_UpdatesServiceCharge(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	seedShopRates(t, eng, "10.00", "5.00")
	o := seedSkuAndOrder(t, eng, "sku-X", 1000) // qty=2 → 2000
	itemID := o.Items[0].ID

	five := 5
	got, err := eng.UpdateItem(o.ID, itemID, ItemPatch{Quantity: &five})
	if err != nil {
		t.Fatalf("qty: %v", err)
	}
	if got.Subtotal != 5000 {
		t.Fatalf("subtotal want 5000, got %d", got.Subtotal)
	}
	if got.ServiceCharge != 250 {
		t.Errorf("service_charge want 250 (5%% of 5000), got %d", got.ServiceCharge)
	}
	if got.TaxAmount != 500 {
		t.Errorf("tax_amount want 500 (10%% of 5000), got %g", got.TaxAmount)
	}
	if got.TotalAmount != 5750 {
		t.Errorf("total want 5750, got %d", got.TotalAmount)
	}
}

// TestStampLineTaxAmounts_ReconcilesToGroupTax pins that a recompute ALLOCATES
// the once-per-group tax back to the per-line tax_amount snapshots (largest
// remainder) so Σ line == order.tax_amount — 3×¥333 @8% → 27+27+26 = 80, never
// the per-line-summed 81. The seeded 27s are the pre-fix per-line-rounded values
// the recompute must correct.
func TestStampLineTaxAmounts_ReconcilesToGroupTax(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	if _, err := db.Exec(`INSERT INTO shop_settings (key, value) VALUES ('currency_code','JPY')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`); err != nil {
		t.Fatalf("seed currency: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO orders
		(id, order_code, order_type, status, opened_at,
		 subtotal, discount_amount, tax_amount, service_charge, total_amount, paid_amount,
		 is_tax_included, created_at, updated_at)
		VALUES ('o1','WS-1','takeaway','open',datetime('now'),
		 999, 0, 0, 0, 0, 0, 0, datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	for _, id := range []string{"i1", "i2", "i3"} {
		if _, err := db.Exec(`INSERT INTO order_items
			(id, customer_order_id, quantity, unit_price, subtotal, status, tax_rate, tax_amount)
			VALUES (?, 'o1', 1, 333, 333, 'served', 8, 27)`, id); err != nil {
			t.Fatalf("seed item %s: %v", id, err)
		}
	}

	if err := eng.RecalcOrderTotals("o1"); err != nil {
		t.Fatalf("recalc: %v", err)
	}

	var orderTax int
	if err := db.QueryRow(`SELECT tax_amount FROM orders WHERE id='o1'`).Scan(&orderTax); err != nil {
		t.Fatal(err)
	}
	if orderTax != 80 {
		t.Errorf("order.tax_amount = %d, want 80 (group-once)", orderTax)
	}

	rows, err := db.Query(`SELECT tax_amount FROM order_items WHERE customer_order_id='o1' ORDER BY tax_amount ASC`)
	if err != nil {
		t.Fatal(err)
	}
	defer rows.Close()
	var lineTaxes []int
	sum := 0
	for rows.Next() {
		var v int
		if err := rows.Scan(&v); err != nil {
			t.Fatal(err)
		}
		lineTaxes = append(lineTaxes, v)
		sum += v
	}
	if sum != 80 {
		t.Errorf("Σ line tax_amount = %d, want 80 (allocated, not per-line 81)", sum)
	}
	if len(lineTaxes) != 3 || lineTaxes[0] != 26 || lineTaxes[1] != 27 || lineTaxes[2] != 27 {
		t.Errorf("per-line snapshots = %v, want [26 27 27]", lineTaxes)
	}
	if sum != orderTax {
		t.Errorf("Σ line %d != order.tax_amount %d — must reconcile", sum, orderTax)
	}
}
