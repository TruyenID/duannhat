package escpos

// Finishing — plan-052 T1.4c (DESIGN §3b, #1166).
//
// What happens to the paper AFTER the content: how (and whether) it is cut,
// how far to feed first, and whether the cash drawer can be kicked at all.
//
// This is a plain value type, not the printer package's Profile, so the
// encoder stays a leaf package with no import back into `printer`. The caller
// translates its profile into one of these.
type Finishing struct {
	// CutMode: "none" | "gs_v_full" | "gs_v_partial" | "esc_d".
	CutMode string
	// FeedBeforeCut is a PHYSICAL quirk: the distance from print head to blade
	// differs per chassis, and too little feed slices the last line off the
	// slip. It is data so a shop can correct it without a release.
	FeedBeforeCut int
	// AutoCutPerJob: the machine cuts by itself at end of job, so sending a cut
	// command would produce a second, blank cut.
	AutoCutPerJob bool

	DrawerKickSupported bool
	DrawerPin           int
	DrawerOnMs          int
	DrawerOffMs         int
}

// Cut modes, mirrored from printer.Profile (kept as strings so this package
// needs no import of its own parent).
const (
	CutNone       = "none"
	CutGsVFull    = "gs_v_full"
	CutGsVPartial = "gs_v_partial"
	CutEscD       = "esc_d"
	// CutEscDPartial — ESC d 2, bản cắt DỞ của phương ngữ Star.
	//
	// #3059: `printers.cut_type` là lời khai của người lắp máy về việc quán
	// muốn cắt kiểu gì (`full` · `partial` · `none`), còn PHƯƠNG NGỮ (Star
	// `ESC d` hay ESC/POS `GS V`) là chuyện của máy và do `model_profile` khai.
	// Máy chưa ai mô tả thì phương ngữ mặc định là ESC d, nên `partial` ở đó
	// phải ra ESC d 2 — không phải `gs_v_partial`, thứ mà máy Star LỜ ĐI (#438)
	// và sẽ biến "cắt dở" thành "không cắt gì".
	CutEscDPartial = "esc_d_partial"
)

// ESC/POS GS V cut commands. Star machines in StarPRNT emulation IGNORE these
// (#438) and need ESC d instead, which is exactly why the command is chosen by
// profile rather than hard-coded.
var (
	gsVFullCut    = []byte{0x1D, 0x56, 0x00} // GS V 0
	gsVPartialCut = []byte{0x1D, 0x56, 0x01} // GS V 1
)

// Finish applies the profile's end-of-job behaviour.
//
// P-36 [the reason this exists]: `CutMode == none` sends NO cut command. A
// tear-bar machine has nothing to cut with, and some cheap firmware prints an
// unrecognised escape sequence as literal garbage onto the next customer's
// slip. So the honest action is to feed the paper clear of the head and stop.
func (e *Encoder) Finish(f Finishing) *Encoder {
	if f.CutMode == CutNone {
		// Still feed: without it the last lines sit inside the mechanism and
		// the operator tears through the total.
		e.Feed(maxInt(f.FeedBeforeCut, 2))
		return e
	}

	if f.AutoCutPerJob {
		// The machine cuts on its own. Sending a cut as well produces a second,
		// blank slip every single time.
		return e
	}

	switch f.CutMode {
	case CutEscD:
		// ESC d n already feeds n lines before cutting, so an extra Feed here
		// would double it.
		e.buf.Write(Cut)
	case CutEscDPartial:
		// Cùng lý do: ESC d 2 tự feed rồi cắt dở.
		e.buf.Write(PartialCut)
	case CutGsVPartial:
		e.Feed(f.FeedBeforeCut)
		e.buf.Write(gsVPartialCut)
	default: // CutGsVFull
		e.Feed(f.FeedBeforeCut)
		e.buf.Write(gsVFullCut)
	}

	return e
}

// KickDrawer pulses the cash-drawer pin using the profile's pin and timing.
//
// P-37: a machine that cannot kick gets NOTHING — and the caller is expected
// to have hidden the button. Silently emitting a pulse that does nothing is
// the failure mode where a cashier stands pressing a button while the queue
// grows, so this reports whether it actually did anything.
func (e *Encoder) KickDrawer(f Finishing) bool {
	if !f.DrawerKickSupported {
		return false
	}

	pin := byte(0x00) // ESC p m: m=0 → pin 2, m=1 → pin 5
	if f.DrawerPin == 5 || f.DrawerPin == 1 {
		pin = 0x01
	}

	// ESC p m t1 t2 — pulse widths in units of 2 ms, clamped to the one-byte
	// range the command actually carries.
	e.buf.Write([]byte{0x1B, 0x70, pin, pulseUnits(f.DrawerOnMs), pulseUnits(f.DrawerOffMs)})
	return true
}

func pulseUnits(ms int) byte {
	units := ms / 2
	if units < 1 {
		units = 1
	}
	if units > 255 {
		units = 255
	}
	return byte(units)
}

func maxInt(a, b int) int {
	if a > b {
		return a
	}
	return b
}
