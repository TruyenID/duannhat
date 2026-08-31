package service

import (
	"context"
	"errors"
	"log/slog"
	"path/filepath"
	stdsync "sync"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// #2535 B10 — phiên thu tiền mặt phải sống sót qua một lần restart.
//
// Thứ đang đo KHÔNG phải "hàm có chạy không" mà là: **sau khi workstation tắt
// giữa lượt thu rồi bật lại, khoản tiền trong ngăn kéo có được ghi vào sổ
// không** — và khi không kết luận được thì có ai đó được báo không.

// Fake này bị goroutine của `Begin` chạm vào song song với test, nên nó có
// mutex — bản đầu không có và `-race` bắt được ngay. Store thật là `*sql.DB`
// (an toàn đa luồng sẵn), nên đây là chuyện của fake, không phải của bản vá.
type fakeSessionStore struct {
	ledger   map[string]MachineLedgerRow
	mu       stdsync.Mutex
	rows     []UnresolvedCashSession
	resolved map[string]string
	listErr  error
	// beginErr tiêm lỗi ghi hàng phiên. Không có nó thì bất biến "lỗi ghi phiên
	// KHÔNG chặn lượt thu" không thể đo được — xem
	// TestSession_StoreFailureDoesNotBlockCollection (#2587 vòng 2).
	beginErr error
}

func (f *fakeSessionStore) BeginSession(
	sessionID, orderID string,
	amount int,
	paymentMetadata string,
) error {
	f.mu.Lock()
	defer f.mu.Unlock()

	if f.beginErr != nil {
		return f.beginErr
	}
	f.rows = append(f.rows, UnresolvedCashSession{
		SessionID: sessionID, OrderID: orderID, Amount: amount,
		PaymentMetadata: paymentMetadata,
	})
	return nil
}

func (f *fakeSessionStore) StampTransaction(sessionID, txn string) error {
	f.mu.Lock()
	defer f.mu.Unlock()

	for i := range f.rows {
		if f.rows[i].SessionID == sessionID {
			f.rows[i].GloryTransactionID = txn
		}
	}
	return nil
}

func (f *fakeSessionStore) ResolveSession(sessionID, outcome string) error {
	f.mu.Lock()
	defer f.mu.Unlock()

	if f.resolved == nil {
		f.resolved = map[string]string{}
	}
	f.resolved[sessionID] = outcome
	return nil
}

// #2878 — sổ MÁY, tách khỏi kết cục phục hồi. Giữ lại để test khẳng định được
// rằng lượt thu nào cũng đóng dấu sự thật của máy, không chỉ đóng phiên.
func (f *fakeSessionStore) RecordMachineLedger(sessionID string, row MachineLedgerRow) error {
	f.mu.Lock()
	defer f.mu.Unlock()

	if f.ledger == nil {
		f.ledger = map[string]MachineLedgerRow{}
	}
	f.ledger[sessionID] = row
	return nil
}

func (f *fakeSessionStore) outcomeOf(sessionID string) string {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.resolved[sessionID]
}

func (f *fakeSessionStore) rowCount() int {
	f.mu.Lock()
	defer f.mu.Unlock()
	return len(f.rows)
}

func (f *fakeSessionStore) UnresolvedSessions() ([]UnresolvedCashSession, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	return f.rows, f.listErr
}

type fakeTxnQuerier struct {
	txn   glory.Transaction
	err   error
	calls int
}

func (f *fakeTxnQuerier) GetTransaction(context.Context, string) (glory.Transaction, error) {
	f.calls++
	return f.txn, f.err
}

func recoveryService(t *testing.T, store *fakeSessionStore, q *fakeTxnQuerier, rec *fakeRecorder) (*CashChangerService, *AlertEmitter) {
	t.Helper()

	db, err := storetest.Open(filepath.Join(t.TempDir(), "recovery.db"))
	if err != nil {
		t.Fatalf("storetest.Open: %v", err)
	}
	t.Cleanup(func() { _ = db.Close() })

	svc := NewCashChangerService(&fakeCollector{}, rec)
	em := NewAlertEmitter(NewAlertStore(db), slog.Default())
	svc.SetAlerts(em)
	svc.SetSessionStore(store)
	if q != nil {
		svc.SetTransactionQuerier(q)
	}

	return svc, em
}

func unresolved(txn string) UnresolvedCashSession {
	return UnresolvedCashSession{
		SessionID: "s-1", OrderID: "o-1", Amount: 1000,
		GloryTransactionID: txn, StartedAt: time.Now().UTC(),
	}
}

func TestReconcile_FinishedTransactionGetsRecorded(t *testing.T) {
	// Ca chính: máy ĐÃ thu và ĐÃ thối tiền, workstation tắt trước khi ghi sổ.
	// Lượt khởi động phải biến khoản tiền trong ngăn kéo thành một dòng payment.
	store := &fakeSessionStore{rows: []UnresolvedCashSession{unresolved("T-1")}}
	q := &fakeTxnQuerier{txn: glory.Transaction{
		TransactionStatus: glory.StatusFinish, Total: 1000, Deposit: 5000, DispensedCash: 4000,
	}}
	rec := &fakeRecorder{}
	svc, em := recoveryService(t, store, q, rec)

	n, err := svc.ReconcileUnfinishedSessions(context.Background())
	if err != nil || n != 1 {
		t.Fatalf("Reconcile = %d, %v; want 1, nil", n, err)
	}

	if len(rec.recorded) != 1 {
		t.Fatalf("ghi %d payment, want 1", len(rec.recorded))
	}
	got := rec.recorded[0]
	if got.OrderID != "o-1" || got.Amount != 1000 || got.Tendered != 5000 || got.Change != 4000 {
		t.Errorf("payment = %+v, want số của MÁY, không phải số đoán", got)
	}
	if got.GloryTransactionID != "T-1" {
		t.Errorf("GloryTransactionID = %q — mất khoá idempotency thì lượt khởi động kế sẽ ghi trùng", got.GloryTransactionID)
	}
	if store.outcomeOf("s-1") != CashSessionRecorded {
		t.Errorf("outcome = %q, want %q", store.outcomeOf("s-1"), CashSessionRecorded)
	}
	if kinds := openAlertKinds(t, em); len(kinds) != 0 {
		t.Errorf("alert = %v, want rỗng — khoản này đã vào sổ, không có gì để báo", kinds)
	}
}

func TestReconcile_FinishedSplitTransactionPreservesAuditContext(t *testing.T) {
	metadata := `{"split_mode":"by_amount","bill_index":1,"total_bills":2,"label":"Guest 2","amount":1000}`
	row := unresolved("T-split-recovery")
	row.PaymentMetadata = metadata
	store := &fakeSessionStore{rows: []UnresolvedCashSession{row}}
	q := &fakeTxnQuerier{txn: glory.Transaction{
		TransactionStatus: glory.StatusFinish, Total: 1000, Deposit: 1000,
	}}
	rec := &fakeRecorder{}
	svc, _ := recoveryService(t, store, q, rec)

	if _, err := svc.ReconcileUnfinishedSessions(context.Background()); err != nil {
		t.Fatalf("Reconcile: %v", err)
	}
	if len(rec.recorded) != 1 || rec.recorded[0].PaymentMetadata != metadata {
		t.Fatalf("recovered payment = %+v, want original split metadata %s", rec.recorded, metadata)
	}
}

func TestReconcile_CanceledTransactionRecordsNothingAndAlertsNothing(t *testing.T) {
	store := &fakeSessionStore{rows: []UnresolvedCashSession{unresolved("T-2")}}
	q := &fakeTxnQuerier{txn: glory.Transaction{TransactionStatus: glory.StatusCancel}}
	rec := &fakeRecorder{}
	svc, em := recoveryService(t, store, q, rec)

	if _, err := svc.ReconcileUnfinishedSessions(context.Background()); err != nil {
		t.Fatalf("Reconcile: %v", err)
	}

	if len(rec.recorded) != 0 {
		t.Errorf("ghi %d payment, want 0 — tiền đã trả lại khách", len(rec.recorded))
	}
	if store.outcomeOf("s-1") != CashSessionReturned {
		t.Errorf("outcome = %q, want %q", store.outcomeOf("s-1"), CashSessionReturned)
	}
	if kinds := openAlertKinds(t, em); len(kinds) != 0 {
		t.Errorf("alert = %v, want rỗng", kinds)
	}
}

func TestReconcile_NoTransactionIDMeansAskAHuman(t *testing.T) {
	// Máy chưa kịp trả id ⇒ KHÔNG có gì để hỏi. Đoán theo hướng nào cũng sai:
	// "chưa nhận tiền" làm mất một khoản thu, "đã nhận" ghi vào sổ một khoản
	// không có thật.
	store := &fakeSessionStore{rows: []UnresolvedCashSession{unresolved("")}}
	q := &fakeTxnQuerier{}
	rec := &fakeRecorder{}
	svc, em := recoveryService(t, store, q, rec)

	if _, err := svc.ReconcileUnfinishedSessions(context.Background()); err != nil {
		t.Fatalf("Reconcile: %v", err)
	}

	if q.calls != 0 {
		t.Errorf("hỏi máy %d lần với id rỗng — không có gì để hỏi", q.calls)
	}
	if len(rec.recorded) != 0 {
		t.Errorf("ghi %d payment, want 0 — không được đoán", len(rec.recorded))
	}
	kinds := openAlertKinds(t, em)
	if len(kinds) != 1 || kinds[0] != KindCashCollectedNotRecorded {
		t.Errorf("alert = %v, want đúng một %s", kinds, KindCashCollectedNotRecorded)
	}
}

func TestReconcile_UnreachableMachineAlertsInsteadOfGuessing(t *testing.T) {
	store := &fakeSessionStore{rows: []UnresolvedCashSession{unresolved("T-3")}}
	q := &fakeTxnQuerier{err: errors.New("dial tcp: connection refused")}
	rec := &fakeRecorder{}
	svc, em := recoveryService(t, store, q, rec)

	if _, err := svc.ReconcileUnfinishedSessions(context.Background()); err != nil {
		t.Fatalf("Reconcile: %v", err)
	}

	if len(rec.recorded) != 0 {
		t.Errorf("ghi %d payment, want 0 — không hỏi được thì không kết luận được", len(rec.recorded))
	}
	kinds := openAlertKinds(t, em)
	if len(kinds) != 1 || kinds[0] != KindCashCollectedNotRecorded {
		t.Errorf("alert = %v, want đúng một %s", kinds, KindCashCollectedNotRecorded)
	}
}

func TestReconcile_RetainedTransactionRaisesCashRetained(t *testing.T) {
	store := &fakeSessionStore{rows: []UnresolvedCashSession{unresolved("T-4")}}
	q := &fakeTxnQuerier{txn: glory.Transaction{TransactionStatus: glory.StatusTimeout}}
	svc, em := recoveryService(t, store, q, &fakeRecorder{})

	if _, err := svc.ReconcileUnfinishedSessions(context.Background()); err != nil {
		t.Fatalf("Reconcile: %v", err)
	}

	kinds := openAlertKinds(t, em)
	if len(kinds) != 1 || kinds[0] != KindCashRetained {
		t.Errorf("alert = %v, want đúng một %s — máy đang giữ tiền", kinds, KindCashRetained)
	}
}

func TestReconcile_NothingUnresolvedTouchesNothing(t *testing.T) {
	// Khởi động bình thường (không có phiên dở) không được hỏi máy một lần nào:
	// một lượt gọi thừa tới máy lúc khởi động là một lượt gọi có thể va vào
	// giao dịch đang chạy.
	q := &fakeTxnQuerier{}
	svc, em := recoveryService(t, &fakeSessionStore{}, q, &fakeRecorder{})

	n, err := svc.ReconcileUnfinishedSessions(context.Background())
	if err != nil || n != 0 {
		t.Fatalf("Reconcile = %d, %v; want 0, nil", n, err)
	}
	if q.calls != 0 {
		t.Errorf("hỏi máy %d lần khi không có phiên dở nào", q.calls)
	}
	if kinds := openAlertKinds(t, em); len(kinds) != 0 {
		t.Errorf("alert = %v, want rỗng", kinds)
	}
}

func TestReconcile_WithoutStoreIsANoOp(t *testing.T) {
	// Máy trạm chưa migrate (hoặc test không quan tâm): luồng chạy y như trước.
	svc := NewCashChangerService(&fakeCollector{}, &fakeRecorder{})

	n, err := svc.ReconcileUnfinishedSessions(context.Background())
	if err != nil || n != 0 {
		t.Fatalf("Reconcile = %d, %v; want 0, nil", n, err)
	}
}

// Nửa còn lại của bản vá: reconcile chỉ có việc để làm nếu `Begin` đã ghi hàng
// xuống đĩa TRƯỚC khi gọi máy, và đóng nó lại khi lượt thu kết thúc.
func TestBegin_WritesSessionRowAndResolvesIt(t *testing.T) {
	store := &fakeSessionStore{}
	rec := &fakeRecorder{}
	svc := NewCashChangerService(
		&fakeCollector{res: glory.Result{TransactionID: "T-9", Status: glory.StatusFinish}},
		rec,
	)
	svc.SetSessionStore(store)

	sessionID, err := svc.Begin("order-9", 1500)
	if err != nil {
		t.Fatalf("Begin: %v", err)
	}

	if store.rowCount() != 1 || store.rows[0].SessionID != sessionID {
		t.Fatalf("rows = %+v, want đúng một hàng cho phiên %s", store.rows, sessionID)
	}
	if store.rows[0].OrderID != "order-9" || store.rows[0].Amount != 1500 {
		t.Errorf("hàng = %+v, want order-9 / 1500", store.rows[0])
	}

	// Lượt thu chạy nền — chờ nó đóng hàng lại.
	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if store.outcomeOf(sessionID) != "" {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}

	if got := store.outcomeOf(sessionID); got != CashSessionRecorded {
		t.Errorf("outcome = %q, want %q — hàng không đóng lại thì lượt khởi động kế sẽ đối soát một phiên đã xong",
			got, CashSessionRecorded)
	}
}

func TestBeginWithPaymentMetadata_WritesContextBeforeCollection(t *testing.T) {
	store := &fakeSessionStore{}
	recorder := &fakeRecorder{}
	svc := NewCashChangerService(
		&fakeCollector{res: glory.Result{TransactionID: "T-meta", Status: glory.StatusFinish}},
		recorder,
	)
	svc.SetSessionStore(store)
	metadata := `{"split_mode":"even","bill_index":0,"total_bills":2}`

	sessionID, err := svc.BeginWithPaymentMetadata("order-meta", 1000, metadata)
	if err != nil {
		t.Fatalf("BeginWithPaymentMetadata: %v", err)
	}
	if store.rowCount() != 1 || store.rows[0].PaymentMetadata != metadata {
		t.Fatalf("session row = %+v, want metadata persisted before machine call", store.rows)
	}

	waitSnapshot(t, svc, sessionID)
	recorder.mu.Lock()
	defer recorder.mu.Unlock()
	if len(recorder.recorded) != 1 || recorder.recorded[0].PaymentMetadata != metadata {
		t.Fatalf("live payment = %+v, want the same metadata forwarded to recorder", recorder.recorded)
	}
}
