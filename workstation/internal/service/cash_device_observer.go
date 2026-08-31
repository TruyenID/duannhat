package service

import (
	"errors"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
)

// CashDeviceObserver là chỗ ghi hai sổ quan sát máy 釣銭機 (#2879, #2882).
//
// Cùng khuôn `CashSessionStore`: service giữ luật, tầng handler giữ `*sql.DB`.
// Nil-safe ở mọi chỗ gọi — máy trạm chưa migrate thì luồng chạy y như trước,
// chỉ là không đối soát và không có sổ sự cố.
type CashDeviceObserver interface {
	// CaptureInventory ghi 在高 tại một ranh ca ('opening' | 'closing').
	CaptureInventory(inv glory.Inventory, tillSessionID, phase string) error
	// RaiseDeviceError mở một sự cố, hoặc giữ nguyên nếu nó đang mở.
	RaiseDeviceError(errorTitle, errorGroup, gloryTransactionID, tillSessionID string) error
	// ClearDeviceError đóng sự cố đang mở — nửa cho phép tính thời lượng.
	ClearDeviceError(errorTitle string) error
}

// ErrorGroupFor phân nhóm lỗi adapter theo VIỆC NGƯỜI PHẢI LÀM (#2882).
//
// Phân nhóm ở tầng GHI vì máy trạm là nơi có `errors.go`. Cloud phân nhóm lại
// sẽ là bảng phân loại thứ hai, và hai bảng sẽ lệch nhau.
//
// Chuỗi rỗng = KHÔNG vào sổ. `IsBusy`, `IsNotFound`, `IsNotEnoughDeposit` là
// nhịp bình thường của giao thức; ghi chúng vào sẽ chôn lấp bốn nhóm thật, và
// một sổ toàn rác sẽ bị tắt.
func ErrorGroupFor(err error) string {
	if err == nil {
		return ""
	}

	// Các phép phân loại là METHOD trên `*glory.Error`, không phải hàm package —
	// nên lỗi ngoài adapter (ctx hết giờ, máy trạm tắt) rơi thẳng xuống "" và
	// KHÔNG vào sổ. Đó là đúng: cột `error_group` mang từ vựng của MÁY.
	var ge *glory.Error
	if !errors.As(err, &ge) {
		return ""
	}

	switch {
	case ge.IsChangeShortage():
		// Chặn bán hàng — đo được là đo được DOANH THU MẤT.
		return "change_shortage"
	case ge.NeedsOperator():
		return "needs_operator"
	case ge.IsConnectivity():
		return "connectivity"
	case ge.IsForbidden():
		// Cấu hình sai, và nó thường im lặng hàng tuần.
		return "forbidden"
	default:
		return ""
	}
}
