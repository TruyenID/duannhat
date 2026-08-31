package service

import (
	"database/sql"
	"testing"
)

// Regression: a HQ-created "trừ ¥1000" coupon must subtract 1000, not 0.
//
// Pre-fix the switch only handled "flat" and "percent" — Cloud's enum
// emits "fixed", so the apply succeeded but `discount_applied` came back
// as 0 and the receipt showed "Coupon -¥0". This test pins the contract:
// discount_type='fixed' is treated identically to legacy 'flat'.
func TestComputeDiscount_FixedTypeReturnsFlatAmount(t *testing.T) {
	c := &couponRow{
		DiscountType:  "fixed", // Cloud's CouponDiscountTypeEnum value
		DiscountValue: 1000,
	}
	got := computeDiscount(c, 5000)
	if got != 1000 {
		t.Errorf("fixed coupon: want 1000 off a 5000 subtotal, got %d", got)
	}
}

// Legacy alias still works — old wires that ship discount_type='flat'
// continue to compute the same value as 'fixed'.
func TestComputeDiscount_FlatAliasStillWorks(t *testing.T) {
	c := &couponRow{
		DiscountType:  "flat",
		DiscountValue: 250,
	}
	if got := computeDiscount(c, 5000); got != 250 {
		t.Errorf("legacy flat alias: want 250, got %d", got)
	}
}

// Fixed coupons cap at the order subtotal — never refund money.
// Mirrors backend CouponService::computeDiscount's `min($value, $subtotal)`.
func TestComputeDiscount_FixedCapsAtSubtotal(t *testing.T) {
	c := &couponRow{
		DiscountType:  "fixed",
		DiscountValue: 5000,
	}
	if got := computeDiscount(c, 1000); got != 1000 {
		t.Errorf("fixed coupon must cap at subtotal: want 1000, got %d", got)
	}
}

// Percent math regression: pre-fix the divisor was 10000 (basis-points
// assumption that never matched Cloud). Cloud stores decimal-percent
// (15.00 → 15), so 15% off ¥1000 must be ¥150, not ¥0 (1500/10000=0).
func TestComputeDiscount_PercentDecimalConvention(t *testing.T) {
	c := &couponRow{
		DiscountType:  "percent",
		DiscountValue: 15, // Cloud emits the decimal percent as int
	}
	if got := computeDiscount(c, 1000); got != 150 {
		t.Errorf("15%% off 1000 = 150, got %d (regression: basis-points divisor)", got)
	}
}

// Percent max_discount_cap honoured. Backend's `min($raw, $max_cap)`.
func TestComputeDiscount_PercentMaxCapApplies(t *testing.T) {
	c := &couponRow{
		DiscountType:   "percent",
		DiscountValue:  50,
		MaxDiscountCap: sql.NullInt64{Valid: true, Int64: 200},
	}
	// 50% of 1000 = 500, but cap = 200
	if got := computeDiscount(c, 1000); got != 200 {
		t.Errorf("percent + cap: want 200, got %d", got)
	}
}

// Unknown discount_type yields 0 — defensive default. If a future Cloud
// release ships a new enum value, the workstation falls open rather than
// guessing the math (Cloud re-validates on sync UP).
func TestComputeDiscount_UnknownTypeReturnsZero(t *testing.T) {
	c := &couponRow{DiscountType: "unknown_future_type", DiscountValue: 1000}
	if got := computeDiscount(c, 5000); got != 0 {
		t.Errorf("unknown type should yield 0, got %d", got)
	}
}
