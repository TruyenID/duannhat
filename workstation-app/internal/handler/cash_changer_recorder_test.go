package handler

import (
	"context"
	"path/filepath"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
)

func newRecorderServer(t *testing.T) *Server {
	t.Helper()
	db, err := store.Open(filepath.Join(t.TempDir(), "rec.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return &Server{db: db}
}

func TestRecordCashPayment_InsertsRowAndClosesOrder(t *testing.T) {
	s := newRecorderServer(t)
	if _, err := s.db.Exec(
		`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 8650)`,
	); err != nil {
		t.Fatal(err)
	}

	pid, err := s.RecordCashPayment(context.Background(), service.CashPayment{
		OrderID: "o1", Amount: 8650, Tendered: 10000, Change: 1350,
		GloryTransactionID: "T1", ServerID: "srv-9",
	})
	if err != nil {
		t.Fatalf("RecordCashPayment: %v", err)
	}
	if pid == "" {
		t.Fatal("want a non-empty payment id")
	}

	// The durable cash payment row landed with the right money + metadata.
	var (
		method, status, idem, meta, ref, target string
		amount, tendered, change                int
	)
	err = s.db.QueryRow(`
		SELECT payment_method, amount, tendered_amount, change_amount, status,
		       idempotency_key, COALESCE(metadata,''), COALESCE(reference_no,''),
		       COALESCE(sync_target,'')
		FROM payments WHERE id = ?`, pid,
	).Scan(&method, &amount, &tendered, &change, &status, &idem, &meta, &ref, &target)
	if err != nil {
		t.Fatalf("read payment row: %v", err)
	}
	if method != "cash" || amount != 8650 || tendered != 10000 || change != 1350 {
		t.Errorf("row money = method %s amount %d tendered %d change %d, want cash/8650/10000/1350",
			method, amount, tendered, change)
	}
	if status != "confirmed" {
		t.Errorf("status = %q, want confirmed (auto-confirm cash)", status)
	}
	if idem != "glory:T1" || ref != "T1" {
		t.Errorf("idempotency=%q reference=%q, want glory:T1 / T1", idem, ref)
	}
	if target != "workstation" {
		t.Errorf("sync_target = %q, want workstation", target)
	}
	if !strings.Contains(meta, "cash_changer") || !strings.Contains(meta, "T1") {
		t.Errorf("metadata = %q, want it to carry capture_source cash_changer + glory txn T1", meta)
	}

	// Auto-confirm cash fully paid → order closed + paid_amount stamped.
	var oStatus string
	var paid int
	if err := s.db.QueryRow(`SELECT status, paid_amount FROM orders WHERE id='o1'`).Scan(&oStatus, &paid); err != nil {
		t.Fatal(err)
	}
	if oStatus != "closed" || paid != 8650 {
		t.Errorf("order = status %s paid %d, want closed/8650", oStatus, paid)
	}
}

func TestRecordCashPayment_IdempotentOnGloryTxn(t *testing.T) {
	s := newRecorderServer(t)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 1000)`)

	first, err := s.RecordCashPayment(context.Background(), service.CashPayment{
		OrderID: "o1", Amount: 1000, Tendered: 1000, GloryTransactionID: "TXDUP",
	})
	if err != nil {
		t.Fatalf("first: %v", err)
	}
	second, err := s.RecordCashPayment(context.Background(), service.CashPayment{
		OrderID: "o1", Amount: 1000, Tendered: 1000, GloryTransactionID: "TXDUP",
	})
	if err != nil {
		t.Fatalf("second: %v", err)
	}
	if first != second {
		t.Errorf("replay returned %s, want the same id %s", second, first)
	}

	var count int
	s.db.QueryRow(`SELECT COUNT(*) FROM payments WHERE order_id='o1'`).Scan(&count)
	if count != 1 {
		t.Errorf("payment rows = %d, want 1 (idempotent on glory txn)", count)
	}
}

func TestRecordCashPayment_OrderNotFound(t *testing.T) {
	s := newRecorderServer(t)
	_, err := s.RecordCashPayment(context.Background(), service.CashPayment{
		OrderID: "missing", Amount: 1000, GloryTransactionID: "T1",
	})
	if err == nil || !strings.Contains(err.Error(), "not found") {
		t.Fatalf("err = %v, want order-not-found", err)
	}
}
