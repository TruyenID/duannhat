package service

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

// CouponEngine — local-first coupon application + release.
//
// Scope reduced vs Cloud's app/Services/Promotion/CouponService.php: the
// workstation handles the single-coupon-per-order case (the dominant 95%)
// and defers true stacking + exclusivity-with-promotions to Cloud. When a
// pos-web request requires a multi-coupon decision, the cloud proxy in
// routes.go bypasses this engine entirely.
//
// Validation ordering matches the Cloud rule set so the local rejection
// messages line up with what Cloud would have returned:
//  1. Code unknown            → 404
//  2. Paused / out of window  → 409
//  3. Usage cap exhausted     → 409
//  4. Order below min_amount  → 422
//  5. Already has active coupon (exclusive mode) → 409
type CouponEngine struct {
	db     *store.DB
	orders *OrderEngine
}

func NewCouponEngine(db *store.DB, orders *OrderEngine) *CouponEngine {
	return &CouponEngine{db: db, orders: orders}
}

type CouponApplied struct {
	OrderID         string `json:"order_id"`
	CouponID        string `json:"coupon_id"`
	CouponCode      string `json:"coupon_code"`
	DiscountApplied int    `json:"discount_applied"`
	NewSubtotal     int    `json:"new_subtotal"`
	NewTotal        int    `json:"new_total"`
}

var (
	ErrCouponNotFound      = errors.New("coupon code not found")
	ErrCouponPaused        = errors.New("coupon paused")
	ErrCouponWindow        = errors.New("coupon outside valid window")
	ErrCouponExhausted     = errors.New("coupon usage cap exhausted")
	ErrCouponMinOrder      = errors.New("order below coupon minimum")
	ErrCouponAlreadyActive = errors.New("order already has an active coupon")
	// Plan-LAN-offline additions.
	ErrCouponCustomerRequired     = errors.New("coupon requires an identified customer")
	ErrCouponPerCustomerExhausted = errors.New("customer has reached this coupon's per-customer limit")
	ErrCouponExcludedByPromotion  = errors.New("order has Happy-Hour items that block coupons")
	// Plan-coupon-parity additions.
	ErrCouponBranchScope = errors.New("coupon not applicable to this branch")
)

// ApplyCouponOptions extends ApplyCoupon with the fields backend's
// CouponController accepts (customer scoping + HH downgrade opt-in) +
// the device's branch context needed for branch-whitelist enforcement.
// Zero value preserves legacy behaviour.
type ApplyCouponOptions struct {
	CustomerID                   string
	DowngradeExclusivePromotions bool
	// BranchID is the workstation device's bound branch. Used to enforce
	// coupon_branches whitelist locally (CouponService::validateBranch).
	// Empty string falls open — Cloud re-validates on sync UP.
	BranchID string
	// Phase C: audit + provenance stamp on the coupon_redemptions row.
	// Defaults: RedeemedVia="pos" (workstation's till is always the POS
	// path), RedeemedByUserID="" (LAN-mode device-token apply is
	// anonymous; populated only when an SSO user-id flows from a
	// manager-override route).
	RedeemedVia      string
	RedeemedByUserID string
}

// couponSnapshot freezes coupon state at apply time. Mirrors backend's
// CouponRedemption.coupon_snapshot shape (key set chosen so Cloud-side
// reconciliation can verify the apply against the snapshot even after
// the source coupon row is edited).
type couponSnapshot struct {
	Code                  string `json:"code"`
	Name                  string `json:"name"`
	Description           string `json:"description,omitempty"`
	DiscountType          string `json:"discount_type"`
	DiscountValue         int    `json:"discount_value"`
	MinOrderSubtotal      int    `json:"min_order_subtotal"`
	MaxDiscountCap        *int   `json:"max_discount_cap"`
	UsageLimitPerCustomer *int   `json:"usage_limit_per_customer"`
	ValidFrom             string `json:"valid_from,omitempty"`
	ValidUntil            string `json:"valid_until,omitempty"`
	BrandID               string `json:"brand_id,omitempty"`
	OrganizationID        string `json:"organization_id,omitempty"`
}

func buildCouponSnapshot(c *couponRow) ([]byte, error) {
	snap := couponSnapshot{
		Code:             c.Code,
		Name:             c.Name,
		DiscountType:     c.DiscountType,
		DiscountValue:    c.DiscountValue,
		MinOrderSubtotal: c.MinOrderSubtotal,
		ValidFrom:        c.ValidFrom,
		ValidUntil:       c.ValidUntil,
	}
	if c.Description.Valid {
		snap.Description = c.Description.String
	}
	if c.MaxDiscountCap.Valid {
		v := int(c.MaxDiscountCap.Int64)
		snap.MaxDiscountCap = &v
	}
	if c.UsageLimitPerCustomer.Valid {
		v := int(c.UsageLimitPerCustomer.Int64)
		snap.UsageLimitPerCustomer = &v
	}
	if c.BrandID.Valid {
		snap.BrandID = c.BrandID.String
	}
	if c.OrganizationID.Valid {
		snap.OrganizationID = c.OrganizationID.String
	}
	return json.Marshal(snap)
}

// ApplyCoupon — legacy entry point used by handlers without coupon-V2
// context. Delegates to ApplyCouponWithOptions with no customer + no
// downgrade so behaviour is unchanged for old callers.
func (c *CouponEngine) ApplyCoupon(orderID, code string) (*CouponApplied, error) {
	return c.ApplyCouponWithOptions(orderID, code, ApplyCouponOptions{})
}

// ApplyCouponWithOptions validates + applies a coupon by code. Discount
// is computed from the order's subtotal (NOT the running total — matches
// Cloud). Honours per_customer_limit when CustomerID is supplied AND
// exclusive_with_promotions blocks (or downgrades) when HH items are
// present on the order.
func (c *CouponEngine) ApplyCouponWithOptions(orderID, code string, opts ApplyCouponOptions) (*CouponApplied, error) {
	order, err := c.orders.GetByID(orderID)
	if err != nil {
		return nil, err
	}
	if order.Status == StatusVoided || order.Status == StatusClosed {
		return nil, fmt.Errorf("cannot apply coupon on %s order", order.Status)
	}

	coupon, err := c.findByCode(code)
	if err != nil {
		return nil, err
	}

	now := time.Now().UTC()

	// Computed status — single source of truth for lifecycle gating.
	// Mirrors backend CouponService::computeStatus order: paused/draft →
	// scheduled → expired → exhausted → active. Anything other than
	// `active` rejects the apply with a specific error so pos-web can
	// surface a precise message.
	switch coupon.computeStatus(now) {
	case CouponStatusPaused:
		return nil, ErrCouponPaused
	case CouponStatusScheduled, CouponStatusExpired:
		return nil, ErrCouponWindow
	case CouponStatusExhausted:
		return nil, ErrCouponExhausted
	}

	// Branch whitelist (coupon_branches pivot). When the coupon is
	// scoped to specific branches, only orders on those branches may
	// apply it. Empty pivot = all branches (parity with backend).
	allowed, berr := c.branchWhitelistAllows(coupon.ID, opts.BranchID)
	if berr != nil {
		return nil, berr
	}
	if !allowed {
		return nil, ErrCouponBranchScope
	}
	if order.Subtotal < coupon.MinOrderSubtotal {
		return nil, ErrCouponMinOrder
	}

	// Per-customer eligibility — mirrors backend
	// CouponService::assertCustomerEligible. A positive
	// usage_limit_per_customer marks the coupon customer-scoped: it
	// REQUIRES an identified customer AND caps redemptions per customer.
	// A 0/NULL limit means no restriction at all (0 is the backend
	// default). Gating on Int64 > 0 (not just .Valid) both closes the
	// missing customer_required check and avoids the default-0 coupon
	// being rejected as "already used" on its first redemption.
	if coupon.UsageLimitPerCustomer.Valid && coupon.UsageLimitPerCustomer.Int64 > 0 {
		if opts.CustomerID == "" {
			return nil, ErrCouponCustomerRequired
		}
		var used int
		_ = c.db.QueryRow(`
			SELECT COUNT(*) FROM coupon_redemptions
			WHERE coupon_id = ? AND customer_id = ? AND released_at IS NULL`,
			coupon.ID, opts.CustomerID,
		).Scan(&used)
		if used >= int(coupon.UsageLimitPerCustomer.Int64) {
			return nil, ErrCouponPerCustomerExhausted
		}
	}

	// exclusive_with_coupons HH conflict. Backend's flow:
	//   - Default → reject COUPON_EXCLUDED_BY_ACTIVE_PROMOTION.
	//   - downgrade=true → strip exclusive promotions off the order, restore
	//     original_unit_price, recompute subtotal, then keep going. The
	//     coupon discount caps against the restored (higher) subtotal.
	hasExcl, herr := c.hasExclusivePromotionItem(orderID)
	if herr != nil {
		return nil, herr
	}
	if hasExcl {
		if !opts.DowngradeExclusivePromotions {
			return nil, ErrCouponExcludedByPromotion
		}
		if err := c.downgradeExclusivePromotions(orderID); err != nil {
			return nil, err
		}
		// Re-fetch the order so we work against the recomputed subtotal.
		order, err = c.orders.GetByID(orderID)
		if err != nil {
			return nil, err
		}
	}

	// Single active coupon per order (exclusive mode). Sync UP allows Cloud
	// to override later if its stacking decision differs.
	if coupon.StackingMode == "exclusive" {
		var active int
		_ = c.db.QueryRow(
			`SELECT COUNT(*) FROM order_coupons WHERE order_id = ? AND released_at IS NULL`,
			orderID,
		).Scan(&active)
		if active > 0 {
			return nil, ErrCouponAlreadyActive
		}
	}

	discount := computeDiscount(coupon, order.Subtotal)
	bindingID := uuid.NewString()
	nowStr := now.Format(time.RFC3339)

	// Phase C: freeze coupon state at apply time so Cloud-side
	// reconciliation can audit the redemption against the exact terms
	// the customer was billed under, even if the source row is edited
	// before sync UP.
	snapshot, sErr := buildCouponSnapshot(coupon)
	if sErr != nil {
		return nil, fmt.Errorf("build snapshot: %w", sErr)
	}
	via := opts.RedeemedVia
	if via == "" {
		via = "pos"
	}

	err = c.db.Transaction(func(tx *sql.Tx) error {
		if _, err := tx.Exec(`
			INSERT INTO order_coupons (id, order_id, coupon_id, coupon_code,
			    discount_applied, applied_at)
			VALUES (?, ?, ?, ?, ?, ?)`,
			bindingID, orderID, coupon.ID, coupon.Code, discount, nowStr,
		); err != nil {
			return err
		}
		// Mirror to the new coupon_redemptions ledger so per-customer cap
		// math + sync UP queue both see a stable row identity. Phase C
		// adds coupon_snapshot + redeemed_via + redeemed_by_user_id.
		redemptionID := uuid.NewString()
		if _, err := tx.Exec(`
			INSERT INTO coupon_redemptions (id, coupon_id, coupon_code,
			    order_id, customer_id, discount_amount, redeemed_at,
			    coupon_snapshot, redeemed_via, redeemed_by_user_id)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
			redemptionID, coupon.ID, coupon.Code, orderID,
			nullableString(opts.CustomerID), discount, nowStr,
			string(snapshot), via, nullableString(opts.RedeemedByUserID),
		); err != nil {
			return err
		}
		// Increment coupon usage locally. Cloud's authoritative counter
		// is reconciled on sync UP.
		if _, err := tx.Exec(
			`UPDATE coupons SET times_used = times_used + 1 WHERE id = ?`,
			coupon.ID,
		); err != nil {
			return err
		}
		// plan-043 (T3.5) — only bump discount_amount here; the per-rate tax +
		// totals are re-derived by recalcOrderTotals AFTER commit so the coupon
		// discount is allocated pro-rata across the rate groups and each group's
		// tax is recomputed on its DISCOUNTED base (naïvely subtracting the
		// discount from the pre-coupon total over-charged tax on the discounted
		// portion — bug #5).
		_, err := tx.Exec(`
			UPDATE orders SET discount_amount = discount_amount + ?, updated_at = ?
			WHERE id = ?`,
			discount, nowStr, orderID,
		)
		return err
	})
	if err != nil {
		return nil, fmt.Errorf("apply coupon: %w", err)
	}

	// Re-run the §8 engine on the discounted base (pro-rata per rate group).
	if err := c.orders.recalcOrderTotals(orderID); err != nil {
		return nil, fmt.Errorf("apply coupon recalc: %w", err)
	}

	updated, _ := c.orders.GetByID(orderID)
	return &CouponApplied{
		OrderID:         orderID,
		CouponID:        coupon.ID,
		CouponCode:      coupon.Code,
		DiscountApplied: discount,
		NewSubtotal:     updated.Subtotal,
		NewTotal:        updated.TotalAmount,
	}, nil
}

// ReleaseCoupon clears the active binding (sets released_at) and reverses
// the discount on the order. Idempotent: releasing when no active binding
// exists returns the order unchanged.
func (c *CouponEngine) ReleaseCoupon(orderID string) (*Order, error) {
	var bindingID string
	var discount int
	err := c.db.QueryRow(`
		SELECT id, discount_applied FROM order_coupons
		WHERE order_id = ? AND released_at IS NULL
		ORDER BY applied_at DESC LIMIT 1`, orderID,
	).Scan(&bindingID, &discount)
	if errors.Is(err, sql.ErrNoRows) {
		return c.orders.GetByID(orderID)
	}
	if err != nil {
		return nil, err
	}

	now := time.Now().UTC().Format(time.RFC3339)
	err = c.db.Transaction(func(tx *sql.Tx) error {
		if _, err := tx.Exec(
			`UPDATE order_coupons SET released_at = ? WHERE id = ?`,
			now, bindingID,
		); err != nil {
			return err
		}
		// Mirror release to coupon_redemptions so per-customer cap math
		// stops counting this row as "still spending the budget".
		if _, err := tx.Exec(
			`UPDATE coupon_redemptions SET released_at = ?
			 WHERE order_id = ? AND released_at IS NULL`,
			now, orderID,
		); err != nil {
			return err
		}
		_, err := tx.Exec(`
			UPDATE orders SET discount_amount = MAX(discount_amount - ?, 0),
			                  updated_at = ?
			WHERE id = ?`,
			discount, now, orderID,
		)
		return err
	})
	if err != nil {
		return nil, fmt.Errorf("release coupon: %w", err)
	}
	// plan-043 (T3.5) — restore the pre-coupon per-rate tax by re-running the
	// §8 engine on the now-undiscounted base (mirror of the apply path).
	if err := c.orders.recalcOrderTotals(orderID); err != nil {
		return nil, fmt.Errorf("release coupon recalc: %w", err)
	}
	return c.orders.GetByID(orderID)
}

// ─── Internal types + helpers ─────────────────────────────────────────────────

type couponRow struct {
	ID                      string
	Code                    string
	Name                    string
	Description             sql.NullString
	DiscountType            string
	DiscountValue           int
	MinOrderSubtotal        int           // backend: min_order_subtotal (renamed from min_order_amount)
	MaxDiscountCap          sql.NullInt64 // backend: max_discount_cap (renamed from max_discount)
	ValidFrom               string
	ValidUntil              string
	UsageLimitTotal         sql.NullInt64
	TimesUsed               int
	UsageLimitPerCustomer   sql.NullInt64 // backend: usage_limit_per_customer (renamed from per_customer_limit)
	Status                  string        // raw stored: draft | active | paused
	StackingMode            string
	ExclusiveWithPromotions bool
	BrandID                 sql.NullString
	OrganizationID          sql.NullString
}

// CouponComputedStatus mirrors backend CouponService::computeStatus. The
// stored coupons.status column only carries draft|active|paused; the
// scheduled/expired/exhausted transitions are derived at read-time so
// workstation never needs a cron to flip the column as time passes.
type CouponComputedStatus string

const (
	CouponStatusDraft     CouponComputedStatus = "draft"
	CouponStatusPaused    CouponComputedStatus = "paused"
	CouponStatusScheduled CouponComputedStatus = "scheduled"
	CouponStatusActive    CouponComputedStatus = "active"
	CouponStatusExpired   CouponComputedStatus = "expired"
	CouponStatusExhausted CouponComputedStatus = "exhausted"
)

// computeStatus derives the lifecycle state. Check order mirrors backend
// CouponService::computeStatus exactly. Stored values are draft|paused
// (the persisted enum); the active|scheduled|expired|exhausted
// transitions are derived from valid_from/until + usage caps. 'draft' is
// the default — not a hard block on apply; the time-window + cap checks
// take over.
//
//  1. stored 'paused' → paused                 (hard reject)
//  2. before valid_from → scheduled            (hard reject)
//  3. after  valid_until → expired             (hard reject)
//  4. times_used >= cap → exhausted            (hard reject)
//  5. otherwise → active                       (apply ok)
func (r *couponRow) computeStatus(now time.Time) CouponComputedStatus {
	if r.Status == "paused" {
		return CouponStatusPaused
	}
	if r.ValidFrom != "" {
		if t, err := time.Parse(time.RFC3339, r.ValidFrom); err == nil && now.Before(t) {
			return CouponStatusScheduled
		}
	}
	if r.ValidUntil != "" {
		if t, err := time.Parse(time.RFC3339, r.ValidUntil); err == nil && now.After(t) {
			return CouponStatusExpired
		}
	}
	if r.UsageLimitTotal.Valid && r.TimesUsed >= int(r.UsageLimitTotal.Int64) {
		return CouponStatusExhausted
	}
	return CouponStatusActive
}

func (c *CouponEngine) findByCode(code string) (*couponRow, error) {
	var row couponRow
	var validFrom, validUntil sql.NullString
	var excl int
	err := c.db.QueryRow(`
		SELECT id, code, name, description,
		       discount_type, discount_value, min_order_subtotal,
		       max_discount_cap, valid_from, valid_until, usage_limit_total,
		       times_used, status, stacking_mode,
		       usage_limit_per_customer, exclusive_with_promotions,
		       brand_id, organization_id
		FROM coupons WHERE code = ? AND deleted_at IS NULL LIMIT 1`, code,
	).Scan(
		&row.ID, &row.Code, &row.Name, &row.Description,
		&row.DiscountType, &row.DiscountValue,
		&row.MinOrderSubtotal, &row.MaxDiscountCap, &validFrom, &validUntil,
		&row.UsageLimitTotal, &row.TimesUsed, &row.Status, &row.StackingMode,
		&row.UsageLimitPerCustomer, &excl,
		&row.BrandID, &row.OrganizationID,
	)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrCouponNotFound
	}
	if err != nil {
		return nil, err
	}
	row.ValidFrom = validFrom.String
	row.ValidUntil = validUntil.String
	row.ExclusiveWithPromotions = excl == 1
	return &row, nil
}

// branchWhitelistAllows returns true when the coupon may be applied on
// `branchID`. Semantics mirror backend CouponService::validateBranch:
//
//   - Empty coupon_branches rows for this coupon → applies to ALL branches.
//   - One-or-more rows → coupon is restricted; allow only if branchID is
//     in the list.
//   - branchID == "" (unknown device branch) → fall open (we can't enforce
//     and rejecting would lock out un-paired workstations). Cloud
//     re-validates on sync UP.
func (c *CouponEngine) branchWhitelistAllows(couponID, branchID string) (bool, error) {
	var listed int
	if err := c.db.QueryRow(
		`SELECT COUNT(*) FROM coupon_branches WHERE coupon_id = ?`, couponID,
	).Scan(&listed); err != nil {
		return false, err
	}
	if listed == 0 || branchID == "" {
		return true, nil
	}
	var match int
	if err := c.db.QueryRow(
		`SELECT COUNT(*) FROM coupon_branches WHERE coupon_id = ? AND branch_id = ?`,
		couponID, branchID,
	).Scan(&match); err != nil {
		return false, err
	}
	return match > 0, nil
}

// hasExclusivePromotionItem returns true when the order has at least one
// non-voided item carrying a promotion_id whose menu_promotions row sets
// exclusive_with_coupons = 1.
func (c *CouponEngine) hasExclusivePromotionItem(orderID string) (bool, error) {
	var n int
	err := c.db.QueryRow(`
		SELECT COUNT(*)
		FROM order_items oi
		JOIN menu_promotions mp ON mp.id = oi.promotion_id
		WHERE oi.customer_order_id = ?
		  AND oi.status != 'voided'
		  AND mp.exclusive_with_coupons = 1`, orderID).Scan(&n)
	if err != nil {
		return false, err
	}
	return n > 0, nil
}

// downgradeExclusivePromotions reverts unit_price back to
// original_unit_price for items whose promotion is exclusive-with-coupons,
// clears the promotion stamp, then recomputes the order subtotal so the
// coupon discount math runs against the restored value.
func (c *CouponEngine) downgradeExclusivePromotions(orderID string) error {
	return c.db.Transaction(func(tx *sql.Tx) error {
		if _, err := tx.Exec(`
			UPDATE order_items
			SET unit_price = COALESCE(original_unit_price, unit_price),
			    subtotal = COALESCE(original_unit_price, unit_price) * quantity,
			    original_unit_price = NULL,
			    promotion_id = NULL,
			    promotion_label = NULL,
			    updated_at = datetime('now')
			WHERE customer_order_id = ?
			  AND status != 'voided'
			  AND promotion_id IN (
			    SELECT id FROM menu_promotions WHERE exclusive_with_coupons = 1
			  )`, orderID); err != nil {
			return err
		}
		if _, err := tx.Exec(`
			UPDATE orders
			SET subtotal = (
			    SELECT COALESCE(SUM(subtotal + topping_subtotal), 0)
			    FROM order_items
			    WHERE customer_order_id = ? AND status != 'voided'
			),
			updated_at = datetime('now')
			WHERE id = ?`, orderID, orderID); err != nil {
			return err
		}
		return nil
	})
}

// computeDiscount mirrors backend's CouponService::computeDiscount math.
//
// Backend stores `discount_value` as decimal(12,2). For a 1000-yen "trừ
// ¥1000" coupon the cell holds 1000.00; CouponReplicaController emits it
// as `(int) round(...) → 1000`. For a 15% coupon the cell holds 15.00 →
// emitted as 15. Workstation receives the integer and runs the same
// arithmetic Cloud does, so an apply on the LAN gives the same discount
// the customer would see if the apply had gone through Cloud.
//
// Two bugs fixed here (plan: workstation coupon discount value):
//
//  1. "fixed" was missing from the switch. Cloud's CouponDiscountTypeEnum
//     is 'fixed' | 'percent'; the legacy "flat" string predates that
//     rollout. A coupon with discount_type='fixed' used to fall through
//     and return 0 — the bug surfaced as "applied a -¥1000 coupon, the
//     receipt shows -¥0".
//
//  2. The percent branch used `/ 10000` because the original author
//     assumed Cloud stored basis-points (so 15% → 1500). Backend
//     CouponService::computeDiscount actually does `$subtotal * $value
//     / 100` against the decimal percent, so 15% × 1000 yen must be 150
//     yen off, not 0.15 yen. Aligning the divisor here closes the drift.
func computeDiscount(c *couponRow, subtotal int) int {
	switch c.DiscountType {
	case "flat", "fixed":
		// "flat" is the legacy workstation alias; both shapes survive
		// in flight (older sync feeds still ship "flat", newer ones
		// ship "fixed") — treat them identically.
		if c.DiscountValue > subtotal {
			return subtotal
		}
		return c.DiscountValue
	case "percent":
		// Decimal-percent (Cloud convention): 15.00% serializes as 15.
		// Integer division mirrors Cloud's `round($raw, 0)` for the
		// JPY zero-decimal currency path; non-JPY currencies can lose
		// sub-yen precision here, acceptable because workstation only
		// runs in JPY-priced shops today.
		raw := subtotal * c.DiscountValue / 100
		if c.MaxDiscountCap.Valid && raw > int(c.MaxDiscountCap.Int64) {
			raw = int(c.MaxDiscountCap.Int64)
		}
		return raw
	}
	return 0
}
