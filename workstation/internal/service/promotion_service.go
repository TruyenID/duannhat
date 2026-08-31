package service

import (
	"database/sql"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// PromotionEngine ports backend's MenuPromotionService::applyToItem() to Go
// so workstation can apply Happy-Hour-style promotions when an item is
// added LAN-offline. Reads from the menu_promotions / _products /
// _schedules replicas (synced DOWN every 5 min). Branch-local clock — the
// workstation always runs in branch TZ, so time.Now() is correct.
type PromotionEngine struct {
	db *store.DB
}

func NewPromotionEngine(db *store.DB) *PromotionEngine {
	return &PromotionEngine{db: db}
}

// PromotionMatch is what survives the day-of-week + time window filter
// plus the applies_to scope test. Tie-breaker is highest DiscountValue
// (== discount_percent in basis points) then earliest CreatedAt — mirrors
// backend MenuPromotionService::resolveForItem decision Q-B3.
type PromotionMatch struct {
	ID                   string
	Name                 string
	DiscountType         string
	DiscountValue        int
	ExclusiveWithCoupons bool
	Priority             int    // legacy field; kept for callers but no longer used as primary sort
	CreatedAt            string // RFC3339; "" sorts last (= unknown)
	EndsAt               string // RFC3339; "" = open-ended. Carried for the menu badge countdown.
}

// ApplyToItem returns the final unit_price after promotion + the snapshot
// of the original unit_price + the promotion id/label that applied. When
// no promotion matches, the returned PromotionMatch is nil and final ==
// originalPrice.
//
// Order of operations:
//  1. Find every promotion that lists productSkuID in menu_promotion_products.
//  2. Filter by is_active = 1 AND now ∈ [starts_at, ends_at].
//  3. Filter by schedule rows: every promotion must have ≥1 schedule whose
//     day_of_week is NULL (= any day) or matches now's weekday, AND whose
//     [daily_time_from, daily_time_to] window contains now (treat null
//     window as always-on for that day).
//  4. Pick the highest-priority survivor.
//  5. Apply discount and return.
func (p *PromotionEngine) ApplyToItem(productSkuID string, originalPrice int, now time.Time) (final int, match *PromotionMatch, err error) {
	winner, err := p.ResolveForSku(productSkuID, now)
	if err != nil {
		return originalPrice, nil, err
	}
	if winner == nil {
		return originalPrice, nil, nil
	}
	final = applyDiscount(originalPrice, winner.DiscountType, winner.DiscountValue)
	return final, winner, nil
}

// ResolveForSku returns the single promotion that ApplyToItem would apply to
// productSkuID at `now`, or nil when none applies. It is the SINGLE SOURCE OF
// TRUTH for "which promotion, what discount" — the same candidate filtering
// (is_active + [starts_at, ends_at] window + daily menu_promotion_schedules +
// applies_to pivot) and the same tie-break as the cart. The pos-web menu badge
// calls this so the tile can never advertise a discount the cart won't honor
// (e.g. a Happy Hour promo outside its scheduled window, or a lower-priority
// but higher-percent promo).
//
// Tie-break mirrors backend MenuPromotionService::resolveForItem: highest
// discount_percent wins (= DiscountValue for percent type); ties broken by
// earliest created_at, then by id for determinism. The legacy `priority`
// column is intentionally NOT consulted — Cloud has no such concept and the
// replica feed returned priority=100 for every row (a no-op signal).
func (p *PromotionEngine) ResolveForSku(productSkuID string, now time.Time) (*PromotionMatch, error) {
	if productSkuID == "" {
		return nil, nil
	}
	candidates, err := p.findCandidates(productSkuID, now)
	if err != nil {
		return nil, err
	}
	if len(candidates) == 0 {
		return nil, nil
	}
	winner := candidates[0]
	for _, c := range candidates[1:] {
		if betterPromotion(c, winner) {
			winner = c
		}
	}
	return &winner, nil
}

// HasActiveExclusivePromotion returns true when the order has at least one
// non-voided item whose promotion (still applied) sets
// `exclusive_with_coupons = 1`. Coupon apply consults this and refuses
// (matching backend's coupon_excluded_by_active_promotion flow).
func (p *PromotionEngine) HasActiveExclusivePromotion(orderID string) (bool, error) {
	var n int
	err := p.db.QueryRow(`
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

// betterPromotion returns true when `c` should win the tie-break against
// `cur`. Order mirrors backend MenuPromotionService::resolveForItem:
//  1. higher DiscountValue (discount_percent basis points)
//  2. earlier CreatedAt (stable, deterministic)
//  3. lower ID lex (final tie-break for repeatability in tests)
func betterPromotion(c, cur PromotionMatch) bool {
	if c.DiscountValue != cur.DiscountValue {
		return c.DiscountValue > cur.DiscountValue
	}
	if c.CreatedAt != "" && cur.CreatedAt != "" && c.CreatedAt != cur.CreatedAt {
		return c.CreatedAt < cur.CreatedAt
	}
	// One has a known CreatedAt, the other doesn't → prefer the known.
	if c.CreatedAt != "" && cur.CreatedAt == "" {
		return true
	}
	if cur.CreatedAt != "" && c.CreatedAt == "" {
		return false
	}
	return c.ID < cur.ID
}

func (p *PromotionEngine) findCandidates(productSkuID string, now time.Time) ([]PromotionMatch, error) {
	nowISO := now.Format(time.RFC3339Nano)

	// Resolve SKU → product. Cloud's menu_promotion_product pivot is
	// keyed on product_id (migration 028); LAN-mode SKU passes through
	// pos_product_skus to find its parent product.
	var productID string
	_ = p.db.QueryRow(
		`SELECT product_id FROM pos_product_skus WHERE id = ? LIMIT 1`,
		productSkuID,
	).Scan(&productID)

	// Single sweep over in-window promotions. applies_to scoping is
	// evaluated per row (cheaper than building two separate SELECTs for
	// the all_items vs products branches; the row count is bounded
	// to ~dozens).
	rows, err := p.db.Query(`
		SELECT mp.id, mp.name, mp.discount_type, mp.discount_value,
		       mp.exclusive_with_coupons, mp.priority,
		       COALESCE(mp.applies_to, 'products') AS applies_to,
		       COALESCE(mp.promo_created_at, '') AS promo_created_at,
		       COALESCE(mp.ends_at, '') AS ends_at
		FROM menu_promotions mp
		WHERE mp.is_active = 1
		  AND (mp.starts_at IS NULL OR mp.starts_at <= ?)
		  AND (mp.ends_at IS NULL OR mp.ends_at >= ?)`,
		nowISO, nowISO)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type promoCandidate struct {
		PromotionMatch
		AppliesTo string
	}
	pending := []promoCandidate{}
	for rows.Next() {
		var m PromotionMatch
		var excl int
		var appliesTo string
		if err := rows.Scan(&m.ID, &m.Name, &m.DiscountType, &m.DiscountValue,
			&excl, &m.Priority, &appliesTo, &m.CreatedAt, &m.EndsAt); err != nil {
			return nil, err
		}
		m.ExclusiveWithCoupons = excl == 1
		pending = append(pending, promoCandidate{PromotionMatch: m, AppliesTo: appliesTo})
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	weekday := int(now.Weekday()) // 0=Sun..6=Sat
	hhmm := now.Format("15:04")   // branch-local HH:MM

	out := []PromotionMatch{}
	for _, pc := range pending {
		// applies_to scope test:
		//   all_items → match every product in window
		//   products / categories / mixed → match iff the resolved
		//     product_id is listed in menu_promotion_products
		//     (categories were already flattened to product_ids by
		//     the backend replica feed — decision Q-B2)
		if pc.AppliesTo != "all_items" {
			if productID == "" {
				continue
			}
			ok, err := p.productInPivot(pc.ID, productID)
			if err != nil {
				return nil, err
			}
			if !ok {
				continue
			}
		}

		ok, err := p.scheduleMatches(pc.ID, weekday, hhmm)
		if err != nil {
			return nil, err
		}
		if ok {
			out = append(out, pc.PromotionMatch)
		}
	}
	return out, nil
}

// productInPivot returns true if (promotion_id, product_id) is listed
// in menu_promotion_products. Used by applies_to ∈ {products, categories,
// mixed} after the backend replica feed flattens categories into the
// same product_id space.
func (p *PromotionEngine) productInPivot(promotionID, productID string) (bool, error) {
	var n int
	err := p.db.QueryRow(`
		SELECT COUNT(*) FROM menu_promotion_products
		WHERE promotion_id = ? AND product_id = ?`,
		promotionID, productID,
	).Scan(&n)
	if err != nil {
		return false, err
	}
	return n > 0, nil
}

func (p *PromotionEngine) scheduleMatches(promoID string, weekday int, hhmm string) (bool, error) {
	// At least one schedule row must accept (weekday, hhmm). NULL day_of_week
	// means "every day"; NULL time window means "all day". Both inclusive.
	rows, err := p.db.Query(`
		SELECT day_of_week, daily_time_from, daily_time_to
		FROM menu_promotion_schedules
		WHERE promotion_id = ?`, promoID)
	if err != nil {
		return false, err
	}
	defer rows.Close()

	hadSchedule := false
	for rows.Next() {
		hadSchedule = true
		var dow sql.NullInt64
		var from, to sql.NullString
		if err := rows.Scan(&dow, &from, &to); err != nil {
			return false, err
		}
		if dow.Valid && int(dow.Int64) != weekday {
			continue
		}
		if from.Valid && to.Valid && from.String != "" && to.String != "" {
			if hhmm < from.String || hhmm > to.String {
				continue
			}
		}
		return true, nil
	}
	// No schedule rows at all = treat as always-on (matches Cloud
	// behaviour when a promotion was created without time constraints).
	return !hadSchedule, nil
}

// applyDiscount mirrors backend's per-type math:
//
//	percent          → discount_value is a PLAIN PERCENT (Cloud convention:
//	                   15.00% serializes as 15 — NOT basis points). This matches
//	                   CustomerOrderService: rawUnitPrice * (100 - n) / 100, and
//	                   the coupon path (couponRow percent, also / 100). Using
//	                   / 10000 here silently applied a 15% promo as 0.15%.
//	                   final = floor(original * (100 - value) / 100).
//	amount           → discount in yen; final = max(0, original - value).
//	fixed_unit_price → value IS the final price.
func applyDiscount(original int, kind string, value int) int {
	switch strings.ToLower(kind) {
	case "percent":
		if value <= 0 {
			return original
		}
		if value >= 100 {
			return 0
		}
		// floor((original * (100 - value)) / 100)
		return (original * (100 - value)) / 100
	case "amount":
		final := original - value
		if final < 0 {
			final = 0
		}
		return final
	case "fixed_unit_price":
		if value < 0 {
			value = 0
		}
		return value
	default:
		return original
	}
}
