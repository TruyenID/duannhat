package glory

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeConfig scripts the fake adapter's behaviour for one scenario.
type fakeConfig struct {
	total             int
	depositAmount     int  // deposit the "customer" inserts (reported once polling starts)
	forbidden         bool // 403 on everything (IP not allowed)
	emptyOnFix        bool // fix-deposit → 503 "empty" (change shortage)
	reportTimeout     bool // GetTransaction reports status=timeout
	failureOnDispense bool // after fix, report status=failure (error while dispensing)
	machineErrorOnce  bool // first GetTransaction → 503 "error", then recover
}

// fakeGlory is an in-memory YRT-R08-MN adapter for tests. It models the
// transaction state machine closely enough to exercise Collector end-to-end.
type fakeGlory struct {
	mu           sync.Mutex
	cfg          fakeConfig
	deposit      int
	phase        string // "deposit" | "dispense" | ""
	canceled     bool
	errServed    bool
	dispensePoll int
	fixCalls     int
	cancelCalls  int
}

func newFake(cfg fakeConfig) *fakeGlory { return &fakeGlory{cfg: cfg, phase: "deposit"} }

func (f *fakeGlory) server(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(f.handle))
	t.Cleanup(srv.Close)
	return srv
}

func writeErr(w http.ResponseWriter, status int, title string) {
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(map[string]string{"title": title, "detail": title})
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(v)
}

func (f *fakeGlory) handle(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()

	if f.cfg.forbidden {
		writeErr(w, http.StatusForbidden, "forbidden")
		return
	}

	p := r.URL.Path
	switch {
	case r.Method == http.MethodPost && p == "/api/v1/transactions":
		writeJSON(w, map[string]string{"transactionId": "T1"})

	case r.Method == http.MethodPost && p == "/api/v1/transactions/fix-deposit":
		f.fixCalls++
		if f.cfg.emptyOnFix {
			writeErr(w, http.StatusServiceUnavailable, "empty")
			return
		}
		if f.deposit < f.cfg.total {
			writeErr(w, http.StatusPaymentRequired, "notEnough")
			return
		}
		f.phase = "dispense"
		w.WriteHeader(http.StatusOK)

	case r.Method == http.MethodPost && p == "/api/v1/transactions/cancel":
		f.cancelCalls++
		f.canceled = true
		w.WriteHeader(http.StatusOK)

	case r.Method == http.MethodGet && strings.HasPrefix(p, "/api/v1/transactions/"):
		f.getTransaction(w)

	case r.Method == http.MethodGet && p == "/api/v1/machine/status":
		writeJSON(w, sampleStatusJSON())

	case r.Method == http.MethodGet && p == "/api/v1/machine/cash":
		writeJSON(w, sampleInventoryJSON())

	default:
		http.Error(w, "<html>not found</html>", http.StatusNotFound)
	}
}

func (f *fakeGlory) getTransaction(w http.ResponseWriter) {
	if f.cfg.machineErrorOnce && !f.errServed {
		f.errServed = true
		writeErr(w, http.StatusServiceUnavailable, "error")
		return
	}
	if f.canceled {
		writeJSON(w, Transaction{TransactionID: "T1", TransactionStatus: StatusCancel,
			Total: f.cfg.total, Deposit: f.deposit, DispensedCash: f.deposit})
		return
	}
	switch f.phase {
	case "deposit":
		f.deposit = f.cfg.depositAmount // customer inserts cash
		if f.cfg.reportTimeout {
			writeJSON(w, Transaction{TransactionID: "T1", TransactionStatus: StatusTimeout,
				Total: f.cfg.total, Deposit: f.deposit})
			return
		}
		writeJSON(w, Transaction{TransactionID: "T1", TransactionStatus: StatusBeginDeposit,
			Total: f.cfg.total, Deposit: f.deposit, FixDeposit: false})
	case "dispense":
		change := f.deposit - f.cfg.total
		if f.cfg.failureOnDispense {
			writeJSON(w, Transaction{TransactionID: "T1", TransactionStatus: StatusFailure,
				Total: f.cfg.total, Deposit: f.deposit, DispensedCash: 0, FixDeposit: true})
			return
		}
		f.dispensePoll++
		if f.dispensePoll == 1 { // first poll after fix: dispensing
			writeJSON(w, Transaction{TransactionID: "T1", TransactionStatus: StatusDispenseChange,
				Total: f.cfg.total, Deposit: f.deposit, Change: change, FixDeposit: true})
			return
		}
		writeJSON(w, Transaction{TransactionID: "T1", TransactionStatus: StatusFinish,
			Total: f.cfg.total, Deposit: f.deposit, Change: change, DispensedCash: change, FixDeposit: true})
	}
}

func sampleStatusJSON() map[string]any {
	return map[string]any{
		"bill": map[string]any{"errorCode": 0, "setInfo": 72},
		"coin": map[string]any{"errorCode": 0, "setInfo": 64},
		"cashStatus": map[string]any{
			"1": "empty", "5": "nearEmpty", "100": "enough", "10000": "enough",
			"billReject": "empty", "cassete": "none", "overflow": "none",
		},
		"seqNo": 1638262502654,
	}
}

func sampleInventoryJSON() map[string]any {
	// Note: zero-count denominations are OMITTED (spec) — only non-zero appear.
	return map[string]any{
		"cashCount": map[string]any{
			"cash": map[string]any{"1": 10, "100": 50, "10000": 90},
		},
		"cashErrorStatus": map[string]any{
			"cash":     map[string]any{"100": false},
			"cassette": false,
		},
		"billRejectCount": 0,
		"seqNo":           1638262502654,
	}
}

func fastCollector(t *testing.T, cfg fakeConfig) (*Collector, *fakeGlory) {
	t.Helper()
	f := newFake(cfg)
	srv := f.server(t)
	c := NewCollector(New(srv.URL, nil),
		WithPollInterval(time.Millisecond),
		WithDepositTimeout(5*time.Second))
	return c, f
}

func TestCollect_Finish(t *testing.T) {
	c, f := fastCollector(t, fakeConfig{total: 8650, depositAmount: 10000})

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	res, err := c.Collect(ctx, 8650)
	if err != nil {
		t.Fatalf("Collect: unexpected error: %v", err)
	}
	if res.Status != StatusFinish {
		t.Errorf("status = %q, want finish", res.Status)
	}
	if res.Total != 8650 || res.Tendered != 10000 || res.Change != 1350 {
		t.Errorf("result = %+v, want total 8650 tendered 10000 change 1350", res)
	}
	if f.fixCalls != 1 {
		t.Errorf("fixCalls = %d, want exactly 1", f.fixCalls)
	}
}

func TestCollect_MachineErrorThenRecover(t *testing.T) {
	// First GetTransaction 503 "error" (jam during deposit) — Collector must keep
	// polling and still finish once the machine recovers.
	c, _ := fastCollector(t, fakeConfig{total: 1000, depositAmount: 1000, machineErrorOnce: true})

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	res, err := c.Collect(ctx, 1000)
	if err != nil {
		t.Fatalf("Collect: unexpected error: %v", err)
	}
	if res.Status != StatusFinish || res.Change != 0 {
		t.Errorf("result = %+v, want finish change 0", res)
	}
}

func TestCollect_ChangeShortage(t *testing.T) {
	c, f := fastCollector(t, fakeConfig{total: 1000, depositAmount: 5000, emptyOnFix: true})

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	_, err := c.Collect(ctx, 1000)
	if !errors.Is(err, ErrChangeShortage) {
		t.Fatalf("err = %v, want ErrChangeShortage", err)
	}
	if f.cancelCalls != 1 {
		t.Errorf("cancelCalls = %d, want 1 (must cancel on shortage)", f.cancelCalls)
	}
}

func TestCollect_Timeout(t *testing.T) {
	c, _ := fastCollector(t, fakeConfig{total: 1000, depositAmount: 500, reportTimeout: true})

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	res, err := c.Collect(ctx, 1000)
	if !errors.Is(err, ErrTimedOut) {
		t.Fatalf("err = %v, want ErrTimedOut", err)
	}
	if res.Status != StatusTimeout {
		t.Errorf("status = %q, want timeout", res.Status)
	}
}

func TestCollect_FailureDuringDispense(t *testing.T) {
	// Reconcile-manually path: deposit taken, dispense failed — Result must carry
	// the tendered amount for drawer reconciliation.
	c, _ := fastCollector(t, fakeConfig{total: 1000, depositAmount: 5000, failureOnDispense: true})

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	res, err := c.Collect(ctx, 1000)
	if !errors.Is(err, ErrFailed) {
		t.Fatalf("err = %v, want ErrFailed", err)
	}
	if res.Tendered != 5000 || res.Change != 0 {
		t.Errorf("result = %+v, want tendered 5000 change 0 for reconciliation", res)
	}
}

func TestCollect_Cancel(t *testing.T) {
	// depositAmount < total → Collector never fixes, keeps polling; an external
	// Cancel (the POS button) drives it to the cancel terminal state.
	c, f := fastCollector(t, fakeConfig{total: 1000, depositAmount: 500})

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	go func() {
		time.Sleep(20 * time.Millisecond)
		_ = c.Cancel(context.Background())
	}()

	_, err := c.Collect(ctx, 1000)
	if !errors.Is(err, ErrCanceled) {
		t.Fatalf("err = %v, want ErrCanceled", err)
	}
	if f.cancelCalls != 1 {
		t.Errorf("cancelCalls = %d, want 1", f.cancelCalls)
	}
}

func TestClient_Forbidden(t *testing.T) {
	f := newFake(fakeConfig{forbidden: true})
	srv := f.server(t)
	client := New(srv.URL, nil)

	_, err := client.StartTransaction(context.Background(), StartRequest{Total: 1000})
	ge := asError(err)
	if ge == nil || !ge.IsForbidden() || ge.HTTPStatus != http.StatusForbidden {
		t.Fatalf("err = %v, want 403 forbidden *Error", err)
	}
}

func TestClient_GetStatus(t *testing.T) {
	f := newFake(fakeConfig{})
	srv := f.server(t)
	client := New(srv.URL, nil)

	st, err := client.GetStatus(context.Background())
	if err != nil {
		t.Fatalf("GetStatus: %v", err)
	}
	if st.Bill.ErrorCode != 0 || st.Bill.SetInfo != 72 {
		t.Errorf("bill = %+v, want errorCode 0 setInfo 72", st.Bill)
	}
	if st.CashStatus["1"] != "empty" || st.CashStatus["5"] != "nearEmpty" {
		t.Errorf("cashStatus = %v, want 1:empty 5:nearEmpty", st.CashStatus)
	}
}

func TestClient_GetInventory_OmitsZero(t *testing.T) {
	f := newFake(fakeConfig{})
	srv := f.server(t)
	client := New(srv.URL, nil)

	inv, err := client.GetInventory(context.Background())
	if err != nil {
		t.Fatalf("GetInventory: %v", err)
	}
	if inv.CashCount.Cash["100"] != 50 {
		t.Errorf("cash[100] = %d, want 50", inv.CashCount.Cash["100"])
	}
	// A zero-count denom is omitted from JSON → default 0, not an error.
	if inv.CashCount.Cash["500"] != 0 {
		t.Errorf("cash[500] = %d, want 0 (omitted denom defaults to zero)", inv.CashCount.Cash["500"])
	}
	if inv.BillRejectCount != 0 {
		t.Errorf("billRejectCount = %d, want 0", inv.BillRejectCount)
	}
}
