package handler

import (
	"database/sql"
	"errors"
	"net/http"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Phase 4 — coupon apply/release handlers backed by the local coupons +
// order_coupons tables. The engine (service/coupon_service.go) implements
// the validation rules; this layer is a thin HTTP translator.

// POST /api/v1/pos/orders/{id}/apply-coupon
//
// Body: { code, customer_id?, downgrade_exclusive_promotions? }
func (s *Server) handleLocalPosApplyCoupon(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	var body struct {
		Code                         string `json:"code"`
		CustomerID                   string `json:"customer_id"`
		DowngradeExclusivePromotions bool   `json:"downgrade_exclusive_promotions"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	if body.Code == "" {
		writeError(w, http.StatusBadRequest, "code required")
		return
	}

	// Inject the workstation's paired branch_id so the engine can run
	// CouponService::validateBranch locally (coupon_branches pivot).
	// Empty branchID falls open inside the engine — Cloud re-validates
	// on sync UP if the device hadn't completed pairing yet.
	applied, err := s.coupons.ApplyCouponWithOptions(id, body.Code, service.ApplyCouponOptions{
		CustomerID:                   body.CustomerID,
		DowngradeExclusivePromotions: body.DowngradeExclusivePromotions,
		BranchID:                     s.workstationBranchID(),
	})
	if err != nil {
		writeCouponError(w, err)
		return
	}

	o, _ := s.orders.GetByID(id)
	s.hub.BroadcastEvent("order_updated", o)
	s.enqueueOrderSync("order.apply_coupon", id, map[string]any{
		"coupon_code":                    body.Code,
		"customer_id":                    body.CustomerID,
		"downgrade_exclusive_promotions": body.DowngradeExclusivePromotions,
		"discount_applied":               applied.DiscountApplied,
	})
	s.auditLogPOS(r, "order.apply_coupon", "order", id, "")
	writeJSON(w, http.StatusOK, map[string]any{"data": s.customerOrderShape(o, localeFromRequest(r))})
}

// DELETE /api/v1/pos/orders/{id}/coupon
func (s *Server) handleLocalPosReleaseCoupon(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	o, err := s.coupons.ReleaseCoupon(id)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	s.enqueueOrderSync("order.release_coupon", id, nil)
	s.auditLogPOS(r, "order.release_coupon", "order", id, "")
	writeJSON(w, http.StatusOK, map[string]any{"data": s.customerOrderShape(o, localeFromRequest(r))})
}

// couponErrorStatus maps engine sentinel errors onto HTTP status codes that
// match Cloud's CouponController responses, so pos-web error handling is
// identical regardless of where the call landed.
func couponErrorStatus(err error) int {
	switch {
	case errors.Is(err, service.ErrCouponNotFound):
		return http.StatusNotFound
	case errors.Is(err, service.ErrCouponPaused),
		errors.Is(err, service.ErrCouponWindow),
		errors.Is(err, service.ErrCouponExhausted),
		errors.Is(err, service.ErrCouponAlreadyActive),
		errors.Is(err, service.ErrCouponPerCustomerExhausted),
		errors.Is(err, service.ErrCouponExcludedByPromotion),
		errors.Is(err, service.ErrCouponBranchScope):
		return http.StatusConflict
	case errors.Is(err, service.ErrCouponMinOrder),
		errors.Is(err, service.ErrCouponCustomerRequired):
		return http.StatusUnprocessableEntity
	default:
		return http.StatusBadRequest
	}
}

// couponErrorCode maps engine sentinel errors onto the snake_case
// `error_code` strings Cloud's CouponException emits. pos-web's
// parseCouponError(err.body.error_code) then renders the matching
// `coupon.error.<code>` i18n key.
//
// Pre-fix the LAN handler only sent err.Error() as `message`, so
// pos-web fell through to the hard-coded "generic" code → the
// untranslated `coupon.error.generic` string showed verbatim on
// screen. Returning the right code closes the parity gap with Cloud.
func couponErrorCode(err error) string {
	switch {
	case errors.Is(err, service.ErrCouponNotFound):
		return "coupon_not_found"
	case errors.Is(err, service.ErrCouponPaused):
		return "coupon_paused"
	case errors.Is(err, service.ErrCouponWindow):
		// Engine collapses scheduled + expired into the same window
		// sentinel; default to "coupon_expired" because pos-web's
		// existing copy is identical for both states.
		return "coupon_expired"
	case errors.Is(err, service.ErrCouponExhausted):
		return "coupon_exhausted"
	case errors.Is(err, service.ErrCouponMinOrder):
		return "coupon_min_subtotal_not_met"
	case errors.Is(err, service.ErrCouponCustomerRequired):
		return "customer_required"
	case errors.Is(err, service.ErrCouponAlreadyActive):
		return "order_not_modifiable"
	case errors.Is(err, service.ErrCouponPerCustomerExhausted):
		return "coupon_already_used_by_customer"
	case errors.Is(err, service.ErrCouponExcludedByPromotion):
		return "coupon_excluded_by_active_promotion"
	case errors.Is(err, service.ErrCouponBranchScope):
		return "coupon_branch_not_eligible"
	default:
		return "generic"
	}
}

// writeCouponError emits the same {error_code, message, meta} envelope
// Cloud's CouponController returns so pos-web's i18n flow is
// transport-agnostic.
func writeCouponError(w http.ResponseWriter, err error) {
	body := map[string]any{
		"error_code": couponErrorCode(err),
		"message":    err.Error(),
	}
	writeJSON(w, couponErrorStatus(err), body)
}
