package cloudhttp

import (
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/config"
)

// #2123 tầng D — header phiên bản phải tới ĐÚNG những request đã định danh, và
// KHÔNG tới chỗ nào khác.
//
// Cổng `Authorization` không phải tối ưu, nó là rào an toàn:
// `http.DefaultTransport` dùng chung toàn tiến trình, nên một wrapper trần sẽ
// gửi header này tới máy đếm tiền Glory trên LAN và tới host ảnh bất kỳ đọc từ
// SQLite. Cả hai đều không set `Authorization` — nên bài "KHÔNG gắn khi thiếu
// Authorization" dưới đây chính là bài canh tính chất ấy, không phải một ca rìa.

// transportNilClient dựng client KHÔNG có Transport riêng.
//
// `httptest.Server.Client()` mang Transport của chính nó, nên dùng nó ở đây sẽ
// ĐI VÒNG qua wrapper và mọi bài test thành xanh giả — kể cả khi
// InstallVersionHeader chưa từng được gọi.
func transportNilClient() *http.Client {
	return &http.Client{}
}

const ownToken = "workstation-own-token"

func installForTest(t *testing.T) {
	t.Helper()

	installWithToken(t, ownToken)
}

func installWithToken(t *testing.T, token string) {
	t.Helper()

	saved := http.DefaultTransport
	t.Cleanup(func() { http.DefaultTransport = saved })

	InstallVersionHeader(func() string { return token })
}

// echoHeaders trả về server ghi lại header của request cuối cùng.
func echoHeaders(t *testing.T, got *http.Header) *httptest.Server {
	t.Helper()

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		*got = r.Header.Clone()
		w.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(srv.Close)

	return srv
}

func TestVersionHeader_AddedWhenAuthorized(t *testing.T) {
	installForTest(t)

	var got http.Header
	srv := echoHeaders(t, &got)

	req, err := http.NewRequest(http.MethodGet, srv.URL, nil)
	if err != nil {
		t.Fatalf("new request: %v", err)
	}
	req.Header.Set("Authorization", "Bearer "+ownToken)

	resp, err := transportNilClient().Do(req)
	if err != nil {
		t.Fatalf("do: %v", err)
	}
	resp.Body.Close()

	if got.Get("X-App-Version") != config.Version {
		t.Errorf("X-App-Version = %q, want %q", got.Get("X-App-Version"), config.Version)
	}
}

func TestVersionHeader_NotAddedWithoutAuthorization(t *testing.T) {
	// Đây là bài canh AN TOÀN: máy đếm tiền Glory (LAN) và image fetcher (host
	// bất kỳ từ SQLite) đều đi qua http.DefaultTransport và đều KHÔNG set
	// Authorization. Bỏ cổng này là gửi header nội bộ tới thiết bị phần cứng và
	// tới CDN của khách.
	installForTest(t)

	var got http.Header
	srv := echoHeaders(t, &got)

	resp, err := transportNilClient().Get(srv.URL)
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	resp.Body.Close()

	if v := got.Get("X-App-Version"); v != "" {
		t.Errorf("X-App-Version = %q trên request KHÔNG có Authorization — phải trống", v)
	}
}

func TestVersionHeader_DoesNotOverrideExplicitValue(t *testing.T) {
	installForTest(t)

	var got http.Header
	srv := echoHeaders(t, &got)

	req, _ := http.NewRequest(http.MethodGet, srv.URL, nil)
	req.Header.Set("Authorization", "Bearer "+ownToken)
	req.Header.Set("X-App-Version", "set-by-caller")

	resp, err := transportNilClient().Do(req)
	if err != nil {
		t.Fatalf("do: %v", err)
	}
	resp.Body.Close()

	if got.Get("X-App-Version") != "set-by-caller" {
		t.Errorf("X-App-Version = %q, want set-by-caller", got.Get("X-App-Version"))
	}
}

func TestVersionHeader_DoesNotMutateCallerRequest(t *testing.T) {
	// Hợp đồng của net/http: RoundTripper KHÔNG được sửa request gốc. Vi phạm nó
	// làm hỏng retry ở tầng trên — lần gửi lại mang một request đã bị sửa.
	installForTest(t)

	var got http.Header
	srv := echoHeaders(t, &got)

	req, _ := http.NewRequest(http.MethodGet, srv.URL, nil)
	req.Header.Set("Authorization", "Bearer "+ownToken)

	resp, err := transportNilClient().Do(req)
	if err != nil {
		t.Fatalf("do: %v", err)
	}
	resp.Body.Close()

	if v := req.Header.Get("X-App-Version"); v != "" {
		t.Errorf("request GỐC bị sửa: X-App-Version = %q", v)
	}
}

// #2145 vòng 1 — bài canh chế độ hỏng NẶNG NHẤT của tầng này.
//
// Máy trạm chuyển tiếp token của thiết bị KHÁC tới Cloud thường xuyên:
// `service.CloudVerifier` xác thực kiosk/kds/tms/pos-web bằng cách gửi token của
// chính client đó tới `GET /api/v1/devices/me`, một endpoint chạy `device.auth`.
// Cloud làm mới `devices.device_info.app_version` của **thiết bị mà token định
// danh**, nên gắn header lên request đó ghi phiên bản của MÁY TRẠM vào hàng của
// KIOSK — ở mức tin cậy `heartbeat`, đè vĩnh viễn lên `pairing` thật của nó.
//
// Cổng cũ (`Authorization != ""`) để lọt ca này. Đây là bài bắt nó quay lại.
func TestVersionHeader_NotAddedWhenForwardingAnotherDeviceToken(t *testing.T) {
	installForTest(t)

	var got http.Header
	srv := echoHeaders(t, &got)

	req, _ := http.NewRequest(http.MethodGet, srv.URL, nil)
	req.Header.Set("Authorization", "Bearer kiosk-device-token")

	resp, err := transportNilClient().Do(req)
	if err != nil {
		t.Fatalf("do: %v", err)
	}
	resp.Body.Close()

	if v := got.Get("X-App-Version"); v != "" {
		t.Errorf("X-App-Version = %q trên request mang token THIẾT BỊ KHÁC — phải trống, "+
			"nếu không Cloud ghi phiên bản máy trạm vào hàng của thiết bị đó", v)
	}
}

// Chưa pair thì không có hàng `devices` nào để cập nhật — và fail-closed ở đây
// rẻ hơn nhiều so với gắn nhầm: thiếu vài request chỉ làm ĐẾM THIẾU, còn gắn
// nhầm thì GHI ĐÈ dữ liệu thật của thiết bị khác.
func TestVersionHeader_NotAddedWhenUnpaired(t *testing.T) {
	installWithToken(t, "")

	var got http.Header
	srv := echoHeaders(t, &got)

	req, _ := http.NewRequest(http.MethodGet, srv.URL, nil)
	req.Header.Set("Authorization", "Bearer some-token")

	resp, err := transportNilClient().Do(req)
	if err != nil {
		t.Fatalf("do: %v", err)
	}
	resp.Body.Close()

	if v := got.Get("X-App-Version"); v != "" {
		t.Errorf("X-App-Version = %q khi máy trạm CHƯA PAIR — phải trống", v)
	}
}
