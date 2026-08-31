package handler

import (
	"context"
	"encoding/json"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/audit"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func newRecorderServer(t *testing.T) *Server {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "rec.db"))
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
	if status != "succeeded" {
		t.Errorf("status = %q, want succeeded (auto-confirm cash, #1120)", status)
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

func TestRecordCashPayment_MergesSplitAuditWithMachineProvenance(t *testing.T) {
	s := newRecorderServer(t)
	if _, err := s.db.Exec(
		`INSERT INTO orders (id, status, total_amount) VALUES ('o-split-meta', 'checkout', 1600)`,
	); err != nil {
		t.Fatal(err)
	}

	pid, err := s.RecordCashPayment(context.Background(), service.CashPayment{
		OrderID: "o-split-meta", Amount: 1600, Tendered: 2000, Change: 400,
		GloryTransactionID: "T-split-meta", ServerID: "srv-real",
		PaymentMetadata: `{"split_mode":"by_items","bill_index":1,"total_bills":2,` +
			`"label":"Guest 2","item_allocations":[{"item_id":"line-7","units":2}]}`,
	})
	if err != nil {
		t.Fatalf("RecordCashPayment: %v", err)
	}

	var raw string
	if err := s.db.QueryRow(`SELECT metadata FROM payments WHERE id = ?`, pid).Scan(&raw); err != nil {
		t.Fatal(err)
	}
	var metadata map[string]any
	if err := json.Unmarshal([]byte(raw), &metadata); err != nil {
		t.Fatalf("metadata is not JSON: %v — %q", err, raw)
	}
	if metadata["split_mode"] != "by_items" || metadata["bill_index"] != float64(1) || metadata["total_bills"] != float64(2) {
		t.Fatalf("split audit context lost: %#v", metadata)
	}
	if metadata["capture_source"] != "cash_changer" ||
		metadata["glory_transaction_id"] != "T-split-meta" ||
		metadata["server_id"] != "srv-real" {
		t.Fatalf("machine provenance changed or missing: %#v", metadata)
	}
}

func TestRecordCashPayment_NonSplitHasNoInventedSplitMode(t *testing.T) {
	s := newRecorderServer(t)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o-normal-meta', 'checkout', 1000)`)

	pid, err := s.RecordCashPayment(context.Background(), service.CashPayment{
		OrderID: "o-normal-meta", Amount: 1000, Tendered: 1000,
		GloryTransactionID: "T-normal-meta", ServerID: "srv-1",
	})
	if err != nil {
		t.Fatal(err)
	}

	var raw string
	if err := s.db.QueryRow(`SELECT metadata FROM payments WHERE id = ?`, pid).Scan(&raw); err != nil {
		t.Fatal(err)
	}
	var metadata map[string]any
	if err := json.Unmarshal([]byte(raw), &metadata); err != nil {
		t.Fatal(err)
	}
	if _, exists := metadata["split_mode"]; exists {
		t.Fatalf("normal cash payment invented split_mode: %#v", metadata)
	}
}

func TestRecordCashPayment_CorruptAuditMetadataCannotLoseCollectedCash(t *testing.T) {
	for _, corrupt := range []string{`null`, `["not-an-object"]`, `{broken`} {
		t.Run(corrupt, func(t *testing.T) {
			s := newRecorderServer(t)
			s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o-corrupt-meta', 'checkout', 1000)`)

			pid, err := s.RecordCashPayment(context.Background(), service.CashPayment{
				OrderID: "o-corrupt-meta", Amount: 1000, Tendered: 1000,
				GloryTransactionID: "T-corrupt-meta", ServerID: "srv-safe",
				PaymentMetadata: corrupt,
			})
			if err != nil || pid == "" {
				t.Fatalf("cash was collected; corrupt audit context must not lose payment: id=%q err=%v", pid, err)
			}

			var raw string
			if err := s.db.QueryRow(`SELECT metadata FROM payments WHERE id = ?`, pid).Scan(&raw); err != nil {
				t.Fatal(err)
			}
			var metadata map[string]any
			if err := json.Unmarshal([]byte(raw), &metadata); err != nil {
				t.Fatal(err)
			}
			if metadata["capture_source"] != "cash_changer" || metadata["server_id"] != "srv-safe" {
				t.Fatalf("fallback lost machine provenance: %#v", metadata)
			}
		})
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

// A pending payment on the same order must not count towards orders.paid_amount
// nor towards the close decision.
//
// #555 M13 fixed exactly this on the POS path, and its comment names the damage:
// "wrote pending rows into paid_amount (a later /fail never reverses it) and
// could close an order on in-flight terminal money". The peripheral recorders
// kept summing ACTIVE money, which includes `pending`, so the same bug survived
// here — reachable whenever a split bill has one leg on a card terminal awaiting
// confirmation while the other is settled at the cash changer.
func TestRecordCashPayment_IgnoresPendingMoney(t *testing.T) {
	s := newRecorderServer(t)
	if _, err := s.db.Exec(
		`INSERT INTO orders (id, status, total_amount) VALUES ('o-split', 'checkout', 10000)`,
	); err != nil {
		t.Fatal(err)
	}
	// Leg 1: a terminal authorisation still in flight. Not captured money.
	if _, err := s.db.Exec(`
		INSERT INTO payments (id, order_id, payment_method, amount, status, created_at, updated_at)
		VALUES ('p-pending', 'o-split', 'card', 6000, 'pending', datetime('now'), datetime('now'))`,
	); err != nil {
		t.Fatal(err)
	}

	// Leg 2: 4000 in cash. Captured total is therefore 4000, not 10000.
	if _, err := s.RecordCashPayment(context.Background(), service.CashPayment{
		OrderID: "o-split", Amount: 4000, Tendered: 4000, Change: 0,
		GloryTransactionID: "T-split", ServerID: "srv-1",
	}); err != nil {
		t.Fatalf("RecordCashPayment: %v", err)
	}

	var paidAmount int
	var status string
	if err := s.db.QueryRow(
		`SELECT COALESCE(paid_amount, 0), status FROM orders WHERE id = 'o-split'`,
	).Scan(&paidAmount, &status); err != nil {
		t.Fatal(err)
	}

	if paidAmount != 4000 {
		t.Fatalf("paid_amount = %d, want 4000 (the pending 6000 must not be counted)", paidAmount)
	}
	// 4000 < 10000, so the order stays open. Closing here would settle a bill on
	// money that can still fail.
	if status == "closed" {
		t.Fatal("order closed on captured 4000 of 10000 — a pending leg was counted")
	}
}

// Cash taken through the changer must leave an audit row.
//
// Every payment that arrives via an HTTP handler is audited (auditLogPOS →
// payment.create). Peripheral-driven payments were not, so cash — the surface
// where an audit trail matters most, because there is no card network record to
// fall back on — left nothing behind at all.
func TestRecordCashPayment_WritesAuditRow(t *testing.T) {
	s := newRecorderServer(t)
	s.audit = audit.NewLogger(s.db)

	if _, err := s.db.Exec(
		`INSERT INTO orders (id, status, total_amount) VALUES ('o-audit', 'checkout', 5000)`,
	); err != nil {
		t.Fatal(err)
	}

	pid, err := s.RecordCashPayment(context.Background(), service.CashPayment{
		OrderID: "o-audit", Amount: 5000, Tendered: 5000, Change: 0,
		GloryTransactionID: "T-audit", ServerID: "srv-7",
	})
	if err != nil {
		t.Fatalf("RecordCashPayment: %v", err)
	}

	var actor, action, entityID, details string
	if err := s.db.QueryRow(
		`SELECT actor, action, entity_id, COALESCE(details,'') FROM audit_log WHERE entity_id = ?`, pid,
	).Scan(&actor, &action, &entityID, &details); err != nil {
		t.Fatalf("no audit row for the payment: %v", err)
	}

	if action != "payment.create" {
		t.Fatalf("action = %q, want payment.create", action)
	}
	// The actor identifies the peripheral, not "unknown" — a cash row whose
	// origin cannot be named is barely an audit row at all.
	if actor != "cash_changer:srv-7" {
		t.Fatalf("actor = %q, want cash_changer:srv-7", actor)
	}
	if !strings.Contains(details, "5000") {
		t.Fatalf("details = %q, want the amount in it", details)
	}
}

// A PARTIAL payment must broadcast order_updated, not silence.
//
// pos-web treats the two events oppositely: order_paid drops the order from the
// open list, order_updated keeps it and rewrites the cached balance. The
// recorders emitted only the former, and only on close, so a partial collection
// at the changer left the cashier looking at the old remaining amount — with no
// polling behind it, because the open-orders query only polls when the
// workstation is UNREACHABLE.
func TestRecordCashPayment_BroadcastsOrderUpdatedOnPartial(t *testing.T) {
	s := newRecorderServer(t)
	s.hub = NewHub()
	s.orders = service.NewOrderEngine(s.db)

	if _, err := s.db.Exec(
		`INSERT INTO orders (id, status, total_amount) VALUES ('o-partial', 'checkout', 10000)`,
	); err != nil {
		t.Fatal(err)
	}

	// Register a client straight into the hub — the test is in this package, so
	// no production test-seam is needed. Broadcasts land in its send buffer.
	client := &Client{hub: s.hub, send: make(chan []byte, 8)}
	s.hub.mu.Lock()
	s.hub.clients[client] = true
	s.hub.mu.Unlock()

	if _, err := s.RecordCashPayment(context.Background(), service.CashPayment{
		OrderID: "o-partial", Amount: 4000, Tendered: 4000, Change: 0,
		GloryTransactionID: "T-partial", ServerID: "srv-2",
	}); err != nil {
		t.Fatalf("RecordCashPayment: %v", err)
	}

	var got []string
	for len(client.send) > 0 {
		var envelope struct {
			Type string `json:"type"`
		}
		if err := json.Unmarshal(<-client.send, &envelope); err != nil {
			t.Fatalf("broadcast payload is not JSON: %v", err)
		}
		got = append(got, envelope.Type)
	}
	if !slices.Contains(got, "order_updated") {
		t.Fatalf("events = %v, want order_updated — pos-web keeps a stale balance without it", got)
	}
	if slices.Contains(got, "order_paid") {
		t.Fatalf("events = %v — order_paid would drop the tab from the open list on a PARTIAL payment", got)
	}
}

// A payment that fails to enqueue must leave an AUDIT row, not just a line in
// the process log.
//
// The POS path already does this. Money recorded here and never at Cloud is the
// same silent divergence as a void that never syncs (#1254) — and the process
// log has no reader, while the audit log has a dashboard behind it.
func TestRecordCashPayment_AuditsFailedSyncEnqueue(t *testing.T) {
	s := newRecorderServer(t)
	s.audit = audit.NewLogger(s.db)
	// A sync engine whose queue table is gone: Enqueue fails, the payment is
	// still taken. Exactly the fail-open the audit row exists to make visible.
	s.sync = service.NewSyncEngine(s.db, "", nil)
	if _, err := s.db.Exec(`DROP TABLE sync_queue`); err != nil {
		t.Fatal(err)
	}

	if _, err := s.db.Exec(
		`INSERT INTO orders (id, status, total_amount) VALUES ('o-enq', 'checkout', 2000)`,
	); err != nil {
		t.Fatal(err)
	}

	pid, err := s.RecordCashPayment(context.Background(), service.CashPayment{
		OrderID: "o-enq", Amount: 2000, Tendered: 2000, Change: 0,
		GloryTransactionID: "T-enq", ServerID: "srv-3",
	})
	if err != nil {
		t.Fatalf("RecordCashPayment must not fail because sync did: %v", err)
	}

	var n int
	if err := s.db.QueryRow(
		`SELECT COUNT(*) FROM audit_log WHERE action = 'payment.sync_enqueue_failed' AND entity_id = ?`, pid,
	).Scan(&n); err != nil {
		t.Fatal(err)
	}
	if n != 1 {
		t.Fatalf("audit rows for the failed enqueue = %d, want 1 — the payment never reaches Cloud and nothing says so", n)
	}
}

// #2535 B6 — rào vượt-thu của recorder, và nó KHÔNG có test cho tới #2577 vòng 2.
//
// Review bắt đúng chỗ đau: xoá cả khối guard trong `RecordCashPayment` thì
// `go test ./internal/handler/ -count=1` vẫn xanh. Đây là guard TIỀN duy nhất
// nằm ở recorder, nên ai "đơn giản hoá" nó về `insertPayment` thẳng sẽ không
// làm đỏ gì cả — và đường sync bên Cloud khi đó **cắt** số tiền xuống
// `outstanding` rồi chỉ ghi một dòng `slog.Error`. Kết cục: tiền mặt nằm trong
// ngăn kéo máy mà không sổ nào ghi nhận.
//
// Ba khẳng định, vì fail-closed ở đây phải đúng cả ba mới có nghĩa:
// lỗi trả về (để `CashChangerService` biến nó thành alert), KHÔNG có dòng
// payment (không ghi nhận nửa vời), và đơn KHÔNG đóng (không chốt bill trên
// một khoản chưa từng được nhận).
func TestRecordCashPayment_RefusesOvercollection(t *testing.T) {
	s := newRecorderServer(t)
	if _, err := s.db.Exec(
		`INSERT INTO orders (id, status, total_amount) VALUES ('o-over', 'checkout', 5000)`,
	); err != nil {
		t.Fatal(err)
	}
	// Đã thu 4000 tiền mặt — captured THẬT. Trạng thái phải là `succeeded`:
	// `sumCapturedPaymentsForOrder` chỉ đếm `('succeeded','confirmed')`, nên
	// seed `'captured'` (một chuỗi không thuộc từ vựng này) sẽ khiến bài test
	// đọc lên như đang canh guard trong khi guard không hề thấy tiền — bản đầu
	// của chính bài này mắc đúng lỗi đó và đỏ vì lý do sai.
	if _, err := s.db.Exec(`
		INSERT INTO payments (id, order_id, payment_method, amount, status, created_at, updated_at)
		VALUES ('p-cash-1', 'o-over', 'cash', 4000, 'succeeded', datetime('now'), datetime('now'))`,
	); err != nil {
		t.Fatal(err)
	}

	// Máy đòi thêm 2000 ⇒ 4000+2000 = 6000 > 5000. Phải bị chặn.
	pid, err := s.RecordCashPayment(context.Background(), service.CashPayment{
		OrderID: "o-over", Amount: 2000, Tendered: 2000, Change: 0,
		GloryTransactionID: "T-over", ServerID: "srv-over",
	})
	if err == nil {
		t.Fatalf("RecordCashPayment nhận 2000 trên đơn 5000 đã thu 4000 — phải LỖI, nhận payment id %q", pid)
	}
	if !strings.Contains(err.Error(), "exceeds the outstanding balance") {
		t.Fatalf("thông điệp lỗi = %q, cần nói rõ vượt số còn phải thu", err.Error())
	}

	var payments int
	if err := s.db.QueryRow(
		`SELECT COUNT(*) FROM payments WHERE order_id = 'o-over'`,
	).Scan(&payments); err != nil {
		t.Fatal(err)
	}
	if payments != 1 {
		t.Fatalf("số dòng payment = %d, want 1 (chỉ dòng 4000 seed sẵn — guard chặn thì không được ghi thêm)", payments)
	}

	var status string
	var paid int
	if err := s.db.QueryRow(
		`SELECT status, COALESCE(paid_amount, 0) FROM orders WHERE id = 'o-over'`,
	).Scan(&status, &paid); err != nil {
		t.Fatal(err)
	}
	if status == "closed" {
		t.Fatal("đơn đóng dù khoản thu bị từ chối — chốt bill trên tiền chưa nhận")
	}
	if paid >= 6000 {
		t.Fatalf("paid_amount = %d — khoản bị từ chối vẫn cộng vào", paid)
	}
}

// #2942 — THỨ TỰ gán trong RecordCashPayment là load-bearing: provenance của
// máy được ghi SAU context được chuyển tiếp, nên một giá trị giả mạo trong
// context không bao giờ thắng.
//
// Thứ thật sự chặn đường tấn công lại nằm ở FILE KHÁC:
// `canonicalCashChangerSplitMetadata` bật `DisallowUnknownFields`, nên một POS
// gửi `capture_source` bị 422 trước khi máy chạy (bài ngay dưới ghim điều đó).
// Nghĩa là nếu ai đó lật thứ tự ở đây, mọi test vẫn xanh — đã đo: đột biến
// "chỉ gán provenance khi khoá chưa tồn tại" KHÔNG làm gì đỏ.
//
// Hàng phòng thủ này vẫn cần, vì đường PHỤC HỒI đọc `payment_metadata` từ
// phiên bền vững chứ không đi lại qua validate: tiền đã chuyển tay lúc đó, và
// một dòng hỏng không được phép đổi danh tính máy.
func TestRecordCashPayment_ForgedProvenanceNeverBeatsTheMachine(t *testing.T) {
	s := newRecorderServer(t)
	if _, err := s.db.Exec(
		`INSERT INTO orders (id, status, total_amount) VALUES ('o-forged', 'checkout', 800)`,
	); err != nil {
		t.Fatal(err)
	}

	pid, err := s.RecordCashPayment(context.Background(), service.CashPayment{
		OrderID: "o-forged", Amount: 800, Tendered: 800,
		GloryTransactionID: "T-real", ServerID: "srv-real",
		PaymentMetadata: `{"split_mode":"even","bill_index":0,"total_bills":2,` +
			`"capture_source":"pos_manual","glory_transaction_id":"T-forged","server_id":"srv-forged"}`,
	})
	if err != nil {
		t.Fatalf("RecordCashPayment: %v", err)
	}

	var raw string
	if err := s.db.QueryRow(`SELECT metadata FROM payments WHERE id = ?`, pid).Scan(&raw); err != nil {
		t.Fatal(err)
	}
	var metadata map[string]any
	if err := json.Unmarshal([]byte(raw), &metadata); err != nil {
		t.Fatalf("metadata is not JSON: %v — %q", err, raw)
	}

	for key, want := range map[string]string{
		"capture_source":       "cash_changer",
		"glory_transaction_id": "T-real",
		"server_id":            "srv-real",
	} {
		if metadata[key] != want {
			t.Fatalf("provenance %q bị context giả mạo ghi đè: %#v", key, metadata)
		}
	}
	// Context hợp lệ vẫn phải sống sót — rào này không được đổi thành "vứt sạch
	// metadata gửi lên", vì như thế là mất đúng thứ #2942 sinh ra để giữ.
	if metadata["split_mode"] != "even" {
		t.Fatalf("ngữ cảnh chia bill hợp lệ bị mất: %#v", metadata)
	}
}
