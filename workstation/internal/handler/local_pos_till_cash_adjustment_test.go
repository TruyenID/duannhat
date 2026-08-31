package handler

import (
	"encoding/json"
	"net/http/httptest"
	"strings"
	"testing"
)

// #1986 — 「tiền lẻ」 on the LOCAL till path.
//
// The reported defect was narrow: the draft handler was registered under POST
// while pos-web and Cloud both use PATCH, so it was unreachable. Measuring it
// turned up something larger sitting on the same line. `draftInput` — the struct
// that backs the draft AND the live `close`/`handover` — had no field for
// `closing_cash_adjustment`, so `encoding/json` discarded it silently.
//
// What that costs, in order of severity:
//
//   - `counted` cash is short by the adjustment, so `cash_variance` is wrong by
//     the same amount, and the tolerance gate DEMANDS A REASON for a shortfall
//     the drawer does not have. The cashier cannot close without inventing an
//     explanation for a discrepancy the software created.
//   - `settlement_snapshot` is immutable and feeds plan-046's chain aggregate.
//     A wrong figure there is not corrected later; it is summed.
//   - `enqueueShiftSettle` did not send the adjustment either, so Cloud
//     recomputed from `?? 0` and landed on the same wrong number. R7 then wrote
//     that back. Both sides agreed — and agreement is what let this survive:
//     there was no disagreement for any reconciliation to catch.
//
// These tests fix the arithmetic in place. The ROUTE half is guarded
// structurally in pos_api_manifest_parity_test.go, because a behavioural test
// here would pass whether or not the handler is reachable from the mux — which
// is exactly how the original bug hid.

// seedAdjShift inserts a session with a known expected-cash position: an
// opening float and one confirmed cash payment, so `reconcileSession` has a
// definite number to be measured against.
func seedAdjShift(t *testing.T, s *Server, sessionID string, openingFloat, cashSales float64) {
	t.Helper()

	if _, err := s.db.Exec(`INSERT INTO till_sessions
		(id, session_code, status, business_date, default_currency_code,
		 opening_float_amount, opened_at, till_id, branch_id)
		VALUES (?, 'S-ADJ', 'open', '2026-08-06', 'JPY', ?, '2026-08-06T09:00:00Z', 'till-1', 'b1')`,
		sessionID, openingFloat); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	if _, err := s.db.Exec(`INSERT INTO payments (id, order_id, payment_method, amount, status, created_at)
		VALUES ('pay-adj', 'o-adj', 'cash', ?, 'confirmed', '2026-08-06T10:00:00Z')`,
		cashSales); err != nil {
		t.Fatalf("seed payment: %v", err)
	}
	// One ¥1000 note so the count sheet can express part of the drawer, with the
	// rest necessarily falling to the adjustment.
	//
	// A plain INSERT, not `OR IGNORE`: the first draft used `OR IGNORE` and it
	// swallowed a NOT NULL violation on `currency_code`, so the row never
	// existed and all four settle tests failed with "unknown denomination_id" —
	// a seeding failure wearing the costume of a handler bug.
	if _, err := s.db.Exec(
		`INSERT INTO denominations (id, currency_code, value, kind)
		 VALUES ('d1000', 'JPY', 1000, 'note')`,
	); err != nil {
		t.Fatalf("seed denomination: %v", err)
	}
}

func settleBody(t *testing.T, adjustment *float64, note string) string {
	t.Helper()

	body := map[string]any{
		"closing_counts": []map[string]any{
			{"denomination_id": "d1000", "quantity": 5},
		},
		"tender_details": []any{},
		"closing_note":   note,
	}
	if adjustment != nil {
		body["closing_cash_adjustment"] = *adjustment
	}
	raw, err := json.Marshal(body)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}

	return string(raw)
}

func closeShift(t *testing.T, s *Server, sessionID, body string) *httptest.ResponseRecorder {
	t.Helper()

	req := httptest.NewRequest("POST", "/api/v1/pos/till/sessions/"+sessionID+"/close",
		strings.NewReader(body))
	req.SetPathValue("id", sessionID)
	rec := httptest.NewRecorder()
	s.handleLocalPosTillClose(rec, req)

	return rec
}

// A1 — the adjustment reaches counted cash, so the variance is ZERO on a drawer
// that balances. Expected = float 2000 + cash sales 3010; drawer = 5×¥1000 note
// plus ¥10 of loose change the sheet cannot express.
//
// Before the fix `counted` was 5000 against an expected 5010, i.e. a ¥10
// shortfall the drawer did not have.
func TestSettle_CashAdjustmentCountsTowardCountedCash(t *testing.T) {
	s := newFireTestServer(t)
	seedAdjShift(t, s, "sess-a1", 2000, 3010)

	adj := 10.0
	rec := closeShift(t, s, "sess-a1", settleBody(t, &adj, ""))
	if rec.Code != 200 {
		t.Fatalf("close returned %d: %s", rec.Code, rec.Body.String())
	}

	var counted, variance float64
	if err := s.db.QueryRow(
		`SELECT counted_cash, cash_variance FROM till_sessions WHERE id='sess-a1'`,
	).Scan(&counted, &variance); err != nil {
		t.Fatalf("read settled row: %v", err)
	}

	if counted != 5010 {
		t.Errorf("counted_cash = %v, want 5010 (5×1000 + 10 adjustment)", counted)
	}
	if variance != 0 {
		t.Errorf("cash_variance = %v, want 0 — the drawer balances", variance)
	}
}

// A2 — THE OPERATOR-VISIBLE FAILURE, and the reason this is a bug rather than a
// rounding detail: without the adjustment the close is REFUSED, and refused with
// a message telling the cashier to explain a shortfall that does not exist.
//
// Tolerance is 0 here (no `tills` row → the scan leaves it zero), so any
// non-zero variance trips the gate. With the adjustment counted, there is no
// variance and no reason is needed.
func TestSettle_CashAdjustmentPreventsASpuriousVarianceReasonDemand(t *testing.T) {
	s := newFireTestServer(t)
	seedAdjShift(t, s, "sess-a2", 2000, 3010)

	adj := 10.0
	if rec := closeShift(t, s, "sess-a2", settleBody(t, &adj, "")); rec.Code != 200 {
		t.Fatalf("a balanced drawer was refused: %d %s", rec.Code, rec.Body.String())
	}

	// The converse, to prove the gate still bites when a variance is REAL: same
	// drawer, no adjustment declared, so ¥10 is genuinely unaccounted for.
	s2 := newFireTestServer(t)
	seedAdjShift(t, s2, "sess-a2b", 2000, 3010)

	rec := closeShift(t, s2, "sess-a2b", settleBody(t, nil, ""))
	if rec.Code != 422 {
		t.Fatalf("an unexplained ¥10 shortfall must still be refused; got %d", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "VARIANCE_REASON_REQUIRED") {
		t.Errorf("wrong refusal: %s", rec.Body.String())
	}
}

// A3 — the adjustment must ride the sync payload to Cloud.
//
// This is the half that is easy to skip once the local numbers look right. Cloud
// does not adopt `counted_cash`; it RECOMPUTES from the counts plus
// `closing_cash_adjustment ?? 0`. Omit the key and Cloud lands on the pre-fix
// figure, then R7 writes its snapshot back over the correct local one — the bug
// returns through the sync layer wearing a different costume.
func TestSettle_SyncPayloadCarriesTheAdjustment(t *testing.T) {
	s := newFireTestServer(t)
	seedAdjShift(t, s, "sess-a3", 2000, 3010)

	adj := 10.0
	if rec := closeShift(t, s, "sess-a3", settleBody(t, &adj, "")); rec.Code != 200 {
		t.Fatalf("close failed: %d %s", rec.Code, rec.Body.String())
	}

	// `s.sync` is nil in this harness, so assert on the source of the payload
	// builder instead of a queued row. Weaker than observing the row, and said
	// plainly rather than dressed up: what it proves is that the key is built,
	// not that it was enqueued. The enqueue path itself is covered by the
	// existing till sync tests.
	src := readSourceFile(t, "local_pos_till.go")
	body := src[strings.Index(src, "func (s *Server) enqueueShiftSettle"):]
	body = body[:strings.Index(body, "\nfunc ")]
	if !strings.Contains(body, `"closing_cash_adjustment"`) {
		t.Fatal("enqueueShiftSettle no longer sends closing_cash_adjustment — " +
			"Cloud will recompute from 0 and R7 will write the wrong figure back")
	}
}

// A4 — a negative adjustment may not reduce counted cash.
//
// Cloud validates `min:0`. Locally there is no validator, so an unclamped
// negative would invent a shortfall out of a malformed request — the software
// asserting the drawer holds less than the cashier counted.
func TestSettle_NegativeAdjustmentIsIgnoredNotSubtracted(t *testing.T) {
	s := newFireTestServer(t)
	seedAdjShift(t, s, "sess-a4", 2000, 3000)

	adj := -500.0
	if rec := closeShift(t, s, "sess-a4", settleBody(t, &adj, "")); rec.Code != 200 {
		t.Fatalf("close failed: %d %s", rec.Code, rec.Body.String())
	}

	var counted float64
	if err := s.db.QueryRow(
		`SELECT counted_cash FROM till_sessions WHERE id='sess-a4'`,
	).Scan(&counted); err != nil {
		t.Fatalf("read: %v", err)
	}
	if counted != 5000 {
		t.Errorf("counted_cash = %v, want 5000 — a negative adjustment must not subtract", counted)
	}
}

// A5 — the draft persists the adjustment AND the session read hands it back.
//
// This is SC-11: the cashier counts, reloads, and the loose change is gone. Both
// halves are asserted together on purpose — storing a value nothing reads back
// is worse than not storing it, because the screen tells the cashier their work
// was kept.
func TestDraft_PersistsAdjustmentAndSessionReadReturnsIt(t *testing.T) {
	s := newFireTestServer(t)
	seedAdjShift(t, s, "sess-a5", 2000, 3000)

	adj := 990.0
	req := httptest.NewRequest("PATCH", "/api/v1/pos/till/sessions/sess-a5/draft",
		strings.NewReader(settleBody(t, &adj, "đếm dở")))
	req.SetPathValue("id", "sess-a5")
	rec := httptest.NewRecorder()
	s.handleLocalPosTillDraft(rec, req)
	if rec.Code != 200 {
		t.Fatalf("draft returned %d: %s", rec.Code, rec.Body.String())
	}

	sess, err := s.loadSession("sess-a5")
	if err != nil {
		t.Fatalf("loadSession: %v", err)
	}

	if got := sess["closing_cash_adjustment_amount"]; got != 990.0 {
		t.Errorf("closing_cash_adjustment_amount = %v, want 990 — the reload lost the loose change", got)
	}

	counts, ok := sess["closing_counts"].([]map[string]any)
	if !ok || len(counts) != 1 {
		t.Fatalf("closing_counts = %#v, want the one counted denomination — "+
			"without it the close screen has nothing to restore", sess["closing_counts"])
	}
	if counts[0]["quantity"] != 5 {
		t.Errorf("restored quantity = %v, want 5", counts[0]["quantity"])
	}
}

// A6 — the session read speaks Cloud's field names.
//
// pos-web's `TillSession` type declares `counted_cash_amount` /
// `cash_variance_amount`; this replica emitted `counted_cash` / `cash_variance`,
// so every locally-served session handed the close screen `undefined` for both.
// Nothing threw — `undefined` renders blank, and blank reads as "not counted
// yet" rather than as two sides that named the same field differently.
func TestLoadSession_UsesCloudsFieldNames(t *testing.T) {
	s := newFireTestServer(t)
	seedAdjShift(t, s, "sess-a6", 2000, 3000)

	sess, err := s.loadSession("sess-a6")
	if err != nil {
		t.Fatalf("loadSession: %v", err)
	}

	for _, key := range []string{
		"counted_cash_amount",
		"cash_variance_amount",
		"closing_cash_adjustment_amount",
		"closing_counts",
		"opening_counts",
	} {
		if _, ok := sess[key]; !ok {
			t.Errorf("missing %q — Cloud's TillSessionResource sends it, so the response "+
				"shape now depends on whether the shop happened to be online", key)
		}
	}

	// And the old spellings must be GONE, not kept alongside. Two names for one
	// field is how these shapes drifted this far apart to begin with.
	for _, dead := range []string{"counted_cash", "cash_variance"} {
		if _, ok := sess[dead]; ok {
			t.Errorf("%q is still emitted; Cloud's resource does not have it", dead)
		}
	}
}
