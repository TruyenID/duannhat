package printer

import (
	"encoding/hex"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// #1965 — a machine nobody has described must still cut.
//
// #1950 moved cutting from a blind `FullCut()` to a profile-aware `Finish()`,
// and asserted a safety property: "an unconfigured shop behaves exactly as it
// did yesterday". That property was false in production and the test could not
// see it, because the test built `PrintRenderProfile{Columns: 48}` BY HAND — a
// shape no print path ever produces. Every real path goes through
// `PrintRenderProfileFor`, which fills `Finishing` unconditionally.
//
// The chain on a real machine:
//
//	printers.model_profile = NULL
//	  → ParseProfile("")  → DefaultProfile()  (Preset "escpos_generic")
//	  → Cut.Mode = gs_v_full
//	  → Finish() emits GS V 0
//
// Star mC-Print in StarPRNT emulation IGNORES `GS V`. So every shop that had
// not run the setup wizard stopped cutting paper — no error, no log, the slip
// simply never separates.
//
// These tests therefore start from `ParseProfile`, the way the app does, rather
// than from a hand-built struct. That is the whole lesson of the regression.

func finishHex(t *testing.T, p Profile) string {
	t.Helper()

	e := escpos.New()
	e.Finish(p.FinishingSpec())

	return hex.EncodeToString(e.Bytes())
}

func TestUndeclaredProfile_CutsWithEscD(t *testing.T) {
	// `model_profile` NULL in the database arrives here as the empty string.
	profile := ParseProfile("")

	got := finishHex(t, profile)

	// ESC d 3 — what every print path emitted before #1950, and what the
	// installed fleet is known to cut on.
	if !strings.HasSuffix(got, "1b6433") {
		t.Fatalf("an undescribed machine must cut with ESC d 3; got %s", got)
	}
	// And it must NOT reach for the dialect Star silently drops.
	if strings.Contains(got, "1d5600") {
		t.Fatalf("undescribed machine emitted GS V 0, which Star ignores: %s", got)
	}
}

func TestDeclaredGenericProfile_KeepsItsOwnDialect(t *testing.T) {
	// The converse, and the reason this is a `Declared` flag rather than a
	// change to `escpos_generic`'s cut mode: a shop that chose `escpos_generic`
	// ON PURPOSE has described its machine, and must keep GS V. Collapsing the
	// two would fix the Star shops by breaking the ones that answered honestly.
	profile := ParseProfile(`{"preset":"escpos_generic"}`)

	got := finishHex(t, profile)
	if !strings.Contains(got, "1d5600") {
		t.Fatalf("a DECLARED escpos_generic must still cut with GS V 0; got %s", got)
	}
}

func TestDeclaredPresetsKeepTheirOwnCutDialect(t *testing.T) {
	// The fix must not leak into machines that WERE described. My first attempt
	// carried a `Declared bool` on the profile and flipped the dialect whenever
	// it was false — which also caught `DefaultProfile()` used as a MUTATION
	// BASE, a legitimate way to express a configured machine. It broke
	// `TestFinishing_CutNoneNeverSendsACut`: a tear-bar machine started
	// receiving a cut command. The flag was in the wrong place; the ambiguity
	// lives at the point where NULL becomes a profile, so that is where the
	// answer belongs.
	for raw, want := range map[string]string{
		`{"preset":"star_mcprint"}`:   "1b6433", // ESC d — declared, unchanged
		`{"preset":"epson_tm_i"}`:     "1d5601", // GS V 1 — partial cut
		`{"preset":"escpos_generic"}`: "1d5600", // GS V 0 — chosen on purpose
	} {
		got := finishHex(t, ParseProfile(raw))
		if !strings.Contains(got, want) {
			t.Fatalf("ParseProfile(%s): want cut %s, got %s", raw, want, got)
		}
	}
}

func TestDefaultProfileStaysUsableAsAMutationBase(t *testing.T) {
	// `DefaultProfile()` is the base the presets build on and the base tests
	// mutate. It must keep meaning "generic ESC/POS baseline", NOT "nobody
	// described this" — conflating the two is what broke the tear-bar test.
	p := DefaultProfile()
	p.Finishing.Cut.Mode = CutNone

	got := finishHex(t, p)
	if strings.Contains(got, "1b6433") || strings.Contains(got, "1d56") {
		t.Fatalf("an explicit cut=none must receive NO cut command; got %s", got)
	}
}

func TestCorruptProfileIsTreatedAsUndeclared(t *testing.T) {
	// P-29 — a corrupt value must still yield a working profile. It must ALSO
	// count as undescribed: garbage in the column is not a statement about the
	// machine, so the safe cut applies.
	for _, raw := range []string{"not json", "{", `["array"]`} {
		p := ParseProfile(raw)
		if !strings.HasSuffix(finishHex(t, p), "1b6433") {
			t.Fatalf("corrupt profile %q must still cut with ESC d 3", raw)
		}
	}
}

func TestUndeclaredProfileStillHonoursTheRestOfTheProfile(t *testing.T) {
	// The override is narrow ON PURPOSE: only the cut dialect. Widening it to
	// "ignore the whole profile when undeclared" would throw away the generic
	// defaults for columns, charset and drawer that P-29 depends on.
	p := ParseProfile("")

	spec := p.FinishingSpec()
	if spec.DrawerKickSupported != p.Finishing.DrawerKick.Supported {
		t.Fatal("the undeclared branch must not touch drawer capability")
	}
	if spec.AutoCutPerJob != p.Finishing.Cut.AutoCutPerJob {
		t.Fatal("the undeclared branch must not touch auto-cut")
	}
}
