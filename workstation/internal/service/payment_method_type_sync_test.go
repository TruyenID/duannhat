package service

import (
	"context"
	"database/sql"
	"net/http"
	"net/http/httptest"
	"testing"
)

// `payment_methods.type` is the BEHAVIOURAL classification (cash / card /
// on_account / …) and the only way the workstation can tell a debt method from
// a cash one — `code` cannot, because it is shop-editable.
//
// It was never mirrored. The column carries `NOT NULL DEFAULT 'cash'`, so the
// omission did not surface as a null anybody would notice: every method,
// including the debt one, read back as cash. Two LAN money paths went wrong on
// that, silently and permanently:
//
//   - handleLANPrintDebtSlip refuses anything that is not `on_account`, so
//     every 掛売 slip printed over LAN answered `payment_method_not_on_account`.
//   - ComputeTillDebtSummary sums `pm.type = 'on_account'`, so the shift report
//     said 0 debt issued however much had been recorded — printed next to a
//     "settled" figure that does not read the column at all.

const paymentMethodsBody = `{"data":[
	{"id":"pm-cash","code":"cash","name":"Cash","is_active":true,"sort_order":1,
	 "is_auto_confirm":true,"requires_tendered":true,"type":"cash"},
	{"id":"pm-debt","code":"debt","name":"On account","is_active":true,"sort_order":7,
	 "is_auto_confirm":true,"requires_tendered":false,"type":"on_account"}
]}`

func TestPullPaymentMethods_MirrorsType(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/payment-methods" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(paymentMethodsBody))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullPaymentMethods(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var debtType, cashType string
	if err := db.QueryRow(`SELECT type FROM payment_methods WHERE code = 'debt'`).Scan(&debtType); err != nil {
		t.Fatalf("read debt row: %v", err)
	}
	if err := db.QueryRow(`SELECT type FROM payment_methods WHERE code = 'cash'`).Scan(&cashType); err != nil {
		t.Fatalf("read cash row: %v", err)
	}

	if debtType != "on_account" {
		t.Errorf("debt method mirrored as %q — the column default made it look like cash", debtType)
	}
	if cashType != "cash" {
		t.Errorf("cash method mirrored as %q", cashType)
	}
}

// A Cloud older than the feed change sends no `type`. Falling back to the
// column's historical default keeps those installs exactly where they were,
// rather than writing an empty string no reader knows how to interpret.
func TestPullPaymentMethods_OldCloudFallsBackToCash(t *testing.T) {
	const body = `{"data":[
		{"id":"pm-cash","code":"cash","name":"Cash","is_active":true,"sort_order":1,
		 "is_auto_confirm":true,"requires_tendered":true}
	]}`
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(body))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullPaymentMethods(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var got string
	if err := db.QueryRow(`SELECT type FROM payment_methods WHERE code = 'cash'`).Scan(&got); err != nil {
		t.Fatalf("read: %v", err)
	}
	if got != "cash" {
		t.Errorf("want the historical default, got %q", got)
	}
}

// The consumer that decides how much 掛売 a shift issued. Before the mirror
// carried `type`, this returned 0 for every shift.
func TestTillDebtSummary_CountsDebtOnceTypeIsMirrored(t *testing.T) {
	db := newTillDebtTestDB(t)

	if _, err := db.Exec(`
		INSERT INTO payment_methods (id, code, name, is_active, sort_order,
		    is_auto_confirm, requires_tendered, type)
		VALUES ('pm-debt','debt','On account',1,7,1,0,'on_account')`); err != nil {
		t.Fatal(err)
	}
	seedTillDebt(t, db, "pay-1", "succeeded", "2496", `{}`, "2026-06-20T11:00:00Z", "pm-debt", "debt")

	sum, err := ComputeTillDebtSummary(db, "2026-06-20T00:00:00Z",
		sql.NullString{String: "2026-06-20T23:00:00Z", Valid: true})
	if err != nil {
		t.Fatalf("ComputeTillDebtSummary: %v", err)
	}
	if sum.TotalIssued != 2496 {
		t.Errorf("shift report must see the debt it issued, got %v", sum.TotalIssued)
	}
}

// The same row with the pre-fix mirror value: the debt is invisible to the
// shift report. Kept as the explicit statement of what the bug looked like, so
// a future change that stops writing `type` fails here with its own name on it.
func TestTillDebtSummary_BlindWhenTypeIsWrong(t *testing.T) {
	db := newTillDebtTestDB(t)

	if _, err := db.Exec(`
		INSERT INTO payment_methods (id, code, name, is_active, sort_order,
		    is_auto_confirm, requires_tendered, type)
		VALUES ('pm-debt','debt','On account',1,7,1,0,'cash')`); err != nil {
		t.Fatal(err)
	}
	seedTillDebt(t, db, "pay-1", "succeeded", "2496", `{}`, "2026-06-20T11:00:00Z", "pm-debt", "debt")

	sum, err := ComputeTillDebtSummary(db, "2026-06-20T00:00:00Z",
		sql.NullString{String: "2026-06-20T23:00:00Z", Valid: true})
	if err != nil {
		t.Fatalf("ComputeTillDebtSummary: %v", err)
	}
	if sum.TotalIssued != 0 {
		t.Fatalf("this case documents the DEFECT: with type='cash' the summary "+
			"cannot see the debt. Got %v — if this now passes, the query stopped "+
			"keying on type and this test should be rewritten, not deleted", sum.TotalIssued)
	}
}
