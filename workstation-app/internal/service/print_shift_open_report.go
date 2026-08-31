package service

import (
	"fmt"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// ShiftOpenDenomLine is one 金種 (denomination) row of the opening cash count:
// the face value, how many pieces were counted, and the subtotal (value ×
// quantity). The renderer formats Value with the currency unit so all money
// formatting lives in one layer.
type ShiftOpenDenomLine struct {
	Value    int // face value, e.g. 10000 → rendered "10,000円"
	Quantity int // 枚数 — pieces counted
	Subtotal int // 金額 — value × quantity in the currency's whole unit
}

// ShiftOpenReportInfo is the fully-aggregated shift-OPEN (レジ開け) payload. The
// handler builds it from local SQLite (till_sessions + till_cash_denomination_
// counts + denominations) and hands it to FormatShiftOpenReport for pure,
// testable ESC/POS rendering — same split as FormatShiftReport.
type ShiftOpenReportInfo struct {
	DeviceName string // 端末 — POS terminal name (optional)
	Operator   string // 担当者 — falls back to the locale "(not set)" when empty
	OpenedAt   string // preformatted "2006/01/02 15:04"

	Denominations []ShiftOpenDenomLine // opening cash breakdown, high value first
	OpeningFloat  int                  // 合計 — total cash in drawer at open
	Note          string               // 備考 — cashier's opening note (free text)

	Currency string // e.g. "JPY" → 円 suffix
}

// FormatShiftOpenReport renders the shift-OPEN (レジ開け) thermal slip.
//
// Layout (42-col paper, matches FormatShiftReport conventions):
//
//	          {StoreName}
//	            開始
//	                              端末 POS-01
//	                              担当者 田中
//	                 開始時刻 2026/07/06 14:00
//	- - - - - - - - - - - - - - - - - - - - -
//	金種                     枚数        金額
//	  10,000円               1枚      10,000円
//	   5,000円               0枚           0円
//	   1,000円               3枚       3,000円
//	- - - - - - - - - - - - - - - - - - - - -
//	合計                             13,000円
//	- - - - - - - - - - - - - - - - - - - - -
//	備考
//	  {note text, wrapped}
func FormatShiftOpenReport(config PrintJobConfig, info ShiftOpenReportInfo) []byte {
	w := config.PaperWidth
	if w == 0 {
		w = 42
	}
	L := openLabelsFor(config.Locale)
	unit := moneyUnit(info.Currency)

	money := func(n int) string {
		if n < 0 {
			return "-" + formatPrice(-n) + unit
		}
		return formatPrice(n) + unit
	}

	e := escpos.New()

	// row: label flush-left, value flush-right, ≥1 space gap (DISPLAY width so
	// CJK labels stay aligned).
	row := func(label, value string) {
		gap := max(w-displayWidth(label)-displayWidth(value), 1)
		e.Line(label + spaces(gap) + value)
	}
	padLeft := func(s string, width int) string {
		if dw := displayWidth(s); dw < width {
			return spaces(width-dw) + s
		}
		return s
	}
	const cntCol, amtCol = 6, 12
	// statValue builds the right-hand "{n}枚      {amount}" block shared by the
	// denomination rows and the column header.
	statValue := func(count int, amount string) string {
		return padLeft(fmt.Sprintf("%d%s", count, L.QtyUnit), cntCol) + padLeft(amount, amtCol)
	}
	sep := func() { e.Line(dashedLine(w)) }

	// ─── header: store name + title (double-size, centered) ───
	e.Align(escpos.AlignCenter)
	e.Bold(true)
	if name := strings.TrimSpace(config.StoreName); name != "" {
		nameSize := escpos.DoubleSize
		if displayWidth(name)*2 > w {
			nameSize = escpos.DoubleHeight
		}
		e.Size(nameSize)
		e.Line(name)
	}
	e.Size(escpos.DoubleSize)
	e.Line(L.Title)
	e.Size(escpos.NormalSize)
	e.Bold(false)

	// ─── meta block: label flush-left, value flush-right (full width) ───
	e.Align(escpos.AlignLeft)
	if d := strings.TrimSpace(info.DeviceName); d != "" {
		row(L.Device, d)
	}
	operator := strings.TrimSpace(info.Operator)
	if operator == "" {
		operator = L.OperatorNone
	}
	row(L.Operator, operator)
	if info.OpenedAt != "" {
		row(L.OpenedAt, info.OpenedAt)
	}

	// ─── denomination breakdown ───
	sep()
	// column header: 金種 (left) + 枚数 金額 (right-aligned in the stat columns).
	e.Line(L.DenomHeader + spaces(max(w-displayWidth(L.DenomHeader)-cntCol-amtCol, 1)) +
		padLeft(L.QtyHeader, cntCol) + padLeft(L.AmountHeader, amtCol))
	for _, d := range info.Denominations {
		row("  "+money(d.Value), statValue(d.Quantity, money(d.Subtotal)))
	}

	// ─── total ───
	sep()
	row(L.Total, money(info.OpeningFloat))

	// ─── opening note (wrapped) ───
	if note := strings.TrimSpace(info.Note); note != "" {
		sep()
		e.Line(L.Note)
		for _, line := range wrapText(note, w-2) {
			e.Line("  " + line)
		}
	}

	e.Feed(1)
	e.FullCut()
	return e.Bytes()
}

// wrapText breaks s into lines no wider than width DISPLAY columns. It packs on
// space boundaries where possible and hard-splits any single run longer than
// width (CJK notes have no spaces), so a free-text note never overflows the
// paper edge. Newlines in the input start a fresh line.
func wrapText(s string, width int) []string {
	if width < 1 {
		width = 1
	}
	var out []string
	for _, para := range strings.Split(s, "\n") {
		out = append(out, wrapParagraph(para, width)...)
	}
	return out
}

func wrapParagraph(para string, width int) []string {
	fields := strings.Fields(para)
	if len(fields) == 0 {
		return []string{""}
	}
	var lines []string
	var cur strings.Builder
	curW := 0
	flush := func() {
		if cur.Len() > 0 {
			lines = append(lines, cur.String())
			cur.Reset()
			curW = 0
		}
	}
	for _, word := range fields {
		for _, piece := range hardSplit(word, width) {
			pw := displayWidth(piece)
			switch {
			case curW == 0:
				cur.WriteString(piece)
				curW = pw
			case curW+1+pw <= width:
				cur.WriteByte(' ')
				cur.WriteString(piece)
				curW += 1 + pw
			default:
				flush()
				cur.WriteString(piece)
				curW = pw
			}
		}
	}
	flush()
	return lines
}

// hardSplit chops a single word into chunks each ≤ width display columns,
// respecting rune boundaries (never splits a multi-byte rune).
func hardSplit(word string, width int) []string {
	if displayWidth(word) <= width {
		return []string{word}
	}
	var chunks []string
	var cur strings.Builder
	curW := 0
	for _, r := range word {
		rw := displayWidth(string(r))
		if curW+rw > width && curW > 0 {
			chunks = append(chunks, cur.String())
			cur.Reset()
			curW = 0
		}
		cur.WriteRune(r)
		curW += rw
	}
	if cur.Len() > 0 {
		chunks = append(chunks, cur.String())
	}
	return chunks
}
