package service

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

// Full-flow regression for the user's reported bug.
//
//  1. Workstation pulls a 1000-yen coupon.
//  2. Cashier applies it to an order — discount stamped at 1000.
//  3. Cashier releases the coupon.
//  4. HQ edits the coupon to 1500 on Cloud.
//  5. Next sync tick UPSERTs the row with the new value.
//  6. Cashier re-applies the coupon — must get 1500 off, not 1000.
//
// Pre-fix this scenario broke at step 5 (FK abort) — the new value
// never landed. Even with UPSERT correct, this end-to-end test guards
// against regressions where ApplyCouponWithOptions might read a stale
// row (e.g. via an in-memory cache nobody noticed).
func TestCouponEdit_FullApplyReleaseReApplyFlow(t *testing.T) {
	stage := "first"
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		discount := 1000
		if stage == "second" {
			discount = 1500
		}
		w.Write([]byte(fmt.Sprintf(`{"data":[{
			"id":"cp-edit","code":"EDIT","name":"Trừ %d",
			"discount_type":"fixed","discount_value":%d,
			"min_order_subtotal":0,"status":"draft",
			"stacking_mode":"exclusive","branches":[]
		}]}`, discount, discount)))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	puller := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	orders := NewOrderEngine(db, 10)
	coupons := NewCouponEngine(db, orders)

	// 1. First sync — coupon at 1000.
	if err := puller.PullCoupons(context.Background()); err != nil {
		t.Fatalf("first pull: %v", err)
	}

	// 2. Create order, apply coupon.
	orderID := mkOrderForFlow(t, db, 5000)
	applied1, err := coupons.ApplyCouponWithOptions(orderID, "EDIT", ApplyCouponOptions{})
	if err != nil {
		t.Fatalf("first apply: %v", err)
	}
	if applied1.DiscountApplied != 1000 {
		t.Fatalf("first apply: want 1000 off, got %d", applied1.DiscountApplied)
	}

	// 3. Release.
	if _, err := coupons.ReleaseCoupon(orderID); err != nil {
		t.Fatalf("release: %v", err)
	}

	// 4 + 5. HQ edits → next sync UPSERT.
	stage = "second"
	if err := puller.PullCoupons(context.Background()); err != nil {
		t.Fatalf("second pull after HQ edit: %v", err)
	}

	// Verify the local row was actually rewritten.
	var dv int
	_ = db.QueryRow(`SELECT discount_value FROM coupons WHERE code = 'EDIT'`).Scan(&dv)
	if dv != 1500 {
		t.Fatalf("DB after second pull: want discount_value=1500, got %d", dv)
	}

	// 6. Re-apply — must compute 1500 off the same order.
	applied2, err := coupons.ApplyCouponWithOptions(orderID, "EDIT", ApplyCouponOptions{})
	if err != nil {
		t.Fatalf("re-apply: %v", err)
	}
	if applied2.DiscountApplied != 1500 {
		t.Errorf("re-apply after HQ edit: want 1500 off, got %d (the user's reported bug)", applied2.DiscountApplied)
	}

	// And the order's running discount + total reflect the new value.
	var orderDiscount, orderTotal int
	_ = db.QueryRow(`SELECT discount_amount, total_amount FROM orders WHERE id = ?`, orderID).
		Scan(&orderDiscount, &orderTotal)
	if orderDiscount != 1500 {
		t.Errorf("orders.discount_amount: want 1500, got %d", orderDiscount)
	}
	// plan-043 (T3.5) — the coupon apply now re-runs the §8 engine on the
	// discounted base (this fixture order has no line items → single legacy-rate
	// group at the engine's 10% fallback). taxable = 5000-1500 = 3500, tax = 350,
	// total = 3850. The pre-plan-043 arithmetic (total = subtotal - discount,
	// tax-blind) gave 3500 — the bug this task fixes.
	if orderTotal != 3850 {
		t.Errorf("orders.total_amount: want 3850 (3500 taxable + 350 tax @10%%), got %d", orderTotal)
	}
}

func mkOrderForFlow(t *testing.T, db *store.DB, subtotal int) string {
	t.Helper()
	id := uuid.NewString()
	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, discount_amount, total_amount, paid_amount,
		    created_at, updated_at)
		VALUES (?, ?, 'spot', 'open', ?, ?, 0, ?, 0, ?, ?)`,
		id, "T-"+id[:8], now, subtotal, subtotal, now, now); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	return id
}
