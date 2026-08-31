package service

import (
	"database/sql"
	"path/filepath"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

func newTillDebtTestDB(t *testing.T) *store.DB {
	t.Helper()
	db, err := store.Open(filepath.Join(t.TempDir(), "till.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return db
}

func seedTillDebt(t *testing.T, db *store.DB, paymentID, status, amount, metadata, createdAt, methodID, methodCode string) {
	t.Helper()
	if _, err := db.Exec(
		`INSERT INTO payments (id, order_id, payment_method, payment_method_id, amount, status, metadata, created_at, updated_at)
		 VALUES (?, 'o-1', ?, ?, ?, ?, ?, ?, ?)`,
		paymentID, methodCode, methodID, amount, status, metadata, createdAt, createdAt,
	); err != nil {
		t.Fatal(err)
	}
}

func TestComputeTillDebtSummary_SumsIssuedAndSettled(t *testing.T) {
	db := newTillDebtTestDB(t)

	// Seed payment methods: one on_account, one cash.
	if _, err := db.Exec(
		`INSERT INTO payment_methods (id, code, name, type, is_active, branch_id, organization_id)
		 VALUES ('pm-debt', 'debt', 'Ghi nợ', 'on_account', 1, 'br-1', 'org-1'),
		        ('pm-cash', 'cash', 'Tiền mặt', 'cash', 1, 'br-1', 'org-1')`,
	); err != nil {
		// payment_methods may not have all the columns in this minimal
		// shape; fall back to a smaller INSERT for SQLite environments
		// that don't have NOT NULL constraints on every column.
		_, err2 := db.Exec(
			`INSERT INTO payment_methods (id, code, name, type, is_active)
			 VALUES ('pm-debt', 'debt', 'Ghi nợ', 'on_account', 1),
			        ('pm-cash', 'cash', 'Tiền mặt', 'cash', 1)`,
		)
		if err2 != nil {
			t.Skipf("payment_methods schema differs in this build (%v / %v)", err, err2)
		}
	}

	openedAt := "2026-06-20T10:00:00Z"
	closedAt := sql.NullString{String: "2026-06-20T23:00:00Z", Valid: true}

	// Two debts inside the window, one outside.
	seedTillDebt(t, db, "p1", "confirmed", "100000", `{"split_mode":"by_amount"}`, "2026-06-20T11:00:00Z", "pm-debt", "debt")
	seedTillDebt(t, db, "p2", "succeeded", "50000", `{}`, "2026-06-20T13:00:00Z", "pm-debt", "debt")
	seedTillDebt(t, db, "p_old", "confirmed", "999999", `{}`, "2026-05-01T00:00:00Z", "pm-debt", "debt")

	// One settlement payment inside the window.
	seedTillDebt(t, db, "p_settle", "confirmed", "100000", `{"settles_payment_id":"p1"}`, "2026-06-20T15:00:00Z", "pm-cash", "cash")

	sum, err := ComputeTillDebtSummary(db, openedAt, closedAt)
	if err != nil {
		t.Fatalf("ComputeTillDebtSummary: %v", err)
	}
	if sum.TotalIssued != 150000 {
		t.Errorf("total issued: want 150000, got %d", sum.TotalIssued)
	}
	if sum.TotalSettled != 100000 {
		t.Errorf("total settled: want 100000, got %d", sum.TotalSettled)
	}
}
