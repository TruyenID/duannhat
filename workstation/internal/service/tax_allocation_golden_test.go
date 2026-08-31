package service

import (
	"encoding/json"
	"math"
	"os"
	"testing"
)

// Per-rate group tax is rounded ONCE per rate group and then allocated back to
// the lines by largest remainder. Cloud and the workstation each implement that
// allocator, and until now the only thing asserting they agree was a comment
// ("Mirrors CustomerOrderService::stampLineTaxAmounts on Cloud").
//
// #1219 is what an unpinned parity claim costs: a payment filter carried the
// same promise in a docstring, one caller quietly did something else, and the
// cashier's 過不足 stopped matching the money that was recorded. Rate RESOLUTION
// was already pinned by tax_resolution_golden.json; the rounding and allocation
// step — the half with the tie-breaks — was not.
//
// testdata/tax_allocation_golden.json is byte-identical to
// backend/tests/Fixtures/tax_allocation_golden.json and is read by both sides.
// It was generated from the PHP implementation, so this test is a genuine
// cross-engine comparison rather than Go checking its own output.
//
// The cases are chosen for what can DIVERGE between two implementations rather
// than for happy-path coverage: exact ties in the fractional remainder (the only
// place a tie-break rule is observable at all), a residual landing on a
// different index than the whole steps, step = 0, and the rare 内税 branch where
// the floored sum overshoots the group tax.
func TestAllocateGroupTax_MatchesCloudGolden(t *testing.T) {
	raw, err := os.ReadFile("testdata/tax_allocation_golden.json")
	if err != nil {
		t.Fatalf("read golden: %v", err)
	}

	var golden struct {
		CaseCount int `json:"case_count"`
		Cases     []struct {
			Name     string    `json:"name"`
			Ideals   []float64 `json:"ideals"`
			GroupTax float64   `json:"group_tax"`
			Step     float64   `json:"step"`
			Expected []float64 `json:"expected"`
		} `json:"cases"`
	}
	if err := json.Unmarshal(raw, &golden); err != nil {
		t.Fatalf("parse golden: %v", err)
	}
	if len(golden.Cases) == 0 || len(golden.Cases) != golden.CaseCount {
		t.Fatalf("golden has %d cases, header says %d — a truncated fixture would make this test pass vacuously",
			len(golden.Cases), golden.CaseCount)
	}

	for _, c := range golden.Cases {
		t.Run(c.Name, func(t *testing.T) {
			got := AllocateGroupTax(c.Ideals, c.GroupTax, c.Step)

			if len(got) != len(c.Expected) {
				t.Fatalf("got %d values, want %d", len(got), len(c.Expected))
			}
			for i := range c.Expected {
				// Both engines work in float; compare at a tolerance far tighter
				// than the smallest currency step in play.
				if math.Abs(got[i]-c.Expected[i]) > 1e-6 {
					t.Errorf("line %d: got %v, Cloud says %v (full: got=%v want=%v)",
						i, got[i], c.Expected[i], got, c.Expected)
				}
			}

			// Whatever the split, the group total is the invariant that must
			// hold — a per-line disagreement that still sums correctly is a
			// display bug, one that does not is a money bug.
			sum := 0.0
			for _, v := range got {
				sum += v
			}
			if math.Abs(sum-c.GroupTax) > 1e-6 {
				t.Errorf("Σ allocated = %v, want the group tax %v", sum, c.GroupTax)
			}
		})
	}
}
