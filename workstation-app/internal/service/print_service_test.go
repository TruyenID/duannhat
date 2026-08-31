package service

import (
	"bytes"
	"strings"
	"testing"
)

func TestTaxBreakdown(t *testing.T) {
	cases := []struct {
		name     string
		total    int
		orderTax int
		rate     float64
		want     int
	}{
		// order.TaxAmount authoritative — used verbatim, rate ignored.
		{"prefers order tax", 1780, 162, 10, 162},
		// Tax-INCLUDED 10%: tax = 1780 - round(1780/1.1) = 1780 - 1618 = 162.
		{"tax-included 10pct", 1780, 0, 10, 162},
		// 8% 軽減税率: tax = 800 - round(800/1.08) = 800 - 741 = 59.
		{"tax-included 8pct", 800, 0, 8, 59},
		// rate 0 falls back to default 10%.
		{"zero rate falls back", 1100, 0, 0, 100},
		// non-positive total → 0.
		{"zero total", 0, 0, 10, 0},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := taxBreakdown(c.total, c.orderTax, c.rate); got != c.want {
				t.Errorf("taxBreakdown(%d,%d,%g) = %d, want %d", c.total, c.orderTax, c.rate, got, c.want)
			}
		})
	}
}

func sampleOrder() *Order {
	return &Order{
		ID:          "order-uuid-1",
		OrderCode:   "WS-019e-20260608-004",
		TableNumber: "C-07",
		TotalAmount: 1780,
		TaxAmount:   162,
		PaidAmount:  0,
		Items: []Item{
			{MenuItemName: "Bun ga la chanh", Quantity: 1, UnitPrice: 1780, Status: ItemStatusPending},
		},
	}
}

func TestFormatPaidTicket_NoQRAndPaidLabel(t *testing.T) {
	o := sampleOrder()
	o.PaidAmount = 1780
	cfg := PrintJobConfig{StoreName: "ベト屋", PaperWidth: 48, TaxRate: 10}

	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{
		PaymentMethod: "cash",
		AmountPaid:    1780,
	})

	text := decodeSJIS(t, out)
	if !strings.Contains(text, "支払済") {
		t.Error("paid ticket missing 支払済 title")
	}
	if !strings.Contains(text, "支払方法") || !strings.Contains(text, "cash") {
		t.Error("paid ticket missing payment method line")
	}
	if strings.Contains(text, "会計伝票") {
		t.Error("paid ticket should not carry the runner 会計伝票 title")
	}
	// QR uses the Star ESC GS y command prefix (1B 1D 79). Must be absent.
	if bytes.Contains(out, []byte{0x1B, 0x1D, 0x79}) {
		t.Error("paid ticket must not contain a QR code")
	}
}

// TestDisplayWidth_CombiningMarksAreZeroWidth proves a decomposed (NFD)
// Vietnamese branch name measures the same width as its precomposed (NFC) form.
// Before the fix each combining diacritic counted as an extra column, so an NFD
// name over-measured and got bumped onto its own line in the header.
func TestDisplayWidth_CombiningMarksAreZeroWidth(t *testing.T) {
	// "Chi nhánh Hà Nội" — NFC (precomposed) vs NFD (base letter + combining mark).
	nfc := "Chi nhánh Hà Nội"
	nfd := "Chi nhánh Hà Nội"
	if got := displayWidth(nfc); got != 16 {
		t.Fatalf("NFC width = %d, want 16", got)
	}
	if got := displayWidth(nfd); got != 16 {
		t.Fatalf("NFD width = %d, want 16 (combining marks must be zero-width)", got)
	}
}

// TestFormatPaidTicket_NFDBranchNameStaysOnHeaderLine proves an NFD Vietnamese
// branch name still shares the header line with the title on a 32-col printer
// (it fits at its true 16-col width) rather than dropping to its own line.
func TestFormatPaidTicket_NFDBranchNameStaysOnHeaderLine(t *testing.T) {
	o := sampleOrder()
	o.PaidAmount = 1780
	// NFD form: base letters + combining marks.
	branch := "Chi nhánh Hà Nội"
	cfg := PrintJobConfig{StoreName: branch, PaperWidth: 32, TaxRate: 10}

	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: 1780})

	// The branch name and the "支払済" title must land on the SAME line:
	// no line break between the end of the name and the title.
	firstLine := decodeSJIS(t, out)
	if i := strings.IndexByte(firstLine, '\n'); i >= 0 {
		firstLine = firstLine[:i]
	}
	if !strings.Contains(firstLine, "支払済") {
		t.Errorf("title dropped to a new line; first header line = %q", firstLine)
	}
}

// TestKitchenTicket_STTAndSoHD proves the kitchen ticket shows the incrementing
// daily count as "STT" and the order-code suffix as "So HD" (the same invoice
// number printed on the customer receipt), so the two slips cross-reference.
func TestKitchenTicket_STTAndSoHD(t *testing.T) {
	order := &Order{OrderType: "dine_in", TableNumber: "A-1", OrderCode: "WS-019e-20260608-004"}
	items := []Item{{MenuItemName: "Bun ga", Quantity: 1, UnitPrice: 1000}}

	for _, w := range []int{48, 32} {
		text := decodeSJIS(t, FormatKitchenTicket(order, items, 319, PrintJobConfig{PaperWidth: w}))
		for _, lbl := range []string{"提供", "番号", "伝票", "卓"} {
			if !strings.Contains(text, lbl) {
				t.Errorf("w=%d: missing header label %q", w, lbl)
			}
		}
		// 番号 = incrementing kitchen ticket number.
		if !strings.Contains(text, "319") {
			t.Errorf("w=%d: missing ticket number 319", w)
		}
		// 伝票 = order-code suffix, matching the receipt — not the ticket number.
		if !strings.Contains(text, "004") {
			t.Errorf("w=%d: 伝票 should show order-code suffix 004", w)
		}
	}
}

// A takeaway kitchen ticket carries NO table reference: neither the "Ban"/"卓"
// column header nor a table value (a pickup order has no table). A dine-in
// ticket keeps both.
func TestKitchenTicket_TakeawayDropsTable(t *testing.T) {
	items := []Item{{MenuItemName: "Bun ga", Quantity: 1, UnitPrice: 1000}}

	// Takeaway: even with a table value present it must be suppressed, and the
	// 卓 header column is gone.
	takeaway := &Order{OrderType: "takeaway", TableNumber: "T9", OrderCode: "WS-1-004"}
	ta := decodeSJIS(t, FormatKitchenTicket(takeaway, items, 1, PrintJobConfig{PaperWidth: 48}))
	if strings.Contains(ta, "卓") {
		t.Errorf("takeaway kitchen ticket must not print the 卓 table header:\n%s", ta)
	}
	if strings.Contains(ta, "T9") {
		t.Errorf("takeaway kitchen ticket must not print a table value:\n%s", ta)
	}
	// Sanity: the order-type label (持ち帰り) + So HD (004) survive.
	if !strings.Contains(ta, "持ち帰り") || !strings.Contains(ta, "004") {
		t.Errorf("takeaway ticket lost its order-type label or So HD:\n%s", ta)
	}

	// Dine-in keeps the table column header + value.
	dinein := &Order{OrderType: "dine_in", TableNumber: "A-1", OrderCode: "WS-1-004"}
	di := decodeSJIS(t, FormatKitchenTicket(dinein, items, 1, PrintJobConfig{PaperWidth: 48}))
	if !strings.Contains(di, "卓") || !strings.Contains(di, "A-1") {
		t.Errorf("dine-in kitchen ticket must keep the 卓 header + table value:\n%s", di)
	}
}

// The takeaway hold/serving slip is titled with the localized "Takeaway"
// (持ち帰り / TAKEAWAY / MANG VE), not the default "newly-added items" title —
// a takeaway order is a pickup, not a waiter round. Non-takeaway keeps the
// default title.
func TestFormatDeltaQRTicket_TakeawayTitle(t *testing.T) {
	items := []Item{{MenuItemName: "Pho", Quantity: 1, UnitPrice: 1000, Status: ItemStatusPending}}
	cases := []struct {
		locale    string
		wantTitle string // localized "Takeaway" the slip must now carry
		oldTitle  string // "newly-added items" title it must no longer use
	}{
		{"ja", "持ち帰り", "追加商品"},
		{"en", "TAKEAWAY", "NEW ITEMS"},
		{"vi", "MANG VE", "MON VUA THEM"},
	}
	for _, c := range cases {
		t.Run(c.locale, func(t *testing.T) {
			o := &Order{ID: "tk-1", OrderCode: "ORD-9", OrderType: "takeaway", TotalAmount: 1000, Items: items}
			out := decodeSJIS(t, FormatDeltaQRTicket(o, items, PrintJobConfig{PaperWidth: 48, Locale: c.locale}))
			if !strings.Contains(out, c.wantTitle) {
				t.Errorf("takeaway delta[%s] missing title %q:\n%s", c.locale, c.wantTitle, out)
			}
			if strings.Contains(out, c.oldTitle) {
				t.Errorf("takeaway delta[%s] must not use the %q title:\n%s", c.locale, c.oldTitle, out)
			}
		})
	}
	// Non-takeaway (spot) keeps the default 追加商品 title.
	spot := &Order{ID: "sp-1", OrderCode: "ORD-9", OrderType: "spot", TotalAmount: 1000, Items: items}
	out := decodeSJIS(t, FormatDeltaQRTicket(spot, items, PrintJobConfig{PaperWidth: 48, Locale: "ja"}))
	if !strings.Contains(out, "追加商品") {
		t.Errorf("non-takeaway delta must keep the 追加商品 title:\n%s", out)
	}
}

// The hold/serving slip (FormatDeltaQRTicket) and the full table bill
// (FormatRunnerTicket) drop the 卓 table row for a takeaway order — a pickup has
// no table (matches the kitchen ticket). Dine-in / spot keep it.
func TestBillTicket_TakeawayDropsTable(t *testing.T) {
	items := []Item{{MenuItemName: "Pho", Quantity: 1, UnitPrice: 1000, Status: ItemStatusPending}}
	cfg := PrintJobConfig{PaperWidth: 48, Locale: "ja"}

	takeaway := &Order{ID: "tk-1", OrderCode: "ORD-9", OrderType: "takeaway", TableNumber: "T9", TotalAmount: 1000, Items: items}
	for name, out := range map[string][]byte{
		"delta": FormatDeltaQRTicket(takeaway, items, cfg),
		"hold":  FormatRunnerTicket(takeaway, items, 0, cfg),
	} {
		text := decodeSJIS(t, out)
		if strings.Contains(text, "卓") {
			t.Errorf("takeaway %s slip must not print the 卓 table row:\n%s", name, text)
		}
		if strings.Contains(text, "T9") {
			t.Errorf("takeaway %s slip must not print a table value:\n%s", name, text)
		}
	}

	// Dine-in keeps the 卓 row + value.
	dinein := &Order{ID: "di-1", OrderCode: "ORD-9", OrderType: "dine_in", TableNumber: "A-1", TotalAmount: 1000, Items: items}
	di := decodeSJIS(t, FormatRunnerTicket(dinein, items, 0, cfg))
	if !strings.Contains(di, "卓") || !strings.Contains(di, "A-1") {
		t.Errorf("dine-in hold slip must keep the 卓 row + table value:\n%s", di)
	}
}

// A missing order code must not print a blank So HD column.
func TestKitchenTicket_SoHDFallbackWhenNoCode(t *testing.T) {
	order := &Order{OrderType: "takeaway", TableNumber: "-"}
	items := []Item{{MenuItemName: "Bun ga", Quantity: 1, UnitPrice: 1000}}
	out := FormatKitchenTicket(order, items, 5, PrintJobConfig{PaperWidth: 48})
	if !bytes.Contains(out, []byte("-")) {
		t.Error("So HD should fall back to '-' when order code is empty")
	}
}

// TestFormatPaidTicket_CentersOnPhysicalWidth proves a 42-col layout is centered
// on a 48-col (80mm) printer with an equal left/right margin of (48-42)/2 = 3,
// instead of hugging the left edge with 6 blank cols on the right.
func TestFormatPaidTicket_CentersOnPhysicalWidth(t *testing.T) {
	o := sampleOrder()
	o.PaidAmount = 1780
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 42, PhysicalWidth: 48, TaxRate: 10}
	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: 1780})

	// The "So HD" footer row fills the content width (label left, value right),
	// so its printed length + left margin locates both edges. The right margin
	// is unprinted paper (no trailing spaces), so derive it from PhysicalWidth.
	line := printedLineContaining(t, out, "伝票")
	if line == "" {
		t.Fatal("could not find 伝票 line")
	}
	leftMargin := leadingSpaces(line)
	// displayWidth, not len: a JA label is 2 columns but 3 UTF-8 bytes.
	rightMargin := cfg.PhysicalWidth - displayWidth(line) // 48 - (3 + 42) = 3
	if leftMargin != 3 {
		t.Errorf("left margin = %d, want 3 (line=%q)", leftMargin, line)
	}
	if leftMargin != rightMargin {
		t.Errorf("padding not symmetric: left=%d right=%d (line=%q)", leftMargin, rightMargin, line)
	}
}

// With no PhysicalWidth set, output must be flush-left (legacy behavior).
func TestFormatPaidTicket_NoCenteringWithoutPhysicalWidth(t *testing.T) {
	o := sampleOrder()
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 42, TaxRate: 10}
	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: 1780})
	line := printedLineContaining(t, out, "伝票")
	if leadingSpaces(line) != 0 {
		t.Errorf("without PhysicalWidth the layout must be flush-left, got %q", line)
	}
}

// printedLineContaining returns the printable (control-runes stripped) text of
// the first output line containing sub. The stream is decoded from Shift_JIS
// first so multi-byte JA labels survive — dropping non-ASCII bytes would erase
// the very labels the margin assertions key off.
func printedLineContaining(t *testing.T, b []byte, sub string) string {
	t.Helper()
	for _, line := range strings.Split(decodeSJIS(t, b), "\n") {
		var cur []rune
		for _, r := range line {
			if r >= 0x20 && r != 0x7f {
				cur = append(cur, r)
			}
		}
		if s := string(cur); strings.Contains(s, sub) {
			return s
		}
	}
	return ""
}

func leadingSpaces(s string) int {
	n := 0
	for n < len(s) && s[n] == ' ' {
		n++
	}
	return n
}

// TestKitchenTicket_FreeNoteShows proves a plain item note (no structured
// toppings) surfaces as "Ghi chu: ..." on the kitchen ticket instead of being
// misclassified as a "-- <note>" topping and effectively lost.
func TestKitchenTicket_FreeNoteShows(t *testing.T) {
	o := &Order{OrderCode: "OC-1", TableNumber: "A1"}
	item := Item{MenuItemName: "Bun ga", Quantity: 1, UnitPrice: 1000, Note: "it cay, khong hanh"}
	out := FormatKitchenTicket(o, []Item{item}, 1, PrintJobConfig{PaperWidth: 48})
	if !strings.Contains(decodeSJIS(t, out), "備考: it cay, khong hanh") {
		t.Errorf("kitchen ticket must print the free note as '備考: ...'")
	}
	if bytes.Contains(out, []byte("-- it cay")) {
		t.Errorf("free note must not render as a '-- <note>' topping line")
	}
}

// The same note must show on the customer receipt.
func TestReceipt_FreeNoteShows(t *testing.T) {
	o := &Order{OrderCode: "OC-1", TableNumber: "A1", TotalAmount: 1000}
	item := Item{MenuItemName: "Bun ga", Quantity: 1, UnitPrice: 1000, Note: "khong hanh"}
	out := FormatPaidTicket(o, []Item{item}, 0, PrintJobConfig{PaperWidth: 48, TaxRate: 10},
		PaymentSlipInfo{AmountPaid: 1000})
	if !strings.Contains(decodeSJIS(t, out), "備考: khong hanh") {
		t.Errorf("receipt must print the free note as '備考: ...'")
	}
}

// A note alongside structured toppings must still show BOTH the toppings and
// the free note.
func TestKitchenTicket_NoteWithStructuredToppings(t *testing.T) {
	o := &Order{OrderCode: "OC-1", TableNumber: "A1"}
	item := Item{MenuItemName: "Pho bo", Quantity: 1, UnitPrice: 2000, Note: "it bun",
		Toppings: []ItemTopping{{Name: "Them thit", Quantity: 1, UnitPrice: 300}}}
	out := FormatKitchenTicket(o, []Item{item}, 1, PrintJobConfig{PaperWidth: 48})
	if !bytes.Contains(out, []byte("Them thit")) {
		t.Errorf("structured topping missing")
	}
	if !strings.Contains(decodeSJIS(t, out), "備考: it bun") {
		t.Errorf("free note missing alongside structured toppings")
	}
}

// parseNoteAsToppings: +/- lines stay toppings, plain text becomes the free note.
func TestParseNoteAsToppings_SeparatesFreeNote(t *testing.T) {
	toppings, free := parseNoteAsToppings("+ Them thit ¥300\n- khong hanh\nit cay")
	if len(toppings) != 2 {
		t.Fatalf("want 2 toppings from +/- lines, got %d: %+v", len(toppings), toppings)
	}
	if free != "it cay" {
		t.Errorf("free note = %q, want %q", free, "it cay")
	}
}

// TestOrderNotePrintsOnBillAndKitchen proves the order-level note (Ghi chu),
// set via the POS "update order" note field, surfaces on both the customer bill
// and the kitchen ticket — previously it was never printed on paper at all.
func TestOrderNotePrintsOnBillAndKitchen(t *testing.T) {
	o := &Order{OrderCode: "OC-1", TableNumber: "A1", TotalAmount: 1000,
		Note:  "Giao truoc 12h",
		Items: []Item{{MenuItemName: "Bun ga", Quantity: 1, UnitPrice: 1000}},
	}
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 48, TaxRate: 10}

	kitchen := FormatKitchenTicket(o, o.Items, 1, cfg)
	if !strings.Contains(decodeSJIS(t, kitchen), "備考: Giao truoc 12h") {
		t.Error("kitchen ticket missing order-level note")
	}

	bill := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{AmountPaid: 1000})
	if !strings.Contains(decodeSJIS(t, bill), "備考: Giao truoc 12h") {
		t.Error("bill missing order-level note")
	}
}

// An empty order note must not print a stray "Ghi chu:" line.
func TestEmptyOrderNoteNotPrinted(t *testing.T) {
	o := &Order{OrderCode: "OC-1", TableNumber: "A1", TotalAmount: 1000,
		Items: []Item{{MenuItemName: "Bun ga", Quantity: 1, UnitPrice: 1000}}}
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 48, TaxRate: 10}
	bill := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{AmountPaid: 1000})
	if strings.Contains(decodeSJIS(t, bill), "備考:") {
		t.Error("no order note set, but a '備考:' line was printed")
	}
}

// TestNoteWrapsByWordWithinWidth proves a long note wraps on word boundaries
// (every whole word survives — a mid-word cut would break the substring) and
// spans multiple printed lines instead of overflowing one line.
func TestNoteWrapsByWordWithinWidth(t *testing.T) {
	o := &Order{OrderCode: "OC-1", TableNumber: "A1", TotalAmount: 1000,
		Note:  "khong hanh khong ngo it cay giao truoc muoi hai gio trua goi rieng nuoc mam",
		Items: []Item{{MenuItemName: "Bun ga", Quantity: 1, UnitPrice: 1000}}}
	out := FormatPaidTicket(o, o.Items, 0, PrintJobConfig{PaperWidth: 42, TaxRate: 10},
		PaymentSlipInfo{AmountPaid: 1000})

	// Every whole word must survive intact — a mid-word split would break it.
	words := []string{"khong", "hanh", "ngo", "cay", "giao", "truoc", "muoi", "gio", "trua", "goi", "rieng", "nuoc", "mam"}
	for _, wd := range words {
		if !bytes.Contains(out, []byte(wd)) {
			t.Errorf("word %q was split or lost on wrap", wd)
		}
	}

	// The note must span >1 printed line (it wrapped, not overflowed one line).
	// Lines are LF-delimited; count lines carrying an early vs a late word.
	lines := bytes.Split(out, []byte("\n"))
	var earlyLine, lateLine int = -1, -1
	for i, ln := range lines {
		if bytes.Contains(ln, []byte("khong hanh")) {
			earlyLine = i
		}
		if bytes.Contains(ln, []byte("nuoc mam")) {
			lateLine = i
		}
	}
	if earlyLine < 0 || lateLine < 0 {
		t.Fatalf("note fragments not found (early=%d late=%d)", earlyLine, lateLine)
	}
	if lateLine == earlyLine {
		t.Error("note did not wrap — start and end landed on the same line")
	}
}

func TestFormatRemainingTicket_HasQRAndRemainingTotal(t *testing.T) {
	o := sampleOrder()
	cfg := PrintJobConfig{StoreName: "Store", PaperWidth: 48, TaxRate: 10}

	out := FormatRemainingTicket(o, o.Items, 0, cfg, 890)

	if !strings.Contains(decodeSJIS(t, out), "残額") {
		t.Error("remaining ticket missing 残額 title")
	}
	// Remaining ticket keeps the QR so the next payer can scan.
	if !bytes.Contains(out, []byte{0x1B, 0x1D, 0x79}) {
		t.Error("remaining ticket must contain a QR code")
	}
	// Con lai should reflect the remaining amount (890), not 0.
	if !bytes.Contains(out, []byte("890")) {
		t.Error("remaining ticket should show remaining amount 890")
	}
}

// TestFormatPaidTicket_SplitUsesSubBillTotal proves a split slip prints THIS
// person's sub-bill (BillTotal), not the whole order total, and omits the
// order-level breakdown — fixing "chia bill mà vẫn để tổng bill".
// A by-items share slip lists ONLY this payer's món, prints their món total as
// "Phan chia (i/N)", and shows the whole-order "Tong don" as context under the
// HOA DON CHIA banner.
func TestFormatPaidTicket_SplitByItemsShowsShareAndOrderTotal(t *testing.T) {
	// Sub-order = this payer's món only (total 2,998). Whole order = 8,993.
	sub := &Order{
		ID: "o1", OrderCode: "ORD-1", TableNumber: "B1",
		Subtotal: 2725, ServiceCharge: 136, TaxAmount: 137, TotalAmount: 2998,
		Items: []Item{{MenuItemName: "Regular", Quantity: 1, UnitPrice: 2725, Status: ItemStatusPending}},
	}
	cfg := PrintJobConfig{StoreName: "Store", PaperWidth: 48, TaxRate: 10}

	out := FormatPaidTicket(sub, sub.Items, 0, cfg, PaymentSlipInfo{
		PaymentMethod:   "qr",
		AmountPaid:      2998,
		Remaining:       0,
		BillTotal:       0, // sub-order total drives the headline
		SlipIndex:       2,
		SplitCount:      4,
		SplitMode:       "by_items",
		OrderGrossTotal: 8993,
	})

	text := decodeSJIS(t, out)
	if !strings.Contains(text, "分割会計") {
		t.Error("by-items slip missing the 分割会計 banner")
	}
	if !strings.Contains(text, "品目割") {
		t.Error("by-items slip missing the mode label")
	}
	if !strings.Contains(text, "取り分") || !strings.Contains(text, "2,998") {
		t.Error("by-items slip must show this payer's share 2,998 as 取り分")
	}
	if !strings.Contains(text, "注文合計") || !strings.Contains(text, "8,993") {
		t.Error("by-items slip must show the whole-order 注文合計 8,993 as context")
	}
	if !strings.Contains(text, "2/4") {
		t.Error("by-items slip missing the i/N split index")
	}
	// The legacy numeric "お客 n/N" row is replaced by the banner.
	if strings.Contains(text, "お客 ") {
		t.Error("banner replaces the legacy お客 label")
	}
}

// TestFormatPaidTicket_ConLaiOnlyWhenNonzero: "Con lai" prints only when > 0.
func TestFormatPaidTicket_ConLaiOnlyWhenNonzero(t *testing.T) {
	o := sampleOrder()
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 48, TaxRate: 10}

	full := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{AmountPaid: 1780, Remaining: 0})
	if strings.Contains(decodeSJIS(t, full), "残額") {
		t.Error("full settle (remaining 0) must not print a 残額 line")
	}

	partial := decodeSJIS(t, FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{AmountPaid: 1000, Remaining: 780}))
	if !strings.Contains(partial, "残額") || !strings.Contains(partial, "780") {
		t.Error("partial settle must print 残額 780")
	}
}

// TestFormatPaidTicket_PrintsVariantAndToppings asserts the bill renders the
// SKU variant (from the structured column) and each topping label + price under
// the item line — matching the Handy ticket format.
func TestFormatPaidTicket_PrintsVariantAndToppings(t *testing.T) {
	o := &Order{
		ID: "o1", OrderCode: "ORD-1", TableNumber: "B1", TotalAmount: 1930, TaxAmount: 0,
		Items: []Item{{
			MenuItemName: "Tra sua", SkuVariantName: "Size L",
			Quantity: 1, UnitPrice: 1930, Status: ItemStatusPending,
			Toppings: []ItemTopping{
				{Name: "Extra cheese", ModifierType: "add", Quantity: 1, UnitPrice: 150},
				{Name: "No sugar", ModifierType: "remove", Quantity: 1, UnitPrice: 0},
			},
		}},
	}
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 48, TaxRate: 10}
	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{AmountPaid: 1930})

	for _, want := range []string{"Tra sua", "Size L", "Extra cheese", "150", "No sugar"} {
		if !bytes.Contains(out, []byte(want)) {
			t.Errorf("paid ticket missing %q", want)
		}
	}
}

func TestCollapseMirroredName(t *testing.T) {
	cases := []struct{ in, want string }{
		{"100% sugar · 100% sugar", "100% sugar"},
		{"Iced · Iced", "Iced"},
		{"iced · Iced", "iced"}, // case-insensitive collapse
		{"Fish sauce", "Fish sauce"},
		{"Cheese · Large", "Cheese · Large"}, // genuine variant untouched
		{"", ""},
	}
	for _, c := range cases {
		if got := collapseMirroredName(c.in); got != c.want {
			t.Errorf("collapseMirroredName(%q) = %q, want %q", c.in, got, c.want)
		}
	}
}

// A topping name stored doubled ("X · X" — orders created before
// workstation-app#101) must print ONCE on the bill, not twice + wrapped.
func TestFormatPaidTicket_CollapsesDoubledToppingName(t *testing.T) {
	o := &Order{
		ID: "o1", OrderCode: "ORD-1", TableNumber: "B1", TotalAmount: 1020, TaxAmount: 0,
		Items: []Item{{
			MenuItemName: "Vietnamese Coffee", SkuVariantName: "Hot",
			Quantity: 1, UnitPrice: 1020, Status: ItemStatusPending,
			Toppings: []ItemTopping{
				{Name: "100% sugar · 100% sugar", ModifierType: "add", Quantity: 1, UnitPrice: 0},
				{Name: "Iced · Iced", ModifierType: "add", Quantity: 1, UnitPrice: 0},
			},
		}},
	}
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 48, TaxRate: 10}
	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{AmountPaid: 1020})

	// The ASCII label survives Shift_JIS encoding; count occurrences. Doubled
	// would print it twice, collapsed prints it once.
	if n := bytes.Count(out, []byte("100% sugar")); n != 1 {
		t.Errorf("want '100%% sugar' printed once (collapsed), got %d:\n%s", n, out)
	}
	if n := bytes.Count(out, []byte("Iced")); n != 1 {
		t.Errorf("want 'Iced' printed once (collapsed), got %d:\n%s", n, out)
	}
}

// Documents the two behaviours the fire/print changes rely on:
//   - the kitchen + serving ticket (FormatKitchenTicket) shows ONLY the items
//     passed to it (the newly-fired delta), never the whole order;
//   - the order-bill button (FormatRunnerTicket) shows ALL items and embeds the
//     order.ID in a QR code.
func TestKitchenTicketIsDelta_RunnerIsFullWithQR(t *testing.T) {
	o := &Order{
		ID: "order-xyz", OrderCode: "ORD-9", TableNumber: "T2", OrderType: "spot",
		TotalAmount: 3000, TaxAmount: 0,
		Items: []Item{
			{MenuItemName: "Already fired dish", Quantity: 1, UnitPrice: 1000, Status: ItemStatusPending},
			{MenuItemName: "Newly added dish", Quantity: 1, UnitPrice: 2000, Status: ItemStatusPending},
		},
	}
	cfg := PrintJobConfig{PaperWidth: 48}

	// Serving/kitchen delta ticket — pass ONLY the newly-fired item.
	delta := FormatKitchenTicket(o, []Item{o.Items[1]}, 1, cfg)
	if !bytes.Contains(delta, []byte("Newly added dish")) {
		t.Errorf("delta ticket must show the new dish, got:\n%s", delta)
	}
	if bytes.Contains(delta, []byte("Already fired dish")) {
		t.Errorf("delta ticket must NOT show already-fired items, got:\n%s", delta)
	}
	if bytes.Contains(delta, []byte("order-xyz")) {
		t.Errorf("kitchen/serving ticket must NOT embed a QR (order.ID), got:\n%s", delta)
	}

	// Full-order bill — all items + a QR encoding order.ID (the raw payload
	// bytes appear in the ESC/POS QR command).
	full := FormatRunnerTicket(o, o.Items, 0, cfg)
	if !bytes.Contains(full, []byte("Already fired dish")) || !bytes.Contains(full, []byte("Newly added dish")) {
		t.Errorf("full bill must show ALL items, got:\n%s", full)
	}
	if !bytes.Contains(full, []byte("order-xyz")) {
		t.Errorf("full bill must embed order.ID in a QR, got:\n%s", full)
	}
}

// The per-fire customer QR slip (FormatDeltaQRTicket) lists ONLY the delta
// items with a DELTA total and a QR (order.ID) — never the whole-order items
// or the whole-order figures. It DOES carry its own per-batch tax, computed on
// the delta items (see TestKitchenAndDelta_ShowPerRateTax), so the check below
// only asserts the whole-order subtotal/total (9,000 / 9,900) never leak in.
func TestFormatDeltaQRTicket_DeltaItemsTotalAndQR(t *testing.T) {
	o := &Order{
		ID: "ord-777", OrderCode: "ORD-7", TableNumber: "T5", OrderType: "spot",
		// Whole-order totals — these must NOT appear on a delta slip.
		Subtotal: 9000, TaxAmount: 900, TotalAmount: 9900,
		Items: []Item{
			{MenuItemName: "Old dish", Quantity: 1, UnitPrice: 7000, Status: ItemStatusPending},
			{MenuItemName: "New dish", Quantity: 1, UnitPrice: 2000, Status: ItemStatusPending},
		},
	}
	cfg := PrintJobConfig{PaperWidth: 48}

	// Pass ONLY the newly-fired item.
	out := FormatDeltaQRTicket(o, []Item{o.Items[1]}, cfg)

	if !bytes.Contains(out, []byte("New dish")) {
		t.Errorf("delta slip must show the new dish, got:\n%s", out)
	}
	if bytes.Contains(out, []byte("Old dish")) {
		t.Errorf("delta slip must NOT show other items, got:\n%s", out)
	}
	if !bytes.Contains(out, []byte("ord-777")) {
		t.Errorf("delta slip must embed order.ID in a QR, got:\n%s", out)
	}
	// Delta total = 2,000 (the new item), and the whole-order figures
	// (9,000 subtotal / 9,900 total) must be suppressed.
	if !bytes.Contains(out, []byte("2,000")) {
		t.Errorf("delta total should be 2,000, got:\n%s", out)
	}
	if bytes.Contains(out, []byte("9,000")) || bytes.Contains(out, []byte("9,900")) {
		t.Errorf("delta slip must NOT show whole-order totals, got:\n%s", out)
	}
}

// All three slips (kitchen, customer QR delta, full QR bill) print their labels
// in the locale carried by config.Locale — the operator's pos-web language.
func TestPrintTickets_LocalizedLabels(t *testing.T) {
	o := &Order{
		ID: "loc-1", OrderCode: "ORD-1", TableNumber: "T1", OrderType: "spot",
		Subtotal: 1000, TotalAmount: 1000,
		Items: []Item{{MenuItemName: "Pho", Quantity: 1, UnitPrice: 1000, Status: ItemStatusPending}},
	}
	cases := []struct {
		locale     string
		itemHeader string // kitchen + bill item column
		deltaTitle string // customer QR delta slip title
		billTitle  string // full QR bill title
		total      string // bill total label
	}{
		{"vi", "San pham", "MON VUA THEM", "HOA DON BAN", "Tong"},
		{"en", "Item", "NEW ITEMS", "TABLE BILL", "Total"},
		{"ja", "商品", "追加商品", "会計伝票", "合計"},
	}
	for _, c := range cases {
		t.Run(c.locale, func(t *testing.T) {
			cfg := PrintJobConfig{PaperWidth: 48, Locale: c.locale}

			kitchen := decodeSJIS(t, FormatKitchenTicket(o, o.Items, 1, cfg))
			if !strings.Contains(kitchen, c.itemHeader) {
				t.Errorf("kitchen[%s] missing item header %q:\n%s", c.locale, c.itemHeader, kitchen)
			}
			delta := decodeSJIS(t, FormatDeltaQRTicket(o, o.Items, cfg))
			if !strings.Contains(delta, c.deltaTitle) {
				t.Errorf("delta[%s] missing title %q:\n%s", c.locale, c.deltaTitle, delta)
			}
			bill := decodeSJIS(t, FormatRunnerTicket(o, o.Items, 0, cfg))
			if !strings.Contains(bill, c.billTitle) || !strings.Contains(bill, c.total) {
				t.Errorf("bill[%s] missing %q/%q:\n%s", c.locale, c.billTitle, c.total, bill)
			}
		})
	}
}

// Regression: the service-charge row (and every footer row) must fit the paper
// width in EVERY locale. A Japanese label ("サービス料") is fullwidth; the old
// rune-count gap calc under-measured it so the row overflowed and the printer
// wrapped it. displayWidth keeps it on one line.
func TestBillFooterRow_FitsWidthEveryLocale(t *testing.T) {
	o := &Order{
		ID: "w-1", OrderCode: "ORD-1", TableNumber: "T1", OrderType: "spot",
		Subtotal: 5000, ServiceCharge: 500, TaxAmount: 0, TotalAmount: 5500,
		Items: []Item{{MenuItemName: "Pho", Quantity: 1, UnitPrice: 5000, Status: ItemStatusPending}},
	}
	svcLabel := map[string]string{"vi": "Phi phuc vu", "en": "Service", "ja": "サービス料"}
	for _, w := range []int{42, 48} {
		for locale, label := range svcLabel {
			cfg := PrintJobConfig{PaperWidth: w, Locale: locale} // Currency "" → plain digits
			text := decodeSJIS(t, FormatRunnerTicket(o, o.Items, 0, cfg))
			found := false
			for _, line := range strings.Split(text, "\n") {
				if strings.Contains(line, label) {
					found = true
					if dw := displayWidth(line); dw > w {
						t.Errorf("[%s w=%d] service line overflows (dw=%d>%d), will wrap: %q",
							locale, w, dw, w, line)
					}
				}
			}
			if !found {
				t.Errorf("[%s w=%d] service-charge line %q not found", locale, w, label)
			}
		}
	}
}

// TestFormatPaidTicket_ToppingQuantityPrefix proves a topping with quantity >1
// renders a "N x " prefix on the label (e.g. "3 x Eggs") so the customer sees
// how many units they got — and the price line is the line-total
// (unit_price * quantity). Mirrors the item-row convention which already
// puts quantity before the name.
func TestFormatPaidTicket_ToppingQuantityPrefix(t *testing.T) {
	o := &Order{
		ID: "o1", OrderCode: "ORD-1", TableNumber: "B1", TotalAmount: 1230, TaxAmount: 0,
		Items: []Item{{
			MenuItemName: "Banh mi", Quantity: 1, UnitPrice: 930, Status: ItemStatusPending,
			Toppings: []ItemTopping{
				{Name: "Eggs", ModifierType: "add", Quantity: 3, UnitPrice: 100},
			},
		}},
	}
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 48, TaxRate: 10}
	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{AmountPaid: 1230})

	if !bytes.Contains(out, []byte("3 x Eggs")) {
		t.Errorf("paid ticket must render topping qty prefix '3 x Eggs', got:\n%s", out)
	}
	if !bytes.Contains(out, []byte("300")) {
		t.Errorf("paid ticket must render line-total 300 (unit_price*qty), got:\n%s", out)
	}
}

// TestFormatPaidTicket_RemoveModifierSkipsQuantityPrefix proves a "remove"
// topping doesn't get the "N x " prefix ("2 x No sugar" reads as nonsense
// for a removal); the label stays the bare name.
func TestFormatPaidTicket_RemoveModifierSkipsQuantityPrefix(t *testing.T) {
	o := &Order{
		ID: "o1", OrderCode: "ORD-1", TableNumber: "B1", TotalAmount: 1000, TaxAmount: 0,
		Items: []Item{{
			MenuItemName: "Tra sua", Quantity: 1, UnitPrice: 1000, Status: ItemStatusPending,
			Toppings: []ItemTopping{
				{Name: "No sugar", ModifierType: "remove", Quantity: 2, UnitPrice: 0},
			},
		}},
	}
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 48, TaxRate: 10}
	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{AmountPaid: 1000})

	if bytes.Contains(out, []byte("2 x No sugar")) {
		t.Errorf("remove topping must NOT carry 'N x ' prefix, got:\n%s", out)
	}
	if !bytes.Contains(out, []byte("No sugar")) {
		t.Errorf("remove topping label still required, got:\n%s", out)
	}
}

// TestFormatPaidTicket_VariantFromNameSuffix proves the variant is recovered
// from the " · " suffix when sku_variant_name is absent (e.g. older rows), and
// the suffix is stripped from the printed item name.
func TestFormatPaidTicket_VariantFromNameSuffix(t *testing.T) {
	o := &Order{
		ID: "o1", OrderCode: "ORD-1", TableNumber: "B1", TotalAmount: 500, TaxAmount: 0,
		Items: []Item{{
			MenuItemName: "Ca phe \xc2\xb7 Lon", Quantity: 1, UnitPrice: 500, Status: ItemStatusPending,
		}},
	}
	cfg := PrintJobConfig{StoreName: "S", PaperWidth: 48, TaxRate: 10}
	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{AmountPaid: 500})

	if !bytes.Contains(out, []byte("-- Lon")) {
		t.Error("expected variant 'Lon' rendered from name suffix as '-- Lon'")
	}
}

// An equal (chia đều) share slip lists the FULL món, shows the whole-order
// "Tong don", and this payer's "Phan chia (i/N)" under the HOA DON CHIA banner.
func TestFormatPaidTicket_SplitEqualBannerAndShare(t *testing.T) {
	o := sampleOrder()
	cfg := PrintJobConfig{StoreName: "Store", PaperWidth: 48, TaxRate: 10}

	out := FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{
		PaymentMethod:   "qr",
		AmountPaid:      890,
		SlipIndex:       2,
		SplitCount:      4,
		SplitMode:       "even",
		OrderGrossTotal: o.TotalAmount,
	})

	text := decodeSJIS(t, out)
	if !strings.Contains(text, "分割会計") {
		t.Error("equal split slip missing the 分割会計 banner")
	}
	if !strings.Contains(text, "均等割") {
		t.Error("equal split slip missing the mode label")
	}
	if !strings.Contains(text, "2/4") {
		t.Error("equal split slip missing the i/N split index")
	}
	if !strings.Contains(text, "注文合計") {
		t.Error("equal split slip missing the 注文合計 context line")
	}
	if !strings.Contains(text, "取り分") || !strings.Contains(text, "890") {
		t.Error("equal split slip missing this payer's share 890 as 取り分")
	}
	if strings.Contains(text, "お客 ") {
		t.Error("banner replaces the legacy お客 label")
	}
}

// Takeaway customer header (name / phone / pickup) prints on the kitchen ticket.
// Asserts on the raw ESC/POS bytes (ASCII label + ASCII value) so it does not
// depend on the Shift_JIS decode path the older slip tests use.
func TestKitchenTicket_TakeawayCustomerHeader(t *testing.T) {
	order := &Order{
		OrderCode:             "ORD-2026-0017",
		OrderType:             "takeaway",
		CustomerTakeawayName:  "TRAN",
		CustomerTakeawayPhone: "0966506438",
		ScheduledPickupTime:   "2026-07-24T13:45:00Z",
	}
	items := []Item{{MenuItemName: "Pho", Quantity: 1, UnitPrice: 1100}}
	out := FormatKitchenTicket(order, items, 17, PrintJobConfig{PaperWidth: 48, Locale: "en"})

	for _, want := range []string{"Customer: TRAN", "Phone: 0966506438", "Pickup: 07/24"} {
		if !strings.Contains(string(out), want) {
			t.Errorf("kitchen ticket missing %q", want)
		}
	}
}

// A dine-in order carries no takeaway fields, so the header prints nothing.
func TestKitchenTicket_NoCustomerHeaderForDineIn(t *testing.T) {
	order := &Order{OrderCode: "ORD-1", OrderType: "dine_in", CustomerTakeawayName: "IGNORED"}
	items := []Item{{MenuItemName: "Pho", Quantity: 1, UnitPrice: 1100}}
	out := FormatKitchenTicket(order, items, 1, PrintJobConfig{PaperWidth: 48, Locale: "en"})
	if strings.Contains(string(out), "IGNORED") || strings.Contains(string(out), "Customer:") {
		t.Errorf("dine-in ticket must not print a takeaway customer header")
	}
}
