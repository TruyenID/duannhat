package service

import (
	"encoding/json"
	"testing"

	"github.com/google/uuid"
)

// Phase C — every applied coupon must stamp a coupon_snapshot JSON +
// redeemed_via + (optional) redeemed_by_user_id row on coupon_redemptions.
// Backend's CouponRedemption.coupon_snapshot has the same shape so Cloud
// can audit a LAN-applied coupon even after the source row was edited.

func TestCoupon_ApplyWritesSnapshotAndVia(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	couponID := uuid.NewString()
	mkCoupon(t, db, couponFixture{
		id: couponID, code: "SNAP", discountType: "flat", discountValue: 250,
		status: "draft", totalLimit: 50, minOrder: 100, maxDiscount: 500,
		perCustomerLimit: 5,
	})

	o := mkOrder(t, db, 1000)
	if _, err := coupons.ApplyCouponWithOptions(o, "SNAP", ApplyCouponOptions{
		CustomerID:       "cust-1",
		RedeemedByUserID: "user-mgr",
	}); err != nil {
		t.Fatalf("apply: %v", err)
	}

	var snap, via, userID string
	if err := db.QueryRow(`
		SELECT coupon_snapshot, redeemed_via, COALESCE(redeemed_by_user_id, '')
		FROM coupon_redemptions WHERE order_id = ?`, o).
		Scan(&snap, &via, &userID); err != nil {
		t.Fatalf("readback: %v", err)
	}

	if via != "pos" {
		t.Errorf("default via: want pos, got %q", via)
	}
	if userID != "user-mgr" {
		t.Errorf("user id: want user-mgr, got %q", userID)
	}

	// Snapshot must round-trip through json.Unmarshal with the
	// frozen coupon state.
	var got couponSnapshot
	if err := json.Unmarshal([]byte(snap), &got); err != nil {
		t.Fatalf("invalid snapshot JSON: %v\n%s", err, snap)
	}
	if got.Code != "SNAP" || got.DiscountType != "flat" || got.DiscountValue != 250 {
		t.Errorf("snapshot core fields wrong: %+v", got)
	}
	if got.MinOrderSubtotal != 100 {
		t.Errorf("snapshot min_order_subtotal: want 100, got %d", got.MinOrderSubtotal)
	}
	if got.MaxDiscountCap == nil || *got.MaxDiscountCap != 500 {
		t.Errorf("snapshot max_discount_cap: want 500, got %v", got.MaxDiscountCap)
	}
	if got.UsageLimitPerCustomer == nil || *got.UsageLimitPerCustomer != 5 {
		t.Errorf("snapshot usage_limit_per_customer: want 5, got %v", got.UsageLimitPerCustomer)
	}
}

func TestCoupon_ApplyDefaultsRedeemedViaToPos(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	mkCoupon(t, db, couponFixture{
		id: uuid.NewString(), code: "DEFV", discountType: "flat", discountValue: 50,
		status: "draft",
	})

	o := mkOrder(t, db, 1000)
	if _, err := coupons.ApplyCouponWithOptions(o, "DEFV", ApplyCouponOptions{}); err != nil {
		t.Fatalf("apply: %v", err)
	}

	var via, userID string
	_ = db.QueryRow(`
		SELECT redeemed_via, COALESCE(redeemed_by_user_id, '')
		FROM coupon_redemptions WHERE order_id = ?`, o,
	).Scan(&via, &userID)
	if via != "pos" {
		t.Errorf("default via: want 'pos', got %q", via)
	}
	if userID != "" {
		t.Errorf("anon apply: user_id should be empty, got %q", userID)
	}
}
