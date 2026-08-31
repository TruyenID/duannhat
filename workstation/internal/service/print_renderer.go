package service

import (
	"fmt"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// plan-053 M3 (#1171) — the definition-driven renderer (TASKS T3.1, DESIGN §7).
//
//	render(definition, data, profile, locale, paper) -> bytes
//
// Three rules make this safe to swap in under a live shop:
//
//  1. The renderer COMPUTES NOTHING. Every amount, tax bucket, count and
//     timestamp arrives on PrintRenderData, already decided by the engine. A
//     template that could compute would be a second money implementation, and
//     the one that disagreed would be the one nobody could read.
//  2. The definition decides WHICH blocks appear and IN WHAT ORDER. A `locked`
//     block's CONTENT is built here from the data (tax breakdown, grand total,
//     registration number, reprint marker…), so no brand can reword 合計.
//  3. Output is byte-identical to the hard-coded formatter it replaces when fed
//     the system default definition — the TR-40 migration gate, enforced by
//     print_renderer_golden_test.go. Migrating a fleet must not change one
//     shop's slip.

// PrintTextMode selects how the renderer emits text for a printer.
//
// A capability profile that cannot print a glyph natively (plan-052 §3b) needs
// the text rasterised. TR-36 requires that to happen PER BLOCK, not per sheet:
// rasterising a whole 精算 slip costs seconds of print time, while rasterising
// only the block that needs it keeps the rest at native speed.
type PrintTextMode string

const (
	PrintTextNative PrintTextMode = "native"
	PrintTextRaster PrintTextMode = "raster"
)

// PrintRenderProfile is the printer-side half of the render contract. It is a
// deliberately small stand-in for plan-052's capability profile: this milestone
// must not touch internal/printer while that track is mid-flight, so the
// renderer takes the two facts it actually needs and the richer profile can be
// adapted onto it later without changing a single emitter.
type PrintRenderProfile struct {
	// Columns is the printer's real characters-per-line (32 = 58mm, 48 = 80mm).
	// 0 → fall back to PrintJobConfig.PaperWidth, then the kind's default.
	Columns int
	// TextMode drives native vs per-block raster segmentation (TR-36).
	TextMode PrintTextMode
	// Paper names the roll ("58mm"/"80mm") so a definition's `paper` map can be
	// consulted when neither the profile nor the job config pins a width.
	Paper string
	// Finishing, when set, drives the paper cut through the PROFILE instead of a
	// blind `FullCut()` — #1950. Nil means "no profile", and nil deliberately
	// reproduces today's bytes exactly (ESC d 3), so a shop with no profile
	// configured prints byte-for-byte what it printed yesterday and the TR-40
	// gate stays meaningful.
	//
	// Set, it fixes three things a blind cut gets wrong: a `gs_v_partial`
	// machine gets a PARTIAL cut (the tab that keeps the slip from falling on
	// the floor), a `none` machine stops receiving a cut it cannot do, and an
	// `auto_cut_per_job` machine stops emitting a second blank slip every time.
	Finishing *escpos.Finishing
	// TextModeForBlock, when set, decides native vs raster PER BLOCK and
	// overrides TextMode. It receives the block's id and the bytes it produced,
	// because the decision depends on the block's CONTENT (a machine with no
	// kanji ROM needs the Japanese blocks rastered and the money native), not on
	// which block it is. This is the seam plan-052's
	// printer.Profile.TextModeFor plugs into — see PrintRenderProfileFor.
	TextModeForBlock func(blockID string, content []byte) PrintTextMode
}

// finish ends the sheet. With no profile it is exactly `FullCut()` — the bytes
// this renderer has always produced — so nothing changes for a shop that has
// not configured a printer profile (#1950).
func (c *printRenderCtx) finish() {
	if c.profile.Finishing == nil {
		c.e.FullCut()

		return
	}

	c.e.Finish(*c.profile.Finishing)
}

// textModeFor answers the one question the segmenter asks per block.
func (p PrintRenderProfile) textModeFor(blockID string, content []byte) PrintTextMode {
	// Control-only segments and the QR are device commands, not text.
	// Rasterising them would break the printer, not help it — so they are never
	// eligible, whatever the profile says.
	if !rasterisableBlock(blockID) {
		return PrintTextNative
	}
	if p.TextModeForBlock != nil {
		return p.TextModeForBlock(blockID, content)
	}
	if p.TextMode == PrintTextRaster {
		return PrintTextRaster
	}
	return PrintTextNative
}

// PrintRenderSegment is one block's contribution to the byte stream, tagged
// with the mode it was emitted in. Native output is the plain ESC/POS the
// legacy formatters produce; a raster segment is the same block flagged for
// bitmap emission by the transport layer (see the deviation note in
// docs/guide/print-templates.md — the bitmap encoder itself belongs to
// plan-052's printer capability track, which owns internal/printer).
type PrintRenderSegment struct {
	BlockID string
	Mode    PrintTextMode
	Bytes   []byte
}

// PrintRenderResult carries both the flat byte stream (what a native printer
// receives, and what the golden gate compares) and the per-block segmentation
// (what a raster-only printer needs).
type PrintRenderResult struct {
	Segments []PrintRenderSegment
	bytes    []byte
}

// Bytes returns the full ESC/POS stream.
func (r PrintRenderResult) Bytes() []byte { return r.bytes }

// RasterSegments returns only the segments the profile asked to rasterise.
func (r PrintRenderResult) RasterSegments() []PrintRenderSegment {
	var out []PrintRenderSegment
	for _, s := range r.Segments {
		if s.Mode == PrintTextRaster {
			out = append(out, s)
		}
	}
	return out
}

// PrintRenderData is the pre-computed payload a slip is rendered from.
//
// It intentionally reuses the SAME structs the hard-coded formatters take
// (Order/Item/PaymentSlipInfo/VatInvoiceInfo/…) instead of inventing a parallel
// model. A parallel model would need a translation layer, and a translation
// layer is where a receipt quietly loses a topping.
type PrintRenderData struct {
	Kind string

	// Shared context
	Config PrintJobConfig
	Now    time.Time // slip timestamp; zero → printNow()

	// Order-backed kinds (receipt / kitchen / runner / delta_qr / remaining /
	// red_invoice / debt_slip).
	Order    *Order
	Items    []Item
	TicketNo int

	// Bill-family money, decided by the caller exactly as the legacy
	// Format*Ticket entry points decide it.
	Total     int
	Remaining int
	PaidShown int
	ShowQR    bool
	DeltaBill bool
	Slip      *PaymentSlipInfo

	// Document kinds.
	Vat        *VatInvoiceInfo
	VoidReason string
	VoidedAt   time.Time
	Debt       *DebtSlipInfo
	Shift      *ShiftReportInfo
	ShiftOpen  *ShiftOpenReportInfo
	TablePaid  *TablePaidInfo

	// ReprintNumber drives the 「Bản in #N」 marker on the kinds that carry one.
	ReprintNumber int
}

// now resolves the slip timestamp through the single print clock seam.
//
// #2065 — the ladder is: caller-supplied `Now` → the ORDER's transaction time →
// the print clock. The middle rung is the fix: it used to fall straight to
// `printNow()`, so an order-backed slip printed the moment the paper moved and a
// reprint re-dated a tax document (see orderTransactionTime).
//
// An explicit `Now` still wins, and must: Cloud hands the instant down because
// its renderer has no shop clock to read (#1091), and PHP's counterpart refuses
// to invent one at all — `BillKindPlans::emitIssuedAt` prints NOTHING rather
// than guess. This ladder makes Go agree with that stance instead of quietly
// filling the hole with the wrong answer.
func (d *PrintRenderData) now() time.Time {
	if d != nil && !d.Now.IsZero() {
		return d.Now
	}
	if d == nil {
		return printNow()
	}
	// Kind-aware since 2026-08-17: a work order (kitchen / hall) dates itself
	// from the PRINT clock, a transaction document from the sale. An explicit
	// `Now` still wins above — Cloud is handed the resolved instant because its
	// renderer has no shop clock to read (#1091), and the golden exporter
	// resolves it here so PHP replays the same answer rather than deriving a
	// second one.
	return orderSlipTimeForKind(d.Kind, d.Order, d.Config.slipLocation())
}

// printRenderCtx is the per-render scratch space shared by every block emitter.
type printRenderCtx struct {
	e       *escpos.Encoder
	def     *PrintTemplateDefinition
	data    *PrintRenderData
	cfg     PrintJobConfig
	profile PrintRenderProfile
	locale  string
	w       int
	// japaneseDoc chép từ plan của kind — xem printKindPlan.japaneseDoc (#1493).
	japaneseDoc bool
	labels      printLabels
	tax         taxLabels

	// #1957 mảnh C — nguồn bitmap cho khối `logo`. `nil` = chưa nối dây (bề mặt
	// xem trước, test cũ): khối logo không vẽ gì và phiếu vẫn in (TR-05).
	//
	// Go TỰ TRA còn PHP NHẬN TỪ NGOÀI (`PrintRenderContext::$images`) — khác
	// biệt có lý do: máy trạm phục vụ đúng MỘT chi nhánh nên tra thẳng SQLite là
	// đúng, còn Cloud phục vụ nhiều chi nhánh trong một tiến trình nên người gọi
	// phải nói rõ ảnh của ai.
	images *PrintImageStore
	// Giờ treo tường của CHI NHÁNH (#1091), rỗng = chưa biết → bỏ qua
	// `effective_from` thay vì đoán. Xem PrintImageStore.Lookup.
	branchWallClock string

	// bill-family derived state, computed once before the block walk so the ※
	// markers on the item lines and the per-rate footer blocks can never
	// disagree (they are the same summary).
	taxSummary        receiptTaxSummary
	showTaxBreakdown  bool
	suppressOrderRows bool

	// headerDrawn guards the composed "store name … TITLE" line, which two
	// blocks (`store_info` and `title`) share: whichever the definition places
	// first draws it, the other becomes a no-op.
	headerDrawn bool
}

// blockEmitter renders one block. It is called only for blocks the definition
// enabled, and only for ids the kind knows.
type blockEmitter func(c *printRenderCtx, b *PrintTemplateBlock)

// printKindPlan binds a kind's block emitters to its paper defaults and the
// prologue/epilogue that frame every slip of that kind.
type printKindPlan struct {
	defaultWidth int
	prologue     func(c *printRenderCtx)
	epilogue     func(c *printRenderCtx)
	emitters     map[string]blockEmitter
	// japaneseDoc: chứng từ này là chứng từ NHẬT (適格簡易請求書) — #1493.
	//
	// Thuộc tính của KIND, không phải của locale. Trước #1493 các emitter rẽ theo
	// `c.locale == "ja"`, nên NGÔN NGỮ GIAO DIỆN của thu ngân quyết định CHỨNG TỪ
	// NÀO được in: quán Việt để tiếng Nhật ra chứng từ Nhật, quán Nhật để tiếng
	// Việt ra hoá đơn GTGT. Bốn trục độc lập (#1459) — cái này là trục 1.
	//
	// Cloud đã ràng kind theo quốc gia (`PrintTemplateKind::countries()`), nên
	// quán JP chỉ nhận được kind Nhật và quán VN chỉ nhận được kind Việt. Rẽ theo
	// kind vì thế LÀ rẽ theo quốc gia, và không cần thêm trường nào vào ctx.
	japaneseDoc bool
}

// printKindPlans is the renderer's dispatch table. Each entry is derived from
// exactly one hard-coded formatter, which is what makes the TR-40 gate provable
// kind by kind.
var printKindPlans = map[string]printKindPlan{}

func registerPrintKind(kind string, plan printKindPlan) {
	printKindPlans[kind] = plan
}

// RenderPrintTemplate is the DESIGN §7 entry point:
// render(definition, data, profile, locale, paper) -> bytes.
//
// It never returns a partially-written slip: a kind with no plan, or a
// definition the renderer cannot use, is an error the CALLER answers by falling
// back to the embedded system default (see RenderWithFallback / TR-14). The
// renderer's own job is to be honest about what it could not do.
// RenderOption chỉnh một lượt render mà không đổi chữ ký cho mọi caller cũ.
type RenderOption func(*printRenderCtx)

// WithPrintImages cấp nguồn bitmap cho khối `logo` (#1957 mảnh C).
func WithPrintImages(s *PrintImageStore, branchWallClock string) RenderOption {
	return func(c *printRenderCtx) {
		c.images = s
		c.branchWallClock = branchWallClock
	}
}

func RenderPrintTemplate(def *PrintTemplateDefinition, data *PrintRenderData, profile PrintRenderProfile, locale string, opts ...RenderOption) (PrintRenderResult, error) {
	if def == nil {
		return PrintRenderResult{}, fmt.Errorf("print render: nil definition")
	}
	if data == nil {
		return PrintRenderResult{}, fmt.Errorf("print render: nil data")
	}
	kind := data.Kind
	if kind == "" {
		kind = def.Kind
	}
	plan, ok := printKindPlans[kind]
	if !ok {
		return PrintRenderResult{}, fmt.Errorf("print render: no renderer for kind %q", kind)
	}
	if def.index == nil {
		def.prepare()
	}

	c := &printRenderCtx{
		e:       escpos.New(),
		def:     def,
		data:    data,
		cfg:     data.Config,
		profile: profile,
		locale:  normalizePrintLocale(locale),
		w:       resolvePrintWidth(def, data.Config, profile, plan.defaultWidth),
	}
	for _, opt := range opts {
		opt(c)
	}
	c.japaneseDoc = plan.japaneseDoc
	c.labels = printLabelsFor(c.locale)
	c.tax = taxLabelsFor(c.locale)

	result := PrintRenderResult{}
	// Offset starts at ZERO, not at the encoder's current length: the printer
	// init sequence escpos.New() already wrote belongs to the prologue segment.
	// Skipping it would make the segments fail to reassemble into the slip,
	// which is the one invariant a raster transport relies on.
	offset := 0
	record := func(blockID string) {
		full := c.e.Bytes()
		if len(full) == offset {
			return // block emitted nothing — no segment, nothing to rasterise
		}
		body := append([]byte(nil), full[offset:]...)
		seg := PrintRenderSegment{
			BlockID: blockID,
			Mode:    profile.textModeFor(blockID, body),
			Bytes:   body,
		}
		result.Segments = append(result.Segments, seg)
		offset = len(full)
	}

	if plan.prologue != nil {
		plan.prologue(c)
	}
	// #3082 — dòng trắng đầu phiếu, TRONG đoạn prologue.
	//
	// Nằm trong prologue chứ không thành segment riêng: `record` chỉ ghi segment
	// khi có byte mới, và một segment toàn LF không có gì để raster hoá — tách ra
	// chỉ đẻ thêm một đoạn rỗng cho transport phải ghép lại.
	if def.TopFeed > 0 {
		c.e.Feed(def.TopFeed)
	}
	record("__prologue__")

	for i := range def.Blocks {
		b := &def.Blocks[i]
		emit, known := plan.emitters[b.ID]
		if !known || !b.isEnabled() {
			continue
		}
		// #3082 — cỡ chữ bọc NGOÀI emitter, không nằm trong từng emitter.
		//
		// Mỗi khối tự đọc `b.Size` thì 20+ emitter phải nhớ làm điều đó, và cái
		// quên sẽ im lặng. Bọc ở đây thì prop áp cho MỌI khối bằng một chỗ, và
		// khối nào chưa đặt `size` phát ra đúng byte như hôm nay.
		//
		// Trả về NormalSize ngay sau khối: để rò rỉ sang khối kế là biến một
		// lựa chọn cục bộ thành trạng thái toàn phiếu.
		if tall := b.Size == "tall"; tall {
			c.e.Size(escpos.DoubleHeight)
			emit(c, b)
			c.e.Size(escpos.NormalSize)
		} else {
			emit(c, b)
		}
		record(b.ID)
	}

	if plan.epilogue != nil {
		plan.epilogue(c)
		record("__epilogue__")
	}

	result.bytes = c.e.Bytes()
	return result, nil
}

// rasterisableBlock reports whether a block's payload is text a raster-only
// printer needs as a bitmap. Control-only segments (the prologue's init +
// margin, the epilogue's cut) and the QR block are already device commands —
// rasterising them would break the printer, not help it. This is the TR-36
// "per block, not per sheet" rule expressed as data.
func rasterisableBlock(blockID string) bool {
	switch blockID {
	case "__prologue__", "__epilogue__", "qr_block", "logo":
		return false
	}
	return true
}

// resolvePrintWidth picks the content width. Precedence is deliberate:
// the printer's real capability (profile) beats the job config, which beats the
// template's authored paper map, which beats the kind's historical default.
// A template must never be able to make a slip wider than the paper.
func resolvePrintWidth(def *PrintTemplateDefinition, cfg PrintJobConfig, profile PrintRenderProfile, kindDefault int) int {
	return clampToPhysical(resolveDesiredWidth(def, cfg, profile, kindDefault), cfg)
}

// resolveDesiredWidth là thang ưu tiên cũ — bề rộng người vận hành MUỐN, chưa
// đối chiếu với máy in. Tách ra để `resolvePrintWidth` có đúng MỘT lối ra và
// phép kẹp không thể bị bỏ sót ở một nhánh nào.
func resolveDesiredWidth(def *PrintTemplateDefinition, cfg PrintJobConfig, profile PrintRenderProfile, kindDefault int) int {
	if profile.Columns > 0 {
		return profile.Columns
	}
	if cfg.PaperWidth > 0 {
		return cfg.PaperWidth
	}
	if def != nil {
		if w := def.Paper.columnsFor(profile.Paper); w > 0 {
			return w
		}
	}
	if kindDefault > 0 {
		return kindDefault
	}
	return 48
}

// clampToPhysical hạ bề rộng DÀN TRANG xuống bề rộng THẬT của máy in (#2084).
//
// Hai trường này trả lời hai câu khác nhau, và trước bài này chỉ một cái được
// dùng để dàn trang:
//
//	PaperWidth    — bề rộng bố cục người vận hành muốn (mặc định 42 ở mọi chỗ gọi)
//	PhysicalWidth — số ký tự máy in THẬT SỰ vừa một dòng (32 với 58mm, 48 với 80mm)
//
// HẸP HƠN máy là hợp lệ và có chủ đích: 42 trên giấy 80mm cho ra lề hai bên,
// và `leftPad()` căn giữa đúng nhờ chênh lệch ấy. VƯỢT máy thì không bao giờ
// hợp lệ — nội dung 42 cột in lên đầu in 32 cột là tràn giấy, mất chữ ở mép
// phải, và `leftPad()` im lặng trả 0 vì nó chỉ đệm khi giấy RỘNG HƠN nội dung.
//
// Hệ quả trước khi kẹp: mọi quán dùng 58mm in tràn ở MỌI loại phiếu, và toàn
// bộ nhánh bố cục khổ hẹp (`print_narrow.go`, #2035) không bao giờ chạy — nó
// chỉ kích hoạt khi `columns < 42`, mà columns luôn là 42.
//
// Kẹp Ở ĐÂY chứ không sửa 10 chỗ gọi: chỗ gọi nói "tôi muốn bố cục 42", máy in
// nói "tôi chỉ vừa 32". Hoà giải hai lời khai ấy là việc của tầng phân giải,
// và đặt ở đây thì mọi chỗ gọi TƯƠNG LAI cũng được bảo vệ mà không phải nhớ.
func clampToPhysical(width int, cfg PrintJobConfig) int {
	if cfg.PhysicalWidth > 0 && width > cfg.PhysicalWidth {
		return cfg.PhysicalWidth
	}

	return width
}

// ─── shared layout helpers ────────────────────────────────────────────────
//
// These reproduce the exact geometry of the legacy formatters. Two variants
// exist on purpose: `row` measures with DISPLAY width (a Kanji label is 2
// columns), while `runeRow` measures with rune count. The VAT invoice and the
// debt slip were written with rune counting and their column positions are
// baked into slips shops already file, so the renderer keeps each one's own
// arithmetic rather than "fixing" it during a migration (TR-40).

func (c *printRenderCtx) row(label, value string) {
	gap := c.w - displayWidth(label) - displayWidth(value)
	if gap < 1 {
		gap = 1
	}
	c.e.Line(label + spaces(gap) + value)
}

func (c *printRenderCtx) runeRow(label, value string) {
	gap := c.w - runeLen(label) - runeLen(value)
	if gap < 1 {
		gap = 1
	}
	c.e.Line(label + spaces(gap) + value)
}

func (c *printRenderCtx) money(n int) string {
	return c.cfg.cur() + formatPrice(n)
}

// separatorBand is the "blank / dashes / blank" band the order-backed slips put
// above their column header and below their item list.
func (c *printRenderCtx) separatorBand() {
	c.e.Feed(1)
	c.e.Line(dashedLine(c.w))
	c.e.Feed(1)
}

// columnHeaderText splits a two-column header string ("San pham   Thanh tien")
// into its left and right halves on the first run of 2+ spaces, then re-justifies
// them to the ACTUAL paper width.
//
// The authored text is stored pre-spaced (that is how a human writes a column
// header in a form field), but a header laid out for 32 columns would collide
// or drift on 48. Re-justifying is presentation, not computation — the words
// still come entirely from the definition.
func columnHeaderText(s string) (left, right string) {
	runes := []rune(s)
	for i := 0; i+1 < len(runes); i++ {
		if runes[i] == ' ' && runes[i+1] == ' ' {
			left = string(runes[:i])
			j := i
			for j < len(runes) && runes[j] == ' ' {
				j++
			}
			return left, string(runes[j:])
		}
	}
	return s, ""
}

// emitAuthoredText renders a brand-authored `text` block: the copy HQ wrote,
// in the reader's locale, wrapped to the paper and justified per the block.
//
// This is the one emitter whose output is entirely the brand's words — the
// "thank you", the opening-hours note, the campaign line. It is also why the
// registry exists at all, so it deliberately supports nothing more than text,
// alignment and bold: anything richer would be a language, and a language on a
// receipt is a way to print a number nobody can trace.
//
// Centering is computed against the CONTENT width rather than delegated to the
// printer's own centre mode, so an authored line sits inside the same left
// margin as every other line instead of drifting relative to them.
func emitAuthoredText(c *printRenderCtx, b *PrintTemplateBlock) {
	text := strings.TrimSpace(c.def.text(b, c.locale, c.w < 42))
	if text == "" {
		return
	}
	if b.Bold {
		c.e.Bold(true)
	}
	for _, line := range wrapText(text, c.w) {
		switch b.Align {
		case "center":
			c.e.Line(spaces(max((c.w-displayWidth(line))/2, 0)) + line)
		case "right":
			c.e.Line(spaces(max(c.w-displayWidth(line), 0)) + line)
		default:
			c.e.Line(line)
		}
	}
	if b.Bold {
		c.e.Bold(false)
	}
}

func runeLen(s string) int {
	n := 0
	for range s {
		n++
	}
	return n
}
