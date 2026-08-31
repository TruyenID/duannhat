package service

import (
	"errors"
	"testing"
)

func TestOrderItemsMutable_ParityCloudAddItemsGate(t *testing.T) {
	for _, s := range []Status{
		StatusAwaitingConfirmation, StatusConfirmed, StatusOpen, StatusPending,
	} {
		if !OrderItemsMutable(s) {
			t.Errorf("status %q should allow item mutations", s)
		}
	}
	for _, s := range []Status{
		StatusDining, StatusCheckout, StatusPaying, StatusClosed, StatusVoided, StatusExpired,
	} {
		if OrderItemsMutable(s) {
			t.Errorf("status %q must block item mutations (#2256)", s)
		}
	}
}

func TestOrderCouponMutable_ParityCloudAssertOrderModifiable(t *testing.T) {
	for _, s := range []Status{
		StatusOpen, StatusDining, StatusPending, StatusConfirmed, StatusCheckout,
	} {
		if !OrderCouponMutable(s) {
			t.Errorf("status %q should allow coupon mutations", s)
		}
	}
	for _, s := range []Status{
		StatusAwaitingConfirmation, StatusPaying, StatusClosed, StatusVoided,
	} {
		if OrderCouponMutable(s) {
			t.Errorf("status %q must block coupon apply/release (#2256)", s)
		}
	}
}

func TestAddItems_RejectsCheckoutOrder(t *testing.T) {
	db := newCouponExtTestDB(t)
	eng := NewOrderEngine(db)
	orderID := mkOrder(t, db, 1000)
	if _, err := db.Exec(`UPDATE orders SET status = ? WHERE id = ?`, StatusCheckout, orderID); err != nil {
		t.Fatalf("set status: %v", err)
	}
	_, err := eng.AddItems(orderID, []CreateItemInput{{ProductSkuID: "sku-1", Quantity: 1, UnitPrice: 500}})
	if !errors.Is(err, ErrOrderNotOpen) {
		t.Fatalf("checkout add-items want ErrOrderNotOpen, got %v", err)
	}
}

func TestApplyCoupon_RejectsPayingOrder(t *testing.T) {
	db := newCouponExtTestDB(t)
	eng := NewOrderEngine(db)
	coupons := NewCouponEngine(db, eng)
	mkCoupon(t, db, couponFixture{
		code: "PAY500", discountType: "flat", discountValue: 500, status: "draft",
	})
	orderID := mkOrder(t, db, 1000)
	if _, err := db.Exec(`UPDATE orders SET status = ? WHERE id = ?`, StatusPaying, orderID); err != nil {
		t.Fatalf("set status: %v", err)
	}
	_, err := coupons.ApplyCoupon(orderID, "PAY500")
	if !errors.Is(err, ErrCouponOrderNotModifiable) {
		t.Fatalf("paying apply-coupon want ErrCouponOrderNotModifiable, got %v", err)
	}
}

func TestReleaseCoupon_RejectsClosedOrder(t *testing.T) {
	db := newCouponExtTestDB(t)
	eng := NewOrderEngine(db)
	coupons := NewCouponEngine(db, eng)
	orderID := mkOrder(t, db, 1000)
	if _, err := db.Exec(`UPDATE orders SET status = ? WHERE id = ?`, StatusClosed, orderID); err != nil {
		t.Fatalf("set status: %v", err)
	}
	_, err := coupons.ReleaseCoupon(orderID)
	if !errors.Is(err, ErrCouponOrderNotModifiable) {
		t.Fatalf("closed release-coupon want ErrCouponOrderNotModifiable, got %v", err)
	}
}
