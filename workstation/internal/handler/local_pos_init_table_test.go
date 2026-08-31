package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Regression for the "tôi gán bàn rồi nhưng nó vẫn không hiển thị bàn"
// report. Full HTTP round-trip: create a floating dine_in order, PUT
// /init with table_ids[], and assert the JSON response's `data.tables[]`
// contains the bound table with the label pos-web's order-cart reads
// (`tbl.name ?? tbl.code`). Pre-fix loadOrderTables queried a missing
// `t.code` column and silently returned [] — UI stayed on "Gán bàn".
func TestHandleLocalPosInitOrder_ResponseExposesBoundTables(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	if srv.orders == nil {
		srv.orders = service.NewOrderEngine(srv.db)
	}

	mustExec(t, srv.db, `INSERT INTO tables (id, name, status) VALUES
		('tbl-x','X9','available'),
		('tbl-y','Y2','available')`)

	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "dine_in"}, nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}

	body, _ := json.Marshal(map[string]any{
		"table_ids": []string{"tbl-x", "tbl-y"},
	})
	req := httptest.NewRequest("PUT", "/api/v1/pos/orders/"+o.ID+"/init", bytes.NewReader(body))
	req.SetPathValue("id", o.ID)
	w := httptest.NewRecorder()
	srv.handleLocalPosInitOrder(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("init want 200, got %d body=%s", w.Code, w.Body.String())
	}

	var resp struct {
		Data struct {
			TableID *string `json:"table_id"`
			Tables  []struct {
				ID     string `json:"id"`
				Code   string `json:"code"`
				Name   string `json:"name"`
				Status string `json:"status"`
			} `json:"tables"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode response: %v body=%s", err, w.Body.String())
	}

	if resp.Data.TableID == nil || *resp.Data.TableID != "tbl-x" {
		t.Errorf("data.table_id want tbl-x, got %v", resp.Data.TableID)
	}
	if len(resp.Data.Tables) != 2 {
		t.Fatalf("data.tables want 2 rows, got %d body=%s", len(resp.Data.Tables), w.Body.String())
	}
	// Cover the two pos-web cart-header read paths.
	if resp.Data.Tables[0].ID != "tbl-x" {
		t.Errorf("tables[0].id want tbl-x, got %q", resp.Data.Tables[0].ID)
	}
	if resp.Data.Tables[0].Name != "X9" || resp.Data.Tables[0].Code != "X9" {
		t.Errorf("tables[0] code/name want X9/X9, got %q/%q",
			resp.Data.Tables[0].Code, resp.Data.Tables[0].Name)
	}
	if resp.Data.Tables[1].ID != "tbl-y" || resp.Data.Tables[1].Name != "Y2" {
		t.Errorf("tables[1] want tbl-y/Y2, got %q/%q",
			resp.Data.Tables[1].ID, resp.Data.Tables[1].Name)
	}
}

// After /init, a GET /orders/{id} fetch must ALSO include the tables[]
// — pos-web's useOrderFieldSave decides init vs update on subsequent
// edits by reading order.tables.length. If the detail endpoint drops
// tables[] the UI will mistakenly call /init again instead of
// /merge-table.
func TestHandleLocalPosGetOrder_AfterInit_IncludesTables(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	if srv.orders == nil {
		srv.orders = service.NewOrderEngine(srv.db)
	}
	mustExec(t, srv.db, `INSERT INTO tables (id, name, status) VALUES ('tbl-q','Q5','available')`)

	o, _ := srv.orders.Create(service.CreateOrderInput{OrderType: "dine_in"}, nil)
	if _, err := srv.orders.InitOrder(o.ID, service.InitOrderInput{
		TableIDs: []string{"tbl-q"},
	}); err != nil {
		t.Fatalf("init: %v", err)
	}

	req := httptest.NewRequest("GET", "/api/v1/pos/orders/"+o.ID, nil)
	req.SetPathValue("id", o.ID)
	w := httptest.NewRecorder()
	srv.handleLocalPosGetOrder(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("GET want 200, got %d body=%s", w.Code, w.Body.String())
	}
	var resp struct {
		Data struct {
			Tables []map[string]any `json:"tables"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if len(resp.Data.Tables) != 1 {
		t.Errorf("GET data.tables want 1 row, got %d body=%s", len(resp.Data.Tables), w.Body.String())
	}
}
