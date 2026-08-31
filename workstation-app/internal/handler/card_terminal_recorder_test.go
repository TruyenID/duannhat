package handler

import (
	"context"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

func TestRecordCardPayment_InsertsRowAndClosesOrder(t *testing.T) {
	s := newRecorderServer(t)
	if _, err := s.db.Exec(
		`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 3000)`,
	); err != nil {
		t.Fatal(err)
	}

	pid, err := s.RecordCardPayment(context.Background(), service.CardPayment{
		OrderID: "o1", Amount: 3000, Service: service.ServiceCredit,
		TerminalTxnID: "SLIP-9", TerminalResponse: `{"ApprovalCode":"OK"}`,
	})
	if err != nil {
		t.Fatalf("RecordCardPayment: %v", err)
	}
	if pid == "" {
		t.Fatal("want a payment id")
	}

	var (
		method, status, idem, meta, ref, target, tresp string
		amount                                         int
	)
	err = s.db.QueryRow(`
		SELECT payment_method, amount, status, idempotency_key, COALESCE(metadata,''),
		       COALESCE(reference_no,''), COALESCE(sync_target,''), COALESCE(terminal_response,'')
		FROM payments WHERE id = ?`, pid,
	).Scan(&method, &amount, &status, &idem, &meta, &ref, &target, &tresp)
	if err != nil {
		t.Fatalf("read payment: %v", err)
	}
	if method != "card" || amount != 3000 {
		t.Errorf("row = method %s amount %d, want card/3000", method, amount)
	}
	if status != "confirmed" {
		t.Errorf("status = %q, want confirmed (terminal approved)", status)
	}
	if idem != "terminal:SLIP-9" || ref != "SLIP-9" || target != "workstation" {
		t.Errorf("idem=%q ref=%q target=%q, want terminal:SLIP-9 / SLIP-9 / workstation", idem, ref, target)
	}
	if !strings.Contains(meta, "p400_vesca") || !strings.Contains(tresp, "ApprovalCode") {
		t.Errorf("meta=%q terminal_response=%q, want p400_vesca + terminal response kept", meta, tresp)
	}

	var oStatus string
	var paid int
	s.db.QueryRow(`SELECT status, paid_amount FROM orders WHERE id='o1'`).Scan(&oStatus, &paid)
	if oStatus != "closed" || paid != 3000 {
		t.Errorf("order = %s paid %d, want closed/3000", oStatus, paid)
	}
}

func TestRecordCardPayment_Idempotent(t *testing.T) {
	s := newRecorderServer(t)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 1000)`)

	first, _ := s.RecordCardPayment(context.Background(), service.CardPayment{OrderID: "o1", Amount: 1000, TerminalTxnID: "T-DUP"})
	second, err := s.RecordCardPayment(context.Background(), service.CardPayment{OrderID: "o1", Amount: 1000, TerminalTxnID: "T-DUP"})
	if err != nil {
		t.Fatalf("second: %v", err)
	}
	if first != second {
		t.Errorf("replay id %s != first %s", second, first)
	}
	var count int
	s.db.QueryRow(`SELECT COUNT(*) FROM payments WHERE order_id='o1'`).Scan(&count)
	if count != 1 {
		t.Errorf("rows = %d, want 1 (idempotent on terminal txn)", count)
	}
}

func TestRecordCardPayment_OrderNotFound(t *testing.T) {
	s := newRecorderServer(t)
	_, err := s.RecordCardPayment(context.Background(), service.CardPayment{OrderID: "missing", Amount: 1000, TerminalTxnID: "T1"})
	if err == nil || !strings.Contains(err.Error(), "not found") {
		t.Fatalf("err = %v, want order-not-found", err)
	}
}
