package handler

import (
	"encoding/json"
	"net/http"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// Sync state inspection endpoints (loopback-only). Operators chasing
// "HQ edit didn't propagate to LAN mode" complaints can hit these
// from a local browser to see exactly what the workstation has in
// its replica tables, with last-synced timestamps so it's obvious
// whether the puller is running.

// GET /api/sync/coupons-state
//
// Response:
//
//	{
//	  "count": <total rows>,
//	  "coupons": [
//	    {
//	      "id": "...", "code": "...", "name": "...",
//	      "discount_type": "fixed|percent",
//	      "discount_value": 1500,
//	      "status": "draft|paused", "times_used": 0,
//	      "valid_from": "...", "valid_until": "...",
//	      "local_synced_at": "2026-06-17 06:30:12",
//	      "branches": ["br-1", "br-2"]
//	    }, ...
//	  ]
//	}
func (s *Server) handleSyncCouponsState(w http.ResponseWriter, _ *http.Request) {
	rows, err := s.db.Query(`
		SELECT id, code, name, COALESCE(description, ''),
		       discount_type, discount_value,
		       min_order_subtotal, COALESCE(max_discount_cap, -1),
		       COALESCE(valid_from, ''), COALESCE(valid_until, ''),
		       COALESCE(usage_limit_total, -1), times_used,
		       COALESCE(usage_limit_per_customer, -1),
		       status, stacking_mode, applies_to,
		       COALESCE(brand_id, ''), COALESCE(organization_id, ''),
		       COALESCE(local_synced_at, '')
		FROM coupons ORDER BY code`)
	if err != nil {
		writeServerError(w, nil, err)
		return
	}
	defer rows.Close()

	out := []map[string]any{}
	for rows.Next() {
		var id, code, name, desc, dtype, validFrom, validUntil string
		var status, stacking, appliesTo, brandID, orgID, syncedAt string
		var dval, minSub, maxCap, usageLimit, timesUsed, perCustLimit int
		if err := rows.Scan(&id, &code, &name, &desc,
			&dtype, &dval, &minSub, &maxCap,
			&validFrom, &validUntil,
			&usageLimit, &timesUsed, &perCustLimit,
			&status, &stacking, &appliesTo, &brandID, &orgID, &syncedAt); err != nil {
			writeServerError(w, nil, err)
			return
		}
		row := map[string]any{
			"id":                 id,
			"code":               code,
			"name":               name,
			"description":        nilIfEmpty(desc),
			"discount_type":      dtype,
			"discount_value":     dval,
			"min_order_subtotal": minSub,
			"valid_from":         nilIfEmpty(validFrom),
			"valid_until":        nilIfEmpty(validUntil),
			"times_used":         timesUsed,
			"status":             status,
			"stacking_mode":      stacking,
			"applies_to":         appliesTo,
			"brand_id":           nilIfEmpty(brandID),
			"organization_id":    nilIfEmpty(orgID),
			"local_synced_at":    syncedAt,
			"branches":           collectCouponBranches(s.db, id),
		}
		if maxCap >= 0 {
			row["max_discount_cap"] = maxCap
		}
		if usageLimit >= 0 {
			row["usage_limit_total"] = usageLimit
		}
		if perCustLimit >= 0 {
			row["usage_limit_per_customer"] = perCustLimit
		}
		out = append(out, row)
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"count":   len(out),
		"coupons": out,
	})
}

func collectCouponBranches(db *store.DB, couponID string) []string {
	rows, err := db.Query(`SELECT branch_id FROM coupon_branches WHERE coupon_id = ?`, couponID)
	if err != nil {
		return []string{}
	}
	defer rows.Close()
	out := []string{}
	for rows.Next() {
		var b string
		_ = rows.Scan(&b)
		out = append(out, b)
	}
	return out
}

// GET /api/sync/promotions-state — same shape for menu_promotions.
func (s *Server) handleSyncPromotionsState(w http.ResponseWriter, _ *http.Request) {
	rows, err := s.db.Query(`
		SELECT id, name, COALESCE(description, ''),
		       discount_type, discount_value,
		       COALESCE(starts_at, ''), COALESCE(ends_at, ''),
		       is_active, exclusive_with_coupons,
		       COALESCE(stacking_mode, ''), COALESCE(applies_to, ''),
		       COALESCE(branch_id, ''), COALESCE(brand_id, ''), COALESCE(organization_id, ''),
		       COALESCE(promo_created_at, ''), priority,
		       COALESCE(local_synced_at, '')
		FROM menu_promotions ORDER BY name`)
	if err != nil {
		writeServerError(w, nil, err)
		return
	}
	defer rows.Close()

	out := []map[string]any{}
	for rows.Next() {
		var id, name, desc, dtype, startsAt, endsAt string
		var stacking, appliesTo, branchID, brandID, orgID, createdAt, syncedAt string
		var dval, active, excl, priority int
		if err := rows.Scan(&id, &name, &desc,
			&dtype, &dval, &startsAt, &endsAt,
			&active, &excl, &stacking, &appliesTo,
			&branchID, &brandID, &orgID, &createdAt, &priority, &syncedAt); err != nil {
			writeServerError(w, nil, err)
			return
		}
		out = append(out, map[string]any{
			"id":                     id,
			"name":                   name,
			"description":            nilIfEmpty(desc),
			"discount_type":          dtype,
			"discount_value":         dval,
			"starts_at":              nilIfEmpty(startsAt),
			"ends_at":                nilIfEmpty(endsAt),
			"is_active":              active == 1,
			"exclusive_with_coupons": excl == 1,
			"stacking_mode":          nilIfEmpty(stacking),
			"applies_to":             nilIfEmpty(appliesTo),
			"branch_id":              nilIfEmpty(branchID),
			"brand_id":               nilIfEmpty(brandID),
			"organization_id":        nilIfEmpty(orgID),
			"created_at":             nilIfEmpty(createdAt),
			"priority":               priority,
			"local_synced_at":        syncedAt,
			"product_ids":            collectPromotionProductIDs(s.db, id),
		})
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"count":      len(out),
		"promotions": out,
	})
}

func collectPromotionProductIDs(db *store.DB, promoID string) []string {
	rows, err := db.Query(`SELECT product_id FROM menu_promotion_products WHERE promotion_id = ?`, promoID)
	if err != nil {
		return []string{}
	}
	defer rows.Close()
	out := []string{}
	for rows.Next() {
		var p string
		_ = rows.Scan(&p)
		out = append(out, p)
	}
	return out
}

// json helper — keeps the route handlers free of repeated Encode boilerplate.
func init() {
	// Compile-time check that json.NewEncoder still exists; prevents
	// the linter from flagging encoding/json as unused in some builds
	// of this file before the helpers below land.
	var _ = json.NewEncoder
}
