package service

import (
	"bytes"
	"fmt"
	"golang.org/x/text/encoding/japanese"
	"golang.org/x/text/transform"
	"strings"
	"testing"
)

// plan-052 P-10b [HARD] (#1166) — THE MARK ON THE PAPER.
//
// The owner removed the 422 that used to refuse a reprint. This mark is what
// replaced it, so it carries the entire weight of "two sheets must never both
// look like an original". These tests therefore assert it on ACTUAL BYTES, for
// every money document, at every locale and paper width:
//
//	copy 1  → no mark anywhere on the slip (or the mark would mean nothing)
//	copy ≥2 → the locale's mark + " #N" ("BAN IN" vi/en, 「再印刷」 ja — #1890)
//
// and they assert it TWICE per case — once through the hard-coded formatter and
// once through the template renderer — because a shop prints through whichever
// of the two its binary reaches, and a mark that only one path emits is not a
// control, it is a coincidence.

var reprintMarkerKinds = []string{"receipt", "red_invoice", "vat_invoice", "debt_slip"}

// reprintMarkerLegacy renders a money document through the hard-coded
// formatter with a given copy number.
func reprintMarkerLegacy(t *testing.T, kind string, cfg PrintJobConfig, reprintNo int) []byte {
	t.Helper()
	order, items := goldenOrder()
	switch kind {
	case "receipt":
		slip := goldenSlip()
		slip.ReprintNumber = reprintNo
		return FormatPaidTicket(order, items, 7, cfg, slip)
	case "red_invoice":
		slip := goldenSlip()
		slip.ReprintNumber = reprintNo
		return FormatRedInvoiceTicket(order, items, cfg, slip)
	case "vat_invoice":
		info := goldenVatInvoice(cfg.Locale)
		info.ReprintNumber = reprintNo
		return FormatVatInvoice(info, cfg)
	case "debt_slip":
		info := goldenDebtInfo()
		info.ReprintNumber = reprintNo
		return FormatDebtSlip(order, items, cfg, info)
	}
	t.Fatalf("no money-document formatter for kind %q", kind)
	return nil
}

// reprintMarkerRendered renders the same document through the template
// renderer + embedded system default.
func reprintMarkerRendered(t *testing.T, kind string, cfg PrintJobConfig, paper, reprintNo int) []byte {
	t.Helper()
	order, items := goldenOrder()

	var data *PrintRenderData
	switch kind {
	case "receipt":
		slip := goldenSlip()
		slip.ReprintNumber = reprintNo
		data = NewPaidRenderData(order, items, 7, cfg, slip)
	case "red_invoice":
		slip := goldenSlip()
		slip.ReprintNumber = reprintNo
		data = NewRedInvoiceRenderData(order, items, cfg, slip)
	case "vat_invoice":
		info := goldenVatInvoice(cfg.Locale)
		info.ReprintNumber = reprintNo
		data = NewVatInvoiceRenderData(info, cfg)
	case "debt_slip":
		info := goldenDebtInfo()
		info.ReprintNumber = reprintNo
		data = NewDebtSlipRenderData(order, items, cfg, info)
	default:
		t.Fatalf("no money-document render data for kind %q", kind)
	}

	def, err := SystemPrintTemplate(kind)
	if err != nil {
		t.Fatalf("system default for %q: %v", kind, err)
	}
	res, err := RenderPrintTemplate(def, data, PrintRenderProfile{Columns: paper}, cfg.Locale)
	if err != nil {
		t.Fatalf("render %q: %v", kind, err)
	}
	return res.Bytes()
}

// TestReprintMarker_FirstCopyIsClean — copy 1 carries NO mark. This is the half
// of P-10b that is easy to lose: if the marker ever printed on an original, a
// cashier would learn to ignore it within a shift and the control would be dead
// while still looking alive.
func TestReprintMarker_FirstCopyIsClean(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	for _, kind := range reprintMarkerKinds {
		for _, locale := range []string{"ja", "en", "vi"} {
			for _, paper := range []int{32, 42, 48} {
				t.Run(fmt.Sprintf("%s/%s/%dcol", kind, locale, paper), func(t *testing.T) {
					cfg := goldenConfigFor(kind, locale, paper)

					for label, got := range map[string][]byte{
						"formatter": reprintMarkerLegacy(t, kind, cfg, 1),
						"renderer":  reprintMarkerRendered(t, kind, cfg, paper, 1),
					} {
						if strings.Contains(printedText(got), reprintMarkFor(locale)+" #") {
							t.Errorf("%s: first print of %s carries a reprint mark — an original must be clean", label, kind)
						}
					}
				})
			}
		}
	}
}

// TestReprintMarker_ZeroIsAlsoAFirstPrint — callers that never learned about
// reprint numbering leave the field at its zero value. That is a FIRST print,
// not "copy #0": treating it as a reprint would stamp a mark on every slip from
// an un-migrated call site.
func TestReprintMarker_ZeroIsAlsoAFirstPrint(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	for _, kind := range reprintMarkerKinds {
		cfg := goldenConfig("vi", 48)
		if strings.Contains(printedText(reprintMarkerLegacy(t, kind, cfg, 0)), reprintMarkFor(cfg.Locale)+" #") {
			t.Errorf("%s: reprint number 0 printed a mark", kind)
		}
	}
}

// TestReprintMarker_EveryCopyAfterTheFirstIsMarked — the mark, with the right N.
func TestReprintMarker_EveryCopyAfterTheFirstIsMarked(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	for _, kind := range reprintMarkerKinds {
		for _, locale := range []string{"ja", "en", "vi"} {
			for _, paper := range []int{32, 42, 48} {
				for _, n := range []int{2, 3, 12} {
					t.Run(fmt.Sprintf("%s/%s/%dcol/n%d", kind, locale, paper, n), func(t *testing.T) {
						cfg := goldenConfigFor(kind, locale, paper)
						want := fmt.Sprintf("%s #%d", reprintMarkFor(locale), n)

						for label, got := range map[string][]byte{
							"formatter": reprintMarkerLegacy(t, kind, cfg, n),
							"renderer":  reprintMarkerRendered(t, kind, cfg, paper, n),
						} {
							if !strings.Contains(printedText(got), want) {
								t.Errorf("%s: copy %d of %s is missing %q — it would pass as an original",
									label, n, kind, want)
							}
						}
					})
				}
			}
		}
	}
}

// TestReprintMarker_FormatterAndRendererAgreeByteForByte — the two print paths
// must produce the SAME sheet. A shop on an older binary prints through the
// formatter and a shop on a published template prints through the renderer; if
// only one marks the copy, the control depends on which build the shop happens
// to run.
func TestReprintMarker_FormatterAndRendererAgreeByteForByte(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	for _, kind := range reprintMarkerKinds {
		for _, locale := range []string{"ja", "en", "vi"} {
			for _, paper := range []int{32, 42, 48} {
				for _, n := range []int{1, 2, 5} {
					t.Run(fmt.Sprintf("%s/%s/%dcol/n%d", kind, locale, paper, n), func(t *testing.T) {
						cfg := goldenConfigFor(kind, locale, paper)
						legacy := reprintMarkerLegacy(t, kind, cfg, n)
						rendered := reprintMarkerRendered(t, kind, cfg, paper, n)
						if !bytes.Equal(legacy, rendered) {
							t.Fatalf("reprint mark diverges for %s (%s, %d cols, copy %d)\n%s",
								kind, locale, paper, n, diffBytes(legacy, rendered))
						}
					})
				}
			}
		}
	}
}

// TestReprintMarker_MarkIsItsOwnSegment — the renderer must attribute the mark
// to the `reprint_marker` block, not fold it into a neighbour. Segments are how
// a raster-only printer (a cheap machine with no kanji ROM, DESIGN §3b) gets its
// bitmap per block; a mark hidden inside another block's segment would still
// print, but it could no longer be reasoned about — or found — per block.
func TestReprintMarker_MarkIsItsOwnSegment(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	for _, kind := range reprintMarkerKinds {
		t.Run(kind, func(t *testing.T) {
			cfg := goldenConfig("vi", 48)
			order, items := goldenOrder()

			var data *PrintRenderData
			switch kind {
			case "receipt":
				slip := goldenSlip()
				slip.ReprintNumber = 4
				data = NewPaidRenderData(order, items, 7, cfg, slip)
			case "red_invoice":
				slip := goldenSlip()
				slip.ReprintNumber = 4
				data = NewRedInvoiceRenderData(order, items, cfg, slip)
			case "vat_invoice":
				info := goldenVatInvoice(cfg.Locale)
				info.ReprintNumber = 4
				data = NewVatInvoiceRenderData(info, cfg)
			case "debt_slip":
				info := goldenDebtInfo()
				info.ReprintNumber = 4
				data = NewDebtSlipRenderData(order, items, cfg, info)
			}

			def, err := SystemPrintTemplate(kind)
			if err != nil {
				t.Fatalf("system default: %v", err)
			}
			res, err := RenderPrintTemplate(def, data, PrintRenderProfile{Columns: 48}, cfg.Locale)
			if err != nil {
				t.Fatalf("render: %v", err)
			}

			var found bool
			for _, seg := range res.Segments {
				if seg.BlockID != "reprint_marker" {
					continue
				}
				found = true
				if !strings.Contains(string(seg.Bytes), "BAN IN #4") {
					t.Errorf("reprint_marker segment does not carry the mark: %q", string(seg.Bytes))
				}
			}
			if !found {
				t.Errorf("no reprint_marker segment emitted for %s — the mark has no block of its own", kind)
			}
		})
	}
}

// TestReprintMarker_SurvivesADamagedTemplate — a brand cannot delete the block
// (Cloud rejects that at publish, see the backend TemplateValidator), but a
// definition can still arrive damaged: truncated, hand-edited, from an old
// version. The renderer's answer to a missing `reprint_marker` must never be a
// silently clean copy of a money document, so the fallback to the embedded
// system default has to reinstate it.
func TestReprintMarker_SurvivesADamagedTemplate(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	cfg := goldenConfig("vi", 48)
	order, items := goldenOrder()
	slip := goldenSlip()
	slip.ReprintNumber = 3

	stripped := mutateDefinition(t, "receipt", func(doc map[string]any) {
		dropBlock(doc, "reprint_marker")
	})

	res, err := RenderPrintTemplate(stripped, NewPaidRenderData(order, items, 7, cfg, slip),
		PrintRenderProfile{Columns: 48}, cfg.Locale)
	if err != nil {
		t.Fatalf("render stripped: %v", err)
	}
	// The renderer honours the definition it is handed — this documents the
	// exact seam the Cloud-side lock is protecting. If this ever starts
	// printing the mark on its own, the lock has moved into the renderer and
	// the comment above is stale.
	if strings.Contains(string(res.Bytes()), "BAN IN #") {
		t.Log("renderer now reinstates the mark by itself — stronger than documented, update this test's premise")
	}

	// …and the system default, which is what RenderWithFallback reaches for,
	// always carries it.
	def, err := SystemPrintTemplate("receipt")
	if err != nil {
		t.Fatalf("system default: %v", err)
	}
	if !def.has("reprint_marker") {
		t.Fatal("the embedded system receipt template lost its reprint_marker block (P-10b)")
	}
	fallback, err := RenderPrintTemplate(def, NewPaidRenderData(order, items, 7, cfg, slip),
		PrintRenderProfile{Columns: 48}, cfg.Locale)
	if err != nil {
		t.Fatalf("render default: %v", err)
	}
	if !strings.Contains(string(fallback.Bytes()), "BAN IN #3") {
		t.Fatal("the embedded system receipt default does not print the reprint mark")
	}
}

// reprintMarkFor is the wording the copy marker must carry in a locale (#1890):
// ASCII for vi/en because half the fleet has no kanji ROM, 「再印刷」 for ja
// because the staff reading the slip are Japanese. Reads the SAME catalog the
// printer does, so this test cannot drift from the paper by restating a string.
func reprintMarkFor(locale string) string { return printLabelsFor(locale).ReprintMark }

// printedText decodes what actually left the encoder. The printer emits
// Shift_JIS, so a UTF-8 `strings.Contains` finds nothing on a ja slip even when
// the mark is on the paper, correctly right-aligned — searching the raw bytes
// would silently pass every ja assertion for the wrong reason.
func printedText(b []byte) string {
	dec, _, err := transform.Bytes(japanese.ShiftJIS.NewDecoder(), b)
	if err != nil {
		return string(b)
	}
	return string(dec)
}
