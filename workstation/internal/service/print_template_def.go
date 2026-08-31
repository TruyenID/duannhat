package service

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log/slog"
	"strings"
	"sync"
)

// plan-053 M3 (#1171) — the print-template DEFINITION as the workstation reads
// it (DESIGN §3, TASKS T3.1).
//
// The definition is an intermediate representation, NOT a program: it names
// blocks, orders them, toggles them, and carries the brand's own authored text
// per locale. It contains no expressions and no arithmetic — principle #1 of
// the plan. Every number on a slip comes from RenderData, which the engine
// computed before the renderer was called.
//
// Cloud sends the definition ALREADY RESOLVED for this branch (system → brand →
// shop merged server-side, see PrintTemplateReplicaController), so nothing in
// this file re-implements the three-layer merge. A second implementation of
// TR-02/TR-04 in Go is exactly how "the preview and the slip disagree" bugs are
// born.

// printTemplateSchema is the definition envelope version the renderer speaks.
// Cloud checks it verbatim on publish; the renderer refuses anything else and
// falls back to the embedded system default (TR-14) rather than guessing.
const printTemplateSchema = "tempo.print.v1"

// PrintTemplateDefinition is the parsed IR of one kind's template.
//
// There was a `ReceiptlineDialect string` field here (`receiptline_dialect`,
// always "ofsc-1.0"). REMOVED in #2061 together with the Cloud key that fed it:
// nothing in this package ever read it, and this IR is a house format, not
// OFSC ReceiptLine. Old cached definitions may still carry the key — encoding/
// json ignores unknown fields, so they keep parsing unchanged.
type PrintTemplateDefinition struct {
	Schema string               `json:"schema"`
	Paper  PrintTemplatePaper   `json:"paper"`
	Blocks []PrintTemplateBlock `json:"blocks"`
	Kind   string               `json:"kind,omitempty"`
	// TopFeed chừa N dòng TRẮNG ở đầu phiếu, trước mọi khối. #3082 — bếp kẹp
	// phiếu vào thanh kẹp và thanh kẹp CHE MẤT dòng đầu, nên phần bị che phải
	// là giấy trắng chứ không phải chữ.
	//
	// Cấp MẪU chứ không cấp khối: nó nói về mép giấy, không nói về nội dung —
	// và đặt ở một khối thì thứ tự khối đổi là khoảng trắng chạy theo.
	//
	// 0 = như hôm nay ⇒ mọi kind khác giữ nguyên từng byte.
	TopFeed int                 `json:"top_feed,omitempty"`
	warned  map[string]struct{} // (block,locale) pairs already warned about — TR-19
	warnMu  *sync.Mutex         //
	index   map[string]*PrintTemplateBlock
}

// PrintTemplatePaper carries the authored column counts per paper size. The
// renderer only consults it when the caller did not pin a width, so a printer
// capability profile always wins over a template's opinion about paper.
type PrintTemplatePaper struct {
	Columns58mm int `json:"columns_58mm"`
	Columns80mm int `json:"columns_80mm"`
}

// PrintTemplateBlock is one addressable region of a slip.
//
// `Type` mirrors the Cloud catalog (`config/print_blocks.php`):
//
//	text        brand-authored copy, i18n per locale
//	image       a logo, referenced by an allow-listed `source`
//	params      a chosen subset of known fields (never an invented binding)
//	line_items  the item table
//	qr          a QR whose target is an allow-listed `source`
//	locked      the renderer builds the content from RenderData; the definition
//	            may only POSITION it (and flip `enabled` when toggleable)
type PrintTemplateBlock struct {
	ID      string `json:"id"`
	Type    string `json:"type"`
	Enabled *bool  `json:"enabled,omitempty"`
	Align   string `json:"align,omitempty"`
	Bold    bool   `json:"bold,omitempty"`
	// Size expands the character cell. #3082 — bếp đọc phiếu từ xa, và thanh
	// kẹp che dòng đầu.
	//
	// Chỉ `"tall"` (ESC i 1 0 — ×2 CAO, ×1 rộng). Cố ý KHÔNG có double-width:
	// nhân đôi chiều rộng thì mỗi glyph chiếm hai cột, nên `wrapText`, phép căn
	// giữa/phải và hiệu chỉnh lề trái đều phải tính lại — encoder đã nói thẳng
	// điều đó ("DoubleHeight leaves the character cell one column wide, so it
	// needs no correction"). Chiều cao không đụng số cột nên nó là phần AN TOÀN,
	// và phiếu bếp chỉ cần phần đó.
	//
	// Rỗng = như hôm nay ⇒ mọi golden hiện có giữ nguyên từng byte.
	Size     string            `json:"size,omitempty"`
	Source   string            `json:"source,omitempty"`
	Fields   []string          `json:"fields,omitempty"`
	Columns  []string          `json:"columns,omitempty"`
	Fallback bool              `json:"fallback,omitempty"`
	I18n     map[string]string `json:"i18n,omitempty"`
	// I18nNarrow is the variant used when the paper is narrower than 42
	// columns. It exists because FormatVatInvoice already shortens its title on
	// 58mm paper ("HOA DON GTGT"); expressing that in the definition is what
	// lets the registry reproduce today's slip byte-for-byte (TR-40) instead of
	// silently widening it.
	I18nNarrow map[string]string `json:"i18n_narrow,omitempty"`
	// MaxWidthDots clamps an image to the printable width (TR-22).
	MaxWidthDots int `json:"max_width_dots,omitempty"`
}

// isEnabled reports whether a block should render. Absent `enabled` means ON:
// a locked block is always on, and a definition that merely POSITIONS a block
// must not have to restate that.
func (b *PrintTemplateBlock) isEnabled() bool {
	if b == nil {
		return false
	}
	if b.Enabled == nil {
		return true
	}
	return *b.Enabled
}

// ParsePrintTemplateDefinition decodes a definition from the bytes Cloud sent
// (or from the embedded system default).
//
// It is deliberately strict about the envelope and deliberately lax about
// everything else: an unknown block id is not an error here, because the
// renderer simply has no emitter for it and skips it. Validation is Cloud's job
// at PUBLISH time (TR-14) — the print path must never refuse to print.
func ParsePrintTemplateDefinition(raw []byte) (*PrintTemplateDefinition, error) {
	if len(bytes.TrimSpace(raw)) == 0 {
		return nil, fmt.Errorf("print template: empty definition")
	}
	def := &PrintTemplateDefinition{}
	if err := json.Unmarshal(raw, def); err != nil {
		return nil, fmt.Errorf("print template: %w", err)
	}
	if def.Schema != printTemplateSchema {
		return nil, fmt.Errorf("print template: unsupported schema %q (want %q)", def.Schema, printTemplateSchema)
	}
	if len(def.Blocks) == 0 {
		return nil, fmt.Errorf("print template: definition has no blocks")
	}
	def.prepare()
	return def, nil
}

func (d *PrintTemplateDefinition) prepare() {
	d.warned = map[string]struct{}{}
	d.warnMu = &sync.Mutex{}
	d.index = make(map[string]*PrintTemplateBlock, len(d.Blocks))
	for i := range d.Blocks {
		d.index[d.Blocks[i].ID] = &d.Blocks[i]
	}
}

// block returns the block with the given id, or nil.
func (d *PrintTemplateDefinition) block(id string) *PrintTemplateBlock {
	if d == nil || d.index == nil {
		return nil
	}
	return d.index[id]
}

// has reports whether the definition contains an ENABLED block with this id.
func (d *PrintTemplateDefinition) has(id string) bool {
	return d.block(id).isEnabled()
}

// columnsFor picks the character width for a paper size when the caller did not
// pin one. 58mm/80mm are the only two widths the fleet runs.
func (p PrintTemplatePaper) columnsFor(paper string) int {
	switch strings.ToLower(strings.TrimSpace(paper)) {
	case "58", "58mm":
		return p.Columns58mm
	case "80", "80mm":
		return p.Columns80mm
	}
	return 0
}

// printLocaleFallback is the chain TR-19 mandates: the device's locale, then
// Japanese (the accounting locale of the fleet's home market), then English.
// A slip must never print an empty line because HQ forgot a translation.
var printLocaleFallback = []string{"ja", "en"}

// text resolves a block's authored copy for a locale, walking the fallback
// chain and warning ONCE per (block, locale) — the `warnedBrands` pattern from
// TaxResolver. Warning on every print would drown the log of a busy shop, and
// warning never would hide a brand that shipped a half-translated template.
func (d *PrintTemplateDefinition) text(b *PrintTemplateBlock, locale string, narrow bool) string {
	if b == nil {
		return ""
	}
	table := b.I18n
	if narrow && len(b.I18nNarrow) > 0 {
		table = b.I18nNarrow
	}
	if len(table) == 0 {
		return ""
	}
	locale = normalizePrintLocale(locale)
	if v, ok := table[locale]; ok && v != "" {
		return v
	}
	for _, fb := range printLocaleFallback {
		if v, ok := table[fb]; ok && v != "" {
			d.warnMissingLocale(b.ID, locale, fb)
			return v
		}
	}
	// Nothing usable in the chain — take any non-empty entry rather than print
	// a blank line where the brand expected words.
	for _, k := range sortedKeys(table) {
		if table[k] != "" {
			d.warnMissingLocale(b.ID, locale, k)
			return table[k]
		}
	}
	return ""
}

// warnMissingLocale logs ONCE per (template, locale) — not per block and not
// per print. A busy shop prints hundreds of slips an hour; warning per print
// would bury the log, and warning per block would repeat the same fact for
// every string the brand forgot to translate. The first block to notice names
// itself in the record so somebody can find it.
func (d *PrintTemplateDefinition) warnMissingLocale(blockID, want, used string) {
	if d.warnMu == nil {
		return
	}
	key := want
	d.warnMu.Lock()
	_, seen := d.warned[key]
	if !seen {
		d.warned[key] = struct{}{}
	}
	d.warnMu.Unlock()
	if seen {
		return
	}
	slog.Warn("print template locale missing — using fallback",
		"kind", d.Kind, "block", blockID, "locale", want, "fallback", used)
}

// normalizePrintLocale mirrors printLabelsFor: only "en" and "vi" are explicit,
// everything else (empty, unknown, "ja", "ja-JP") is Japanese.
func normalizePrintLocale(locale string) string {
	switch strings.ToLower(strings.TrimSpace(locale)) {
	case "en":
		return "en"
	case "vi":
		return "vi"
	default:
		return "ja"
	}
}

func sortedKeys(m map[string]string) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	for i := 1; i < len(out); i++ {
		for j := i; j > 0 && out[j] < out[j-1]; j-- {
			out[j], out[j-1] = out[j-1], out[j]
		}
	}
	return out
}

// PrintTemplateChecksum reproduces Cloud's `TemplateChecksum::of` in Go: sha256
// over the definition JSON with every OBJECT's keys sorted recursively and
// every ARRAY left alone (block order IS meaning on a receipt).
//
// This is the TR-24 gate — the workstation refuses to overwrite a working cache
// with a payload whose bytes do not hash to the checksum Cloud published, so a
// truncated download can never become the template a shop prints from.
//
// Parity notes with PHP's json_encode(JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):
//   - SetEscapeHTML(false) stops Go escaping <, > and & (PHP does not escape
//     them under these flags either);
//   - UseNumber keeps integers verbatim so 10 never becomes 1e+01.
func PrintTemplateChecksum(raw []byte) (string, error) {
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	var v any
	if err := dec.Decode(&v); err != nil {
		return "", err
	}
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	if err := enc.Encode(v); err != nil {
		return "", err
	}
	// json.Encoder appends a newline; PHP's json_encode does not.
	sum := sha256.Sum256(bytes.TrimRight(buf.Bytes(), "\n"))
	return hex.EncodeToString(sum[:]), nil
}
