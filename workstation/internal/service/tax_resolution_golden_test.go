package service

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"testing"
)

// Cross-language contract for tax resolution (#1099) — the workstation half.
//
// WHY THIS EXISTS. Two implementations decide what tax a line carries: Cloud's
// TaxResolver (backend/app/Services/Customer/TaxResolver.php) and this mirror,
// which has to resolve the same rate with the internet down — the register
// prints a receipt and hands it to a customer before Cloud ever sees the order.
// When the two walks disagree the shop collects one amount and books another,
// and the gap surfaces as an unexplained 過不足 in the shift report rather than
// as an error anyone can trace.
//
// Prose in two repos cannot enforce that, so the contract is a DATA file that
// exists byte-identically in both: testdata/tax_resolution_golden.json here and
// backend/tests/Fixtures/tax_resolution_golden.json there. Each side asserts the
// same expectations against its own resolver. Cloud is authoritative — the
// fixture states what Cloud does, and when Cloud changes, its test fails first
// and the same change must carry the file here.
//
// The digest test below is what makes a one-sided edit impossible to miss.

type taxGoldenType struct {
	ID       string  `json:"id"`
	Code     string  `json:"code"`
	Rate     float64 `json:"rate"`
	IsDefaut bool    `json:"is_default"`
	IsActive bool    `json:"is_active"`
}

type taxGoldenExpect struct {
	TaxTypeID   *string `json:"tax_type_id"`
	Rate        float64 `json:"rate"`
	HasSnapshot bool    `json:"has_snapshot"`
}

type taxGoldenCase struct {
	Name                   string          `json:"name"`
	MenuTaxTypeID          *string         `json:"menu_tax_type_id"`
	ProductTaxTypeID       *string         `json:"product_tax_type_id"`
	BranchDefaultTaxTypeID *string         `json:"branch_default_tax_type_id"`
	LegacyBranchTaxRate    float64         `json:"legacy_branch_tax_rate"`
	ExtraTaxTypes          []taxGoldenType `json:"extra_tax_types"`
	NoBrandDefault         bool            `json:"no_brand_default"`
	Expect                 taxGoldenExpect `json:"expect"`
	Why                    string          `json:"why"`
}

type taxGoldenFile struct {
	Version   string          `json:"version"`
	Digest    string          `json:"digest"`
	CaseCount int             `json:"case_count"`
	TaxTypes  []taxGoldenType `json:"tax_types"`
	Cases     []taxGoldenCase `json:"cases"`
}

func loadTaxGolden(t *testing.T) taxGoldenFile {
	t.Helper()

	raw, err := os.ReadFile("testdata/tax_resolution_golden.json")
	if err != nil {
		t.Fatalf("read golden fixture: %v", err)
	}

	var f taxGoldenFile
	if err := json.Unmarshal(raw, &f); err != nil {
		t.Fatalf("parse golden fixture: %v", err)
	}
	if f.Version != "tempo-tax-resolution-v1" {
		t.Fatalf("golden fixture version = %q, want tempo-tax-resolution-v1", f.Version)
	}
	if len(f.Cases) != f.CaseCount {
		t.Fatalf("golden fixture has %d cases, declares %d", len(f.Cases), f.CaseCount)
	}

	return f
}

func orEmpty(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

// TestTaxResolutionGolden_Parity walks every case in the shared fixture.
func TestTaxResolutionGolden_Parity(t *testing.T) {
	golden := loadTaxGolden(t)

	for _, tc := range golden.Cases {
		t.Run(tc.Name, func(t *testing.T) {
			e, _ := newOrderEngineForTest(t)

			// A brand with zero tax types is the only route to the no-snapshot
			// outcome, so that case suppresses the catalog entirely.
			catalog := []taxGoldenType{}
			if !tc.NoBrandDefault {
				catalog = append(catalog, golden.TaxTypes...)
				catalog = append(catalog, tc.ExtraTaxTypes...)
			}
			for _, tt := range catalog {
				mustExecTax(t, e, fmt.Sprintf(
					`INSERT INTO tax_types (id, code, name, rate, is_default, is_active) VALUES ('%s','%s','%s',%v,%d,%d)`,
					tt.ID, tt.Code, tt.Code, tt.Rate, boolToInt(tt.IsDefaut), boolToInt(tt.IsActive),
				))
			}

			// The legacy per-shop rate is seeded on PURPOSE and differs from every
			// expected rate: if the resolver ever reached for it again, these cases
			// would show it instead of failing quietly.
			mustExecTax(t, e, fmt.Sprintf(
				`INSERT INTO shop_settings (key, value) VALUES ('tax_rate', '%v')`, tc.LegacyBranchTaxRate,
			))
			if id := orEmpty(tc.BranchDefaultTaxTypeID); id != "" {
				mustExecTax(t, e, fmt.Sprintf(
					`INSERT INTO shop_settings (key, value) VALUES ('default_tax_type_id', '%s')`, id,
				))
			}

			// The menu feed collapses Cloud's tiers 1 and 2 into the single
			// menu_items.tax_type_id column (CustomerMenuService: menu ?? product),
			// so the mirror's input is that collapse, applied here explicitly.
			lineTaxTypeID := orEmpty(tc.MenuTaxTypeID)
			if lineTaxTypeID == "" {
				lineTaxTypeID = orEmpty(tc.ProductTaxTypeID)
			}

			got := e.resolveLineTax(lineTaxTypeID)

			wantID := orEmpty(tc.Expect.TaxTypeID)
			if got.TaxTypeID != wantID {
				t.Errorf("tax TYPE = %q, want %q\n%s", got.TaxTypeID, wantID, tc.Why)
			}
			if got.Rate != tc.Expect.Rate {
				t.Errorf("RATE = %v, want %v\n%s", got.Rate, tc.Expect.Rate, tc.Why)
			}
			if got.HasSnapshot != tc.Expect.HasSnapshot {
				t.Errorf("snapshot = %v, want %v — an explicit 0%% and an unresolved line must stay distinguishable\n%s",
					got.HasSnapshot, tc.Expect.HasSnapshot, tc.Why)
			}
		})
	}
}

// TestTaxResolutionGolden_Digest pins the fixture so it cannot be edited in only
// one repo.
//
// The digest covers a DELIMITED rendering, not canonical JSON: both languages
// must emit identical bytes, and delimited fields are the one encoding where
// that is obvious by inspection — JSON key order, whitespace and float
// formatting are not. Same reasoning as the offline signing message.
func TestTaxResolutionGolden_Digest(t *testing.T) {
	golden := loadTaxGolden(t)

	const absent = "~"
	lines := make([]string, 0, len(golden.Cases))
	for _, tc := range golden.Cases {
		field := func(p *string) string {
			if p == nil {
				return absent
			}
			return *p
		}
		lines = append(lines, strings.Join([]string{
			tc.Name,
			field(tc.MenuTaxTypeID),
			field(tc.ProductTaxTypeID),
			field(tc.BranchDefaultTaxTypeID),
			fmt.Sprintf("%.2f", tc.LegacyBranchTaxRate),
			field(tc.Expect.TaxTypeID),
			fmt.Sprintf("%.2f", tc.Expect.Rate),
			fmt.Sprintf("%t", tc.Expect.HasSnapshot),
		}, "|"))
	}

	sum := sha256.Sum256([]byte(strings.Join(lines, "\n")))
	digest := hex.EncodeToString(sum[:])

	if digest != golden.Digest {
		t.Fatalf("golden fixture digest = %s, stored %s.\nIf you changed a case on purpose, update the `digest` field AND copy the whole file to backend/tests/Fixtures/tax_resolution_golden.json in the same commit.",
			digest, golden.Digest)
	}
}
