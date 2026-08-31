package service

import "testing"

// #2189 — `order_coupons.discount_applied` phải bám khoản giảm THỰC TẾ.
//
// Cột này từng INSERT-only: đơn 2 dòng 5000+5000 áp coupon fixed 8000, void một
// dòng ⇒ máy trạm chỉ còn tính tiền 5000 giảm (kẹp về subtotal), nhưng phiếu
// 精算 (`SUM(oc.discount_applied)`) vẫn khai 8000 — hai báo cáo của cùng một ca
// nói hai chuyện (#2154 đã chữa nửa Cloud, đây là nửa LAN).
func TestCoupon_DiscountAppliedTracksReprice(t *testing.T) {
	e, db := newOrderEngineForTest(t)
	coupons := NewCouponEngine(db, e)

	seedTaxType(t, e, "std", "標準", 10, true)
	mustExecTax(t, e, `INSERT INTO pos_products (id, name) VALUES ('p1','Item')`)
	mustExecTax(t, e, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-1','p1','Reg','SKU-1',5000,1), ('sku-2','p1','Reg2','SKU-2',5000,1)`)
	mustExecTax(t, e, `INSERT INTO menu_items (id, sku_id, name, price, is_active, tax_type_id)
		VALUES ('mi-1','sku-1','Item',5000,1,'std'), ('mi-2','sku-2','Item2',5000,1,'std')`)

	o, err := e.Create(CreateOrderInput{OrderType: "dine_in", Items: []CreateItemInput{
		{ProductSkuID: "sku-1", Quantity: 1},
		{ProductSkuID: "sku-2", Quantity: 1},
	}}, nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}

	mustExecTax(t, e, `INSERT INTO coupons (id, code, name, discount_type, discount_value,
		min_order_subtotal, status, stacking_mode)
		VALUES ('c1','SAVE8K','Save 8000','fixed', 8000, 0, 'draft', 'exclusive')`)

	if _, err := coupons.ApplyCouponWithOptions(o.ID, "SAVE8K", ApplyCouponOptions{}); err != nil {
		t.Fatalf("apply: %v", err)
	}

	var applied int
	_ = db.QueryRow(`SELECT discount_applied FROM order_coupons
		WHERE order_id = ? AND released_at IS NULL`, o.ID).Scan(&applied)
	if applied != 8000 {
		t.Fatalf("sau apply: discount_applied = %d, muốn 8000", applied)
	}

	// Void một dòng ⇒ subtotal còn 5000 ⇒ khoản giảm THỰC TẾ kẹp về 5000.
	if len(o.Items) != 2 {
		t.Fatalf("muốn 2 dòng, có %d", len(o.Items))
	}
	if _, err := e.VoidItem(o.ID, o.Items[0].ID, "test"); err != nil {
		t.Fatalf("void: %v", err)
	}

	_ = db.QueryRow(`SELECT discount_applied FROM order_coupons
		WHERE order_id = ? AND released_at IS NULL`, o.ID).Scan(&applied)
	if applied != 5000 {
		t.Errorf("sau void: discount_applied = %d, muốn 5000 (số máy trạm THẬT SỰ tính tiền) — phiếu 精算 đang khai dư %d", applied, applied-5000)
	}

	// Số LÚC ÁP không mất — nó sống ở coupon_redemptions (nguồn trừ ngược của release).
	var atApply int
	_ = db.QueryRow(`SELECT discount_amount FROM coupon_redemptions
		WHERE order_id = ? AND released_at IS NULL`, o.ID).Scan(&atApply)
	if atApply != 8000 {
		t.Errorf("coupon_redemptions.discount_amount = %d, muốn giữ nguyên 8000 (số lúc áp)", atApply)
	}
}

// #2189 — release trên đơn đã co giỏ phải trừ ngược ĐỦ số lúc áp.
//
// `ReleaseCoupon` trừ khỏi `orders.discount_amount` (số YÊU CẦU, #2083). Trước
// bản sửa nó đọc `discount_applied`; khi cột đó bám số ĐÃ KẸP thì release trừ
// thiếu (8000 − 5000), để lại 3000 discount tồn dư trên đơn không còn coupon
// nào — sai cả tiền lẫn ngữ nghĩa.
func TestCoupon_ReleaseAfterShrinkLeavesNoResidualDiscount(t *testing.T) {
	e, db := newOrderEngineForTest(t)
	coupons := NewCouponEngine(db, e)

	seedTaxType(t, e, "std", "標準", 10, true)
	mustExecTax(t, e, `INSERT INTO pos_products (id, name) VALUES ('p1','Item')`)
	mustExecTax(t, e, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-1','p1','Reg','SKU-1',5000,1), ('sku-2','p1','Reg2','SKU-2',5000,1)`)
	mustExecTax(t, e, `INSERT INTO menu_items (id, sku_id, name, price, is_active, tax_type_id)
		VALUES ('mi-1','sku-1','Item',5000,1,'std'), ('mi-2','sku-2','Item2',5000,1,'std')`)

	o, err := e.Create(CreateOrderInput{OrderType: "dine_in", Items: []CreateItemInput{
		{ProductSkuID: "sku-1", Quantity: 1},
		{ProductSkuID: "sku-2", Quantity: 1},
	}}, nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}

	mustExecTax(t, e, `INSERT INTO coupons (id, code, name, discount_type, discount_value,
		min_order_subtotal, status, stacking_mode)
		VALUES ('c1','SAVE8K','Save 8000','fixed', 8000, 0, 'draft', 'exclusive')`)

	if _, err := coupons.ApplyCouponWithOptions(o.ID, "SAVE8K", ApplyCouponOptions{}); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if _, err := e.VoidItem(o.ID, o.Items[0].ID, "test"); err != nil {
		t.Fatalf("void: %v", err)
	}

	released, err := coupons.ReleaseCoupon(o.ID)
	if err != nil {
		t.Fatalf("release: %v", err)
	}
	if released.DiscountAmount != 0 {
		t.Errorf("sau release: discount_amount = %d, muốn 0 — còn tồn dư nghĩa là release trừ theo số kẹp thay vì số lúc áp", released.DiscountAmount)
	}
	// Và đơn định giá lại như chưa từng có coupon: 5000 @10% ⇒ total 5500.
	if released.TotalAmount != 5500 {
		t.Errorf("sau release: total = %d, muốn 5500", released.TotalAmount)
	}
}
