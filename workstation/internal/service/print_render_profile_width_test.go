package service

import (
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer"
)

// #1969 — a profile may not assert a paper width nobody asked about.
//
// `PrintRenderProfileFor(p, "")` defaulted the roll to "80mm" and read
// `p.Columns["80mm"]` = 48. `resolvePrintWidth` ranks the profile ABOVE the job
// config, so that guess beat the `PaperWidth: 42` every call site sets, and
// every slip rendered six columns too wide — line ends wrapping onto the next
// row, on receipts, kitchen tickets, 精算 reports and invoices alike.
//
// ## Why the existing gates missed it
//
// This is the third defect in a row (#1965, #1966, this one) with the same root
// cause: production builds its render profile through `PrintRenderProfileFor`,
// and every gate builds one BY HAND.
//
//   - TR-40 golden:        `PrintRenderProfile{Columns: tc.Paper}`
//   - #1950 safety test:   `PrintRenderProfile{Columns: 48}`
//   - slip parity harness: the same shape
//
// Each is green and each is blind in exactly the same place. So these tests
// start from a `printer.Profile` and go through the real adapter.

func TestProfileFor_NoPaperNamedMakesNoWidthClaim(t *testing.T) {
	// The production shape: `model_profile` NULL, call site passes "".
	got := PrintRenderProfileFor(printer.ParseProfile(""), "")

	if got.Columns != 0 {
		t.Fatalf("an unnamed roll must claim no width; got %d columns", got.Columns)
	}
}

func TestProfileFor_NamedPaperStillGetsTheMachineCapability(t *testing.T) {
	// The converse, and the reason this is not simply "delete the Columns
	// lookup": when the caller NAMES a roll, pairing it with the machine's
	// capability is exactly right — the caller says which paper, the machine
	// says how many characters fit on it.
	p := printer.ParseProfile("")

	for paper, want := range map[string]int{"58mm": 32, "80mm": 48} {
		if got := PrintRenderProfileFor(p, paper); got.Columns != want {
			t.Fatalf("paper %s: want %d columns, got %d", paper, want, got.Columns)
		}
	}
}

func TestResolvedWidth_JobConfigWinsWhenNoPaperIsNamed(t *testing.T) {
	// The end-to-end property the bug broke. 42 is what every call site sets and
	// is not any preset's column count — evidence on its own that the capability
	// table cannot be the source of the width for this fleet.
	profile := PrintRenderProfileFor(printer.ParseProfile(""), "")
	cfg := PrintJobConfig{PaperWidth: 42}

	if got := resolvePrintWidth(nil, cfg, profile, 0); got != 42 {
		t.Fatalf("the job config must decide the width when no roll is named; got %d", got)
	}
}

func TestResolvedWidth_NamedPaperStillOutranksTheJobConfig(t *testing.T) {
	// Unchanged behaviour, pinned so the fix is not later widened into "the job
	// config always wins". A caller that names the roll IS telling us the paper,
	// and then the machine's real capability should outrank a stale config
	// number — that ordering was the original intent and it stays.
	profile := PrintRenderProfileFor(printer.ParseProfile(""), "58mm")
	cfg := PrintJobConfig{PaperWidth: 42}

	if got := resolvePrintWidth(nil, cfg, profile, 0); got != 32 {
		t.Fatalf("a named 58mm roll must win over a 42-column config; got %d", got)
	}
}
