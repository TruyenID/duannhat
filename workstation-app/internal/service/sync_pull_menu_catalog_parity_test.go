package service

import (
	"context"
	"database/sql"
	"net/http"
	"net/http/httptest"
	"testing"
)

// PullMenuCatalog must persist the plan-022 fields end-to-end:
// is_price_overridden + default_price on skus, the option_value FKs,
// product_options + values, topping_groups + items + item_skus, the
// product↔group pivot, and both override tables.
func TestPullMenuCatalog_PersistsAllParityArrays(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{
			"menus":[{"id":"m1","name":"L","status":"published","sort_order":0,"description":""}],
			"sections":[],
			"menu_products":[
				{"id":"mp1","menu_id":"m1","product_id":"p1","menu_section_id":null,"is_active":true,"display_order":1}
			],
			"products":[
				{"id":"p1","name":"Pho","description":"","is_active":true,"product_type_code":null,"image_url":null},
				{"id":"top1","name":"Cheese","description":"","is_active":true,"product_type_code":null,"image_url":null}
			],
			"product_galleries":[],
			"skus":[
				{"id":"sk1","product_id":"p1","name":"L","sku":"X","selling_price":900,"default_price":1000,"is_price_overridden":true,
				 "is_active":true,"image_url":null,
				 "option_value1_id":"ov1","option_value2_id":null,"option_value3_id":null}
			],
			"product_options":[
				{"id":"opt1","product_id":"p1","key":"size","name":"Size","position":0,"is_active":true}
			],
			"product_option_values":[
				{"id":"ov1","option_id":"opt1","value":"L","label":"Large","position":0,"is_active":true}
			],
			"topping_groups":[
				{"id":"tg1","name":"Toppings","selection_type":"multiple","modifier_type":"add","price_strategy":"flat",
				 "free_quantity":null,"min_select":0,"max_select":5,"max_qty_per_item":2,"sort_order":0,"is_active":true}
			],
			"topping_group_items":[
				{"id":"it1","topping_group_id":"tg1","product_id":"top1","sort_order":0,"is_default":false}
			],
			"topping_group_item_skus":[
				{"id":"isk1","topping_group_item_id":"it1","product_sku_id":null,"extra_price":100}
			],
			"product_topping_groups":[
				{"product_id":"p1","topping_group_id":"tg1","sort_order":0,
				 "min_select_override":1,"max_select_override":3}
			],
			"product_topping_item_overrides":[
				{"id":"pov1","product_id":"p1","topping_group_item_id":"it1","product_sku_id":null,
				 "override_price":150,"is_hidden":false}
			],
			"menu_product_topping_overrides":[
				{"id":"mov1","menu_product_id":"mp1","topping_group_id":"tg1","topping_group_item_id":"it1",
				 "product_sku_id":null,"is_hidden":false,"override_price":80}
			]
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	if err := p.PullMenuCatalog(context.Background()); err != nil {
		t.Fatalf("PullMenuCatalog: %v", err)
	}

	// SKU plan-022 columns.
	var defaultPrice sql.NullInt64
	var overridden int
	var ov1 sql.NullString
	db.QueryRow(`SELECT default_price, is_price_overridden, option_value1_id FROM pos_product_skus WHERE id = 'sk1'`).
		Scan(&defaultPrice, &overridden, &ov1)
	if !defaultPrice.Valid || defaultPrice.Int64 != 1000 {
		t.Errorf("default_price want 1000, got %v (valid=%v)", defaultPrice.Int64, defaultPrice.Valid)
	}
	if overridden != 1 {
		t.Errorf("is_price_overridden want 1, got %d", overridden)
	}
	if !ov1.Valid || ov1.String != "ov1" {
		t.Errorf("option_value1_id want 'ov1', got %q (valid=%v)", ov1.String, ov1.Valid)
	}

	// product_options + values landed.
	var optName string
	db.QueryRow(`SELECT name FROM pos_product_options WHERE id = 'opt1'`).Scan(&optName)
	if optName != "Size" {
		t.Errorf("option name want 'Size', got %q", optName)
	}
	var valLabel sql.NullString
	db.QueryRow(`SELECT label FROM pos_product_option_values WHERE id = 'ov1'`).Scan(&valLabel)
	if !valLabel.Valid || valLabel.String != "Large" {
		t.Errorf("value label want 'Large', got %q", valLabel.String)
	}

	// Topping group + item + item_sku.
	var gName string
	db.QueryRow(`SELECT name FROM pos_topping_groups WHERE id = 'tg1'`).Scan(&gName)
	if gName != "Toppings" {
		t.Errorf("topping group name want 'Toppings', got %q", gName)
	}
	var itemSort int
	db.QueryRow(`SELECT sort_order FROM pos_topping_group_items WHERE id = 'it1'`).Scan(&itemSort)
	if itemSort != 0 {
		t.Errorf("item sort_order want 0, got %d", itemSort)
	}
	var extra int
	db.QueryRow(`SELECT extra_price FROM pos_topping_group_item_skus WHERE id = 'isk1'`).Scan(&extra)
	if extra != 100 {
		t.Errorf("item sku extra_price want 100, got %d", extra)
	}

	// Pivot + tier overrides.
	var minOv, maxOv sql.NullInt64
	db.QueryRow(`SELECT min_select_override, max_select_override FROM pos_product_topping_groups WHERE product_id = 'p1' AND topping_group_id = 'tg1'`).
		Scan(&minOv, &maxOv)
	if !minOv.Valid || minOv.Int64 != 1 {
		t.Errorf("pivot min override want 1, got %v", minOv.Int64)
	}
	if !maxOv.Valid || maxOv.Int64 != 3 {
		t.Errorf("pivot max override want 3, got %v", maxOv.Int64)
	}
	var t2Price sql.NullInt64
	db.QueryRow(`SELECT override_price FROM pos_product_topping_item_overrides WHERE id = 'pov1'`).Scan(&t2Price)
	if !t2Price.Valid || t2Price.Int64 != 150 {
		t.Errorf("tier-2 override want 150, got %v", t2Price.Int64)
	}
	var t1Price sql.NullInt64
	db.QueryRow(`SELECT override_price FROM pos_menu_product_topping_overrides WHERE id = 'mov1'`).Scan(&t1Price)
	if !t1Price.Valid || t1Price.Int64 != 80 {
		t.Errorf("tier-1 override want 80, got %v", t1Price.Int64)
	}
}
