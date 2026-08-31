package service

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// TestPullMenu_SyncsTaxTypesAndPerItemFields (#1099 single-rate) — the
// workstation menu pull upserts the brand tax_types[] (one `rate` each) and
// stamps per-item tax_type_id on menu_items.
func TestPullMenu_SyncsTaxTypesAndPerItemFields(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{
			"menu_id":"m1","menu_name":"Menu",
			"tax_types":[
				{"id":"tt-red","code":"軽減","name":"Reduced","rate":8,"is_default":false,"is_active":true},
				{"id":"tt-std","code":"標準","name":"Standard","rate":10,"is_default":true,"is_active":true}
			],
			"categories":[
				{"id":"sec1","name":"Drinks","items":[
					{"id":"mp1","sku_id":"sku-1","name":"Bento","price":1000,"status":"available","tax_type_id":"tt-red"},
					{"id":"mp2","sku_id":"sku-2","name":"Beer","price":500,"status":"available","tax_type_id":"tt-std"}
				]}
			]
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullMenu(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	// tax_types mirrored.
	var cnt, defaultCnt int
	db.QueryRow(`SELECT COUNT(*) FROM tax_types`).Scan(&cnt)
	if cnt != 2 {
		t.Errorf("tax_types count = %d, want 2", cnt)
	}
	db.QueryRow(`SELECT COUNT(*) FROM tax_types WHERE is_default = 1 AND id = 'tt-std'`).Scan(&defaultCnt)
	if defaultCnt != 1 {
		t.Errorf("brand default = std expected, got %d rows", defaultCnt)
	}
	var rate float64
	db.QueryRow(`SELECT rate FROM tax_types WHERE id = 'tt-red'`).Scan(&rate)
	if rate != 8 {
		t.Errorf("reduced rate = %g, want 8", rate)
	}

	// per-item tax_type_id stamped on menu_items.
	var taxTypeID string
	db.QueryRow(`SELECT COALESCE(tax_type_id,'') FROM menu_items WHERE id = 'mp1'`).Scan(&taxTypeID)
	if taxTypeID != "tt-red" {
		t.Errorf("mp1 tax_type_id=%q, want tt-red", taxTypeID)
	}
	db.QueryRow(`SELECT COALESCE(tax_type_id,'') FROM menu_items WHERE id = 'mp2'`).Scan(&taxTypeID)
	if taxTypeID != "tt-std" {
		t.Errorf("mp2 tax_type_id=%q, want tt-std", taxTypeID)
	}
}

// #1128 — shim `rate_takeaway` ĐÃ GỠ. Ca thay thế cho
// TestPullMenu_TransitionOldCloudRatePair: một payload có tax type nhưng THIẾU
// `rate` giờ phải làm cả lượt upsert HỎNG, chứ không âm thầm ghi 0%.
//
// Vì sao ca này tồn tại thay vì chỉ xoá ca cũ: `0` là thuế suất HỢP LỆ (非課税),
// nên nếu `Rate` thôi là con trỏ thì "thiếu trường" và "0%" không phân biệt
// được, và bảng thuế sẽ bị ghi đè bằng 0% trông y như hợp lệ. Ca này ghim rằng
// đường đó đóng.
func TestPullMenu_MissingRateFailsLoudly(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{
			"menu_id":"m1","menu_name":"Menu",
			"tax_types":[
				{"id":"tt-x","code":"標準","name":"Standard","is_default":true,"is_active":true}
			],
			"categories":[]
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	err := p.PullMenu(context.Background())
	if err == nil {
		t.Fatal("payload thiếu `rate` phải làm pull HỎNG — im lặng ghi 0% là lỗi #1128 sinh ra để chặn")
	}
	if !strings.Contains(err.Error(), "refusing to assume 0%") {
		t.Fatalf("thông điệp lỗi không nói vì sao: %v", err)
	}

	// Và KHÔNG được ghi hàng nào — giữ bản đúng lần trước hơn là ghi số sai.
	var cnt int
	db.QueryRow(`SELECT COUNT(*) FROM tax_types`).Scan(&cnt)
	if cnt != 0 {
		t.Errorf("tax_types count = %d, want 0 (lượt upsert phải bị huỷ)", cnt)
	}
}

// 非課税 = 0%% là HỢP LỆ và phải ghi được. Không có ca này thì một bản sửa
// "chặn rate 0" sẽ qua được ca trên mà làm hỏng loại thuế 0%.
func TestPullMenu_ZeroRateIsLegal(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{
			"menu_id":"m1","menu_name":"Menu",
			"tax_types":[
				{"id":"tt-zero","code":"非課税","name":"Exempt","rate":0,"is_default":false,"is_active":true}
			],
			"categories":[]
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullMenu(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var rate float64
	if err := db.QueryRow(`SELECT rate FROM tax_types WHERE id = 'tt-zero'`).Scan(&rate); err != nil {
		t.Fatalf("loại thuế 0%% phải được ghi: %v", err)
	}
	if rate != 0 {
		t.Errorf("rate = %g, want 0", rate)
	}
}

// TestPullMenu_OldCloudNoTaxKeys — a menu payload from an OLD cloud (no
// tax_types, no per-item tax fields) must not panic, and must leave the item
// with a null tax_type_id. Zero-value safety (§11.1).
func TestPullMenu_OldCloudNoTaxKeys(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{
			"menu_id":"m1","menu_name":"Menu",
			"categories":[
				{"id":"sec1","name":"Drinks","items":[
					{"id":"mp1","sku_id":"sku-1","name":"Bento","price":1000,"status":"available"}
				]}
			]
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullMenu(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	var taxTypeID *string
	db.QueryRow(`SELECT tax_type_id FROM menu_items WHERE id = 'mp1'`).Scan(&taxTypeID)
	if taxTypeID != nil {
		t.Errorf("old-cloud item tax_type_id = %v, want NULL", *taxTypeID)
	}
	// No tax_types synced.
	var cnt int
	db.QueryRow(`SELECT COUNT(*) FROM tax_types`).Scan(&cnt)
	if cnt != 0 {
		t.Errorf("tax_types count = %d, want 0 (old cloud sent none)", cnt)
	}
}

// TestUpsertOrder_DecodesPerLineTax (plan-043 T3.3) — a cloud order payload's
// per-line tax snapshot + is_tax_included decode into the local mirror.
func TestUpsertOrder_DecodesPerLineTax(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://unused", staticTokenFn("WS-TOKEN"))

	included := true
	rate := json.Number("8.00")
	order := cloudOrderPayload{
		ID: "ord-tax", OrderCode: "ORD-1", OrderType: "takeaway", Status: "open",
		OpenedAt: "2026-05-26T10:00:00Z", UpdatedAt: "2026-05-26T10:00:00Z",
		BranchID: "branch-A", BrandID: "brand-A", OrgID: "org-A",
		Subtotal: "1000", TaxAmount: "80", TotalAmount: "1080",
		IsTaxIncluded: &included,
		Items: []cloudOrderItemPayload{{
			ID: "it-1", ProductSkuID: "sku-1", MenuItemName: "Bento",
			Quantity: "1", UnitPrice: "1000", Subtotal: "1000", Status: "pending",
			UpdatedAt: "2026-05-26T10:00:00Z",
			TaxTypeID: "tt-red",
			TaxRate:   &rate,
			TaxAmount: "80",
		}},
	}
	if err := p.upsertOrder(order, false); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}

	var isTaxIncluded int
	db.QueryRow(`SELECT COALESCE(is_tax_included,0) FROM orders WHERE id = 'ord-tax'`).Scan(&isTaxIncluded)
	if isTaxIncluded != 1 {
		t.Errorf("order is_tax_included = %d, want 1", isTaxIncluded)
	}

	var taxTypeID string
	var taxRate float64
	var taxAmount int
	db.QueryRow(`SELECT COALESCE(tax_type_id,''), COALESCE(tax_rate,-1), COALESCE(tax_amount,0)
		FROM order_items WHERE id = 'it-1'`).Scan(&taxTypeID, &taxRate, &taxAmount)
	if taxTypeID != "tt-red" || taxRate != 8 || taxAmount != 80 {
		t.Errorf("item tax snapshot = %q/%g/%d, want tt-red/8/80", taxTypeID, taxRate, taxAmount)
	}
}

// TestUpsertOrder_OldCloudNoTaxKeys — a cloud order without tax fields leaves
// the mirror's tax columns at NULL / default (no panic).
func TestUpsertOrder_OldCloudNoTaxKeys(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://unused", staticTokenFn("WS-TOKEN"))

	order := cloudOrderPayload{
		ID: "ord-old", OrderCode: "ORD-2", OrderType: "spot", Status: "open",
		OpenedAt: "2026-05-26T10:00:00Z", UpdatedAt: "2026-05-26T10:00:00Z",
		BranchID: "branch-A", BrandID: "brand-A", OrgID: "org-A",
		Subtotal: "1000", TotalAmount: "1000",
		Items: []cloudOrderItemPayload{{
			ID: "it-old", ProductSkuID: "sku-1", MenuItemName: "Bento",
			Quantity: "1", UnitPrice: "1000", Subtotal: "1000", Status: "pending",
			UpdatedAt: "2026-05-26T10:00:00Z",
		}},
	}
	if err := p.upsertOrder(order, false); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}
	var taxRate *float64
	db.QueryRow(`SELECT tax_rate FROM order_items WHERE id = 'it-old'`).Scan(&taxRate)
	if taxRate != nil {
		t.Errorf("old-cloud item tax_rate = %v, want NULL", *taxRate)
	}
}

// TestOrderUnmarshal_DecodesStringTaxFields — the Recover path unmarshals cloud
// JSON directly into Order/Item. Cloud serialises tax_rate/tax_amount as decimal
// STRINGS; the custom UnmarshalJSON must decode them (a naive alias decode would
// fail string→int/*float64). is_tax_included (bool) decodes via the alias.
func TestOrderUnmarshal_DecodesStringTaxFields(t *testing.T) {
	raw := []byte(`{
		"id":"o1","order_code":"ORD","order_type":"takeaway","status":"open",
		"is_tax_included":true,
		"subtotal":"1000.00","tax_amount":"80.00","total_amount":"1080.00",
		"items":[{
			"id":"i1","product_sku_id":"sku-1","quantity":"1","unit_price":"1000.00","subtotal":"1000.00",
			"status":"pending","tax_type_id":"tt-red","tax_rate":"8.00","tax_amount":"80.00"
		}]
	}`)
	var o Order
	if err := json.Unmarshal(raw, &o); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	if !o.IsTaxIncluded {
		t.Error("is_tax_included should decode true")
	}
	if len(o.Items) != 1 {
		t.Fatalf("want 1 item, got %d", len(o.Items))
	}
	it := o.Items[0]
	if it.TaxTypeID != "tt-red" {
		t.Errorf("tax_type_id = %q, want tt-red", it.TaxTypeID)
	}
	if it.TaxRate == nil || *it.TaxRate != 8 {
		t.Errorf("tax_rate = %v, want 8", it.TaxRate)
	}
	if it.TaxAmount != 80 {
		t.Errorf("tax_amount = %g, want 80", it.TaxAmount)
	}
}
