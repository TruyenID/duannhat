package service

import (
	"bytes"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// #1950 — cắt giấy phải theo PROFILE, không phải một `FullCut()` mù.
//
// Trước bài này `PrintRenderProfile` bỏ mất spec kết thúc, nên mọi máy đều nhận
// ESC d 3 bất kể profile khai gì. Đối chiếu ba profile Cloud có thật:
//
//	escpos_generic  gs_v_full     → nhận ESC d 3   ✗
//	epson_tm_i      gs_v_partial  → nhận ESC d 3   ✗  cắt RỜI thay vì cắt DÍNH
//	star_mcprint    esc_d         → nhận ESC d 3   ✓  đúng, tình cờ
//
// `epson_tm_i` là ca đau: partial giữ một mẩu dính để tờ phiếu không rơi xuống
// sàn.
func renderReceiptWith(t *testing.T, p PrintRenderProfile) []byte {
	t.Helper()

	restore := freezePrintClock(t)
	defer restore()

	cfg := goldenConfigFor("receipt", "ja", 48)

	def, err := SystemPrintTemplate("receipt")
	if err != nil {
		t.Fatalf("system default: %v", err)
	}

	got, err := RenderPrintTemplate(def, goldenRenderData("receipt", cfg), p, "ja")
	if err != nil {
		t.Fatalf("render: %v", err)
	}

	return got.Bytes()
}

// TestFinishing_NilProfileKeepsTodaysBytes là cái giữ cho bài này AN TOÀN: một
// quán chưa cấu hình profile phải in y hệt hôm qua. Nếu ca này đỏ thì thay đổi
// đã rò ra ngoài phạm vi nó tự khai.
func TestFinishing_NilProfileKeepsTodaysBytes(t *testing.T) {
	out := renderReceiptWith(t, PrintRenderProfile{Columns: 48})

	if !bytes.HasSuffix(out, escpos.Cut) {
		t.Fatalf("profile rỗng phải kết thúc bằng ESC d 3 như trước #1950, đuôi thật: % x", tailOf(out))
	}
}

func TestFinishing_PartialCutProfileGetsPartialCut(t *testing.T) {
	p := printer.DefaultProfile()
	p.Finishing.Cut.Mode = printer.CutGsVPartial
	p.Finishing.Cut.FeedBeforeCut = 2

	out := renderReceiptWith(t, withFinishing(p))

	if bytes.HasSuffix(out, escpos.Cut) {
		t.Fatal("máy khai gs_v_partial vẫn nhận ESC d 3 — cắt RỜI, tờ phiếu rơi xuống sàn")
	}
	if !bytes.HasSuffix(out, []byte{0x1D, 0x56, 0x01}) {
		t.Fatalf("thiếu GS V 1 (partial cut), đuôi thật: % x", tailOf(out))
	}
}

func TestFinishing_CutNoneNeverSendsACut(t *testing.T) {
	p := printer.DefaultProfile()
	p.Finishing.Cut.Mode = printer.CutNone

	out := renderReceiptWith(t, withFinishing(p))

	for _, seq := range [][]byte{escpos.Cut, {0x1D, 0x56, 0x00}, {0x1D, 0x56, 0x01}} {
		if bytes.Contains(out, seq) {
			t.Fatalf("máy tear-bar (cut=none) vẫn nhận lệnh cắt % x", seq)
		}
	}
}

// TestFinishing_AutoCutSendsNothing — máy tự cắt mà còn gửi lệnh cắt thì ra một
// tờ TRẮNG sau mỗi phiếu. Mỗi phiếu. Cả ngày.
func TestFinishing_AutoCutSendsNothing(t *testing.T) {
	p := printer.DefaultProfile()
	p.Finishing.Cut.AutoCutPerJob = true

	out := renderReceiptWith(t, withFinishing(p))

	for _, seq := range [][]byte{escpos.Cut, {0x1D, 0x56, 0x00}, {0x1D, 0x56, 0x01}} {
		if bytes.Contains(out, seq) {
			t.Fatalf("máy auto_cut_per_job vẫn nhận lệnh cắt % x — mỗi phiếu kèm một tờ trắng", seq)
		}
	}
}

func withFinishing(p printer.Profile) PrintRenderProfile {
	f := p.FinishingSpec()

	return PrintRenderProfile{Columns: 48, Finishing: &f}
}

func tailOf(b []byte) []byte {
	if len(b) < 8 {
		return b
	}

	return b[len(b)-8:]
}

// #1950 để lại một lỗ: ca "chưa cấu hình profile" được ghim bằng một
// PrintRenderProfile DỰNG TAY (`PrintRenderProfile{Columns: 48}`), mà không
// đường in thật nào dựng như thế. Mọi call site đi qua
// `PrintRenderProfileFor`, và hàm đó gán `Finishing` VÔ ĐIỀU KIỆN — nên nhánh
// nil mà test an toàn kia bảo vệ là nhánh production không bao giờ chạy.
//
// Hệ quả trên máy thật: `printers.model_profile` NULL → `ParseProfile("")` →
// `DefaultProfile()` = `escpos_generic` = `gs_v_full` → phiếu nhận `GS V 0`.
// Star mC-Print3 ở chế độ StarPRNT **bỏ qua GS V** (#438, ghi ngay trong
// escpos/encoder.go), nên máy nhận lệnh, không kêu lỗi, và **không cắt**.
//
// Ca này đi qua đúng hàm mà đường in thật gọi.
func TestFinishing_UnconfiguredPrinterStillCuts(t *testing.T) {
	// Đúng thứ manager dựng cho một hàng printers có model_profile NULL.
	out := renderReceiptWith(t, PrintRenderProfileFor(printer.ParseProfile(""), ""))

	if !bytes.HasSuffix(out, escpos.Cut) {
		t.Fatalf("máy chưa cấu hình profile phải giữ ESC d 3 như trước #1950 — "+
			"Star bỏ qua GS V và sẽ không cắt. Đuôi thật: % x", tailOf(out))
	}
}

// Cấu hình rồi thì profile vẫn phải thắng — đây là toàn bộ giá trị của #1950,
// và bản sửa không được nuốt nó.
func TestFinishing_ConfiguredProfileStillWins(t *testing.T) {
	// Qua ParseProfile như manager làm, chứ không gán cờ bằng tay — có thế mới
	// chứng minh được đường thật đánh dấu "đã có người mô tả máy này".
	star := printer.ParseProfile(`{"preset":"star_mcprint"}`)
	if !star.Configured {
		t.Fatal("giá trị đã lưu phải được đánh dấu Configured")
	}
	if PrintRenderProfileFor(star, "").Finishing == nil {
		t.Fatal("profile đã cấu hình phải mang spec kết thúc, không được nil")
	}

	epson := printer.ParseProfile(`{"preset":"epson_tm_i"}`)
	out := renderReceiptWith(t, PrintRenderProfileFor(epson, ""))
	if !bytes.HasSuffix(out, []byte{0x1D, 0x56, 0x01}) {
		t.Fatalf("epson_tm_i phải nhận cắt DÍNH (GS V 1), đuôi thật: % x", tailOf(out))
	}
}

// JSON hỏng KHÔNG phải một lời khai. ParseProfile đã trả về profile generic cho
// nó từ trước; điều phải giữ là nó cũng không được coi là "đã cấu hình", nếu
// không một blob rác sẽ lặng lẽ đổi lệnh cắt của cả quán.
func TestFinishing_CorruptProfileIsNotADeclaration(t *testing.T) {
	broken := printer.ParseProfile(`{ not json at all`)
	if broken.Configured {
		t.Fatal("blob không đọc được không được tính là đã cấu hình")
	}
	if PrintRenderProfileFor(broken, "").Finishing != nil {
		t.Fatal("blob không đọc được phải rơi về hành vi mặc định (nil)")
	}
}
