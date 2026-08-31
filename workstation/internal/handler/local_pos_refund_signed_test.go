package handler

import (
	"encoding/json"
	"net/http"
	"testing"
)

// #2656 — the refund WRITE path: an append-only signed row instead of a
// cumulative column on the original.
//
// These cases are end-to-end through the handler, not seeded rows: the whole
// point of layer 2 is what the workstation now WRITES, and a seeded fixture
// would keep passing if the writer regressed.

// refundRow is one signed refund row as the ledger holds it.
type refundRow struct {
	id, note, status, method, session string
	amount                            int
}

func signedRefundRows(t *testing.T, srv *Server, originalID string) []refundRow {
	t.Helper()
	rows, err := srv.db.Query(`
		SELECT id, COALESCE(note,''), status, payment_method, COALESCE(till_session_id,''), amount
		FROM payments WHERE refund_of_id = ? ORDER BY created_at, id`, originalID)
	if err != nil {
		t.Fatal(err)
	}
	defer rows.Close()
	var out []refundRow
	for rows.Next() {
		var r refundRow
		if err := rows.Scan(&r.id, &r.note, &r.status, &r.method, &r.session, &r.amount); err != nil {
			t.Fatal(err)
		}
		out = append(out, r)
	}
	return out
}

func originalRow(t *testing.T, srv *Server, id string) (status string, refundedAmount int, refundedAt string) {
	t.Helper()
	if err := srv.db.QueryRow(
		`SELECT status, refunded_amount, COALESCE(refunded_at,'') FROM payments WHERE id = ?`, id,
	).Scan(&status, &refundedAmount, &refundedAt); err != nil {
		t.Fatal(err)
	}
	return
}

// oldBinaryNet is the money query a workstation binary from BEFORE this release
// runs — it knows nothing about `refund_of_id`. It is reproduced verbatim (not
// called through any helper) because the property under test is that a rolled
// back binary still reads the converted ledger correctly.
func oldBinaryNet(t *testing.T, srv *Server, orderID string) int {
	t.Helper()
	var net int
	if err := srv.db.QueryRow(`
		SELECT COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) FROM payments
		WHERE order_id = ? AND status IN ('pending','confirmed','succeeded')`, orderID,
	).Scan(&net); err != nil {
		t.Fatal(err)
	}
	return net
}

// Two partial refunds on the same payment — the thing the cumulative column
// could not represent at all. Each must survive as its own row with its own
// note, and the original must be left untouched.
func TestRefund_TwoPartialRefundsBecomeTwoDistinctSignedRows(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "succeeded", 1000, 1000)

	if rec := postRefund(t, srv, orderID, payID, "k-1", `{"amount":300,"note":"first"}`); rec.Code != http.StatusOK {
		t.Fatalf("first refund want 200, got %d — %s", rec.Code, rec.Body.String())
	}
	if rec := postRefund(t, srv, orderID, payID, "k-2", `{"amount":200,"note":"second"}`); rec.Code != http.StatusOK {
		t.Fatalf("second refund want 200, got %d — %s", rec.Code, rec.Body.String())
	}

	rows := signedRefundRows(t, srv, payID)
	if len(rows) != 2 {
		t.Fatalf("want 2 signed refund rows, got %d (%+v)", len(rows), rows)
	}
	if rows[0].id == rows[1].id {
		t.Fatalf("the two refunds share an id %q — they are not distinct entries", rows[0].id)
	}
	// Both land in the same second, so assert them as a SET keyed by the note
	// each refund carries — the per-refund detail the cumulative column erased.
	byNote := map[string]int{}
	for _, r := range rows {
		byNote[r.note] = r.amount
	}
	if byNote["first"] != -300 || byNote["second"] != -200 {
		t.Errorf("refund rows by note = %+v, want first:-300 second:-200", byNote)
	}
	for _, r := range rows {
		if r.status != "succeeded" {
			t.Errorf("refund row %s status = %q, want succeeded (every money aggregate keys off it)", r.id, r.status)
		}
		if r.method != "cash" {
			t.Errorf("refund row %s method = %q, want the original's cash — a cash refund must come off the cash line", r.id, r.method)
		}
	}

	// Each signed row is the same entry as its payment_refunds history line.
	if n := countRefundRows(t, srv, payID); n != 2 {
		t.Errorf("payment_refunds rows = %d, want 2", n)
	}
	for _, r := range rows {
		var n int
		if err := srv.db.QueryRow(
			`SELECT COUNT(*) FROM payment_refunds WHERE id = ? AND payment_id = ?`, r.id, payID).Scan(&n); err != nil {
			t.Fatal(err)
		}
		if n != 1 {
			t.Errorf("refund row %s has no matching payment_refunds line", r.id)
		}
	}

	// The original is not mutated — not its status, not the retired column.
	status, refunded, refundedAt := originalRow(t, srv, payID)
	if status != "succeeded" {
		t.Errorf("original status = %q, want succeeded: flipping it drops the SALE from every money sum while keeping the refund", status)
	}
	if refunded != 0 {
		t.Errorf("original refunded_amount = %d, want 0 — a row carrying both representations is subtracted twice", refunded)
	}
	if refundedAt != "" {
		t.Errorf("original refunded_at = %q, want empty (append-only)", refundedAt)
	}

	if _, paid, _ := orderColsRefund(t, srv, orderID); paid != 500 {
		t.Errorf("order paid_amount = %d, want 500", paid)
	}
}

// No double subtraction: the two order-level money guards see 1000 − 300 − 200
// exactly once, and neither knows what `refund_of_id` is.
func TestRefund_SignedRowsDoNotDoubleSubtract(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "succeeded", 1000, 1000)
	if rec := postRefund(t, srv, orderID, payID, "k-1", `{"amount":300}`); rec.Code != http.StatusOK {
		t.Fatalf("refund want 200, got %d — %s", rec.Code, rec.Body.String())
	}

	active, err := srv.sumActivePaymentsForOrder(orderID)
	if err != nil {
		t.Fatal(err)
	}
	if active != 700 {
		t.Errorf("sumActivePaymentsForOrder = %d, want 700 (overpay guard: 400 would mean the refund came off twice)", active)
	}
	captured, err := srv.sumCapturedPaymentsForOrder(orderID)
	if err != nil {
		t.Fatal(err)
	}
	if captured != 700 {
		t.Errorf("sumCapturedPaymentsForOrder = %d, want 700", captured)
	}
	if net := oldBinaryNet(t, srv, orderID); net != 700 {
		t.Errorf("a rolled-back binary reads %d, want 700", net)
	}
}

// Forward compatibility, stated as a property: whatever the refund path writes,
// the PREVIOUS binary's query shape must still net to the same money. That is
// only true while the fully-refunded original keeps a counted status.
func TestRefund_FullRefundStaysReadableByTheOldBinaryQuery(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "succeeded", 1000, 1000)
	if rec := postRefund(t, srv, orderID, payID, "k-full", `{}`); rec.Code != http.StatusOK {
		t.Fatalf("full refund want 200, got %d — %s", rec.Code, rec.Body.String())
	}

	if status, refunded, _ := originalRow(t, srv, payID); status != "succeeded" || refunded != 0 {
		t.Fatalf("original after full refund = (%q, %d), want (succeeded, 0)", status, refunded)
	}
	if net := oldBinaryNet(t, srv, orderID); net != 0 {
		t.Errorf("old-binary net = %d, want 0. A 'refunded' original drops out of its status filter, "+
			"leaving only the negative row — a shift that cannot close", net)
	}
	if captured, _ := srv.sumCapturedPaymentsForOrder(orderID); captured != 0 {
		t.Errorf("captured = %d, want 0", captured)
	}

	// Nothing left to refund.
	if rec := postRefund(t, srv, orderID, payID, "k-again", `{"amount":1}`); rec.Code != http.StatusConflict {
		t.Errorf("refunding a fully refunded payment want 409, got %d — %s", rec.Code, rec.Body.String())
	}
}

// The refundable balance is derived from the rows, so it cannot be over-drawn.
func TestRefund_RemainingIsDerivedFromTheRows(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "succeeded", 1000, 1000)
	if rec := postRefund(t, srv, orderID, payID, "k-1", `{"amount":600}`); rec.Code != http.StatusOK {
		t.Fatalf("refund want 200, got %d", rec.Code)
	}
	rec := postRefund(t, srv, orderID, payID, "k-2", `{"amount":500}`)
	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("over-drawing the balance want 422, got %d — %s", rec.Code, rec.Body.String())
	}
	if rows := signedRefundRows(t, srv, payID); len(rows) != 1 {
		t.Errorf("rejected refund must write nothing; rows = %d", len(rows))
	}
}

// A refund row carries `status='succeeded'`, so without an explicit guard it
// would pass the "is this refundable?" check and pay the money out twice.
func TestRefund_ARefundRowIsNotItselfRefundable(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "succeeded", 1000, 1000)
	if rec := postRefund(t, srv, orderID, payID, "k-1", `{"amount":400}`); rec.Code != http.StatusOK {
		t.Fatalf("refund want 200, got %d", rec.Code)
	}
	rows := signedRefundRows(t, srv, payID)
	if len(rows) != 1 {
		t.Fatalf("want 1 refund row, got %d", len(rows))
	}

	rec := postRefund(t, srv, orderID, rows[0].id, "k-2", `{"amount":100}`)
	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("refunding a refund row want 422, got %d — %s", rec.Code, rec.Body.String())
	}
	if _, paid, _ := orderColsRefund(t, srv, orderID); paid != 600 {
		t.Errorf("order paid_amount = %d, want 600 — the rejected refund must not move money", paid)
	}
}

// POS history keeps its shape: refund rows hidden, `refunded_amount` derived,
// and a fully-refunded payment still reads 'refunded' to pos-web.
func TestRefund_HistoryHidesRefundRowsAndDerivesRefundedAmount(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "succeeded", 1000, 1000)
	if rec := postRefund(t, srv, orderID, payID, "k-1", `{"amount":300}`); rec.Code != http.StatusOK {
		t.Fatalf("refund want 200, got %d", rec.Code)
	}

	list := srv.loadOrderPayments(orderID, "ja")
	if len(list) != 1 {
		t.Fatalf("history rows = %d, want 1 (the refund is not a second payment): %+v", len(list), list)
	}
	if list[0]["refunded_amount"] != 300 {
		t.Errorf("refunded_amount = %v, want 300", list[0]["refunded_amount"])
	}
	if list[0]["status"] != "succeeded" {
		t.Errorf("partially refunded payment status = %v, want succeeded", list[0]["status"])
	}

	if rec := postRefund(t, srv, orderID, payID, "k-2", `{"amount":700}`); rec.Code != http.StatusOK {
		t.Fatalf("second refund want 200, got %d", rec.Code)
	}
	list = srv.loadOrderPayments(orderID, "ja")
	if len(list) != 1 {
		t.Fatalf("history rows = %d, want 1", len(list))
	}
	if list[0]["status"] != "refunded" || list[0]["refunded_amount"] != 1000 {
		t.Errorf("fully refunded payment reads %v / %v, want refunded / 1000",
			list[0]["status"], list[0]["refunded_amount"])
	}
	if list[0]["refunded_at"] == nil || list[0]["refunded_at"] == "" {
		t.Errorf("refunded_at must be derived from the last refund row, got %v", list[0]["refunded_at"])
	}
}

// The unpair guard measures money Cloud has not acknowledged. A refund row never
// gets a cloud_id (it travels as a payment.refund op), so counting it would mean
// no shop that has ever refunded could unpair a device again.
func TestRefund_UnsyncedGuardIgnoresSignedRefundRows(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "succeeded", 1000, 1000)
	mustExec(t, srv.db, `UPDATE payments SET cloud_id = 'cloud-1' WHERE id = ?`, payID)
	mustExec(t, srv.db, `UPDATE orders SET synced_at = '2026-08-12T00:00:00Z' WHERE id = ?`, orderID)

	if rec := postRefund(t, srv, orderID, payID, "k-1", `{"amount":300}`); rec.Code != http.StatusOK {
		t.Fatalf("refund want 200, got %d", rec.Code)
	}

	sum := srv.unsyncedSummary()
	if sum.Payments != 0 {
		t.Errorf("unsynced payments = %d (amount %d), want 0: the refund row has no cloud_id and never will",
			sum.Payments, sum.Amount)
	}
}

// The drawer. A refund taken during an open shift comes off THAT shift's cash,
// and the 精算 figure must be the sale minus the refund — measured through
// reconcileSession, the same call the close screen makes.
func TestRefund_ShiftCashTotalNetsTheSignedRow(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "succeeded", 20000, 20000)
	mustExec(t, srv.db, `INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code, opening_float_amount, opened_at, till_id, branch_id)
		VALUES ('sess-r','SHIFT-1','open','2026-08-12','JPY',0,'2026-08-12T00:00:00Z','till-1','b1')`)
	mustExec(t, srv.db, `INSERT INTO tills (id, branch_id, code, current_session_id)
		VALUES ('till-1','b1','MAIN','sess-r')`)
	mustExec(t, srv.db,
		`UPDATE payments SET till_session_id = 'sess-r', created_at = '2026-08-12T02:00:00Z' WHERE id = ?`, payID)

	if rec := postRefund(t, srv, orderID, payID, "k-1", `{"amount":7980}`); rec.Code != http.StatusOK {
		t.Fatalf("refund want 200, got %d — %s", rec.Code, rec.Body.String())
	}

	rows := signedRefundRows(t, srv, payID)
	if len(rows) != 1 || rows[0].session != "sess-r" {
		t.Fatalf("refund must be attributed to the open shift, got %+v", rows)
	}

	recon, err := srv.reconcileSession("sess-r")
	if err != nil {
		t.Fatal(err)
	}
	const want = 12020.0 // 20,000 collected − 7,980 refunded
	if got := recon.CategoryExpected["cash"]; got != want {
		t.Errorf("category_expected[cash] = %v, want %v", got, want)
	}
	if got := recon.ExpectedCash; got != want {
		t.Errorf("expected_cash = %v, want %v — this is the number the cashier counts against", got, want)
	}
}

// The response body pos-web reads back is unchanged by the representation swap.
func TestRefund_ResponseStillReportsTheCumulativeFigure(t *testing.T) {
	srv, orderID, payID := seedRefundOrder(t, "succeeded", 1000, 1000)
	postRefund(t, srv, orderID, payID, "k-1", `{"amount":300}`)
	rec := postRefund(t, srv, orderID, payID, "k-2", `{"amount":200}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("refund want 200, got %d", rec.Code)
	}
	var body struct {
		Data struct {
			RefundedAmount int `json:"refunded_amount"`
			RefundAmount   int `json:"refund_amount"`
			Amount         int `json:"amount"`
		} `json:"data"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatal(err)
	}
	if body.Data.RefundedAmount != 500 || body.Data.RefundAmount != 200 || body.Data.Amount != 1000 {
		t.Errorf("response = %+v, want cumulative 500 / this refund 200 / original 1000", body.Data)
	}
}
