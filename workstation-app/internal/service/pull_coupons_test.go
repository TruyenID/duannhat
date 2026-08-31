package service

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

// PullCoupons must ingest backend-native field names AND the legacy
// aliases so a workstation rolled out at the rename boundary still gets
// a populated replica during the two-build overlap. Also asserts the
// branches[] pivot lands in coupon_branches so the branch whitelist
// enforces correctly in LAN-mode apply.
func TestPullCoupons_IngestsBackendNamesAndBranches(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":[
			{
				"id":"cp-1","code":"PARITY","name":"Parity",
				"description":"backend-native fields",
				"discount_type":"fixed","discount_value":500,
				"min_order_subtotal":1000,
				"max_discount_cap":800,
				"usage_limit_per_customer":3,
				"usage_limit_total":100,
				"times_used":5,
				"status":"draft",
				"stacking_mode":"exclusive",
				"exclusive_with_promotions":false,
				"applies_to":"order",
				"brand_id":"brand-A",
				"organization_id":"org-A",
				"branches":["br-1","br-2"],
				"valid_from":"","valid_until":""
			},
			{
				"id":"cp-legacy","code":"OLDWIRE","name":"Legacy",
				"discount_type":"percent","discount_value":1500,
				"min_order_amount":2000,
				"max_discount":1200,
				"per_customer_limit":2,
				"usage_limit_total":50,
				"times_used":0,
				"status":"draft",
				"stacking_mode":"exclusive",
				"exclusive_with_promotions":false,
				"applies_to":"order",
				"branches":[]
			}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullCoupons(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	// Backend-native row landed via new field names.
	var minSub, maxCap, perCust int
	var desc, brandID, orgID string
	if err := db.QueryRow(`
		SELECT min_order_subtotal, max_discount_cap, usage_limit_per_customer,
		       description, brand_id, organization_id
		FROM coupons WHERE code = ?`, "PARITY").
		Scan(&minSub, &maxCap, &perCust, &desc, &brandID, &orgID); err != nil {
		t.Fatalf("readback PARITY: %v", err)
	}
	if minSub != 1000 || maxCap != 800 || perCust != 3 {
		t.Errorf("PARITY values wrong: minSub=%d maxCap=%d perCust=%d", minSub, maxCap, perCust)
	}
	if desc != "backend-native fields" || brandID != "brand-A" || orgID != "org-A" {
		t.Errorf("PARITY metadata wrong: desc=%q brand=%q org=%q", desc, brandID, orgID)
	}

	// Branches pivot populated for PARITY.
	var brCount int
	_ = db.QueryRow(`SELECT COUNT(*) FROM coupon_branches WHERE coupon_id = 'cp-1'`).Scan(&brCount)
	if brCount != 2 {
		t.Errorf("coupon_branches for PARITY want 2, got %d", brCount)
	}

	// Legacy-name row landed via fallback aliases (min_order_amount → min_order_subtotal etc.).
	var lMinSub, lMaxCap, lPerCust int
	if err := db.QueryRow(`
		SELECT min_order_subtotal, max_discount_cap, usage_limit_per_customer
		FROM coupons WHERE code = ?`, "OLDWIRE").
		Scan(&lMinSub, &lMaxCap, &lPerCust); err != nil {
		t.Fatalf("readback OLDWIRE: %v", err)
	}
	if lMinSub != 2000 || lMaxCap != 1200 || lPerCust != 2 {
		t.Errorf("legacy alias mapping failed: minSub=%d maxCap=%d perCust=%d", lMinSub, lMaxCap, lPerCust)
	}

	// OLDWIRE has empty branches[] → no pivot rows.
	var oldBrCount int
	_ = db.QueryRow(`SELECT COUNT(*) FROM coupon_branches WHERE coupon_id = 'cp-legacy'`).Scan(&oldBrCount)
	if oldBrCount != 0 {
		t.Errorf("coupon_branches for OLDWIRE want 0, got %d", oldBrCount)
	}
}

// Phase C — i18n rows. Cloud emits translations[] on each coupon; the
// pull replaces coupon_translations atomically so a removed locale on
// Cloud drops from the device on the next tick.
func TestPullCoupons_IngestsTranslations(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":[{
			"id":"cp-i18n","code":"I18N","name":"Discount EN",
			"discount_type":"fixed","discount_value":100,
			"min_order_subtotal":0,"status":"draft",
			"stacking_mode":"exclusive","branches":[],
			"translations":[
				{"locale":"ja","name":"クーポン","description":"日本語"},
				{"locale":"vi","name":"Phiếu","description":"Tiếng Việt"}
			]
		}]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullCoupons(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	rows, err := db.Query(`
		SELECT locale, name, description FROM coupon_translations
		WHERE coupon_id = 'cp-i18n' ORDER BY locale`)
	if err != nil {
		t.Fatal(err)
	}
	defer rows.Close()
	type tr struct{ locale, name, desc string }
	got := []tr{}
	for rows.Next() {
		var t tr
		_ = rows.Scan(&t.locale, &t.name, &t.desc)
		got = append(got, t)
	}
	if len(got) != 2 {
		t.Fatalf("want 2 translation rows, got %d", len(got))
	}
	if got[0].locale != "ja" || got[0].name != "クーポン" || got[0].desc != "日本語" {
		t.Errorf("ja row wrong: %+v", got[0])
	}
	if got[1].locale != "vi" || got[1].name != "Phiếu" {
		t.Errorf("vi row wrong: %+v", got[1])
	}
}

// Replace-all semantic: a second pull with a different coupon set must
// wipe stale rows + their branch pivot.
func TestPullCoupons_ReplaceAllAlsoClearsBranches(t *testing.T) {
	stage := "first"
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		if stage == "first" {
			w.Write([]byte(`{"data":[{
				"id":"old","code":"OLD","name":"old",
				"discount_type":"fixed","discount_value":1,
				"min_order_subtotal":0,"status":"draft",
				"stacking_mode":"exclusive","branches":["br-X"]
			}]}`))
		} else {
			w.Write([]byte(`{"data":[{
				"id":"new","code":"NEW","name":"new",
				"discount_type":"fixed","discount_value":1,
				"min_order_subtotal":0,"status":"draft",
				"stacking_mode":"exclusive","branches":["br-Y"]
			}]}`))
		}
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullCoupons(context.Background()); err != nil {
		t.Fatal(err)
	}
	stage = "second"
	if err := p.PullCoupons(context.Background()); err != nil {
		t.Fatal(err)
	}

	var rowCount, oldBrCount, newBrCount int
	_ = db.QueryRow(`SELECT COUNT(*) FROM coupons`).Scan(&rowCount)
	_ = db.QueryRow(`SELECT COUNT(*) FROM coupon_branches WHERE coupon_id = 'old'`).Scan(&oldBrCount)
	_ = db.QueryRow(`SELECT COUNT(*) FROM coupon_branches WHERE coupon_id = 'new'`).Scan(&newBrCount)

	if rowCount != 1 {
		t.Errorf("coupons after replace: want 1, got %d", rowCount)
	}
	if oldBrCount != 0 {
		t.Errorf("stale old pivot rows: %d (should be 0)", oldBrCount)
	}
	if newBrCount != 1 {
		t.Errorf("new pivot row missing: %d", newBrCount)
	}
}
