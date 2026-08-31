package service

import (
	"bytes"
	"sort"
	"strconv"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer"
)

// #1969 closed the width bug and `print_render_profile_width_test.go` pins the
// RULE it turned on: no roll named, no width claimed; a named roll still gets
// the machine's capability. This file pins the CONSEQUENCE the shop actually
// cares about, which those cannot see.
//
// A width test says "the ladder returns 42". It cannot say the slip that comes
// out of the printer is the slip that used to come out — the width feeds a
// renderer, and a renderer is a lot of code between a number and a sheet of
// paper. The TR-40 golden gate does compare renderer against legacy formatter,
// byte for byte, over the whole kind × locale × paper matrix — but it builds
// `PrintRenderProfile{Columns: tc.Paper}` BY HAND. Production never builds one
// that way; all 13 print call sites go through `PrintRenderProfileFor`.
//
// So: the same matrix, driven through the production adapter. It is the only
// gate that would have failed on the day every slip went six columns wide, and
// it fails on any future change that alters what the printer receives, not just
// on ones that alter a number.
//
// Deliberately NOT re-asserting the width rule here — that is
// print_render_profile_width_test.go's job, and two copies of a rule drift.

// productionProfile is exactly what every print call site builds: the profile of
// a printer nobody has configured, with no roll stated.
func productionProfile() PrintRenderProfile {
	return PrintRenderProfileFor(printer.ParseProfile(""), "")
}

// Every kind, both papers, all three locales — the bug hit every document type
// at once, so the guard has to as well.
func TestProductionProfile_ByteIdenticalToLegacy(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	kinds := SystemPrintTemplateKinds()
	sort.Strings(kinds)

	for _, kind := range kinds {
		for _, locale := range []string{"ja", "en", "vi"} {
			for _, paper := range []int{42, 32} {
				t.Run(kind+"/"+locale+"/"+strconv.Itoa(paper), func(t *testing.T) {
					cfg := goldenConfigFor(kind, locale, paper)

					data := goldenRenderData(kind, cfg)
					if data == nil {
						t.Skipf("no render data wired for %q", kind)
					}

					def, err := SystemPrintTemplate(kind)
					if err != nil {
						t.Fatalf("system default for %q: %v", kind, err)
					}

					// The profile production actually builds.
					got, err := RenderPrintTemplate(def, data, productionProfile(), locale)
					if err != nil {
						t.Fatalf("render %q: %v", kind, err)
					}

					want := renderLegacy(t, kind, cfg)
					if !bytes.Equal(got.Bytes(), want) {
						t.Fatalf("%s/%s/%d: production profile không cho ra byte của formatter cũ.\n%s",
							kind, locale, paper, diffBytes(got.Bytes(), want))
					}
				})
			}
		}
	}
}
