package service

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

// #2091 — golden layer for ORDERS WITH MISSING DATA (#2067 branches).
//
// The 126-cell `goldenMatrix()` only ever carries engine-derived "beautiful"
// orders: positive totals, tax_rate on every line, tax_amount stamped. The
// print layer's no-fabrication rules (`print_no_fabricated_money_test.go`) are
// behavioural at the formatter boundary but never reached slip-level parity.
//
// This file adds a SECOND fixture, keyed by scenario × kind, so a regression that
// re-summed voided lines or invented 10%% tax breaks the hash gate — not only a
// unit test nobody runs before merge.
var updateDeficientGolden = flag.Bool(
	"update-print-deficient-golden", false, "rewrite testdata/print_deficient_golden.json")

var updateDeficientInputGolden = flag.Bool(
	"update-print-deficient-input-golden", false, "rewrite testdata/print_deficient_input_golden.json")

const (
	printDeficientGoldenPath      = "testdata/print_deficient_golden.json"
	printDeficientInputGoldenPath = "testdata/print_deficient_input_golden.json"
)

type deficientCase struct {
	Scenario string
	Kind     string
	Locale   string
	Paper    int
}

// deficientGoldenMatrix is deliberately SMALL: four scenarios × five money kinds
// × one locale × one paper width (vi/42 — the VND shop shape #2067 measured).
func deficientGoldenMatrix() []deficientCase {
	scenarios := []string{
		"no_tax_fact",
		"stamped_tax_amount",
		"coupon_zero_total",
		"all_voided",
	}
	kinds := []string{"runner", "receipt", "red_invoice", "remaining", "debt_slip"}
	var out []deficientCase
	for _, s := range scenarios {
		for _, k := range kinds {
			out = append(out, deficientCase{
				Scenario: s,
				Kind:     k,
				Locale:   "vi",
				Paper:    42,
			})
		}
	}
	return out
}

func deficientGoldenKey(tc deficientCase) string {
	return fmt.Sprintf("%s|%s|%s|%d", tc.Scenario, tc.Kind, tc.Locale, tc.Paper)
}

func deficientConfigFor(tc deficientCase) PrintJobConfig {
	cfg := noTaxRateConfig()
	cfg.PaperWidth = tc.Paper
	cfg.PhysicalWidth = tc.Paper
	cfg.Locale = tc.Locale
	cfg.OperatingCountry = "VN"
	return cfg
}

func deficientOrder(scenario string) (*Order, []Item) {
	switch scenario {
	case "no_tax_fact":
		return untaxedUnstampedOrder()
	case "stamped_tax_amount":
		order, items := untaxedUnstampedOrder()
		order.TaxAmount = 88
		return order, items
	case "coupon_zero_total":
		return &Order{
			ID: "order-coupon", OrderCode: "WS-0001",
			Subtotal: 2000, DiscountAmount: 2000, TotalAmount: 0,
		}, []Item{{ID: "i1", MenuItemName: "Bun bo", Quantity: 2, UnitPrice: 1000}}
	case "all_voided":
		voided := goldenSaleClock
		return &Order{ID: "order-void", OrderCode: "WS-0002", TotalAmount: 0}, []Item{{
			ID: "i1", MenuItemName: "Bun bo", Quantity: 2, UnitPrice: 1000,
			Status: ItemStatusVoided, VoidedAt: &voided,
		}}
	default:
		panic("unknown deficient scenario: " + scenario)
	}
}

func deficientSlip(scenario string, order *Order) PaymentSlipInfo {
	switch scenario {
	case "coupon_zero_total", "all_voided":
		return PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: 0, Tendered: 0, Change: 0, Remaining: 0}
	default:
		return PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: order.TotalAmount, Tendered: order.TotalAmount, Change: 0, Remaining: 0}
	}
}

func deficientRemaining(scenario string, order *Order) int {
	if scenario == "coupon_zero_total" || scenario == "all_voided" {
		return 0
	}
	return order.TotalAmount
}

func deficientRenderData(kind, scenario string, cfg PrintJobConfig) *PrintRenderData {
	order, items := deficientOrder(scenario)
	slip := deficientSlip(scenario, order)
	switch kind {
	case "receipt":
		return NewPaidRenderData(order, items, 0, cfg, slip)
	case "runner":
		return NewRunnerRenderData(order, items, 0, cfg)
	case "red_invoice":
		return NewRedInvoiceRenderData(order, items, cfg, slip)
	case "remaining":
		return NewRemainingRenderData(order, items, 0, cfg, deficientRemaining(scenario, order))
	case "debt_slip":
		return NewDebtSlipRenderData(order, items, cfg, goldenDebtInfo())
	default:
		return nil
	}
}

func TestPrintRenderer_DeficientGolden_G1_ByteStreamLocked(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	recorded := map[string]string{}
	if raw, err := os.ReadFile(printDeficientGoldenPath); err == nil {
		if err := json.Unmarshal(raw, &recorded); err != nil {
			t.Fatalf("read %s: %v", printDeficientGoldenPath, err)
		}
	} else if !*updateDeficientGolden {
		t.Fatalf("missing %s — rerun with -update-print-deficient-golden", printDeficientGoldenPath)
	}

	produced := map[string]string{}
	for _, tc := range deficientGoldenMatrix() {
		key := deficientGoldenKey(tc)
		cfg := deficientConfigFor(tc)
		def, err := SystemPrintTemplate(tc.Kind)
		if err != nil {
			t.Fatalf("system default for %q: %v", tc.Kind, err)
		}
		data := deficientRenderData(tc.Kind, tc.Scenario, cfg)
		if data == nil {
			t.Fatalf("no render data for %s", key)
		}
		data.Now = data.now()
		res, err := RenderPrintTemplate(def, data, PrintRenderProfile{Columns: tc.Paper}, tc.Locale)
		if err != nil {
			t.Fatalf("render %q: %v", key, err)
		}
		sum := sha256.Sum256(res.Bytes())
		produced[key] = hex.EncodeToString(sum[:])
	}

	if *updateDeficientGolden {
		if err := os.MkdirAll(filepath.Dir(printDeficientGoldenPath), 0o755); err != nil {
			t.Fatal(err)
		}
		body, _ := json.MarshalIndent(produced, "", "  ")
		if err := os.WriteFile(printDeficientGoldenPath, append(body, '\n'), 0o644); err != nil {
			t.Fatal(err)
		}
		t.Logf("rewrote %s with %d entries", printDeficientGoldenPath, len(produced))
		return
	}

	keys := make([]string, 0, len(produced))
	for k := range produced {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		want, ok := recorded[k]
		if !ok {
			t.Errorf("deficient golden %s missing — rerun with -update-print-deficient-golden", k)
			continue
		}
		if want != produced[k] {
			t.Errorf("deficient golden %s changed: recorded %s, produced %s", k, want, produced[k])
		}
	}
}

type printDeficientInputGoldenFile struct {
	Clock        string                       `json:"clock"`
	Cases        map[string]*PrintRenderData  `json:"cases"`
	TaxSummaries map[string]receiptTaxSummary `json:"tax_summaries"`
}

func TestExportPrintDeficientInputGolden(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	produced := map[string]*PrintRenderData{}
	taxes := map[string]receiptTaxSummary{}
	for _, tc := range deficientGoldenMatrix() {
		cfg := deficientConfigFor(tc)
		d := deficientRenderData(tc.Kind, tc.Scenario, cfg)
		d.Now = d.now()
		key := deficientGoldenKey(tc)
		produced[key] = d
		taxes[key] = receiptTaxSummaryForKind(d.Kind, d.DeltaBill, d.Order, d.Items, cfg.step())
	}

	body, err := json.MarshalIndent(printDeficientInputGoldenFile{
		Clock:        goldenClock.Format("2006-01-02T15:04:05Z07:00"),
		Cases:        produced,
		TaxSummaries: taxes,
	}, "", "  ")
	if err != nil {
		t.Fatalf("marshal deficient input golden: %v", err)
	}
	body = append(body, '\n')

	if *updateDeficientInputGolden {
		if err := os.WriteFile(printDeficientInputGoldenPath, body, 0o644); err != nil {
			t.Fatalf("write %s: %v", printDeficientInputGoldenPath, err)
		}
		return
	}

	recorded, err := os.ReadFile(printDeficientInputGoldenPath)
	if err != nil {
		t.Fatalf("missing %s — rerun with -update-print-deficient-input-golden: %v", printDeficientInputGoldenPath, err)
	}
	if !bytes.Equal(recorded, body) {
		t.Fatalf("%s stale — rerun with -update-print-deficient-input-golden", printDeficientInputGoldenPath)
	}
}

func TestDeficientGolden_MigrationGate_ByteIdenticalWithLegacyFormatter(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	for _, tc := range deficientGoldenMatrix() {
		t.Run(strings.ReplaceAll(deficientGoldenKey(tc), "|", "/"), func(t *testing.T) {
			cfg := deficientConfigFor(tc)
			data := deficientRenderData(tc.Kind, tc.Scenario, cfg)
			data.Now = data.now()
			legacy := renderDeficientLegacy(t, tc.Kind, tc.Scenario, cfg)

			def, err := SystemPrintTemplate(tc.Kind)
			if err != nil {
				t.Fatalf("system default: %v", err)
			}
			got, err := RenderPrintTemplate(def, data, PrintRenderProfile{Columns: tc.Paper}, tc.Locale)
			if err != nil {
				t.Fatalf("render: %v", err)
			}
			if !bytes.Equal(legacy, got.Bytes()) {
				t.Fatalf("deficient TR-40 gate FAILED\n%s", diffBytes(legacy, got.Bytes()))
			}
		})
	}
}

func renderDeficientLegacy(t *testing.T, kind, scenario string, cfg PrintJobConfig) []byte {
	t.Helper()
	order, items := deficientOrder(scenario)
	slip := deficientSlip(scenario, order)
	switch kind {
	case "runner":
		return FormatRunnerTicket(order, items, 0, cfg)
	case "receipt":
		return FormatPaidTicket(order, items, 0, cfg, slip)
	case "red_invoice":
		return FormatRedInvoiceTicket(order, items, cfg, slip)
	case "remaining":
		return FormatRemainingTicket(order, items, 0, cfg, deficientRemaining(scenario, order))
	case "debt_slip":
		return FormatDebtSlip(order, items, cfg, goldenDebtInfo())
	default:
		t.Fatalf("no legacy formatter for kind %q", kind)
		return nil
	}
}
