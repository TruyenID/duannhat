package handler

import (
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Regression: pre-fix loadOrderTables queried `t.code` which doesn't
// exist in the local `tables` replica schema. The query silently
// errored and returned an empty slice, so `order.tables[]` was always
// empty in the customerOrderShape response — pos-web's order-cart
// rendered "Chưa có bàn" even after a successful MergeTable /
// InitOrder. The shape MUST surface the bound table label so the
// "Bàn: T1" header lights up.
func TestLoadOrderTables_ReturnsBoundTableAfterMerge(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	if srv.orders == nil {
		srv.orders = service.NewOrderEngine(srv.db, 0)
	}

	mustExec(t, srv.db, `INSERT INTO tables (id, name, status) VALUES ('tbl-1','T1','available')`)

	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "dine_in"}, nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if _, err := srv.orders.MergeTable(o.ID, "tbl-1"); err != nil {
		t.Fatalf("merge: %v", err)
	}

	tables := srv.loadOrderTables(o.ID)
	if len(tables) != 1 {
		t.Fatalf("loadOrderTables: want 1 row after merge, got %d", len(tables))
	}
	if got := tables[0]["id"]; got != "tbl-1" {
		t.Errorf("table id: want tbl-1, got %v", got)
	}
	// pos-web's order-cart renders `tbl.name ?? tbl.code`. Both must be
	// populated so the "Bàn: T1" header doesn't display "—".
	if got := tables[0]["code"]; got != "T1" {
		t.Errorf("table code: want T1, got %v", got)
	}
	if got := tables[0]["name"]; got != "T1" {
		t.Errorf("table name: want T1, got %v", got)
	}
}

// shapeOrderForResponse must surface tables[] in the JSON payload —
// pos-web reads `order.tables` to decide if a /init vs /update call
// should fire on the next field save (useOrderFieldSave).
func TestShapeOrderForResponse_IncludesTablesAfterInit(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	if srv.orders == nil {
		srv.orders = service.NewOrderEngine(srv.db, 0)
	}

	mustExec(t, srv.db, `INSERT INTO tables (id, name, status) VALUES
		('tbl-a','A1','available'),
		('tbl-b','B2','available')`)

	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "dine_in"}, nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if _, err := srv.orders.InitOrder(o.ID, service.InitOrderInput{
		TableIDs: []string{"tbl-a", "tbl-b"},
	}); err != nil {
		t.Fatalf("init: %v", err)
	}

	o, _ = srv.orders.GetByID(o.ID)
	shape := srv.customerOrderShape(o, "")
	tables, ok := shape["tables"].([]map[string]any)
	if !ok {
		t.Fatalf("shape.tables wrong type: %T", shape["tables"])
	}
	if len(tables) != 2 {
		t.Fatalf("shape.tables: want 2 rows, got %d", len(tables))
	}
	// Sort-order preserved: tbl-a (sort_order=0) must come first.
	if tables[0]["id"] != "tbl-a" {
		t.Errorf("first table: want tbl-a, got %v", tables[0]["id"])
	}
	if tables[1]["id"] != "tbl-b" {
		t.Errorf("second table: want tbl-b, got %v", tables[1]["id"])
	}
}
