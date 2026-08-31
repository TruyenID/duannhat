package handler

import (
	"os"
	"regexp"
	"strings"
	"testing"
)

// #1969 — every print path must build its render profile through the ADAPTER.
//
// Three defects in a row shared one root cause, and it was never the code under
// test — it was the seam between what production builds and what the gates
// build:
//
//	#1965  unconfigured machines stopped cutting     (GS V on a Star)
//	#1966  the legacy fallback cut in the wrong dialect
//	#1969  every slip rendered six columns too wide
//
// In all three, production went through `service.PrintRenderProfileFor(...)`
// while every gate constructed a `PrintRenderProfile{...}` literal by hand —
// TR-40's golden used `{Columns: tc.Paper}`, #1950's own safety test used
// `{Columns: 48}`. Each gate was green, and each was blind in exactly the same
// place, because it proved something about a shape the app never builds.
//
// A convention nobody checks is the convention that keeps being broken. Tests
// may still build a literal — that is how you exercise an edge — but a HANDLER
// may not, because a handler IS production.
var renderProfileLiteral = regexp.MustCompile(`service\.PrintRenderProfile\{`)

func TestEveryHandlerBuildsItsRenderProfileThroughTheAdapter(t *testing.T) {
	entries, err := os.ReadDir(".")
	if err != nil {
		t.Fatalf("read handler dir: %v", err)
	}

	scanned := 0
	for _, e := range entries {
		name := e.Name()
		if e.IsDir() || !strings.HasSuffix(name, ".go") || strings.HasSuffix(name, "_test.go") {
			continue
		}

		body, err := os.ReadFile(name)
		if err != nil {
			t.Fatalf("read %s: %v", name, err)
		}
		scanned++

		if renderProfileLiteral.Match(body) {
			t.Errorf("%s builds a service.PrintRenderProfile literal — use "+
				"service.PrintRenderProfileFor so the handler and the gates share one "+
				"construction path (#1965 · #1966 · #1969 all hid in that gap)", name)
		}
	}

	// A scan that finds nothing reports "no offenders" and looks exactly like
	// success — the same failure mode as the gates this test exists to make
	// honest.
	if scanned < 20 {
		t.Fatalf("only scanned %d handler files; the scan has drifted", scanned)
	}
}
