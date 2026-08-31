package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Pre-fix the LAN apply-coupon handler returned `{"error":"<message>"}`
// from writeError(...). pos-web's parseCouponError reads
// `body.error_code`, falls through to `{code: "generic"}` when missing,
// then renders the i18n key "coupon.error.generic" verbatim because
// the missing-key fallback in t() returns the key as a truthy string.
//
// The fix is two-sided: pos-web's t() now warns + i18n adds
// `coupon.error.generic`, AND this LAN handler emits the same
// {error_code, message} envelope Cloud's CouponException produces so
// the structured-code path lights up. This test pins both edges.

func TestApplyCoupon_InvalidCodeEmitsStructuredErrorCode(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.orders = service.NewOrderEngine(db)
	srv.coupons = service.NewCouponEngine(db, srv.orders)
	srv.hub = NewHub()

	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}

	// Code doesn't exist anywhere → engine returns ErrCouponNotFound
	// → handler must emit error_code = "coupon_not_found".
	req := httptest.NewRequest("POST",
		"/api/v1/pos/orders/"+o.ID+"/apply-coupon",
		bytes.NewReader([]byte(`{"code":"DOES_NOT_EXIST"}`)))
	req.SetPathValue("id", o.ID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosApplyCoupon(rec, req)

	if rec.Code != http.StatusNotFound {
		t.Errorf("status: want 404, got %d body=%s", rec.Code, rec.Body.String())
	}

	var body map[string]any
	if err := json.NewDecoder(rec.Body).Decode(&body); err != nil {
		t.Fatalf("decode: %v", err)
	}
	code, _ := body["error_code"].(string)
	if code != "coupon_not_found" {
		t.Errorf("error_code: want 'coupon_not_found', got %q (the user-reported i18n.generic bug)", code)
	}
	if msg, _ := body["message"].(string); msg == "" {
		t.Errorf("message should still ship for non-i18n debug surfaces, got empty")
	}
}

// Paused coupon → expect error_code = "coupon_paused" + status 409.
func TestApplyCoupon_PausedEmitsPausedErrorCode(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.orders = service.NewOrderEngine(db)
	srv.coupons = service.NewCouponEngine(db, srv.orders)
	srv.hub = NewHub()

	mustExec(t, db, `
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode)
		VALUES ('cp-1', 'PAUSED', 'p', 'fixed', 100, 0, 'paused', 'exclusive')`)

	o, _ := srv.orders.Create(service.CreateOrderInput{OrderType: "spot"}, nil)
	// Make sure subtotal exceeds min so the engine reaches the
	// paused-status check.
	_, _ = db.Exec(`UPDATE orders SET subtotal = 5000, total_amount = 5000 WHERE id = ?`, o.ID)

	req := httptest.NewRequest("POST",
		"/api/v1/pos/orders/"+o.ID+"/apply-coupon",
		bytes.NewReader([]byte(`{"code":"PAUSED"}`)))
	req.SetPathValue("id", o.ID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosApplyCoupon(rec, req)

	if rec.Code != http.StatusConflict {
		t.Errorf("status: want 409, got %d", rec.Code)
	}
	var body map[string]any
	_ = json.NewDecoder(rec.Body).Decode(&body)
	if body["error_code"] != "coupon_paused" {
		t.Errorf("error_code: want coupon_paused, got %v", body["error_code"])
	}
}
