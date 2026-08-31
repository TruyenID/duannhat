package service

import (
	"bytes"
	"context"
	"encoding/json"
	"log/slog"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer"
)

// plan-053 M3 (#1171) — renderer behaviour that the golden gate cannot see,
// because the system default deliberately turns most of it off:
//
//	G4     raster is applied PER BLOCK, never per sheet (TR-36)
//	TR-19  a missing locale falls back and warns exactly once
//	TR-20  authored text wraps to the paper instead of overflowing it
//	TR-14  a definition the renderer cannot use is an ERROR the caller can
//	       fall back from — never a half-printed slip

// G4 — TR-36. A printer that cannot render a glyph natively needs bitmaps, but
// rasterising a whole 精算 slip costs seconds of print time. The renderer
// therefore returns the slip as per-block segments and marks only the text ones
// for raster, leaving the QR and the device control sequences native.
func TestPrintRenderer_G4_RasterIsPerBlockNotPerSheet(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	cfg := goldenConfig("ja", 42)
	def, err := SystemPrintTemplate("runner") // has a QR block enabled
	if err != nil {
		t.Fatal(err)
	}

	native, err := RenderPrintTemplate(def, goldenRenderData("runner", cfg),
		PrintRenderProfile{Columns: 42, TextMode: PrintTextNative}, "ja")
	if err != nil {
		t.Fatal(err)
	}
	raster, err := RenderPrintTemplate(def, goldenRenderData("runner", cfg),
		PrintRenderProfile{Columns: 42, TextMode: PrintTextRaster}, "ja")
	if err != nil {
		t.Fatal(err)
	}

	if len(raster.Segments) < 5 {
		t.Fatalf("expected the slip to be segmented per block, got %d segments", len(raster.Segments))
	}
	for _, s := range native.Segments {
		if s.Mode != PrintTextNative {
			t.Errorf("native profile produced a %s segment for block %q", s.Mode, s.BlockID)
		}
	}

	var rasterised, stayedNative []string
	for _, s := range raster.Segments {
		if s.Mode == PrintTextRaster {
			rasterised = append(rasterised, s.BlockID)
		} else {
			stayedNative = append(stayedNative, s.BlockID)
		}
	}
	if len(rasterised) == 0 {
		t.Fatal("raster profile rasterised nothing")
	}
	if len(stayedNative) == 0 {
		t.Fatal("raster profile rasterised the WHOLE sheet — TR-36 asks for per-block")
	}
	for _, id := range rasterised {
		if id == "qr_block" {
			t.Error("the QR block must stay native — it is a device command, not text")
		}
	}

	// And the same decision taken through plan-052's capability profile: a
	// machine with no kanji ROM rasters the Japanese blocks and leaves the
	// money native, which is the per-block split TR-36 is actually about.
	noKanji := printer.DefaultProfile() // escpos_generic — Charset.Kanji false, TextMode auto
	viaProfile, err := RenderPrintTemplate(def, goldenRenderData("runner", cfg),
		PrintRenderProfileFor(noKanji, "80mm"), "ja")
	if err != nil {
		t.Fatal(err)
	}
	var jp, ascii int
	for _, s := range viaProfile.Segments {
		if s.Mode == PrintTextRaster {
			jp++
		} else {
			ascii++
		}
	}
	if jp == 0 || ascii == 0 {
		t.Errorf("a no-kanji profile should split the slip (rastered %d, native %d)", jp, ascii)
	}
	if got := PrintRenderProfileFor(noKanji, "58mm").Columns; got != 32 {
		t.Errorf("58mm should be 32 columns from the profile, got %d", got)
	}

	kanjiROM := printer.DefaultProfile()
	kanjiROM.Charset.Kanji = true
	for _, s := range mustRender(t, def, cfg, PrintRenderProfileFor(kanjiROM, "80mm")).Segments {
		if s.Mode == PrintTextRaster {
			t.Errorf("a machine WITH a kanji ROM should print everything natively, %q was rastered", s.BlockID)
		}
	}

	// The segments must reassemble into exactly the slip, so a transport that
	// does not implement raster can concatenate them and be no worse off.
	var joined bytes.Buffer
	for _, s := range raster.Segments {
		joined.Write(s.Bytes)
	}
	if !bytes.Equal(joined.Bytes(), native.Bytes()) {
		t.Errorf("segments do not reassemble into the slip:\n%s", diffBytes(native.Bytes(), joined.Bytes()))
	}
}

// TR-19 — a brand that translated its footer into Japanese only. A Vietnamese
// cashier must still get a slip with words on it, and the operator must be told
// ONCE, not once per receipt.
func TestPrintRenderer_TR19_LocaleFallbackWarnsExactlyOnce(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	counter := &countingHandler{}
	prev := slog.Default()
	slog.SetDefault(slog.New(counter))
	defer slog.SetDefault(prev)

	raw, err := SystemPrintTemplateRaw("receipt")
	if err != nil {
		t.Fatal(err)
	}
	var doc map[string]any
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatal(err)
	}
	for _, b := range doc["blocks"].([]any) {
		blk := b.(map[string]any)
		if blk["id"] == "footer_text" {
			blk["enabled"] = true
			blk["i18n"] = map[string]any{"ja": "毎度ありがとうございます"} // ja ONLY
		}
	}
	body, _ := json.Marshal(doc)
	def, err := ParsePrintTemplateDefinition(body)
	if err != nil {
		t.Fatal(err)
	}

	cfg := goldenConfig("vi", 42)
	var first []byte
	for i := 0; i < 5; i++ {
		res, err := RenderPrintTemplate(def, goldenRenderData("receipt", cfg), PrintRenderProfile{Columns: 42}, "vi")
		if err != nil {
			t.Fatal(err)
		}
		if i == 0 {
			first = res.Bytes()
		}
	}
	if len(first) == 0 {
		t.Fatal("no slip produced")
	}
	if got := counter.count("print template locale missing"); got != 1 {
		t.Errorf("locale fallback warned %d times across 5 prints, want exactly 1", got)
	}

	// And the fallback text actually printed — a silent empty footer would be
	// the failure this whole rule exists to prevent.
	ja, err := RenderPrintTemplate(def, goldenRenderData("receipt", goldenConfig("ja", 42)), PrintRenderProfile{Columns: 42}, "ja")
	if err != nil {
		t.Fatal(err)
	}
	sysDef, _ := SystemPrintTemplate("receipt")
	plain, err := RenderPrintTemplate(sysDef, goldenRenderData("receipt", cfg), PrintRenderProfile{Columns: 42}, "vi")
	if err != nil {
		t.Fatal(err)
	}
	if len(first) <= len(plain.Bytes()) {
		t.Error("the ja footer did not print on the vi slip")
	}
	if len(ja.Bytes()) == 0 {
		t.Error("ja render produced nothing")
	}
}

// TR-20 — a brand pastes a paragraph into the footer field. It must wrap to the
// paper, not run off the edge and get truncated by the printer.
func TestPrintRenderer_TR20_AuthoredTextWrapsToPaper(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	long := "Cam on quy khach da ghe tham nha hang chung toi hom nay, hen gap lai quy khach vao lan sau"
	raw, _ := SystemPrintTemplateRaw("receipt")
	var doc map[string]any
	_ = json.Unmarshal(raw, &doc)
	for _, b := range doc["blocks"].([]any) {
		blk := b.(map[string]any)
		if blk["id"] == "footer_text" {
			blk["enabled"] = true
			blk["align"] = "left"
			blk["i18n"] = map[string]any{"ja": long, "en": long, "vi": long}
		}
	}
	body, _ := json.Marshal(doc)
	def, err := ParsePrintTemplateDefinition(body)
	if err != nil {
		t.Fatal(err)
	}

	const cols = 32
	res, err := RenderPrintTemplate(def, goldenRenderData("receipt", goldenConfig("vi", cols)), PrintRenderProfile{Columns: cols}, "vi")
	if err != nil {
		t.Fatal(err)
	}
	for _, seg := range res.Segments {
		if seg.BlockID != "footer_text" {
			continue
		}
		for _, line := range strings.Split(strings.TrimRight(string(seg.Bytes), "\n"), "\n") {
			if displayWidth(line) > cols {
				t.Errorf("footer line overflows %d columns (%d): %q", cols, displayWidth(line), line)
			}
		}
		return
	}
	t.Fatal("footer_text produced no segment")
}

// TR-14 — the renderer refuses cleanly. A kind it does not know, or a
// definition that is not a definition, must come back as an ERROR so the caller
// can reach for the system default; it must never emit a partial slip.
func TestPrintRenderer_TR14_UnusableInputIsAnErrorNotAPartialSlip(t *testing.T) {
	if _, err := ParsePrintTemplateDefinition([]byte(`{"schema":"tempo.print.v2","blocks":[{"id":"items"}]}`)); err == nil {
		t.Error("a future schema must be refused, not guessed at")
	}
	if _, err := ParsePrintTemplateDefinition([]byte(`{"schema":"tempo.print.v1","blocks":[]}`)); err == nil {
		t.Error("a definition with no blocks must be refused")
	}
	if _, err := ParsePrintTemplateDefinition(nil); err == nil {
		t.Error("an empty definition must be refused")
	}

	def, _ := SystemPrintTemplate("receipt")
	res, err := RenderPrintTemplate(def, &PrintRenderData{Kind: "not_a_kind"}, PrintRenderProfile{Columns: 42}, "ja")
	if err == nil {
		t.Error("an unknown kind must be an error")
	}
	if len(res.Bytes()) != 0 {
		t.Error("a refused render must emit no bytes at all")
	}
}

// TestPullPrintTemplates_NoTokenIsNotAnError guards the boot-time race: a
// workstation that has not finished pairing must not spam errors, and must not
// wipe anything.
func TestPullPrintTemplates_NoTokenIsNotAnError(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	p := NewSyncPuller(db, "http://127.0.0.1:1", func() string { return "" })
	if err := p.PullPrintTemplates(context.Background()); err != nil {
		t.Errorf("unpaired pull should be a silent no-op, got %v", err)
	}
}

func mustRender(t *testing.T, def *PrintTemplateDefinition, cfg PrintJobConfig, profile PrintRenderProfile) PrintRenderResult {
	t.Helper()
	res, err := RenderPrintTemplate(def, goldenRenderData("runner", cfg), profile, "ja")
	if err != nil {
		t.Fatal(err)
	}
	return res
}

// countingHandler counts slog records by message prefix.
type countingHandler struct {
	msgs []string
}

func (h *countingHandler) Enabled(context.Context, slog.Level) bool { return true }
func (h *countingHandler) Handle(_ context.Context, r slog.Record) error {
	h.msgs = append(h.msgs, r.Message)
	return nil
}
func (h *countingHandler) WithAttrs([]slog.Attr) slog.Handler { return h }
func (h *countingHandler) WithGroup(string) slog.Handler      { return h }

func (h *countingHandler) count(prefix string) int {
	n := 0
	for _, m := range h.msgs {
		if strings.HasPrefix(m, prefix) {
			n++
		}
	}
	return n
}
