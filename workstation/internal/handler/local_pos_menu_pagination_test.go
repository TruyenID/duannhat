package handler

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// pos-web walks EVERY page of this endpoint to build its section pill row, so
// "the page boundary is somewhere sensible" is no longer good enough: the walk
// is only sound if the ORDER BY is a TOTAL order. Neither display_order nor the
// product name is unique — a real branch menu has 104 of its 127 rows on
// display_order 0 — and LIMIT/OFFSET over a partial order is free to return one
// row on two pages and another on none. That lands on the cashier's screen as
// dishes that are simply not there.
//
// Rows are inserted in DESCENDING id order, all sharing display_order and
// product name, so "whatever the query plan yields" and "the contract" cannot
// look alike by accident.
func TestHandleLocalPosMenuProducts_PaginationCoversEveryRowExactlyOnce(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db)

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','Lunch','published')`)
	ids := []string{"mp5", "mp4", "mp3", "mp2", "mp1"}
	for i, id := range ids {
		p := fmt.Sprintf("p%d", i)
		mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES (?, 'Same Name')`, p)
		mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES (?, ?, 'Bowl', 1000)`,
			fmt.Sprintf("s%d", i), p)
		// Every row on display_order 0 — the shape real menus actually have.
		mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES (?, 'm1', ?, 0)`, id, p)
	}

	seen := []string{}
	for page := 1; page <= 3; page++ {
		req := httptest.NewRequest("GET", fmt.Sprintf("/api/v1/pos/menus/m1/products?page=%d&per_page=2", page), nil)
		req.SetPathValue("menu", "m1")
		w := httptest.NewRecorder()
		srv.handleLocalPosMenuProducts(w, req)

		var body struct {
			Data []struct {
				ID string `json:"id"`
			} `json:"data"`
		}
		if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
			t.Fatalf("page %d: %v — %s", page, err, w.Body.String())
		}
		for _, row := range body.Data {
			seen = append(seen, row.ID)
		}
	}

	want := []string{"mp1", "mp2", "mp3", "mp4", "mp5"}
	if len(seen) != len(want) {
		t.Fatalf("walking every page returned %d rows, want %d: %v", len(seen), len(want), seen)
	}
	for i, id := range want {
		if seen[i] != id {
			t.Fatalf("page walk = %v, want %v (a repeated or dropped row means the sort is not total)", seen, want)
		}
	}
}
