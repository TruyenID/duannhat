package service

import (
	"encoding/json"
	"flag"
	"os"
	"strconv"
	"testing"
)

// plan-053 T5.1d (#1375) — THE SHARED INPUT FIXTURE.
//
// Why this exists at all: slip-level parity (T5.2b) asks Go and PHP to emit the
// SAME BYTES for one kind. Same bytes is only meaningful from the same input,
// and until now the input lived in Go test code — `goldenOrder()`,
// `goldenConfig()` and the frozen `goldenClock`. PHP cannot read any of that,
// so a PHP emitter had nothing to be byte-identical *to*.
//
// This writes the render data — the SAME `*PrintRenderData` the Go renderer
// consumes — to `testdata/print_input_golden.json`, so the PHP side reads the
// identical IR rather than re-deriving it from an Order. Re-deriving is where
// parity would silently rot: two independent "build the render data" paths
// drift on defaults nobody looks at, and the byte diff then blames the emitter.
//
// Same shape as the primitives fixture (`print_primitives_golden.json`):
// Go writes, PHP reads, both gate on it.
//
// SCOPE: every kind in goldenMatrix() (#1171, 2026-08-06). It used to be the
// single-element list `{"kitchen"}` — "a kind belongs here only once its PHP
// emitter is gated on it" — and that rule, applied honestly, produced the exact
// inverse of what it wanted. Measured the day the emitters landed:
//
//	kind in this fixture : [kitchen]
//	kind PHP can render  : the other 13
//	intersection         : EMPTY
//
// So the harness both sides were told gates slip parity had **never gated a
// single cell**, and could not, because the one kind it carried was the one
// kind PHP has no emitter for. A hand-curated list is the wrong instrument:
// widening it is a separate act from landing an emitter, and nothing goes red
// when the two drift — which is how it inverted without anyone noticing.
//
// The gap moved to where it closes itself. Go exports everything; PHP owns a
// `NOT_YET` list, and `SlipByteParityTest` fails if a kind on that list turns
// out to BE registered. Landing an emitter therefore breaks the build until its
// parity is switched on — the fixture never needs editing again.
//
//	go test ./internal/service/ -run TestExportPrintInputGolden -update-print-input-golden
var updatePrintInputGolden = flag.Bool(
	"update-print-input-golden", false, "rewrite testdata/print_input_golden.json")

const printInputGoldenPath = "testdata/print_input_golden.json"

type printInputGoldenFile struct {
	// Clock is the frozen instant every case was built at. The legacy
	// formatters read the wall clock, so PHP must stamp the same instant or
	// every slip differs by its timestamp line alone.
	Clock string                      `json:"clock"`
	Cases map[string]*PrintRenderData `json:"cases"`
	// TaxSummaries carries what Go's renderer COMPUTES rather than what it is
	// given: `buildReceiptTaxSummary(Order, Items, step)` runs inside the bill
	// prologue, so it is nowhere in PrintRenderData.
	//
	// PHP cannot reproduce it and must not try — the standing ruling is that the
	// device never asserts an amount and the print layer never recomputes money
	// (#1092, #1937). Its renderer therefore takes the snapshot as an argument.
	// Exporting it here is what makes the two comparable: parity then means "the
	// same tax facts emit the same bytes", which is a statement about the
	// EMITTERS. Letting PHP derive the summary instead would test a tax engine
	// PHP is forbidden to have, and — measured on this repo already — a summary
	// rebuilt with zero amounts prints fabricated tax lines with nothing red.
	TaxSummaries map[string]receiptTaxSummary `json:"tax_summaries"`
}

func TestExportPrintInputGolden(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	produced := map[string]*PrintRenderData{}
	taxes := map[string]receiptTaxSummary{}
	for _, tc := range goldenMatrix() {
		// `goldenConfigFor`, NOT `goldenConfig` — this line was the second half
		// of the same defect (#1171). The OUTPUT hashes in print_golden.json are
		// produced with `goldenConfigFor`, which sets `OperatingCountry` per kind
		// (#1493); exporting the inputs with the plain builder hands PHP a config
		// that never produced those bytes, so byte parity would be unreachable and
		// the failure would read as "the PHP emitter is wrong". It stayed invisible
		// while the fixture held only `kitchen`, whose plan does not branch on
		// country.
		cfg := goldenConfigFor(tc.Kind, tc.Locale, tc.Paper)
		d := goldenRenderDataFor(tc, cfg)

		// Resolve `Now` before serialising. Go's emitters read the (frozen) wall
		// clock when this field is the zero time, so the fixture faithfully
		// recorded `0001-01-01T00:00:00Z` — an instant Go never prints and PHP
		// prints literally, as `0001/01/01 00:00`.
		//
		// PHP is the side that is right here, and deliberately so: its renderer
		// has no branchId, so it must be HANDED the business instant rather than
		// guess one (#1091, `PrintRenderData` docblock). A print layer that reads
		// its own clock is exactly how a Tokyo slip gets stamped in app time.
		// So the fixture carries the resolved instant instead of the zero value.
		//
		// #2065 — resolve through `d.now()` rather than assigning goldenClock.
		// The hard-coded assignment was a SECOND implementation of the ladder,
		// and once Go started dating an order-backed slip from the sale it became
		// a wrong one: the fixture would have handed PHP the print clock while Go
		// printed the sale time, and SlipByteParityTest would have blamed the PHP
		// emitter for a defect in the fixture. One resolver, one answer.
		d.Now = d.now()

		produced[goldenKey(tc)] = d
		// #2170 — through the SAME chokepoint the renderers use: a whole-order
		// kind exports the ledger-backed summary, kitchen/delta export their
		// batch recompute. Exporting one uniform source here would hand PHP a
		// summary the Go bytes in print_golden.json were not drawn from, and
		// SlipByteParityTest would blame the PHP emitter for it.
		taxes[goldenKey(tc)] = receiptTaxSummaryForKind(d.Kind, d.DeltaBill, d.Order, d.Items, cfg.step())
	}

	if len(produced) == 0 {
		t.Fatal("no cases produced — goldenMatrix() is empty")
	}

	body, err := json.MarshalIndent(printInputGoldenFile{
		Clock:        goldenClock.Format("2006-01-02T15:04:05Z07:00"),
		Cases:        produced,
		TaxSummaries: taxes,
	}, "", "  ")
	if err != nil {
		t.Fatalf("marshal input golden: %v", err)
	}
	body = append(body, '\n')

	if *updatePrintInputGolden {
		if err := os.WriteFile(printInputGoldenPath, body, 0o644); err != nil {
			t.Fatalf("write %s: %v", printInputGoldenPath, err)
		}

		return
	}

	// Not updating: the committed file must already equal what we just built.
	// This is the half that keeps the fixture honest — a change to goldenOrder
	// or goldenConfig that nobody re-exported would leave PHP gating on stale
	// input while Go moved on, and the two would disagree for a reason the
	// byte diff cannot explain.
	recorded, err := os.ReadFile(printInputGoldenPath)
	if err != nil {
		t.Fatalf("missing %s — rerun with -update-print-input-golden: %v", printInputGoldenPath, err)
	}
	if string(recorded) != string(body) {
		t.Fatalf("%s is stale: the Go golden input changed but the fixture was not re-exported.\n"+
			"Rerun: go test ./internal/service/ -run TestExportPrintInputGolden -update-print-input-golden",
			printInputGoldenPath)
	}
}

func goldenKey(tc goldenCase) string {
	return goldenKindLabel(tc) + "|" + tc.Locale + "|" + strconv.Itoa(tc.Paper)
}

// goldenKindLabel is the key's first segment: the kind, plus `~variant` when the
// case carries one. The separator is NOT `|`, because PHP splits the key on `|`
// into exactly three parts and resolves a template from the first — a fourth
// segment would silently reshape every existing key.
func goldenKindLabel(tc goldenCase) string {
	if tc.Variant == "" {
		return tc.Kind
	}
	return tc.Kind + "~" + tc.Variant
}
