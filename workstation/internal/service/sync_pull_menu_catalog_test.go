package service

import (
	"context"
	"database/sql"
	"net/http"
	"net/http/httptest"
	"testing"
)

// PullMenuCatalog upserts the new images + product_type_code + gallery
// rows. Without this the LAN handler would emit them as empty regardless
// of what the Cloud feed sends.
func TestPullMenuCatalog_PersistsImagesAndGallery(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{
			"menus":[{"id":"m1","name":"Lunch","status":"published","sort_order":0,"description":""}],
			"sections":[],
			"menu_products":[
				{"id":"mp1","menu_id":"m1","product_id":"p1","menu_section_id":null,"is_active":true,"display_order":1}
			],
			"products":[
				{"id":"p1","name":"Combo A","description":"","is_active":true,
				 "product_type_code":"combo","image_url":"https://cdn/p1.jpg"}
			],
			"product_galleries":[
				{"id":"g1","product_id":"p1","url":"https://cdn/p1-a.jpg","original_name":"a.jpg","mime_type":"image/jpeg","sort_order":1},
				{"id":"g2","product_id":"p1","url":"https://cdn/p1-b.jpg","original_name":null,"mime_type":null,"sort_order":2}
			],
			"skus":[
				{"id":"s1","product_id":"p1","name":"Bowl","sku":"PHO-1","selling_price":1000,"is_active":true,"image_url":"https://cdn/s1.jpg"}
			]
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	if err := p.PullMenuCatalog(context.Background()); err != nil {
		t.Fatalf("PullMenuCatalog: %v", err)
	}

	var typeCode, imageURL sql.NullString
	db.QueryRow(`SELECT product_type_code, image_url FROM pos_products WHERE id = 'p1'`).Scan(&typeCode, &imageURL)
	if !typeCode.Valid || typeCode.String != "combo" {
		t.Errorf("product_type_code want 'combo', got %q (valid=%v)", typeCode.String, typeCode.Valid)
	}
	if !imageURL.Valid || imageURL.String != "https://cdn/p1.jpg" {
		t.Errorf("product image_url want set, got %q (valid=%v)", imageURL.String, imageURL.Valid)
	}

	var skuImg sql.NullString
	db.QueryRow(`SELECT image_url FROM pos_product_skus WHERE id = 's1'`).Scan(&skuImg)
	if !skuImg.Valid || skuImg.String != "https://cdn/s1.jpg" {
		t.Errorf("sku image_url want set, got %q (valid=%v)", skuImg.String, skuImg.Valid)
	}

	// Gallery: two rows, ordered by sort_order.
	rows, err := db.Query(`SELECT id, url, sort_order FROM pos_product_galleries WHERE product_id = 'p1' ORDER BY sort_order`)
	if err != nil {
		t.Fatalf("query gallery: %v", err)
	}
	defer rows.Close()
	type row struct {
		id, url string
		sort    sql.NullInt64
	}
	got := []row{}
	for rows.Next() {
		var r row
		if err := rows.Scan(&r.id, &r.url, &r.sort); err != nil {
			t.Fatal(err)
		}
		got = append(got, r)
	}
	if len(got) != 2 {
		t.Fatalf("expected 2 gallery rows, got %d", len(got))
	}
	if got[0].id != "g1" || got[1].id != "g2" {
		t.Errorf("gallery rows out of order: %+v", got)
	}
}

// Subsequent pulls must replace gallery rows (not duplicate) — Cloud
// deletes a file, workstation should see it gone next tick.
func TestPullMenuCatalog_GalleryReplaceOnRePull(t *testing.T) {
	feeds := []string{
		// First feed: two gallery rows.
		`{"data":{
			"menus":[{"id":"m1","name":"L","status":"published","sort_order":0,"description":""}],
			"sections":[], "menu_products":[],
			"products":[{"id":"p1","name":"P","description":"","is_active":true,"product_type_code":null,"image_url":null}],
			"product_galleries":[
				{"id":"g1","product_id":"p1","url":"u1","sort_order":1,"original_name":null,"mime_type":null},
				{"id":"g2","product_id":"p1","url":"u2","sort_order":2,"original_name":null,"mime_type":null}
			],
			"skus":[]
		}}`,
		// Second feed: one row gone.
		`{"data":{
			"menus":[{"id":"m1","name":"L","status":"published","sort_order":0,"description":""}],
			"sections":[], "menu_products":[],
			"products":[{"id":"p1","name":"P","description":"","is_active":true,"product_type_code":null,"image_url":null}],
			"product_galleries":[
				{"id":"g1","product_id":"p1","url":"u1","sort_order":1,"original_name":null,"mime_type":null}
			],
			"skus":[]
		}}`,
	}
	feedIdx := 0
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(feeds[feedIdx]))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	if err := p.PullMenuCatalog(context.Background()); err != nil {
		t.Fatalf("first pull: %v", err)
	}
	var cnt int
	db.QueryRow(`SELECT COUNT(*) FROM pos_product_galleries WHERE product_id = 'p1'`).Scan(&cnt)
	if cnt != 2 {
		t.Errorf("after first pull want 2 gallery rows, got %d", cnt)
	}

	feedIdx = 1
	if err := p.PullMenuCatalog(context.Background()); err != nil {
		t.Fatalf("second pull: %v", err)
	}
	db.QueryRow(`SELECT COUNT(*) FROM pos_product_galleries WHERE product_id = 'p1'`).Scan(&cnt)
	if cnt != 1 {
		t.Errorf("after Cloud-side delete + re-pull want 1 row, got %d", cnt)
	}
}
