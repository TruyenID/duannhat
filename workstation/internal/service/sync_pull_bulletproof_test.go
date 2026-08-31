package service

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// Bulletproof sync — one bad row from Cloud (e.g. duplicate code, FK
// constraint, future schema drift) used to roll back the WHOLE pull.
// Result: a brand-new HQ coupon never landed because one stale row in
// the same batch tripped UNIQUE(code). With per-row SAVEPOINTs, the
// good rows commit and the bad one logs + drops.
func TestPullCoupons_OneBadRowDoesntKillTheBatch(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		// Three rows: two valid, one with discount_type that violates
		// the local CHECK enum. Pre-fix the third row's INSERT would
		// abort the tx and the first two would never land. With
		// per-row savepoints, the first two commit and the third
		// rolls back to its own savepoint.
		w.Write([]byte(`{"data":[
			{"id":"cp-good-1","code":"GOODONE","name":"Good 1",
			 "discount_type":"fixed","discount_value":500,
			 "min_order_subtotal":0,"status":"draft",
			 "stacking_mode":"exclusive","branches":[]},
			{"id":"cp-bad","code":"BAD","name":"Bad",
			 "discount_type":"","discount_value":0,
			 "min_order_subtotal":0,"status":"draft",
			 "stacking_mode":"exclusive","branches":[]},
			{"id":"cp-good-2","code":"GOODTWO","name":"Good 2",
			 "discount_type":"percent","discount_value":15,
			 "min_order_subtotal":0,"status":"draft",
			 "stacking_mode":"exclusive","branches":[]}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullCoupons(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	// Both GOOD rows should be present even though BAD one failed.
	var count int
	_ = db.QueryRow(`SELECT COUNT(*) FROM coupons`).Scan(&count)
	if count < 2 {
		t.Errorf("good rows must survive bad-row failure: count=%d", count)
	}
	var v1, v2 int
	_ = db.QueryRow(`SELECT discount_value FROM coupons WHERE code='GOODONE'`).Scan(&v1)
	_ = db.QueryRow(`SELECT discount_value FROM coupons WHERE code='GOODTWO'`).Scan(&v2)
	if v1 != 500 {
		t.Errorf("GOODONE not landed: want 500, got %d", v1)
	}
	if v2 != 15 {
		t.Errorf("GOODTWO not landed: want 15, got %d", v2)
	}
}

// User-reported regression: a duplicate code (from a stale orphan row
// that never got cleaned up) used to abort the entire sync because of
// the UNIQUE constraint on coupons.code. Migration 030 dropped that
// UNIQUE; PullCoupons must now succeed even when a stale row shares
// the same code as a brand-new coupon Cloud is shipping down.
func TestPullCoupons_DuplicateCodeFromStaleRowDoesntAbort(t *testing.T) {
	db := newPullerTestDB(t)

	// Seed an orphan local row that was never cleaned up — same code
	// as the brand-new coupon Cloud is about to push.
	if _, err := db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode)
		VALUES ('cp-orphan', 'DUP', 'Orphan from soft-delete', 'fixed', 100, 0, 'draft', 'exclusive')`); err != nil {
		t.Fatal(err)
	}

	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		// Brand-new coupon with the SAME code, different id (HQ
		// re-used the code after the orphan was soft-deleted on Cloud).
		w.Write([]byte(`{"data":[{
			"id":"cp-fresh","code":"DUP","name":"Fresh coupon — HQ just made me",
			"discount_type":"fixed","discount_value":2000,
			"min_order_subtotal":0,"status":"draft",
			"stacking_mode":"exclusive","branches":[]
		}]}`))
	}))
	defer cloud.Close()

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullCoupons(context.Background()); err != nil {
		t.Fatalf("pull after duplicate-code conflict: %v", err)
	}

	// The fresh coupon must land — even though the orphan still
	// has the same code.
	var freshDiscount int
	if err := db.QueryRow(`SELECT discount_value FROM coupons WHERE id='cp-fresh'`).Scan(&freshDiscount); err != nil {
		t.Fatalf("fresh coupon not landed (the user's reported bug): %v", err)
	}
	if freshDiscount != 2000 {
		t.Errorf("fresh discount: want 2000, got %d", freshDiscount)
	}
}

// Same row pulled twice must land idempotently — re-running PullCoupons
// on identical data should not multiply rows or grow pivots.
func TestPullCoupons_IdempotentRePull(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":[{
			"id":"cp-idem","code":"IDEM","name":"Idem",
			"discount_type":"fixed","discount_value":700,
			"min_order_subtotal":0,"status":"draft",
			"stacking_mode":"exclusive",
			"branches":["br-1","br-2"],
			"translations":[
				{"locale":"ja","name":"ja name","description":""},
				{"locale":"vi","name":"vi name","description":""}
			]
		}]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	for i := 0; i < 5; i++ {
		if err := p.PullCoupons(context.Background()); err != nil {
			t.Fatalf("pull #%d: %v", i, err)
		}
	}

	var count, branchCount, trCount int
	_ = db.QueryRow(`SELECT COUNT(*) FROM coupons WHERE id='cp-idem'`).Scan(&count)
	_ = db.QueryRow(`SELECT COUNT(*) FROM coupon_branches WHERE coupon_id='cp-idem'`).Scan(&branchCount)
	_ = db.QueryRow(`SELECT COUNT(*) FROM coupon_translations WHERE coupon_id='cp-idem'`).Scan(&trCount)

	if count != 1 {
		t.Errorf("idempotent re-pull: want 1 coupons row, got %d", count)
	}
	if branchCount != 2 {
		t.Errorf("idempotent re-pull: want 2 branches, got %d", branchCount)
	}
	if trCount != 2 {
		t.Errorf("idempotent re-pull: want 2 translations, got %d", trCount)
	}
}

// 100-row sync: stress-tests that per-row SAVEPOINT overhead doesn't
// drop any rows. All 100 must land.
func TestPullCoupons_LargeBatchAllRowsLand(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		var sb strings.Builder
		sb.WriteString(`{"data":[`)
		for i := 0; i < 100; i++ {
			if i > 0 {
				sb.WriteString(",")
			}
			sb.WriteString(fmt.Sprintf(`{
				"id":"cp-%03d","code":"BIG%03d","name":"Big %d",
				"discount_type":"fixed","discount_value":%d,
				"min_order_subtotal":0,"status":"draft",
				"stacking_mode":"exclusive","branches":[]
			}`, i, i, i, i*10))
		}
		sb.WriteString(`]}`)
		w.Write([]byte(sb.String()))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullCoupons(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var count int
	_ = db.QueryRow(`SELECT COUNT(*) FROM coupons`).Scan(&count)
	if count != 100 {
		t.Errorf("large-batch sync: want all 100 rows, got %d", count)
	}
}
