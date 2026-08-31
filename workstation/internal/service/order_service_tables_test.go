package service

import (
	"database/sql"
	"errors"
	"testing"
)

// seedTablesOrder returns an open dine_in order with no table bindings.
// The two tables ("t1", "t2") are inserted into the local `tables`
// snapshot so MergeTable's `tables.status='occupied'` UPDATE has rows
// to touch — the assertion below uses that stamp as a witness.
func seedTablesOrder(t *testing.T, eng *OrderEngine) string {
	t.Helper()
	if _, err := eng.db.Exec(`
		INSERT OR IGNORE INTO tables (id, name, status) VALUES
		  ('t1', 'T1', 'free'),
		  ('t2', 'T2', 'free'),
		  ('t3', 'T3', 'free')
	`); err != nil {
		t.Fatal(err)
	}
	o, err := eng.Create(CreateOrderInput{OrderType: "dine_in"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	return o.ID
}

func TestMergeTable_HappyPath_SetsPrimaryAndOccupied(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID := seedTablesOrder(t, eng)

	got, err := eng.MergeTable(orderID, "t1")
	if err != nil {
		t.Fatalf("merge: %v", err)
	}
	if got.TableID != "t1" {
		t.Errorf("orders.table_id want t1, got %q", got.TableID)
	}
	var status string
	db.QueryRow(`SELECT status FROM tables WHERE id='t1'`).Scan(&status)
	if status != "occupied" {
		t.Errorf("tables.status want occupied, got %q", status)
	}
	var pivotCount int
	db.QueryRow(`SELECT COUNT(*) FROM order_tables WHERE order_id=? AND table_id='t1'`, orderID).Scan(&pivotCount)
	if pivotCount != 1 {
		t.Errorf("pivot row count want 1, got %d", pivotCount)
	}
}

func TestMergeTable_Idempotent(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID := seedTablesOrder(t, eng)
	if _, err := eng.MergeTable(orderID, "t1"); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.MergeTable(orderID, "t1"); err != nil {
		t.Errorf("second merge same table must be no-op: %v", err)
	}
	var n int
	db.QueryRow(`SELECT COUNT(*) FROM order_tables WHERE order_id=?`, orderID).Scan(&n)
	if n != 1 {
		t.Errorf("pivot must have 1 row, got %d", n)
	}
}

func TestMergeTable_RejectsNonOpen(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID := seedTablesOrder(t, eng)
	db.Exec(`UPDATE orders SET status='closed' WHERE id=?`, orderID)
	_, err := eng.MergeTable(orderID, "t1")
	if !errors.Is(err, ErrOrderNotOpen) {
		t.Errorf("want ErrOrderNotOpen, got %v", err)
	}
}

func TestMergeTable_RejectsTableHeldByAnotherOpenOrder(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	orderA := seedTablesOrder(t, eng)
	if _, err := eng.MergeTable(orderA, "t1"); err != nil {
		t.Fatal(err)
	}
	// Different order tries to grab t1 → ErrTableOccupied.
	orderB, err := eng.Create(CreateOrderInput{OrderType: "dine_in"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	_, err = eng.MergeTable(orderB.ID, "t1")
	if !errors.Is(err, ErrTableOccupied) {
		t.Errorf("want ErrTableOccupied, got %v", err)
	}
}

func TestMergeTable_AllowsReuseAfterPriorOrderClosed(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderA := seedTablesOrder(t, eng)
	if _, err := eng.MergeTable(orderA, "t1"); err != nil {
		t.Fatal(err)
	}
	db.Exec(`UPDATE orders SET status='closed' WHERE id=?`, orderA)

	orderB, _ := eng.Create(CreateOrderInput{OrderType: "dine_in"}, nil)
	if _, err := eng.MergeTable(orderB.ID, "t1"); err != nil {
		t.Errorf("closed parent must not block reuse, got %v", err)
	}
}

func TestUnmergeTable_RejectsLastOnDineIn(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	orderID := seedTablesOrder(t, eng)
	if _, err := eng.MergeTable(orderID, "t1"); err != nil {
		t.Fatal(err)
	}
	_, err := eng.UnmergeTable(orderID, "t1")
	if !errors.Is(err, ErrCannotUnmergeLastDineIn) {
		t.Errorf("want ErrCannotUnmergeLastDineIn, got %v", err)
	}
}

func TestUnmergeTable_HappyPathRePicksPrimary(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID := seedTablesOrder(t, eng)
	if _, err := eng.MergeTable(orderID, "t1"); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.MergeTable(orderID, "t2"); err != nil {
		t.Fatal(err)
	}
	got, err := eng.UnmergeTable(orderID, "t1")
	if err != nil {
		t.Fatalf("unmerge: %v", err)
	}
	if got.TableID != "t2" {
		t.Errorf("primary must re-pick to t2, got %q", got.TableID)
	}
	var status string
	db.QueryRow(`SELECT status FROM tables WHERE id='t1'`).Scan(&status)
	if status != "free" {
		t.Errorf("freed t1.status want free, got %q", status)
	}
}

func TestUnmergeTable_UnknownBindingReturnsNoRows(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	orderID := seedTablesOrder(t, eng)
	_, err := eng.UnmergeTable(orderID, "ghost")
	if !errors.Is(err, sql.ErrNoRows) {
		t.Errorf("want sql.ErrNoRows, got %v", err)
	}
}

func TestUnmergeTable_AllowsLastOnNonDineIn(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	// "spot" is the non-takeaway, non-dine_in default — Create gives
	// it status=open immediately. Backend's last-binding gate only
	// fires for dine_in, so spot orders may release every table.
	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	eng.db.Exec(`INSERT INTO tables (id, name, status) VALUES ('t9','T9','free')`)
	if _, err := eng.MergeTable(o.ID, "t9"); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.UnmergeTable(o.ID, "t9"); err != nil {
		t.Errorf("last-table unmerge must be allowed on non-dine_in, got %v", err)
	}
}

func TestInitOrder_FirstWriteWins_TableIDs(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID := seedTablesOrder(t, eng)

	// First init binds [t1, t2]; promotes t1 as primary.
	if _, err := eng.InitOrder(orderID, InitOrderInput{TableIDs: []string{"t1", "t2"}}); err != nil {
		t.Fatalf("init #1: %v", err)
	}
	// Second init with [t3] must be ignored because pivot is non-empty.
	got, err := eng.InitOrder(orderID, InitOrderInput{TableIDs: []string{"t3"}})
	if err != nil {
		t.Fatalf("init #2: %v", err)
	}
	if got.TableID != "t1" {
		t.Errorf("first-write-wins broken: primary now %q, want t1", got.TableID)
	}
	var n int
	db.QueryRow(`SELECT COUNT(*) FROM order_tables WHERE order_id=? AND table_id='t3'`, orderID).Scan(&n)
	if n != 0 {
		t.Errorf("t3 must not be bound by second init, got %d rows", n)
	}
}

func TestInitOrder_SetsGuestCount(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	orderID := seedTablesOrder(t, eng)
	four := 4
	got, err := eng.InitOrder(orderID, InitOrderInput{GuestCount: &four})
	if err != nil {
		t.Fatalf("init: %v", err)
	}
	if got.GuestCount == nil || *got.GuestCount != 4 {
		t.Errorf("guest_count want 4, got %v", got.GuestCount)
	}
}

func TestInitOrder_RejectsNonOpen(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID := seedTablesOrder(t, eng)
	db.Exec(`UPDATE orders SET status='paying' WHERE id=?`, orderID)
	_, err := eng.InitOrder(orderID, InitOrderInput{TableIDs: []string{"t1"}})
	if !errors.Is(err, ErrOrderNotOpen) {
		t.Errorf("want ErrOrderNotOpen, got %v", err)
	}
}

func TestInitOrder_RejectsOccupiedTable(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	orderA := seedTablesOrder(t, eng)
	if _, err := eng.MergeTable(orderA, "t1"); err != nil {
		t.Fatal(err)
	}
	orderB, _ := eng.Create(CreateOrderInput{OrderType: "dine_in"}, nil)
	_, err := eng.InitOrder(orderB.ID, InitOrderInput{TableIDs: []string{"t1"}})
	if !errors.Is(err, ErrTableOccupied) {
		t.Errorf("want ErrTableOccupied, got %v", err)
	}
}

func TestUpdateMeta_PersistsCustomerID(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID := seedTablesOrder(t, eng)
	cid := "cust-99"
	if _, err := eng.UpdateMeta(orderID, OrderUpdateInput{CustomerID: &cid}); err != nil {
		t.Fatalf("UpdateMeta: %v", err)
	}
	var got sql.NullString
	db.QueryRow(`SELECT customer_id FROM orders WHERE id=?`, orderID).Scan(&got)
	if !got.Valid || got.String != "cust-99" {
		t.Errorf("customer_id want cust-99, got %v", got)
	}
}

func TestUpdateMeta_RejectsNonOpen(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	orderID := seedTablesOrder(t, eng)
	db.Exec(`UPDATE orders SET status='paying' WHERE id=?`, orderID)
	five := 5
	_, err := eng.UpdateMeta(orderID, OrderUpdateInput{GuestCount: &five})
	if !errors.Is(err, ErrOrderNotOpen) {
		t.Errorf("want ErrOrderNotOpen, got %v", err)
	}
}
