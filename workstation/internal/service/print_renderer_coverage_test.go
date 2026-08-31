package service

import (
	"bytes"
	"encoding/json"
	"fmt"
	"log/slog"
	"strings"
	"testing"
)

// plan-053 M3 (#1171) — exhaustive renderer coverage.
//
// The golden gate proves the SYSTEM DEFAULT is safe to migrate to. It cannot
// prove anything about what a brand will do with the registry afterwards,
// because the default deliberately leaves most of it switched off. This file
// exercises the parts a brand will actually reach for: every block type, every
// locale path, every wrap shape, both paper widths, and every way a definition
// can arrive damaged.
//
// The invariant under all of it: THE SLIP STILL PRINTS.

// ─── helpers ──────────────────────────────────────────────────────────────

// mutateDefinition clones the embedded default for a kind and hands it to fn
// as a decoded document, so a test can express "the same template, except…"
// without restating 25 blocks.
func mutateDefinition(t *testing.T, kind string, fn func(doc map[string]any)) *PrintTemplateDefinition {
	t.Helper()
	def, err := ParsePrintTemplateDefinition(mutateDefinitionBytes(t, kind, fn))
	if err != nil {
		t.Fatalf("parse mutated definition: %v", err)
	}
	if def.Kind == "" {
		def.Kind = kind
	}
	return def
}

func mutateDefinitionBytes(t *testing.T, kind string, fn func(doc map[string]any)) []byte {
	t.Helper()
	raw, err := SystemPrintTemplateRaw(kind)
	if err != nil {
		t.Fatalf("system default %q: %v", kind, err)
	}
	var doc map[string]any
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatalf("unmarshal default: %v", err)
	}
	fn(doc)
	out, err := json.Marshal(doc)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}
	return out
}

// blocksOf returns the decoded block list of a mutable definition document.
func blocksOf(doc map[string]any) []any {
	blocks, _ := doc["blocks"].([]any)
	return blocks
}

func withBlock(doc map[string]any, id string, fn func(blk map[string]any)) {
	for _, b := range blocksOf(doc) {
		blk, _ := b.(map[string]any)
		if blk["id"] == id {
			fn(blk)
			return
		}
	}
}

func dropBlock(doc map[string]any, id string) {
	var kept []any
	for _, b := range blocksOf(doc) {
		blk, _ := b.(map[string]any)
		if blk["id"] == id {
			continue
		}
		kept = append(kept, b)
	}
	doc["blocks"] = kept
}

// renderKind is the workhorse: render one kind at one width/locale and return
// the bytes plus the per-block segments keyed by block id.
func renderKind(t *testing.T, def *PrintTemplateDefinition, kind, locale string, cols int) (PrintRenderResult, map[string]string) {
	t.Helper()
	cfg := goldenConfig(locale, cols)
	res, err := RenderPrintTemplate(def, goldenRenderData(kind, cfg), PrintRenderProfile{Columns: cols}, locale)
	if err != nil {
		t.Fatalf("render %s/%s/%d: %v", kind, locale, cols, err)
	}
	seen := map[string]string{}
	for _, s := range res.Segments {
		seen[s.BlockID] += string(s.Bytes)
	}
	return res, seen
}

// hasCut reports whether the stream ends in a cut — the cheapest proof that a
// complete slip was produced rather than a truncated one.
func hasCut(b []byte) bool { return bytes.Contains(b, []byte{0x1B, 0x64, 0x33}) }

// ─── block types, individually and combined ───────────────────────────────

// Every block TYPE in the catalog gets rendered on its own and then all
// together, so a type that silently emits nothing cannot hide behind its
// neighbours.
func TestPrintRenderer_BlockTypes_EachOneRendersSomething(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	cases := []struct {
		blockID   string
		blockType string
		kind      string
		// enable turns the block on in the definition (some ship disabled).
		enable bool
		// wantEmpty marks the types the workstation renderer knowingly cannot
		// emit yet; see the comment on each.
		wantEmpty bool
		why       string
	}{
		{blockID: "store_info", blockType: "params", kind: "receipt"},
		// `title` shares one PHYSICAL LINE with `store_info` ("ベト屋   支払済"),
		// so whichever of the two the definition places first draws the line and
		// the other is a no-op. store_info comes first in every default, so the
		// composed line is attributed to it. TestPrintRenderer_ComposedHeader
		// below covers the title-only and store-only shapes.
		{blockID: "title", blockType: "text", kind: "receipt", wantEmpty: true,
			why: "composed onto the store_info line"},
		{blockID: "order_meta", blockType: "params", kind: "receipt"},
		{blockID: "items", blockType: "line_items", kind: "receipt"},
		{blockID: "grand_total", blockType: "locked", kind: "receipt"},
		{blockID: "tax_breakdown", blockType: "locked", kind: "receipt"},
		{blockID: "qr_block", blockType: "qr", kind: "runner"},
		{blockID: "footer_text", blockType: "text", kind: "receipt", enable: true},
		// `image` has no emitter: the ESC/POS encoder in this repo has no raster
		// primitive, and internal/printer belongs to plan-052's capability track
		// which this milestone must not touch. Enabling a logo must therefore be
		// a SAFE no-op — never a crash, never a stray byte.
		{blockID: "logo", blockType: "image", kind: "receipt", enable: true, wantEmpty: true,
			why: "no raster primitive yet — plan-052 owns internal/printer"},
	}

	for _, tc := range cases {
		t.Run(tc.blockType+"/"+tc.blockID, func(t *testing.T) {
			def := mutateDefinition(t, tc.kind, func(doc map[string]any) {
				if tc.enable {
					withBlock(doc, tc.blockID, func(blk map[string]any) {
						blk["enabled"] = true
						if tc.blockID == "footer_text" {
							blk["i18n"] = map[string]any{"ja": "ご来店ありがとうございます", "en": "Thank you", "vi": "Cam on quy khach"}
						}
					})
				}
			})
			res, seen := renderKind(t, def, tc.kind, "ja", 42)
			if !hasCut(res.Bytes()) {
				t.Fatal("no cut command — the slip is incomplete")
			}
			body, ok := seen[tc.blockID]
			switch {
			case tc.wantEmpty:
				if ok && body != "" {
					t.Errorf("%s was expected to emit nothing (%s) but produced %d bytes", tc.blockID, tc.why, len(body))
				}
			case !ok || body == "":
				t.Errorf("%s (%s) emitted nothing", tc.blockID, tc.blockType)
			}
		})
	}
}

// All authored types switched on at once, on both paper widths — the
// combination a brand that uses every field would produce.
func TestPrintRenderer_BlockTypes_AllTogetherOnBothPapers(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	def := mutateDefinition(t, "receipt", func(doc map[string]any) {
		for _, id := range []string{"logo", "qr_block", "header_text", "footer_text", "greeting"} {
			withBlock(doc, id, func(blk map[string]any) {
				blk["enabled"] = true
				if blk["type"] == "text" {
					blk["i18n"] = map[string]any{
						"ja": "本日はご来店いただきありがとうございます",
						"en": "Thank you for visiting us today",
						"vi": "Cam on quy khach da ghe tham hom nay",
					}
				}
			})
		}
	})

	for _, cols := range []int{32, 48} { // 58mm and 80mm
		for _, locale := range []string{"ja", "en", "vi"} {
			t.Run(fmt.Sprintf("%dcol/%s", cols, locale), func(t *testing.T) {
				res, seen := renderKind(t, def, "receipt", locale, cols)
				if !hasCut(res.Bytes()) {
					t.Fatal("slip has no cut command")
				}
				for _, id := range []string{"header_text", "footer_text", "greeting", "qr_block", "items", "grand_total"} {
					if seen[id] == "" {
						t.Errorf("%s emitted nothing at %d columns", id, cols)
					}
				}
				// A QR was requested, so the QR command must be on the wire.
				if !bytes.Contains(res.Bytes(), []byte{0x1D, 0x28, 0x6B}) && !bytes.Contains(res.Bytes(), []byte{0x1B, 0x1D, 0x79}) {
					t.Error("qr_block was enabled but no QR command reached the printer")
				}
			})
		}
	}
}

// The composed header: store name and title share one line, and each of the
// three shapes (both / store only / title only) must render.
func TestPrintRenderer_ComposedHeader_AllThreeShapes(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	t.Run("both_on_one_line", func(t *testing.T) {
		// Title re-enabled explicitly — see the note in `title_only`.
		def := mutateDefinition(t, "receipt", func(doc map[string]any) {
			withBlock(doc, "title", func(blk map[string]any) { blk["enabled"] = true })
		})
		_, seen := renderKind(t, def, "receipt", "ja", 42)
		line := seen["store_info"]
		if !strings.Contains(line, "\n") && line == "" {
			t.Fatal("header emitted nothing")
		}
		if seen["title"] != "" {
			t.Error("title must not draw a second line — it shares the store_info line")
		}
		// Bất biến là "TÊN QUÁN và TIÊU ĐỀ nằm chung MỘT dòng vật lý", không phải
		// "cả khối store_info chỉ có một dòng". Trước #2000 bước 6 hai câu đó
		// trùng nhau nên đếm line feed cả khối là proxy đủ dùng; giờ khối còn
		// mang 法人名 / thương hiệu / địa chỉ / TEL, nên proxy đó đo nhầm thứ.
		//
		// Đo thẳng: tìm dòng CHỨA tên quán và đòi tiêu đề nằm cùng dòng đó.
		var composed string
		for _, l := range strings.Split(line, "\n") {
			if strings.Contains(l, "\x83x\x83g\x89\xae") { // ベト屋, Shift_JIS
				composed = l

				break
			}
		}
		if composed == "" {
			t.Fatalf("không thấy dòng có tên quán trong %q", line)
		}
		if !strings.Contains(composed, "\x8ex\x95\xa5\x8d\xcf") { // 支払済
			t.Errorf("tên quán và tiêu đề phải chung MỘT dòng, dòng đó là %q", composed)
		}
	})

	t.Run("title_only", func(t *testing.T) {
		// The system default now ships `title` DISABLED on every bill kind
		// (2026-08-17). These subtests are about the header COMPOSITION logic,
		// not about what ships on, so they turn it back on explicitly — which is
		// also exactly what a brand does from the template screen.
		def := mutateDefinition(t, "receipt", func(doc map[string]any) {
			dropBlock(doc, "store_info")
			withBlock(doc, "title", func(blk map[string]any) { blk["enabled"] = true })
		})
		_, seen := renderKind(t, def, "receipt", "ja", 42)
		if seen["title"] == "" {
			t.Error("with no store_info block, the title must draw its own line")
		}
	})

	t.Run("store_only", func(t *testing.T) {
		def := mutateDefinition(t, "receipt", func(doc map[string]any) {
			withBlock(doc, "title", func(blk map[string]any) { blk["enabled"] = false })
		})
		_, seen := renderKind(t, def, "receipt", "ja", 42)
		if seen["store_info"] == "" {
			t.Error("with the title off, the store name must still print")
		}
	})

	t.Run("long_store_name_wraps_to_two_lines", func(t *testing.T) {
		def := mutateDefinition(t, "receipt", func(map[string]any) {})
		cfg := goldenConfig("ja", 32)
		cfg.StoreName = "とても長い店舗名テストベトナム料理店"
		res, err := RenderPrintTemplate(def, NewPaidRenderData(mustOrder(goldenOrder()), mustItems(goldenOrder()), 7, cfg, goldenSlip()),
			PrintRenderProfile{Columns: 32}, "ja")
		if err != nil {
			t.Fatal(err)
		}
		if !hasCut(res.Bytes()) {
			t.Fatal("a long store name broke the slip")
		}
	})
}

func mustOrder(o *Order, _ []Item) *Order { return o }
func mustItems(_ *Order, i []Item) []Item { return i }

// A `params` block prints ONLY the fields it names — the definition chooses
// which known field to show and can never invent a binding.
func TestPrintRenderer_ParamsBlock_HonoursFieldSelection(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	both := mutateDefinition(t, "receipt", func(doc map[string]any) {
		withBlock(doc, "order_meta", func(blk map[string]any) { blk["fields"] = []any{"order_no", "table"} })
	})
	onlyOrderNo := mutateDefinition(t, "receipt", func(doc map[string]any) {
		withBlock(doc, "order_meta", func(blk map[string]any) { blk["fields"] = []any{"order_no"} })
	})
	unknownField := mutateDefinition(t, "receipt", func(doc map[string]any) {
		withBlock(doc, "order_meta", func(blk map[string]any) { blk["fields"] = []any{"order_no", "not_a_field"} })
	})

	_, withTable := renderKind(t, both, "receipt", "ja", 42)
	_, withoutTable := renderKind(t, onlyOrderNo, "receipt", "ja", 42)
	_, withJunk := renderKind(t, unknownField, "receipt", "ja", 42)

	if len(withTable["order_meta"]) <= len(withoutTable["order_meta"]) {
		t.Error("dropping the table field did not shorten the meta block")
	}
	if withJunk["order_meta"] != withoutTable["order_meta"] {
		t.Error("an unknown field must be ignored, not rendered — got a different block")
	}
}

// ─── i18n (TR-19) ─────────────────────────────────────────────────────────

// The fallback chain is locale → ja → en, and it is walked in that order: a
// template translated into en+ja must serve JA to a Vietnamese cashier, not EN.
func TestPrintRenderer_TR19_FallbackChainOrderIsLocaleThenJaThenEn(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	jaAndEn := mutateDefinition(t, "receipt", func(doc map[string]any) {
		withBlock(doc, "footer_text", func(blk map[string]any) {
			blk["enabled"] = true
			blk["align"] = "left"
			blk["i18n"] = map[string]any{"ja": "JAFOOTER", "en": "ENFOOTER"}
		})
	})
	_, seen := renderKind(t, jaAndEn, "receipt", "vi", 42)
	if !strings.Contains(seen["footer_text"], "JAFOOTER") {
		t.Errorf("vi should fall back to ja first, got %q", seen["footer_text"])
	}

	enOnly := mutateDefinition(t, "receipt", func(doc map[string]any) {
		withBlock(doc, "footer_text", func(blk map[string]any) {
			blk["enabled"] = true
			blk["align"] = "left"
			blk["i18n"] = map[string]any{"en": "ENFOOTER"}
		})
	})
	_, seen = renderKind(t, enOnly, "receipt", "vi", 42)
	if !strings.Contains(seen["footer_text"], "ENFOOTER") {
		t.Errorf("with no ja, vi should fall back to en, got %q", seen["footer_text"])
	}

	// A locale nobody wrote at all still gets words rather than a blank line.
	only := mutateDefinition(t, "receipt", func(doc map[string]any) {
		withBlock(doc, "footer_text", func(blk map[string]any) {
			blk["enabled"] = true
			blk["align"] = "left"
			blk["i18n"] = map[string]any{"vi": "VIFOOTER"}
		})
	})
	_, seen = renderKind(t, only, "receipt", "en", 42)
	if seen["footer_text"] == "" {
		t.Error("a template with only vi must still print SOMETHING for en")
	}
}

// One warning per (template, locale) — and a SECOND locale is a second warning,
// because it is a different fact about the same template.
func TestPrintRenderer_TR19_WarnOncePerTemplateAndLocale(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	counter := &countingHandler{}
	prev := slog.Default()
	slog.SetDefault(slog.New(counter))
	defer slog.SetDefault(prev)

	// TWO untranslated blocks, so a per-block ledger would show up as 2 warnings
	// per locale instead of 1.
	def := mutateDefinition(t, "receipt", func(doc map[string]any) {
		for _, id := range []string{"footer_text", "greeting"} {
			withBlock(doc, id, func(blk map[string]any) {
				blk["enabled"] = true
				blk["i18n"] = map[string]any{"ja": "日本語のみ"}
			})
		}
	})

	for i := 0; i < 4; i++ {
		renderKind(t, def, "receipt", "vi", 42)
		renderKind(t, def, "receipt", "en", 42)
	}
	if got := counter.count("print template locale missing"); got != 2 {
		t.Errorf("want exactly 2 warnings (vi + en) across 8 prints of 2 untranslated blocks, got %d", got)
	}

	// The JA render needs no fallback at all, so it must add no warning.
	before := counter.count("print template locale missing")
	renderKind(t, def, "receipt", "ja", 42)
	if after := counter.count("print template locale missing"); after != before {
		t.Errorf("a fully-translated locale must not warn (%d → %d)", before, after)
	}
}

// ─── text wrapping (TR-20) ────────────────────────────────────────────────

func TestPrintRenderer_TR20_WrapShapes(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	cases := []struct {
		name string
		text string
	}{
		{"fullwidth_kanji_only", "本日はご来店いただき誠にありがとうございます今後ともよろしくお願いいたします"},
		{"mixed_fullwidth_halfwidth", "本日は ABC ストア ver.2 をご利用 thank you very much indeed 誠にありがとう"},
		{"single_token_longer_than_the_line", strings.Repeat("A", 200)},
		{"single_fullwidth_token_longer_than_the_line", strings.Repeat("漢", 90)},
		{"whitespace_only", "        "},
		{"newlines_inside", "line one\nline two is quite a lot longer than the first one\n\nline four"},
		// Outside Shift_JIS: Vietnamese combining diacritics + an emoji. The
		// encoder folds/最善-effort encodes these; the renderer's job is only to
		// measure and wrap them without panicking.
		{"outside_codepage", "Cảm ơn quý khách 🎉 đã ghé thăm nhà hàng chúng tôi hôm nay"},
	}

	for _, cols := range []int{32, 48} {
		for _, tc := range cases {
			t.Run(fmt.Sprintf("%s/%dcol", tc.name, cols), func(t *testing.T) {
				def := mutateDefinition(t, "receipt", func(doc map[string]any) {
					withBlock(doc, "footer_text", func(blk map[string]any) {
						blk["enabled"] = true
						blk["align"] = "left"
						blk["i18n"] = map[string]any{"ja": tc.text, "en": tc.text, "vi": tc.text}
					})
				})
				res, seen := renderKind(t, def, "receipt", "ja", cols)
				if !hasCut(res.Bytes()) {
					t.Fatal("slip incomplete")
				}
				// Measure the AUTHORED string, not the encoded bytes: display
				// width is a property of the text, and the codepage fold that
				// happens later belongs to the encoder.
				for _, line := range wrapText(tc.text, cols) {
					if w := displayWidth(line); w > cols {
						t.Errorf("wrapped line is %d columns wide on %d-column paper: %q", w, cols, line)
					}
				}
				if strings.TrimSpace(tc.text) != "" && seen["footer_text"] == "" {
					t.Error("non-empty authored text produced no output")
				}
				if strings.TrimSpace(tc.text) == "" && seen["footer_text"] != "" {
					t.Error("whitespace-only authored text must print nothing, not a blank line")
				}
			})
		}
	}
}

// displayWidth is the measurement everything above depends on, so it gets its
// own direct assertions: fullwidth = 2, combining marks = 0, ASCII = 1.
func TestPrintRenderer_DisplayWidthContract(t *testing.T) {
	cases := []struct {
		s    string
		want int
	}{
		{"", 0},
		{"abc", 3},
		{"合計", 4},
		{"合計 1,000", 4 + 1 + 5},
		// ※ (U+203B) is East-Asian AMBIGUOUS, so Unicode metadata calls it one
		// column while the Japanese head puts down two — Shift_JIS encodes it
		// 0x81 0xA6. This assertion used to pin the narrow measurement on the
		// grounds that every filed 軽減税率 line had been laid out with it, and
		// that changing it would have to be a deliberate layout change rather
		// than a tidy-up. It now is one: measured against the encoder, the old
		// value put every reduced-rate item's price one column right of the
		// money column. See shiftJISWideRanges.
		{"※", 2},
		{"Hà Nội", 6},   // precomposed
		{"Hà Nội", 6}, // decomposed — the combining marks add nothing
		{"￥", 2},
	}
	for _, tc := range cases {
		if got := displayWidth(tc.s); got != tc.want {
			t.Errorf("displayWidth(%q) = %d, want %d", tc.s, got, tc.want)
		}
	}
}

// ─── damaged / hostile definitions (TR-14) ────────────────────────────────

// A definition that positions the locked blocks WRONG — reordered, or with the
// compliance blocks removed — must still produce a printable slip built from
// the data. Validation is Cloud's job at publish; the print path never argues.
func TestPrintRenderer_LockedBlocksMisdefined_StillBuildsFromData(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	t.Run("reordered", func(t *testing.T) {
		def := mutateDefinition(t, "receipt", func(doc map[string]any) {
			blocks := blocksOf(doc)
			// Reverse the money footer: grand_total before the item table.
			for i, j := 0, len(blocks)-1; i < j; i, j = i+1, j-1 {
				blocks[i], blocks[j] = blocks[j], blocks[i]
			}
			doc["blocks"] = blocks
		})
		res, seen := renderKind(t, def, "receipt", "ja", 42)
		if !hasCut(res.Bytes()) {
			t.Fatal("reordering the blocks broke the slip")
		}
		for _, id := range []string{"items", "grand_total", "tax_breakdown"} {
			if seen[id] == "" {
				t.Errorf("%s vanished after reordering", id)
			}
		}
	})

	t.Run("compliance_blocks_removed", func(t *testing.T) {
		def := mutateDefinition(t, "receipt", func(doc map[string]any) {
			for _, id := range []string{"tax_breakdown", "grand_total", "registration_number", "reprint_marker"} {
				dropBlock(doc, id)
			}
		})
		res, seen := renderKind(t, def, "receipt", "ja", 42)
		if !hasCut(res.Bytes()) {
			t.Fatal("dropping locked blocks broke the slip")
		}
		if seen["grand_total"] != "" || seen["tax_breakdown"] != "" {
			t.Error("a dropped block must not render")
		}
		if seen["items"] == "" {
			t.Error("the item table should be unaffected by unrelated removals")
		}
	})

	t.Run("duplicated_block", func(t *testing.T) {
		def := mutateDefinition(t, "receipt", func(doc map[string]any) {
			blocks := blocksOf(doc)
			for _, b := range blocks {
				if blk, _ := b.(map[string]any); blk["id"] == "grand_total" {
					doc["blocks"] = append(blocks, blk)
					return
				}
			}
		})
		res, _ := renderKind(t, def, "receipt", "ja", 42)
		if !hasCut(res.Bytes()) {
			t.Fatal("a duplicated block broke the slip")
		}
	})

	t.Run("unknown_block_id", func(t *testing.T) {
		def := mutateDefinition(t, "receipt", func(doc map[string]any) {
			doc["blocks"] = append(blocksOf(doc), map[string]any{"id": "totally_unknown", "type": "text",
				"i18n": map[string]any{"ja": "x"}})
		})
		res, seen := renderKind(t, def, "receipt", "ja", 42)
		if !hasCut(res.Bytes()) {
			t.Fatal("an unknown block broke the slip")
		}
		if seen["totally_unknown"] != "" {
			t.Error("an unknown block id must be skipped, not guessed at")
		}
	})
}

// Every way a definition can fail to be a definition. Each must be REFUSED by
// the parser (so the caller can fall back), never half-accepted.
func TestPrintRenderer_TR14_MalformedDefinitionsAreAllRefused(t *testing.T) {
	cases := []struct {
		name string
		body string
	}{
		{"empty", ``},
		{"whitespace", "   \n\t "},
		{"not_json", `{definitely not json`},
		{"json_but_not_an_object", `[1,2,3]`},
		{"no_schema", `{"blocks":[{"id":"items","type":"line_items"}]}`},
		{"wrong_schema", `{"schema":"receiptline/1.0","blocks":[{"id":"items"}]}`},
		{"future_schema", `{"schema":"tempo.print.v2","blocks":[{"id":"items"}]}`},
		{"no_blocks_key", `{"schema":"tempo.print.v1"}`},
		{"empty_blocks", `{"schema":"tempo.print.v1","blocks":[]}`},
		{"truncated", `{"schema":"tempo.print.v1","blocks":[{"id":"items"`},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if _, err := ParsePrintTemplateDefinition([]byte(tc.body)); err == nil {
				t.Error("expected the parser to refuse this, so the caller can fall back")
			}
		})
	}
}

// …and each of those, sitting in the cache, must end in a printable slip that
// is byte-identical to today's receipt (TR-14 + TR-40 together).
func TestPrintRenderer_TR14_EveryDamagedCacheRowStillPrintsTodaysSlip(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	damaged := map[string]string{
		"not_json":        `{definitely not json`,
		"wrong_schema":    `{"schema":"receiptline/1.0","blocks":[{"id":"items"}]}`,
		"empty_blocks":    `{"schema":"tempo.print.v1","blocks":[]}`,
		"truncated":       `{"schema":"tempo.print.v1","blocks":[{"id":"items"`,
		"json_null":       `null`,
		"json_array":      `["items"]`,
		"empty_string":    ``,
		"only_whitespace": `   `,
	}

	cfg := goldenConfig("ja", 42)
	order, items := goldenOrder()
	want := FormatPaidTicket(order, items, 7, cfg, goldenSlip())

	for name, body := range damaged {
		t.Run(name, func(t *testing.T) {
			db := newPrintTemplateTestDB(t)
			if _, err := db.Exec(`
				INSERT INTO print_templates (kind, version, scope, definition, effective_from, checksum, is_system_default, fetched_at)
				VALUES ('receipt', 12, 'brand', ?, NULL, 'unverified', 0, datetime('now'))`, body); err != nil {
				t.Fatal(err)
			}
			s := NewPrintTemplateStore(db)
			got, src, err := s.RenderSlip(goldenRenderData("receipt", cfg), PrintRenderProfile{Columns: 42}, "ja")
			if err != nil {
				t.Fatalf("a damaged cache row must not stop a sale: %v", err)
			}
			if !src.IsSystemDefault() || !src.Fallback {
				t.Errorf("expected a flagged fallback, got %+v", src)
			}
			if !bytes.Equal(want, got) {
				t.Errorf("fallback slip is not today's receipt:\n%s", diffBytes(want, got))
			}
		})
	}
}

// A cache row that PARSES but is missing the blocks a receipt needs is not a
// fallback case — the renderer prints what it was told to print. The assertion
// is that it does so without crashing and still cuts the paper.
func TestPrintRenderer_TR14_ParsableButNearlyEmptyDefinitionPrintsMinimalSlip(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	db := newPrintTemplateTestDB(t)
	minimal := []byte(`{"schema":"tempo.print.v1","paper":{"columns_58mm":32,"columns_80mm":48},
		"blocks":[{"id":"title","type":"text","i18n":{"ja":"領収書","en":"RECEIPT","vi":"BIEN LAI"}}]}`)
	seedCachedTemplate(t, db, "receipt", 3, "", minimal)

	s := NewPrintTemplateStore(db)
	out, src, err := s.RenderSlip(goldenRenderData("receipt", goldenConfig("ja", 42)), PrintRenderProfile{Columns: 42}, "ja")
	if err != nil {
		t.Fatalf("a sparse but valid definition must render: %v", err)
	}
	if src.Version != 3 || src.Fallback {
		t.Errorf("a valid definition must be used as-is, got %+v", src)
	}
	if !hasCut(out) {
		t.Error("even a one-block slip must cut the paper")
	}
}

// Every kind survives having its authored text emptied — the shape a brand
// produces by clearing a field in the editor.
func TestPrintRenderer_EmptyAuthoredTextNeverBreaksAnyKind(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	for _, kind := range SystemPrintTemplateKinds() {
		t.Run(kind, func(t *testing.T) {
			def := mutateDefinition(t, kind, func(doc map[string]any) {
				for _, b := range blocksOf(doc) {
					blk, _ := b.(map[string]any)
					if blk["type"] == "text" {
						blk["i18n"] = map[string]any{"ja": "", "en": "", "vi": ""}
					}
				}
			})
			for _, cols := range []int{32, 48} {
				res, _ := renderKind(t, def, kind, "ja", cols)
				if !hasCut(res.Bytes()) {
					t.Errorf("%s at %d columns produced no cut", kind, cols)
				}
			}
		})
	}
}
