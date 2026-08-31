// Package cloudhttp carries the outbound HTTP concerns this app applies to
// EVERY request it makes to Cloud, regardless of which subsystem makes it.
package cloudhttp

import (
	"net/http"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/config"
)

// InstallVersionHeader makes every authenticated outbound request carry
// `X-App-Version` (#2123 tầng D).
//
// # Vì sao ở transport chứ không ở từng chỗ gửi
//
// Có 13 chỗ trong `internal/` gửi request tới Cloud, 9 trong đó mang device
// token, và chúng nằm rải trong 6 constructor client khác nhau — không có
// `*http.Client` dùng chung. Thêm một dòng vào từng chỗ nghĩa là 9 dòng phải
// được nhớ tới mỗi lần ai đó thêm một call site thứ 10, và một chỉ báo phiên
// bản bỏ sót một đường sẽ **đếm thiếu đúng phần lưu lượng đi đường đó**.
//
// Điều làm phương án này chạy được: **không một chỗ nào trong repo gán
// `Transport:`** (đã quét: 5 hit đều là tên trường JSON hoặc nhãn khả năng máy
// in). Nên cả 10 client production đều rơi về `http.DefaultTransport`, và bọc
// nó một lần phủ tất cả — kể cả `SyncEngine.cloudPost`, funnel của toàn bộ
// sync-UP, mà không phải sửa `sync_service.go`.
//
// # Cổng là "token CỦA CHÍNH MÁY TRẠM", không phải "có Authorization"
//
// Bản đầu (#2145 vòng 1) gác bằng `Authorization != ""`. Cổng đó SAI, và sai
// theo hướng ghi đè dữ liệu của thiết bị khác:
//
// Cloud làm mới `devices.device_info.app_version` của **thiết bị mà token định
// danh** (`AuthenticateDevice` → `heartbeat($device, header)`), không phải của
// tiến trình đã gửi request. Mà máy trạm gửi request bằng token của thiết bị
// KHÁC, thường xuyên: `service.CloudVerifier` xác thực client LAN (kiosk · kds ·
// tms · pos-web) bằng cách chuyển tiếp token của CHÍNH client đó tới
// `GET /api/v1/devices/me` — một endpoint chạy `device.auth`. Client đó có
// `Transport` nil nên đi qua wrapper này. Đo được ở review vòng 1: hàng của
// kiosk bị ghi `app_version` của máy trạm, ở mức tin cậy cao nhất
// (`heartbeat`), đè vĩnh viễn lên giá trị `pairing` thật của nó — và kiosk/KDS
// là Expo/Vite, chúng không bao giờ tự gửi header để sửa lại.
//
// `cloud_proxy.go` cũng chuyển tiếp `Authorization` nguyên vẹn; hôm nay là token
// SSO nên vô hại, nhưng `/api/v1/pos/*` phía Cloud nhận cả token thiết bị pos —
// tức đường thứ hai cùng loại đang mở sẵn. So khớp với token của chính máy trạm
// đóng cả hai mà không phải sửa chỗ nào trong hai file đó.
//
// Cổng mới CHẶT HƠN cổng cũ chứ không lỏng hơn, nên tính chất an toàn cũ giữ
// nguyên: `http.DefaultTransport` dùng chung toàn tiến trình, nên một wrapper
// trần sẽ gửi header tới máy đếm tiền Glory trên LAN (`internal/device/glory`)
// và tới host ảnh bất kỳ đọc từ SQLite (`internal/service/image_fetcher`,
// S3 / MinIO / CDN của khách). Cả hai đều không set `Authorization`.
//
// Máy in không nằm trong diện rủi ro: `internal/printer` không import
// `net/http`, ESC/POS là TCP thô.
//
// # Fail-closed
//
// `ownDeviceToken` nil, trả "" (chưa pair), hoặc token không khớp ⇒ KHÔNG gắn
// header. Máy trạm chưa pair thì cũng chưa có hàng `devices` nào để cập nhật, và
// một chỉ báo phiên bản thiếu vài request thì chỉ ĐẾM THIẾU — còn gắn nhầm thì
// GHI ĐÈ dữ liệu thật của thiết bị khác. Hai hậu quả không cùng hạng.
//
// # Không phủ, và vì sao không sao
//
//   - `websocket.Dialer` (sync_poke) không đi qua `http.DefaultTransport`. Bắt
//     tay Reverb không mang device token; phần cần auth là `pokeChannelAuth`,
//     vốn là HTTP thường và ĐƯỢC phủ.
//   - `POST /devices/pair` chưa có token nên bị cổng loại — đúng, phiên bản lúc
//     pair đã đi trong payload `device_info.app_version` sẵn rồi.
//
// Gọi MỘT lần lúc khởi động, trước khi bất kỳ client nào được dựng — nhưng SAU
// khi mở DB, vì `ownDeviceToken` đọc từ bảng `settings`.
func InstallVersionHeader(ownDeviceToken func() string) {
	http.DefaultTransport = versionHeaderTransport{
		base:           http.DefaultTransport,
		ownDeviceToken: ownDeviceToken,
	}
}

type versionHeaderTransport struct {
	base           http.RoundTripper
	ownDeviceToken func() string
}

func (t versionHeaderTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	if req.Header.Get("X-App-Version") != "" || !t.isOwnIdentity(req) {
		return t.base.RoundTrip(req)
	}

	// RoundTripper KHÔNG được sửa request gốc — hợp đồng của net/http nói rõ,
	// và vi phạm nó làm hỏng retry ở tầng trên (request đã bị sửa được gửi lại).
	cloned := req.Clone(req.Context())
	cloned.Header.Set("X-App-Version", config.Version)

	return t.base.RoundTrip(cloned)
}

// isOwnIdentity trả true CHỈ KHI request mang Bearer token của chính máy trạm.
//
// Chuyển tiếp token của thiết bị khác (CloudVerifier, cloud_proxy) rơi vào
// nhánh false, nên header không bao giờ đi kèm một định danh không phải của
// mình.
func (t versionHeaderTransport) isOwnIdentity(req *http.Request) bool {
	if t.ownDeviceToken == nil {
		return false
	}

	const prefix = "Bearer "
	auth := req.Header.Get("Authorization")
	if !strings.HasPrefix(auth, prefix) {
		return false
	}

	own := t.ownDeviceToken()

	return own != "" && strings.TrimPrefix(auth, prefix) == own
}
