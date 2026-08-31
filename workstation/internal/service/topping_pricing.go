package service

import (
	"database/sql"
	"sort"
)

// Port of pos-web/src/app/pos/lib/topping-pricing.ts (priceLineAcrossGroups →
// priceLine → priceFreeUpToN → priceFlat), itself a port of Cloud's
// App\Services\Topping\ToppingPricingService. This MUST produce numbers
// identical to the TS/PHP implementation so the LAN cart line matches the
// pos-web running total and Cloud's persisted topping_subtotal (BR-OI06).
//
// All prices here are integer minor units (extra_price / override_price /
// unit_price are INTEGER columns and int fields). The TS round2() rounds to 2
// decimals half-away-from-zero; with integer inputs it is the identity, so the
// integer sum below is exact parity — no float step to diverge.
//
// Plan 015 / OQ-1: free_up_to_n waives the MOST EXPENSIVE N units (Toast/Square
// industry default). Reverse the DESC comparator to flip to cheapest-first —
// and update topping-pricing.ts + the PHP service in the same change.

// pricedTopping is the minimal per-selection shape the algorithm needs.
type pricedTopping struct {
	ToppingGroupID string
	UnitPrice      int
	Quantity       int
}

// toppingGroupPricing carries the per-group strategy config from
// pos_topping_groups. PriceStrategy: "flat" | "free_up_to_n"; anything else
// (including "" for an unsynced group) is treated as flat.
type toppingGroupPricing struct {
	PriceStrategy string
	FreeQuantity  int // pos_topping_groups.free_quantity; NULL → 0
}

// priceLineAcrossGroups sums the per-unit topping subtotal for one order line,
// bucketing selections by topping_group_id and applying each group's strategy.
// Mirrors the TS function of the same name. Free quotas never leak across
// groups. First-seen group order is preserved for deterministic iteration
// (parity with JS Object.values insertion order); it does not affect the sum.
func priceLineAcrossGroups(toppings []pricedTopping, groups map[string]toppingGroupPricing) int {
	order := make([]string, 0)
	buckets := make(map[string][]pricedTopping)
	for _, t := range toppings {
		if _, ok := buckets[t.ToppingGroupID]; !ok {
			order = append(order, t.ToppingGroupID)
		}
		buckets[t.ToppingGroupID] = append(buckets[t.ToppingGroupID], t)
	}
	total := 0
	for _, gid := range order {
		total += priceLine(buckets[gid], groups[gid])
	}
	return total
}

// priceLine prices selections within ONE group. Caller must filter by group.
func priceLine(sel []pricedTopping, group toppingGroupPricing) int {
	if len(sel) == 0 {
		return 0
	}
	// Expand by quantity into individual units — free_up_to_n waives units, not
	// selections ("Mayo ×3" contributes 3 units to the sort).
	units := make([]int, 0, len(sel))
	for _, s := range sel {
		q := max(1, s.Quantity) // TS Math.max(1, s.quantity)
		for range q {
			units = append(units, s.UnitPrice)
		}
	}
	switch group.PriceStrategy {
	case "free_up_to_n":
		return priceFreeUpToN(units, group.FreeQuantity)
	default:
		// "flat" and any unknown/unsynced strategy price every unit.
		return priceFlat(units)
	}
}

func priceFlat(units []int) int {
	sum := 0
	for _, u := range units {
		sum += u
	}
	return sum
}

// priceFreeUpToN waives the freeQuantity MOST EXPENSIVE units, charging the
// rest. Mirrors the TS priceFreeUpToN (stable DESC sort, waive first N).
func priceFreeUpToN(units []int, freeQuantity int) int {
	if freeQuantity <= 0 {
		return priceFlat(units)
	}
	sorted := make([]int, len(units))
	copy(sorted, units)
	// Stable DESC sort — sort.SliceStable preserves input order for equal keys,
	// replicating the TS "_idx" tiebreak without an explicit index field.
	sort.SliceStable(sorted, func(i, j int) bool { return sorted[i] > sorted[j] })

	sum := 0
	waived := 0
	for _, u := range sorted {
		if waived >= freeQuantity {
			sum += u
		} else {
			waived++
		}
	}
	return sum
}

// loadToppingGroupPricing fetches price_strategy + free_quantity for the
// distinct topping_group_ids on a line, from the synced pos_topping_groups
// replica. NULL free_quantity → 0. A group missing from the replica yields the
// zero value, which priceLine treats as flat (safe default). Must run AFTER
// resolveToppingSnapshot so each ToppingInput.ToppingGroupID is hydrated.
func (e *OrderEngine) loadToppingGroupPricing(toppings []ToppingInput) map[string]toppingGroupPricing {
	out := map[string]toppingGroupPricing{}
	for _, t := range toppings {
		gid := t.ToppingGroupID
		if gid == "" {
			continue
		}
		if _, seen := out[gid]; seen {
			continue
		}
		var strategy string
		var freeQty sql.NullInt64
		if err := e.db.QueryRow(
			`SELECT price_strategy, free_quantity FROM pos_topping_groups WHERE id = ?`, gid,
		).Scan(&strategy, &freeQty); err == nil {
			g := toppingGroupPricing{PriceStrategy: strategy}
			if freeQty.Valid {
				g.FreeQuantity = int(freeQty.Int64)
			}
			out[gid] = g
		}
	}
	return out
}

// menuProductIDForSku resolves the pos_menu_products.id (the tier-1 topping
// override key) for a parent line's ProductSku. A product can be attached to
// several menus, so we mirror the menu-list ordering pos-web renders from
// (published/active menus, sort_order then name) and take the first — the same
// tile the operator tapped. Returns "" when the SKU is on no published menu, in
// which case the caller skips tier-1 and behaves exactly as before this fix.
func (e *OrderEngine) menuProductIDForSku(parentSkuID string) string {
	if parentSkuID == "" {
		return ""
	}
	// plan-056 — `COALESCE(ov.is_active, mp.is_active)`, not `mp.is_active`.
	//
	// The gate has to mean "is this dish on the menu the operator is looking
	// at", and after plan-056 a dish can be off because of a LOCAL toggle that
	// has not reached Cloud yet. Reading the replica column alone would keep
	// choosing a just-turned-off menu_product as the tier-1 source of topping
	// prices — a wrong add-on price on a real bill, from a row the screen no
	// longer shows.
	var menuProductID string
	_ = e.db.QueryRow(`
		SELECT mp.id
		FROM pos_product_skus ps
		JOIN pos_menu_products mp ON mp.product_id = ps.product_id
		JOIN pos_menus m          ON m.id = mp.menu_id
		LEFT JOIN pos_menu_availability_overrides ov
		       ON ov.entity_type = 'menu_product' AND ov.entity_id = mp.id
		WHERE ps.id = ?
		  AND COALESCE(ov.is_active, mp.is_active) = 1
		  AND m.status IN ('published','Published','active','Active')
		ORDER BY m.sort_order, m.name
		LIMIT 1`, parentSkuID).Scan(&menuProductID)
	return menuProductID
}
