package handler

import (
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// plan-052 T1.4d (#1166) — the printer setup wizard.
//
// The wizard's whole value is that a shop employee, holding the paper, can
// teach the system about a machine nobody in the office has ever seen. These
// tests cover the two halves of that: the sheet asks answerable questions, and
// the answers become a profile without destroying what was already known.

func boolPtr(b bool) *bool { return &b }
func intPtr(i int) *int    { return &i }

// P-40 — the wizard must run against a machine we know nothing about. If it
// needed a complete profile in order to discover the profile, it would be
// useless on exactly the printers it exists for.
func TestBuildDiagnosticSheet_WorksWithTheGenericProfile(t *testing.T) {
	sheet := buildDiagnosticSheet(printer.DefaultProfile(), "Bếp 1", time.Now())

	if len(sheet) == 0 {
		t.Fatal("the diagnostic sheet must print even with no profile")
	}

	// The kanji / kana samples exist to FAIL VISIBLY on a machine with no ROM —
	// that failure IS the answer to the charset question. The encoder emits
	// Shift_JIS, so compare against the encoded form rather than the UTF-8
	// source (a naive string match here would pass for the wrong reason).
	for _, marker := range []string{"唐揚げ", "カタカナ"} {
		encoded := escpos.New().Text(marker).Bytes()
		if !bytesContain(sheet, encoded[len(escpos.New().Bytes()):]) {
			t.Errorf("sheet is missing the %q sample — without it nobody can answer the charset question", marker)
		}
	}

	text := string(sheet)
	// The column ruler: 58/80mm is an assumption that is wrong often enough to
	// silently wrap a total onto its own line.
	for _, ruler := range []string{"32:", "42:", "48:"} {
		if !strings.Contains(text, ruler) {
			t.Errorf("sheet is missing the %s column ruler", ruler)
		}
	}
}

// P-37 — a machine that cannot kick must not be sent a blind pulse.
func TestBuildDiagnosticSheet_OnlyKicksWhenTheProfileSaysItCan(t *testing.T) {
	kickCommand := []byte{0x1B, 0x70}

	generic := buildDiagnosticSheet(printer.DefaultProfile(), "P1", time.Now())
	if bytesContain(generic, kickCommand) {
		t.Error("the generic profile must not emit a drawer pulse — a wrong pin can jam a till")
	}

	capable := printer.ParseProfile(`{"finishing":{"drawer_kick":{"supported":true,"pin":2,"on_ms":120,"off_ms":240}}}`)
	sheet := buildDiagnosticSheet(capable, "P2", time.Now())
	if !bytesContain(sheet, kickCommand) {
		t.Error("a drawer-capable machine must be tested — that is the question block D asks")
	}
}

// P-36 — the sheet finishes the way the machine really will in service, so the
// cut question is asking about the right command.
func TestBuildDiagnosticSheet_HonoursCutModeNone(t *testing.T) {
	tearBar := printer.ParseProfile(`{"finishing":{"cut":{"mode":"none"}}}`)
	sheet := buildDiagnosticSheet(tearBar, "P3", time.Now())

	for _, cut := range [][]byte{{0x1D, 0x56}, {0x1B, 0x64}} {
		if bytesContain(sheet, cut) {
			t.Errorf("cut mode `none` emitted %X on the diagnostic sheet", cut)
		}
	}
}

func TestDiagnosticQuestions_CoverEveryProfileFieldTheSheetProbes(t *testing.T) {
	want := map[string]bool{
		"kanji_ok": false, "vietnamese_ok": false, "columns": false,
		"cut_ok": false, "cut_clipped_last_line": false, "drawer_opened": false,
	}

	for _, q := range diagnosticQuestions() {
		key, _ := q["key"].(string)
		if _, ok := want[key]; ok {
			want[key] = true
		}
	}

	for key, present := range want {
		if !present {
			t.Errorf("question %q is missing — the sheet prints a block nobody can answer", key)
		}
	}
}

// ── Answers → profile ──────────────────────────────────────────────────────

func TestApplyDiagnosticAnswers_NoKanjiRomSwitchesToAutoRaster(t *testing.T) {
	got := ApplyDiagnosticAnswers(printer.DefaultProfile(), diagnosticAnswers{
		KanjiOK: boolPtr(false),
	})

	if got.Charset.Kanji {
		t.Error("the operator said the kanji block was garbage")
	}
	if got.TextMode != printer.TextModeAuto {
		t.Errorf("text_mode = %q, want auto — raster only the blocks that need it (P-30)", got.TextMode)
	}
	if mode := got.TextModeFor("唐揚げ"); mode != printer.TextModeRaster {
		t.Errorf("kanji must now rasterise, got %q", mode)
	}
	if mode := got.TextModeFor("TOTAL 1,980"); mode != printer.TextModeNative {
		t.Errorf("money must stay native or a rush jams, got %q", mode)
	}
}

func TestApplyDiagnosticAnswers_KanjiOkGoesNative(t *testing.T) {
	got := ApplyDiagnosticAnswers(printer.DefaultProfile(), diagnosticAnswers{
		KanjiOK: boolPtr(true),
	})

	if !got.Charset.Kanji || got.TextMode != printer.TextModeNative {
		t.Errorf("a machine with the ROM should print natively: kanji=%v mode=%q", got.Charset.Kanji, got.TextMode)
	}
}

// Vietnamese failing is the same problem — outside the codepage.
func TestApplyDiagnosticAnswers_VietnameseFailureAlsoForcesTheRasterRoute(t *testing.T) {
	base := printer.ParseProfile(`{"charset":{"kanji":true},"text_mode":"native"}`)

	got := ApplyDiagnosticAnswers(base, diagnosticAnswers{VietnameseOK: boolPtr(false)})

	if mode := got.TextModeFor("Phở đặc biệt"); mode != printer.TextModeRaster {
		t.Errorf("Vietnamese must rasterise after the operator saw it fail, got %q", mode)
	}
}

func TestApplyDiagnosticAnswers_ColumnsFromTheRuler(t *testing.T) {
	got := ApplyDiagnosticAnswers(printer.DefaultProfile(), diagnosticAnswers{Columns: intPtr(42)})

	if got.ColumnsFor(80) != 42 {
		t.Errorf("columns(80mm) = %d, want the observed 42", got.ColumnsFor(80))
	}
}

// P-36 — "it did not cut" must stop the command, not keep sending it.
func TestApplyDiagnosticAnswers_NoCutDisablesTheCommand(t *testing.T) {
	got := ApplyDiagnosticAnswers(printer.DefaultProfile(), diagnosticAnswers{CutOK: boolPtr(false)})

	if got.CutsPaper() {
		t.Error("a machine that did not cut must stop being sent a cut command")
	}
}

func TestApplyDiagnosticAnswers_ClippedLastLineFeedsMore(t *testing.T) {
	base := printer.DefaultProfile()
	got := ApplyDiagnosticAnswers(base, diagnosticAnswers{CutClippedLastLine: boolPtr(true)})

	if got.Finishing.Cut.FeedBeforeCut != base.Finishing.Cut.FeedBeforeCut+2 {
		t.Errorf("feed_before_cut = %d, want %d — head-to-blade distance is a physical quirk",
			got.Finishing.Cut.FeedBeforeCut, base.Finishing.Cut.FeedBeforeCut+2)
	}
}

func TestApplyDiagnosticAnswers_DrawerObservation(t *testing.T) {
	opened := ApplyDiagnosticAnswers(printer.DefaultProfile(), diagnosticAnswers{DrawerOpened: boolPtr(true)})
	if !opened.CanKickDrawer() {
		t.Error("the operator watched the drawer open")
	}

	stuck := ApplyDiagnosticAnswers(printer.Preset("star_mcprint"), diagnosticAnswers{DrawerOpened: boolPtr(false)})
	if stuck.CanKickDrawer() {
		t.Error("the drawer did not open — the UI must now hide the button rather than swallow the press (P-37)")
	}
}

// P-40 [the important one] — "not answered" and "answered no" are different.
// A wizard that conflated them would wipe a known-good setting every time
// someone skipped a question.
func TestApplyDiagnosticAnswers_UnansweredQuestionsChangeNothing(t *testing.T) {
	base := printer.Preset("star_mcprint")

	got := ApplyDiagnosticAnswers(base, diagnosticAnswers{})

	if got.Finishing.Cut.Mode != base.Finishing.Cut.Mode {
		t.Errorf("cut mode changed on an empty answer set: %q → %q", base.Finishing.Cut.Mode, got.Finishing.Cut.Mode)
	}
	if got.Charset.Kanji != base.Charset.Kanji {
		t.Error("charset changed on an empty answer set")
	}
	if got.CanKickDrawer() != base.CanKickDrawer() {
		t.Error("drawer support changed on an empty answer set")
	}
	if got.ColumnsFor(80) != base.ColumnsFor(80) {
		t.Error("columns changed on an empty answer set")
	}
}

func TestApplyDiagnosticAnswers_PartialRunKeepsWhatWasAnswered(t *testing.T) {
	// Someone answered the charset question and walked away.
	got := ApplyDiagnosticAnswers(printer.Preset("star_mcprint"), diagnosticAnswers{
		KanjiOK: boolPtr(false),
	})

	if got.Charset.Kanji {
		t.Error("the one answer given must survive")
	}
	if got.Finishing.Cut.Mode != printer.CutEscD {
		t.Error("the unanswered cut setting must keep the preset's value")
	}
	if !got.ReconnectBetweenJobs() {
		t.Error("an unrelated known quirk must not be dropped by a partial run")
	}
}

func bytesContain(haystack, needle []byte) bool {
	return strings.Contains(string(haystack), string(needle))
}
