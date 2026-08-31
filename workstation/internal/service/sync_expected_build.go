package service

import (
	"context"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/config"
	"github.com/dxs-platform/workstation-app/internal/update"
)

// #1806 S4 — HQ đẩy xuống "bản phát hành mong đợi"; máy trạm so sánh và KÊU.
//
// # Vì sao máy trạm không tự đo
//
// Ruling S4 (2026-08-05) chọn hướng HQ-đẩy-xuống. Phiên bản mong đợi là một
// QUYẾT ĐỊNH VẬN HÀNH — chỉ HQ biết đã kiểm thử tới đâu, quán nào nâng trước, và
// khi nào cần giữ một quán ở bản cũ vì lý do nghiệp vụ. Máy trạm tự hỏi tag
// GitHub sẽ bắt mọi máy ở mọi quán gọi ra Internet, trong khi "mất Internet
// nhưng LAN vẫn chạy" là trạng thái bình thường ở đây.
//
// # Assisted update, không silent auto-replace
//
// Máy trạm có thể TẢI nền binary từ catalog công khai khi phát hiện lệch
// version + package có sẵn, nhưng CHỈ thay binary khi người đứng máy bấm
// 「Cài & khởi động lại」 và không còn ca mở. Tự thay một binary đang giữ tiền
// mặt và hàng đợi in giữa ca là thứ không được làm khi không có người đứng đó.
//
// # Fail-safe
//
// Feed lỗi, hoặc HQ chưa cấu hình gì ⇒ KHÔNG kêu. Một quán mất đường tới HQ vẫn
// phải bán được hàng; biến "không biết mình có cũ không" thành một màn hình đỏ
// vĩnh viễn chỉ dạy người ta bỏ qua màu đỏ.
const pullPathExpectedBuild = "/api/v1/workstation/expected-build"

// BuildUpdater is the assisted-update planner surface SyncPuller needs.
// Implemented by *update.Planner; nil-safe at the call sites.
type BuildUpdater interface {
	SetExpected(expected, reason string, pkg *update.Package, autoApply bool)
	KickDownload(force bool)
}

// PullExpectedBuild hỏi HQ bản mong đợi rồi dựng/đóng alert `stale_build`.
func (p *SyncPuller) PullExpectedBuild(ctx context.Context) error {
	var resp struct {
		Data struct {
			Version  *string         `json:"version"`
			Severity *string         `json:"severity"`
			Reason   *string         `json:"reason"`
			Package  *update.Package `json:"package"`
			// #2635 — cờ RIÊNG cho tự-cài không người trông trong cửa sổ đêm.
			// KHÔNG suy từ severity: severity nói bản vá tệ đến đâu, cờ này nói
			// máy có được khởi động lại khi không ai đứng đó hay không. Thiếu
			// trường (backend cũ) ⇒ nil ⇒ false — chiều an toàn.
			AutoApply *bool `json:"auto_apply"`
		} `json:"data"`
	}

	if err := p.cloudGet(ctx, pullPathExpectedBuild, &resp); err != nil {
		// KHÔNG kêu khi không hỏi được. Không biết ≠ có vấn đề.
		return err
	}

	expected := ""
	if resp.Data.Version != nil {
		expected = strings.TrimSpace(*resp.Data.Version)
	}

	current := strings.TrimSpace(config.Version)

	reason := ""
	if resp.Data.Reason != nil {
		reason = *resp.Data.Reason
	}

	autoApply := resp.Data.AutoApply != nil && *resp.Data.AutoApply

	// Push into the assisted-update planner even when we resolve the alert —
	// clearing expected keeps Settings from showing a stale "install" CTA.
	if p.updater != nil {
		p.updater.SetExpected(expected, reason, resp.Data.Package, autoApply)
	}

	// HQ chưa cấu hình ⇒ im lặng, và đóng alert cũ nếu có: HQ vừa tắt cảnh báo
	// thì máy trạm phải theo, chứ không giữ một màn hình đỏ không ai gỡ được.
	//
	// `current == "dev"` là bản build cục bộ của lập trình viên; so nó với một
	// tag phát hành luôn ra "cũ", và cảnh báo đó đúng về mặt chuỗi ký tự nhưng
	// vô nghĩa với người đang chạy `make dev`.
	if expected == "" || current == "" || current == "dev" || current == expected {
		p.alerts.Resolve(KindStaleBuild, "build")

		return nil
	}

	severity := "info"
	if resp.Data.Severity != nil && *resp.Data.Severity != "" {
		severity = *resp.Data.Severity
	}

	howToFix := "mở Cài đặt → Cập nhật phần mềm, tải bản mới rồi cài & khởi động lại khi không còn ca mở"
	if resp.Data.Package == nil || len(resp.Data.Package.Platforms) == 0 {
		howToFix = "tải bản mới từ trang /downloads rồi khởi động lại máy trạm"
	}

	// Mức nghiêm trọng đi kèm từ HQ, KHÔNG suy từ khoảng cách phiên bản: trễ ba
	// bản vá lỗi chính tả là chuyện khác hẳn trễ một bản vá lỗi tiền. Ở đây nó
	// vào `detail` để người đọc alert thấy, còn severity của alert lấy từ
	// registry — một kind chỉ có một mức, và đổi mức theo từng lần dựng sẽ làm
	// khoá gộp `(kind, subject)` mang hai ý nghĩa khác nhau.
	p.alerts.Raise(KindStaleBuild, "build",
		"Máy trạm đang chạy bản cũ hơn bản HQ mong đợi",
		map[string]any{
			"current":     current,
			"expected":    expected,
			"hq_severity": severity,
			"reason":      reason,
			"how_to_fix":  howToFix,
			// #2635 — bản bật cờ sẽ được bộ lập lịch tự cài trong cửa sổ
			// 02:00–04:00 giờ quán (không còn ca mở); bản không bật thì tuyệt
			// đối không, dù severity là gì.
			"auto_apply":  autoApply,
			"has_package": resp.Data.Package != nil && len(resp.Data.Package.Platforms) > 0,
		})

	// Background download when HQ attached a package. Never for `dev` (gated
	// above) and never when already on the expected tag.
	if p.updater != nil && resp.Data.Package != nil && len(resp.Data.Package.Platforms) > 0 {
		p.updater.KickDownload(false)
	}

	return nil
}
