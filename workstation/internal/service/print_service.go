package service

import (
	"encoding/json"
	"fmt"
	"log/slog"
	"math"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

type PrintJobConfig struct {
	// StoreOrganization — #2000 bước 4. 法人名, tên PHÁP NHÂN: khác StoreSubName
	// (thương hiệu) và khác StoreName (chi nhánh). 登録番号 T+13 thuộc về pháp
	// nhân, nên in tên thương hiệu cạnh số của pháp nhân là lệch chủ thể.
	StoreOrganization string
	StoreName         string // e.g. "ベト屋"
	StoreSubName      string // e.g. "VIET ORIGIN"
	StoreAddress      string // e.g. "Tokyo..."
	// StorePhone — #2000 bước 3. Cloud gửi `phone` trong feed branch từ lâu; ô
	// này là chỗ đầu tiên nó có để đứng. Trước đó khối `store_info` mời khai
	// `store_phone` mà không nơi nào chứa giá trị, nên bật lên vẫn in ra trống.
	StorePhone string  // e.g. "03-1234-5678"
	PaperWidth int     // content layout width in characters (e.g. 42)
	TaxRate    float64 // consumption-tax percent per shop, e.g. 10 = 10%. 0 → fallbackTaxRate.
	Currency   string  // money symbol for printed prices (e.g. "¥", "₫", "$"). Empty → "¥".
	// PhysicalWidth is the printer's real characters-per-line (32 for 58mm, 48
	// for 80mm). When it exceeds the content PaperWidth, the layout is centered
	// on the paper with an equal left/right margin. 0 → no centering (content is
	// printed flush-left, the legacy behavior).
	PhysicalWidth int
	Locale        string // "ja" | "en" | "vi" — label language; empty → "ja". See print_shift_report_i18n.go.
	// CurrencyCode is the ISO 4217 code (e.g. "JPY", "VND") the shop prices in.
	// Drives the per-rate tax-block rounding STEP (JPY/VND = 1, USD = 0.01) so
	// the printed 内消費税 figures round the same way the engine does. Empty →
	// project default (VND → step 1), matching the engine's currencyStep.
	CurrencyCode string
	// SellerRegistrationNumber is the インボイス T+13 registration number
	// (適格請求書発行事業者登録番号). Printed as "登録番号: {value}" ONLY when
	// non-empty (Q5 — null for now, so it typically won't print).
	SellerRegistrationNumber string
	// OperatingCountry là QUỐC GIA NƠI SHOP TỒN TẠI (ISO 3166-1 alpha-2), lấy
	// từ `shop_settings.operating_country` mà #1490 ship xuống trong feed branch.
	//
	// Nó quyết định CHỨNG TỪ NÀO được in — không phải ngôn ngữ nhãn. Bốn trục
	// độc lập, cấm suy diễn chéo: compliance-country ≠ currency ≠ timezone ≠
	// print locale (#1459). Trước #1493 trục 1 bị suy ra từ trục 4, nên một quán
	// Việt để giao diện tiếng Nhật in ra chứng từ Nhật, còn quán Nhật để giao
	// diện tiếng Việt in ra hoá đơn GTGT Việt Nam.
	//
	// RỖNG là một trạng thái hợp lệ và có nghĩa riêng: "chưa biết" — bản cũ chưa
	// pull lần nào, hoặc Cloud cũ hơn #1490. Khi rỗng, đường in GIỮ NGUYÊN nhánh
	// locale cũ; mặc định một quốc gia ở đây sẽ làm một quán nào đó mất chứng từ
	// luật định giữa chừng, và đó là hỏng nặng hơn hẳn việc chọn sai bằng locale.
	OperatingCountry string

	// slipLoc is the zone the slip's wall-clock lines are written in (#2572).
	// nil → `time.Local`; see slipLocation.
	//
	// UNEXPORTED ON PURPOSE, and this is the one field on this struct where
	// that matters. `print_contract_golden.json` records `config_fields` by
	// reflection over the EXPORTED fields, and `PrintContractParityTest` asserts
	// PHP's `PrintJobConfig` VO carries exactly that set. An exported field here
	// would therefore be a cross-repo change: Cloud's renderer would have to
	// grow a matching property it has no use for, since Cloud is HANDED the
	// resolved instant (`PrintRenderData::$now`) and never resolves a zone of
	// its own (#1091). Keeping the zone out of the serialised contract keeps
	// this fix inside the workstation.
	//
	// Set it with WithSlipLocation.
	slipLoc *time.Location
}

// WithSlipLocation returns a copy of the config that writes slip wall-clock
// lines in `loc`.
//
// This is the seam for the case `time.Local` cannot answer: a register whose OS
// zone is UTC (a misconfigured POS PC — `handler.resolveShopLocation` treats it
// as exactly that) in a shop whose Cloud-registered `branches.timezone` says
// otherwise. Resolving that needs the DB handle, which lives in
// `internal/handler`; until that side is wired the default below is what every
// production caller gets, and on a correctly-configured shop PC it is right.
func (c PrintJobConfig) WithSlipLocation(loc *time.Location) PrintJobConfig {
	c.slipLoc = loc

	return c
}

// slipLocation is the zone this job's wall-clock lines print in.
func (c PrintJobConfig) slipLocation() *time.Location {
	return slipLocation(c.slipLoc)
}

// step returns the currency rounding step for the per-rate tax blocks, derived
// from CurrencyCode via the same currencyStep the engine uses.
func (c PrintJobConfig) step() float64 {
	return currencyStep(c.CurrencyCode)
}

// leftMargin returns the blank columns to indent content by so a contentW-wide
// layout is centered within the printer's PhysicalWidth (equal margin on both
// sides). Returns 0 when PhysicalWidth is unset or not wider than the content.
func (c PrintJobConfig) leftMargin(contentW int) int {
	if c.PhysicalWidth > contentW {
		return (c.PhysicalWidth - contentW) / 2
	}
	return 0
}

// cur returns the money symbol to prefix printed prices with, so a VND shop
// prints ₫ instead of a hard-coded ¥. Defaults to ¥ when unset (back-compat).
func (c PrintJobConfig) cur() string {
	if c.Currency == "" {
		return "¥"
	}
	return c.Currency
}

// stampedTaxRow decides the single aggregate 内税 row a slip may print when the
// order carries no per-rate snapshot (#2067). It returns the amount and whether
// the row may be printed at all.
//
// THE PRINT LAYER DOES NOT COMPUTE TAX. The only figure it may put on paper is
// `order.tax_amount`, stamped by the engine and frozen onto the order. When
// that is absent there is no tax fact to print, so no tax row is printed —
// omitting a line the shop cannot substantiate, rather than inventing one.
//
// What this replaced, and why it had to go:
//
//	if rate <= 0 { rate = 10.0 }              // ← a guess about Japan
//	net := math.Round(total / (1 + rate/100)) // ← the print layer pricing a sale
//
// Three independent things were wrong with it, and none of them made a sound:
//
//   - 10% is an assertion about ONE country applied to every shop — a VN shop, a
//     軽減税率 8% basket and a 非課税 line all printed 10%.
//   - It contradicts plan-043: tax here is per-rate, resolved per LINE and
//     snapshotted immutably onto the order line. One rate applied to one order
//     total cannot reproduce that; a basket of 10% 店内 + 8% 持ち帰り came out
//     wrong by construction.
//   - `OrderEngine.legacyTaxRate` had ALREADY dropped this exact fallback
//     ("a shop that genuinely configured no tax prices at 0% rather than an
//     invented 10%"), and `tax_resolver.go` states the consequence of keeping it
//     in so many words: *printing 10% on a sale Cloud then booked at 0%*. The
//     print layer was the one place it survived.
//
// And it was not a rare edge. `PrintJobConfig.TaxRate` came from
// `shop_settings.tax_rate`, which NO Cloud populates any more — plan-043 T6.2
// dropped the `shop_order_settings.tax_rate` column and the workstation branch
// feed's allowlist has not carried the key since. So `rate` was 0 on every
// register in every country, which means the `rate <= 0` branch was not the
// fallback: it was the ONLY branch that ever ran.
//
// `authoritative` is false for the slips that deliberately pass 0 because they
// show a PART of the order (kitchen ticket, per-fire delta) — those never had a
// tax fact of their own to lose, so their silence is normal and unlogged. A
// whole-order money slip that reaches here has lost one, and says so.
func stampedTaxRow(order *Order, kind string, orderTax, total int, authoritative bool) (int, bool) {
	if orderTax > 0 {
		return orderTax, true
	}
	if authoritative && total > 0 {
		warnPrintTaxOmitted(order, kind, total)
	}
	return 0, false
}

// warnPrintTaxOmitted is the readable trail the omission leaves behind (#2067).
// The old defect was dangerous precisely because it was SILENT — a fabricated
// tax line looks exactly like a real one on thermal paper. Printing nothing is
// visible on the slip; this makes it diagnosable off the slip too.
func warnPrintTaxOmitted(order *Order, kind string, total int) {
	orderID, orderCode := "", ""
	if order != nil {
		orderID, orderCode = order.ID, order.OrderCode
	}
	slog.Warn("print: tax row omitted — the order carries no tax fact to print",
		"kind", kind,
		"order_id", orderID,
		"order_code", orderCode,
		"total", total,
		"detail", "no per-line tax_rate snapshot and order.tax_amount <= 0; "+
			"the slip prints no tax row rather than a computed one (#2067). "+
			"Check that the brand's tax_types have synced to this workstation.")
}

// FormatKitchenTicket formats a kitchen ticket (phiếu bếp).
//
// Layout:
//
//	Cach dat   STT   So HD   Ban
//	Tai ban    319   004     A-1    ← STT = incrementing daily count, So HD = order-code suffix (matches receipt), table bold
//	- - - - - - - - - - - - - - -
//	San pham              Thanh tien
//	1  Bun ga                ¥1,000
//	   —— Tang co mi           ¥150
//	   Ghi chu: It cay
//	- - - - - - - - - - - - - - -
//	              Tong cong  ¥2,200
//
// STT is the per-day kitchen ticket counter (ticketNo). So HD is the invoice
// number shown on the customer receipt (OrderCodeSuffix), so the kitchen slip
// and the receipt can be cross-referenced by the same "So HD".
// OrderCodeSuffix extracts the last segment of an OrderCode separated by "-".
// "WS-019e-20260608-004" → "004". Falls back to the full code if no "-" found.
func OrderCodeSuffix(code string) string {
	if i := strings.LastIndex(code, "-"); i >= 0 && i+1 < len(code) {
		return code[i+1:]
	}
	return code
}

// ─── emphasised order/table identifiers ───────────────────────────────────
//
// The order code and the table number are the two fields staff SCAN for rather
// than read: the kitchen matches a plate to a ticket by them, the runner finds
// the table by them. Both print bold and enlarged.
//
// **The scale is MEASURED, not chosen.** Doubling the height is free — `ESC i 1 0`
// leaves the character cell one column wide, so no column moves. Doubling the
// WIDTH as well makes every glyph eat two columns, and whether that still fits
// depends on the paper and on data the shop owns: on 58mm (w=32) the 卓 column is
// seven columns wide while a five-character table number at ×2 wants ten. So each
// row asks whether ×2-both fits and falls back to ×2-height-only when it does
// not. That is the same ladder the 精算 header uses for a long store name.
//
// The fallback is not a nicety. A slip that overflows WRAPS — it does not report
// an error — so an unchecked ×2 would arrive as a mangled ticket in a kitchen
// rather than as a red test.
//
// Both helpers are shared by the legacy formatters and the plan-053 renderer on
// purpose: the TR-40 gate holds those two paths to byte-identical output, and
// two copies of this sequence is exactly how that gate starts failing.

// printIssuedAtRow draws the slip's timestamp with the order code pinned to the
// right of the same line:
//
//	2026/06/12 20:14                              #530791
//
// The code rides this line rather than getting one of its own because the row it
// would otherwise occupy is worth more to the two identifiers below it. Empty
// code prints the date alone — a slip with no order code is a real state (the
// shift and doc slips share this emitter), and "#" with nothing after it reads
// like data that failed to load.
func printIssuedAtRow(e *escpos.Encoder, w int, when, orderCode string) {
	if orderCode == "" {
		e.Line(when)
		return
	}
	code := "#" + orderCode
	gap := w - displayWidth(when) - displayWidth(code)
	if gap < 1 {
		// An order code long enough to crowd the timestamp gets its own line,
		// right-aligned. Squeezing both onto one row would push the line past
		// the paper, and an overflowing line WRAPS — the date would reappear
		// mid-slip looking like a second, different timestamp.
		e.Line(when)
		e.Line(spaces(max(w-displayWidth(code), 0)) + code)
		return
	}
	e.Line(when + spaces(gap) + code)
}

// printMoneyTableRow repeats the table number inside the money block, between
// the tax and the balance, exactly where the shop's own slip carries it.
//
// It is a REPEAT, not a second source: a runner reading the foot of the slip to
// find where the food goes should not have to scroll their eye back to the
// header. Takeaway has no table and prints nothing.
func printMoneyTableRow(e *escpos.Encoder, w int, labels printLabels, order *Order) {
	if order == nil || order.OrderType == "takeaway" {
		return
	}
	table := order.TableNumber
	if table == "" {
		table = "-"
	}
	gap := w - displayWidth(labels.Table) - displayWidth(table)
	if gap < 1 {
		gap = 1
	}
	e.Line(labels.Table + spaces(gap) + table)
}

// qrCellSize is the QR module size in dots. Halved from 7 on 2026-08-13 at the
// shop's request: at 7 the symbol ate a third of the sheet. 4, not 3.5 — the
// command takes whole dots, and rounding DOWN would put a 42-character payload
// under the ~4-dot floor most phone cameras need on thermal paper, which fails
// as "the QR does not scan" rather than as anything visible here.
const qrCellSize = 4

// slipTopPadding is the blank leading every order-backed slip prints before its
// first line.
//
// Kitchen tickets are clipped to the rail and bills to the table holder, and the
// clip lands on the LEADING EDGE — precisely where 伝票 and 卓 sit. Enlarging
// those two fields does nothing for a runner if the clip is sitting on top of
// them. Three lines is one constant rather than a per-slip choice because the
// right number is a property of the shop's clips, not of any one slip.
const slipTopPadding = 3

// kitchenExtraTopFeed là số dòng trắng CỘNG THÊM ở đầu phiếu bếp (#3082).
//
// Phiếu đã có `slipTopPadding` = 3 dòng, và thực tế ở quán cho thấy CHƯA ĐỦ:
// bếp kẹp phiếu vào thanh kẹp và thanh kẹp vẫn che mất dòng đầu. Chủ dự án chốt
// 2026-08-17: chừa thêm 3 dòng nữa (tổng 6, ~2 cm trên giấy 80mm).
//
// Cộng thêm chứ không nâng `slipTopPadding`: hằng số đó dùng chung cho MỌI kind
// thuộc họ bill, nên nâng nó là đổi mọi hoá đơn khách của mọi quán để chữa một
// chuyện chỉ xảy ra ở bếp.
//
// Con số này phải KHỚP `kind_defaults.kitchen.top_feed` trong
// `backend/config/print_templates.php` — hai đường in cùng một tờ giấy và cổng
// TR-40 giữ chúng byte-identical.
const kitchenExtraTopFeed = 3

// emphasisSize maps a column scale to the character-expansion command for it.
func emphasisSize(scale int) []byte {
	if scale == 2 {
		return escpos.DoubleSize
	}
	return escpos.DoubleHeight
}

// printOrderMetaRow draws one 伝票 / 卓 row of a bill-family slip, at the
// treatment that slip's kind calls for.
//
// **`receipt` keeps the ORIGINAL sizing** — order number plain, table bold — and
// that is a decision about WHO HOLDS THE PAPER, not an exception to tidy up
// later. The receipt is the copy the customer walks out with, while the order
// code and table number are what STAFF scan for; enlarging them there spends the
// slip's most legible space on a reader who will never use it. Every other kind
// in the family (runner, delta_qr, red_invoice, remaining) enlarges both.
//
// `bold` reproduces the pre-existing split exactly: the table was already bold
// before any of this, the order number was not.
func printOrderMetaRow(e *escpos.Encoder, w int, kind, label, value string, bold bool) {
	if kind != "receipt" {
		printEmphasisRow(e, w, label, value)
		return
	}
	if bold {
		e.Bold(true)
	}
	gap := w - displayWidth(label) - displayWidth(value)
	if gap < 1 {
		gap = 1
	}
	e.Line(label + spaces(gap) + value)
	if bold {
		e.Bold(false)
	}
}

// printEmphasisRow is `row`/`footerRow` with the VALUE emphasised. The label
// stays small: it is the word the reader already knows, the value is the part
// they are looking for.
func printEmphasisRow(e *escpos.Encoder, w int, label, value string) {
	// +1 keeps at least one space between label and value; without it a value
	// that exactly fills the remainder would print flush against the label and
	// read as one word.
	scale := 2
	if displayWidth(label)+1+displayWidth(value)*2 > w {
		scale = 1
	}
	gap := w - displayWidth(label) - displayWidth(value)*scale
	if gap < 1 {
		gap = 1
	}
	e.Text(label + spaces(gap))
	e.Bold(true)
	e.Size(emphasisSize(scale))
	e.Text(value)
	e.Size(escpos.NormalSize)
	e.Bold(false)
	e.Feed(1)
}

// kitchenMetaCell is one column of the kitchen ticket's meta block: the small
// header label and the enlarged value printed beneath it.
type kitchenMetaCell struct {
	label, value string
	// emphasised marks the two IDENTIFIER columns. 提供 and 番号 share the row
	// but not the treatment: enlarging every field on a line emphasises nothing,
	// and the two staff actually scan for are the order code and the table.
	emphasised bool
}

// kitchenMetaCells builds the block's columns.
//
// Takeaway drops the 卓 column entirely — header AND value — so a pickup slip
// carries no table reference at all. A dine-in ticket with a blank table keeps
// the column and prints "-": it MUST have a table, so the empty cell is missing
// data and the dash says so instead of leaving a silent gap.
func kitchenMetaCells(labels printLabels, order *Order, orderNo string, ticketNo int) []kitchenMetaCell {
	// An order with no code prints "-", not an empty column. The blank reads as
	// a field that failed to load; the dash says the order genuinely has no
	// code. This fallback moved here when the kitchen ticket joined the bill
	// template — the bill family does not carry one.
	if orderNo == "" {
		orderNo = "-"
	}
	cells := []kitchenMetaCell{
		{label: labels.OrderMethod, value: orderTypeLabelFor(labels, order.OrderType)},
		{label: labels.TicketSeq, value: fmt.Sprintf("%d", ticketNo)},
		{label: labels.OrderNo, value: orderNo, emphasised: true},
	}
	if order.OrderType == "takeaway" {
		return cells
	}
	table := order.TableNumber
	if table == "" {
		table = "-"
	}
	return append(cells, kitchenMetaCell{label: labels.Table, value: table, emphasised: true})
}

// paymentStateValue resolves the takeaway payment word from the ORDER'S OWN
// money, not from its status: `closed` is reached by voiding as well as by
// paying, and a slip that says PAID because a row moved state is worse than no
// slip at all.
//
// The comparison is `>=`, and the zero-total guard is not defensive noise — a
// slip printed before the order was priced would otherwise satisfy `0 >= 0` and
// announce that nothing is owed. Undecided prints UNPAID: the failure this
// direction produces is a staff member asking a customer who already paid; the
// other direction hands food away for free, and does it silently.
func paymentStateValue(labels printLabels, order *Order) string {
	if OrderIsSettled(order) {
		return labels.PaymentPaid
	}
	return labels.PaymentUnpaid
}

// OrderIsSettled is the ONE test for "this order is paid in full", shared by the
// payment word, the hall QR suppression, and the handler-side status field that
// tells pos-web a Mode A order is still awaiting payment. Two predicates would
// eventually disagree, and the visible form of that is a slip that says 済み
// while still carrying the QR the customer is meant to pay through.
//
// Exported for that third caller in `internal/handler`. It stays the same
// function rather than a copy there for exactly the reason above: a signal that
// disagrees with the word printed on the paper it describes is worse than no
// signal.
func OrderIsSettled(order *Order) bool {
	return order != nil && order.TotalAmount > 0 && order.PaidAmount >= order.TotalAmount
}

// hallKind reports whether a bill kind is one of the two HALL sheets — the ones
// that travel with the food and carry the order QR. The money documents
// (receipt / remaining / red_invoice) are not hall sheets and print no QR at
// all, so the settled-QR rule below has nothing to say about them.
func hallKind(kind string) bool { return kind == "runner" || kind == "delta_qr" }

// hallQRSuppressed reports whether the hall QR must be withheld: the sheet is a
// hall sheet AND the order is already paid.
//
// The QR resolves the order for a customer or a pickup counter to PAY or look
// up an unsettled bill. Once the money is in, it points at a transaction that
// can no longer be acted on, and a scannable code on a finished sheet invites
// exactly the double-payment attempt it cannot serve. Applies to dine-in and
// takeaway alike (chủ dự án 2026-08-17).
func hallQRSuppressed(kind string, order *Order) bool {
	return hallKind(kind) && OrderIsSettled(order)
}

// printKitchenMetaRows draws both rows of the kitchen ticket's meta block:
//
//	提供      番号      伝票      卓
//	店内      319       042       C-07
//
// **Every value shares ONE size.** The four are read as a set — a row of mixed
// sizes reads as a ranking nobody meant — so a field that cannot be doubled
// steps the whole row down rather than leaving the block ragged. Labels stay
// small: only the values are enlarged.
//
// **Column widths are computed, not fixed.** Each column is as wide as the wider
// of its label and its ENLARGED value, and the header is laid out on those same
// widths so the two rows cannot drift apart. Fixed quarters could not survive
// this: 持ち帰り is 8 columns and 16 when doubled, so a takeaway ticket would
// have demoted the entire row — including the fields this exists to make
// legible.
func printKitchenMetaRows(e *escpos.Encoder, w int, cells []kitchenMetaCell) {
	scale := 2
	widths, ok := kitchenMetaColumns(w, cells, scale)
	if !ok {
		scale = 1
		widths, _ = kitchenMetaColumns(w, cells, scale)
	}
	last := len(cells) - 1

	var header strings.Builder
	for i, c := range cells {
		if i == last {
			header.WriteString(c.label)
			break
		}
		header.WriteString(padRight(c.label, widths[i]))
	}
	e.Line(header.String())

	size := emphasisSize(scale)
	for i, c := range cells {
		if c.emphasised {
			e.Bold(true)
			e.Size(size)
		}
		e.Text(c.value)
		if c.emphasised {
			e.Size(escpos.NormalSize)
			e.Bold(false)
		}
		if i == last {
			break
		}
		// Padding is emitted at NORMAL size even beside an enlarged cell. A
		// space printed under ×2 costs TWO columns, so padding measured in real
		// columns but printed doubled would push every column after it right by
		// its own width, out from under the header label it belongs to.
		e.Text(spaces(max(widths[i]-cellColumns(c, scale), 1)))
	}
	e.Feed(1)
}

// cellColumns is how many columns a cell's VALUE occupies once its own
// treatment is applied.
func cellColumns(c kitchenMetaCell, scale int) int {
	if c.emphasised {
		return displayWidth(c.value) * scale
	}
	return displayWidth(c.value)
}

// kitchenMetaColumns sizes each column at the given value scale and reports
// whether the row fits the paper.
//
// Slack is spread evenly between the columns rather than left as one lump on the
// right, which is what makes the block read as a table instead of a left-huddled
// clump with a gap after it.
func kitchenMetaColumns(w int, cells []kitchenMetaCell, scale int) ([]int, bool) {
	widths := make([]int, len(cells))
	content := 0
	for i, c := range cells {
		widths[i] = max(displayWidth(c.label), cellColumns(c, scale))
		content += widths[i]
	}

	gaps := len(cells) - 1
	if gaps < 1 {
		return widths, content <= w
	}
	if content+gaps > w {
		// Best effort: one blank column between neighbours, and the row runs
		// long. Reachable only on narrow paper with an unusually long table
		// name, where the fixed-quarter layout overflowed identically — this
		// path does not make that worse, and the caller has already stepped the
		// scale down before reaching it.
		for i := range gaps {
			widths[i]++
		}
		return widths, false
	}

	slack := w - content
	per, rem := slack/gaps, slack%gaps
	for i := range gaps {
		widths[i] += per
		if i < rem {
			widths[i]++
		}
	}
	return widths, true
}

// orderMetaFieldsFor is the row list a kind's `order_meta` block prints: what
// the LEGACY formatter emits, and the fallback the renderer uses when the
// definition names no fields.
//
// The kitchen ticket declares two extra rows. It shares the bill template now,
// and that template has no four-column header to carry 提供 / 番号 — so they
// become rows like everything else instead of being dropped. Losing the daily
// ticket counter would not be cosmetic: it is how the kitchen sequences its
// slips against the plates coming off the pass.
func orderMetaFieldsFor(kind string) []string {
	if kind == "kitchen" {
		return []string{"order_no", "table", "order_type", "ticket_seq"}
	}
	return []string{"order_no", "table"}
}

// orderTypeLabelFor names the 店内 / 持ち帰り / 予約 distinction in the reader's
// locale.
func orderTypeLabelFor(labels printLabels, orderType string) string {
	switch orderType {
	case "takeaway":
		return labels.Takeaway
	case "spot":
		return labels.Spot
	default:
		return labels.DineIn
	}
}

// printOrderMetaFields draws the `order_meta` rows of a slip.
//
// Shared by the legacy formatters and the plan-053 renderer: the TR-40 gate
// holds those two paths to byte-identical output, and a second copy of this
// switch is how that gate starts failing.
func printOrderMetaFields(
	e *escpos.Encoder, w int, kind string, fields []string,
	labels printLabels, order *Order, orderNo string, ticketNo int,
) {
	// The kitchen keeps the four-column header it always had — the labels on one
	// line, the values enlarged beneath them. It shares the hall slip's template
	// everywhere else; this one block stays because the kitchen reads it as a
	// TABLE (order type, sequence, ticket, table — four facts at a glance), and
	// stacked rows would cost four lines of paper to say the same thing.
	if kind == "kitchen" {
		printKitchenMetaRows(e, w, kitchenMetaCells(labels, order, orderNo, ticketNo))
		printTakeawayPaymentRow(e, w, kind, labels, order)
		return
	}
	for _, f := range fields {
		switch f {
		case "order_no":
			printOrderMetaRow(e, w, kind, labels.OrderNo, orderNo, false)
		case "table":
			// Takeaway has no table — drop the row entirely rather than print an
			// empty one, which would send staff looking for a table that does not
			// exist. The customer header below identifies the order instead.
			if order.OrderType == "takeaway" {
				continue
			}
			// "-" when the table is blank: a dine-in order MUST have one, so the
			// empty cell is missing data and the dash says so.
			table := order.TableNumber
			if table == "" {
				table = "-"
			}
			printOrderMetaRow(e, w, kind, labels.Table, table, true)
		case "order_type":
			printOrderMetaRow(e, w, kind, labels.OrderMethod, orderTypeLabelFor(labels, order.OrderType), false)
		case "ticket_seq":
			printOrderMetaRow(e, w, kind, labels.TicketSeq, fmt.Sprintf("%d", ticketNo), false)
		}
	}
	printTakeawayPaymentRow(e, w, kind, labels, order)
}

// printTakeawayPaymentRow prints the ONE takeaway payment line: directly under
// the order-code block, directly above the customer header.
//
// It is the SECOND of the two fields that separate the takeaway sheets from
// their dine-in twins (chủ dự án 2026-08-17). The first is the table, dropped
// for takeaway in `printOrderMetaFields` / `kitchenMetaCells`; this is what
// takes the freed space. Same three sheets carry both halves of that rule —
// kitchen ticket, delta-QR hall slip, and the runner/hall bill — so a dine-in
// order shows 卓 and no payment word, a takeaway order shows the payment word
// and no 卓, and neither ever shows both or neither.
//
// The whole-order money kinds are NOT in the list: `receipt` · `remaining` ·
// `red_invoice` print `payments`/`remaining` blocks in full, so a second and
// shorter answer to the same question is how two statements about one fact
// start disagreeing.
//
// Emphasised, matching the order code it sits under. `printEmphasisRow` steps
// back to ×1 on its own when the line stops fitting, which is what keeps 58mm
// paper from overflowing.
func printTakeawayPaymentRow(e *escpos.Encoder, w int, kind string, labels printLabels, order *Order) {
	if order == nil || order.OrderType != "takeaway" {
		return
	}
	switch kind {
	case "kitchen", "delta_qr", "runner":
	default:
		return
	}
	printEmphasisRow(e, w, labels.PaymentState, paymentStateValue(labels, order))
}

// FormatKitchenTicket formats the kitchen ticket (phiếu bếp).
//
// It renders through the SAME template as the hall/runner slip; the only
// difference between the two sheets is the QR, which the hall slip carries and
// this one does not.
//
// Two things it does NOT share, and both are about money rather than layout:
//
//   - `total` is the fired BATCH, not the order. A kitchen ticket lists the
//     items just sent to the pass, so the order's own total would not describe
//     the paper it is printed on.
//   - `deltaBill` suppresses the whole-order rows (subtotal, ledger discounts,
//     service charge) for the same reason, and routes the per-rate tax block to
//     a batch recompute (#2170). Without it the sheet would set whole-order
//     money beside a partial item list.
func FormatKitchenTicket(order *Order, items []Item, ticketNo int, config PrintJobConfig) []byte {
	batchTotal := 0
	for _, item := range items {
		batchTotal += item.UnitPrice * item.Quantity
		for _, t := range item.Toppings {
			batchTotal += t.UnitPrice * t.Quantity
		}
	}
	return formatBillTicket(order, items, config, billTicketOpts{
		title:     "", // #chủ dự án 2026-08-17 — tiêu đề bỏ trên mọi phiếu, mọi status
		kind:      "kitchen",
		total:     batchTotal,
		ticketNo:  ticketNo,
		deltaBill: true,
		showQR:    false,
	})
}

// printCustomerHeader prints the takeaway customer's name (+ phone) so both the
// kitchen ticket and the delta-QR serving slip identify who the order is for.
// Only takeaway carries these fields (customer_takeaway_name/phone), so dine-in
// / spot orders print nothing here. Each line is emitted only when non-empty so
// a name-without-phone (or vice versa) never leaves a dangling label.
func printCustomerHeader(e *escpos.Encoder, order *Order, labels printLabels, loc *time.Location) {
	if order == nil || order.OrderType != "takeaway" {
		return
	}
	if name := strings.TrimSpace(order.CustomerTakeawayName); name != "" {
		e.Line(labels.CustomerLabel + ": " + name)
	}
	if phone := strings.TrimSpace(order.CustomerTakeawayPhone); phone != "" {
		e.Line(labels.Phone + ": " + phone)
	}
	if pu := formatPickupTime(order.ScheduledPickupTime, loc); pu != "" {
		e.Line(labels.PickupTime + ": " + pu)
	}
}

// formatPickupTime renders the ISO-8601 pickup timestamp Cloud mirrored into
// "MM/DD HH:MM" for the slip. Returns "" for an empty or unparseable value so a
// missing/garbage pickup never prints a bare label.
//
// #2572 — it takes the SAME zone the slip's date line uses. The comment that
// used to sit here claimed the two matched already ("both call .Local()"), and
// that was half true in the worst way: this line called `.Local()`, the slip
// date line converted nothing at all and printed UTC. Two wall-clock lines on
// one sheet, hours apart, is how a shop learns not to trust either.
func formatPickupTime(raw string, loc *time.Location) string {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return ""
	}
	t, err := time.Parse(time.RFC3339, raw)
	if err != nil {
		return ""
	}
	return t.In(slipLocation(loc)).Format("01/02 15:04")
}

// printKitchenItem prints one item row for the kitchen ticket:
// - item name (+ ※ when this is a reduced-rate 軽減税率 line, plan-043) + price (bold)
// - toppings: "   —— Name        ¥price"  (from item.Toppings)
// - free note: "   Ghi chu: ..."          (from item.Note, no price)
//
// reduced marks the line as 軽減税率対象 → a ※ is appended to the name, matching
// printRunnerItem so the kitchen ticket's ※ markers agree with its tax legend.
func printKitchenItem(e *escpos.Encoder, w, priceColW int, item Item, cur string, reduced bool, locale, notePrefix string) {
	if item.Quantity <= 0 || strings.TrimSpace(item.MenuItemName) == "" {
		return
	}

	name := stripVariantSuffix(item.MenuItemName)
	if reduced {
		name = name + " " + taxLabelsFor(locale).ReducedMarker
	}
	slStr := fmt.Sprintf("%d", item.Quantity)
	priceStr := cur + formatPrice(item.UnitPrice*item.Quantity)
	indentW := utf8.RuneCountInString(slStr) + 2

	e.Bold(true)
	printWrappedName(e, w, slStr, name, priceStr)
	e.Bold(false)

	printToppingLines(e, w, priceColW, indentW, item, cur, locale, notePrefix)
}

// FormatRunnerTicket formats a waiter/hold ticket (hóa đơn bàn).
//
// Always shows ALL items of the order (not just the newly fired batch)
// so the waiter has a complete bill. On re-fire (add more items), the
// previous hold slip is superseded by this new full reprint.
//
//	ベト屋                       HOA DON BAN
//	VIET ORIGIN
//	2026/01/10 12:49                   #525
//	So HD                              Tong: ¥1,780
//	  525                              Ban:  C-07
//	- - - - - - - - - - - - - - - - - - - -
//	San pham                      Thanh tien
//	1  Bun ga la chanh               ¥980
//	   —— Them rau mui               ¥150
//	   Ghi chu: Khong hanh
//	- - - - - - - - - - - - - - - - - - - -
//	Tong (co thue)                  ¥1,780
//	Thue                              ¥162
//	Con lai                             ¥0
//	[QR — order.ID centered]
func FormatRunnerTicket(order *Order, items []Item, ticketNo int, config PrintJobConfig) []byte {
	// The engine-stamped order total, verbatim — never re-derived from the lines
	// (#2067). See orderPrintTotal.
	total := orderPrintTotal(order)
	return formatBillTicket(order, items, config, billTicketOpts{
		title:     "", // bỏ tiêu đề — xem FormatKitchenTicket
		kind:      "runner",
		total:     total,
		remaining: total - order.PaidAmount,
		showQR:    true,
	})
}

// FormatDeltaQRTicket is the customer-facing QR slip printed on each fire. It
// lists ONLY the newly-fired items (the delta) with their prices + a delta
// subtotal, plus a QR (order.ID) the customer scans at a kiosk. This is the
// per-fire counterpart to FormatRunnerTicket (the full-order QR bill printed
// at checkout): both carry the same order QR, but this one shows just "món vừa
// thêm". deltaBill mode suppresses the whole-order tax breakdown so the total
// matches the partial item list.
func FormatDeltaQRTicket(order *Order, items []Item, config PrintJobConfig) []byte {
	// Sum the delta lines (unit_price*qty + toppings) — same basis as the
	// kitchen ticket's batch total, so the two fire slips agree.
	total := 0
	for _, it := range items {
		total += it.UnitPrice * it.Quantity
		for _, t := range it.Toppings {
			total += t.UnitPrice * t.Quantity
		}
	}
	return formatBillTicket(order, items, config, billTicketOpts{
		// Bỏ tiêu đề — xem FormatKitchenTicket. This kind used to be the one
		// with a DATA-DEPENDENT title (追加商品, or 持ち帰り on a takeaway order);
		// with no title printed at all the branch has nothing left to decide,
		// and its mirror in `billTitle` goes with it.
		title:     "",
		kind:      "delta_qr",
		total:     total,
		showQR:    true,
		deltaBill: true,
	})
}

// kioskQRPayload builds the string embedded in every bill/serving-slip QR. It
// mirrors customer-web's order QR byte-for-byte — JSON {"orderId","orderCode",
// "type"} (see godx-tempo-customer-web order-success/page.tsx) — so the kiosk's
// hardware-scan fast-path (godx-kiosk advertise.tsx handleHardwareScan) reads
// `orderCode` straight off the parsed JSON and resolves the order via Cloud's
// GET /api/v1/kiosk/orders?code=.
//
// The previous payload was a bare order.ID (UUID), which matched NEITHER the
// JSON fast-path NOR the opaque-token /customer/qr/{token} route, so a scanned
// slip always 404'd at the kiosk. orderCode is the resolve key; orderId rides
// along for parity with customer-web and future by-id resolution.
func kioskQRPayload(order *Order) string {
	b, err := json.Marshal(struct {
		OrderID   string `json:"orderId"`
		OrderCode string `json:"orderCode"`
		Type      string `json:"type"`
	}{
		OrderID:   order.ID,
		OrderCode: order.OrderCode,
		Type:      order.OrderType,
	})
	if err != nil {
		// Marshalling three plain strings cannot fail in practice; keep the slip
		// carrying the id rather than an empty QR if it somehow does.
		return order.ID
	}
	return string(b)
}

// PaymentSlipInfo carries the per-slip payment context for a paid receipt.
// For split bills, AmountPaid is this slip's amount (not the order total) and
// SlipIndex/SplitCount drive the "Khach n/N" label.
type PaymentSlipInfo struct {
	PaymentMethod string // e.g. "cash", "qr", "card" — printed as-is if non-empty
	AmountPaid    int    // amount settled on THIS slip (split) or the full total
	SlipIndex     int    // 1-based index of this payment within the order (0 = unknown)
	SplitCount    int    // total people in the split (0/1 = not a split)
	Remaining     int    // "Con lai" on the slip (sub-bill remainder for a split)
	BillTotal     int    // headline "Tong" — this slip's sub-bill total (0 = use order total)
	// Plan-038 T2.4 — by_amount per-person label ("Người 1", "Người 2", …).
	// When set, the formatter prints "Khach: <label>" instead of the
	// numeric "Khach n/N" so the slip identifies the payer by name.
	Label string
	// Cash tendered + change returned. Printed on the paid slip only when
	// Tendered > 0 (a cash payment recorded the amount handed over). Both are
	// omitted for non-cash methods where they don't apply.
	Tendered int
	Change   int
	// CustomerName — when non-empty the slip prints a "Khach hang: <name>" line.
	// Used by the red-invoice (hoá đơn đỏ) variant which names the payer.
	CustomerName string
	// Split visualization (share-bill receipts). SplitMode drives the top
	// "HOA DON CHIA" banner + the share breakdown in the footer:
	//   - "even" + "by_amount": full items shown; footer prints
	//     Tong don (OrderGrossTotal) then Phan chia (i/N) = AmountPaid.
	//   - "by_items": only this payer's items shown; footer prints Phan chia
	//     (i/N) = this slip's total, then Tong don (OrderGrossTotal) as context.
	// Empty SplitMode + SplitCount<=1 → a normal (non-split) receipt.
	SplitMode       string
	OrderGrossTotal int // whole-order gross total, for the "Tong don" context line
	// ReprintNumber — 0/1 = first print, ≥2 prints the locked 「BAN IN #N」 mark
	// (plan-052 P-10b). The number comes from AppendPrintHistory, which stays
	// the single atomic source of N (P-12); this field only carries it to the
	// paper. Receipts and red invoices had NO mark before #1166 — a second copy
	// was indistinguishable from the original, which is exactly what the (now
	// removed) 422 was failing to prevent.
	ReprintNumber int
}

// splitModeKind classifies a slip's split mode. Returns "" for a non-split slip.
func (slip *PaymentSlipInfo) splitModeKind() string {
	if slip == nil {
		return ""
	}
	switch slip.SplitMode {
	case "by_items":
		return "by_items"
	case "by_amount":
		return "by_amount"
	case "even":
		return "even"
	}
	// Legacy: a positive multi-count split with no explicit mode → treat as even.
	if slip.SplitCount > 1 {
		return "even"
	}
	return ""
}

// FormatPaidTicket formats a "DA THANH TOAN" receipt — a runner-ticket clone
// with NO QR, a paid-status title, the payment method, and Con lai = ¥0
// (the order is fully settled, or this slip closed the split).
//
//	ベト屋                      DA THANH TOAN
//	VIET ORIGIN
//	2026/01/10 12:49                   #525
//	...items...
//	- - - - - - - - - - - - - - - - - - - -
//	Tong (co thue)                  ¥1,780
//	Thue                              ¥162
//	Da thanh toan                   ¥1,780
//	Phuong thuc                         cash
//	Con lai                             ¥0
//	(no QR)
func FormatPaidTicket(order *Order, items []Item, ticketNo int, config PrintJobConfig, slip PaymentSlipInfo) []byte {
	// Headline total is this slip's sub-bill when set (split), else the order's
	// gross total. Without BillTotal every split slip would print the whole bill.
	total := slip.BillTotal
	if total <= 0 {
		total = orderPrintTotal(order)
	}
	// Paid amount shown is this slip's amount when known, else the full total.
	paid := slip.AmountPaid
	if paid <= 0 {
		paid = total
	}
	return formatBillTicket(order, items, config, billTicketOpts{
		title:     "", // bỏ tiêu đề — xem FormatKitchenTicket
		kind:      "receipt",
		total:     total,
		remaining: slip.Remaining, // real "Con lai" (0 on a full settle, >0 on a partial)
		showQR:    false,
		slip:      &slip,
		paidShown: paid,
		reprintNo: slip.ReprintNumber,
	})
}

// FormatRedInvoiceTicket formats the hoá đơn đỏ (red invoice) — identical to the
// paid receipt (items + totals + payment method + tendered/change) but titled
// as a red invoice and ALWAYS carrying a customer-name line (the entered name,
// or a blank underline to hand-write it). slip.CustomerName carries the name.
func FormatRedInvoiceTicket(order *Order, items []Item, config PrintJobConfig, slip PaymentSlipInfo) []byte {
	total := slip.BillTotal
	if total <= 0 {
		total = orderPrintTotal(order)
	}
	paid := slip.AmountPaid
	if paid <= 0 {
		paid = total
	}
	return formatBillTicket(order, items, config, billTicketOpts{
		title:      printLabelsFor(config.Locale).TitleRedInvoice,
		kind:       "red_invoice",
		total:      total,
		remaining:  slip.Remaining,
		showQR:     false,
		slip:       &slip,
		paidShown:  paid,
		redInvoice: true,
		reprintNo:  slip.ReprintNumber,
	})
}

// FormatRemainingTicket formats the "phan con lai" slip for a split bill —
// identical to the runner ticket (WITH QR so the next person can pay) except
// the headline Total is the REMAINING amount, not the order's gross total.
func FormatRemainingTicket(order *Order, items []Item, ticketNo int, config PrintJobConfig, remaining int) []byte {
	if remaining < 0 {
		remaining = 0
	}
	total := orderPrintTotal(order)
	return formatBillTicket(order, items, config, billTicketOpts{
		title:     printLabelsFor(config.Locale).TitleRemaining,
		kind:      "remaining",
		total:     total,
		remaining: remaining,
		showQR:    true,
	})
}

// orderPrintTotal returns the order's gross total for a money slip: the
// engine-stamped `order.total_amount`, and nothing else (#2067).
//
// It takes NO items on purpose. The previous version summed the item lines
// whenever the header total was 0, which put a second pricing implementation in
// the print layer — one that could not see any of the things that make a total a
// total. It ignored `order_conditions` wholesale: discounts, coupons and the
// service charge never reached it, and it counted VOIDED lines because it never
// looked at `voided_at`. Two ordinary situations therefore printed a number the
// shop is not owed:
//
//   - a bill fully covered by a coupon (header total 0, lines still priced) came
//     out at the pre-discount gross;
//   - an order whose lines were all voided came out at the pre-void gross.
//
// Removing the parameter is the guard: with no items in scope there is nothing
// left in this function to sum, so the fallback cannot grow back by accident.
//
// A missing total is an upstream defect and must be fixed upstream — the
// handlers already run the ENGINE's `NormalizedTotals` through
// `normalizeOrderForPrint` for exactly this, which is the right place for it
// (plan-053 §1: money comes from the engine, the template only presents it).
// Here it surfaces as a visible ¥0 plus a warning, not as a plausible invention.
func orderPrintTotal(order *Order) int {
	if order == nil || order.TotalAmount <= 0 {
		warnPrintTotalMissing(order)
		return 0
	}
	return order.TotalAmount
}

// warnPrintTotalMissing leaves the trail for a money slip printed off an order
// with no positive total (#2067) — either genuinely free (a 100% coupon) or
// never costed. The print layer cannot tell those apart and must not guess;
// both are worth a line in the log.
func warnPrintTotalMissing(order *Order) {
	orderID, orderCode := "", ""
	if order != nil {
		orderID, orderCode = order.ID, order.OrderCode
	}
	slog.Warn("print: order has no positive total — the slip prints 0, not a recomputed figure",
		"order_id", orderID,
		"order_code", orderCode,
		"detail", "order.total_amount <= 0 (#2067). If this is not a fully-discounted "+
			"bill, the order was never costed — fix the total upstream (OrderEngine / "+
			"normalizeOrderForPrint); the print layer must not re-derive it from the lines.")
}

// splitIdxSuffix returns " (i/N)" when the slip is one bill of a multi-way
// split, else "". Appended to the "HOA DON CHIA" title + the "Phan chia" line.
func splitIdxSuffix(slip *PaymentSlipInfo) string {
	if slip != nil && slip.SplitCount > 1 && slip.SlipIndex > 0 {
		return fmt.Sprintf(" (%d/%d)", slip.SlipIndex, slip.SplitCount)
	}
	return ""
}

// printSplitBanner renders the top "HOA DON CHIA" header block on a share
// receipt (centered within the paper width):
//
//	========================================
//	           HOA DON CHIA (1/2)
//	           Chia deu - 2 nguoi
//	           Nguoi 1                       (only when a label is set)
//	========================================
//
// No-op for a non-split slip (SplitMode empty + SplitCount <= 1).
func printSplitBanner(e *escpos.Encoder, w int, labels printLabels, slip *PaymentSlipInfo) {
	kind := slip.splitModeKind()
	if kind == "" {
		return
	}
	center := func(s string) string {
		dw := displayWidth(s)
		if dw >= w {
			return s
		}
		return spaces((w-dw)/2) + s
	}
	bar := strings.Repeat("=", w)
	e.Feed(1)
	e.Line(bar)
	e.Bold(true)
	e.Line(center(labels.SplitTitle + splitIdxSuffix(slip)))
	e.Bold(false)
	mode := labels.splitModeText(kind)
	if slip.SplitCount > 1 {
		mode = fmt.Sprintf("%s - %d %s", mode, slip.SplitCount, labels.SplitPeople)
	}
	e.Line(center(mode))
	if lbl := strings.TrimSpace(slip.Label); lbl != "" {
		e.Line(center(lbl))
	}
	e.Line(bar)
}

// billTicketOpts parameterises the shared bill-ticket body so the runner,
// paid, and remaining slips share one layout (header → items → totals → QR).
type billTicketOpts struct {
	title     string           // header right-side title
	total     int              // gross total (tax-included)
	remaining int              // "Con lai" value
	showQR    bool             // draw order.ID QR at the bottom
	slip      *PaymentSlipInfo // non-nil on paid tickets → print payment method / slip label
	paidShown int              // "Da thanh toan" amount on paid tickets
	// deltaBill: this slip lists only a DELTA of the order (the newly-fired
	// items), so the whole-order breakdown (Tam tinh / per-rate tax) wouldn't
	// match — suppress it and print just the delta Tong (opts.total), like a
	// split sub-bill but without the "Khach n/N" labels.
	deltaBill bool
	// redInvoice: the hoá đơn đỏ variant — always prints a customer line (the
	// entered name, or a blank underline to hand-write it).
	redInvoice bool
	// kind: kind của phiếu, dùng để tra ĐÚNG bộ `store_info.fields` mà đường
	// template sẽ in (#2000 bước 6). Rỗng → không in dòng danh tính nào, tức
	// hành vi trước bài này.
	kind string
	// ticketNo: bộ đếm phiếu bếp trong ngày, in thành hàng `ticket_seq`. Chỉ
	// phiếu bếp khai hàng đó; các kind khác bỏ qua giá trị này.
	ticketNo int
	// reprintNo: ≥2 prints the locked 「BAN IN #N」 mark (plan-052 P-10b), at
	// the position the `reprint_marker` block occupies in the template — after
	// the totals, before the QR/tail.
	reprintNo int
}

// formatBillTicket renders the common runner/paid/remaining layout.
func formatBillTicket(order *Order, items []Item, config PrintJobConfig, opts billTicketOpts) []byte {
	w := config.PaperWidth
	if w == 0 {
		w = 48
	}

	e := escpos.New()
	e.SetLeftMargin(config.leftMargin(w))
	e.Align(escpos.AlignLeft)
	e.Feed(slipTopPadding)
	if opts.kind == "kitchen" {
		e.Feed(kitchenExtraTopFeed)
	}

	labels := printLabelsFor(config.Locale)

	// ─── store name (left) + title (right) ───
	// If both fit on one line (with at least 1 space gap), print together.
	// Otherwise print on separate lines to avoid wrapping.
	storeName := config.StoreName
	if storeName == "" {
		storeName = "Store"
	}
	// #2000 bước 6 — dòng danh tính đứng TRÊN dòng "cửa hàng + tiêu đề", đọc từ
	// CHÍNH bộ field mà đường template dùng. Không gõ lại danh sách: hai bản sẽ
	// trôi khỏi nhau, và chỗ trôi là nơi phiếu dự phòng bắt đầu khác phiếu
	// thường — im lặng, đúng lúc renderer đang hỏng.
	storeFields := StoreFieldsForKind(opts.kind)
	for _, line := range StoreDetailValues(config, storeFields, true) {
		e.Line(line)
	}

	// Mirror of emitBillHeader's switch, and the empty-title arm is the one that
	// matters now that no bill kind prints a title: the old two-branch form fell
	// into `storeName + spaces(gap) + ""` and padded the line out to the full
	// paper width with trailing blanks. Invisible on paper, 36 bytes on the
	// wire — enough to break TR-40 against a renderer that simply prints the
	// name. Measured, not reasoned: legacy 1092 vs rendered 1056.
	title := opts.title
	titleW := displayWidth(title)
	storeDispW := displayWidth(storeName)
	e.Bold(true)
	switch {
	case storeName != "" && title != "":
		if storeDispW+1+titleW <= w {
			e.Line(storeName + spaces(w-storeDispW-titleW) + title)
		} else {
			e.Line(storeName)
			e.Line(spaces(w-titleW) + title)
		}
	case storeName != "":
		e.Line(storeName)
	case title != "":
		e.Line(spaces(w-titleW) + title)
	}
	e.Bold(false)

	for _, line := range StoreDetailValues(config, storeFields, false) {
		e.Line(line)
	}

	// ─── date/time (left) ───
	// The #orderCode suffix used to print at the right here; removed per request
	// (the code still appears in the "So HD" footer row below). suffix is kept
	// because that footer row consumes it.
	// #2065 — 取引年月日 is the SALE, not the print. `orderSlipTime` is the same
	// function PrintRenderData.now() ends at, so the legacy path and the template
	// path cannot drift on this field.
	// #2572 — and it is written in the SHOP's zone, not the UTC the timestamp
	// was parsed as.
	//
	// NO order code on this line (chủ dự án 2026-08-17, every kind, every
	// status). The `order_meta` block below already prints 伝票番号 at ×2, which
	// is the copy staff actually read; the small `#1234` here was the same fact
	// a second time, four lines above itself.
	suffix := OrderCodeSuffix(order.OrderCode)
	dateStr := orderSlipTimeForKind(opts.kind, order, config.slipLocation()).Format("2006/01/02 15:04")
	printIssuedAtRow(e, w, dateStr, "")

	// ─── split banner (share receipts): "HOA DON CHIA (i/N) / <mode> - N nguoi" ───
	printSplitBanner(e, w, labels, opts.slip)

	// ─── So HD + Ban ───
	e.Feed(1)
	tableStr := order.TableNumber
	if tableStr == "" {
		tableStr = "-"
	}
	footerRow := func(label, value string) {
		// DISPLAY width, not rune count — a Japanese label ("サービス料") is
		// fullwidth (2 cols/char), so rune count under-measures it and the row
		// overflows the paper and wraps. displayWidth keeps every locale on one
		// line.
		gap := w - displayWidth(label) - displayWidth(value)
		if gap < 1 {
			gap = 1
		}
		e.Line(label + spaces(gap) + value)
	}
	printOrderMetaFields(e, w, opts.kind, orderMetaFieldsFor(opts.kind),
		labels, order, suffix, opts.ticketNo)
	// Red-invoice customer line: the entered name, or a blank underline to
	// hand-write it. Normal slips only print it when a name was supplied.
	if opts.slip != nil {
		if cn := strings.TrimSpace(opts.slip.CustomerName); cn != "" {
			e.Line(labels.CustomerLabel + ": " + cn)
		} else if opts.redInvoice {
			e.Line(labels.CustomerLabel + ": " + strings.Repeat("_", 18))
		}
	}
	// Legacy split label ("Khach n/N"). Only for slips WITHOUT the split banner
	// (which already carries mode + i/N + label) — i.e. non-split slips, where
	// these conditions are all false anyway. Kept for backward compatibility.
	if opts.slip != nil && opts.slip.splitModeKind() == "" {
		label := strings.TrimSpace(opts.slip.Label)
		switch {
		case label != "" && opts.slip.SplitCount > 1 && opts.slip.SlipIndex > 0:
			footerRow(labels.Guest, fmt.Sprintf("%s (%d/%d)", label, opts.slip.SlipIndex, opts.slip.SplitCount))
		case label != "":
			footerRow(labels.Guest, label)
		case opts.slip.SplitCount > 1 && opts.slip.SlipIndex > 0:
			footerRow(labels.Guest, fmt.Sprintf("%d/%d", opts.slip.SlipIndex, opts.slip.SplitCount))
		}
	}
	// ─── takeaway customer (Khach hang / SDT) — who the order is for ───
	// Skipped on split sub-bills that already name the payer via slip.CustomerName
	// above, to avoid printing the customer twice.
	if opts.slip == nil || strings.TrimSpace(opts.slip.CustomerName) == "" {
		printCustomerHeader(e, order, labels, config.slipLocation())
	}

	// ─── order-level note (Ghi chu) ───
	if note := strings.TrimSpace(order.Note); note != "" {
		printNoteLines(e, w, 0, note, labels.NotePrefix)
	}

	// ─── column header ───
	// No rule between the blocks: the shop's own slip (IMG_6128) frames these
	// sections with WHITESPACE. A dashed line here cost a row of paper per
	// section and said nothing the blank line does not.
	e.Feed(1)
	colRight := labels.Price
	colLeft := labels.Item
	e.Line(padRight(colLeft, w-displayWidth(colRight)) + colRight)
	e.Feed(1)

	// ─── per-rate tax breakdown (plan-043 T4.1) ───
	// #2170 — ledger-first for a whole-order slip: the `order_conditions` tax
	// rows riding on order.TaxLines are what Cloud prints for the same order,
	// so they win when present. Fallbacks stay layered underneath: an unpriced
	// order recomputes from the per-line snapshots (item.TaxRate); no line
	// stamped → legacy single-line Thue. A per-fire DELTA slip always
	// recomputes on its own items — the whole-order ledger cannot describe one
	// batch. Drives both the ※ reduced-rate marker on item names below AND the
	// per-rate footer blocks.
	taxSummary := receiptTaxSummaryForKind(opts.kind, opts.deltaBill, order, items, config.step())

	// A split SUB-bill shows a monetary SHARE (opts.total) that doesn't correspond
	// to its item slice, so the whole per-rate インボイス breakdown stays suppressed
	// (Q13) — a ※ marker whose rate group isn't derivable is confusing.
	//
	// A per-fire DELTA slip (hold/serving) is different: opts.total IS the sum of
	// the delta items, so computeReceiptTaxSummary(order, deltaItems) matches it
	// to the yen (never the whole-order ledger — #2170, see
	// receiptTaxSummaryForKind). The delta slip DOES print the tax breakdown
	// (computed on the fired items) — only the whole-order subtotal/service rows
	// stay suppressed below
	// (they'd dwarf the delta). suppressOrderRows gates those; showTaxBreakdown
	// gates the per-rate blocks + ※ markers/legend.
	isSplitSubBill := opts.slip != nil && opts.slip.SplitCount > 1 && opts.slip.BillTotal > 0
	showTaxBreakdown := !isSplitSubBill
	suppressOrderRows := isSplitSubBill || opts.deltaBill

	// ─── items ───
	priceColW := slipPriceWidth(items, config.cur())
	// Dòng món in ở CỠ THƯỜNG trên mọi kind của họ bill — phiếu bếp bằng đúng
	// phiếu hall (chủ dự án 2026-08-17). #3082 từng cho riêng phiếu bếp `tall`
	// (×2 cao); nhánh đó đã gỡ, cùng với `kind_overrides.kitchen.items.size`
	// phía Cloud. Gỡ MỘT phía là hai đường in lệch nhau ngay dòng món đầu tiên.
	for _, item := range items {
		reduced := showTaxBreakdown && taxSummary.HasReduced && isReducedLine(item, taxSummary.blockMaxRate())
		printRunnerItem(e, w, priceColW, item, config.cur(), reduced, config.Locale, labels.NotePrefix)
	}

	// ─── footer totals ───
	printFooterRule(e, w, opts.kind)

	// Additive breakdown matching the order/screen (subtotal + service + tax =
	// total). Each component prints only when present, so a legacy tax-included
	// order (subtotal/service = 0) still renders a clean Thue + Tong pair, while
	// a normalized order shows Tam tinh / Phi phuc vu / Thue / Tong exactly as
	// the kiosk bill — no more mismatch between print and screen.
	//
	// For a split SUB-bill (opts.total is this person's share, not the order
	// total) the order-level subtotal/tax/service don't correspond to the
	// headline, so we skip the breakdown and print only the sub-bill Tong.
	//
	// plan-043 Q13 (default): the per-rate インボイス breakdown (8%対象 / 10%対象
	// blocks + ※ legend) is suppressed on a split sub-bill (its share isn't
	// derivable from the whole-order snapshots) via showTaxBreakdown; a delta slip
	// keeps its breakdown but drops these whole-order subtotal/service rows via
	// suppressOrderRows, both computed above the items loop so the ※ markers stay
	// in step.
	if !suppressOrderRows {
		if order.Subtotal > 0 {
			footerRow(labels.Subtotal, config.cur()+formatPrice(order.Subtotal))
		}
		// #2071 — per-rate discount rows from the `order_conditions` ledger,
		// between subtotal and service charge (the catalog's block order for
		// `receipt`). Gated on the KIND because only `receipt` declares the
		// `discounts` block (config/print_blocks.php) — the template renderer
		// draws it nowhere else, and the TR-40 gate compares this formatter
		// against that renderer byte for byte. The row strings come from the
		// SAME helpers the emitter uses (discountRowLabel/-Value), so the two
		// paths can only differ on where they are called.
		if opts.kind == "receipt" {
			for _, d := range order.Discounts {
				footerRow(discountRowLabel(labels, d), discountRowValue(config.cur(), d))
			}
		}
		if order.ServiceCharge > 0 {
			footerRow(labels.ServiceCharge, config.cur()+formatPrice(order.ServiceCharge))
		}
		// The per-rate tax breakdown is printed BELOW the grand total + indented
		// (issue #1042 layout) — see after the total line.
	}

	// Total line(s). For a split slip we show the share visualization the
	// operator confirmed: whole-order "Tong don" + this payer's "Phan chia (i/N)".
	//   - by_items: this payer's món total IS their share → headline "Phan chia",
	//     then "Tong don" (whole order) as context.
	//   - equal / by_amount: full món shown → headline "Tong don" (order gross),
	//     then "Phan chia (i/N)" = this payer's amount.
	switch opts.slip.splitModeKind() {
	case "by_items":
		e.Bold(true)
		footerRow(labels.SplitShare+splitIdxSuffix(opts.slip), config.cur()+formatPrice(opts.total))
		e.Bold(false)
		footerRow(labels.OrderTotal, config.cur()+formatPrice(opts.slip.OrderGrossTotal))
	case "even", "by_amount":
		e.Bold(true)
		footerRow(labels.OrderTotal, config.cur()+formatPrice(opts.total))
		e.Bold(false)
		e.Bold(true)
		footerRow(labels.SplitShare+splitIdxSuffix(opts.slip), config.cur()+formatPrice(opts.slip.AmountPaid))
		e.Bold(false)
	default:
		e.Bold(true)
		footerRow(labels.Total, config.cur()+formatPrice(opts.total))
		e.Bold(false)
	}

	// ─── per-rate tax breakdown — BELOW the grand total, INDENTED (issue #1042) ───
	// The tax is 内税 (already inside the total), so it reads as an informational
	// split UNDER the total, followed by the ※ legend + 登録番号. Shared with the
	// kitchen ticket + per-fire delta slip (printTaxBreakdown). The legacy row
	// carries order.TaxAmount on a full bill; a delta slip has no tax fact of its
	// own (its total is only the fired items) and prints no row (#2067).
	if showTaxBreakdown {
		stampedOrderTax := int(math.Round(order.TaxAmount))
		authoritative := !opts.deltaBill
		if opts.deltaBill {
			stampedOrderTax = 0
		}
		printTaxBreakdown(e, w, order, config, taxSummary, opts.total, stampedOrderTax, authoritative, opts.kind)
	} else if reg := strings.TrimSpace(config.SellerRegistrationNumber); reg != "" {
		// #2064 — a split SUB-bill suppresses the per-rate blocks + ※ legend
		// (their share isn't derivable from the whole-order snapshots, Q13) but
		// must still carry the seller's 登録番号: it is the seller's identity
		// (適格簡易請求書 field ①), not a property of the breakdown, and each
		// guest's sub-bill is the document THEY hold. Same bytes and the same
		// position as printTaxBreakdown's tail — the template path prints it via
		// emitOrderRegistrationNumber, which is no longer gated either.
		e.Line(taxLabelsFor(config.Locale).RegistrationNumber + ": " + reg)
	}

	// Paid-ticket extras: amount settled on this slip + payment method.
	if opts.slip != nil {
		e.Bold(true)
		footerRow(labels.PaidAmount, config.cur()+formatPrice(opts.paidShown))
		e.Bold(false)
		if m := strings.TrimSpace(opts.slip.PaymentMethod); m != "" {
			footerRow(labels.PaymentMethod, m)
		}
		// Cash tendered + change — only when the payment recorded a tendered
		// amount (cash). Non-cash methods leave these zero and print nothing.
		if opts.slip.Tendered > 0 {
			footerRow(labels.Tendered, config.cur()+formatPrice(opts.slip.Tendered))
			footerRow(labels.Change, config.cur()+formatPrice(opts.slip.Change))
		}
	}

	// "Con lai" only when there's actually something left (or an over-payment
	// excess) — a clean full settle doesn't print a ¥0 line.
	if opts.remaining > 0 {
		// The table repeat sits OUTSIDE the bold: it is a convenience line, not
		// the headline. Keep it here and in emitBillRemaining in the same order
		// — TR-40 compares the two paths byte for byte, and a bold toggle on the
		// wrong side of it produces two streams of identical LENGTH that differ.
		printMoneyTableRow(e, w, labels, order)
		e.Bold(true)
		footerRow(labels.Remaining, config.cur()+formatPrice(opts.remaining))
		e.Bold(false)
	}

	// ─── plan-052 P-10b: 「BAN IN #N」 ───
	// Placed here because that is where the `reprint_marker` block sits in the
	// bill-family templates (after the totals, before the QR). The block order
	// is the shared Cloud↔workstation catalog — moving the mark to the header
	// would need both catalogs changed together, so it stays put and the
	// renderer/formatter agree byte for byte.
	printReprintMarker(e, w, opts.reprintNo, config.Locale)

	// ─── QR code centered ───
	// A settled hall sheet drops it — see hallQRSuppressed. The `else` branch
	// below still feeds the tail margin, so suppressing the code does not
	// shorten the paper before the cut.
	if opts.showQR && !hallQRSuppressed(opts.kind, order) {
		e.Feed(2)
		e.Align(escpos.AlignCenter)
		// JSON {orderId,orderCode,type}, identical to customer-web's order QR, so
		// the kiosk scan resolves the order by orderCode (see kioskQRPayload).
		e.QRCode(kioskQRPayload(order), qrCellSize)
		e.Feed(2)
		e.Align(escpos.AlignLeft)
	} else {
		e.Feed(2)
	}

	e.FullCut()
	return e.Bytes()
}

// printTaxBreakdown renders the per-rate 内税 breakdown (plan-043) indented below
// a total line, followed by the ※ reduced-rate legend and the seller 登録番号 —
// the shared tax footer used by the customer bill (formatBillTicket), the kitchen
// ticket (FormatKitchenTicket), and the per-fire hold/delta slip. Each caller
// passes a taxSummary built from the SAME items it displays so the breakdown
// always agrees with the printed total. When no line carries a rate snapshot
// (snapshot-less order) it falls back to a single tax-included 税/Thue line carrying
// order.TaxAmount — and to NOTHING AT ALL when even that is absent (#2067), see
// stampedTaxRow. stampedOrderTax lets the full bill pass the authoritative
// order.TaxAmount; partial slips (kitchen batch / delta) pass 0 because the
// whole order's tax does not describe their subset, and `authoritative` says
// which of the two this caller is so the omission is only logged when a tax fact
// was actually expected.
func printTaxBreakdown(e *escpos.Encoder, w int, order *Order, config PrintJobConfig, taxSummary receiptTaxSummary, total, stampedOrderTax int, authoritative bool, kind string) {
	const taxIndent = 3
	tl := taxLabelsFor(config.Locale)
	if len(taxSummary.Blocks) > 0 {
		wrap := taxBlocksNeedWrap(w, w-taxIndent, tl, config.cur(), taxSummary.Blocks)
		for _, block := range taxSummary.Blocks {
			for _, line := range formatRateBlockLines(w-taxIndent, tl, config.cur(), block, wrap) {
				e.Line(spaces(taxIndent) + line)
			}
		}
	} else {
		// Aggregate path: the single tax-included row, printed ONLY from the order's
		// own tax_amount. No rate, no arithmetic — see stampedTaxRow (#2067).
		if taxAmount, ok := stampedTaxRow(order, kind, stampedOrderTax, total, authoritative); ok {
			label := printLabelsFor(config.Locale).Tax
			value := config.cur() + formatPrice(taxAmount)
			gap := (w - taxIndent) - displayWidth(label) - displayWidth(value)
			if gap < 1 {
				gap = 1
			}
			e.Line(spaces(taxIndent) + label + spaces(gap) + value)
		}
	}

	// ─── ※ legend + T+13 registration number (plan-043 T4.1) ───
	// The ※ legend prints when a reduced-rate line was marked; 登録番号 prints only
	// when the shop carries a non-empty seller registration number (Q5 — usually null).
	if taxSummary.HasReduced {
		e.Line(tl.ReducedLegend)
	}
	// #2928 — KHÔNG in trên phiếu bếp. Ruling chủ dự án 2026-08-16: "mã số thuế
	// chỉ hiển thị trên hoá đơn mà thôi". `登録番号` là thông tin của HOÁ ĐƠN
	// THUẾ (#1152); phiếu bếp không phải chứng từ và không rời khỏi bếp.
	//
	// Chặn ở ĐÂY vì `printTaxBreakdown` là helper DÙNG CHUNG — phiếu bếp không
	// có hàm in riêng, nên nó thừa hưởng dòng này từ phiếu khách. Giữ đồng bộ
	// với khối `registration_number` bị tắt ở layer 0 (Cloud + Go): cổng TR-40
	// đòi hai bản render ra CÙNG byte, nên đổi một bên mà quên bên kia là đỏ.
	if reg := strings.TrimSpace(config.SellerRegistrationNumber); reg != "" && kind != "kitchen" {
		// Flush-left "登録番号: {value}" per the reference mockup — not a
		// right-aligned amount row.
		e.Line(tl.RegistrationNumber + ": " + reg)
	}
}

// printRunnerItem prints one item row for the runner/hold ticket:
// - item name (+ ※ when this is a reduced-rate 軽減税率 line, plan-043 T4.1) + price
// - toppings: "   —— Name        ¥price"  (em-dash style, from item.Toppings)
// - free note: "   Ghi chu: ..."
//
// reduced marks the line as 軽減税率対象 → a ※ is appended to the name so the
// receipt satisfies インボイス §1.3.2 (8% items must be marked). locale is
// unused for the marker (※ is a locale-neutral glyph) but threaded for parity
// with the rest of the localized print path.
// printFooterRule closes the item table.
//
// Only the HALL slip draws a rule here. It is the sheet a runner reads at a
// glance while walking, and the money footer is the part they are NOT reading —
// the line tells the eye where to stop. The kitchen ticket and the customer's
// receipt are both read standing still, and the shop's own slip frames their
// sections with whitespace, so they get the blank line and nothing more.
func printFooterRule(e *escpos.Encoder, w int, kind string) {
	e.Feed(1)
	if kind != "runner" {
		return
	}
	e.Line(dashedLine(w))
	e.Feed(1)
}

// slipPriceWidth is the width every money value in the item table is padded to
// so the currency symbols form a straight COLUMN.
//
// Right-aligning each price on its own puts the ¥ of "¥0" four columns inside
// the ¥ of "¥1,000" — the eye then has to re-find the column on every line.
// Padding each value to the widest one on the slip and right-aligning the padded
// block left-aligns the symbols, which is what the shop's own slip does.
//
// Measured across the whole item list, toppings included, so the column cannot
// shift halfway down the table. Both render paths call it with the same slice —
// TR-40 compares them byte for byte.
func slipPriceWidth(items []Item, cur string) int {
	widest := 0
	for _, item := range items {
		if item.Quantity <= 0 || strings.TrimSpace(item.MenuItemName) == "" {
			continue
		}
		widest = max(widest, displayWidth(cur+formatPrice(item.UnitPrice*item.Quantity)))
		for _, t := range item.Toppings {
			if strings.TrimSpace(t.Name) == "" {
				continue
			}
			amount := t.UnitPrice * t.Quantity
			p := cur + formatPrice(amount)
			if t.ModifierType == "remove" && amount != 0 {
				p = "-¥" + formatPrice(amount)
			}
			widest = max(widest, displayWidth(p))
		}
	}
	return widest
}

// padPrice right-pads a money value to the slip's price-column width.
func padPrice(price string, colW int) string {
	return padRight(price, colW)
}

func printRunnerItem(e *escpos.Encoder, w, priceColW int, item Item, cur string, reduced bool, locale, notePrefix string) {
	if item.Quantity <= 0 || strings.TrimSpace(item.MenuItemName) == "" {
		return
	}

	name := stripVariantSuffix(item.MenuItemName)
	if reduced {
		name = name + " " + taxLabelsFor(locale).ReducedMarker
	}
	slStr := fmt.Sprintf("%d", item.Quantity)
	priceStr := padPrice(cur+formatPrice(item.UnitPrice*item.Quantity), priceColW)
	indentW := utf8.RuneCountInString(slStr) + 2

	printWrappedName(e, w, slStr, name, priceStr)
	printVariantLine(e, w, indentW, item)
	printToppingLines(e, w, priceColW, indentW, item, cur, locale, notePrefix)
}

// itemVariant returns the SKU variant label (e.g. "Large") for an item.
// Prefers the structured sku_variant_name column; falls back to the " · "
// suffix in the display name (stripVariantSuffix removes that suffix from the
// printed item name, so without this the variant would be lost entirely).
func itemVariant(item Item) string {
	if v := strings.TrimSpace(item.SkuVariantName); v != "" {
		return v
	}
	if dot := strings.Index(item.MenuItemName, " \xc2\xb7 "); dot != -1 {
		return strings.TrimSpace(item.MenuItemName[dot+len(" \xc2\xb7 "):])
	}
	return ""
}

// printVariantLine renders the SKU variant as an indented line under the item
// name, matching the topping line style ("-- Large") but without a price —
// mirroring how the Handy note format surfaces the variant as its own line.
// Printed before toppings since the variant is part of the item's identity.
func printVariantLine(e *escpos.Encoder, w, indentW int, item Item) {
	v := itemVariant(item)
	if v == "" {
		return
	}
	e.Line(spaces(indentW) + "-- " + v)
}

// parsedTopping holds a topping line parsed from item.Note fallback.
type parsedTopping struct {
	name         string
	modifierType string // "add" | "remove"
	price        int
}

// parseNoteAsToppings parses handy-app note format into toppings + free note.
// Handy encodes toppings into note as: "+ Name ¥price", "- Name", "Name" (variant).
// Pure free-text lines (no +/- prefix and no ¥) are returned separately as freeNote.
func parseNoteAsToppings(note string) (toppings []parsedTopping, freeNote string) {
	var freeLines []string
	for _, raw := range strings.Split(note, "\n") {
		line := strings.TrimSpace(raw)
		if line == "" {
			continue
		}
		if strings.HasPrefix(line, "+ ") || strings.HasPrefix(line, "+") {
			name := strings.TrimPrefix(strings.TrimPrefix(line, "+ "), "+")
			price := 0
			if i := strings.LastIndex(name, "¥"); i >= 0 {
				fmt.Sscanf(strings.ReplaceAll(name[i+len("¥"):], ",", ""), "%d", &price)
				name = strings.TrimSpace(name[:i])
			}
			toppings = append(toppings, parsedTopping{name: name, modifierType: "add", price: price})
		} else if strings.HasPrefix(line, "- ") || strings.HasPrefix(line, "-") {
			name := strings.TrimSpace(strings.TrimPrefix(strings.TrimPrefix(line, "- "), "-"))
			toppings = append(toppings, parsedTopping{name: name, modifierType: "remove", price: 0})
		} else {
			// No +/- prefix — a genuine free-text customer note (e.g.
			// "it cay, khong hanh"). Handy encodes toppings with explicit
			// +/- markers, so anything else is the note and must surface as
			// "Ghi chu: ..." rather than being hidden as a "-- <note>" topping.
			freeLines = append(freeLines, line)
		}
	}
	freeNote = strings.Join(freeLines, "\n")
	return
}

// printToppingLines renders toppings + free note for an item.
// Uses item.Toppings (structured) when available; falls back to parsing item.Note.
//
// # The topping amount is a LINE amount, like the item row above it
//
// `ItemTopping.Quantity` counts the topping WITHIN ONE UNIT of the dish ("2 x
// cheese on each bowl"), and `ItemTopping.UnitPrice` is its per-unit price. The
// item row directly above already prints `UnitPrice × item.Quantity` — a line
// amount — so the topping row has to be a line amount too, or the two rows are
// in different units and the slip does not add up.
//
// It did not. Until this was fixed a bowl at ¥1.000 ordered ×3 with a ¥100
// extra printed:
//
//	3  Pho bo                    ¥3.000
//	   -- Them gio lua             ¥100     ← the extra, charged once
//	小計                          ¥3.300     ← the engine, charged three times
//
// The rows summed to ¥3.100 against a ¥3.300 subtotal, so a customer could not
// check their own bill, and the same rows print on the red invoice. #2067 is
// the standing rule this broke: the print layer prints order data, it does not
// price a sale — and a row that silently drops the line quantity is the printer
// pricing a sale, just badly.
//
// The COUNT moves with the amount for the same reason: the row shows
// `topping qty × line qty` so that what is printed on the left multiplied by
// the topping's price is what is printed on the right.
//
// # Money sits in ONE column on the slip
//
// `priceColW` is the widest price ON THIS SLIP, computed once by
// `slipPriceWidth`. Every amount is padded to it before being right-
// aligned, so the currency signs line up down the page instead of
// stair-stepping with the digit count. The topping rows have to use the
// same column as the item row above them or the indent reads as a second,
// narrower table.
func printToppingLines(e *escpos.Encoder, w, priceColW, indentW int, item Item, cur, locale, notePrefix string) {
	prefix := "-- "
	prefixW := displayWidth(prefix)

	// Callers (printRunnerItem / printKitchenItem) return early on qty <= 0, so
	// this only guards a direct call. Clamping to 1 keeps a malformed line
	// printing its topping at list price rather than at zero — an amount that
	// looks wrong is reported; an amount silently zeroed is not.
	lineQty := item.Quantity
	if lineQty < 1 {
		lineQty = 1
	}

	if len(item.Toppings) > 0 {
		// Structured toppings from DB.
		//
		// `printedPerUnit` accumulates the money these rows put on paper, per
		// UNIT of the dish, so the waiver row below can close the gap against
		// what the shop actually charged. It is summed from the SAME values the
		// rows print, not recomputed — a second derivation would be a second
		// opinion, and the whole point of the row is that the two agree.
		printedPerUnit := 0
		reconcilable := true
		for _, t := range item.Toppings {
			tName := collapseMirroredName(strings.TrimSpace(t.Name))
			if tName == "" {
				// Nothing goes on paper for this row, but the engine still
				// priced it. The slip therefore cannot be reconciled against
				// `ToppingSubtotal`, so no waiver row is printed for this item.
				if t.UnitPrice != 0 {
					reconcilable = false
				}
				continue
			}
			totalQty := t.Quantity * lineQty
			if totalQty > 1 && t.ModifierType != "remove" {
				tName = fmt.Sprintf("%d x %s", totalQty, tName)
			}
			// A zero-priced modifier still prints its amount. Blank reads as
			// "the amount failed to load"; a printed zero states that it is
			// free, which is what the shop's own slip does. That is why this
			// is no longer guarded by `t.UnitPrice != 0` — but the RECONCILE
			// accounting below is unchanged by the move: a zero unit price
			// contributes zero to `printedPerUnit` either way, and a zero
			// "remove" still must NOT clear `reconcilable`, because a
			// deduction of nothing is not a deduction the engine missed.
			tPrice := cur + formatPrice(t.UnitPrice*totalQty)
			if t.ModifierType == "remove" {
				if t.UnitPrice != 0 {
					// A "remove" carries extra_price 0 by contract (the schema
					// says pricing is unaffected by what the customer drops), so
					// this branch is unreachable on a well-formed line. When it
					// IS reached the row prints a deduction the engine never
					// applied — it sums `UnitPrice` unsigned — so the item stops
					// being reconcilable and gets no waiver row.
					reconcilable = false
					// "-" + cur, not a hard-coded "-¥": every other money on
					// this slip is rendered in the shop's currency, and a VND
					// shop printing a yen sign on one row is the row nobody
					// trusts afterwards.
					tPrice = "-" + cur + formatPrice(t.UnitPrice*totalQty)
				}
			} else {
				printedPerUnit += t.UnitPrice * max(1, t.Quantity)
			}
			tPrice = padPrice(tPrice, priceColW)
			tPriceW := displayWidth(tPrice)
			tNameW := w - indentW - prefixW - tPriceW
			if tNameW < 1 {
				tNameW = 1
			}
			e.Line(spaces(indentW) + prefix + padRight(tName, tNameW) + tPrice)
		}
		if reconcilable {
			printToppingWaiver(e, w, indentW, prefix, item, printedPerUnit, lineQty, cur, locale)
		}
		// Free note alongside structured toppings
		note := strings.TrimSpace(item.Note)
		if note != "" {
			printNoteLines(e, w, indentW, note, notePrefix)
		}
		return
	}

	// Fallback: parse item.Note (handy app encodes toppings into note)
	note := strings.TrimSpace(item.Note)
	if note == "" {
		return
	}
	toppings, freeNote := parseNoteAsToppings(note)
	for _, t := range toppings {
		tPrice := ""
		if t.price != 0 {
			// `t.price` is what the handy app encoded per unit ("+ Name ¥300"),
			// so it needs the same × line-quantity as the structured branch —
			// the two branches print the same row on the same slip and cannot
			// mean different things.
			lineAmount := t.price * lineQty
			if t.modifierType == "remove" {
				tPrice = "-" + cur + formatPrice(lineAmount)
			} else {
				tPrice = cur + formatPrice(lineAmount)
			}
		}
		tPriceW := displayWidth(tPrice)
		tNameW := w - indentW - prefixW - tPriceW
		if tNameW < 1 {
			tNameW = 1
		}
		e.Line(spaces(indentW) + prefix + padRight(t.name, tNameW) + tPrice)
	}
	if freeNote != "" {
		printNoteLines(e, w, indentW, freeNote, notePrefix)
	}
}

// printToppingWaiver closes the gap between the topping rows just printed and
// what the shop actually charged for them, when a `free_up_to_n` group waived
// some of the picks.
//
// # Why the rows cannot simply print the right amount
//
// `free_up_to_n` waives the `free_quantity` most EXPENSIVE units in a group.
// The engine computes that and stores only the RESULT, per unit, in
// `customer_order_items.topping_subtotal`. Which units were waived is never
// persisted: `order_item_toppings` carries `quantity` + `unit_price` (list) and
// nothing else, so the `charged` flag the pricer computes per unit dies inside
// the pricer. A printer looking at the rows therefore cannot know which of them
// was free — and must not guess, because guessing is pricing a sale (#2067).
//
// What it CAN do is state the total that was given away, which is the one thing
// both stored figures agree on:
//
//	waived per unit = Σ(printed row prices per unit) − topping_subtotal
//
//	3  Pho bo                   ¥3.000
//	   -- 3 x Them gio lua        ¥300
//	   -- 3 x Trung chan          ¥240
//	   -- Topping mien phi       -¥300
//	小計                         ¥3.240
//
// Without the last row the item block sums to ¥3.540 against a ¥3.240 subtotal
// and the customer cannot check their own bill. It is a DISPLAY row: every
// total on the slip already comes from stored figures that account for the
// waiver, so nothing is added to or subtracted from them here.
//
// # When it stays silent, and why that is the safer half
//
//   - `ToppingSubtotal == 0`: the line carries no stored figure to reconcile
//     against. `itemTaxableSubtotal` falls back to summing the same rows in
//     that shape, so the slip already adds up — and printing a discount
//     derived from a missing value would be inventing one on a tax document.
//   - gap < 0 (the shop charged MORE than the rows show): that is not a
//     waiver, and a row labelled "free toppings" carrying a surcharge would be
//     a false statement. It is logged instead, because it means the rows and
//     the stored subtotal disagree in a direction nothing explains.
//   - the caller found a row it could not account for (blank name, or a
//     "remove" carrying a price the engine did not apply): reconciliation is
//     off for the whole item rather than folded into a mislabelled row.
//
// The amount is scaled by `lineQty` exactly like the rows above it — on a
// kitchen ticket that is the fired delta, which keeps the block internally
// consistent with the partial figures around it.
func printToppingWaiver(
	e *escpos.Encoder,
	w, indentW int,
	prefix string,
	item Item,
	printedPerUnit, lineQty int,
	cur, locale string,
) {
	if item.ToppingSubtotal == 0 {
		return
	}
	waivedPerUnit := printedPerUnit - item.ToppingSubtotal
	if waivedPerUnit == 0 {
		return
	}
	if waivedPerUnit < 0 {
		slog.Warn("print: topping rows total less than the charged topping subtotal — no reconciling row printed",
			"item_id", item.ID,
			"menu_item_name", item.MenuItemName,
			"printed_per_unit", printedPerUnit,
			"topping_subtotal", item.ToppingSubtotal,
			"detail", "the printed topping rows and customer_order_items.topping_subtotal disagree in a direction a free-tier waiver cannot produce. The slip omits the row rather than printing a surcharge under a 'free toppings' label (#2067 — the print layer prints order data, it does not price a sale).")
		return
	}

	label := printLabelsFor(locale).ToppingWaived
	amount := "-" + cur + formatPrice(waivedPerUnit*lineQty)
	nameW := w - indentW - displayWidth(prefix) - displayWidth(amount)
	if nameW < 1 {
		nameW = 1
	}
	e.Line(spaces(indentW) + prefix + padRight(label, nameW) + amount)
}

// printNoteLines renders "   Ghi chu: ..." lines, word-wrapping long notes so a
// note is never hard-cut mid-word by the printer and every wrapped line stays
// inside the content width (so the left/right padding is preserved). Wrapped
// continuation lines are indented to align under the note text, past the
// "Ghi chu: " prefix.
func printNoteLines(e *escpos.Encoder, w, indentW int, note, notePrefix string) {
	notePrefixW := displayWidth(notePrefix)
	textW := w - indentW - notePrefixW
	if textW < 1 {
		textW = 1
	}
	contIndent := indentW + notePrefixW
	for _, para := range strings.Split(note, "\n") {
		para = strings.TrimSpace(para)
		if para == "" {
			continue
		}
		// Wrap on word boundaries at the note-text width; lines[0] follows the
		// "Ghi chu: " label, the rest align under it.
		lines := wrapNameLines(para, textW, textW)
		if len(lines) == 0 {
			continue
		}
		e.Line(spaces(indentW) + notePrefix + lines[0])
		for _, ln := range lines[1:] {
			e.Line(spaces(contIndent) + ln)
		}
	}
}

// stripVariantSuffix removes " · VariantName" suffix added by the frontend.
func stripVariantSuffix(name string) string {
	if dot := strings.Index(name, " \xc2\xb7 "); dot != -1 {
		return name[:dot]
	}
	return name
}

// collapseMirroredName collapses a name that is nothing but the same label
// repeated across the " · " separator down to a single label ("Fish sauce ·
// Fish sauce" → "Fish sauce"). Orders created before workstation-app#101 stored
// topping names doubled this way (a default-SKU topping whose SKU name equals
// its product name); the print path renders the stored value verbatim, so
// without this a printed bill showed the topping twice and wrapped it onto a
// second, prefix-less line. A genuine "Product · Variant" topping (distinct
// parts) is left untouched. Mirrors pos-web's collapseMirroredToppingName.
func collapseMirroredName(name string) string {
	const sep = " \xc2\xb7 " // " · " (U+00B7) — matches resolveToppingSnapshot's format
	if !strings.Contains(name, sep) {
		return name
	}
	parts := strings.Split(name, sep)
	first := strings.TrimSpace(parts[0])
	for _, p := range parts {
		if !strings.EqualFold(strings.TrimSpace(p), first) {
			return name
		}
	}
	return first
}

// shiftJISWideRanges are the code points `encodeShiftJIS` emits as TWO bytes —
// therefore two columns on the print head — that fall outside the CJK blocks
// runeDisplayWidth tests below. They are the non-kanji rows of JIS X 0208:
// symbols (rows 1-2), Greek (6), Cyrillic (7) and box drawing (8).
//
// This list has to exist because Unicode calls almost all of them East-Asian
// AMBIGUOUS. A width table derived from Unicode metadata therefore measures
// them at ONE column while the Japanese head puts down two, and the drift is
// invisible until it reaches paper: every 軽減税率 item line was laid out one
// column narrow — 「緑茶※」 pushed its price one place right of every other
// item's — because ※ (U+203B) is in here.
//
// Sorted and non-overlapping; inShiftJISWideRange binary-searches it.
// TestRuneDisplayWidth_MatchesShiftJISEncoder rebuilds the whole set from
// japanese.ShiftJIS — the encoder escpos actually prints through — so this
// table cannot drift away from the bytes.
var shiftJISWideRanges = [...][2]rune{
	{0x00A7, 0x00A8}, // §¨
	{0x00B0, 0x00B1}, // °±
	{0x00B4, 0x00B4}, // ´
	{0x00B6, 0x00B6}, // ¶
	{0x00D7, 0x00D7}, // ×
	{0x00F7, 0x00F7}, // ÷
	{0x0391, 0x03A1}, // ΑΒΓΔΕΖ…
	{0x03A3, 0x03A9}, // ΣΤΥΦΧΨ…
	{0x03B1, 0x03C1}, // αβγδεζ…
	{0x03C3, 0x03C9}, // στυφχψ…
	{0x0401, 0x0401}, // Ё
	{0x0410, 0x044F}, // АБВГДЕ…
	{0x0451, 0x0451}, // ё
	{0x2010, 0x2010}, // ‐
	{0x2015, 0x2015}, // ―
	{0x2018, 0x2019}, // ‘’
	{0x201C, 0x201D}, // “”
	{0x2020, 0x2021}, // †‡
	{0x2025, 0x2026}, // ‥…
	{0x2030, 0x2030}, // ‰
	{0x2032, 0x2033}, // ′″
	{0x203B, 0x203B}, // ※
	{0x2103, 0x2103}, // ℃
	{0x2116, 0x2116}, // №
	{0x2121, 0x2121}, // ℡
	{0x212B, 0x212B}, // Å
	{0x2160, 0x2169}, // ⅠⅡⅢⅣⅤⅥ…
	{0x2170, 0x2179}, // ⅰⅱⅲⅳⅴⅵ…
	{0x2190, 0x2193}, // ←↑→↓
	{0x21D2, 0x21D2}, // ⇒
	{0x21D4, 0x21D4}, // ⇔
	{0x2200, 0x2200}, // ∀
	{0x2202, 0x2203}, // ∂∃
	{0x2207, 0x2208}, // ∇∈
	{0x220B, 0x220B}, // ∋
	{0x2211, 0x2211}, // ∑
	{0x221A, 0x221A}, // √
	{0x221D, 0x2220}, // ∝∞∟∠
	{0x2225, 0x2225}, // ∥
	{0x2227, 0x222C}, // ∧∨∩∪∫∬
	{0x222E, 0x222E}, // ∮
	{0x2234, 0x2235}, // ∴∵
	{0x223D, 0x223D}, // ∽
	{0x2252, 0x2252}, // ≒
	{0x2260, 0x2261}, // ≠≡
	{0x2266, 0x2267}, // ≦≧
	{0x226A, 0x226B}, // ≪≫
	{0x2282, 0x2283}, // ⊂⊃
	{0x2286, 0x2287}, // ⊆⊇
	{0x22A5, 0x22A5}, // ⊥
	{0x22BF, 0x22BF}, // ⊿
	{0x2312, 0x2312}, // ⌒
	{0x2460, 0x2473}, // ①②③④⑤⑥…
	{0x2500, 0x2503}, // ─━│┃
	{0x250C, 0x250C}, // ┌
	{0x250F, 0x2510}, // ┏┐
	{0x2513, 0x2514}, // ┓└
	{0x2517, 0x2518}, // ┗┘
	{0x251B, 0x251D}, // ┛├┝
	{0x2520, 0x2520}, // ┠
	{0x2523, 0x2525}, // ┣┤┥
	{0x2528, 0x2528}, // ┨
	{0x252B, 0x252C}, // ┫┬
	{0x252F, 0x2530}, // ┯┰
	{0x2533, 0x2534}, // ┳┴
	{0x2537, 0x2538}, // ┷┸
	{0x253B, 0x253C}, // ┻┼
	{0x253F, 0x253F}, // ┿
	{0x2542, 0x2542}, // ╂
	{0x254B, 0x254B}, // ╋
	{0x25A0, 0x25A1}, // ■□
	{0x25B2, 0x25B3}, // ▲△
	{0x25BC, 0x25BD}, // ▼▽
	{0x25C6, 0x25C7}, // ◆◇
	{0x25CB, 0x25CB}, // ○
	{0x25CE, 0x25CF}, // ◎●
	{0x25EF, 0x25EF}, // ◯
	{0x2605, 0x2606}, // ★☆
	{0x2640, 0x2640}, // ♀
	{0x2642, 0x2642}, // ♂
	{0x266A, 0x266A}, // ♪
	{0x266D, 0x266D}, // ♭
	{0x266F, 0x266F}, // ♯
}

// inShiftJISWideRange reports whether r is one of the double-byte JIS X 0208
// symbols listed above.
func inShiftJISWideRange(r rune) bool {
	lo, hi := 0, len(shiftJISWideRanges)-1
	for lo <= hi {
		mid := int(uint(lo+hi) >> 1)
		switch {
		case r < shiftJISWideRanges[mid][0]:
			hi = mid - 1
		case r > shiftJISWideRanges[mid][1]:
			lo = mid + 1
		default:
			return true
		}
	}
	return false
}

// runeDisplayWidth returns the printer column width of a rune.
// CJK characters (fullwidth) occupy 2 columns; combining marks occupy 0 (they
// stack on the preceding glyph — e.g. Vietnamese "á" in NFD form is a base
// letter + a combining accent); everything else occupies 1.
func runeDisplayWidth(r rune) int {
	// Nonspacing (Mn) / enclosing (Me) combining marks add no columns. Without
	// this, a decomposed (NFD) Vietnamese name like "Chi nhánh Hà Nội" measures
	// 20 cols instead of 16, wrongly bumping the branch name onto its own line.
	if unicode.Is(unicode.Mn, r) || unicode.Is(unicode.Me, r) {
		return 0
	}
	// Checked BEFORE the block test below because most of these sit under
	// U+1100, which that test short-circuits as narrow.
	if inShiftJISWideRange(r) {
		return 2
	}
	if r >= 0x1100 && (r <= 0x115F || // Hangul Jamo
		r == 0x2329 || r == 0x232A ||
		(r >= 0x2E80 && r <= 0x303E) || // CJK Radicals / Kangxi
		(r >= 0x3040 && r <= 0x33FF) || // Hiragana / Katakana / CJK compat
		(r >= 0x3400 && r <= 0x4DBF) || // CJK Ext-A
		(r >= 0x4E00 && r <= 0x9FFF) || // CJK Unified
		(r >= 0xA000 && r <= 0xA4CF) || // Yi
		(r >= 0xAC00 && r <= 0xD7AF) || // Hangul Syllables
		(r >= 0xF900 && r <= 0xFAFF) || // CJK compat ideographs
		(r >= 0xFE10 && r <= 0xFE19) ||
		(r >= 0xFE30 && r <= 0xFE6F) || // CJK compat forms
		(r >= 0xFF00 && r <= 0xFF60) || // Fullwidth forms
		(r >= 0xFFE0 && r <= 0xFFE6) ||
		(r >= 0x1F300 && r <= 0x1F64F) || // Emoji
		(r >= 0x20000 && r <= 0x2FFFD) ||
		(r >= 0x30000 && r <= 0x3FFFD)) {
		return 2
	}
	return 1
}

// displayWidth returns total printer column width of a string.
func displayWidth(s string) int {
	w := 0
	for _, r := range s {
		w += runeDisplayWidth(r)
	}
	return w
}

// wrapNameLines lays a menu-item name out across printer lines using DISPLAY
// width (CJK/emoji = 2 cols). Line 0 is firstW columns wide (the price sits to
// its right); continuation lines are contW wide. Rules:
//   - break on spaces — a word is never split mid-word when it fits a line;
//   - a single token wider than a whole line is char-split (e.g. a spaceless
//     Japanese name);
//   - widow control: a final line that would be a lone 1-column character has
//     the previous line's last rune pulled down so it never prints alone.
func wrapNameLines(name string, firstW, contW int) []string {
	if firstW < 1 {
		firstW = 1
	}
	if contW < 1 {
		contW = 1
	}

	var lines []string
	var cur []rune
	curW := 0
	// limit is the column budget of the line currently being built: the narrow
	// firstW for line 0, contW for every continuation line.
	limit := func() int {
		if len(lines) == 0 {
			return firstW
		}
		return contW
	}
	flush := func() {
		lines = append(lines, string(cur))
		cur = nil
		curW = 0
	}
	// charSplit appends an over-long token rune-by-rune, wrapping as it fills
	// each line's budget.
	charSplit := func(word string) {
		for _, r := range word {
			rw := runeDisplayWidth(r)
			if curW+rw > limit() {
				flush()
			}
			cur = append(cur, r)
			curW += rw
		}
	}

	for _, word := range strings.Fields(name) {
		ww := displayWidth(word)
		if curW == 0 {
			if ww <= limit() {
				cur = []rune(word)
				curW = ww
			} else {
				charSplit(word)
			}
			continue
		}
		if curW+1+ww <= limit() {
			cur = append(cur, ' ')
			cur = append(cur, []rune(word)...)
			curW += 1 + ww
			continue
		}
		// Doesn't fit the current line — start a fresh one and retry the word.
		flush()
		if ww <= limit() {
			cur = []rune(word)
			curW = ww
		} else {
			charSplit(word)
		}
	}
	flush()
	if len(lines) == 0 {
		lines = []string{""}
	}

	// Widow control: never leave a final 1-column orphan (the classic "name
	// drops one character to its own line"). Pull the previous line's last rune
	// down so the tail line carries at least two columns.
	if n := len(lines); n >= 2 && displayWidth(lines[n-1]) < 2 {
		prev := []rune(lines[n-2])
		if len(prev) > 1 {
			moved := prev[len(prev)-1]
			lines[n-2] = string(prev[:len(prev)-1])
			lines[n-1] = string(moved) + lines[n-1]
		}
	}
	return lines
}

// printWrappedName prints "slStr  name....price", word-wrapping long names and
// right-aligning the price on the first line. Continuation lines are indented
// under the name column.
func printWrappedName(e *escpos.Encoder, w int, slStr, name, priceStr string) {
	indentW := utf8.RuneCountInString(slStr) + 2
	priceW := displayWidth(priceStr)
	nameColW := w - indentW - priceW
	if nameColW < 1 {
		nameColW = 1
	}
	contW := w - indentW
	if contW < 1 {
		contW = 1
	}

	lines := wrapNameLines(name, nameColW, contW)
	// First line: name padded to the name column so the price is right-aligned.
	e.Line(slStr + "  " + padRight(lines[0], nameColW) + priceStr)
	for _, ln := range lines[1:] {
		e.Line(spaces(indentW) + ln)
	}
}

// CurrencySymbol maps an ISO 4217 code to the symbol printed on receipts /
// kitchen tickets, so a shop prints in the currency it prices in (e.g. a VND
// shop prints ₫ instead of a hard-coded ¥). Defaults to ¥ for JPY / unknown.
func CurrencySymbol(code string) string {
	switch strings.ToUpper(strings.TrimSpace(code)) {
	case "VND":
		return "₫"
	case "USD", "AUD", "CAD", "SGD", "HKD", "NZD":
		return "$"
	case "EUR":
		return "€"
	case "GBP":
		return "£"
	case "KRW":
		return "₩"
	case "THB":
		return "฿"
	case "PHP":
		return "₱"
	case "CNY", "JPY":
		return "¥"
	default:
		return "¥"
	}
}

func formatPrice(amount int) string {
	s := fmt.Sprintf("%d", amount)
	if len(s) <= 3 {
		return s
	}
	var result []byte
	for i, c := range s {
		if i > 0 && (len(s)-i)%3 == 0 {
			result = append(result, ',')
		}
		result = append(result, byte(c))
	}
	return string(result)
}

func padRight(s string, width int) string {
	dw := displayWidth(s)
	if dw >= width {
		return s
	}
	return s + spaces(width-dw)
}

func spaces(n int) string {
	if n <= 0 {
		return ""
	}
	s := make([]byte, n)
	for i := range s {
		s[i] = ' '
	}
	return string(s)
}

// dashedLine returns a "- - - - - -" separator matching the HTML design.
func dashedLine(w int) string {
	// "- " repeated, trimmed to w runes
	unit := "- "
	var b strings.Builder
	for b.Len() < w*2 {
		b.WriteString(unit)
	}
	runes := []rune(b.String())
	if len(runes) > w {
		runes = runes[:w]
	}
	return string(runes)
}
