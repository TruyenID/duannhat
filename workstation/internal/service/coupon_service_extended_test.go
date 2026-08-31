package service

import (
	"errors"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

// Test fixtures for the LAN-offline coupon flow: per-customer cap +
// HH-coupon exclusivity downgrade. Keeps each test isolated by spinning
// up its own temp SQLite via the same helper the existing coupon tests
// use.

func newCouponExtTestDB(t *testing.T) *store.DB {
	return newPromoTestDB(t) // reuse helper from promotion_service_test.go
}

func mkOrder(t *testing.T, db *store.DB, subtotal int) string {
	t.Helper()
	id := uuid.NewString()
	now := time.Now().UTC().Format(time.RFC3339)
	_, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, discount_amount, total_amount, paid_amount, created_at, updated_at)
		VALUES (?, ?, 'spot', 'open', ?, ?, 0, ?, 0, ?, ?)`,
		id, "T-"+id[:8], now, subtotal, subtotal, now, now)
	if err != nil {
		t.Fatalf("insert order: %v", err)
	}
	return id
}

func mkCoupon(t *testing.T, db *store.DB, fields couponFixture) {
	t.Helper()
	_, err := db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, max_discount_cap, status, stacking_mode, times_used,
		    usage_limit_total, usage_limit_per_customer, exclusive_with_promotions)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'exclusive', 0, ?, ?, ?)`,
		fields.id, fields.code, fields.code, fields.discountType, fields.discountValue,
		fields.minOrder, nullIfNonZero(fields.maxDiscount), fields.status,
		nullIfZero(fields.totalLimit), nullIfZero(fields.perCustomerLimit), boolInt(fields.exclusiveWithPromos))
	if err != nil {
		t.Fatalf("insert coupon: %v", err)
	}
}

type couponFixture struct {
	id                  string
	code                string
	discountType        string
	discountValue       int
	minOrder            int
	maxDiscount         int
	status              string
	totalLimit          int
	perCustomerLimit    int
	exclusiveWithPromos bool
}

func nullIfZero(v int) any {
	if v == 0 {
		return nil
	}
	return v
}

func nullIfNonZero(v int) any {
	if v == 0 {
		return nil
	}
	return v
}

func TestCoupon_PerCustomerLimitBlocksThirdUse(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	couponID := uuid.NewString()
	mkCoupon(t, db, couponFixture{
		id: couponID, code: "VIP", discountType: "flat", discountValue: 100,
		status: "active", perCustomerLimit: 2,
	})

	customerID := uuid.NewString()

	// First + second redemptions OK.
	o1 := mkOrder(t, db, 1000)
	if _, err := coupons.ApplyCouponWithOptions(o1, "VIP", ApplyCouponOptions{CustomerID: customerID}); err != nil {
		t.Fatalf("first apply: %v", err)
	}
	o2 := mkOrder(t, db, 1000)
	if _, err := coupons.ApplyCouponWithOptions(o2, "VIP", ApplyCouponOptions{CustomerID: customerID}); err != nil {
		t.Fatalf("second apply: %v", err)
	}

	// Third must fail.
	o3 := mkOrder(t, db, 1000)
	_, err := coupons.ApplyCouponWithOptions(o3, "VIP", ApplyCouponOptions{CustomerID: customerID})
	if !errors.Is(err, ErrCouponPerCustomerExhausted) {
		t.Errorf("expected ErrCouponPerCustomerExhausted, got %v", err)
	}
}

// A positive usage_limit_per_customer marks the coupon customer-scoped:
// applying it without an identified customer must be rejected with
// customer_required — parity with backend CouponService::assertCustomerEligible
// (issue #543: this check was missing on the workstation LAN path, so
// pos-web never surfaced "This coupon requires a customer.").
func TestCoupon_CustomerRequiredWhenLimited(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	couponID := uuid.NewString()
	mkCoupon(t, db, couponFixture{
		id: couponID, code: "NEEDCUST", discountType: "flat", discountValue: 100,
		status: "active", perCustomerLimit: 1,
	})

	o := mkOrder(t, db, 1000)
	_, err := coupons.ApplyCouponWithOptions(o, "NEEDCUST", ApplyCouponOptions{CustomerID: ""})
	if !errors.Is(err, ErrCouponCustomerRequired) {
		t.Errorf("expected ErrCouponCustomerRequired, got %v", err)
	}
}

// A 0/NULL usage_limit_per_customer means no restriction at all: the
// coupon applies anonymously, and applying it WITH a customer must not
// be mis-read as "already used" on the first redemption (used >= 0).
func TestCoupon_UnlimitedPerCustomerAllowsAnyone(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	couponID := uuid.NewString()
	mkCoupon(t, db, couponFixture{
		id: couponID, code: "OPEN", discountType: "flat", discountValue: 100,
		status: "active", perCustomerLimit: 0,
	})

	// Anonymous apply passes.
	o1 := mkOrder(t, db, 1000)
	if _, err := coupons.ApplyCouponWithOptions(o1, "OPEN", ApplyCouponOptions{CustomerID: ""}); err != nil {
		t.Errorf("anon apply on unlimited coupon should pass, got %v", err)
	}
	// First apply WITH a customer must not trip the per-customer cap.
	o2 := mkOrder(t, db, 1000)
	if _, err := coupons.ApplyCouponWithOptions(o2, "OPEN", ApplyCouponOptions{CustomerID: uuid.NewString()}); err != nil {
		t.Errorf("identified apply on unlimited coupon should pass, got %v", err)
	}
}

func TestCoupon_ExclusivePromotionRejectsByDefault(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	couponID := uuid.NewString()
	mkCoupon(t, db, couponFixture{
		id: couponID, code: "TEN", discountType: "flat", discountValue: 100,
		status: "active",
	})

	orderID := mkOrder(t, db, 1000)
	// Plant an item carrying a promotion that's exclusive-with-coupons.
	promoID := uuid.NewString()
	if _, err := db.Exec(`
		INSERT INTO menu_promotions (id, name, discount_type, discount_value,
		    is_active, exclusive_with_coupons, priority)
		VALUES (?, 'HH', 'percent', 10, 1, 1, 100)`, promoID); err != nil {
		t.Fatal(err)
	}
	skuID := uuid.NewString()
	if _, err := db.Exec(`
		INSERT INTO order_items (id, customer_order_id, product_sku_id,
		    menu_item_name, quantity, unit_price, subtotal, status,
		    original_unit_price, promotion_id, print_status, printer_group,
		    created_at, updated_at)
		VALUES (?, ?, ?, 'foo', 1, 900, 900, 'pending', 1000, ?, 'pending', 'kitchen', datetime('now'), datetime('now'))`,
		uuid.NewString(), orderID, skuID, promoID); err != nil {
		t.Fatal(err)
	}

	_, err := coupons.ApplyCouponWithOptions(orderID, "TEN", ApplyCouponOptions{})
	if !errors.Is(err, ErrCouponExcludedByPromotion) {
		t.Errorf("expected ErrCouponExcludedByPromotion, got %v", err)
	}
}

func TestCoupon_DowngradeExclusivePromotionsRestoresOriginalPrice(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	mkCoupon(t, db, couponFixture{
		id: uuid.NewString(), code: "DOWN", discountType: "flat",
		discountValue: 100, status: "active",
	})

	orderID := mkOrder(t, db, 900) // subtotal AFTER HH (-100)
	promoID := uuid.NewString()
	if _, err := db.Exec(`
		INSERT INTO menu_promotions (id, name, discount_type, discount_value,
		    is_active, exclusive_with_coupons, priority)
		VALUES (?, 'HH', 'amount', 100, 1, 1, 100)`, promoID); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO order_items (id, customer_order_id, product_sku_id,
		    menu_item_name, quantity, unit_price, subtotal, status,
		    original_unit_price, promotion_id, print_status, printer_group,
		    created_at, updated_at)
		VALUES (?, ?, ?, 'foo', 1, 900, 900, 'pending', 1000, ?, 'pending', 'kitchen', datetime('now'), datetime('now'))`,
		uuid.NewString(), orderID, uuid.NewString(), promoID); err != nil {
		t.Fatal(err)
	}

	applied, err := coupons.ApplyCouponWithOptions(orderID, "DOWN", ApplyCouponOptions{
		DowngradeExclusivePromotions: true,
	})
	if err != nil {
		t.Fatalf("apply: %v", err)
	}
	if applied.DiscountApplied != 100 {
		t.Errorf("want discount=100, got %d", applied.DiscountApplied)
	}

	// Verify item unit_price was restored to 1000 (downgrade ran).
	var unitPrice int
	if err := db.QueryRow("SELECT unit_price FROM order_items WHERE customer_order_id = ?", orderID).Scan(&unitPrice); err != nil {
		t.Fatal(err)
	}
	if unitPrice != 1000 {
		t.Errorf("unit_price after downgrade should be 1000, got %d", unitPrice)
	}
}

func TestCoupon_RedemptionLedgerWritten(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	couponID := uuid.NewString()
	mkCoupon(t, db, couponFixture{
		id: couponID, code: "LEDGER", discountType: "flat",
		discountValue: 200, status: "active",
	})

	orderID := mkOrder(t, db, 1000)
	customerID := uuid.NewString()
	if _, err := coupons.ApplyCouponWithOptions(orderID, "LEDGER", ApplyCouponOptions{CustomerID: customerID}); err != nil {
		t.Fatalf("apply: %v", err)
	}

	var count int
	if err := db.QueryRow(`
		SELECT COUNT(*) FROM coupon_redemptions
		WHERE coupon_id = ? AND order_id = ? AND customer_id = ? AND released_at IS NULL`,
		couponID, orderID, customerID).Scan(&count); err != nil {
		t.Fatal(err)
	}
	if count != 1 {
		t.Errorf("expected 1 redemption row, got %d", count)
	}
}

func TestCoupon_ReleaseMarksRedemption(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	mkCoupon(t, db, couponFixture{
		id: uuid.NewString(), code: "REL", discountType: "flat",
		discountValue: 100, status: "active",
	})

	orderID := mkOrder(t, db, 1000)
	if _, err := coupons.ApplyCoupon(orderID, "REL"); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if _, err := coupons.ReleaseCoupon(orderID); err != nil {
		t.Fatalf("release: %v", err)
	}

	var releasedAt string
	if err := db.QueryRow(
		"SELECT COALESCE(released_at, '') FROM coupon_redemptions WHERE order_id = ?",
		orderID).Scan(&releasedAt); err != nil {
		t.Fatal(err)
	}
	if releasedAt == "" {
		t.Error("released_at should be set after ReleaseCoupon")
	}
}
