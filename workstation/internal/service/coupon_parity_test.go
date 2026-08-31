package service

import (
	"errors"
	"testing"
	"time"

	"github.com/google/uuid"
)

// ─── Computed status tests ─────────────────────────────────────────────────
//
// Backend's CouponService.computeStatus derives the lifecycle state at
// read-time from stored draft|paused + valid_from/until + times_used.
// Workstation mirrors the derivation in CouponEngine.computeStatus so
// LAN-mode apply rejects scheduled / expired / exhausted coupons
// without a Cloud round-trip.

func TestCoupon_ScheduledRejectsBeforeValidFrom(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	couponID := uuid.NewString()
	future := time.Now().UTC().Add(24 * time.Hour).Format(time.RFC3339)
	_, err := db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode, times_used,
		    valid_from)
		VALUES (?, ?, ?, 'flat', 100, 0, 'draft', 'exclusive', 0, ?)`,
		couponID, "SCHED", "sched", future)
	if err != nil {
		t.Fatalf("insert: %v", err)
	}

	o := mkOrder(t, db, 1000)
	_, err = coupons.ApplyCouponWithOptions(o, "SCHED", ApplyCouponOptions{})
	if !errors.Is(err, ErrCouponWindow) {
		t.Errorf("want ErrCouponWindow for scheduled coupon, got %v", err)
	}
}

func TestCoupon_ExpiredRejectsAfterValidUntil(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	past := time.Now().UTC().Add(-24 * time.Hour).Format(time.RFC3339)
	_, err := db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode, times_used,
		    valid_until)
		VALUES (?, ?, ?, 'flat', 100, 0, 'draft', 'exclusive', 0, ?)`,
		uuid.NewString(), "EXPIRED", "expired", past)
	if err != nil {
		t.Fatalf("insert: %v", err)
	}

	o := mkOrder(t, db, 1000)
	_, err = coupons.ApplyCouponWithOptions(o, "EXPIRED", ApplyCouponOptions{})
	if !errors.Is(err, ErrCouponWindow) {
		t.Errorf("want ErrCouponWindow for expired coupon, got %v", err)
	}
}

func TestCoupon_ExhaustedRejectsAtCap(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	mkCoupon(t, db, couponFixture{
		id: uuid.NewString(), code: "CAP", discountType: "flat", discountValue: 100,
		status: "draft", totalLimit: 3,
	})
	_, _ = db.Exec(`UPDATE coupons SET times_used = 3 WHERE code = ?`, "CAP")

	o := mkOrder(t, db, 1000)
	_, err := coupons.ApplyCouponWithOptions(o, "CAP", ApplyCouponOptions{})
	if !errors.Is(err, ErrCouponExhausted) {
		t.Errorf("want ErrCouponExhausted, got %v", err)
	}
}

// ─── Branch whitelist tests ────────────────────────────────────────────────
//
// coupon_branches pivot mirrors backend's coupon_branch. Empty pivot
// = all branches; one-or-more rows = restricted to listed branches.

func TestCoupon_BranchWhitelist_EmptyPivotAllowsAnyBranch(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	mkCoupon(t, db, couponFixture{
		id: uuid.NewString(), code: "ALLBR", discountType: "flat", discountValue: 100,
		status: "draft",
	})

	o := mkOrder(t, db, 1000)
	if _, err := coupons.ApplyCouponWithOptions(o, "ALLBR", ApplyCouponOptions{
		BranchID: "branch-arbitrary",
	}); err != nil {
		t.Errorf("empty pivot should allow any branch, got %v", err)
	}
}

func TestCoupon_BranchWhitelist_RestrictedPivotRejectsForeignBranch(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	couponID := uuid.NewString()
	mkCoupon(t, db, couponFixture{
		id: couponID, code: "BRBR", discountType: "flat", discountValue: 100,
		status: "draft",
	})
	// Scope coupon to branch-A only.
	if _, err := db.Exec(`INSERT INTO coupon_branches (coupon_id, branch_id) VALUES (?, 'branch-A')`, couponID); err != nil {
		t.Fatalf("attach branch: %v", err)
	}

	o := mkOrder(t, db, 1000)
	// Device on branch-B (not in pivot) → reject.
	_, err := coupons.ApplyCouponWithOptions(o, "BRBR", ApplyCouponOptions{BranchID: "branch-B"})
	if !errors.Is(err, ErrCouponBranchScope) {
		t.Errorf("want ErrCouponBranchScope when device branch not in pivot, got %v", err)
	}

	// Device on branch-A (in pivot) → accept.
	o2 := mkOrder(t, db, 1000)
	if _, err := coupons.ApplyCouponWithOptions(o2, "BRBR", ApplyCouponOptions{BranchID: "branch-A"}); err != nil {
		t.Errorf("want apply success when device branch in pivot, got %v", err)
	}
}

func TestCoupon_BranchWhitelist_EmptyBranchIDFallsOpen(t *testing.T) {
	// When the device hasn't been paired or branch isn't resolved, we
	// fall open rather than rejecting every coupon — Cloud re-validates
	// on sync UP. Otherwise an unpaired workstation lock-out would be
	// indistinguishable from a coupon misconfiguration.
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	couponID := uuid.NewString()
	mkCoupon(t, db, couponFixture{
		id: couponID, code: "ANY", discountType: "flat", discountValue: 100,
		status: "draft",
	})
	if _, err := db.Exec(`INSERT INTO coupon_branches (coupon_id, branch_id) VALUES (?, 'branch-X')`, couponID); err != nil {
		t.Fatalf("attach: %v", err)
	}

	o := mkOrder(t, db, 1000)
	if _, err := coupons.ApplyCouponWithOptions(o, "ANY", ApplyCouponOptions{BranchID: ""}); err != nil {
		t.Errorf("empty BranchID should fall open, got %v", err)
	}
}

// ─── Computed-status helper unit test ──────────────────────────────────────

func TestCouponRow_computeStatus_StateDerivation(t *testing.T) {
	now := time.Date(2026, 6, 15, 12, 0, 0, 0, time.UTC)
	future := now.Add(24 * time.Hour).Format(time.RFC3339)
	past := now.Add(-24 * time.Hour).Format(time.RFC3339)

	cases := []struct {
		name string
		row  couponRow
		want CouponComputedStatus
	}{
		{"stored paused wins", couponRow{Status: "paused"}, CouponStatusPaused},
		// stored 'draft' is the default — not a hard block. Time +
		// usage gates take over (mirrors backend computeStatus).
		{"draft + scheduled — before valid_from", couponRow{Status: "draft", ValidFrom: future}, CouponStatusScheduled},
		{"draft + expired — after valid_until", couponRow{Status: "draft", ValidUntil: past}, CouponStatusExpired},
		{
			"draft + exhausted — at cap",
			couponRow{Status: "draft", TimesUsed: 10},
			CouponStatusExhausted,
		},
		{"draft + within window → active", couponRow{Status: "draft", ValidUntil: future}, CouponStatusActive},
		{"draft + no window → active", couponRow{Status: "draft"}, CouponStatusActive},
	}

	// Patch the exhausted case to give it a usage limit. Inline so the
	// struct literals above don't grow ugly.
	for i := range cases {
		if cases[i].want == CouponStatusExhausted {
			cases[i].row.UsageLimitTotal.Valid = true
			cases[i].row.UsageLimitTotal.Int64 = 10
		}
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := c.row.computeStatus(now)
			if got != c.want {
				t.Errorf("want %s, got %s", c.want, got)
			}
		})
	}
}
