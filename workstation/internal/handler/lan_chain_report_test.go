package handler

import (
	"testing"
)

// buildChainReport must surface each shift's OWN revenue and drawer, and a
// grand total that is the exact Σ of the per-shift snapshots (R4) — never a
// live re-derivation, so a later refund can't retro-change a block whose paper
// slip is already filed (R2).
//
// The fixture is a real 3-shift chain (2 handovers + a final) captured from a
// running workstation, so the arithmetic below is production arithmetic.
func seedChain(t *testing.T, s *Server) string {
	t.Helper()
	exec := func(q string, args ...any) {
		t.Helper()
		if _, err := s.db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v\n%s", err, q)
		}
	}
	const chainID = "c4173a91-0000-0000-0000-000000000000"
	exec(`INSERT INTO tills (id, branch_id, code) VALUES ('t1','br-1','0001')`)
	exec(`INSERT INTO payment_methods (id, code, name) VALUES ('pm-cash','cash','現金')`)
	exec(`INSERT INTO payment_methods (id, code, name) VALUES ('pm-credit','credit','クレジット')`)

	// snapshot(gross, net, tax, counted, expected, float, cashSales, taxable, rateTax)
	snap := func(gross, net, tax, counted, expected, float, cashSales, taxable, rateTax int) string {
		return `{
			"opening_float": ` + itoa(float) + `,
			"cash": {"counted": ` + itoa(counted) + `, "expected": ` + itoa(expected) +
			`, "variance": 0, "sales": ` + itoa(cashSales) + `, "paid_in": 0, "paid_out": 0},
			"revenue": {"gross": ` + itoa(gross) + `, "net": ` + itoa(net) +
			`, "tax": ` + itoa(tax) + `, "discount": 0},
			"tax_breakdown": [{"rate": 10, "taxable": ` + itoa(taxable) + `, "tax": ` + itoa(rateTax) + `}],
			"orders": {"paid_count": 1, "paid_total": ` + itoa(gross) + `},
			"counts": {"item_count": 2, "guest_count": 3},
			"discounts": [{"label":"HAPPY15","count":1,"amount":100}],
			"voids": {"unpaid_count":1,"unpaid_amount":500,"paid_count":0,"paid_amount":0},
			"cash_events": {"paid_in_count":1,"paid_out_count":0},
			"denominations": [{"value":1000,"quantity":2,"subtotal":2000}],
			"tenders": [{"tender_key":"cash","category":"cash","expected_amount":` + itoa(cashSales) + `},
			            {"tender_key":"credit","category":"card","expected_amount":0}]
		}`
	}
	add := func(id string, seq int, kind, opened, closed, snapshot string) {
		exec(`INSERT INTO till_sessions
			(id, session_code, status, business_date, default_currency_code,
			 opening_float_amount, opened_at, closed_at, counted_cash, cash_variance,
			 till_id, branch_id, chain_id, chain_sequence, settlement_kind, settlement_snapshot)
			VALUES (?,?,'settled','2026-07-21','JPY',0,?,?,0,0,'t1','br-1',?,?,?,?)`,
			id, "WS-"+id, opened, closed, chainID, seq, kind, snapshot)
	}
	// Real figures: shift1 1081/982/99, shift2 5596/5087/509, shift3 6067/5516/551.
	add("s1", 1, "handover", "2026-07-21T00:18:16Z", "2026-07-21T00:19:44Z",
		snap(1081, 982, 99, 11081, 11081, 10000, 1081, 935, 94))
	add("s2", 2, "handover", "2026-07-21T00:19:44Z", "2026-07-21T00:23:19Z",
		snap(5596, 5087, 509, 16677, 16677, 11081, 5596, 4845, 485))
	add("s3", 3, "final", "2026-07-21T00:23:19Z", "2026-07-21T00:30:00Z",
		snap(6067, 5516, 551, 22744, 22744, 16677, 6067, 5253, 525))
	return chainID
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	neg := n < 0
	if neg {
		n = -n
	}
	var b []byte
	for n > 0 {
		b = append([]byte{byte('0' + n%10)}, b...)
		n /= 10
	}
	if neg {
		return "-" + string(b)
	}
	return string(b)
}

func TestBuildChainReport_PerShiftDetailAndExactGrandTotal(t *testing.T) {
	s := newLANPrintTestServer(t)
	chainID := seedChain(t, s)

	info, err := s.buildChainReport(chainID)
	if err != nil {
		t.Fatalf("buildChainReport: %v", err)
	}

	if info.ShiftCount != 3 || len(info.Shifts) != 3 {
		t.Fatalf("ShiftCount = %d / %d shifts, want 3", info.ShiftCount, len(info.Shifts))
	}

	// Each shift keeps its OWN figures, in chain order.
	wantShift := []struct{ gross, net, tax, counted int }{
		{1081, 982, 99, 11081},
		{5596, 5087, 509, 16677},
		{6067, 5516, 551, 22744},
	}
	for i, w := range wantShift {
		got := info.Shifts[i]
		if got.Sequence != i+1 {
			t.Errorf("shift[%d].Sequence = %d, want %d", i, got.Sequence, i+1)
		}
		if got.Gross != w.gross || got.Net != w.net || got.Tax != w.tax {
			t.Errorf("shift[%d] revenue = %d/%d/%d, want %d/%d/%d",
				i, got.Gross, got.Net, got.Tax, w.gross, w.net, w.tax)
		}
		if got.CountedCash != w.counted {
			t.Errorf("shift[%d].CountedCash = %d, want %d", i, got.CountedCash, w.counted)
		}
		if got.CheckCount != 1 {
			t.Errorf("shift[%d].CheckCount = %d, want 1", i, got.CheckCount)
		}
		if got.OpenedAt == "" || got.ClosedAt == "" {
			t.Errorf("shift[%d] missing its time span", i)
		}
	}

	// Grand total == Σ snapshots, to the yen.
	if info.Gross != 1081+5596+6067 {
		t.Errorf("Gross = %d, want %d", info.Gross, 1081+5596+6067)
	}
	if info.Net != 982+5087+5516 {
		t.Errorf("Net = %d, want %d", info.Net, 982+5087+5516)
	}
	if info.TaxTotal != 99+509+551 {
		t.Errorf("TaxTotal = %d, want %d", info.TaxTotal, 99+509+551)
	}
	// The drawer is ONE physical till handed along the chain — each shift's
	// opening float IS the previous shift's closing count. Summing the counts
	// would report the same banknotes once per shift (50,502 for a drawer
	// holding 22,744), so the chain reports the FINAL count.
	if info.CountedCash != 22744 {
		t.Errorf("CountedCash = %d, want 22744 (the final count, NOT the sum)", info.CountedCash)
	}
	if info.ExpectedCash != 22744 {
		t.Errorf("ExpectedCash = %d, want 22744 (comparable to the final count)", info.ExpectedCash)
	}
	// counted − expected must still equal the printed variance, or the drawer
	// block on the slip cannot be added up.
	if info.CountedCash-info.ExpectedCash != info.Variance {
		t.Errorf("drawer block does not reconcile: %d - %d != %d",
			info.CountedCash, info.ExpectedCash, info.Variance)
	}
	// Per-shift variance stays visible in the index so per-cashier
	// accountability is not lost to the final-count rule.
	if len(info.Shifts) != 3 {
		t.Fatalf("chain index lost its shifts")
	}
	if info.CheckCount != 3 {
		t.Errorf("CheckCount = %d, want 3", info.CheckCount)
	}

	// The chain's opening float is the FIRST shift's, NOT the sum — each
	// handover passes the same physical drawer on.
	if info.OpeningFloat != 10000 {
		t.Errorf("OpeningFloat = %d, want 10000 (first shift only)", info.OpeningFloat)
	}

	// Per-rate bucket summed per rate, never merged.
	if len(info.TaxByRate) != 1 || info.TaxByRate[0].Rate != 10 {
		t.Fatalf("TaxByRate = %+v, want a single 10%% bucket", info.TaxByRate)
	}
	if got, want := info.TaxByRate[0].Tax, 94+485+525; got != want {
		t.Errorf("10%% bucket tax = %d, want %d", got, want)
	}
	if got, want := info.TaxByRate[0].TaxableSales, 935+4845+5253; got != want {
		t.Errorf("10%% bucket taxable = %d, want %d", got, want)
	}

	// THE RECONCILIATION: the rate buckets cover item lines only, so the slip
	// must surface the service charge and its tax explicitly — otherwise the
	// printed columns don't add up to the totals above them.
	if want := info.TaxTotal - info.TaxByRate[0].Tax; info.ServiceChargeTax != want {
		t.Errorf("ServiceChargeTax = %d, want %d", info.ServiceChargeTax, want)
	}
	if want := info.Net - info.TaxByRate[0].TaxableSales; info.ServiceCharge != want {
		t.Errorf("ServiceCharge = %d, want %d", info.ServiceCharge, want)
	}
	// And with it, the columns reconcile exactly.
	if info.TaxByRate[0].Tax+info.ServiceChargeTax != info.TaxTotal {
		t.Errorf("tax column does not reconcile: %d + %d != %d",
			info.TaxByRate[0].Tax, info.ServiceChargeTax, info.TaxTotal)
	}
	if info.TaxByRate[0].TaxableSales+info.ServiceCharge != info.Net {
		t.Errorf("sales column does not reconcile: %d + %d != %d",
			info.TaxByRate[0].TaxableSales, info.ServiceCharge, info.Net)
	}

	// Tender split, largest first, labelled from the local mirror.
	if len(info.Payments) == 0 {
		t.Fatal("no tender rows")
	}
	if info.Payments[0].Code != "cash" {
		t.Errorf("first tender = %q, want cash (largest)", info.Payments[0].Code)
	}
	if info.Payments[0].Label != "現金" {
		t.Errorf("cash label = %q, want 現金", info.Payments[0].Label)
	}
	if got, want := info.Payments[0].Amount, 1081+5596+6067; got != want {
		t.Errorf("cash tender total = %d, want %d", got, want)
	}
}

// A workstation-written provisional snapshot uses {"expected": n} while Cloud
// uses {"expected_amount": n}; Cloud overwrites the local one on sync-UP, so a
// chain can hold both shapes at once and neither may be dropped.
func TestBuildChainReport_AcceptsBothTenderShapes(t *testing.T) {
	s := newLANPrintTestServer(t)
	exec := func(q string, args ...any) {
		t.Helper()
		if _, err := s.db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}
	exec(`INSERT INTO tills (id, branch_id, code) VALUES ('t1','br-1','0001')`)
	const chain = "chain-mixed"
	add := func(id string, seq int, tenders string) {
		exec(`INSERT INTO till_sessions
			(id, session_code, status, business_date, default_currency_code,
			 opening_float_amount, opened_at, closed_at, counted_cash, cash_variance,
			 till_id, branch_id, chain_id, chain_sequence, settlement_kind, settlement_snapshot)
			VALUES (?,?,'settled','2026-07-21','JPY',0,'2026-07-21T00:00:00Z','2026-07-21T01:00:00Z',
			 0,0,'t1','br-1',?,?,'handover',?)`,
			id, "WS-"+id, chain, seq,
			`{"cash":{"counted":0},"revenue":{"gross":0},"tenders":[`+tenders+`]}`)
	}
	add("a", 1, `{"tender_key":"cash","expected_amount":1000}`) // Cloud shape
	add("b", 2, `{"tender_key":"cash","expected":500}`)         // workstation shape

	info, err := s.buildChainReport(chain)
	if err != nil {
		t.Fatalf("buildChainReport: %v", err)
	}
	if len(info.Payments) != 1 {
		t.Fatalf("Payments = %+v, want one merged cash row", info.Payments)
	}
	if info.Payments[0].Amount != 1500 {
		t.Errorf("cash = %d, want 1500 (both shapes counted)", info.Payments[0].Amount)
	}
}

// plan-046 step 2 — the sections the chain slip previously could not show at
// all. Each is summed across the chain; discounts merge by coupon label and
// denominations by face value.
func TestBuildChainReport_AggregatesStep2Detail(t *testing.T) {
	s := newLANPrintTestServer(t)
	chainID := seedChain(t, s)

	info, err := s.buildChainReport(chainID)
	if err != nil {
		t.Fatalf("buildChainReport: %v", err)
	}

	if !info.HasDetail {
		t.Fatal("HasDetail = false, want true (all three members carry detail)")
	}
	if info.ItemCount != 2*3 {
		t.Errorf("ItemCount = %d, want 6", info.ItemCount)
	}
	if info.GuestCount != 3*3 {
		t.Errorf("GuestCount = %d, want 9", info.GuestCount)
	}
	if info.VoidUnpaidCount != 3 || info.VoidUnpaidAmount != 1500 {
		t.Errorf("voids unpaid = %d/%d, want 3/1500", info.VoidUnpaidCount, info.VoidUnpaidAmount)
	}
	if info.PaidInCount != 3 {
		t.Errorf("PaidInCount = %d, want 3", info.PaidInCount)
	}
	// Same coupon in all three shifts merges into ONE row.
	if len(info.Discounts) != 1 {
		t.Fatalf("Discounts = %+v, want one merged row", info.Discounts)
	}
	if info.Discounts[0].Label != "HAPPY15" || info.Discounts[0].Count != 3 || info.Discounts[0].Amount != 300 {
		t.Errorf("discount row = %+v, want HAPPY15 x3 = 300", info.Discounts[0])
	}
	// Same face value in all three shifts merges by value.
	if len(info.Denominations) != 1 {
		t.Fatalf("Denominations = %+v, want one merged row", info.Denominations)
	}
	// 金種 is the FINAL physical count for the same reason as レジ金額 — the
	// notes were re-counted at every handover, so merging the mixes would
	// multiply the same cash.
	if d := info.Denominations[0]; d.Value != 1000 || d.Quantity != 2 || d.Subtotal != 2000 {
		t.Errorf("denom row = %+v, want the final count 1000 x2 = 2000", d)
	}
}

// A chain settled BEFORE the enrichment carries none of those keys. It must
// report HasDetail=false so the slip omits the sections rather than printing
// zeros for figures nobody recorded.
func TestBuildChainReport_LegacyChainHasNoDetail(t *testing.T) {
	s := newLANPrintTestServer(t)
	exec := func(q string, args ...any) {
		t.Helper()
		if _, err := s.db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}
	exec(`INSERT INTO tills (id, branch_id, code) VALUES ('t1','br-1','0001')`)
	exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code,
		 opening_float_amount, opened_at, closed_at, counted_cash, cash_variance,
		 till_id, branch_id, chain_id, chain_sequence, settlement_kind, settlement_snapshot)
		VALUES ('old','WS-OLD','settled','2026-07-01','JPY',0,
		 '2026-07-01T00:00:00Z','2026-07-01T01:00:00Z',0,0,'t1','br-1','legacy',1,'final',
		 '{"cash":{"counted":1000},"revenue":{"gross":1000,"net":900,"tax":100}}')`)

	info, err := s.buildChainReport("legacy")
	if err != nil {
		t.Fatalf("buildChainReport: %v", err)
	}
	if info.HasDetail {
		t.Error("HasDetail = true on a pre-enrichment chain")
	}
	if len(info.Discounts) != 0 || len(info.Denominations) != 0 {
		t.Error("legacy chain fabricated discount/denomination rows")
	}
	// The money keys it DOES have must still work.
	if info.Gross != 1000 || info.Net != 900 || info.TaxTotal != 100 {
		t.Errorf("legacy money = %d/%d/%d, want 1000/900/100", info.Gross, info.Net, info.TaxTotal)
	}
}

// The chain's cash block must satisfy the physical identity of a single drawer
// handed from cashier to cashier:
//
//	first opening float + Σ cash sales + Σ paid-in − Σ paid-out == final count
//
// Verified against the real 3-shift chain: 10,000 + (1,081 + 5,596 + 6,067) =
// 22,744, which is exactly what the last cashier counted. Summing the per-shift
// counts instead yields 50,502 — the same banknotes reported once per shift.
func TestBuildChainReport_CashIdentityHoldsForOnePhysicalDrawer(t *testing.T) {
	s := newLANPrintTestServer(t)
	chainID := seedChain(t, s)

	info, err := s.buildChainReport(chainID)
	if err != nil {
		t.Fatalf("buildChainReport: %v", err)
	}

	got := info.OpeningFloat + info.CashSales + info.PaidIn - info.PaidOut
	if got != info.CountedCash {
		t.Errorf("cash identity broken: float %d + sales %d + in %d - out %d = %d, want the final count %d",
			info.OpeningFloat, info.CashSales, info.PaidIn, info.PaidOut, got, info.CountedCash)
	}
	// And the float is the chain's STARTING float, never a sum.
	if info.OpeningFloat != 10000 {
		t.Errorf("OpeningFloat = %d, want 10000 (the first shift's)", info.OpeningFloat)
	}
}
