package handler

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printjob"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
)

// The payment-receipt endpoint prints paper that states money was received.
// Everything here guards that claim.
//
// Two bugs met on this path. A Cloud-settled payment deliberately never becomes
// a local `payments` row (that table feeds the Z-report and the till
// reconciliation panel, and an online payment sitting in it would present
// itself as claimable cash), so the reprint path — which read only that table —
// priced every online receipt at 0 and 404'd the cashier's reprint button.
// The obvious repair is to fall back to the order header when the lookup
// misses; done carelessly that ALSO removes the gate deciding who may be
// printed at all, and the endpoint will happily produce a receipt for a failed
// payment, a refunded one, a payment belonging to a different order, or an
// order nobody has paid.
//
// So: the fallback may change WHERE the amount comes from. It may never change
// WHO can be printed. Each test below pins one half of that.

// receiptGuardServer builds a server with a real order engine and a Cloud stub,
// seeded with one order that carries a Cloud payment_summary mirror.
func receiptGuardServer(t *testing.T, summary string) (*Server, *store.DB, *httptest.Server) {
	t.Helper()
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	t.Cleanup(cloud.Close)

	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	s.printJournal = printjob.NewJournal(db)

	// #2593 — endpoint biên lai nay trả `503 no_printer` khi không có máy mang
	// role `receipt_printer`, và cổng đó chạy TRƯỚC `beginMoneyPrint`. Mọi bài
	// trong file này đo LOGIC SỐ TIỀN (đơn nào, khoản nào, tổng bao nhiêu) chứ
	// không đo cổng đó, nên harness cấp cho chúng một máy thật — một TCP
	// listener vứt đi, nên lượt in thành công thật chứ không mock tầng nào.
	//
	// Cổng 503 có bài riêng: `TestLANPrintReceipt_NoReceiptPrinterIs503AndBurnsNothing`.
	seedReceiptPrinter(t, s, db)

	if _, err := db.Exec(`INSERT INTO orders
		(id, order_code, order_type, status, opened_at, branch_id, brand_id, organization_id,
		 total_amount, paid_amount, cloud_payment_summary, created_at, updated_at)
		VALUES ('ord-1','O-1','takeaway','closed','2026-07-31T03:00:00Z','branch-A','bd-1','org-1',
		        5000, 5000, ?, '2026-07-31T03:00:00Z','2026-07-31T03:00:00Z')`, summary); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	return s, db, cloud
}

func postReceipt(t *testing.T, s *Server, body string) *httptest.ResponseRecorder {
	t.Helper()
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)
	req := httptest.NewRequest("POST", "/api/lan/print/payment-receipt", strings.NewReader(body))
	req.Header.Set("Authorization", "Bearer kiosk-token")
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)
	return rec
}

// printedAmount reads back what the endpoint actually committed to paper, via
// the print journal the handler writes.
func printedAmount(t *testing.T, db *store.DB) (int, bool) {
	t.Helper()
	var payload string
	if err := db.QueryRow(
		`SELECT COALESCE(payload,'') FROM print_jobs WHERE kind = 'receipt' ORDER BY rowid DESC LIMIT 1`,
	).Scan(&payload); err != nil {
		return 0, false
	}
	var p struct {
		Amount int `json:"amount"`
	}
	if json.Unmarshal([]byte(payload), &p) != nil {
		return 0, false
	}
	return p.Amount, true
}

// The bug this whole change exists to fix: a payment settled in Cloud has no
// local row, so the amount has to come from the order header — otherwise the
// customer gets a receipt that says 0.
func TestLANPrintReceipt_CloudSettledPaymentPrintsRealAmount(t *testing.T) {
	s, db, _ := receiptGuardServer(t,
		`[{"id":"pay-cloud","payment_method_code":"paypay","payment_method_name":"PayPay","amount":5000,"status":"succeeded"}]`)

	rec := postReceipt(t, s, `{"order_id":"ord-1","payment_id":"pay-cloud"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("a settled Cloud payment must print; got %d body=%s", rec.Code, rec.Body.String())
	}
	if got, ok := printedAmount(t, db); !ok || got != 5000 {
		t.Fatalf("receipt must carry the real amount; got %d (found=%v)", got, ok)
	}
}

// …and the same on the legacy path, where pos-web sends no payment_id.
func TestLANPrintReceipt_LegacyPathUsesSettledCloudTotal(t *testing.T) {
	s, db, _ := receiptGuardServer(t,
		`[{"id":"pay-cloud","payment_method_code":"paypay","amount":5000,"status":"succeeded"}]`)

	if rec := postReceipt(t, s, `{"order_id":"ord-1"}`); rec.Code != http.StatusOK {
		t.Fatalf("legacy path must print; got %d body=%s", rec.Code, rec.Body.String())
	}
	if got, ok := printedAmount(t, db); !ok || got != 5000 {
		t.Fatalf("legacy path must price the slip from the Cloud mirror; got %d (found=%v)", got, ok)
	}
}

// A refund is money that went BACK to the customer. Cloud says so in the
// summary status; the workstation used to drop that field on the way into
// SQLite, which left no way to tell a refund from a sale.
func TestLANPrintReceipt_RefundedCloudPaymentIsRefused(t *testing.T) {
	s, _, _ := receiptGuardServer(t,
		`[{"id":"pay-refunded","payment_method_code":"card","amount":3000,"status":"refunded"}]`)

	rec := postReceipt(t, s, `{"order_id":"ord-1","payment_id":"pay-refunded"}`)
	if rec.Code != http.StatusConflict {
		t.Fatalf("a refunded payment must never print as paid; got %d body=%s", rec.Code, rec.Body.String())
	}
}

// An unknown status is not a licence to print. The allow-list must fail closed,
// because new payment states get invented and the two failure directions are
// not symmetric.
func TestLANPrintReceipt_UnknownCloudStatusIsRefused(t *testing.T) {
	s, _, _ := receiptGuardServer(t,
		`[{"id":"pay-weird","payment_method_code":"card","amount":3000,"status":"partially_refunded"}]`)

	rec := postReceipt(t, s, `{"order_id":"ord-1","payment_id":"pay-weird"}`)
	if rec.Code != http.StatusConflict {
		t.Fatalf("an unrecognised status must fail closed; got %d body=%s", rec.Code, rec.Body.String())
	}
}

// A payment id nobody has heard of must stay a 404 — the fallback is for
// payments that exist elsewhere, not for ones that do not exist.
func TestLANPrintReceipt_UnknownPaymentStays404(t *testing.T) {
	s, _, _ := receiptGuardServer(t,
		`[{"id":"pay-cloud","payment_method_code":"paypay","amount":5000,"status":"succeeded"}]`)

	rec := postReceipt(t, s, `{"order_id":"ord-1","payment_id":"does-not-exist"}`)
	if rec.Code != http.StatusNotFound {
		t.Fatalf("an unknown payment must not print; got %d body=%s", rec.Code, rec.Body.String())
	}
}

// The summary is read scoped to the requested order. A payment that settled on
// a DIFFERENT order must not be printable by quoting its id here.
func TestLANPrintReceipt_ForeignPaymentIsRefused(t *testing.T) {
	s, db, _ := receiptGuardServer(t, `[]`)

	if _, err := db.Exec(`INSERT INTO orders
		(id, order_code, order_type, status, opened_at, branch_id, brand_id, organization_id,
		 total_amount, paid_amount, cloud_payment_summary, created_at, updated_at)
		VALUES ('ord-2','O-2','takeaway','closed','2026-07-31T03:00:00Z','branch-A','bd-1','org-1',
		        9000, 9000, '[{"id":"pay-other","amount":9000,"status":"succeeded"}]',
		        '2026-07-31T03:00:00Z','2026-07-31T03:00:00Z')`); err != nil {
		t.Fatalf("seed second order: %v", err)
	}

	rec := postReceipt(t, s, `{"order_id":"ord-1","payment_id":"pay-other"}`)
	if rec.Code != http.StatusNotFound {
		t.Fatalf("a payment from another order must not print here; got %d body=%s", rec.Code, rec.Body.String())
	}
}

// An order nobody has paid must not have its own bill printed back as money
// received. The fallback is the SETTLED total, never the order total.
func TestLANPrintReceipt_UnpaidOrderDoesNotPrintItsTotal(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	defer cloud.Close()
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	s.printJournal = printjob.NewJournal(db)

	// #2593 — endpoint biên lai nay trả `503 no_printer` khi không có máy mang
	// role `receipt_printer`, và cổng đó chạy TRƯỚC `beginMoneyPrint`. Mọi bài
	// trong file này đo LOGIC SỐ TIỀN (đơn nào, khoản nào, tổng bao nhiêu) chứ
	// không đo cổng đó, nên harness cấp cho chúng một máy thật — một TCP
	// listener vứt đi, nên lượt in thành công thật chứ không mock tầng nào.
	//
	// Cổng 503 có bài riêng: `TestLANPrintReceipt_NoReceiptPrinterIs503AndBurnsNothing`.
	seedReceiptPrinter(t, s, db)

	if _, err := db.Exec(`INSERT INTO orders
		(id, order_code, order_type, status, opened_at, branch_id, brand_id, organization_id,
		 total_amount, paid_amount, cloud_payment_summary, created_at, updated_at)
		VALUES ('ord-open','O-OPEN','takeaway','open','2026-07-31T03:00:00Z','branch-A','bd-1','org-1',
		        5000, 0, '[]', '2026-07-31T03:00:00Z','2026-07-31T03:00:00Z')`); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	if rec := postReceipt(t, s, `{"order_id":"ord-open"}`); rec.Code != http.StatusOK {
		t.Fatalf("legacy path stays best-effort; got %d body=%s", rec.Code, rec.Body.String())
	}
	if got, ok := printedAmount(t, db); ok && got != 0 {
		t.Fatalf("an unpaid order must not print a paid amount; got %d", got)
	}
}

// A payment that failed locally is still refused — the fallback must not be
// reachable as a way around the local status gate.
func TestLANPrintReceipt_FailedLocalPaymentIsRefused(t *testing.T) {
	s, db, _ := receiptGuardServer(t,
		`[{"id":"pay-x","payment_method_code":"card","amount":5000,"status":"succeeded"}]`)

	if _, err := db.Exec(`INSERT INTO payments (id, order_id, amount, payment_method, status, created_at, updated_at)
		VALUES ('pay-x','ord-1',5000,'card','failed','2026-07-31T03:00:00Z','2026-07-31T03:00:00Z')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}

	rec := postReceipt(t, s, `{"order_id":"ord-1","payment_id":"pay-x"}`)
	if rec.Code != http.StatusConflict {
		t.Fatalf("a failed local payment must not print; got %d body=%s", rec.Code, rec.Body.String())
	}
}

// The force-pull: an order that exists only in Cloud must be materialised and
// printed, not 404'd. This is the 5 s race that left shops unable to print.
func TestLANPrintReceipt_ForcePullsOrderMissingLocally(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if strings.Contains(r.URL.Path, "/workstation/orders") {
			fmt.Fprint(w, `{"data":[{"id":"ord-remote","order_code":"O-R","order_type":"takeaway",
				"status":"closed","opened_at":"2026-07-31T03:00:00Z","updated_at":"2026-07-31T03:00:00Z",
				"branch_id":"branch-A","paid_amount":"7000","total_amount":"7000",
				"payment_summary":[{"id":"pay-r","payment_method_code":"paypay","amount":"7000","status":"succeeded"}]}],
				"count":1,"generated_at":"2026-07-31T04:00:00Z"}`)
			return
		}
		fmt.Fprint(w, `{"data":{"id":"kiosk-1","type":"kiosk","branch_id":"branch-A","status":"active"}}`)
	}))
	defer cloud.Close()

	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	s.printJournal = printjob.NewJournal(db)

	// #2593 — endpoint biên lai nay trả `503 no_printer` khi không có máy mang
	// role `receipt_printer`, và cổng đó chạy TRƯỚC `beginMoneyPrint`. Mọi bài
	// trong file này đo LOGIC SỐ TIỀN (đơn nào, khoản nào, tổng bao nhiêu) chứ
	// không đo cổng đó, nên harness cấp cho chúng một máy thật — một TCP
	// listener vứt đi, nên lượt in thành công thật chứ không mock tầng nào.
	//
	// Cổng 503 có bài riêng: `TestLANPrintReceipt_NoReceiptPrinterIs503AndBurnsNothing`.
	seedReceiptPrinter(t, s, db)
	s.puller = service.NewSyncPuller(db, cloud.URL, s.GetDeviceToken)
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token', 'WS-TOKEN')`); err != nil {
		t.Fatalf("seed token: %v", err)
	}

	var before int
	_ = db.QueryRow(`SELECT COUNT(*) FROM orders WHERE id='ord-remote'`).Scan(&before)
	if before != 0 {
		t.Fatalf("precondition: order must be absent locally, found %d", before)
	}

	rec := postReceipt(t, s, `{"order_id":"ord-remote","payment_id":"pay-r"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("a Cloud-only order must be force-pulled and printed; got %d body=%s", rec.Code, rec.Body.String())
	}

	var after int
	_ = db.QueryRow(`SELECT COUNT(*) FROM orders WHERE id='ord-remote'`).Scan(&after)
	if after != 1 {
		t.Fatalf("force-pull must land the order locally; got %d rows", after)
	}
}

// Cloud genuinely does not have it → 404, not a 500 or a blank slip.
func TestLANPrintReceipt_ForcePullMissOn404(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if strings.Contains(r.URL.Path, "/workstation/orders") {
			fmt.Fprint(w, `{"data":[],"count":0,"generated_at":"2026-07-31T04:00:00Z"}`)
			return
		}
		fmt.Fprint(w, `{"data":{"id":"kiosk-1","type":"kiosk","branch_id":"branch-A","status":"active"}}`)
	}))
	defer cloud.Close()

	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	s.printJournal = printjob.NewJournal(db)

	// #2593 — endpoint biên lai nay trả `503 no_printer` khi không có máy mang
	// role `receipt_printer`, và cổng đó chạy TRƯỚC `beginMoneyPrint`. Mọi bài
	// trong file này đo LOGIC SỐ TIỀN (đơn nào, khoản nào, tổng bao nhiêu) chứ
	// không đo cổng đó, nên harness cấp cho chúng một máy thật — một TCP
	// listener vứt đi, nên lượt in thành công thật chứ không mock tầng nào.
	//
	// Cổng 503 có bài riêng: `TestLANPrintReceipt_NoReceiptPrinterIs503AndBurnsNothing`.
	seedReceiptPrinter(t, s, db)
	s.puller = service.NewSyncPuller(db, cloud.URL, s.GetDeviceToken)
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token', 'WS-TOKEN')`); err != nil {
		t.Fatalf("seed token: %v", err)
	}

	if rec := postReceipt(t, s, `{"order_id":"nope"}`); rec.Code != http.StatusNotFound {
		t.Fatalf("an order Cloud does not have must 404; got %d body=%s", rec.Code, rec.Body.String())
	}
}

// Materialising an order legitimately fires the auto-print hooks — that is the
// delivery path for a Cloud-settled payment, and suppressing it loses the
// receipt entirely because those hooks fire exactly once. So when the pull this
// handler triggered has already put the receipt on paper, the handler must not
// print a second identical sheet.
func TestLANPrintReceipt_DoesNotDoublePrintAfterAutoPrint(t *testing.T) {
	s, db, _ := receiptGuardServer(t,
		`[{"id":"pay-cloud","payment_method_code":"paypay","amount":5000,"status":"succeeded"}]`)

	// Stand in for "the force-pull just auto-printed this receipt" by taking
	// the claim the auto-print path takes.
	if !s.claimAutoPrint("receipt", "ord-1") {
		t.Fatalf("precondition: claim should be free")
	}
	// A claim taken BEFORE the request is an older auto-print, not this one —
	// the reprint button must still work.
	rec := postReceipt(t, s, `{"order_id":"ord-1","payment_id":"pay-cloud"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("a pre-existing claim must not block a reprint; got %d", rec.Code)
	}
	if _, ok := printedAmount(t, db); !ok {
		t.Fatalf("reprint must still reach the printer")
	}
}

func TestPaymentSettled(t *testing.T) {
	for _, s := range []string{"succeeded", "confirmed", "PAID", " completed "} {
		if !service.PaymentSettled(s) {
			t.Errorf("PaymentSettled(%q) = false, want true", s)
		}
	}
	for _, s := range []string{"", "failed", "refunded", "pending", "partially_refunded", "disputed", "unknown"} {
		if service.PaymentSettled(s) {
			t.Errorf("PaymentSettled(%q) = true, want false — the allow-list must fail closed", s)
		}
	}
}

func TestPaymentVisibleInHistory(t *testing.T) {
	for _, s := range []string{"succeeded", "confirmed", "paid", "completed", "refunded", "PARTIALLY_REFUNDED"} {
		if !service.PaymentVisibleInHistory(s) {
			t.Errorf("PaymentVisibleInHistory(%q) = false, want true", s)
		}
	}
	for _, s := range []string{"", "failed", "pending", "disputed", "unknown"} {
		if service.PaymentVisibleInHistory(s) {
			t.Errorf("PaymentVisibleInHistory(%q) = true, want false", s)
		}
	}
}

// cloudOrderStub serves one order on the workstation-orders feed and the device
// shape on everything else, so both the auth verifier and the puller are happy.
func cloudOrderStub(t *testing.T, orderJSON string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if strings.Contains(r.URL.Path, "/workstation/orders") {
			fmt.Fprintf(w, `{"data":[%s],"count":1,"generated_at":"2026-07-31T04:00:00Z"}`, orderJSON)
			return
		}
		fmt.Fprint(w, `{"data":{"id":"kiosk-1","type":"kiosk","branch_id":"branch-A","status":"active"}}`)
	}))
	t.Cleanup(srv.Close)
	return srv
}

func pullServer(t *testing.T, cloud *httptest.Server) (*Server, *store.DB) {
	t.Helper()
	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	s.printJournal = printjob.NewJournal(db)

	// #2593 — endpoint biên lai nay trả `503 no_printer` khi không có máy mang
	// role `receipt_printer`, và cổng đó chạy TRƯỚC `beginMoneyPrint`. Mọi bài
	// trong file này đo LOGIC SỐ TIỀN (đơn nào, khoản nào, tổng bao nhiêu) chứ
	// không đo cổng đó, nên harness cấp cho chúng một máy thật — một TCP
	// listener vứt đi, nên lượt in thành công thật chứ không mock tầng nào.
	//
	// Cổng 503 có bài riêng: `TestLANPrintReceipt_NoReceiptPrinterIs503AndBurnsNothing`.
	seedReceiptPrinter(t, s, db)
	s.puller = service.NewSyncPuller(db, cloud.URL, s.GetDeviceToken)
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token','WS-TOKEN')`); err != nil {
		t.Fatalf("seed token: %v", err)
	}
	return s, db
}

// THE dedup case, as opposed to its mirror image above: the force-pull this
// handler triggers is what fires the auto-print hook, so the receipt is already
// on paper by the time control returns. Printing again here is what puts two
// identical sheets in the customer's hand.
//
// Hooks are wired exactly as server.go wires them — the point is that they stay
// LIVE. Suppressing them instead loses the receipt outright, because they fire
// only once per order.
func TestLANPrintReceipt_SkipsPrintWhenForcePullAlreadyAutoPrinted(t *testing.T) {
	cloud := cloudOrderStub(t, `{"id":"ord-paid","order_code":"O-P","order_type":"takeaway",
		"status":"closed","opened_at":"2026-07-31T03:00:00Z","updated_at":"2026-07-31T03:00:00Z",
		"branch_id":"branch-A","paid_amount":"4200","total_amount":"4200",
		"payment_summary":[{"id":"pay-p","payment_method_code":"paypay","amount":"4200","status":"succeeded"}]}`)
	s, db := pullServer(t, cloud)
	// Stand in for an auto-print that SUCCEEDED. Driving the real one needs a
	// reachable thermal printer; what matters to this handler is the state a
	// successful auto-print leaves behind, which is the claim. The hook is
	// wired at the same seam server.go wires it, so the claim lands inside the
	// force-pull window — which is the whole point.
	s.puller.SetOnOrderPaid(func(orderID, branchID string, amount int) {
		s.claimAutoPrint("receipt", orderID)
	})

	rec := postReceipt(t, s, `{"order_id":"ord-paid","payment_id":"pay-p"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("want 200; got %d body=%s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), `"printed_by":"auto"`) {
		t.Fatalf("handler must yield to the auto-print it just triggered; body=%s", rec.Body.String())
	}
	// Exactly one receipt reached the journal, not two.
	var n int
	_ = db.QueryRow(`SELECT COUNT(*) FROM print_jobs WHERE kind='receipt'`).Scan(&n)
	if n > 1 {
		t.Fatalf("one button press must not journal %d receipts", n)
	}
}

// An order can exist locally twice — the workstation row and its cloud-keyed
// sibling — and the payment may hang off either. Matching on the raw order id
// alone both missed real payments and let one from another order through, which
// is why every other payment query in this file is family-scoped.
func TestLANPrintReceipt_FindsPaymentOnCloudSibling(t *testing.T) {
	s, db, _ := receiptGuardServer(t, `[]`)

	if _, err := db.Exec(`UPDATE orders SET cloud_id = 'ord-cloud' WHERE id = 'ord-1'`); err != nil {
		t.Fatalf("link sibling: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, amount, payment_method, status, created_at, updated_at)
		VALUES ('pay-sib','ord-cloud',4500,'card','succeeded','2026-07-31T03:00:00Z','2026-07-31T03:00:00Z')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}

	rec := postReceipt(t, s, `{"order_id":"ord-1","payment_id":"pay-sib"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("a payment on the cloud sibling must be found; got %d body=%s", rec.Code, rec.Body.String())
	}
	if got, ok := printedAmount(t, db); !ok || got != 4500 {
		t.Fatalf("must print the sibling payment's amount; got %d (found=%v)", got, ok)
	}
}

// The other endpoint that prints an order — same race, same repair.
func TestHandlePrintOrder_ForcePullsOrderMissingLocally(t *testing.T) {
	cloud := cloudOrderStub(t, `{"id":"ord-remote2","order_code":"O-R2","order_type":"takeaway",
		"status":"closed","opened_at":"2026-07-31T03:00:00Z","updated_at":"2026-07-31T03:00:00Z",
		"branch_id":"branch-A","paid_amount":"1000","total_amount":"1000"}`)
	s, db := pullServer(t, cloud)

	mux := http.NewServeMux()
	s.registerRoutes(mux)
	req := httptest.NewRequest("POST", "/api/orders/ord-remote2/print", strings.NewReader(`{"type":"receipt"}`))
	req.Header.Set("Content-Type", "application/json")
	req.RemoteAddr = "127.0.0.1:5555" // the endpoint is loopback-only
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code == http.StatusNotFound {
		t.Fatalf("a Cloud-only order must be force-pulled, not 404'd; body=%s", rec.Body.String())
	}
	var n int
	_ = db.QueryRow(`SELECT COUNT(*) FROM orders WHERE id='ord-remote2'`).Scan(&n)
	if n != 1 {
		t.Fatalf("force-pull must land the order locally; got %d rows", n)
	}
}

// Cloud is up but broken → 503 with a retry hint, never a silent failure or a
// blank slip. pos-web keys its retry policy on these shapes.
func TestLANPrintReceipt_CloudErrorGives503WithRetryHint(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if strings.Contains(r.URL.Path, "/workstation/orders") {
			w.WriteHeader(http.StatusInternalServerError)
			fmt.Fprint(w, `{"message":"boom"}`)
			return
		}
		fmt.Fprint(w, `{"data":{"id":"kiosk-1","type":"kiosk","branch_id":"branch-A","status":"active"}}`)
	}))
	defer cloud.Close()
	s, _ := pullServer(t, cloud)

	rec := postReceipt(t, s, `{"order_id":"ord-nowhere"}`)
	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("a broken Cloud must give 503; got %d body=%s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "retry_after_ms") {
		t.Fatalf("503 must carry a retry hint; body=%s", rec.Body.String())
	}
}

// The dangerous half of standing down for the auto-print: it must only happen
// when paper actually came out.
//
// The auto-print path claims BEFORE it prints (a claim taken afterwards would
// double-print paper that may already be moving). So a failed print used to
// leave the claim standing — and this handler, reading that claim as "already
// printed", would return 200 while the customer got nothing, converting a
// visible printer error into a silent success. The claim is released on failure
// precisely so that cannot happen.
func TestLANPrintReceipt_StillPrintsWhenAutoPrintFailed(t *testing.T) {
	s, _, _ := receiptGuardServer(t,
		`[{"id":"pay-cloud","payment_method_code":"paypay","amount":5000,"status":"succeeded"}]`)

	// Stand in for an auto-print that claimed and then failed on an offline
	// printer: claim, then release, exactly as autoPrintReceiptOnce does.
	if !s.claimAutoPrint("receipt", "ord-1") {
		t.Fatalf("precondition: claim should be free")
	}
	s.releaseAutoPrint("receipt", "ord-1")

	if s.autoPrintReceiptClaimed("ord-1") {
		t.Fatalf("a failed auto-print must not leave its claim behind")
	}
	rec := postReceipt(t, s, `{"order_id":"ord-1","payment_id":"pay-cloud"}`)
	if strings.Contains(rec.Body.String(), `"printed_by":"auto"`) {
		t.Fatalf("handler must not stand down after a FAILED auto-print; body=%s", rec.Body.String())
	}
	if rec.Code != http.StatusOK {
		t.Fatalf("want 200; got %d body=%s", rec.Code, rec.Body.String())
	}
}

// And the release must be reachable through the real path, not just by calling
// the helper: an auto-print with no printer configured leaves no claim.
func TestAutoPrintReceipt_ReleasesClaimWhenPrintFails(t *testing.T) {
	s, db, _ := receiptGuardServer(t, `[]`)

	// #2593 — bài này đo "in HỎNG thì nhả claim", nên nó cần một lượt in hỏng
	// THẬT. Trước đây nó mượn `devices == nil`, nhưng đó nay là một đường KHÁC
	// hẳn: `autoPrintReceiptOnce` có nil-guard chạy trước `claimAutoPrint`, nên
	// claim không bao giờ được lấy và bài đọc lên như đang đo việc nhả claim
	// trong khi không có claim nào tồn tại.
	//
	// Thay bằng máy in trỏ vào cổng đóng: role đúng, phân giải được, `Connect`
	// hỏng. Đó mới là hình dạng thật của "máy in offline giữa ca".
	for _, d := range s.devices.ListDevices() {
		if err := s.devices.RemoveDevice(d.ID); err != nil {
			t.Fatalf("remove seeded printer: %v", err)
		}
	}
	if _, err := s.devices.AddPrinter("dead", []printer.DeviceType{printer.TypeReceiptPrinter},
		printer.ConnNetwork, "127.0.0.1:1", printer.PrinterConfig{PaperWidth: 80}); err != nil {
		t.Fatalf("add dead printer: %v", err)
	}

	s.autoPrintReceiptOnce("ord-1", 5000)

	var n int
	_ = db.QueryRow(`SELECT COUNT(*) FROM idempotency_keys WHERE key = 'autoprint:receipt:ord-1'`).Scan(&n)
	if n != 0 {
		t.Fatalf("a receipt that never printed must not hold its claim; found %d", n)
	}
}

// #2593 — "chưa tick role receipt_printer" là lỗi CẤU HÌNH, và mã lý do gửi
// cho kiosk phải nói đúng điều đó.
//
// Không có case riêng thì `classifyPrintError` rơi vào nhánh mặc định
// `printer_error`, và kiosk bảo nhân viên đi kiểm MÁY IN — trong khi cách sửa
// nằm ở màn Settings. Cùng repo đã có từ vựng cho tình huống này (`no_printer`
// ở endpoint LAN, `no_receipt_printer` ở ngăn kéo); một mã thứ ba cho cùng
// nguyên nhân là cách chắc chắn để không ai tra được nó.
func TestClassifyPrintError_NoReceiptPrinterIsAConfigProblem(t *testing.T) {
	if got := classifyPrintError(errNoReceiptPrinter); got != "no_printer" {
		t.Fatalf("classifyPrintError(errNoReceiptPrinter) = %q, want \"no_printer\" — "+
			"%q gửi nhân viên đi kiểm phần cứng thay vì tick role", got, got)
	}
	// Lỗi máy thật vẫn phải phân loại như cũ — bản vá không được nuốt nhánh kia.
	if got := classifyPrintError(errors.New("dial tcp 10.0.0.9:9100: connect: connection refused")); got != "printer_offline" {
		t.Fatalf("lỗi kết nối = %q, want printer_offline", got)
	}
}

// #3040 — bản cục bộ CŨ phải được làm mới trước khi in, không chỉ bản VẮNG MẶT.
//
// Đây là nửa còn lại của cuộc đua mà `ensureOrderLocal` mô tả. Nửa đã đóng là
// "đơn chưa về". Nửa này khác hẳn về hình dạng và là ca quán thật gặp: khách
// đang ngồi ăn nên máy trạm ĐÃ CÓ đơn từ lâu, rồi khách quét QR trả online —
// Cloud ghi nhận, còn máy trạm giữ bản `open` cho tới nhịp pull kế.
//
// Trong cửa sổ đó, `o != nil` là đúng nên bản cũ không kéo lại gì, và tờ biên
// lai được dựng từ một đơn mà máy trạm tin là CHƯA TRẢ.
func TestLANPrintReceipt_RefreshesStaleLocalOrderPaidInCloud(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if strings.Contains(r.URL.Path, "/workstation/orders") {
			fmt.Fprint(w, `{"data":[{"id":"ord-stale","order_code":"O-S","order_type":"dine_in",
				"status":"closed","opened_at":"2026-07-31T03:00:00Z","updated_at":"2026-07-31T03:30:00Z",
				"branch_id":"branch-A","paid_amount":"4500","total_amount":"4500",
				"payment_summary":[{"id":"pay-s","payment_method_code":"paypay","amount":"4500","status":"succeeded"}]}],
				"count":1,"generated_at":"2026-07-31T04:00:00Z"}`)
			return
		}
		fmt.Fprint(w, `{"data":{"id":"kiosk-1","type":"kiosk","branch_id":"branch-A","status":"active"}}`)
	}))
	defer cloud.Close()

	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	s.printJournal = printjob.NewJournal(db)
	seedReceiptPrinter(t, s, db)
	s.puller = service.NewSyncPuller(db, cloud.URL, s.GetDeviceToken)
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token', 'WS-TOKEN')`); err != nil {
		t.Fatalf("seed token: %v", err)
	}

	// Bản CŨ: đơn đã có mặt, nhưng còn `open` và chưa ghi nhận đồng nào.
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, order_type, status, total_amount, paid_amount, branch_id, opened_at, updated_at)
		VALUES ('ord-stale','O-S','dine_in','open',4500,0,'branch-A','2026-07-31T03:00:00Z','2026-07-31T03:00:00Z')`); err != nil {
		t.Fatalf("seed stale order: %v", err)
	}

	rec := postReceipt(t, s, `{"order_id":"ord-stale","payment_id":"pay-s"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("đơn cũ phải được làm mới rồi in; got %d body=%s", rec.Code, rec.Body.String())
	}

	var status string
	var paid int
	if err := db.QueryRow(`SELECT status, COALESCE(paid_amount,0) FROM orders WHERE id='ord-stale'`).Scan(&status, &paid); err != nil {
		t.Fatalf("read back: %v", err)
	}
	if status != "closed" || paid != 4500 {
		t.Fatalf("phải làm mới từ Cloud; còn status=%q paid=%d", status, paid)
	}
}

// Cloud hỏng KHÔNG được biến một lượt in thành lỗi.
//
// Khác hẳn nhánh "đơn vắng mặt": vắng mặt nghĩa là không có gì để in nên 503 là
// đúng. Ở đây máy trạm đã có một bản dùng được, và luật "warn, never block"
// (plan-052 §4) nói tờ giấy vẫn phải ra.
func TestLANPrintReceipt_StaleRefreshFailsOpenWhenCloudDown(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if strings.Contains(r.URL.Path, "/workstation/orders") {
			w.WriteHeader(http.StatusInternalServerError)
			fmt.Fprint(w, `{"message":"boom"}`)
			return
		}
		fmt.Fprint(w, `{"data":{"id":"kiosk-1","type":"kiosk","branch_id":"branch-A","status":"active"}}`)
	}))
	defer cloud.Close()

	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)
	s.printJournal = printjob.NewJournal(db)
	seedReceiptPrinter(t, s, db)
	s.puller = service.NewSyncPuller(db, cloud.URL, s.GetDeviceToken)
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token', 'WS-TOKEN')`); err != nil {
		t.Fatalf("seed token: %v", err)
	}

	// Đơn cục bộ ĐÃ thanh toán đủ — in được mà không cần hỏi Cloud.
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, order_type, status, total_amount, paid_amount, branch_id, opened_at, updated_at)
		VALUES ('ord-local','O-L','dine_in','paying',3000,3000,'branch-A','2026-07-31T03:00:00Z','2026-07-31T03:00:00Z')`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO payments (id, order_id, amount, payment_method, status, created_at, updated_at)
		VALUES ('pay-l','ord-local',3000,'cash','succeeded','2026-07-31T03:10:00Z','2026-07-31T03:10:00Z')`); err != nil {
		t.Fatalf("seed payment: %v", err)
	}

	rec := postReceipt(t, s, `{"order_id":"ord-local","payment_id":"pay-l"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("Cloud hỏng không được chặn lượt in; got %d body=%s", rec.Code, rec.Body.String())
	}
}
