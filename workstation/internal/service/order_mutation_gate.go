package service

import "fmt"

// OrderItemsMutable mirrors Cloud addItems / updateItem / voidItem assertStatus
// and CustomerOrderPricingResolution::resolveLine (#2256).
func OrderItemsMutable(s Status) bool {
	switch s {
	case StatusAwaitingConfirmation, StatusConfirmed, StatusOpen, StatusPending:
		return true
	default:
		return false
	}
}

// OrderCouponMutable mirrors Cloud OrderCouponService::assertOrderModifiable.
func OrderCouponMutable(s Status) bool {
	switch s {
	case StatusOpen, StatusDining, StatusPending, StatusConfirmed, StatusCheckout:
		return true
	default:
		return false
	}
}

func errOrderItemsNotMutable(status Status) error {
	return fmt.Errorf("%w: cannot change items while order status is %q", ErrOrderNotOpen, status)
}

func errOrderCouponNotMutable(status Status) error {
	return fmt.Errorf("%w: order status is %q", ErrCouponOrderNotModifiable, status)
}
