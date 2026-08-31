package service

import (
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// TablePaidInfo is the payload for the "table paid" staff slip. The handler
// builds it from the local order row and hands it to FormatTablePaid for pure,
// testable ESC/POS rendering — same split as FormatShiftOpenReport.
type TablePaidInfo struct {
	TableNumber string // e.g. "12" / "A-3" — appears in the headline message
	OrderCode   string // full order code; only the suffix is printed
	PaidAt      string // preformatted "2006/01/02 15:04" in shop tz (optional)
}

// FormatTablePaid renders the tiny "which table just paid" staff slip printed
// to the kitchen / hold station when a dine-in order is settled by an online
// (kiosk / QR) self-payment. The meta block (store name, date/time, So HD) sits
// at the top like the receipt header; the glanceable headline — "ĐÃ THANH TOÁN
// BÀN A-01" — sits below the splitter so a passing runner reads it at a glance.
//
// Layout (42-col paper):
//
//	{StoreName}
//	2026/07/20 14:32
//	So HD:                                004
//	- - - - - - - - - - - - - - - - - - - - -
//	DA THANH TOAN BAN A-01                    (bold, double-height)
func FormatTablePaid(config PrintJobConfig, info TablePaidInfo) []byte {
	w := config.PaperWidth
	if w == 0 {
		w = 42
	}
	L := tablePaidLabelsFor(config.Locale)

	e := escpos.New()
	e.Align(escpos.AlignLeft)
	// Center the w-wide layout within a wider printer (e.g. 42 cols of content on
	// a 48-col 80mm head) so the left and right margins match — same plumbing the
	// receipt / kitchen ticket use via config.PhysicalWidth.
	e.SetLeftMargin(config.leftMargin(w))

	// ─── top meta block: store name, date/time, So HD — mirrors the receipt ───
	if name := strings.TrimSpace(config.StoreName); name != "" {
		e.Bold(true)
		e.Line(name)
		e.Bold(false)
	}
	// Full date/time (2006/01/02 15:04) — same shape as the receipt's date line.
	if paidAt := strings.TrimSpace(info.PaidAt); paidAt != "" {
		e.Line(paidAt)
	}
	// So HD as a right-aligned footer row (label left, code right) — the same
	// layout the receipt uses for its OrderNo row, so the two cross-reference.
	if code := OrderCodeSuffix(strings.TrimSpace(info.OrderCode)); code != "" {
		label := L.Order + ":"
		gap := max(w-displayWidth(label)-displayWidth(code), 1)
		e.Line(label + spaces(gap) + code)
	}

	// ─── splitter ───
	e.Line(dashedLine(w))

	// ─── headline: "DA THANH TOAN BAN A-01" — bold, double-height, one row ───
	// Double-height is ×2 tall but ×1 wide, so the whole message stays on a single
	// row of 42-col paper. This is the whole point of the slip: a runner reads it
	// at a glance.
	table := strings.TrimSpace(info.TableNumber)
	if table == "" {
		table = "-"
	}
	e.Bold(true)
	e.Size(escpos.DoubleHeight)
	e.Line(L.Title + " " + L.Table + " " + table)
	e.Size(escpos.NormalSize)
	e.Bold(false)

	e.Feed(1)
	e.FullCut()
	return e.Bytes()
}
