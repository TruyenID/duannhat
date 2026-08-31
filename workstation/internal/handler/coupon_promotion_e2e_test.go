package handler

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// E2E integration test (Phase D): drive the LAN apply-coupon HTTP handler
// through the full Phase A→C stack — branch whitelist enforcement,
// computed-status rejection, snapshot persistence on success — so we
// don't regress when a future change moves a guard up or down the
// validation order.
//
// Each test re-uses newServerWithAuth's "branch-A" default workstation
// pairing, then seeds the catalog + coupon rows under that branch.

func setupOrderEngines(t *testing.T, srv *Server) *service.CouponEngine {
	t.Helper()
	orders := service.NewOrderEngine(srv.db)
	srv.orders = orders
	srv.coupons = service.NewCouponEngine(srv.db, orders)
	srv.hub = NewHub()
	return srv.coupons
}

func seedOrderForCoupon(t *testing.T, srv *Server, subtotal int) string {
	t.Helper()
	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatalf("create order: %v", err)
	}
	if _, err := srv.db.Exec(
		`UPDATE orders SET subtotal = ?, total_amount = ? WHERE id = ?`,
		subtotal, subtotal, o.ID,
	); err != nil {
		t.Fatalf("seed subtotal: %v", err)
	}
	// #2188 — the engine no longer invents a rate group from a bare stored
	// subtotal; materialise the cart as one 10%-stamped line.
	if _, err := srv.db.Exec(
		`INSERT INTO order_items (id, customer_order_id, quantity, unit_price, subtotal, status, tax_rate, created_at, updated_at)
		 VALUES (?, ?, 1, ?, ?, 'pending', 10, datetime('now'), datetime('now'))`,
		"line-"+o.ID, o.ID, subtotal, subtotal,
	); err != nil {
		t.Fatalf("seed order line: %v", err)
	}
	return o.ID
}

func postApplyCoupon(t *testing.T, srv *Server, orderID, body string) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest("POST",
		"/api/v1/pos/orders/"+orderID+"/apply-coupon",
		bytes.NewReader([]byte(body)))
	req.SetPathValue("id", orderID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosApplyCoupon(rec, req)
	return rec
}

func TestE2E_ApplyCoupon_StampsSnapshotAndVia(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	setupOrderEngines(t, srv)

	mustExec(t, db, `
		INSERT INTO coupons (id, code, name, description, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode, brand_id, organization_id)
		VALUES ('cp-1','E2EOK','E2E OK','full stack',
		        'flat', 200, 0, 'draft', 'exclusive', 'brand-X', 'org-X')`)

	orderID := seedOrderForCoupon(t, srv, 1000)

	rec := postApplyCoupon(t, srv, orderID, `{"code":"E2EOK","customer_id":"cust-1"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("apply: want 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	// Snapshot persisted.
	var snap, via string
	if err := db.QueryRow(`
		SELECT coupon_snapshot, redeemed_via
		FROM coupon_redemptions WHERE order_id = ?`, orderID).
		Scan(&snap, &via); err != nil {
		t.Fatalf("readback: %v", err)
	}
	if via != "pos" {
		t.Errorf("via: want pos, got %q", via)
	}
	if !strings.Contains(snap, `"code":"E2EOK"`) {
		t.Errorf("snapshot missing code: %s", snap)
	}
	if !strings.Contains(snap, `"discount_value":200`) {
		t.Errorf("snapshot missing discount_value: %s", snap)
	}
}

func TestE2E_ApplyCoupon_BranchWhitelistDenies(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	setupOrderEngines(t, srv)

	mustExec(t, db, `
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode)
		VALUES ('cp-br','SCOPED','Branch Scoped','flat', 100, 0, 'draft', 'exclusive')`)
	// Scope to branch-B (workstation is paired to branch-A).
	mustExec(t, db, `INSERT INTO coupon_branches (coupon_id, branch_id) VALUES ('cp-br','branch-B')`)

	orderID := seedOrderForCoupon(t, srv, 1000)

	rec := postApplyCoupon(t, srv, orderID, `{"code":"SCOPED"}`)
	if rec.Code != http.StatusConflict {
		t.Errorf("branch-scope rejection: want 409, got %d (body=%s)", rec.Code, rec.Body.String())
	}
}

func TestE2E_ApplyCoupon_ScheduledCouponRejected(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	setupOrderEngines(t, srv)

	mustExec(t, db, `
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode, valid_from)
		VALUES ('cp-sched','FUTURE','Future','flat', 100, 0, 'draft', 'exclusive',
		        '2099-01-01T00:00:00Z')`)

	orderID := seedOrderForCoupon(t, srv, 1000)

	rec := postApplyCoupon(t, srv, orderID, `{"code":"FUTURE"}`)
	if rec.Code != http.StatusConflict {
		t.Errorf("scheduled rejection: want 409, got %d (body=%s)", rec.Code, rec.Body.String())
	}
}

func TestE2E_ApplyCoupon_PausedRejected(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	setupOrderEngines(t, srv)

	mustExec(t, db, `
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode)
		VALUES ('cp-p','PAUSED','Paused','flat', 100, 0, 'paused', 'exclusive')`)

	orderID := seedOrderForCoupon(t, srv, 1000)

	rec := postApplyCoupon(t, srv, orderID, `{"code":"PAUSED"}`)
	if rec.Code != http.StatusConflict {
		t.Errorf("paused rejection: want 409, got %d (body=%s)", rec.Code, rec.Body.String())
	}
}

// Regression for the "trừ 0" bug: HQ creates a fixed-amount coupon
// (discount_type='fixed', discount_value=1000). Pre-fix computeDiscount
// only handled "flat"/"percent" so the switch fell through and returned
// 0 — the apply succeeded but the receipt showed "Coupon -¥0". This
// pins the full handler path: apply must report discount_applied = 1000
// AND update the order's coupon_discount_amount + total_amount.
func TestE2E_ApplyCoupon_FixedTypeAppliesFullDiscount(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	setupOrderEngines(t, srv)

	mustExec(t, db, `
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode)
		VALUES ('cp-fixed1k','MINUS1K','Trừ 1000','fixed', 1000, 0, 'draft', 'exclusive')`)

	orderID := seedOrderForCoupon(t, srv, 5000)

	rec := postApplyCoupon(t, srv, orderID, `{"code":"MINUS1K"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("apply: want 200, got %d body=%s", rec.Code, rec.Body.String())
	}

	// Check the redemption ledger discount.
	var discount, totalAmt int
	if err := db.QueryRow(`
		SELECT discount_amount FROM coupon_redemptions WHERE order_id = ?`, orderID).
		Scan(&discount); err != nil {
		t.Fatalf("redemption readback: %v", err)
	}
	if discount != 1000 {
		t.Errorf("redemption discount: want 1000, got %d (the 'trừ 0' bug)", discount)
	}

	// And the order's running total reflects the same.
	if err := db.QueryRow(`SELECT total_amount FROM orders WHERE id = ?`, orderID).Scan(&totalAmt); err != nil {
		t.Fatal(err)
	}
	// plan-043 (T3.5) — the coupon apply now re-runs the §8 engine on the
	// discounted base. This fixture order has no line items → a single
	// legacy-rate group at the engine's 10% fallback: taxable = 5000-1000 =
	// 4000, tax = 400, total = 4400. Pre-plan-043 the coupon math was tax-blind
	// (total = subtotal - discount = 4000) — the drift this task removes.
	if totalAmt != 4400 {
		t.Errorf("order total after -1000 coupon: want 4400 (4000 taxable + 400 tax @10%%), got %d", totalAmt)
	}
}

// Branch whitelist: workstation paired to branch-A, coupon scoped to
// branch-A → apply succeeds. Exercises the positive path of the
// CouponEngine.branchWhitelistAllows lookup so the regression test
// rounds out (we already covered the deny case above).
func TestE2E_ApplyCoupon_BranchWhitelistAllowsMatchingBranch(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	setupOrderEngines(t, srv)

	mustExec(t, db, `
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode)
		VALUES ('cp-okbr','MYBRANCH','My branch','flat', 100, 0, 'draft', 'exclusive')`)
	mustExec(t, db, `INSERT INTO coupon_branches (coupon_id, branch_id) VALUES ('cp-okbr','branch-A')`)

	orderID := seedOrderForCoupon(t, srv, 1000)

	rec := postApplyCoupon(t, srv, orderID, `{"code":"MYBRANCH"}`)
	if rec.Code != http.StatusOK {
		t.Errorf("matching-branch apply: want 200, got %d (body=%s)", rec.Code, rec.Body.String())
	}
}
