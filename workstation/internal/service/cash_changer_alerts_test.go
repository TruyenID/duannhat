package service

import (
	"context"
	"errors"
	"log/slog"
	"path/filepath"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// #2535 B4/B5 — cảnh báo của luồng 釣銭機 phải nói ĐÚNG chuyện đang xảy ra với
// tiền, vì đó là thứ quyết định nhân viên làm gì tiếp theo:
//
//   - máy CÒN GIỮ tiền  ⇒ đi mở máy lấy tiền ra
//   - tiền ĐÃ TRẢ khách ⇒ không phải làm gì
//   - đã thu, ghi sổ hỏng ⇒ tiền nằm đúng chỗ, phải GHI TAY dòng thu và đối soát
//
// Bản đầu gộp ca 1 và 2 vào cùng một cảnh báo critical không tự đóng, còn ca 3
// thì không có cảnh báo nào.
//
// Không mock emitter: thứ đang đo là "sau lượt thu này còn cảnh báo nào đang
// mở", và một mock chỉ chứng minh `Raise` được gọi.
func cashChangerWithAlerts(t *testing.T, col *fakeCollector, rec *fakeRecorder) (*CashChangerService, *AlertEmitter) {
	t.Helper()

	db, err := storetest.Open(filepath.Join(t.TempDir(), "alerts.db"))
	if err != nil {
		t.Fatalf("storetest.Open: %v", err)
	}
	t.Cleanup(func() { _ = db.Close() })

	svc := NewCashChangerService(col, rec)
	em := NewAlertEmitter(NewAlertStore(db), slog.Default())
	svc.SetAlerts(em)

	return svc, em
}

func openAlertKinds(t *testing.T, em *AlertEmitter) []AlertKind {
	t.Helper()

	open, err := em.ListOpen()
	if err != nil {
		t.Fatalf("ListOpen: %v", err)
	}

	kinds := make([]AlertKind, 0, len(open))
	for _, a := range open {
		kinds = append(kinds, a.Kind)
	}

	return kinds
}

func TestCashChanger_Canceled_RaisesNoRetentionAlert(t *testing.T) {
	// `glory.ErrCanceled` nói rõ trong docblock: "deposited cash was RETURNED".
	// Khách đổi ý bấm huỷ là chuyện thường ngày; để nó đẻ ra một cảnh báo đỏ
	// vĩnh viễn bảo nhân viên đi tìm tiền trong máy là cách dạy quán bấm-qua
	// đúng cái cảnh báo duy nhất không được phép bỏ qua.
	col := &fakeCollector{res: glory.Result{TransactionID: "T-cancel"}, err: glory.ErrCanceled}
	svc, em := cashChangerWithAlerts(t, col, &fakeRecorder{})

	if _, err := svc.Collect(context.Background(), "order-1", 1000); !errors.Is(err, glory.ErrCanceled) {
		t.Fatalf("err = %v, want ErrCanceled", err)
	}

	if kinds := openAlertKinds(t, em); len(kinds) != 0 {
		t.Errorf("alert đang mở = %v, want rỗng — tiền đã trả lại khách", kinds)
	}
}

func TestCashChanger_ChangeShortage_RaisesNoRetentionAlert(t *testing.T) {
	// "change shortage, transaction canceled" — cũng là tiền đã về tay khách.
	col := &fakeCollector{res: glory.Result{TransactionID: "T-short"}, err: glory.ErrChangeShortage}
	svc, em := cashChangerWithAlerts(t, col, &fakeRecorder{})

	if _, err := svc.Collect(context.Background(), "order-1", 1000); !errors.Is(err, glory.ErrChangeShortage) {
		t.Fatalf("err = %v, want ErrChangeShortage", err)
	}

	if kinds := openAlertKinds(t, em); len(kinds) != 0 {
		t.Errorf("alert đang mở = %v, want rỗng — máy đã huỷ giao dịch và trả tiền", kinds)
	}
}

func TestCashChanger_TimedOut_StillRaisesCashRetained(t *testing.T) {
	// Chiều ngược của hai ca trên: `ErrTimedOut` = "the machine KEPT the cash".
	// Thu hẹp cảnh báo KHÔNG được làm mất ca thật.
	col := &fakeCollector{res: glory.Result{TransactionID: "T-timeout", Tendered: 5000}, err: glory.ErrTimedOut}
	svc, em := cashChangerWithAlerts(t, col, &fakeRecorder{})

	if _, err := svc.Collect(context.Background(), "order-1", 1000); !errors.Is(err, glory.ErrTimedOut) {
		t.Fatalf("err = %v, want ErrTimedOut", err)
	}

	kinds := openAlertKinds(t, em)
	if len(kinds) != 1 || kinds[0] != KindCashRetained {
		t.Errorf("alert đang mở = %v, want đúng một %s", kinds, KindCashRetained)
	}
}

func TestCashChanger_RecordFailure_RaisesItsOwnCriticalAlert(t *testing.T) {
	// Ca nguy hiểm nhất của cả luồng: tiền mặt thật đã vào máy, máy đã thối
	// tiền, mà sổ không có dòng nào. Trước #2535 nó đi ra ngoài dưới dạng một
	// chuỗi lỗi mà giao diện không bao giờ render, và KHÔNG có cảnh báo nào.
	col := &fakeCollector{res: glory.Result{
		TransactionID: "T-nowrite", Status: glory.StatusFinish, Tendered: 5000, Change: 4000,
	}}
	svc, em := cashChangerWithAlerts(t, col, &fakeRecorder{err: errors.New("disk full")})

	out, err := svc.Collect(context.Background(), "order-42", 1000)
	if err == nil {
		t.Fatal("want an error when cash collected but recording failed")
	}

	// `payment_id` rỗng là dấu hiệu mà pos-web dựa vào để KHÔNG gọi đây là
	// thành công — xem `cash-changer-overlay.tsx` (#2535 B3).
	if out.PaymentID != "" {
		t.Errorf("PaymentID = %q, want rỗng khi ghi sổ hỏng", out.PaymentID)
	}

	open, err := em.ListOpen()
	if err != nil {
		t.Fatalf("ListOpen: %v", err)
	}
	if len(open) != 1 || open[0].Kind != KindCashCollectedNotRecorded {
		t.Fatalf("alert đang mở = %+v, want đúng một %s", open, KindCashCollectedNotRecorded)
	}

	// KHÔNG phải `cash_retained`: máy không giữ tiền ở đây, nên việc phải làm là
	// ghi tay dòng thu chứ không phải đi mở máy.
	if open[0].Kind == KindCashRetained {
		t.Error("dùng lại cash_retained là bảo nhân viên đi tìm số tiền không mất")
	}
	if open[0].Subject != "T-nowrite" {
		t.Errorf("subject = %q, want mã giao dịch Glory để đối soát", open[0].Subject)
	}
}
