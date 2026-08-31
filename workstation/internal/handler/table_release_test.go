package handler

import (
	"testing"
)

func seedOrder(t *testing.T, db execer, id, tableID, status string) {
	t.Helper()
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, table_id, opened_at, created_at, updated_at)
		VALUES (?, ?, 'DineIn', ?, ?, datetime('now'), datetime('now'), datetime('now'))`,
		id, "ORD-"+id, status, tableID); err != nil {
		t.Fatalf("seed order %s: %v", id, err)
	}
}

// A voided/deleted order's table must be freed locally AND enqueued for Cloud
// (the table.status op), so it never stays stuck `occupied` when the
// order-lifecycle sync is 409-drained without releasing the table.
func TestReleaseTablesToFree_FreesAndEnqueues(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")
	srv.hub = NewHub()
	mustExec(t, db, `INSERT INTO tables (id, name, status) VALUES ('t1','A-01','occupied')`)
	seedOrder(t, db, "o1", "t1", "voided")

	srv.releaseTablesToFree([]string{"t1"}, "o1")

	var st string
	if err := db.QueryRow("SELECT status FROM tables WHERE id='t1'").Scan(&st); err != nil {
		t.Fatal(err)
	}
	if st != "free" {
		t.Fatalf("table want free, got %q", st)
	}
	var n int
	_ = db.QueryRow(`SELECT COUNT(*) FROM sync_queue
		WHERE entity_type='table' AND operation='status' AND entity_id='t1'`).Scan(&n)
	if n != 1 {
		t.Fatalf("want 1 table.status op enqueued, got %d", n)
	}
}

// A merged table still held by another live order must NOT be freed.
func TestReleaseTablesToFree_KeepsTableWithAnotherActiveOrder(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")
	srv.hub = NewHub()
	mustExec(t, db, `INSERT INTO tables (id, name, status) VALUES ('t1','A-01','occupied')`)
	seedOrder(t, db, "o1", "t1", "voided") // the one being released
	seedOrder(t, db, "o2", "t1", "open")   // another live order on the same table

	srv.releaseTablesToFree([]string{"t1"}, "o1")

	var st string
	_ = db.QueryRow("SELECT status FROM tables WHERE id='t1'").Scan(&st)
	if st != "occupied" {
		t.Fatalf("table must stay occupied (o2 still live), got %q", st)
	}
}

// The startup reconciler frees ONLY tables stuck occupied with a terminal local
// order and no active one — never a legitimately busy table, and never a table
// occupied without any local order (another device's order).
func TestReconcileStuckTables_FreesOnlyStuck(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")
	srv.hub = NewHub()

	// stuck: occupied + a voided local order, no active order → free it.
	mustExec(t, db, `INSERT INTO tables (id, name, status) VALUES ('stuck','A-02','occupied')`)
	seedOrder(t, db, "vo", "stuck", "voided")
	// busy: occupied + an active local order → leave it.
	mustExec(t, db, `INSERT INTO tables (id, name, status) VALUES ('busy','A-03','occupied')`)
	seedOrder(t, db, "ao", "busy", "open")
	// other-device: occupied but NO local order → must NOT be touched.
	mustExec(t, db, `INSERT INTO tables (id, name, status) VALUES ('other','A-04','occupied')`)

	srv.reconcileStuckTables()

	get := func(id string) string {
		var s string
		_ = db.QueryRow("SELECT status FROM tables WHERE id=?", id).Scan(&s)
		return s
	}
	if get("stuck") != "free" {
		t.Errorf("stuck table must be freed, got %q", get("stuck"))
	}
	if get("busy") != "occupied" {
		t.Errorf("busy table must stay occupied, got %q", get("busy"))
	}
	if get("other") != "occupied" {
		t.Errorf("other-device table must be left alone, got %q", get("other"))
	}
}
