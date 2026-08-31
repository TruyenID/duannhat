package store

import (
	"testing"
)

// #2656 — migration 083 converts the cumulative `refunded_amount` column into
// signed refund rows on the shop's existing data.
//
// The conversion SQL is executed from the SAME embedded file the migration
// runner uses, against rows seeded in the OLD shape, so this measures the bytes
// that will run on a shop machine rather than a re-typed copy of them. Every
// statement in that file is guarded on `refunded_amount > 0`, which is also what
// makes running it a second time here a no-op for anything already converted.
func applyMigration083(t *testing.T, db *DB) {
	t.Helper()
	sqlBytes, err := localMigrationsFS.ReadFile("migrations/083_payments_signed_refund_rows.sql")
	if err != nil {
		t.Fatalf("read migration 083: %v", err)
	}
	if _, err := db.Conn().Exec(string(sqlBytes)); err != nil {
		t.Fatalf("apply migration 083: %v", err)
	}
}

// oldShapeNet is the money query a workstation binary from BEFORE #2656 runs.
// It has never heard of `refund_of_id`, so it is the exact reason the conversion
// may not leave a fully-refunded original in `status='refunded'`.
func oldShapeNet(t *testing.T, db *DB) int {
	t.Helper()
	var net int
	if err := db.QueryRow(`
		SELECT COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) FROM payments
		WHERE status IN ('pending','confirmed','succeeded')`).Scan(&net); err != nil {
		t.Fatalf("old-shape net: %v", err)
	}
	return net
}

func TestMigration083_ConvertsRefundedAmountIntoSignedRows(t *testing.T) {
	db := openTestDB(t)

	// Old shape: a partially refunded sale with TWO history lines, and a fully
	// refunded sale flipped to `status='refunded'`.
	mustExecStore(t, db, `INSERT INTO payments
		(id, order_id, payment_method, payment_method_id, amount, refunded_amount, refunded_at,
		 status, till_session_id, created_at, updated_at)
		VALUES
		 ('p1','o1','cash','pm-cash',13490,2980,NULL,'succeeded','sess-1','2026-08-12T02:00:00Z','2026-08-12T02:00:00Z'),
		 ('p2','o2','cash','pm-cash',5000,5000,'2026-08-12T05:00:00Z','refunded','sess-1','2026-08-12T03:00:00Z','2026-08-12T05:00:00Z'),
		 ('p3','o3','cash','pm-cash',20000,0,NULL,'succeeded','sess-1','2026-08-12T04:00:00Z','2026-08-12T04:00:00Z')`)
	mustExecStore(t, db, `INSERT INTO payment_refunds (id, payment_id, amount, note, refunded_at)
		VALUES
		 ('r1a','p1',1980,'khach tra lai mon','2026-08-12T02:30:00Z'),
		 ('r1b','p1',1000,'giam gia bu','2026-08-12T02:45:00Z'),
		 ('r2','p2',5000,NULL,'2026-08-12T05:00:00Z')`)

	before := oldShapeNet(t, db) // 13490-2980 + 20000 = 30510 ('refunded' p2 is dropped)
	if before != 30510 {
		t.Fatalf("pre-conversion net = %d, want 30510 — the fixture is not the old shape", before)
	}

	applyMigration083(t, db)

	// 1. Each history line became its own signed row, keeping its own time+note.
	type row struct {
		id, note, status, method, session, createdAt string
		amount                                       int
	}
	var got []row
	rows, err := db.Query(`
		SELECT id, COALESCE(note,''), status, payment_method, COALESCE(till_session_id,''),
		       created_at, amount
		FROM payments WHERE refund_of_id = 'p1' ORDER BY created_at`)
	if err != nil {
		t.Fatal(err)
	}
	for rows.Next() {
		var r row
		if err := rows.Scan(&r.id, &r.note, &r.status, &r.method, &r.session, &r.createdAt, &r.amount); err != nil {
			t.Fatal(err)
		}
		got = append(got, r)
	}
	rows.Close()
	if len(got) != 2 {
		t.Fatalf("p1 refund rows = %d, want 2 — two partial refunds are two entries: %+v", len(got), got)
	}
	if got[0].id != "r1a" || got[0].amount != -1980 || got[0].note != "khach tra lai mon" ||
		got[0].createdAt != "2026-08-12T02:30:00Z" {
		t.Errorf("first refund row = %+v, want r1a/-1980 at 02:30 with its own note", got[0])
	}
	if got[1].id != "r1b" || got[1].amount != -1000 || got[1].createdAt != "2026-08-12T02:45:00Z" {
		t.Errorf("second refund row = %+v, want r1b/-1000 at 02:45", got[1])
	}
	for _, r := range got {
		if r.status != "succeeded" || r.method != "cash" || r.session != "sess-1" {
			t.Errorf("refund row %+v must carry succeeded + the original's tender + shift", r)
		}
	}

	// 2. The originals are retired cleanly: column zeroed (no double subtraction),
	//    marker cleared, and the fully-refunded one back in a COUNTED status.
	for _, id := range []string{"p1", "p2"} {
		var status, refundedAt string
		var refunded int
		if err := db.QueryRow(
			`SELECT status, refunded_amount, COALESCE(refunded_at,'') FROM payments WHERE id = ?`, id,
		).Scan(&status, &refunded, &refundedAt); err != nil {
			t.Fatal(err)
		}
		if refunded != 0 || refundedAt != "" {
			t.Errorf("%s left with refunded_amount=%d refunded_at=%q — every reader would subtract twice", id, refunded, refundedAt)
		}
		if status != "succeeded" {
			t.Errorf("%s status = %q, want succeeded", id, status)
		}
	}

	// 3. THE forward-compat property: a binary rolled back past this release,
	//    reading this converted data with a query that has never heard of
	//    refund_of_id, still gets the same money.
	if after := oldShapeNet(t, db); after != before {
		t.Errorf("old-binary net after conversion = %d, want %d. Leaving the original at 'refunded' "+
			"drops the sale and keeps the negative row — the drawer goes negative on a rolled-back build", after, before)
	}

	// 4. New-shape readers agree with it.
	var newNet int
	if err := db.QueryRow(`
		SELECT COALESCE(SUM(amount - COALESCE(refunded_amount,0)), 0) FROM payments
		WHERE ((refund_of_id IS NULL AND status IN ('pending','confirmed','succeeded','refunded'))
		    OR (refund_of_id IS NOT NULL AND status = 'succeeded'))`).Scan(&newNet); err != nil {
		t.Fatal(err)
	}
	if newNet != before {
		t.Errorf("new-shape net = %d, want %d", newNet, before)
	}
}

// A refunded figure with no surviving history line must not evaporate: the
// conversion emits one residual row so the ledger still sums to the same money.
func TestMigration083_RefundedAmountWithoutHistoryKeepsItsMoney(t *testing.T) {
	db := openTestDB(t)
	mustExecStore(t, db, `INSERT INTO payments
		(id, order_id, payment_method, amount, refunded_amount, status, created_at, updated_at)
		VALUES ('p9','o9','cash',8000,3000,'succeeded','2026-08-12T02:00:00Z','2026-08-12T06:00:00Z')`)

	before := oldShapeNet(t, db)
	applyMigration083(t, db)

	var n, sum int
	if err := db.QueryRow(
		`SELECT COUNT(*), COALESCE(SUM(amount),0) FROM payments WHERE refund_of_id = 'p9'`).Scan(&n, &sum); err != nil {
		t.Fatal(err)
	}
	if n != 1 || sum != -3000 {
		t.Errorf("residual conversion = %d row(s) summing %d, want 1 row of -3000", n, sum)
	}
	if after := oldShapeNet(t, db); after != before {
		t.Errorf("net moved from %d to %d — the conversion lost or invented money", before, after)
	}
}

// Rows already in the new shape (a Cloud-written pair, or a second run of the
// migration) are left exactly as they are.
func TestMigration083_LeavesAlreadySignedRowsAlone(t *testing.T) {
	db := openTestDB(t)
	mustExecStore(t, db, `INSERT INTO payments
		(id, order_id, payment_method, amount, refunded_amount, status, refund_of_id, created_at, updated_at)
		VALUES ('c1','o1','cash',5000,0,'refunded',NULL,'2026-08-12T02:00:00Z','2026-08-12T02:00:00Z'),
		       ('c1r','o1','cash',-5000,0,'succeeded','c1','2026-08-12T03:00:00Z','2026-08-12T03:00:00Z')`)

	applyMigration083(t, db)

	var n int
	if err := db.QueryRow(`SELECT COUNT(*) FROM payments`).Scan(&n); err != nil {
		t.Fatal(err)
	}
	if n != 2 {
		t.Errorf("payments rows = %d, want 2 — the conversion must not touch rows it did not write", n)
	}
	var status string
	if err := db.QueryRow(`SELECT status FROM payments WHERE id = 'c1'`).Scan(&status); err != nil {
		t.Fatal(err)
	}
	if status != "refunded" {
		t.Errorf("Cloud-written original status = %q, want it untouched at 'refunded'", status)
	}
}

func mustExecStore(t *testing.T, db *DB, q string, args ...any) {
	t.Helper()
	if _, err := db.Conn().Exec(q, args...); err != nil {
		t.Fatalf("exec %q: %v", q, err)
	}
}
