package service

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Regression for the "HQ edited 1000 → 1500, pos-web still shows 1000"
// bug. coupon_redemptions has FK → coupons(id); pre-fix the puller did
// `DELETE FROM coupons` first, which under PRAGMA foreign_keys=ON aborts
// the whole sync tx the moment any redemption row exists. Result: every
// HQ edit silently failed to propagate after the first customer redeemed.
//
// With UPSERT semantics the row identity is preserved AND every
// Cloud-owned column refreshes on each tick, so a second pull with a
// changed discount_value lands cleanly.
func TestPullCoupons_UpdatesValueEvenWithExistingRedemption(t *testing.T) {
	stage := "first"
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		discount := 1000
		name := "Trừ 1000"
		if stage == "second" {
			discount = 1500
			name = "Trừ 1500"
		}
		fmt := `{"data":[{
			"id":"cp-edit","code":"EDIT","name":"%s",
			"discount_type":"fixed","discount_value":%d,
			"min_order_subtotal":0,"status":"draft",
			"stacking_mode":"exclusive","branches":[]
		}]}`
		w.Write([]byte(sprintf(fmt, name, discount)))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	// First pull: lands the original 1000-yen coupon.
	if err := p.PullCoupons(context.Background()); err != nil {
		t.Fatalf("first pull: %v", err)
	}
	var v1 int
	_ = db.QueryRow(`SELECT discount_value FROM coupons WHERE id = 'cp-edit'`).Scan(&v1)
	if v1 != 1000 {
		t.Fatalf("first pull: want 1000, got %d", v1)
	}

	// Simulate a real-world apply — write a coupon_redemptions row.
	// This is the FK reference that pre-fix would abort the next pull.
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, discount_amount, total_amount, paid_amount, created_at, updated_at)
		VALUES ('o-1','ORD-1','spot','open',datetime('now'),
		        5000, 0, 5000, 0, datetime('now'), datetime('now'))`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO coupon_redemptions (id, coupon_id, coupon_code, order_id,
		    discount_amount, redeemed_at)
		VALUES ('r-1','cp-edit','EDIT','o-1', 1000, datetime('now'))`); err != nil {
		t.Fatal(err)
	}

	// HQ edits the discount from 1000 → 1500 on Cloud.
	stage = "second"
	if err := p.PullCoupons(context.Background()); err != nil {
		t.Fatalf("second pull (with existing redemption): %v", err)
	}

	var v2 int
	var nameAfter string
	if err := db.QueryRow(`SELECT discount_value, name FROM coupons WHERE id = 'cp-edit'`).
		Scan(&v2, &nameAfter); err != nil {
		t.Fatalf("readback after edit: %v", err)
	}
	if v2 != 1500 {
		t.Errorf("HQ edit (1000 → 1500) must propagate: want 1500, got %d", v2)
	}
	if nameAfter != "Trừ 1500" {
		t.Errorf("name update: want 'Trừ 1500', got %q", nameAfter)
	}

	// The existing redemption row must STILL exist — UPSERT doesn't
	// touch its row, so the per-customer cap ledger stays intact.
	var rCount int
	_ = db.QueryRow(`SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = 'cp-edit'`).Scan(&rCount)
	if rCount != 1 {
		t.Errorf("redemption row should be preserved: count = %d", rCount)
	}
}

// Same regression for menu_promotions — order_items.promotion_id FK
// triggers the identical cascade failure under DELETE+INSERT.
func TestPullPromotions_UpdatesValueEvenWithExistingOrderItem(t *testing.T) {
	stage := "first"
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		discount := 10
		name := "HH 10"
		if stage == "second" {
			discount = 20
			name = "HH 20"
		}
		fmt := `{"data":[{
			"id":"promo-edit","name":"%s",
			"discount_type":"percent","discount_value":%d,
			"is_active":true,"applies_to":"all_items",
			"stacking_mode":"stackable_with_coupons",
			"product_ids":[],"schedules":[]
		}]}`
		w.Write([]byte(sprintf(fmt, name, discount)))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullPromotions(context.Background()); err != nil {
		t.Fatalf("first pull: %v", err)
	}

	// Seed an order_item with this promotion_id, mirroring a real
	// apply-to-line at item-add time. Pre-fix the next sync would
	// abort here when DELETE FROM menu_promotions tripped the FK.
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, discount_amount, total_amount, paid_amount, created_at, updated_at)
		VALUES ('o-2','ORD-2','spot','open',datetime('now'),
		        1000, 0, 1000, 0, datetime('now'), datetime('now'))`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`
		INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity,
		    unit_price, subtotal, printer_group, status, print_status,
		    promotion_id, created_at, updated_at)
		VALUES ('i-1','o-2','test',1,900,900,'kitchen','pending','pending',
		        'promo-edit', datetime('now'), datetime('now'))`); err != nil {
		t.Fatal(err)
	}

	stage = "second"
	if err := p.PullPromotions(context.Background()); err != nil {
		t.Fatalf("second pull (with existing order_item ref): %v", err)
	}

	var v int
	_ = db.QueryRow(`SELECT discount_value FROM menu_promotions WHERE id = 'promo-edit'`).Scan(&v)
	if v != 20 {
		t.Errorf("HQ edit (10%% → 20%%) must propagate: want 20, got %d", v)
	}
}

func sprintf(layout string, args ...any) string {
	return fmt.Sprintf(layout, args...)
}
