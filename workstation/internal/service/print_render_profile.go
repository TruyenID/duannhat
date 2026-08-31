package service

import (
	"github.com/dxs-platform/workstation-app/internal/printer"
)

// plan-053 M3 (#1171) ↔ plan-052 M1 (#1166) — the join between the two tracks.
//
// The renderer needs three facts about a printer: how wide it is, which roll it
// carries, and whether a given block of text can be drawn natively. plan-052's
// capability profile already answers all three, per MODEL rather than per
// branch-in-a-formatter, which is the whole point of that track. So the
// renderer decides nothing about the hardware; it asks.
//
// This adapter READS `internal/printer` and never writes to it: the two tracks
// share a boundary, not an implementation.
func PrintRenderProfileFor(p printer.Profile, paper string) PrintRenderProfile {
	// #1969 — no paper named means NO WIDTH CLAIM.
	//
	// This used to default `paper` to "80mm" and then read `p.Columns["80mm"]`.
	// `Profile.Columns` is a CAPABILITY TABLE — how many characters the machine
	// fits at each roll size, `{"58mm": 32, "80mm": 48}` — not a statement about
	// which roll is currently loaded. Reading it because the caller said nothing
	// turned a table of what the machine CAN do into an assertion about what it
	// IS doing.
	//
	// `resolvePrintWidth` puts the profile ABOVE the job config, so that guess of
	// 48 beat the `PaperWidth: 42` every call site actually sets, and every slip
	// — receipts, kitchen tickets, 精算, invoices — rendered six columns wider
	// than the paper. Line ends wrapped onto the next row.
	//
	// The clinching measurement: all 13 call sites pass `""`, and 42 is not any
	// preset's column count. For this fleet the capability table cannot be the
	// source of the width; the job config is.
	//
	// So a width is claimed only when the caller NAMES a roll — then the pairing
	// is honest: the caller says which paper, the machine says how many columns
	// fit on it.
	out := PrintRenderProfile{
		Paper:            paper,
		TextMode:         PrintTextNative,
		TextModeForBlock: blockTextModeFrom(p),
	}
	if paper != "" {
		out.Columns = p.Columns[paper]
	}

	// #1950 — mang theo spec kết thúc tờ giấy. Trước bài đó `PrintRenderProfile`
	// bỏ mất nó, nên renderer cắt MÙ bằng `FullCut()`: máy khai `gs_v_partial`
	// vẫn bị cắt rời, máy `none` vẫn nhận lệnh cắt, máy tự cắt vẫn nhả thêm một
	// tờ trắng. Ba thứ đó `escpos.Finish` xử đúng từ lâu — chỉ là không ai gọi
	// nó ngoài thuật sĩ cài máy in.
	//
	// CHỈ khi profile được ai đó cấu hình thật. #1950 dựa vào `Finishing == nil`
	// để giữ nguyên hành vi cho quán chưa cấu hình, nhưng hàm này gán nó VÔ ĐIỀU
	// KIỆN — nên nhánh nil ấy production không bao giờ chạy, và test an toàn của
	// nó dựng `PrintRenderProfile` bằng tay nên không thấy. Thực tế:
	// `model_profile` NULL → `DefaultProfile()` = `escpos_generic` = `gs_v_full`
	// → phiếu nhận `GS V 0`, mà Star mC-Print3 ở chế độ StarPRNT **bỏ qua GS V**
	// (#438, ghi ngay trong escpos/encoder.go). Máy nhận lệnh, không báo lỗi, và
	// không cắt.
	//
	// Một giá trị mặc định KHÔNG phải một lời khai. Chưa ai mô tả máy thì giữ
	// đúng byte của hôm qua và để `escpos.Cut` (ESC d 3) đi ra — đúng thứ #1950
	// tự khai là sẽ làm.
	if p.Configured {
		finishing := p.FinishingSpec()
		out.Finishing = &finishing
	}
	if p.TextMode == printer.TextModeRaster {
		out.TextMode = PrintTextRaster
	}
	return out
}

// blockTextModeFrom defers the native-vs-raster ruling to the profile.
//
// `Profile.TextModeFor` takes the block's SOURCE TEXT and, in `auto`, asks one
// question of it: does it contain a character outside this machine's codepages?
// By the time the renderer has a segment, that text is already encoded for the
// printer — so the question is asked here in the only form that survives
// encoding (a byte ≥ 0x80 is a multi-byte glyph) and then handed to the profile
// as a representative probe.
//
// Splitting it this way keeps ONE owner for the rule. Re-deriving "kanji ROM ⇒
// native, otherwise raster" locally would mean plan-052 could change the policy
// for every printer in the fleet and this renderer would quietly keep the old
// one.
func blockTextModeFrom(p printer.Profile) func(string, []byte) PrintTextMode {
	return func(_ string, content []byte) PrintTextMode {
		probe := "A"
		for _, b := range content {
			if b >= 0x80 {
				probe = "漢"
				break
			}
		}
		if p.TextModeFor(probe) == printer.TextModeRaster {
			return PrintTextRaster
		}
		return PrintTextNative
	}
}
