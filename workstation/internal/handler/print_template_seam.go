package handler

import (
	"bytes"
	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
	"log/slog"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// plan-053 T3.6 tầng 1 (#1913) — chỗ NỐI giữa formatter cũ và renderer template.
//
// ── Vì sao từng có cờ fail-closed (#1913) ─────────────────────────────────
//
// `PrintTemplateStore.RenderSlip` đã dựng đủ TR-14 từ lâu. TR-40 chứng minh
// **bản mặc định hệ thống** byte-identical với formatter cũ; template brand
// publish khác layout là tính năng opt-in, không phải side effect deploy.
//
// P-39 (plan-052) KHÔNG canh chỗ này: nó từ chối transport
// epos/webprnt/cloudprnt ở tầng cấu hình, còn đây là thứ chính workstation
// render trên `ws_lan`.
//
// ── Production: #1945 (KHÔNG phải T5.4 transport cloud) ───────────────────
//
// Hai cổng kỹ thuật (T5.1d emitter, T5.2b parity) đã hết trên dev 2026-08-06.
// Quyết định sản phẩm: **bật renderer layer 0 cho toàn bộ quán** — setting
// vắng mặt = ON. Brand/shop publish chỉ khi bật thêm
// `print_template_use_published_templates` (sau khi đã kiểm giấy thật).

// printTemplateRendererSetting điều khiển seam. VẮNG MẶT = BẬT (#1945).
// Chỉ "0" · "false" · "no" tắt hẳn (rollback khẩn).
const printTemplateRendererSetting = "print_template_renderer_enabled"

// printTemplatePublishedSetting bật bước resolve cache brand/shop. Mặc định TẮT —
// mọi quán in từ bản mặc định hệ thống; tuỳ biến brand là việc sau.
const printTemplatePublishedSetting = "print_template_use_published_templates"

func settingTruthy(raw string) bool {
	switch strings.ToLower(strings.TrimSpace(raw)) {
	case "1", "true", "yes":
		return true
	default:
		return false
	}
}

func settingExplicitOff(raw string) bool {
	switch strings.ToLower(strings.TrimSpace(raw)) {
	case "0", "false", "no":
		return true
	default:
		return false
	}
}

// templateRendererEnabled: absent hoặc mọi giá trị khác explicit off → BẬT.
func (s *Server) templateRendererEnabled() bool {
	return !settingExplicitOff(s.settingValue(printTemplateRendererSetting))
}

// templateUsePublishedTemplates: absent → TẮT (chỉ layer 0). Whitelist bật.
func (s *Server) templateUsePublishedTemplates() bool {
	return settingTruthy(s.settingValue(printTemplatePublishedSetting))
}

// renderMoneySlip là seam DUY NHẤT mà tầng 2 (#1914) được dùng để chuyển từng
// call site. Mọi call site gọi đúng hàm này, nên câu hỏi "phiếu này đến từ đâu"
// có đúng một chỗ để trả lời.
//
// Hợp đồng, theo thứ tự:
//
//  1. Cờ TẮT (explicit off) → `builtinFormatter()`. Không đọc DB.
//  2. Cờ BẬT nhưng store `nil` → `builtinFormatter()`. `nil` store là hợp lệ theo thiết kế
//     của `RenderSlip` (máy chưa pair, TR-05), nhưng ở seam này ta không cần
//     phân biệt: không có store thì không có gì để render từ.
//  3. Cờ BẬT, `RenderSlip` lỗi → `builtinFormatter()`, kèm log. `RenderSlip` đã tự rơi về
//     bản mặc định hệ thống trước khi trả lỗi, nên tới được đây nghĩa là cả bản
//     mặc định cũng không render nổi. Khi đó formatter cũ là thứ cuối cùng còn
//     in được, và **in được quan trọng hơn in đúng template**.
//  4. Còn lại → byte của renderer template.
//
// Không bao giờ trả nil khi `builtinFormatter()` có byte: một phiếu không in ra là một
// khách đứng chờ ở quầy.
//
// ── Giá trị thứ hai: DẤU PHIÊN BẢN (TR-28, #1171) ─────────────────────────
//
// Trước đây seam BIẾT phiên bản (`RenderSlip` trả `src`) rồi vứt đi ngay sau
// khi `slog.Info` — nên câu "phiếu tháng trước in bằng layout nào" không có
// chỗ nào trả lời được sau khi tờ giấy đã ra. Giờ nó trả ra ngoài để chỗ gọi
// đóng lên hàng nhật ký (`printjob.Entry.TemplateVersion`).
//
// Trả THÊM GIÁ TRỊ chứ không cất vào state của `Server`: hai lệnh in chạy song
// song trên hai máy in khác nhau là chuyện bình thường ở quán đông, và một ô
// nhớ dùng chung sẽ cho tờ này đóng dấu phiên bản của tờ kia — sai lặng lẽ,
// đúng loại sai mà cả TR-28 sinh ra để chặn.
//
// Chuỗi RỖNG là câu trả lời thật, không phải lỗi: **formatter built-in không có
// phiên bản**. Formatter cũ là MÃ NGUỒN, không phải definition; nó không mang
// số hiệu nào để đóng. Bịa ra `system:0` cho nó sẽ khiến một lần in lại đi tìm
// bản mặc định hệ thống trong khi tờ gốc do formatter vẽ. Rỗng → NULL trong
// SQLite và NULL trên Cloud, phân biệt được với mọi dấu có thật.
// finishBuiltinSlip gives a built-in formatter's bytes the PROFILE's cut dialect.
//
// #1966 — every `Format*` ends with a bare `FullCut()` = `ESC d 3`, chosen
// before profiles existed. #1950 moved the template path onto the profile but
// deliberately left the formatters alone (they are the independent baseline the
// TR-40 gate compares against, and a formatter that called the renderer would
// make that gate circular).
//
// The consequence only shows on the FALLBACK, and only on a machine that
// declared something else. `epson_tm_i` declares `gs_v_partial`; on a real
// Epson, `ESC d n` is "print and feed n lines" and is NOT a cut. So a shop that
// ran the setup wizard, declared its Epson, and then hit a render error got a
// slip that silently stopped being cut — the same shape as #1965, one step
// further down the fallback chain.
//
// ## Why trim-and-refinish rather than changing ten signatures
//
// The formatters must keep being the baseline, so they must keep being callable
// with nothing but a `PrintJobConfig`. Threading finishing into them would put a
// MACHINE capability into a struct that carries SHOP and DOCUMENT data — the
// exact conflation that produced #1965, where a default was read as a
// declaration because both lived in the same shape.
//
// So the dialect is applied where the profile already is: here, once.
//
// ## Why this is a no-op for almost everyone
//
// `Finish` on an `esc_d` profile writes exactly `Cut`. Trimming `Cut` and
// re-finishing therefore yields byte-identical output for every machine whose
// dialect is ESC d — which after #1965 includes every undescribed machine, i.e.
// the majority of the fleet. The change is only visible where it was wrong.
//
// The suffix check is a guard, not an optimisation: if a formatter ever stops
// ending with a cut, this leaves its bytes untouched rather than appending a
// second one. Doing nothing is the safe direction — an uncut slip can be torn,
// a double cut ejects a blank.
func finishBuiltinSlip(slip []byte, profile service.PrintRenderProfile) []byte {
	if len(slip) == 0 || profile.Finishing == nil {
		return slip
	}
	if !bytes.HasSuffix(slip, escpos.Cut) {
		return slip
	}

	e := escpos.New()
	e.Finish(*profile.Finishing)
	tail := bytes.TrimPrefix(e.Bytes(), escpos.Init)

	return append(bytes.TrimSuffix(slip, escpos.Cut), tail...)
}

func (s *Server) renderMoneySlip(
	data *service.PrintRenderData,
	profile service.PrintRenderProfile,
	locale string,
	builtinFormatter func() []byte,
) ([]byte, string) {
	if !s.templateRendererEnabled() {
		return finishBuiltinSlip(builtinFormatter(), profile), ""
	}

	if s.printTemplates == nil || data == nil {
		return finishBuiltinSlip(builtinFormatter(), profile), ""
	}

	var slip []byte
	var src service.PrintTemplateSource
	var err error
	if s.templateUsePublishedTemplates() {
		slip, src, err = s.printTemplates.RenderSlip(data, profile, locale)
	} else {
		slip, src, err = s.printTemplates.RenderSlipSystemDefault(data, profile, locale)
	}
	if err != nil || len(slip) == 0 {
		slog.Error("print template seam: render failed — dùng formatter cũ",
			"kind", data.Kind, "error", err)

		return finishBuiltinSlip(builtinFormatter(), profile), ""
	}

	// Ghi lại NGUỒN của tờ giấy vừa in. Không có dòng này thì "vì sao phiếu hôm
	// nay khác hôm qua" không trả lời được sau khi nó đã in ra.
	slog.Info("print template seam: rendered from template",
		"kind", data.Kind,
		"scope", src.Scope,
		"version", src.Version,
		"fallback", src.Fallback,
		"reason", src.Reason,
	)

	return slip, src.Stamp()
}
