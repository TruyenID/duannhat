package service

import (
	"strings"
	"testing"
	"time"
)

// #2065 — 取引年月日 must be the SALE, never the moment the paper moved.
//
// The golden gates cannot carry this claim on their own. They render with a
// FROZEN print clock, so a renderer that reads the clock and one that reads the
// order both produce a stable hash; the goldens only notice because
// `goldenSaleClock` is deliberately a different day from `goldenClock`, and that
// is one edit away from being undone by someone tidying fixtures.
//
// These tests state the property directly: move the print clock, keep the order
// fixed, and the printed date must not move. A slip whose date follows the
// printer is the #2065 defect no matter which fixture is in play.

// billFamilyDate pulls the "YYYY/MM/DD HH:MM" line the bill family prints.
func decodedSlip(t *testing.T, b []byte) string {
	t.Helper()
	return decodeSJIS(t, b)
}

func TestTransactionDate_FollowsTheSaleNotThePrinter(t *testing.T) {
	// #2572 — the shop zone is +07, so this instant is 2026/07/19 01:30 on the
	// paper and 2026-07-18 in UTC. Both halves are asserted below.
	sold := goldenSaleClock
	order, items := goldenOrder()
	order.OpenedAt = sold.Add(-time.Hour)
	order.CheckoutAt = nil
	order.ClosedAt = &sold

	cfg := goldenConfigFor("receipt", "vi", 48)

	// Two prints of the SAME sale, days apart. Byte-for-byte identical is the
	// point: a reprint is a copy of a document, not a new document.
	render := func(when time.Time) []byte {
		prev := printNow
		printNow = func() time.Time { return when }
		defer func() { printNow = prev }()
		return FormatPaidTicket(order, items, 7, cfg, goldenSlip())
	}

	first := render(time.Date(2026, 7, 20, 14, 32, 9, 0, time.UTC))
	muchLater := render(time.Date(2026, 9, 3, 8, 15, 0, 0, time.UTC))

	if string(first) != string(muchLater) {
		t.Fatal("#2065: the slip changed when only the PRINT clock moved — " +
			"取引年月日 is being stamped from the printer, not the sale")
	}

	text := decodedSlip(t, first)
	if !strings.Contains(text, "2026/07/19 01:30") {
		t.Errorf("#2065/#2572: expected the SALE instant in SHOP time, 2026/07/19 01:30; got:\n%s", text)
	}
	// The print days must be absent — either one appearing means the clock
	// leaked. `2026/07/18` is the third: it is the sale's UTC calendar day, so
	// its presence means the raw parsed timestamp reached the paper (#2572).
	for _, wrongDay := range []string{"2026/07/20", "2026/09/03", "2026/07/18"} {
		if strings.Contains(text, wrongDay) {
			t.Errorf("wrong date %s on the slip:\n%s", wrongDay, text)
		}
	}
}

// The same claim for the debt slip, which had the identical one-line defect.
func TestTransactionDate_DebtSlipFollowsTheSale(t *testing.T) {
	sold := goldenSaleClock
	order, items := goldenOrder()
	order.OpenedAt = sold
	order.CheckoutAt = nil
	order.ClosedAt = nil

	cfg := goldenConfigFor("debt_slip", "vi", 48)

	prev := printNow
	printNow = func() time.Time { return time.Date(2026, 9, 3, 8, 15, 0, 0, time.UTC) }
	defer func() { printNow = prev }()

	text := decodedSlip(t, FormatDebtSlip(order, items, cfg, goldenDebtInfo()))
	if !strings.Contains(text, "2026/07/19 01:30") {
		t.Errorf("#2065/#2572: debt slip lost the sale instant in shop time; got:\n%s", text)
	}
	if strings.Contains(text, "2026/09/03") {
		t.Error("#2065: debt slip stamped with the print clock")
	}
	if strings.Contains(text, "2026/07/18") {
		t.Error("#2572: debt slip carries the sale's UTC calendar day, not the shop's")
	}
}

// #2572 — THE DATE-LINE CASE, stated on its own so it cannot be diluted.
//
// Everything above pins an INSTANT and would still pass, in a weakened form, if
// someone reverted the zone conversion and re-recorded the fixtures around it.
// This one is about the CALENDAR DAY, which is the thing 取引年月日 actually is:
// a mandatory field of a 適格簡易請求書 (消法57条の4②) and of a VN hoá đơn.
//
//	01:00 on 13 Aug in Asia/Ho_Chi_Minh  ==  18:00 on 12 Aug UTC
//
// A late-night sale is not an edge case in this business — it is the last hour
// of a normal restaurant shift. Before the fix every such sale printed the
// previous day's date on a legal document, and every gate stayed green because
// they were all built on `time.UTC` and so could not tell the two apart.
//
// Asserted across the whole money family, since they share `orderSlipTime` and
// a fix applied to one call site would leave the others silently wrong.
func TestTransactionDate_LateNightSaleKeepsTheShopsCalendarDay(t *testing.T) {
	hcmc, err := time.LoadLocation("Asia/Ho_Chi_Minh")
	if err != nil {
		t.Fatalf("load Asia/Ho_Chi_Minh: %v", err)
	}

	// The order arrives from SQLite exactly as orderEngine.scanOrder leaves it:
	// parsed by time.Parse(time.RFC3339, "…Z"), so Location == UTC.
	sold, err := time.Parse(time.RFC3339, "2026-08-12T18:00:00Z")
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if sold.Location() != time.UTC {
		t.Fatalf("precondition: the seam under test is a UTC-located time, got %v", sold.Location())
	}

	order, items := goldenOrder()
	order.OpenedAt = sold.Add(-90 * time.Minute)
	order.CheckoutAt = nil
	order.ClosedAt = &sold

	// The print happens the next morning — so "print clock leaked" and "UTC
	// leaked" name two DIFFERENT wrong dates and cannot be confused.
	prev := printNow
	printNow = func() time.Time { return time.Date(2026, 8, 13, 2, 0, 0, 0, time.UTC) }
	defer func() { printNow = prev }()

	slips := map[string][]byte{}
	cfgFor := func(kind string) PrintJobConfig {
		return goldenConfigFor(kind, "vi", 48).WithSlipLocation(hcmc)
	}
	slips["receipt"] = FormatPaidTicket(order, items, 7, cfgFor("receipt"), goldenSlip())
	slips["red_invoice"] = FormatRedInvoiceTicket(order, items, cfgFor("red_invoice"), goldenSlip())
	slips["remaining"] = FormatRemainingTicket(order, items, 7, cfgFor("remaining"), 1000)
	slips["debt_slip"] = FormatDebtSlip(order, items, cfgFor("debt_slip"), goldenDebtInfo())

	// The template path renders the same order through the definition renderer;
	// both must agree, because both route through orderSlipTime.
	def, err := SystemPrintTemplate("receipt")
	if err != nil {
		t.Fatalf("system template: %v", err)
	}
	data := NewPaidRenderData(order, items, 7, cfgFor("receipt"), goldenSlip())
	rendered, err := RenderPrintTemplate(def, data, PrintRenderProfile{Columns: 48}, "vi")
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	slips["receipt(template)"] = rendered.Bytes()

	for name, raw := range slips {
		t.Run(name, func(t *testing.T) {
			text := decodedSlip(t, raw)
			if !strings.Contains(text, "2026/08/13 01:00") {
				t.Errorf("取引年月日 must be the shop's 2026/08/13 01:00; got:\n%s", text)
			}
			if strings.Contains(text, "2026/08/12") {
				t.Error("#2572: printed the sale's UTC calendar day (2026/08/12) — " +
					"that is the WRONG DATE on a statutory tax document, not merely the wrong hour")
			}
			if strings.Contains(text, "09:00") {
				t.Error("#2572: printed 09:00 — the shop's local time of the PRINT clock leaked")
			}
		})
	}
}

// #2572 — a recorded byte fixture must not depend on the machine that recorded
// it.
//
// The fix makes the slip's date follow a zone, so the moment a golden config
// forgets to pin one it silently follows `time.Local` instead: the fixture then
// says "correct on the developer's laptop" and every CI run in UTC disagrees —
// or worse, agrees for the wrong reason. `goldenConfig`/`noTaxRateConfig` pin
// `goldenShopLocation` for exactly this; this test is what notices if one of
// them stops.
//
// It moves `time.Local` itself rather than the TZ env var, because Go samples
// TZ once at init and a test process cannot re-read it.
func TestPrintGolden_DoesNotDependOnMachineZone(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	prev := time.Local
	defer func() { time.Local = prev }()

	cfg := goldenConfigFor("receipt", "vi", 48)
	order, items := goldenOrder()

	time.Local = time.UTC
	inUTC := FormatPaidTicket(order, items, 7, cfg, goldenSlip())

	time.Local = time.FixedZone("NOWHERE", -11*60*60)
	inNowhere := FormatPaidTicket(order, items, 7, cfg, goldenSlip())

	if string(inUTC) != string(inNowhere) {
		t.Fatal("#2572: the golden receipt changed with the MACHINE zone — a config " +
			"feeding a recorded hash is not pinning goldenShopLocation")
	}
	if !strings.Contains(decodedSlip(t, inUTC), "2026/07/19 01:30") {
		t.Error("#2572: the pinned +07 shop zone is not the one being printed")
	}
}

// The ladder itself, stated once so a future edit has to argue with it.
func TestOrderTransactionTime_Ladder(t *testing.T) {
	closed := time.Date(2026, 7, 19, 23, 50, 0, 0, time.UTC)
	checkout := time.Date(2026, 7, 19, 23, 40, 0, 0, time.UTC)
	opened := time.Date(2026, 7, 19, 21, 0, 0, 0, time.UTC)

	cases := []struct {
		name string
		o    *Order
		want time.Time
		ok   bool
	}{
		{"closed wins", &Order{OpenedAt: opened, CheckoutAt: &checkout, ClosedAt: &closed}, closed, true},
		{"checkout when not closed", &Order{OpenedAt: opened, CheckoutAt: &checkout}, checkout, true},
		{"opened as last rung", &Order{OpenedAt: opened}, opened, true},
		{"nothing usable", &Order{}, time.Time{}, false},
		{"nil order", nil, time.Time{}, false},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got, ok := orderTransactionTime(tc.o)
			if ok != tc.ok || !got.Equal(tc.want) {
				t.Fatalf("got (%v, %v), want (%v, %v)", got, ok, tc.want, tc.ok)
			}
		})
	}

	// A zero-valued pointer must not win over a real lower rung — otherwise a
	// row synced down with an empty timestamp column prints 0001/01/01.
	zero := time.Time{}
	got, ok := orderTransactionTime(&Order{OpenedAt: opened, ClosedAt: &zero, CheckoutAt: &zero})
	if !ok || !got.Equal(opened) {
		t.Fatalf("zero-valued ClosedAt/CheckoutAt must be skipped; got (%v, %v)", got, ok)
	}
}

// An explicit caller-supplied Now still wins — Cloud has no shop clock and must
// be able to hand the instant down (#1091).
func TestPrintRenderData_ExplicitNowStillWins(t *testing.T) {
	sold := time.Date(2026, 7, 19, 23, 50, 0, 0, time.UTC)
	supplied := time.Date(2026, 1, 2, 3, 4, 0, 0, time.UTC)
	order, _ := goldenOrder()
	order.ClosedAt = &sold

	d := &PrintRenderData{Order: order, Now: supplied}
	if got := d.now(); !got.Equal(supplied) {
		t.Fatalf("caller-supplied Now must win: got %v want %v", got, supplied)
	}

	d2 := &PrintRenderData{Order: order}
	if got := d2.now(); !got.Equal(sold) {
		t.Fatalf("with no explicit Now the SALE instant must be used: got %v want %v", got, sold)
	}
}
