package service

import (
	"context"
	"errors"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
)

// waitSnapshot polls Snapshot until Running==false or a deadline.
func waitSnapshot(t *testing.T, s *CashChangerService, sid string) SessionSnapshot {
	t.Helper()
	deadline := time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		snap, ok := s.Snapshot(sid)
		if !ok {
			t.Fatalf("snapshot for %s not found", sid)
		}
		if !snap.Running {
			return snap
		}
		time.Sleep(2 * time.Millisecond)
	}
	t.Fatal("collection did not finish in time")
	return SessionSnapshot{}
}

func TestSession_Begin_Finish(t *testing.T) {
	col := &fakeCollector{res: glory.Result{TransactionID: "T1", Status: glory.StatusFinish, Tendered: 10000, Change: 1350}, delay: 10 * time.Millisecond}
	rec := &fakeRecorder{}
	svc := NewCashChangerService(col, rec)

	sid, err := svc.Begin("order-1", 8650)
	if err != nil {
		t.Fatalf("Begin: %v", err)
	}

	// Immediately after Begin the session is running (non-blocking).
	if snap, ok := svc.Snapshot(sid); !ok || !snap.Running {
		t.Fatalf("snapshot right after Begin = %+v ok=%v, want running", snap, ok)
	}

	final := waitSnapshot(t, svc, sid)
	if final.Status != glory.StatusFinish || final.PaymentID == "" {
		t.Errorf("final = %+v, want finish + a payment id", final)
	}
	if final.Tendered != 10000 || final.Change != 1350 {
		t.Errorf("final money = tendered %d change %d, want 10000/1350", final.Tendered, final.Change)
	}
}

func TestSession_Busy_WhileRunning(t *testing.T) {
	col := &fakeCollector{res: glory.Result{TransactionID: "T", Status: glory.StatusFinish}, delay: 60 * time.Millisecond}
	svc := NewCashChangerService(col, &fakeRecorder{})

	sid, err := svc.Begin("order-1", 1000)
	if err != nil {
		t.Fatalf("Begin: %v", err)
	}
	// Second Begin while the first is still running → busy.
	if _, err := svc.Begin("order-2", 1000); err != ErrMachineBusy {
		t.Fatalf("second Begin err = %v, want ErrMachineBusy", err)
	}

	// After the first finishes, a new Begin succeeds.
	waitSnapshot(t, svc, sid)
	if _, err := svc.Begin("order-3", 1000); err != nil {
		t.Fatalf("Begin after finish: %v", err)
	}
}

func TestSession_Cancel(t *testing.T) {
	// depositAmount < total → never fixes, keeps polling until canceled.
	col := &fakeCollector{res: glory.Result{TransactionID: "T", Status: glory.StatusCancel}, err: glory.ErrCanceled, delay: 5 * time.Millisecond}
	svc := NewCashChangerService(col, &fakeRecorder{})

	sid, _ := svc.Begin("order-1", 1000)
	if err := svc.CancelSession(context.Background(), sid); err != nil {
		t.Fatalf("CancelSession: %v", err)
	}
	if col.cancelCalls != 1 {
		t.Errorf("cancelCalls = %d, want 1", col.cancelCalls)
	}

	final := waitSnapshot(t, svc, sid)
	if final.PaymentID != "" {
		t.Errorf("canceled session recorded a payment: %+v", final)
	}
	if final.Error == "" {
		t.Errorf("canceled session should carry a terminal error, got %+v", final)
	}
}

func TestSession_UnknownID(t *testing.T) {
	svc := NewCashChangerService(&fakeCollector{}, &fakeRecorder{})
	if _, ok := svc.Snapshot("nope"); ok {
		t.Error("Snapshot of unknown id returned ok=true")
	}
	if err := svc.CancelSession(context.Background(), "nope"); err == nil {
		t.Error("CancelSession of unknown id returned nil error")
	}
}

// #2535 B10 / #2587 vòng 2 — bất biến LOAD-BEARING mà chính PR này tuyên bố:
// **lỗi ghi hàng phiên KHÔNG được chặn lượt thu.**
//
// Lý lẽ nằm trong `cash_changer_session.go`: khách đang đứng trước máy, và mất
// khả năng PHỤC HỒI vẫn tốt hơn mất luôn khả năng BÁN. Hành vi có thật (log rồi
// đi tiếp), nhưng không test nào tiêm được lỗi vào `BeginSession` — nên một
// refactor đổi `slog.Error(...)` thành `return err` sẽ giết lượt bán ngay giữa
// lúc khách trả tiền, và không gì đỏ.
//
// Ba khẳng định: Begin KHÔNG lỗi, máy VẪN được gọi, và tiền VẪN được ghi nhận.
// Thiếu bất kỳ cái nào thì "không chặn" chỉ đúng trên giấy.
func TestSession_StoreFailureDoesNotBlockCollection(t *testing.T) {
	col := &fakeCollector{res: glory.Result{TransactionID: "T-store-down", Status: glory.StatusFinish, Tendered: 5000, Change: 0}, delay: 5 * time.Millisecond}
	rec := &fakeRecorder{}
	svc := NewCashChangerService(col, rec)
	svc.sessions = &fakeSessionStore{beginErr: errors.New("disk full")}

	sid, err := svc.Begin("order-store-down", 5000)
	if err != nil {
		t.Fatalf("Begin lỗi %v — lỗi ghi phiên đã chặn lượt thu; khách đứng trước máy mà không bán được", err)
	}

	final := waitSnapshot(t, svc, sid)
	if final.Status != glory.StatusFinish {
		t.Fatalf("status = %v, want finish — máy phải vẫn được gọi dù không ghi được hàng phiên", final.Status)
	}
	if final.PaymentID == "" {
		t.Fatal("không có payment id — tiền đã thu mà không ghi nhận, đúng thứ bất biến này sinh ra để tránh")
	}
	if col.maxInFlight == 0 {
		t.Fatal("máy chưa bao giờ được gọi — Collect không chạy thì bất biến này vô nghĩa")
	}
}
