package service

import (
	"bytes"
	"strings"
	"testing"
)

// #3095 — a takeaway order is handed over at a counter, so the two slips that
// travel WITH THE FOOD (kitchen ticket + delta-QR hall slip) carry whether the
// money was already taken. The row occupies the slot the 卓 column leaves empty.
//
// The scope is the point of most of this file: the same row must NOT appear on
// the slips that already print `payments`/`remaining` in full.

func takeawayOrder(paid int) *Order {
	return &Order{
		ID:          "order-uuid-tk",
		OrderCode:   "WS-019e-20260817-004",
		OrderType:   "takeaway",
		TableNumber: "C-07", // present on purpose: takeaway must ignore it
		TotalAmount: 1780,
		PaidAmount:  paid,
		Items: []Item{
			{MenuItemName: "Bun ga la chanh", Quantity: 1, UnitPrice: 1780, Status: ItemStatusPending},
		},
	}
}

func TestTakeawaySlips_CarryPaymentState(t *testing.T) {
	cases := []struct {
		locale string
		paid   string
		unpaid string
		label  string
	}{
		{"ja", "済み", "未払", "支払"},
		{"en", "PAID", "UNPAID", "Payment"},
		{"vi", "DA TRA", "CHUA TRA", "Thanh toan"},
	}
	for _, tc := range cases {
		t.Run(tc.locale, func(t *testing.T) {
			cfg := PrintJobConfig{PaperWidth: 48, TaxRate: 10, Locale: tc.locale}

			settled := takeawayOrder(1780)
			owing := takeawayOrder(0)

			slips := map[string]func(o *Order) []byte{
				"kitchen":  func(o *Order) []byte { return FormatKitchenTicket(o, o.Items, 319, cfg) },
				"delta_qr": func(o *Order) []byte { return FormatDeltaQRTicket(o, o.Items, cfg) },
				// The hall bill carries it too (chủ dự án 2026-08-17). It is the
				// sheet with the CORRECT money — full 小計 / 合計 (税込) / per-rate
				// tax — which is exactly why the payment word was copied onto it
				// rather than the delta-QR slip being reused as the reprint: that
				// one totals its fire batch, so on a whole order it prints a
				// figure ¥50 short of what the customer actually paid.
				"runner": func(o *Order) []byte { return FormatRunnerTicket(o, o.Items, 0, cfg) },
			}
			for name, render := range slips {
				t.Run(name, func(t *testing.T) {
					gotPaid := decodeSJIS(t, render(settled))
					if !strings.Contains(gotPaid, tc.label) || !strings.Contains(gotPaid, tc.paid) {
						t.Errorf("settled takeaway %s slip missing %q/%q:\n%s", name, tc.label, tc.paid, gotPaid)
					}
					if strings.Contains(gotPaid, tc.unpaid) {
						t.Errorf("settled takeaway %s slip claims %q:\n%s", name, tc.unpaid, gotPaid)
					}

					gotOwing := decodeSJIS(t, render(owing))
					if !strings.Contains(gotOwing, tc.unpaid) {
						t.Errorf("unpaid takeaway %s slip missing %q:\n%s", name, tc.unpaid, gotOwing)
					}

					// The table reference must stay gone — the freed slot is
					// REUSED, not shared.
					if strings.Contains(gotOwing, "C-07") {
						t.Errorf("takeaway %s slip leaked the table number:\n%s", name, gotOwing)
					}
				})
			}
		})
	}
}

// Anything short of the full amount reads UNPAID. Takeaway carries no split and
// no partial settlement, so this is an impossible-state guard rather than a
// business branch — and it fails in the direction that makes staff ask.
func TestTakeawayPaymentState_ShortPaymentIsUnpaid(t *testing.T) {
	cfg := PrintJobConfig{PaperWidth: 48, TaxRate: 10, Locale: "en"}
	for _, paid := range []int{0, 1, 1779} {
		got := decodeSJIS(t, FormatKitchenTicket(takeawayOrder(paid), takeawayOrder(paid).Items, 1, cfg))
		if !strings.Contains(got, "UNPAID") {
			t.Errorf("paid=%d must read UNPAID:\n%s", paid, got)
		}
	}
	// A zero-total order is NOT settled: `0 >= 0` would otherwise announce that
	// nothing is owed on a slip printed before the order was priced.
	unpriced := takeawayOrder(0)
	unpriced.TotalAmount = 0
	got := decodeSJIS(t, FormatKitchenTicket(unpriced, unpriced.Items, 1, cfg))
	if !strings.Contains(got, "UNPAID") {
		t.Errorf("unpriced order must read UNPAID:\n%s", got)
	}
}

// The SETTLEMENT slips answer this question with their own blocks — `payments`,
// `change_due`, `remaining`. A second, shorter answer on the same sheet is how
// two statements about one fact start disagreeing, so the row stops here.
//
// `runner` is deliberately NOT in this list: it is a hall sheet, not a
// settlement document — it prints no `payments` block at all, so the payment
// word is the sheet's only statement about the money, not a competing one.
func TestTakeawayPaymentState_AbsentFromSettlementSlips(t *testing.T) {
	cfg := PrintJobConfig{PaperWidth: 48, TaxRate: 10, Locale: "en"}
	o := takeawayOrder(0)

	slips := map[string][]byte{
		"receipt":   FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: 1780}),
		"remaining": FormatRemainingTicket(o, o.Items, 0, cfg, 1780),
	}
	for name, slip := range slips {
		got := decodeSJIS(t, slip)
		if strings.Contains(got, "UNPAID") {
			t.Errorf("%s slip must not carry the takeaway payment row:\n%s", name, got)
		}
	}
}

// Dine-in is untouched: the 卓 row is where it always was and no payment row
// appears. Without this the change could silently reshape every table slip.
func TestDineInSlips_KeepTableAndCarryNoPaymentState(t *testing.T) {
	cfg := PrintJobConfig{PaperWidth: 48, TaxRate: 10, Locale: "en"}
	o := takeawayOrder(0)
	o.OrderType = "dine_in"

	for name, slip := range map[string][]byte{
		"kitchen":  FormatKitchenTicket(o, o.Items, 319, cfg),
		"delta_qr": FormatDeltaQRTicket(o, o.Items, cfg),
	} {
		got := decodeSJIS(t, slip)
		if !strings.Contains(got, "C-07") {
			t.Errorf("dine-in %s slip lost its table:\n%s", name, got)
		}
		if strings.Contains(got, "UNPAID") || strings.Contains(got, "PAID") {
			t.Errorf("dine-in %s slip grew a payment row:\n%s", name, got)
		}
	}
}

// TR-40 for the takeaway branch. The recorded golden matrix covers takeaway
// (kind~takeaway cells), but this keeps the legacy↔template comparison readable
// for the one branch this change adds: a diff here names the slip, not a hash.
func TestTakeawayPaymentState_LegacyMatchesTemplateRenderer(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	for _, locale := range []string{"ja", "en", "vi"} {
		for _, cols := range []int{32, 42, 48} {
			for _, paid := range []int{0, 1780} {
				o := takeawayOrder(paid)
				cfg := goldenConfigFor("kitchen", locale, cols)

				pairs := map[string]struct {
					legacy []byte
					data   *PrintRenderData
				}{
					"kitchen": {
						FormatKitchenTicket(o, o.Items, 319, cfg),
						NewKitchenRenderData(o, o.Items, 319, cfg),
					},
					"delta_qr": {
						FormatDeltaQRTicket(o, o.Items, cfg),
						NewDeltaQRRenderData(o, o.Items, cfg),
					},
				}
				for kind, p := range pairs {
					def, err := SystemPrintTemplate(kind)
					if err != nil {
						t.Fatalf("system default for %q: %v", kind, err)
					}
					got, err := RenderPrintTemplate(def, p.data, PrintRenderProfile{Columns: cols}, locale)
					if err != nil {
						t.Fatalf("render %q: %v", kind, err)
					}
					if !bytes.Equal(p.legacy, got.Bytes()) {
						t.Fatalf("TR-40 takeaway FAILED for %s (%s, %d cols, paid=%d)\n%s",
							kind, locale, cols, paid, diffBytes(p.legacy, got.Bytes()))
					}
				}
			}
		}
	}
}
