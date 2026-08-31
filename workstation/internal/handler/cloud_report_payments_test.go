package handler

import (
	"encoding/json"
	"testing"
	"time"
)

// #2934 — an online payment is Cloud revenue, not drawer money. The shift slip
// must include its order/method while expected cash stays byte-for-byte driven
// by local payments only.
func TestBuildShiftReport_IncludesCloudStripeAndPayPayWithoutMovingDrawerCash(t *testing.T) {
	s := newLANPrintTestServer(t)
	sessionID := seedShiftForReport(t, s)
	db := s.db.Conn()

	// A local card payment echoed by Cloud must be deduped by payments.cloud_id.
	// This is the normal sync-up → pull-down round trip, not a synthetic edge.
	mustExec(t, db, `UPDATE orders SET cloud_id = 'cloud-o2', cloud_till_session_id = ?,
		cloud_payment_summary = ? WHERE id = 'o2'`, sessionID,
		`[{"id":"cloud-p2","payment_method_code":"card","payment_method_name":"Card","amount":1775,"status":"succeeded","paid_at":"2026-07-03T17:00:00Z"}]`)
	mustExec(t, db, `UPDATE payments SET cloud_id = 'cloud-p2' WHERE id = 'p2'`)

	// Online-only order: two provider transactions, no local payments row.
	// Refunded/failed entries are controls and must not re-enter revenue.
	mustExec(t, db, `INSERT INTO orders
		(id, cloud_id, order_code, status, opened_at, guest_count, subtotal,
		 discount_amount, tax_amount, total_amount, branch_id,
		 cloud_till_session_id, cloud_payment_summary, created_at)
		VALUES ('cloud-online','cloud-online','WEB-1','closed','2026-07-03T17:01:00Z',2,
		 1900,0,100,2000,'br-1',?,?,'2026-07-03T17:01:00Z')`, sessionID,
		`[
		 {"id":"stripe-online","payment_method_code":"stripe","payment_method_name":"Stripe","amount":"1500.00","net_amount":"1200.00","status":"refunded","paid_at":"2026-07-03T17:02:00Z","refunds":[{"id":"stripe-online-r1","amount":-300,"status":"succeeded","paid_at":"2026-07-04T01:00:00Z"}]},
		 {"id":"paypay-online","payment_method_code":"paypay","payment_method_name":"PayPay","amount":800,"status":"confirmed","paid_at":"2026-07-03T17:03:00Z"},
		 {"id":"stripe-refunded","payment_method_code":"stripe","amount":500,"net_amount":0,"status":"refunded","paid_at":"2026-07-03T17:04:00Z","refunds":[{"id":"stripe-refunded-r1","amount":-500,"status":"succeeded","paid_at":"2026-07-03T17:05:00Z"}]},
		 {"id":"paypay-failed","payment_method_code":"paypay","amount":900,"status":"failed","paid_at":"2026-07-03T17:05:00Z"}
		]`)
	mustExec(t, db, `INSERT INTO order_items
		(id, customer_order_id, quantity, subtotal, tax_rate, status)
		VALUES ('online-item','cloud-online',2,1900,10,'served')`)
	mustExec(t, db, `INSERT INTO order_conditions
		(id,conditionable_type,conditionable_id,type,rate,amount,taxable_base)
		VALUES ('online-tax','order','cloud-online','tax',10,100,1900)`)
	// The sync-up/pull-down sibling carries the same summary. Family collapse
	// must keep gross/items/payment methods single-counted.
	mustExec(t, db, `INSERT INTO orders
		(id,cloud_id,order_code,status,opened_at,guest_count,subtotal,discount_amount,
		 tax_amount,total_amount,branch_id,cloud_till_session_id,cloud_payment_summary,created_at)
		SELECT 'local-online',cloud_id,'WS-ONLINE',status,opened_at,guest_count,subtotal,
		 discount_amount,tax_amount,total_amount,branch_id,cloud_till_session_id,
		 cloud_payment_summary,created_at FROM orders WHERE id = 'cloud-online'`)

	// Same clock window, different attributed shift: must not leak into sess-1.
	mustExec(t, db, `INSERT INTO orders
		(id, cloud_id, order_code, status, opened_at, guest_count, total_amount,
		 branch_id, cloud_till_session_id, cloud_payment_summary, created_at)
		VALUES ('cloud-other','cloud-other','WEB-OTHER','closed','2026-07-03T17:02:00Z',9,9999,
		 'br-1','sess-other',?,'2026-07-03T17:02:00Z')`,
		`[{"id":"stripe-other","payment_method_code":"stripe","amount":9999,"status":"succeeded","paid_at":"2026-07-03T17:02:00Z"}]`)

	info, err := s.buildShiftReport(sessionID)
	if err != nil {
		t.Fatalf("buildShiftReport: %v", err)
	}
	if info.GrossSales != 5775 || info.TaxTotal != 415 || info.NetSales != 5360 {
		t.Errorf("online order missing from sales: gross/tax/net = %d/%d/%d, want 5775/415/5360",
			info.GrossSales, info.TaxTotal, info.NetSales)
	}
	if info.CheckCount != 3 || info.GuestCount != 7 || info.ItemCount != 7 {
		t.Errorf("online order scope = checks/guests/items %d/%d/%d, want 3/7/7",
			info.CheckCount, info.GuestCount, info.ItemCount)
	}
	if info.ExpectedCash != 2000 || info.CountedCash != 2000 || info.CashVariance != 0 {
		t.Errorf("online money moved drawer: expected/counted/variance = %d/%d/%d, want 2000/2000/0",
			info.ExpectedCash, info.CountedCash, info.CashVariance)
	}
	foundOnlineTax := false
	for _, bucket := range info.TaxBreakdown {
		if bucket.Rate == 10 && bucket.Tax == 100 && bucket.TaxableSales == 1900 {
			foundOnlineTax = true
		}
	}
	if !foundOnlineTax {
		t.Errorf("online tax ledger missing from tax breakdown: %+v", info.TaxBreakdown)
	}

	methods := map[string]struct{ amount, count int }{}
	for _, line := range info.Payments {
		methods[line.Code] = struct{ amount, count int }{line.Amount, line.Count}
	}
	for code, want := range map[string]struct{ amount, count int }{
		"cash":   {2000, 1},
		"card":   {1775, 1}, // cloud-p2 echo did not double this to 3550
		"stripe": {1200, 4}, // 2 sales + 2 signed refund rows, net ¥1200
		"paypay": {800, 1},
	} {
		if got := methods[code]; got != want {
			t.Errorf("payment method %s = %+v, want %+v", code, got, want)
		}
	}
	if len(methods) != 4 {
		t.Errorf("payment methods = %+v, want exactly cash/card/stripe/paypay", methods)
	}

	var localOnlineRows int
	if err := db.QueryRow(`SELECT COUNT(*) FROM payments WHERE order_id = 'cloud-online'`).Scan(&localOnlineRows); err != nil {
		t.Fatalf("count local online rows: %v", err)
	}
	if localOnlineRows != 0 {
		t.Fatalf("online payments were materialised into drawer ledger: %d rows", localOnlineRows)
	}
}

func TestBuildLocalSettlementSnapshot_IncludesCloudRevenueButNotCashSales(t *testing.T) {
	s := newLANPrintTestServer(t)
	sessionID := seedShiftForReport(t, s)
	db := s.db.Conn()
	mustExec(t, db, `INSERT INTO orders
		(id, cloud_id, order_code, status, opened_at, guest_count, subtotal, tax_amount,
		 total_amount, branch_id, cloud_till_session_id, cloud_payment_summary, created_at)
		VALUES ('cloud-snapshot','cloud-snapshot','WEB-S','closed','2026-07-03T17:01:00Z',1,
		 900,100,1000,'br-1',?,?,'2026-07-03T17:01:00Z')`, sessionID,
		`[{"id":"stripe-snapshot","payment_method_code":"stripe","payment_method_name":"Stripe","amount":1000,"status":"succeeded","paid_at":"2026-07-03T17:02:00Z"}]`)
	mustExec(t, db, `INSERT INTO order_items (id,customer_order_id,quantity,status)
		VALUES ('snapshot-item','cloud-snapshot',1,'served')`)

	recon, err := s.reconcileSession(sessionID)
	if err != nil {
		t.Fatalf("reconcileSession: %v", err)
	}
	settledAt, _ := time.Parse(time.RFC3339, "2026-07-03T17:09:00Z")
	blob, err := s.buildLocalSettlementSnapshot(sessionID, recon, 2000, 0, settledAt, nil)
	if err != nil {
		t.Fatalf("buildLocalSettlementSnapshot: %v", err)
	}
	var snap struct {
		Revenue struct {
			Gross float64 `json:"gross"`
		} `json:"revenue"`
		Cash struct {
			Sales    float64 `json:"sales"`
			Expected float64 `json:"expected"`
		} `json:"cash"`
		Orders struct {
			PaidCount int `json:"paid_count"`
		} `json:"orders"`
		Payments []struct {
			Code   string  `json:"code"`
			Amount float64 `json:"amount"`
		} `json:"payments"`
	}
	if err := json.Unmarshal([]byte(blob), &snap); err != nil {
		t.Fatalf("snapshot JSON: %v", err)
	}
	if snap.Revenue.Gross != 4775 || snap.Orders.PaidCount != 3 {
		t.Errorf("snapshot revenue/count = %.0f/%d, want 4775/3", snap.Revenue.Gross, snap.Orders.PaidCount)
	}
	if snap.Cash.Sales != 2000 || snap.Cash.Expected != 2000 {
		t.Errorf("snapshot drawer changed by Stripe: sales/expected %.0f/%.0f, want 2000/2000",
			snap.Cash.Sales, snap.Cash.Expected)
	}
	foundStripe := false
	for _, payment := range snap.Payments {
		if payment.Code == "stripe" && payment.Amount == 1000 {
			foundStripe = true
		}
	}
	if !foundStripe {
		t.Errorf("snapshot payment methods missing Stripe: %s", blob)
	}
}

func TestRevenueByPaymentMethod_MergesOnlineWindowAndDedupesCloudEcho(t *testing.T) {
	s := newLANPrintTestServer(t)
	db := s.db.Conn()
	mustExec(t, db, `INSERT OR REPLACE INTO settings (key,value) VALUES ('workstation_branch_id','br-1')`)
	s.setWorkstationBranchIDSnapshot("br-1")
	mustExec(t, db, `INSERT INTO payment_methods (id,code,name) VALUES
		('pm-cash-online-report','cash','Cash'),
		('pm-stripe-online-report','stripe','Stripe'),
		('pm-paypay-online-report','paypay','PayPay')`)

	// Local payment plus its Cloud echo: still one ¥1000 cash transaction.
	mustExec(t, db, `INSERT INTO orders
		(id,cloud_id,status,branch_id,created_at,cloud_payment_summary)
		VALUES ('local-order','cloud-local-order','closed','br-1','2026-08-13T03:00:00Z',?)`,
		`[{"id":"cloud-local-payment","payment_method_code":"cash","amount":1000,"status":"succeeded","paid_at":"2026-08-13T03:00:00Z"}]`)
	mustExec(t, db, `INSERT INTO payments
		(id,cloud_id,order_id,payment_method,status,amount,created_at)
		VALUES ('local-payment','cloud-local-payment','local-order','cash','succeeded',1000,'2026-08-13T03:00:00Z')`)

	// Online-only payments inside the requested day, plus three negative controls.
	mustExec(t, db, `INSERT INTO orders
		(id,cloud_id,status,branch_id,created_at,cloud_payment_summary)
		VALUES ('online-day','online-day','closed','br-1','2026-08-13T04:00:00Z',?)`,
		`[
		 {"id":"stripe-day","payment_method_code":"stripe","payment_method_name":"Stripe Cloud","amount":4000,"net_amount":3000,"status":"refunded","paid_at":"2026-08-13T04:00:00Z","refunds":[{"id":"stripe-day-r1","amount":-500,"status":"succeeded","paid_at":"2026-08-13T08:00:00Z"},{"id":"stripe-day-r2","amount":-500,"status":"succeeded","paid_at":"2026-08-14T08:00:00Z"}]},
		 {"id":"paypay-day","payment_method_code":"paypay","payment_method_name":"PayPay Cloud","amount":"2000.00","status":"confirmed","paid_at":"2026-08-13T05:00:00+00:00"},
		 {"id":"stripe-refund","payment_method_code":"stripe","amount":700,"net_amount":0,"status":"refunded","paid_at":"2026-08-13T06:00:00Z","refunds":[{"id":"stripe-refund-r1","amount":-700,"status":"succeeded","paid_at":"2026-08-13T06:30:00Z"}]},
		 {"id":"legacy-refund-without-net","payment_method_code":"stripe","amount":600,"status":"refunded","paid_at":"2026-08-13T06:30:00Z"},
		 {"id":"stripe-pending","payment_method_code":"stripe","amount":800,"status":"pending","paid_at":"2026-08-13T07:00:00Z"},
		 {"id":"stripe-tomorrow","payment_method_code":"stripe","amount":900,"status":"succeeded","paid_at":"2026-08-14T01:00:00Z"}
		]`)
	mustExec(t, db, `INSERT INTO orders
		(id,cloud_id,status,branch_id,created_at,cloud_payment_summary)
		VALUES ('other-branch','other-branch','closed','br-2','2026-08-13T04:00:00Z',?)`,
		`[{"id":"stripe-other-branch","payment_method_code":"stripe","amount":9999,"status":"succeeded","paid_at":"2026-08-13T04:00:00Z"}]`)

	got := revenueByMethod(t, s)
	if got["cash"] != 1000 || got["stripe"] != 3500 || got["paypay"] != 2000 {
		t.Errorf("daily payment split = %+v, want cash=1000 stripe=3500 paypay=2000", got)
	}
	if len(got) != 3 {
		t.Errorf("daily payment split leaked refund/pending/window/branch controls: %+v", got)
	}
}
