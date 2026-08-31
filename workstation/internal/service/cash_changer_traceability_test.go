package service

import (
	"context"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
)

// #2535 B9 — lượt thu tiền mặt phải nói được nó đến từ MÁY NÀO.
//
// `CashPayment.ServerID` đi vào actor audit (`cash_changer:<id>`) và
// `metadata.server_id`. Trước đây nó không bao giờ được gán, nên actor là chuỗi
// `"cash_changer:"` cụt: dòng audit của một khoản tiền mặt không truy được về
// thiết bị. Quán một máy còn đoán ra; quán hai máy thì không.
func TestCashChanger_PassesServerIDToRecorder(t *testing.T) {
	col := &fakeCollector{res: glory.Result{
		TransactionID: "T-trace", Status: glory.StatusFinish, Tendered: 1000,
	}}
	rec := &fakeRecorder{}
	svc := NewCashChangerService(col, rec)
	svc.SetServerIDResolver(func() string { return "glory-01" })

	if _, err := svc.Collect(context.Background(), "order-1", 1000); err != nil {
		t.Fatalf("Collect: %v", err)
	}

	if len(rec.recorded) != 1 {
		t.Fatalf("recorded %d payments, want 1", len(rec.recorded))
	}
	if got := rec.recorded[0].ServerID; got != "glory-01" {
		t.Errorf("ServerID = %q, want glory-01", got)
	}
	// Mã giao dịch Glory vẫn phải đi kèm — nó là khoá đối chiếu với sổ của máy.
	if got := rec.recorded[0].GloryTransactionID; got != "T-trace" {
		t.Errorf("GloryTransactionID = %q, want T-trace", got)
	}
}

// Không có máy đăng ký (chạy bằng env fallback) thì rỗng là câu trả lời ĐÚNG —
// một chuỗi bịa ra còn tệ hơn ô trống, vì nó trông như đã truy được.
func TestCashChanger_NoResolverMeansEmptyServerID(t *testing.T) {
	col := &fakeCollector{res: glory.Result{TransactionID: "T2", Status: glory.StatusFinish}}
	rec := &fakeRecorder{}
	svc := NewCashChangerService(col, rec)

	if _, err := svc.Collect(context.Background(), "order-1", 1000); err != nil {
		t.Fatalf("Collect: %v", err)
	}
	if got := rec.recorded[0].ServerID; got != "" {
		t.Errorf("ServerID = %q, want rỗng khi không có phép tra", got)
	}
}
