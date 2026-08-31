package handler

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
	_ "time/tzdata" // embed tz db so LoadLocation works in the test binary on any OS
)

// TestFmtShiftTime_ConvertsToShopZone proves the timezone fix: a stored UTC
// timestamp renders in the shop zone, not the process zone. 07:57 UTC → 16:57
// JST (the 9h Japan offset that was previously printed wrong).
func TestFmtShiftTime_ConvertsToShopZone(t *testing.T) {
	jst := time.FixedZone("JST", 9*60*60)
	if got := fmtShiftTime("2026-07-03T07:57:00Z", jst); got != "2026/07/03 16:57" {
		t.Errorf("JST: got %q, want 2026/07/03 16:57", got)
	}
	if got := fmtShiftTime("2026-07-03T07:57:00Z", time.UTC); got != "2026/07/03 07:57" {
		t.Errorf("UTC: got %q, want 2026/07/03 07:57", got)
	}
	// RFC3339Nano fractional seconds also parse + convert.
	if got := fmtShiftTime("2026-07-03T07:57:00.123456Z", jst); got != "2026/07/03 16:57" {
		t.Errorf("JST nano: got %q, want 2026/07/03 16:57", got)
	}
	// ISO8601 with a numeric +00:00 offset (Laravel toIso8601String).
	if got := fmtShiftTime("2026-07-03T07:57:00+00:00", jst); got != "2026/07/03 16:57" {
		t.Errorf("ISO offset: got %q, want 2026/07/03 16:57", got)
	}
	// Naive MySQL/Cloud datetime (space, no zone) — the format that previously
	// fell through to the raw substring and printed unconverted UTC.
	if got := fmtShiftTime("2026-07-03 07:57:00", jst); got != "2026/07/03 16:57" {
		t.Errorf("naive space: got %q, want 2026/07/03 16:57", got)
	}
	// Naive with trailing fractional seconds (auto-consumed by the parser).
	if got := fmtShiftTime("2026-07-03 07:57:00.000000", jst); got != "2026/07/03 16:57" {
		t.Errorf("naive space frac: got %q, want 2026/07/03 16:57", got)
	}
	// Naive with a T separator, no zone.
	if got := fmtShiftTime("2026-07-03T07:57:00", jst); got != "2026/07/03 16:57" {
		t.Errorf("naive T: got %q, want 2026/07/03 16:57", got)
	}
	// Hybrid: space separator WITH a Z suffix — the shape the previous
	// layout-list parser missed, causing the open time to print raw UTC.
	if got := fmtShiftTime("2026-07-03 07:57:00Z", jst); got != "2026/07/03 16:57" {
		t.Errorf("space+Z: got %q, want 2026/07/03 16:57", got)
	}
	if got := fmtShiftTime("2026-07-03 07:57:00.000000Z", jst); got != "2026/07/03 16:57" {
		t.Errorf("space+frac+Z: got %q, want 2026/07/03 16:57", got)
	}
	// Same instant must render identically no matter which shape it's stored in
	// — this is the open-vs-close consistency guarantee.
	shapes := []string{
		"2026-07-03T07:57:00Z",
		"2026-07-03T07:57:00+00:00",
		"2026-07-03T07:57:00.123456789Z",
		"2026-07-03 07:57:00",
		"2026-07-03 07:57:00Z",
		"2026-07-03T07:57:00",
	}
	for _, sh := range shapes {
		if got := fmtShiftTime(sh, jst); got != "2026/07/03 16:57" {
			t.Errorf("shape %q: got %q, want 2026/07/03 16:57", sh, got)
		}
	}
	if got := fmtShiftTime("", jst); got != "" {
		t.Errorf("empty: got %q, want empty", got)
	}
}

// TestResolveShopLocation covers the three branches deterministically (no
// dependence on the test machine's own timezone).
func TestResolveShopLocation(t *testing.T) {
	ict := time.FixedZone("ICT", 7*60*60) // a real, non-UTC machine zone
	fmt := "15:04"
	utc0757, _ := time.Parse(time.RFC3339, "2026-07-03T07:57:00Z")

	// 1) Real machine zone wins even when the branch is registered elsewhere.
	if loc := resolveShopLocation(ict, "Asia/Tokyo"); utc0757.In(loc).Format(fmt) != "14:57" {
		t.Errorf("machine +07 vs branch Tokyo: got %q, want 14:57 (machine wins)",
			utc0757.In(loc).Format(fmt))
	}
	// 2) UTC machine (misconfigured) → trust the registered branch timezone.
	if loc := resolveShopLocation(time.UTC, "Asia/Tokyo"); utc0757.In(loc).Format(fmt) != "16:57" {
		t.Errorf("UTC machine + branch Tokyo: got %q, want 16:57 (JST safety net)",
			utc0757.In(loc).Format(fmt))
	}
	// 3) UTC machine, no branch tz → stays UTC.
	if loc := resolveShopLocation(time.UTC, ""); utc0757.In(loc).Format(fmt) != "07:57" {
		t.Errorf("UTC machine, no branch tz: got %q, want 07:57", utc0757.In(loc).Format(fmt))
	}
}

// TestShopLocation_UsesMachineZone verifies shopLocation() returns the process
// OS zone when that zone is real (non-UTC) — the case for a shop PC.
func TestShopLocation_UsesMachineZone(t *testing.T) {
	if _, off := time.Now().Zone(); off == 0 {
		t.Skip("test machine is UTC; machine-zone preference not exercised")
	}
	s := newLANPrintTestServer(t)
	if loc := s.shopLocation(); loc != time.Local {
		t.Errorf("shopLocation = %v, want time.Local (%v) on a non-UTC machine", loc, time.Local)
	}
}

// seedShiftForReport inserts a settled session with two paid orders so
// buildShiftReport has a realistic dataset to aggregate. Window is
// 16:57 → 17:09 on 2026/07/03, matching the reference slip.
func seedShiftForReport(t *testing.T, s *Server) string {
	t.Helper()
	db := s.db.Conn()
	const (
		sessionID = "sess-1"
		tillID    = "till-1"
		opened    = "2026-07-03T16:57:00Z"
		closed    = "2026-07-03T17:09:00Z"
		paidAt    = "2026-07-03T17:00:00Z"
	)
	exec := func(q string, args ...any) {
		if _, err := db.Exec(q, args...); err != nil {
			t.Fatalf("seed exec: %v\n%s", err, q)
		}
	}

	exec(`INSERT INTO tills (id, branch_id, code) VALUES (?, 'br-1', '0001')`, tillID)
	exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code,
		 opening_float_amount, opened_at, closed_at, counted_cash, cash_variance,
		 till_id, branch_id, opener_name)
		VALUES (?, 'SHIFT-1', 'settled', '2026-07-03', 'JPY',
		 0, ?, ?, 2000, 0, ?, 'br-1', '田中')`,
		sessionID, opened, closed, tillID)

	exec(`INSERT INTO payment_methods (id, code, name) VALUES ('pm-cash','cash','現金')`)
	exec(`INSERT INTO payment_methods (id, code, name) VALUES ('pm-card','card','クレジットカード')`)

	// Order 1 — cash, 2 items, 2 guests.
	exec(`INSERT INTO orders (id, order_code, status, opened_at, guest_count,
		subtotal, discount_amount, tax_amount, total_amount, created_at)
		VALUES ('o1','WS-1','closed',?,2, 1818,0,182,2000,?)`, opened, paidAt)
	exec(`INSERT INTO order_items (id, customer_order_id, quantity, status) VALUES ('i1','o1',1,'served')`)
	exec(`INSERT INTO order_items (id, customer_order_id, quantity, status) VALUES ('i2','o1',1,'served')`)
	exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('p1','o1','cash',2000,'confirmed',?)`, paidAt)

	// Order 2 — card, 3 items, 3 guests, ¥225 coupon discount.
	exec(`INSERT INTO orders (id, order_code, status, opened_at, guest_count,
		subtotal, discount_amount, tax_amount, total_amount, created_at)
		VALUES ('o2','WS-2','closed',?,3, 1642,225,133,1775,?)`, opened, paidAt)
	exec(`INSERT INTO order_items (id, customer_order_id, quantity, status) VALUES ('i3','o2',3,'served')`)
	exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('p2','o2','card',1775,'confirmed',?)`, paidAt)

	return sessionID
}

func TestBuildShiftReport_Aggregates(t *testing.T) {
	s := newLANPrintTestServer(t)
	sessionID := seedShiftForReport(t, s)

	info, err := s.buildShiftReport(sessionID)
	if err != nil {
		t.Fatalf("buildShiftReport: %v", err)
	}

	if info.TillCode != "0001" {
		t.Errorf("TillCode = %q, want 0001", info.TillCode)
	}
	if info.GrossSales != 3775 {
		t.Errorf("GrossSales = %d, want 3775", info.GrossSales)
	}
	if info.TaxTotal != 315 {
		t.Errorf("TaxTotal = %d, want 315", info.TaxTotal)
	}
	if info.NetSales != 3460 {
		t.Errorf("NetSales = %d, want 3460", info.NetSales)
	}
	if info.ItemCount != 5 {
		t.Errorf("ItemCount = %d, want 5", info.ItemCount)
	}
	if info.GuestCount != 5 {
		t.Errorf("GuestCount = %d, want 5", info.GuestCount)
	}
	if info.CheckCount != 2 {
		t.Errorf("CheckCount = %d, want 2", info.CheckCount)
	}
	if info.CountedCash != 2000 || info.ExpectedCash != 2000 || info.CashVariance != 0 {
		t.Errorf("drawer check = counted %d / expected %d / variance %d, want 2000/2000/0",
			info.CountedCash, info.ExpectedCash, info.CashVariance)
	}
	if info.Operator != "田中" {
		t.Errorf("Operator = %q, want 田中", info.Operator)
	}
	if info.DiscountTotalAmount != 225 || info.DiscountTotalCount != 1 {
		t.Errorf("discount total = %d x%d, want 225 x1", info.DiscountTotalAmount, info.DiscountTotalCount)
	}

	// Payments sorted by amount desc → cash (2000) then card (1775).
	if len(info.Payments) != 2 {
		t.Fatalf("Payments len = %d, want 2 (%+v)", len(info.Payments), info.Payments)
	}
	if info.Payments[0].Label != "現金" || info.Payments[0].Amount != 2000 || info.Payments[0].Count != 1 {
		t.Errorf("Payments[0] = %+v, want 現金/2000/1", info.Payments[0])
	}
	if info.Payments[1].Label != "クレジットカード" || info.Payments[1].Amount != 1775 {
		t.Errorf("Payments[1] = %+v, want クレジットカード/1775", info.Payments[1])
	}
}

// TestBuildShiftReport_JSTOpenedAt_UTCPayments reproduces the reported bug: the
// session's opened_at/closed_at carry a Cloud +09:00 offset while payments are
// stored UTC "Z". A raw lexical window compare ("08:00…Z" >= "16:57…+09:00" is
// false) wrongly excluded every payment, emptying 支払方法 + the sales summary.
// The substr(...,1,19) + normalizeInstant window must now include them.
func TestBuildShiftReport_JSTOpenedAt_UTCPayments(t *testing.T) {
	s := newLANPrintTestServer(t)
	db := s.db.Conn()
	exec := func(q string, args ...any) {
		if _, err := db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v\n%s", err, q)
		}
	}
	// Window: 16:57→17:09 JST = 07:57→08:09 UTC.
	exec(`INSERT INTO tills (id, branch_id, code) VALUES ('t1','br-1','0001')`)
	exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code,
		 opening_float_amount, opened_at, closed_at, counted_cash, cash_variance, till_id, branch_id)
		VALUES ('s1','SH-1','settled','2026-07-06','JPY',
		 0, '2026-07-06T16:57:00+09:00', '2026-07-06T17:09:00+09:00', 2000, 0, 't1', 'br-1')`)
	exec(`INSERT INTO payment_methods (id, code, name) VALUES ('pm','cash','現金')`)
	exec(`INSERT INTO orders (id, order_code, status, opened_at, guest_count,
		subtotal, discount_amount, tax_amount, total_amount, created_at)
		VALUES ('o1','WS-1','closed','2026-07-06T08:00:00Z',1, 1818,0,182,2000,'2026-07-06T08:00:00Z')`)
	exec(`INSERT INTO order_items (id, customer_order_id, quantity, status) VALUES ('i1','o1',1,'served')`)
	// Payment stored UTC "Z", 08:00 UTC (17:00 JST) — inside the window.
	exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('p1','o1','cash',2000,'confirmed','2026-07-06T08:00:00.123456Z')`)

	info, err := s.buildShiftReport("s1")
	if err != nil {
		t.Fatalf("buildShiftReport: %v", err)
	}
	if len(info.Payments) != 1 {
		t.Fatalf("支払方法 empty despite an in-window payment — the +09:00 vs Z window bug (%+v)", info.Payments)
	}
	if info.Payments[0].Amount != 2000 {
		t.Errorf("payment amount = %d, want 2000", info.Payments[0].Amount)
	}
	if info.GrossSales != 2000 || info.ItemCount != 1 {
		t.Errorf("sales summary wrong: gross %d (want 2000), items %d (want 1)", info.GrossSales, info.ItemCount)
	}
}

func TestBuildShiftReport_404OnMissingSession(t *testing.T) {
	s := newLANPrintTestServer(t)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/shift-report",
		bytes.NewBufferString(`{"session_id":"nope"}`))
	req = stubAuth(req)
	s.handleLANPrintShiftReport(w, req)
	if w.Code != http.StatusNotFound {
		t.Errorf("want 404 for unknown session, got %d (%s)", w.Code, w.Body.String())
	}
}

func TestHandleShiftReport_400OnMissingSessionID(t *testing.T) {
	s := newLANPrintTestServer(t)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/lan/print/shift-report",
		bytes.NewBufferString(`{}`))
	req = stubAuth(req)
	s.handleLANPrintShiftReport(w, req)
	if w.Code != http.StatusBadRequest {
		t.Errorf("want 400, got %d (%s)", w.Code, w.Body.String())
	}
}

// TestBuildShiftReport_PerRateBreakdown proves the per-rate 消費税内訳 rows
// (plan-043 T4.2) are derived from the shift's order_items tax snapshots and
// gated on the close_report_tax_breakdown setting.
//
// Two orders paid in-window: a mixed takeaway (8% bentō + 10% beer) and a
// dine-in (10% pho). Expected aggregate:
//
//	8%:  taxable 1,000  tax 80
//	10%: taxable  500 + 1,000 = 1,500  tax 50 + 100 = 150
func TestBuildShiftReport_PerRateBreakdown(t *testing.T) {
	s := newLANPrintTestServer(t)
	db := s.db.Conn()
	exec := func(q string, args ...any) {
		if _, err := db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v\n%s", err, q)
		}
	}
	const (
		opened = "2026-07-03T16:57:00Z"
		closed = "2026-07-03T17:09:00Z"
		paidAt = "2026-07-03T17:00:00Z"
	)
	exec(`INSERT INTO tills (id, branch_id, code) VALUES ('t1','br-1','0001')`)
	exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code,
		 opening_float_amount, opened_at, closed_at, counted_cash, cash_variance, till_id, branch_id)
		VALUES ('s1','SH-1','settled','2026-07-03','JPY',
		 0, ?, ?, 2000, 0, 't1', 'br-1')`, opened, closed)
	exec(`INSERT INTO payment_methods (id, code, name) VALUES ('pm','cash','現金')`)

	// Order 1 — takeaway, 8% bentō + 10% beer.
	exec(`INSERT INTO orders (id, order_code, status, opened_at, guest_count,
		subtotal, discount_amount, tax_amount, total_amount, created_at)
		VALUES ('o1','WS-1','closed',?,1, 1500,0,130,1630,?)`, opened, paidAt)
	exec(`INSERT INTO order_items (id, customer_order_id, quantity, unit_price, subtotal, status, tax_rate, tax_amount)
		VALUES ('i1','o1',1,1000,1000,'served',8,80)`)
	exec(`INSERT INTO order_items (id, customer_order_id, quantity, unit_price, subtotal, status, tax_rate, tax_amount)
		VALUES ('i2','o1',1,500,500,'served',10,50)`)
	exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('p1','o1','cash',1630,'confirmed',?)`, paidAt)

	// Order 2 — dine-in, 10% pho.
	exec(`INSERT INTO orders (id, order_code, status, opened_at, guest_count,
		subtotal, discount_amount, tax_amount, total_amount, created_at)
		VALUES ('o2','WS-2','closed',?,1, 1000,0,100,1100,?)`, opened, paidAt)
	exec(`INSERT INTO order_items (id, customer_order_id, quantity, unit_price, subtotal, status, tax_rate, tax_amount)
		VALUES ('i3','o2',1,1000,1000,'served',10,100)`)
	exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('p2','o2','cash',1100,'confirmed',?)`, paidAt)

	info, err := s.buildShiftReport("s1")
	if err != nil {
		t.Fatalf("buildShiftReport: %v", err)
	}

	if !info.ShowTaxBreakdown {
		t.Fatalf("ShowTaxBreakdown should default true (close_report_tax_breakdown)")
	}
	if len(info.TaxBreakdown) != 2 {
		t.Fatalf("TaxBreakdown len = %d, want 2 (%+v)", len(info.TaxBreakdown), info.TaxBreakdown)
	}
	// Sorted by rate ascending: 8% then 10%.
	if info.TaxBreakdown[0].Rate != 8 || info.TaxBreakdown[0].TaxableSales != 1000 || info.TaxBreakdown[0].Tax != 80 {
		t.Errorf("8%% row = %+v, want rate 8 / taxable 1000 / tax 80", info.TaxBreakdown[0])
	}
	if info.TaxBreakdown[1].Rate != 10 || info.TaxBreakdown[1].TaxableSales != 1500 || info.TaxBreakdown[1].Tax != 150 {
		t.Errorf("10%% row = %+v, want rate 10 / taxable 1500 / tax 150", info.TaxBreakdown[1])
	}
}

// A single order with THREE ¥333 lines all at 8% must show the group-once tax
// (round(999×0.08)=80), NOT the per-line-summed 81 — even though each line's
// stored tax_amount snapshot is the per-line-rounded 27. Proves the Z-report
// recomputes once per (order, rate) instead of trusting the per-line snapshots,
// and that the breakdown reconciles to Σ order.tax_amount.
func TestBuildShiftReport_PerRateBreakdown_GroupOnceMultiLine(t *testing.T) {
	s := newLANPrintTestServer(t)
	db := s.db.Conn()
	exec := func(q string, args ...any) {
		if _, err := db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v\n%s", err, q)
		}
	}
	const (
		opened = "2026-07-03T16:57:00Z"
		closed = "2026-07-03T17:09:00Z"
		paidAt = "2026-07-03T17:00:00Z"
	)
	exec(`INSERT INTO tills (id, branch_id, code) VALUES ('t1','br-1','0001')`)
	exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code,
		 opening_float_amount, opened_at, closed_at, counted_cash, cash_variance, till_id, branch_id)
		VALUES ('s1','SH-1','settled','2026-07-03','JPY',
		 0, ?, ?, 1079, 0, 't1', 'br-1')`, opened, closed)
	exec(`INSERT INTO payment_methods (id, code, name) VALUES ('pm','cash','現金')`)

	// One order, three ¥333 lines @8% → group tax round(999×0.08)=80. Each line's
	// stored tax_amount is the WRONG per-line-rounded 27 (Σ=81); the report must
	// ignore it and recompute the group-once 80. order.tax_amount = 80.
	exec(`INSERT INTO orders (id, order_code, status, opened_at, guest_count,
		subtotal, discount_amount, tax_amount, total_amount, created_at)
		VALUES ('o1','WS-1','closed',?,1, 999,0,80,1079,?)`, opened, paidAt)
	for _, id := range []string{"i1", "i2", "i3"} {
		exec(`INSERT INTO order_items (id, customer_order_id, quantity, unit_price, subtotal, status, tax_rate, tax_amount)
			VALUES (?, 'o1', 1, 333, 333, 'served', 8, 27)`, id)
	}
	exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('p1','o1','cash',1079,'confirmed',?)`, paidAt)

	info, err := s.buildShiftReport("s1")
	if err != nil {
		t.Fatalf("buildShiftReport: %v", err)
	}
	if len(info.TaxBreakdown) != 1 {
		t.Fatalf("TaxBreakdown len = %d, want 1 (%+v)", len(info.TaxBreakdown), info.TaxBreakdown)
	}
	row := info.TaxBreakdown[0]
	if row.Rate != 8 || row.TaxableSales != 999 || row.Tax != 80 {
		t.Errorf("8%% row = %+v, want rate 8 / taxable 999 / tax 80 (group-once, not per-line 81)", row)
	}
	if row.Tax == 81 {
		t.Errorf("breakdown summed the per-line snapshots (81) instead of rounding once per group (80)")
	}
	// Breakdown tax must reconcile to Σ order.tax_amount.
	if row.Tax != info.TaxTotal {
		t.Errorf("breakdown tax %d != order TaxTotal %d — must reconcile", row.Tax, info.TaxTotal)
	}
}

// The Z-slip must describe the SAME money Cloud reconciles by. buildShiftReport
// filtered payments on a pure time window while reconcileSession — the
// expected-cash half of the very same slip — already filtered on
// till_session_id, so one accounting document mixed two different sets of
// payments, and Σ per-shift slips could not equal the chain aggregate.
//
// Attribution-first fixes both directions; the unattributed fallback keeps a
// best-effort-unstamped row from vanishing out of the drawer.
func TestBuildShiftReport_AttributionBeatsTheTimeWindow(t *testing.T) {
	s := newLANPrintTestServer(t)
	db := s.db
	exec := func(q string, args ...any) {
		t.Helper()
		if _, err := db.Exec(q, args...); err != nil {
			t.Fatalf("seed exec: %v\n%s", err, q)
		}
	}

	const (
		opened   = "2026-07-03T07:00:00Z"
		closed   = "2026-07-03T17:00:00Z"
		beforeOp = "2026-07-03T06:30:00Z" // BEFORE the shift opened
		inside   = "2026-07-03T10:00:00Z"
	)

	exec(`INSERT INTO tills (id, branch_id, code) VALUES ('t1','br-1','0001')`)
	exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code,
		 opening_float_amount, opened_at, closed_at, counted_cash, cash_variance,
		 till_id, branch_id)
		VALUES ('s1','SHIFT-1','settled','2026-07-03','JPY',0,?,?,0,0,'t1','br-1')`,
		opened, closed)
	exec(`INSERT INTO payment_methods (id, code, name) VALUES ('pm-cash','cash','現金')`)

	order := func(id string, total int, paidAt string) {
		exec(`INSERT INTO orders (id, order_code, status, opened_at, guest_count,
			subtotal, discount_amount, tax_amount, total_amount, created_at)
			VALUES (?,?, 'closed', ?, 1, ?, 0, 0, ?, ?)`,
			id, "WS-"+id, opened, total, total, paidAt)
	}
	// (a) claimed GAP payment: collected before this shift opened, then stamped
	//     to it at open — outside the window, but it is this drawer's money.
	order("gap", 1000, beforeOp)
	exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at, till_session_id)
		VALUES ('p-gap','gap','cash',1000,'confirmed',?, 's1')`, beforeOp)
	// (b) inside the window but already owned by ANOTHER shift — double-count risk.
	order("other", 2000, inside)
	exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at, till_session_id)
		VALUES ('p-other','other','cash',2000,'confirmed',?, 's-other')`, inside)
	// (c) unattributed legacy row inside the window — must still be counted.
	order("legacy", 500, inside)
	exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('p-legacy','legacy','cash',500,'confirmed',?)`, inside)

	info, err := s.buildShiftReport("s1")
	if err != nil {
		t.Fatalf("buildShiftReport: %v", err)
	}

	// 1000 (claimed gap, outside window) + 500 (unattributed fallback).
	// The 2000 owned by another shift must NOT appear.
	if info.GrossSales != 1500 {
		t.Errorf("GrossSales = %d, want 1500 (gap 1000 + legacy 500; other shift's 2000 excluded)",
			info.GrossSales)
	}
	if info.CheckCount != 2 {
		t.Errorf("CheckCount = %d, want 2", info.CheckCount)
	}
	var cash int
	for _, line := range info.Payments {
		if line.Code == "cash" {
			cash = line.Amount
		}
	}
	if cash != 1500 {
		t.Errorf("cash tender total = %d, want 1500", cash)
	}
}
