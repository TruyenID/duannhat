package service

import (
	"context"
	"database/sql"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/google/uuid"
)

// #2186 (tầng 2 của #2118) — máy trạm phải dùng giá trị giảm CHÍNH XÁC
// `discount_value_x100` khi Cloud phát nó, và rơi về `discount_value` (số đã
// làm tròn) khi NULL. Phần SỐ HỌC thuần đã nằm trong fixture chung
// (coupon_math_golden.json — ca 12,5% / 0,5% / 7,25% / feed-cũ); file này ghim
// phần ĐƯỜNG ỐNG quanh nó: feed → SQLite → findByCode → apply → snapshot.

// PullCoupons phải ingest discount_value_x100 khi có và giữ NULL khi Cloud cũ
// chưa phát — NULL với 0 là hai nghĩa khác nhau (0 = coupon 0% thật).
func TestPullCoupons_IngestsDiscountValueX100(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":[
			{
				"id":"cp-x100","code":"HALF","name":"12.5 off",
				"discount_type":"percent","discount_value":13,
				"discount_value_x100":1250,
				"min_order_subtotal":0,"usage_limit_total":null,"times_used":0,
				"status":"active","stacking_mode":"exclusive",
				"exclusive_with_promotions":false,"applies_to":"order",
				"branches":[],"valid_from":"","valid_until":""
			},
			{
				"id":"cp-old","code":"OLDFEED","name":"Old cloud",
				"discount_type":"percent","discount_value":15,
				"min_order_subtotal":0,"usage_limit_total":null,"times_used":0,
				"status":"active","stacking_mode":"exclusive",
				"exclusive_with_promotions":false,"applies_to":"order",
				"branches":[],"valid_from":"","valid_until":""
			}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullCoupons(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var x100 sql.NullInt64
	if err := db.QueryRow(`SELECT discount_value_x100 FROM coupons WHERE code = 'HALF'`).Scan(&x100); err != nil {
		t.Fatalf("readback HALF: %v", err)
	}
	if !x100.Valid || x100.Int64 != 1250 {
		t.Errorf("HALF discount_value_x100: want 1250, got %+v", x100)
	}

	if err := db.QueryRow(`SELECT discount_value_x100 FROM coupons WHERE code = 'OLDFEED'`).Scan(&x100); err != nil {
		t.Fatalf("readback OLDFEED: %v", err)
	}
	if x100.Valid {
		t.Errorf("OLDFEED discount_value_x100: want NULL (feed cũ), got %d", x100.Int64)
	}
}

// Đường apply trọn vòng: coupon 12,5% (feed cũ tròn thành 13) trên giỏ 10.000
// phải trừ 1.250 — không phải 1.300 (đọc trường cũ), càng không phải 125.000
// (đọc 1250 như phần trăm). Đồng thời snapshot đóng băng lúc apply phải mang
// discount_value_x100 để Cloud đối soát được sau khi coupon nguồn bị sửa.
func TestApplyCoupon_UsesExactDiscountValueX100(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	couponID := uuid.NewString()
	if _, err := db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    discount_value_x100, min_order_subtotal, status, stacking_mode,
		    times_used, exclusive_with_promotions)
		VALUES (?, 'EXACT125', 'Exact 12.5%', 'percent', 13, 1250, 0, 'active',
		    'exclusive', 0, 0)`, couponID); err != nil {
		t.Fatalf("seed coupon: %v", err)
	}

	o := mkOrder(t, db, 10000)
	applied, err := coupons.ApplyCoupon(o, "EXACT125")
	if err != nil {
		t.Fatalf("apply: %v", err)
	}
	if applied.DiscountApplied != 1250 {
		t.Errorf("discount applied: want 1250 (12.5%% của 10000), got %d — "+
			"1300 = còn đọc trường cũ đã tròn; 10000 = đơn bị kẹp về 0 đồng vì đọc 1250 như phần trăm",
			applied.DiscountApplied)
	}

	var snapJSON string
	if err := db.QueryRow(`
		SELECT coupon_snapshot FROM coupon_redemptions WHERE order_id = ?`, o).
		Scan(&snapJSON); err != nil {
		t.Fatalf("readback snapshot: %v", err)
	}
	var snap couponSnapshot
	if err := json.Unmarshal([]byte(snapJSON), &snap); err != nil {
		t.Fatalf("invalid snapshot JSON: %v\n%s", err, snapJSON)
	}
	if snap.DiscountValueX100 == nil || *snap.DiscountValueX100 != 1250 {
		t.Errorf("snapshot discount_value_x100: want 1250, got %v", snap.DiscountValueX100)
	}
	if snap.DiscountValue != 13 {
		t.Errorf("snapshot discount_value (trường cũ giữ nguyên nghĩa): want 13, got %d", snap.DiscountValue)
	}
}

// Feed cũ (cột NULL): hành vi phải Y HỆT hôm nay — dùng discount_value, và
// snapshot KHÔNG mang khoá discount_value_x100 (omitempty) để Cloud phân biệt
// "không biết" với "0%".
func TestApplyCoupon_NullX100FallsBackToDiscountValue(t *testing.T) {
	db := newCouponExtTestDB(t)
	orders := NewOrderEngine(db)
	coupons := NewCouponEngine(db, orders)

	couponID := uuid.NewString()
	if _, err := db.Exec(`
		INSERT INTO coupons (id, code, name, discount_type, discount_value,
		    min_order_subtotal, status, stacking_mode, times_used,
		    exclusive_with_promotions)
		VALUES (?, 'OLD15', 'Old 15%', 'percent', 15, 0, 'active',
		    'exclusive', 0, 0)`, couponID); err != nil {
		t.Fatalf("seed coupon: %v", err)
	}

	o := mkOrder(t, db, 1005)
	applied, err := coupons.ApplyCoupon(o, "OLD15")
	if err != nil {
		t.Fatalf("apply: %v", err)
	}
	if applied.DiscountApplied != 151 {
		t.Errorf("feed cũ: want 151 (round(150.75)), got %d — 0 = fallback NULL bị hỏng", applied.DiscountApplied)
	}

	var snapJSON string
	if err := db.QueryRow(`
		SELECT coupon_snapshot FROM coupon_redemptions WHERE order_id = ?`, o).
		Scan(&snapJSON); err != nil {
		t.Fatalf("readback snapshot: %v", err)
	}
	var raw map[string]any
	if err := json.Unmarshal([]byte(snapJSON), &raw); err != nil {
		t.Fatalf("invalid snapshot JSON: %v\n%s", err, snapJSON)
	}
	if _, present := raw["discount_value_x100"]; present {
		t.Errorf("snapshot của feed cũ không được mang discount_value_x100: %s", snapJSON)
	}
}
