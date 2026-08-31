package service

import (
	"bytes"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// The order code and the table number are the two fields staff SCAN for rather
// than read — the kitchen matches a plate to a ticket by them, the runner finds
// the table by them. Both print bold and enlarged on every slip carrying them,
// on both the legacy formatters and the plan-053 renderer.
//
// These tests exist because the treatment is invisible to every other gate here.
// The golden hashes lock the byte stream, but a hash says "changed", never
// "changed into what" — regenerate it and an accidental revert to small text is
// recorded as the new truth without a word. What follows names the intent.

// emphasisSpan is one run the slip prints bold and enlarged, plus which rung of
// the ladder it landed on.
type emphasisSpan struct {
	text string
	wide bool // true → ×2 both ways; false → ×2 height only
}

// emphasisedSpans returns every emphasised run on a slip. Plain bold does not
// match: the kitchen's order note is bold too, and it is not an identifier.
func emphasisedSpans(t *testing.T, b []byte) []emphasisSpan {
	t.Helper()
	shut := append(append([]byte{}, escpos.NormalSize...), escpos.BoldOff...)

	var out []emphasisSpan
	rest := b
	for {
		i, size := nextEmphasisOpen(rest)
		if i < 0 {
			return out
		}
		rest = rest[i+len(escpos.BoldOn)+len(size):]
		j := bytes.Index(rest, shut)
		if j < 0 {
			t.Fatalf("emphasis opened but never closed — the rest of the slip would print enlarged")
		}
		// The span may re-enter normal size mid-way (the kitchen row prints the
		// gap between its two fields unscaled), so strip commands rather than
		// decoding the raw bytes.
		txt := decodeSJIS(t, stripESCPOSCommands(rest[:j]))
		out = append(out, emphasisSpan{
			text: strings.Join(strings.Fields(txt), " "),
			wide: bytes.Equal(size, escpos.DoubleSize),
		})
		rest = rest[j+len(shut):]
	}
}

func emphasisedTexts(t *testing.T, b []byte) []string {
	t.Helper()
	var out []string
	for _, s := range emphasisedSpans(t, b) {
		out = append(out, s.text)
	}
	return out
}

// nextEmphasisOpen finds the next `bold + enlarge` pair and reports which
// enlargement it used.
//
// It takes whichever rung appears FIRST in the stream, not a preferred one:
// rows step down independently, so a slip whose 伝票 fell back to height-only
// while its 卓 kept ×2 would otherwise have its first span skipped entirely and
// report as if that row carried no emphasis at all.
func nextEmphasisOpen(b []byte) (int, []byte) {
	at, found := -1, []byte(nil)
	for _, size := range [][]byte{escpos.DoubleSize, escpos.DoubleHeight} {
		open := append(append([]byte{}, escpos.BoldOn...), size...)
		if i := bytes.Index(b, open); i >= 0 && (at < 0 || i < at) {
			at, found = i, size
		}
	}
	return at, found
}

// printedSegment is a run of text printed under one character-expansion state,
// together with the column it starts at.
type printedSegment struct {
	text     string
	col      int  // starting column, enlargement accounted for
	cols     int  // columns occupied
	enlarged bool // any expansion at all (height, width, or both)
}

// slipLines splits a slip into lines of size-aware segments.
//
// This is the measurement the whole width decision rests on, and it cannot be
// taken from the decoded text: stripping commands and counting characters
// reports a ×2-width line at half its true width, which is precisely the blind
// spot that would let an overflowing slip pass.
func slipLines(t *testing.T, b []byte) [][]printedSegment {
	t.Helper()

	var (
		lines      [][]printedSegment
		cur        []printedSegment
		buf        []byte
		wide, tall bool
		col        int
	)
	flush := func() {
		if len(buf) == 0 {
			return
		}
		txt := decodeSJIS(t, buf)
		f := 1
		if wide {
			f = 2
		}
		n := displayWidth(txt) * f
		cur = append(cur, printedSegment{text: txt, col: col, cols: n, enlarged: wide || tall})
		col += n
		buf = buf[:0]
	}
	for i := 0; i < len(b); {
		if n := escposCommandLen(b[i:]); n > 0 {
			// ESC i n1 n2 — n1 is HEIGHT expansion, n2 is WIDTH.
			if b[i] == 0x1B && b[i+1] == 0x69 {
				flush()
				tall, wide = b[i+2] == 0x01, b[i+3] == 0x01
			}
			i += n
			continue
		}
		if b[i] == '\n' {
			flush()
			lines = append(lines, cur)
			cur, col = nil, 0
			i++
			continue
		}
		buf = append(buf, b[i])
		i++
	}
	flush()
	if len(cur) > 0 {
		lines = append(lines, cur)
	}
	return lines
}

// printedColumns returns the true column width of every printed line.
func printedColumns(t *testing.T, b []byte) []int {
	t.Helper()
	lines := slipLines(t, b)
	out := make([]int, 0, len(lines))
	for _, line := range lines {
		n := 0
		for _, s := range line {
			n += s.cols
		}
		out = append(out, n)
	}
	return out
}

// columnStartsOf locates each label on the header line, searched in order so a
// label carrying a space (the Vietnamese "Cach dat") is still found as one unit
// rather than split into two columns.
func columnStartsOf(t *testing.T, line string, labels []string) []int {
	t.Helper()
	out := make([]int, 0, len(labels))
	from := 0
	for _, l := range labels {
		i := strings.Index(line[from:], l)
		if i < 0 {
			t.Fatalf("label %q not found in header %q", l, line)
		}
		out = append(out, displayWidth(line[:from+i]))
		from += i + len(l)
	}
	return out
}

// valueRowColumnStarts returns the starting column of each enlarged field on the
// slip's first enlarged line — the kitchen meta value row.
func valueRowColumnStarts(t *testing.T, b []byte) []int {
	t.Helper()
	for _, line := range slipLines(t, b) {
		var out []int
		for _, s := range line {
			if s.enlarged && strings.TrimSpace(s.text) != "" {
				out = append(out, s.col)
			}
		}
		if len(out) > 0 {
			return out
		}
	}
	t.Fatal("slip has no enlarged line")
	return nil
}

// Only the two IDENTIFIER columns of the kitchen block are enlarged.
//
// 提供 and 番号 share the row but not the treatment: enlarging every field on a
// line emphasises nothing, and the two staff actually scan for are the order
// code and the table.
func TestKitchenTicket_OnlyIdentifierColumnsAreEmphasised(t *testing.T) {
	o := &Order{OrderCode: "WS-019e-20260720-004", TableNumber: "A-1"}
	items := []Item{{MenuItemName: "Bun ga", Quantity: 1, UnitPrice: 1000}}

	runs := emphasisedTexts(t, FormatKitchenTicket(o, items, 319, PrintJobConfig{PaperWidth: 48}))
	if len(runs) != 2 {
		t.Fatalf("want the order code and the table emphasised, got %d: %q", len(runs), runs)
	}
	if runs[0] != "004" || runs[1] != "A-1" {
		t.Errorf("emphasised = %q, want [004 A-1]", runs)
	}
	for _, r := range runs {
		if strings.Contains(r, "319") || strings.Contains(r, "店内") {
			t.Errorf("提供 / 番号 must stay small, got %q", r)
		}
	}
}

// A takeaway ticket drops the 卓 column entirely, so the order code must still
// be emphasised rather than quietly demoted along with the column that
// disappeared — and the payment word that replaces 卓 is emphasised WITH it, at
// the same size, because the two are what a pickup counter reads (chủ dự án
// 2026-08-17).
//
// The pair is asserted in ORDER: the code identifies the bag, the payment word
// says whether to take money for it. A run list that swapped them would mean
// the payment row had drifted above the identity block.
func TestKitchenTicket_TakeawayEmphasisesCodeAndPaymentState(t *testing.T) {
	o := &Order{OrderCode: "WS-019e-20260720-004", OrderType: "takeaway", TotalAmount: 1000}
	items := []Item{{MenuItemName: "Bun ga", Quantity: 1, UnitPrice: 1000}}

	runs := emphasisedTexts(t, FormatKitchenTicket(o, items, 319, PrintJobConfig{PaperWidth: 48}))
	if len(runs) != 2 || runs[0] != "004" || runs[1] != "未払" {
		t.Fatalf("takeaway must emphasise the order code then the payment state, got %q", runs)
	}

	// Settled prints the other word at the same emphasis — the size is a
	// property of the SLOT, not of which value landed in it.
	o.PaidAmount = 1000
	runs = emphasisedTexts(t, FormatKitchenTicket(o, items, 319, PrintJobConfig{PaperWidth: 48}))
	if len(runs) != 2 || runs[0] != "004" || runs[1] != "済み" {
		t.Fatalf("settled takeaway must emphasise the order code then 済み, got %q", runs)
	}
}

// The header labels must sit at the same columns as the values beneath them.
// This is the property the computed column widths exist for: the values are
// twice as wide as their labels, so a layout that sized columns from the labels
// alone (or from fixed quarters) would leave the two rows describing different
// things.
func TestKitchenTicket_HeaderColumnsTrackTheEnlargedValues(t *testing.T) {
	o := &Order{OrderCode: "WS-019e-20260720-004", TableNumber: "C-07"}
	items := []Item{{MenuItemName: "Bun ga", Quantity: 1, UnitPrice: 1000}}

	for _, locale := range []string{"ja", "en", "vi"} {
		t.Run(locale, func(t *testing.T) {
			slip := FormatKitchenTicket(o, items, 319,
				PrintJobConfig{PaperWidth: 42, PhysicalWidth: 48, Locale: locale})

			labels := printLabelsFor(locale)
			// Located by OrderMethod, not OrderNo: the slip's title is 厨房伝票,
			// which CONTAINS the 伝票 label, so searching for that would match
			// the header line of the store block instead of this table.
			header := printedLineContaining(t, slip, labels.OrderMethod)
			if header == "" {
				t.Fatalf("no header row carrying %q", labels.OrderMethod)
			}
			// Only the two IDENTIFIER columns are enlarged, so only those two
			// can be located by their size command — and they are the two the
			// alignment actually matters for. 提供 / 番号 print at normal size
			// and merge into their neighbours' text run, which is why this
			// checks the tail of the row rather than all four cells.
			all := columnStartsOf(t, header,
				[]string{labels.OrderMethod, labels.TicketSeq, labels.OrderNo, labels.Table})
			wantAt := all[2:]
			gotAt := valueRowColumnStarts(t, slip)
			if len(wantAt) != len(gotAt) {
				t.Fatalf("want %d emphasised columns, got %d", len(wantAt), len(gotAt))
			}
			for i := range wantAt {
				if wantAt[i] != gotAt[i] {
					t.Errorf("column %d: label starts at %d, value at %d", i, wantAt[i], gotAt[i])
				}
			}
		})
	}
}

func TestBill_OrderNoAndTableRowsAreEmphasised(t *testing.T) {
	o := sampleOrder()
	out := FormatRunnerTicket(o, o.Items, 0,
		PrintJobConfig{StoreName: "S", PaperWidth: 48, TaxRate: 10})

	runs := emphasisedTexts(t, out)
	if len(runs) != 2 {
		t.Fatalf("want the 伝票 and 卓 values emphasised and nothing else, got %d: %q", len(runs), runs)
	}
	if runs[0] != "004" {
		t.Errorf("伝票 value = %q, want the order-code suffix %q", runs[0], "004")
	}
	if runs[1] != "C-07" {
		t.Errorf("卓 value = %q, want %q", runs[1], "C-07")
	}
}

// THE RECEIPT IS THE EXCEPTION, and it is an exception about WHO HOLDS THE
// PAPER. It is the copy the customer walks out with, while the order code and
// table number are what staff scan for — so it keeps the sizing it always had:
// the order number plain, the table bold, neither enlarged.
//
// Asserted as "no enlarged span anywhere", not as a golden hash, because the
// hash cannot tell this decision apart from an accident.
func TestReceipt_KeepsItsOriginalSizing(t *testing.T) {
	o := sampleOrder()
	o.PaidAmount = 1780
	out := FormatPaidTicket(o, o.Items, 0,
		PrintJobConfig{StoreName: "S", PaperWidth: 42, PhysicalWidth: 48, TaxRate: 10},
		PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: 1780})

	if runs := emphasisedTexts(t, out); len(runs) != 0 {
		t.Errorf("the receipt must carry no enlarged field, got %q", runs)
	}
	for _, size := range [][]byte{escpos.DoubleSize, escpos.DoubleWidth, escpos.DoubleHeight} {
		if bytes.Contains(out, size) {
			t.Errorf("receipt selects an expansion command %q — it must stay at normal size", size)
		}
	}
	// Bold on the table row is PRE-EXISTING and stays: reverting the size is not
	// a licence to strip what was there before.
	table := printedLineContaining(t, out, printLabelsFor("").Table)
	if table == "" || !strings.Contains(table, "C-07") {
		t.Fatalf("no 卓 row on the receipt, got %q", table)
	}
	if !bytes.Contains(out, escpos.BoldOn) {
		t.Error("the table row lost the bold it had before this change")
	}
}

// ─── the ×2-width ladder ──────────────────────────────────────────────────
//
// Height is free — it moves no column. Width is not: it doubles the column cost
// of every glyph, so it is taken only when it has been MEASURED to fit, and the
// slip drops back to height-only when it has not.

func TestOrderIdentifiers_UseFullDoubleSizeWhenItFits(t *testing.T) {
	o := sampleOrder() // table "C-07", order code suffix "004"
	o.PaidAmount = 1780
	// The shop's real geometry: a 42-column layout centred on 80mm paper.
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 42, PhysicalWidth: 48, TaxRate: 10}

	slips := map[string][]byte{
		"kitchen": FormatKitchenTicket(o, o.Items, 319, cfg),
		"runner":  FormatRunnerTicket(o, o.Items, 0, cfg),
	}
	for name, slip := range slips {
		t.Run(name, func(t *testing.T) {
			for _, s := range emphasisedSpans(t, slip) {
				if !s.wide {
					t.Errorf("%q fits at ×2 both ways here — it should not have fallen back", s.text)
				}
			}
		})
	}
}

// The fallback, on the paper and the data that force it: 58mm is the narrowest
// the fleet runs, and a table number is free text a shop can make as long as it
// likes.
//
// Asserted per SPAN, not per slip. A bill carries two independent rows and the
// short one keeps its ×2 while the long one steps down — checking the slip for
// any DoubleSize byte would call that a pass no matter which row overflowed.
func TestOrderIdentifiers_FallBackToHeightWhenWidthWouldOverflow(t *testing.T) {
	t.Run("kitchen/long table on 58mm", func(t *testing.T) {
		o := &Order{OrderCode: "WS-019e-20260720-004", TableNumber: "TERRACE-12"}
		items := []Item{{MenuItemName: "Bun ga", Quantity: 1, UnitPrice: 1000}}

		// Two spans now: the order code and the table are emphasised
		// independently, and 提供 / 番号 are not emphasised at all.
		spans := emphasisedSpans(t, FormatKitchenTicket(o, items, 319, PrintJobConfig{PaperWidth: 32}))
		if len(spans) != 2 {
			t.Fatalf("want the 伝票番号 and テーブル spans, got %+v", spans)
		}
		for _, s := range spans {
			if s.wide {
				t.Errorf("a 10-character table at ×2 width cannot fit 32 columns — %q must fall back", s.text)
			}
		}
	})

	// OrderCodeSuffix returns the WHOLE code when it carries no "-", so a slip
	// can legitimately be asked to print a very long identifier.
	t.Run("bill/undashed order code on 58mm", func(t *testing.T) {
		o := sampleOrder()
		o.OrderCode = "WS0192026072000400491"
		spans := emphasisedSpans(t, FormatRunnerTicket(o, o.Items, 0,
			PrintJobConfig{StoreName: "S", PaperWidth: 32, TaxRate: 10}))

		if len(spans) != 2 {
			t.Fatalf("want the 伝票 and 卓 spans, got %+v", spans)
		}
		if spans[0].wide {
			t.Error("a 21-character order code at ×2 width cannot fit 32 columns — must fall back")
		}
		// The short row is judged on its own and keeps the better treatment.
		if !spans[1].wide {
			t.Error(`"C-07" still fits at ×2 — one long row must not demote the other`)
		}
	})
}

// THE contract of the width upgrade: it may never be the reason a line runs off
// the paper. A slip that overflows WRAPS — it does not report an error — so
// nothing else in this package would notice.
//
// Stated as an implication rather than a flat "nothing overflows", because the
// flat version is not true here and never was: on 58mm the four-column kitchen
// row already overflowed at NORMAL size for a 10-character table (measured:
// lead 16 + code 3 + gap 4 + table 10 = 33 > 32, byte-identical before this
// change). That is a pre-existing narrow-paper defect and fixing it is a layout
// question, not a sizing one. What this gate forbids is the new failure: taking
// ×2 width on a line that cannot carry it.
func TestOrderIdentifiers_WidthUpgradeNeverCausesOverflow(t *testing.T) {
	tables := []string{"C-07", "A-1", "TERRACE-12", "テラス席１２"}
	codes := []string{"WS-019e-20260720-004", "WS0192026072000400491"}
	papers := []struct {
		name            string
		paper, physical int
		limit           int
	}{
		{name: "58mm", paper: 32, limit: 32},
		{name: "80mm", paper: 42, physical: 48, limit: 48},
		{name: "80mm-full", paper: 48, physical: 48, limit: 48},
	}

	for _, p := range papers {
		for _, table := range tables {
			for _, code := range codes {
				t.Run(p.name+"/"+table+"/"+code, func(t *testing.T) {
					cfg := PrintJobConfig{
						StoreName: "ベト屋", PaperWidth: p.paper,
						PhysicalWidth: p.physical, TaxRate: 10, Locale: "ja",
					}
					o := sampleOrder()
					o.OrderCode = code
					o.TableNumber = table
					o.PaidAmount = 1780

					slips := map[string][]byte{
						"kitchen": FormatKitchenTicket(o, o.Items, 319, cfg),
						"runner":  FormatRunnerTicket(o, o.Items, 0, cfg),
						"receipt": FormatPaidTicket(o, o.Items, 0, cfg,
							PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: 1780}),
					}
					for name, slip := range slips {
						widest := 0
						for _, cols := range printedColumns(t, slip) {
							widest = max(widest, cols)
						}
						if widest <= p.limit {
							continue
						}
						for _, s := range emphasisedSpans(t, slip) {
							if s.wide {
								t.Errorf("%s runs to %d columns on %d-column paper WHILE printing %q at ×2 width — the ladder should have fallen back",
									name, widest, p.limit, s.text)
							}
						}
					}
				})
			}
		}
	}
}

// ─── top padding ──────────────────────────────────────────────────────────

// Enlarging 伝票 and 卓 buys nothing if the rail clip is sitting on them, so
// every order-backed slip opens with blank leading. This is asserted on the
// BYTES rather than on the decoded text: the whole point is paper travelling
// past the head before the first glyph.
func TestOrderSlips_OpenWithTopPadding(t *testing.T) {
	o := sampleOrder()
	o.PaidAmount = 1780
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 42, PhysicalWidth: 48, TaxRate: 10}

	// #3082 — số dòng trắng KHÁC NHAU theo kind, và con số phải nằm ở đây chứ
	// không suy ra từ chính lượt in.
	//
	// Bếp cần 6 vì thực tế ở quán: 3 dòng vẫn bị thanh kẹp che mất dòng đầu
	// (chủ dự án 2026-08-17). Các phiếu khách giữ 3 — chúng không bị kẹp.
	//
	// Vế "không THỪA" bên dưới quan trọng ngang vế "đủ": không có nó thì mỗi lần
	// ai đó thấy phiếu bị che sẽ cộng thêm một dòng, và không gì cản.
	slips := map[string]struct {
		bytes   []byte
		padding int
	}{
		"kitchen": {FormatKitchenTicket(o, o.Items, 319, cfg), slipTopPadding + kitchenExtraTopFeed},
		"runner":  {FormatRunnerTicket(o, o.Items, 0, cfg), slipTopPadding},
		// The receipt keeps its original SIZING but still gets the leading:
		// padding is about the clip, not about legibility.
		"receipt": {FormatPaidTicket(o, o.Items, 0, cfg,
			PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: 1780}), slipTopPadding},
	}
	for name, tc := range slips {
		t.Run(name, func(t *testing.T) {
			cols := printedColumns(t, tc.bytes)
			if len(cols) < tc.padding {
				t.Fatalf("slip has only %d lines", len(cols))
			}
			for i := range tc.padding {
				if cols[i] != 0 {
					t.Errorf("line %d must be blank leading, got %d columns", i, cols[i])
				}
			}
			if cols[tc.padding] == 0 {
				t.Errorf("padding is %d lines but line %d is also blank — more than asked for",
					tc.padding, tc.padding)
			}
		})
	}
}
