package service

import "testing"

// TestCoupon_RecomputesPerGroupTaxOnDiscountedBase (plan-043 T3.5) — after a
// coupon is applied the per-rate tax is re-derived on the DISCOUNTED base
// (pro-rata across groups), not by naïvely subtracting the discount from the
// pre-coupon total. Release restores the pre-coupon numbers.
func TestCoupon_RecomputesPerGroupTaxOnDiscountedBase(t *testing.T) {
	e, db := newOrderEngineForTest(t)
	coupons := NewCouponEngine(db, e)

	seedTaxType(t, e, "std", "標準", 10, 10, true)
	mustExecTax(t, e, `INSERT INTO pos_products (id, name) VALUES ('p1','Item')`)
	mustExecTax(t, e, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-1','p1','Reg','SKU-1',5000,1)`)
	mustExecTax(t, e, `INSERT INTO menu_items (id, sku_id, name, price, is_active, tax_type_id, is_alcohol)
		VALUES ('mi-1','sku-1','Item',5000,1,'std',0)`)

	o, err := e.Create(CreateOrderInput{OrderType: "dine_in", Items: []CreateItemInput{
		{ProductSkuID: "sku-1", Quantity: 1},
	}}, nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	// Pre-coupon: subtotal 5000 @10% → tax 500, total 5500.
	if o.TaxAmount != 500 || o.TotalAmount != 5500 {
		t.Fatalf("pre-coupon: tax=%g total=%d, want 500/5500", o.TaxAmount, o.TotalAmount)
	}

	// A ¥1000 fixed coupon.
	mustExecTax(t, e, `INSERT INTO coupons (id, code, name, discount_type, discount_value,
		min_order_subtotal, status, stacking_mode)
		VALUES ('c1','SAVE1K','Save 1000','fixed', 1000, 0, 'draft', 'exclusive')`)

	applied, err := coupons.ApplyCouponWithOptions(o.ID, "SAVE1K", ApplyCouponOptions{})
	if err != nil {
		t.Fatalf("apply: %v", err)
	}
	if applied.DiscountApplied != 1000 {
		t.Fatalf("discount = %d, want 1000", applied.DiscountApplied)
	}

	// After coupon: taxable 4000 @10% → tax 400, total 4400 (NOT 5500-1000=4500,
	// the tax-blind bug).
	after, _ := e.GetByID(o.ID)
	if after.TaxAmount != 400 {
		t.Errorf("post-coupon tax = %g, want 400 (10%% of discounted 4000)", after.TaxAmount)
	}
	if after.TotalAmount != 4400 {
		t.Errorf("post-coupon total = %d, want 4400", after.TotalAmount)
	}

	// Release restores the pre-coupon numbers.
	released, err := coupons.ReleaseCoupon(o.ID)
	if err != nil {
		t.Fatalf("release: %v", err)
	}
	if released.TaxAmount != 500 || released.TotalAmount != 5500 {
		t.Errorf("post-release: tax=%g total=%d, want 500/5500", released.TaxAmount, released.TotalAmount)
	}
}
