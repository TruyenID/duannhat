package handler

import (
	"bytes"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// #1966 — the built-in fallback must cut in the machine's dialect too.
//
// #1950 moved the TEMPLATE path onto the profile and deliberately left the ten
// `Format*` functions alone: they are the independent baseline the TR-40 gate
// compares against, and a formatter that called the renderer would make that
// gate circular. Correct — but it left the fallback emitting a bare `ESC d 3`.
//
// On a real Epson, `ESC d n` is "print and feed n lines" and is NOT a cut. So a
// shop that ran the setup wizard, declared `epson_tm_i` (which asks for
// `gs_v_partial` so the slip stays hanging rather than dropping on the floor),
// and then hit a render error got a slip that silently stopped being cut.
//
// Same shape as #1965, one step further down the fallback chain: the machine
// accepts the bytes, reports nothing, and the paper does not separate.

func profileFor(t *testing.T, raw string) service.PrintRenderProfile {
	t.Helper()

	return service.PrintRenderProfileFor(printer.ParseProfile(raw), "")
}

func TestFinishBuiltinSlip_DeclaredEpsonGetsPartialCut(t *testing.T) {
	// The case the issue is about. `ESC d 3` in, `GS V 1` out.
	builtinSlip := append([]byte("SLIP BODY\n"), escpos.Cut...)

	got := finishBuiltinSlip(builtinSlip, profileFor(t, `{"preset":"epson_tm_i"}`))

	if bytes.HasSuffix(got, escpos.Cut) {
		t.Fatalf("a declared Epson still received ESC d 3: % x", got)
	}
	if !bytes.Contains(got, []byte{0x1D, 0x56, 0x01}) {
		t.Fatalf("a declared Epson must receive GS V 1 (partial cut); got % x", got)
	}
	if !bytes.HasPrefix(got, []byte("SLIP BODY\n")) {
		t.Fatal("the slip body must be untouched")
	}
}

func TestFinishBuiltinSlip_IsAByteForByteNoOpOnEscD(t *testing.T) {
	// The majority case, and the reason this change is safe to land without
	// regenerating anything: after #1965 every UNDESCRIBED machine cuts with
	// `ESC d`, and `Finish` on an esc_d profile writes exactly `Cut`. Trimming
	// the cut and re-finishing must therefore return identical bytes.
	//
	// If this ever stops being true, the change stops being invisible to the
	// fleet — which is precisely when someone needs to be told.
	builtinSlip := append([]byte("SLIP BODY\n"), escpos.Cut...)

	for name, raw := range map[string]string{
		"undescribed": "",
		"corrupt":     "{not json",
		"empty json":  "{}",
		"star":        `{"preset":"star_mcprint"}`,
	} {
		got := finishBuiltinSlip(builtinSlip, profileFor(t, raw))
		if !bytes.Equal(got, builtinSlip) {
			t.Fatalf("%s: expected byte-identical output, got % x", name, got)
		}
	}
}

func TestFinishBuiltinSlip_TearBarMachineLosesTheCutButKeepsTheFeed(t *testing.T) {
	// P-36 — a machine that declares `cut: none` must receive NO cut command
	// (on some firmware the unrecognised bytes print as garbage), but must
	// still be FED, or the last lines sit inside the mechanism and the operator
	// tears through the total.
	// Built through `ParseProfile`, the way a real tear-bar shop arrives: the
	// operator runs the wizard, answers "it did not cut", and that is STORED.
	//
	// My first version used `DefaultProfile()` + a field mutation. After PR #207
	// merged a `Configured` flag alongside this work, that shape means
	// "undescribed, but declaring cut=none" — a state nothing produces, and the
	// test went red on a tree whose real behaviour is correct.
	//
	// Which is exactly the criticism I had just written about #1950's safety
	// test: it asserted a shape no print path builds. Measured before believing
	// it: a stored `{"cut":{"mode":"none"}}` yields `1b40 0a0a0a0a` — init plus
	// four feeds, no cut command anywhere.
	spec := service.PrintRenderProfileFor(
		printer.ParseProfile(`{"preset":"escpos_generic","finishing":{"cut":{"mode":"none","feed_before_cut":4}}}`),
		"",
	)

	got := finishBuiltinSlip(append([]byte("BODY\n"), escpos.Cut...), spec)

	if bytes.Contains(got, escpos.Cut) || bytes.Contains(got, []byte{0x1D, 0x56}) {
		t.Fatalf("tear-bar machine received a cut command: % x", got)
	}
	if !bytes.Contains(got, []byte{0x0A}) {
		t.Fatal("tear-bar machine must still be fed")
	}
}

func TestFinishBuiltinSlip_LeavesSlipsThatDoNotEndInACutAlone(t *testing.T) {
	// A guard, not an optimisation. If a formatter ever stops ending with a
	// cut, appending one here would eject a blank slip on every print. Doing
	// nothing is the safe direction: an uncut slip can be torn by hand, a
	// double cut cannot be un-ejected.
	body := []byte("NO CUT HERE\n")

	got := finishBuiltinSlip(body, profileFor(t, `{"preset":"epson_tm_i"}`))
	if !bytes.Equal(got, body) {
		t.Fatalf("bytes without a trailing cut must be returned untouched; got % x", got)
	}
}

func TestFinishBuiltinSlip_NoProfileAndEmptyInputAreBothSafe(t *testing.T) {
	// `Finishing == nil` is the shape #1950's own safety test used, and the one
	// no production path produces — but an empty slip reaching here means the
	// formatter itself failed, and this must not turn that into a stray cut.
	if got := finishBuiltinSlip(nil, profileFor(t, "")); got != nil {
		t.Fatalf("nil slip must stay nil; got % x", got)
	}

	body := append([]byte("BODY\n"), escpos.Cut...)
	if got := finishBuiltinSlip(body, service.PrintRenderProfile{Columns: 48}); !bytes.Equal(got, body) {
		t.Fatalf("a profile with no finishing must leave the slip alone; got % x", got)
	}
}

// The test that would have caught a seam that forgot to CALL finishBuiltinSlip.
//
// Everything above exercises the helper directly, which is exactly the mistake
// #1950 made: its safety test asserted a shape no print path produces, so the
// branch it protected was never the branch production took. Reverting the seam
// wiring left all five of those tests green — measured, not supposed.
//
// This one drives `renderMoneySlip` itself.
func TestRenderMoneySlip_FallbackCarriesTheProfileDialect(t *testing.T) {
	// `newSeamServer` is the harness the existing seam tests use; with the
	// renderer flag turned OFF it takes the first fallback branch, which is the
	// one a shop hits when the flag is off or a render fails.
	s := newSeamServer(t)
	if _, err := s.db.Exec(
		`INSERT INTO settings (key, value) VALUES (?, ?)`,
		printTemplateRendererSetting, "false",
	); err != nil {
		t.Fatalf("turn the renderer off: %v", err)
	}

	builtinFormatter := func() []byte { return append([]byte("BODY\n"), escpos.Cut...) }
	profile := service.PrintRenderProfileFor(printer.ParseProfile(`{"preset":"epson_tm_i"}`), "")

	slip, version := s.renderMoneySlip(nil, profile, "ja", builtinFormatter)

	if version != "" {
		t.Fatalf("the built-in path has no template version; got %q", version)
	}
	if bytes.HasSuffix(slip, escpos.Cut) {
		t.Fatalf("the fallback still emitted ESC d 3 on a declared Epson: % x", slip)
	}
	if !bytes.Contains(slip, []byte{0x1D, 0x56, 0x01}) {
		t.Fatalf("the fallback must carry the profile's GS V 1; got % x", slip)
	}
}
