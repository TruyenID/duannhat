package printer

import (
	"encoding/json"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// plan-052 T1.4b / T1.4c (DESIGN §3b, #1166).
//
// The profile is what lets one codebase drive an Epson TM-i, a Star mC-Print
// and a ¥6,000 marketplace ESC/POS box without a single per-model branch. These
// tests lock the answers it gives, because every one of them turns into bytes
// on a machine that a shop is depending on.

// P-29 — the fallback exists so a machine nobody has described still prints.
func TestParseProfile_FallsBackToGeneric(t *testing.T) {
	for name, raw := range map[string]string{
		"empty":   "",
		"null":    "null",
		"garbage": "{not json",
		"blank":   "{}",
	} {
		t.Run(name, func(t *testing.T) {
			p := ParseProfile(raw)
			// #1965 — the CUT is the one field an undescribed machine does not
			// inherit from the generic profile. This assertion used to want
			// `gs_v_full`, and that expectation was the bug: `GS V` is silently
			// ignored by Star mC-Print in StarPRNT emulation, so every shop that
			// had not run the setup wizard stopped cutting paper after #1950.
			//
			// `blank` (`{}`) is the interesting row. It parses, so SOMETHING was
			// stored — but it says nothing about the machine, which is the same
			// state as NULL. Treating it as a declaration would leave exactly
			// those shops silently uncut again.
			if p.Finishing.Cut.Mode != CutEscD {
				t.Errorf("cut mode = %q, want %q", p.Finishing.Cut.Mode, CutEscD)
			}
			if p.CanKickDrawer() {
				t.Error("generic profile must NOT kick the drawer — a wrong pin can jam a till")
			}
			if got := p.ColumnsFor(80); got != 48 {
				t.Errorf("columns(80mm) = %d, want 48", got)
			}
			if got := p.ColumnsFor(58); got != 32 {
				t.Errorf("columns(58mm) = %d, want 32", got)
			}
			if p.PrintConfidence() != "sent_only" {
				t.Error("a machine we know nothing about cannot confirm anything")
			}
		})
	}
}

// P-40 — a wizard run that stopped after one question keeps that one answer.
func TestParseProfile_PartialOverridesKeepDefaults(t *testing.T) {
	p := ParseProfile(`{"finishing":{"cut":{"mode":"none"}}}`)

	if p.CutsPaper() {
		t.Error("declared cut mode `none` must disable cutting")
	}
	if p.Finishing.Cut.FeedBeforeCut != 4 {
		t.Errorf("unanswered feed_before_cut = %d, want the generic 4", p.Finishing.Cut.FeedBeforeCut)
	}
	if p.ColumnsFor(80) != 48 {
		t.Error("unanswered columns must stay at the generic default")
	}
}

func TestParseProfile_InheritsPresetThenOverrides(t *testing.T) {
	p := ParseProfile(`{"preset":"star_mcprint","finishing":{"cut":{"feed_before_cut":6}}}`)

	if p.Finishing.Cut.Mode != CutEscD {
		t.Errorf("cut mode = %q, want the preset's %q", p.Finishing.Cut.Mode, CutEscD)
	}
	if p.Finishing.Cut.FeedBeforeCut != 6 {
		t.Errorf("feed_before_cut = %d, want the override 6", p.Finishing.Cut.FeedBeforeCut)
	}
	if !p.Charset.Kanji {
		t.Error("preset kanji support must survive an unrelated override")
	}
}

// An unrecognised value must never reach a machine as a command.
func TestParseProfile_NormalisesUnknownEnums(t *testing.T) {
	p := ParseProfile(`{"text_mode":"telepathy","finishing":{"cut":{"mode":"laser"}},` +
		`"error_detect":{"level":"psychic"},"health":{"method":"vibes"}}`)

	if p.TextMode != TextModeAuto {
		t.Errorf("text_mode = %q, want auto", p.TextMode)
	}
	if p.Finishing.Cut.Mode != CutNone {
		t.Errorf("unknown cut mode must degrade to `none` (send NOTHING), got %q", p.Finishing.Cut.Mode)
	}
	if p.ErrorDetect.Level != ErrorDetectNone {
		t.Errorf("error level = %q, want none", p.ErrorDetect.Level)
	}
	if p.Health.Method != HealthTCPDial {
		t.Errorf("health method = %q, want tcp_dial", p.Health.Method)
	}
}

// P-30 — raster fallback picks the blocks that need it, and only those.
func TestTextModeFor(t *testing.T) {
	noKanji := ParseProfile(`{"charset":{"kanji":false},"text_mode":"auto"}`)
	withKanji := ParseProfile(`{"charset":{"kanji":true},"text_mode":"auto"}`)

	cases := []struct {
		name    string
		profile Profile
		block   string
		want    string
	}{
		{"kanji on a machine with no ROM", noKanji, "唐揚げ 2点", TextModeRaster},
		{"vietnamese diacritics, same problem", noKanji, "Phở đặc biệt", TextModeRaster},
		{"money stays native — rasterising a rush jams the queue", noKanji, "TOTAL 1,980", TextModeNative},
		{"ascii date stays native", noKanji, "2026-07-28 19:04", TextModeNative},
		{"machine with a ROM prints everything natively", withKanji, "唐揚げ", TextModeNative},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := tc.profile.TextModeFor(tc.block); got != tc.want {
				t.Errorf("TextModeFor(%q) = %q, want %q", tc.block, got, tc.want)
			}
		})
	}
}

func TestTextModeFor_ExplicitChoiceWinsOverHeuristic(t *testing.T) {
	forcedRaster := ParseProfile(`{"charset":{"kanji":true},"text_mode":"raster"}`)
	forcedNative := ParseProfile(`{"charset":{"kanji":false},"text_mode":"native"}`)

	if got := forcedRaster.TextModeFor("TOTAL 100"); got != TextModeRaster {
		t.Errorf("explicit raster ignored: got %q", got)
	}
	if got := forcedNative.TextModeFor("唐揚げ"); got != TextModeNative {
		t.Errorf("explicit native ignored: got %q", got)
	}
}

// P-33 [HARD] — the confidence a machine can honestly earn.
func TestPrintConfidenceFollowsErrorDetectLevel(t *testing.T) {
	cases := map[string]string{
		ErrorDetectNone:       "sent_only",
		ErrorDetectStatusBack: "confirmed",
		ErrorDetectProtocol:   "confirmed",
	}
	for level, want := range cases {
		p := ParseProfile(`{"error_detect":{"level":"` + level + `"}}`)
		if got := p.PrintConfidence(); got != want {
			t.Errorf("level %q → confidence %q, want %q", level, got, want)
		}
		if p.SupportsPreflightStatus() != (level != ErrorDetectNone) {
			t.Errorf("level %q: preflight support disagrees with the level", level)
		}
	}
}

// P-32 — the quirk that made the second job of a burst fail on Star hardware.
func TestReconnectBetweenJobsQuirk(t *testing.T) {
	if !Preset("star_mcprint").ReconnectBetweenJobs() {
		t.Error("star_mcprint must declare reconnect_between_jobs")
	}
	if DefaultProfile().ReconnectBetweenJobs() {
		t.Error("the generic profile must not assume a Star quirk")
	}
}

// P-38 — the health probe a machine can actually answer.
func TestHealthMethodPerPreset(t *testing.T) {
	cases := map[string]string{
		"escpos_generic": HealthTCPDial,
		"epson_tm_i":     HealthHTTPPing,
		"star_mcprint":   HealthDLEEOT,
	}
	for preset, want := range cases {
		if got := Preset(preset).Health.Method; got != want {
			t.Errorf("%s health = %q, want %q", preset, got, want)
		}
	}
}

func TestSupportsTransport(t *testing.T) {
	if Preset("escpos_generic").SupportsTransport("cloudprnt") {
		t.Error("a raw ESC/POS box cannot poll Cloud — it needs a workstation")
	}
	if !Preset("star_mcprint").SupportsTransport("cloudprnt") {
		t.Error("star_mcprint speaks CloudPRNT")
	}
	if !Preset("escpos_generic").SupportsTransport("ws_lan") {
		t.Error("every machine we drive today goes through the workstation")
	}
}

// A profile must survive a round trip through the column it is stored in.
func TestProfileJSONRoundTrip(t *testing.T) {
	original := Preset("star_mcprint")
	raw, err := original.JSON()
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}

	back := ParseProfile(raw)
	if back.Finishing.Cut.Mode != original.Finishing.Cut.Mode ||
		back.Charset.Kanji != original.Charset.Kanji ||
		back.ErrorDetect.Level != original.ErrorDetect.Level ||
		!back.ReconnectBetweenJobs() {
		t.Errorf("round trip lost data:\n got %+v\nwant %+v", back, original)
	}

	var check map[string]any
	if err := json.Unmarshal([]byte(raw), &check); err != nil {
		t.Fatalf("stored profile must be valid JSON: %v", err)
	}
}

// ── Finishing → bytes ──────────────────────────────────────────────────────

// P-36 — a tear-bar machine must receive NO cut command.
func TestFinish_CutNoneSendsNoCutCommand(t *testing.T) {
	p := ParseProfile(`{"finishing":{"cut":{"mode":"none","feed_before_cut":3}}}`)

	e := escpos.New()
	e.Finish(p.FinishingSpec())
	out := e.Bytes()

	for _, forbidden := range [][]byte{{0x1D, 0x56}, {0x1B, 0x64}} {
		if containsBytes(out, forbidden) {
			t.Errorf("cut mode `none` emitted a cut command %X — cheap firmware prints that as garbage", forbidden)
		}
	}
	if len(out) == 0 {
		t.Error("cut mode `none` must still FEED, or the last lines stay inside the mechanism")
	}
}

func TestFinish_CutModeSelectsTheRightCommand(t *testing.T) {
	cases := []struct {
		mode string
		want []byte
	}{
		{CutGsVFull, []byte{0x1D, 0x56, 0x00}},
		{CutGsVPartial, []byte{0x1D, 0x56, 0x01}},
		{CutEscD, []byte{0x1B, 0x64, 0x33}},
	}

	for _, tc := range cases {
		t.Run(tc.mode, func(t *testing.T) {
			p := ParseProfile(`{"finishing":{"cut":{"mode":"` + tc.mode + `","feed_before_cut":2}}}`)
			e := escpos.New()
			e.Finish(p.FinishingSpec())
			if !containsBytes(e.Bytes(), tc.want) {
				t.Errorf("mode %q did not emit %X", tc.mode, tc.want)
			}
		})
	}
}

// A machine that cuts by itself must not be told to cut as well, or every job
// ejects a second blank slip.
func TestFinish_AutoCutPerJobSendsNothing(t *testing.T) {
	p := ParseProfile(`{"finishing":{"cut":{"mode":"gs_v_full","auto_cut_per_job":true}}}`)

	// escpos.New() writes the ESC @ init prefix, so "nothing added" is
	// measured against a fresh encoder rather than against zero.
	baseline := len(escpos.New().Bytes())

	e := escpos.New()
	e.Finish(p.FinishingSpec())

	if len(e.Bytes()) != baseline {
		t.Errorf("auto-cutting machine received %X — that is a second, blank cut", e.Bytes()[baseline:])
	}
}

// P-37 — the drawer.
func TestKickDrawer(t *testing.T) {
	baseline := len(escpos.New().Bytes())

	unsupported := DefaultProfile()
	e := escpos.New()
	if e.KickDrawer(unsupported.FinishingSpec()) {
		t.Error("a machine that cannot kick must report that it did not")
	}
	if len(e.Bytes()) != baseline {
		t.Error("no bytes may be emitted for an unsupported drawer")
	}

	capable := ParseProfile(`{"finishing":{"drawer_kick":{"supported":true,"pin":5,"on_ms":100,"off_ms":200}}}`)
	e2 := escpos.New()
	if !e2.KickDrawer(capable.FinishingSpec()) {
		t.Fatal("a capable machine must kick")
	}
	out := e2.Bytes()
	// ESC p m t1 t2 — m=1 for pin 5, timings in 2ms units.
	want := []byte{0x1B, 0x70, 0x01, 50, 100}
	if !containsBytes(out, want) {
		t.Errorf("drawer pulse = %X, want %X", out, want)
	}
}

func containsBytes(haystack, needle []byte) bool {
	if len(needle) == 0 || len(haystack) < len(needle) {
		return false
	}
	for i := 0; i+len(needle) <= len(haystack); i++ {
		match := true
		for j := range needle {
			if haystack[i+j] != needle[j] {
				match = false
				break
			}
		}
		if match {
			return true
		}
	}
	return false
}
