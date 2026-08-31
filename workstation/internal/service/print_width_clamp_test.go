package service

import (
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer"
)

// #2084 — bề rộng DÀN TRANG không bao giờ được vượt bề rộng THẬT của máy in.
//
// `PaperWidth` (bố cục người vận hành muốn, 42 ở mọi chỗ gọi) và `PhysicalWidth`
// (số ký tự máy thật sự vừa một dòng) trả lời hai câu khác nhau, và trước bài
// này chỉ cái đầu được dùng để dàn trang.
//
// HẸP HƠN máy là hợp lệ và có chủ đích — 42 trên giấy 80mm cho ra lề hai bên và
// `leftPad()` căn giữa nhờ chênh lệch ấy. VƯỢT máy thì không bao giờ hợp lệ:
// nội dung 42 cột trên đầu in 32 cột là tràn giấy, mất chữ ở mép phải, và
// `leftPad()` im lặng trả 0 vì nó chỉ đệm khi giấy RỘNG HƠN nội dung.

func TestWidthClamp_PhysicalNarrowerThanConfig(t *testing.T) {
	// Ca của mọi quán dùng 58mm: chỗ gọi xin bố cục 42, máy chỉ vừa 32.
	profile := PrintRenderProfileFor(printer.ParseProfile(""), "")
	cfg := PrintJobConfig{PaperWidth: 42, PhysicalWidth: 32}

	if got := resolvePrintWidth(nil, cfg, profile, 0); got != 32 {
		t.Errorf("bố cục %d cột trên máy 32 cột — phiếu tràn giấy", got)
	}
}

func TestWidthClamp_PhysicalWiderKeepsTheNarrowLayout(t *testing.T) {
	// Mặt kia, và là lý do phép kẹp phải MỘT CHIỀU: 42 trên giấy 80mm (48 cột)
	// là bố cục CỐ Ý hẹp hơn giấy — `leftPad()` dựa vào đúng chênh lệch ấy để
	// căn giữa. Kẹp hai chiều sẽ kéo bố cục lên 48 và xoá mất lề.
	profile := PrintRenderProfileFor(printer.ParseProfile(""), "")
	cfg := PrintJobConfig{PaperWidth: 42, PhysicalWidth: 48}

	if got := resolvePrintWidth(nil, cfg, profile, 0); got != 42 {
		t.Errorf("bố cục hẹp hơn giấy bị kéo rộng ra %d — mất lề căn giữa", got)
	}
}

func TestWidthClamp_UnknownPhysicalChangesNothing(t *testing.T) {
	// `PhysicalWidth = 0` nghĩa là KHÔNG BIẾT máy nào, không phải "máy rộng 0
	// cột". Đoán bừa ở đây sẽ làm mọi phiếu co về 0.
	profile := PrintRenderProfileFor(printer.ParseProfile(""), "")
	cfg := PrintJobConfig{PaperWidth: 42}

	if got := resolvePrintWidth(nil, cfg, profile, 0); got != 42 {
		t.Errorf("không biết máy mà vẫn đổi bề rộng: %d", got)
	}
}

func TestWidthClamp_NamedRollStillClampedToTheMachine(t *testing.T) {
	// Chỗ gọi khai cuộn 80mm nhưng máy đang lắp 58mm: bảng năng lực nói 48, máy
	// nói 32. Máy thắng — bảng năng lực là thứ máy CÓ THỂ làm, không phải thứ
	// nó ĐANG làm, và đó chính là nhầm lẫn mà `PrintRenderProfileFor` đã sửa.
	profile := PrintRenderProfileFor(printer.ParseProfile(""), "80mm")
	cfg := PrintJobConfig{PaperWidth: 42, PhysicalWidth: 32}

	if got := resolvePrintWidth(nil, cfg, profile, 0); got != 32 {
		t.Errorf("cuộn khai 80mm thắng máy 32 cột: %d", got)
	}
}

// knownWideBlocks — khối CÒN tràn ở 32 cột. Danh sách CHỈ ĐƯỢC CO LẠI.
//
// `row()` khi nhãn + số tiền không vừa thì ép khoảng cách xuống 1 và để dòng
// TRÀN, chứ không cắt cụt. Đó là lựa chọn đúng của nó — thà tràn còn hơn giấu
// mất một con số tiền — nhưng nó có nghĩa bố cục khổ hẹp phải rút NHÃN, và việc
// ấy thuộc #2035 (`print_narrow.go` đã làm cho 3 khối khác).
//
// Hai khối này chưa bao giờ tràn TRƯỚC bài #2084, vì đường 58mm chưa bao giờ
// chạy ở 32 cột — nó luôn dàn trang ở 42 rồi in tràn TOÀN BỘ. Nói cách khác
// danh sách này không phải hồi quy: nó là thứ vừa lộ ra lần đầu.
// `items` LEFT this list when the money column was padded to a shared width and
// left-aligned: every price on a slip is now the same number of columns wide, so
// the widest one no longer decides alone whether the row overruns. The ratchet
// is what noticed — the list may only shrink, and a block that stops overflowing
// has to be taken off it or the guard stops guarding.
var knownWideBlocks = map[string]bool{
	"discount_summary": true,
	"service_charge":   true,
}

// overflowingBlocks trả tập khối có ít nhất một dòng rộng hơn `cols`, đo trên
// TOÀN BỘ loại phiếu × ngôn ngữ.
//
// Một phép đo dùng chung cho cả hai test bên dưới. Bản đầu để mỗi test tự quét
// một tập loại phiếu khác nhau, nên chúng bất đồng về việc khối nào tràn — và
// bất đồng ấy trông y hệt một phát hiện thật.
func overflowingBlocks(t *testing.T, cols int) map[string]int {
	t.Helper()

	out := map[string]int{}

	for _, kind := range []string{"receipt", "kitchen", "shift_report"} {
		for _, locale := range []string{"ja", "en", "vi"} {
			def := mutateDefinition(t, kind, func(map[string]any) {})
			cfg := goldenConfig(locale, 42)
			cfg.PhysicalWidth = cols

			res, err := RenderPrintTemplate(def, goldenRenderData(kind, cfg),
				PrintRenderProfileFor(printer.ParseProfile(""), ""), locale)
			if err != nil {
				t.Fatalf("render %s/%s: %v", kind, locale, err)
			}

			for _, seg := range res.Segments {
				// Với Shift_JIS số byte BẰNG số cột (nửa chiều rộng 1/1, đủ
				// chiều rộng 2/2), nên đo trên byte đã bóc lệnh là hợp lệ — và
				// tránh phải giải mã ngược một codepage.
				for _, line := range strings.Split(stripEscPos(seg.Bytes), "\n") {
					if w := len(strings.TrimRight(line, " ")); w > cols && w > out[seg.BlockID] {
						out[seg.BlockID] = w
					}
				}
			}
		}
	}

	return out
}

// Bằng chứng end-to-end: không khối MỚI nào tràn khổ giấy.
//
// Đây là phép đo mà cả hai repo đang thiếu (#2090): golden chỉ là sha256 nên nó
// đóng băng nguyên trạng — kể cả nguyên trạng tràn giấy.
func TestWidthClamp_NoNewBlockOverflowsThePaper(t *testing.T) {
	for id, w := range overflowingBlocks(t, 32) {
		if !knownWideBlocks[id] {
			t.Errorf("khối %q tràn %d cột trên giấy 32 cột — khối MỚI, không nằm trong danh sách đã biết", id, w)
		}
	}
}

// Mặt kia của bánh cóc: khối đã hết tràn thì phải được GỠ khỏi danh sách, nếu
// không danh sách chỉ phình ra và thôi nói lên điều gì.
func TestWidthClamp_KnownWideListOnlyShrinks(t *testing.T) {
	wide := overflowingBlocks(t, 32)

	for id := range knownWideBlocks {
		if _, still := wide[id]; !still {
			t.Errorf("khối %q KHÔNG còn tràn — gỡ nó khỏi `knownWideBlocks`", id)
		}
	}
}

// stripEscPos bóc TRỌN chuỗi lệnh ESC/POS, giữ lại đúng phần văn bản.
//
// Bản đầu của hàm này chỉ bỏ byte < 0x20, và nó đo SAI: `ESC E 1` (đậm) mất byte
// ESC nhưng chữ `E` sống sót và bị tính là văn bản, nên mọi dòng ra 33 cột thay
// vì 32 — đúng một cột thừa, đủ để tôi suýt kết luận phép kẹp không chạy.
//
// Byte tham số của lệnh là ký tự in được. Bỏ theo NGƯỠNG thì không thể đúng; phải
// bỏ theo ĐỘ DÀI của từng lệnh.
func stripEscPos(b []byte) string {
	var out []byte

	for i := 0; i < len(b); {
		if b[i] != 0x1B && b[i] != 0x1D {
			out = append(out, b[i])
			i++

			continue
		}

		i += escPosSeqLen(b[i:])
	}

	return string(out)
}

// escPosSeqLen trả độ dài chuỗi lệnh bắt đầu tại b[0] (b[0] là ESC hoặc GS).
// Lệnh lạ thì bỏ 2 byte — đủ để nuốt phần dẫn và không nuốt lem văn bản.
func escPosSeqLen(b []byte) int {
	if len(b) < 2 {
		return len(b)
	}

	switch b[0] {
	case 0x1B:
		switch b[1] {
		case 0x1D: // ESC GS a n — căn lề
			return 4
		case 0x45, 0x64, 0x21, 0x2D, 0x61: // ESC E/d/!/-/a n
			return 3
		case 0x69: // ESC i n m — cỡ chữ
			return 4
		case 0x70: // ESC p m t1 t2 — ngăn kéo
			return 5
		case 0x40: // ESC @ — khởi tạo
			return 2
		}
	case 0x1D:
		switch b[1] {
		case 0x21, 0x56: // GS ! n / GS V m
			return 3
		}
	}

	return 2
}
