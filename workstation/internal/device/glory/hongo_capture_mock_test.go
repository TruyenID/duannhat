package glory

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

// Hongo live-capture fixtures (本郷店 YRT-R08-MN, 192.168.251.120,
// X-Server-Id 00-A0-58-90-30-24, 2026-08-10 JST). Behaviours below match the
// smoke we ran on the shop LAN: status/cash shapes, start→beginDeposit→cancel
// lag, idle cancel 404, parameter 400, double-start replaces the open txn.

const (
	hongoServerID = "00-A0-58-90-30-24"
	hongoFixture  = "testdata"
)

func loadFixture(t *testing.T, name string) []byte {
	t.Helper()
	raw, err := os.ReadFile(filepath.Join(hongoFixture, name))
	if err != nil {
		t.Fatalf("read fixture %s: %v", name, err)
	}
	return raw
}

func loadFixtureJSON(t *testing.T, name string, out any) {
	t.Helper()
	if err := json.Unmarshal(loadFixture(t, name), out); err != nil {
		t.Fatalf("decode fixture %s: %v", name, err)
	}
}

// hongoMock is an httptest adapter that serves the captured Hongo response
// shapes and the transaction lifecycle we observed on the real machine.
type hongoMock struct {
	mu sync.Mutex

	statusRaw []byte
	cashRaw   []byte

	seq         int // txn id suffix counter
	activeID    string
	activeTotal int
	activeStart time.Time
	deposit     int
	status      Status
	fixDeposit  bool
	// cancelLagLeft: after Cancel, the next N GetTransaction calls still report
	// beginDeposit (real machine: poll 1 still beginDeposit, poll 2 → cancel).
	cancelLagLeft int
	change        int
	dispensed     int
	endDate       string
	history       map[string]Transaction // terminal snapshots by id (double-start)

	startCalls  int
	cancelCalls int
	fixCalls    int
}

func newHongoMock(t *testing.T) *hongoMock {
	t.Helper()
	return &hongoMock{
		statusRaw: loadFixture(t, "machine_status.json"),
		cashRaw:   loadFixture(t, "machine_cash.json"),
		seq:       212202, // matches captured id suffix 20260810212202
		history:   map[string]Transaction{},
	}
}

func (m *hongoMock) server(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(m.handle))
	t.Cleanup(srv.Close)
	return srv
}

func (m *hongoMock) handle(w http.ResponseWriter, r *http.Request) {
	m.mu.Lock()
	defer m.mu.Unlock()

	w.Header().Set("X-Server-Id", hongoServerID)
	w.Header().Set("Content-Type", "application/json; charset=UTF-8")

	p := r.URL.Path
	switch {
	case r.Method == http.MethodGet && p == "/api/v1/machine/status":
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(m.statusRaw)

	case r.Method == http.MethodGet && p == "/api/v1/machine/cash":
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(m.cashRaw)

	case r.Method == http.MethodPost && p == "/api/v1/transactions":
		m.handleStart(w, r)

	case r.Method == http.MethodPost && p == "/api/v1/transactions/cancel":
		m.handleCancel(w)

	case r.Method == http.MethodPost && p == "/api/v1/transactions/fix-deposit":
		m.handleFix(w)

	case r.Method == http.MethodGet && strings.HasPrefix(p, "/api/v1/transactions/"):
		id := strings.TrimPrefix(p, "/api/v1/transactions/")
		m.handleGetTxn(w, id)

	default:
		// Real adapter: unknown paths → HTML 404 (not JSON).
		w.Header().Set("Content-Type", "text/html")
		http.Error(w, "<html><title>404: Not Found</title><body>404: Not Found</body></html>", http.StatusNotFound)
	}
}

func (m *hongoMock) handleStart(w http.ResponseWriter, r *http.Request) {
	m.startCalls++
	var body StartRequest
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		m.writeErrFixture(w, http.StatusBadRequest, "err_parameter.json")
		return
	}
	// Spec + live capture: total must be 1..9_999_999.
	if body.Total < 1 || body.Total > 9_999_999 {
		m.writeErrFixture(w, http.StatusBadRequest, "err_parameter.json")
		return
	}

	// Live observation: starting while a txn is open returns 200 with a NEW id;
	// the previous txn flips to cancel (workstation still serializes via mutex).
	if m.activeID != "" {
		m.status = StatusCancel
		m.endDate = time.Now().Format("2006-01-02T15:04:05.000")
		m.history[m.activeID] = m.txnBody(m.activeID)
	}

	m.seq++
	id := fmt.Sprintf("20260810%06d", m.seq)
	m.activeID = id
	m.activeTotal = body.Total
	m.activeStart = time.Now()
	m.deposit = 0
	m.status = StatusBeginDeposit
	m.fixDeposit = false
	m.cancelLagLeft = 0
	m.change = 0
	m.dispensed = 0
	m.endDate = ""

	writeJSON(w, map[string]string{"transactionId": id})
}

func (m *hongoMock) handleCancel(w http.ResponseWriter) {
	m.cancelCalls++
	if m.activeID == "" || m.status.IsTerminal() {
		m.writeErrFixture(w, http.StatusNotFound, "err_not_found.json")
		return
	}
	// Accept cancel immediately (HTTP 200 {}), but lag the status flip by one
	// GetTransaction poll — matches capture: cancel 200 → poll1 beginDeposit →
	// poll2 cancel.
	m.cancelLagLeft = 1
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write([]byte("{}"))
}

func (m *hongoMock) handleFix(w http.ResponseWriter) {
	m.fixCalls++
	if m.activeID == "" || m.status.IsTerminal() {
		m.writeErrFixture(w, http.StatusNotFound, "err_not_found.json")
		return
	}
	if m.deposit < m.activeTotal {
		writeErr(w, http.StatusPaymentRequired, "notEnough")
		return
	}
	m.fixDeposit = true
	m.status = StatusDispenseChange
	m.change = m.deposit - m.activeTotal
	w.WriteHeader(http.StatusOK)
}

func (m *hongoMock) handleGetTxn(w http.ResponseWriter, id string) {
	if id != m.activeID {
		if tx, ok := m.history[id]; ok {
			writeJSON(w, tx)
			return
		}
		// Unknown id → HTML 404 (live: GET …/does-not-exist).
		w.Header().Set("Content-Type", "text/html")
		http.Error(w, "<html><title>404: Not Found</title><body>404: Not Found</body></html>", http.StatusNotFound)
		return
	}

	// Cancel lag: poll N still beginDeposit, then flip to cancel (capture: N=1).
	if m.cancelLagLeft > 0 {
		m.cancelLagLeft--
		writeJSON(w, m.txnBodyWith(id, StatusBeginDeposit))
		if m.cancelLagLeft == 0 {
			m.status = StatusCancel
			m.endDate = time.Now().Format("2006-01-02T15:04:05.000")
			m.history[id] = m.txnBody(id)
		}
		return
	}

	if m.status == StatusDispenseChange {
		// One poll in dispense, then finish (same rhythm as fakeGlory).
		m.status = StatusFinish
		m.dispensed = m.change
		m.endDate = time.Now().Format("2006-01-02T15:04:05.000")
		m.history[id] = m.txnBody(id)
	} else if m.status.IsTerminal() {
		m.history[id] = m.txnBody(id)
	}

	writeJSON(w, m.txnBody(id))
}

func (m *hongoMock) txnBody(id string) Transaction {
	return m.txnBodyWith(id, m.status)
}

func (m *hongoMock) txnBodyWith(id string, st Status) Transaction {
	start := m.activeStart
	if start.IsZero() {
		start = time.Now()
	}
	return Transaction{
		TransactionID:     id,
		TransactionStatus: st,
		Total:             m.activeTotal,
		Deposit:           m.deposit,
		Change:            m.change,
		DispensedCash:     m.dispensed,
		FixDeposit:        m.fixDeposit,
		SeqNo:             time.Now().UnixMilli(),
		StartDate:         start.Format("2006-01-02T15:04:05.000"),
		EndDate:           m.endDate,
	}
}

func (m *hongoMock) writeErrFixture(w http.ResponseWriter, status int, name string) {
	raw, err := os.ReadFile(filepath.Join(hongoFixture, name))
	if err != nil {
		writeErr(w, status, "notFound")
		return
	}
	w.WriteHeader(status)
	_, _ = w.Write(raw)
}

// InsertCash simulates a customer feeding notes/coins (test helper).
func (m *hongoMock) InsertCash(amount int) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.activeID != "" && m.status == StatusBeginDeposit {
		m.deposit += amount
	}
}

// ─── Fixture decode (shape lock) ───────────────────────────────────────────

func TestHongoFixture_MachineStatusShape(t *testing.T) {
	var st StatusInfo
	loadFixtureJSON(t, "machine_status.json", &st)

	if st.Bill.ErrorCode != 0 || st.Coin.ErrorCode != 0 {
		t.Fatalf("errorCode bill=%d coin=%d, want 0/0", st.Bill.ErrorCode, st.Coin.ErrorCode)
	}
	if st.Bill.SetInfo != 0 || st.Coin.SetInfo != 2 {
		t.Fatalf("setInfo bill=%d coin=%d, want 0/2 (Hongo capture)", st.Bill.SetInfo, st.Coin.SetInfo)
	}
	if st.SeqNo != 1786364495964 {
		t.Fatalf("seqNo = %d, want captured 1786364495964", st.SeqNo)
	}

	wantStatus := map[string]string{
		"1": "enough", "5": "enough", "10": "enough", "50": "enough",
		"100": "enough", "500": "empty", "1000": "enough", "2000": "none",
		"5000": "nearEmpty", "10000": "enough",
		"billReject": "enough", "cassete": "enough", "overflow": "none",
	}
	for k, want := range wantStatus {
		if got := st.CashStatus[k]; got != want {
			t.Errorf("cashStatus[%s] = %q, want %q", k, got, want)
		}
	}
}

func TestHongoFixture_MachineCashShape(t *testing.T) {
	var inv Inventory
	loadFixtureJSON(t, "machine_cash.json", &inv)

	if inv.BillRejectCount != 0 {
		t.Fatalf("billRejectCount = %d, want 0", inv.BillRejectCount)
	}
	if inv.SeqNo != 1786364496219 {
		t.Fatalf("seqNo = %d, want captured 1786364496219", inv.SeqNo)
	}
	if inv.CashErrorStatus.Cassette {
		t.Fatal("cassette error flag true, want false")
	}

	// Zero-count denoms are OMITTED (¥500, ¥2000 absent → default 0).
	wantCounts := map[string]int{
		"1": 39, "5": 71, "10": 88, "50": 15, "100": 59,
		"1000": 113, "5000": 1, "10000": 7,
		"500": 0, "2000": 0,
	}
	total := 0
	for denom, want := range wantCounts {
		got := inv.CashCount.Cash[denom]
		if got != want {
			t.Errorf("cash[%s] = %d, want %d", denom, got, want)
		}
		n, _ := strconv.Atoi(denom)
		total += got * n
	}
	if total != 195_924 {
		t.Fatalf("stock total ¥%d, want ¥195924 (Hongo capture)", total)
	}

	// Live capture: most denoms flagged 在高不確定=true; ¥2000/¥10000 false.
	if inv.CashErrorStatus.Cash["5000"] != true {
		t.Error("cashErrorStatus.cash[5000] want true (nearEmpty + uncertain)")
	}
	if inv.CashErrorStatus.Cash["10000"] != false {
		t.Error("cashErrorStatus.cash[10000] want false")
	}
}

func TestHongoFixture_TxnBodies(t *testing.T) {
	var begin, cancel Transaction
	loadFixtureJSON(t, "txn_begin_deposit.json", &begin)
	loadFixtureJSON(t, "txn_cancel.json", &cancel)

	if begin.TransactionID != "20260810212202" || begin.TransactionStatus != StatusBeginDeposit {
		t.Fatalf("begin = %+v", begin)
	}
	if begin.Total != 100 || begin.Deposit != 0 || begin.FixDeposit {
		t.Fatalf("begin amounts = %+v", begin)
	}
	if begin.StartDate != "2026-08-10T21:22:02.014" {
		t.Fatalf("begin startDate = %q", begin.StartDate)
	}

	if cancel.TransactionStatus != StatusCancel || cancel.EndDate == "" {
		t.Fatalf("cancel = %+v", cancel)
	}
	if cancel.DispensedCash != 0 || cancel.Deposit != 0 {
		t.Fatalf("cancel with no cash inserted must keep deposit/dispensed 0: %+v", cancel)
	}
}

func TestHongoFixture_ErrorBodies(t *testing.T) {
	raw := loadFixture(t, "err_not_found.json")
	var nf struct{ Title, Detail string }
	if err := json.Unmarshal(raw, &nf); err != nil {
		t.Fatal(err)
	}
	if nf.Title != "notFound" || !strings.Contains(nf.Detail, "取引") {
		t.Fatalf("notFound fixture = %+v", nf)
	}

	raw = loadFixture(t, "err_parameter.json")
	var pe struct{ Title, Detail string }
	if err := json.Unmarshal(raw, &pe); err != nil {
		t.Fatal(err)
	}
	if pe.Title != "parameter" || pe.Detail == "" {
		t.Fatalf("parameter fixture = %+v", pe)
	}
}

// ─── Mock server behaviour (live smoke replay) ─────────────────────────────

func TestHongoMock_StatusAndInventory(t *testing.T) {
	m := newHongoMock(t)
	client := New(m.server(t).URL, nil)
	ctx := context.Background()

	st, err := client.GetStatus(ctx)
	if err != nil {
		t.Fatalf("GetStatus: %v", err)
	}
	if st.CashStatus["500"] != "empty" || st.CashStatus["5000"] != "nearEmpty" {
		t.Fatalf("cashStatus = %v", st.CashStatus)
	}

	inv, err := client.GetInventory(ctx)
	if err != nil {
		t.Fatalf("GetInventory: %v", err)
	}
	if inv.CashCount.Cash["1000"] != 113 || inv.CashCount.Cash["500"] != 0 {
		t.Fatalf("inventory = %+v", inv.CashCount.Cash)
	}
}

func TestHongoMock_StartCancelIdleLifecycle(t *testing.T) {
	m := newHongoMock(t)
	srv := m.server(t)
	client := New(srv.URL, nil)
	ctx := context.Background()

	// Idle cancel → 404 notFound (Japanese detail from fixture).
	err := client.Cancel(ctx)
	ge := asError(err)
	if ge == nil || !ge.IsNotFound() || ge.HTTPStatus != http.StatusNotFound {
		t.Fatalf("idle cancel err = %v, want 404 notFound", err)
	}
	if !strings.Contains(ge.Detail, "取引") {
		t.Fatalf("detail = %q, want captured Japanese text", ge.Detail)
	}

	id, err := client.StartTransaction(ctx, StartRequest{
		Total: 100, ShowFixDepositButton: false, Timeout: 30,
	})
	if err != nil {
		t.Fatalf("Start: %v", err)
	}
	if !strings.HasPrefix(id, "20260810") {
		t.Fatalf("txn id = %q, want 20260810… capture format", id)
	}

	tx, err := client.GetTransaction(ctx, id)
	if err != nil {
		t.Fatalf("GetTransaction: %v", err)
	}
	if tx.TransactionStatus != StatusBeginDeposit || tx.Deposit != 0 || tx.Total != 100 {
		t.Fatalf("after start: %+v", tx)
	}

	if err := client.Cancel(ctx); err != nil {
		t.Fatalf("Cancel: %v", err)
	}

	// Lag (live capture): poll 1 still beginDeposit, poll 2 → cancel.
	tx, err = client.GetTransaction(ctx, id)
	if err != nil {
		t.Fatalf("poll1: %v", err)
	}
	if tx.TransactionStatus != StatusBeginDeposit {
		t.Fatalf("poll1 status = %q, want beginDeposit (cancel lag)", tx.TransactionStatus)
	}
	tx, err = client.GetTransaction(ctx, id)
	if err != nil {
		t.Fatalf("poll2: %v", err)
	}
	if tx.TransactionStatus != StatusCancel {
		t.Fatalf("poll2 status = %q, want cancel", tx.TransactionStatus)
	}
	if tx.EndDate == "" {
		t.Fatal("cancel body missing endDate")
	}

	// Idle again.
	err = client.Cancel(ctx)
	ge = asError(err)
	if ge == nil || !ge.IsNotFound() {
		t.Fatalf("post-cancel idle err = %v, want notFound", err)
	}
}

func TestHongoMock_InvalidTotalParameter(t *testing.T) {
	m := newHongoMock(t)
	client := New(m.server(t).URL, nil)
	ctx := context.Background()

	for _, total := range []int{0, 99_999_999} {
		_, err := client.StartTransaction(ctx, StartRequest{Total: total})
		ge := asError(err)
		if ge == nil || ge.HTTPStatus != http.StatusBadRequest || ge.Title != "parameter" {
			t.Fatalf("total=%d err = %v, want 400 parameter", total, err)
		}
		if !strings.Contains(ge.Detail, "パラメータ") {
			t.Fatalf("total=%d detail = %q, want captured Japanese", total, ge.Detail)
		}
	}
}

func TestHongoMock_FixDepositWithoutTxn(t *testing.T) {
	m := newHongoMock(t)
	client := New(m.server(t).URL, nil)

	err := client.FixDeposit(context.Background())
	ge := asError(err)
	if ge == nil || !ge.IsNotFound() {
		t.Fatalf("err = %v, want notFound", err)
	}
}

func TestHongoMock_UnknownTxnHTML404(t *testing.T) {
	m := newHongoMock(t)
	client := New(m.server(t).URL, nil)

	_, err := client.GetTransaction(context.Background(), "does-not-exist")
	ge := asError(err)
	if ge == nil || ge.HTTPStatus != http.StatusNotFound {
		t.Fatalf("err = %v, want 404", err)
	}
	// HTML body → empty Title, raw HTML in Detail (client.do contract).
	if ge.Title != "" {
		t.Fatalf("title = %q, want empty for HTML 404", ge.Title)
	}
	if !strings.Contains(ge.Detail, "404") {
		t.Fatalf("detail = %q, want HTML 404 body", ge.Detail)
	}
}

func TestHongoMock_DoubleStartReplacesOpenTxn(t *testing.T) {
	m := newHongoMock(t)
	client := New(m.server(t).URL, nil)
	ctx := context.Background()

	id1, err := client.StartTransaction(ctx, StartRequest{Total: 100, Timeout: 20})
	if err != nil {
		t.Fatalf("start1: %v", err)
	}
	id2, err := client.StartTransaction(ctx, StartRequest{Total: 200, Timeout: 20})
	if err != nil {
		t.Fatalf("start2: %v", err)
	}
	if id1 == id2 {
		t.Fatalf("expected new txn id, got same %s", id1)
	}

	tx1, err := client.GetTransaction(ctx, id1)
	if err != nil {
		t.Fatalf("get1: %v", err)
	}
	if tx1.TransactionStatus != StatusCancel {
		t.Fatalf("replaced txn1 status = %q, want cancel", tx1.TransactionStatus)
	}

	tx2, err := client.GetTransaction(ctx, id2)
	if err != nil {
		t.Fatalf("get2: %v", err)
	}
	if tx2.TransactionStatus != StatusBeginDeposit || tx2.Total != 200 {
		t.Fatalf("txn2 = %+v", tx2)
	}

	// Cleanup like the live smoke.
	_ = client.Cancel(ctx)
	deadline := time.Now().Add(2 * time.Second)
	for {
		tx2, err = client.GetTransaction(ctx, id2)
		if err != nil {
			t.Fatalf("poll2: %v", err)
		}
		if tx2.TransactionStatus.IsTerminal() {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("txn2 never terminal")
		}
		time.Sleep(5 * time.Millisecond)
	}
}

func TestHongoMock_CollectFinishWithCapturedInventoryPath(t *testing.T) {
	// Full collector path against the capture-shaped mock: insert ¥1000 for a
	// ¥865 total → change ¥135, status finish. Proves fixtures + state machine
	// still compose (same numbers as TestCollect_Finish, different transport).
	m := newHongoMock(t)
	srv := m.server(t)
	col := NewCollector(New(srv.URL, nil),
		WithPollInterval(time.Millisecond),
		WithDepositTimeout(5*time.Second))

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	go func() {
		// Wait until start opens the txn, then feed cash.
		for i := 0; i < 50; i++ {
			m.mu.Lock()
			ready := m.activeID != "" && m.status == StatusBeginDeposit
			m.mu.Unlock()
			if ready {
				m.InsertCash(1000)
				return
			}
			time.Sleep(time.Millisecond)
		}
	}()

	res, err := col.Collect(ctx, 865)
	if err != nil {
		t.Fatalf("Collect: %v", err)
	}
	if res.Status != StatusFinish || res.Tendered != 1000 || res.Change != 135 {
		t.Fatalf("result = %+v, want finish tendered 1000 change 135", res)
	}
	if m.fixCalls != 1 {
		t.Fatalf("fixCalls = %d, want 1", m.fixCalls)
	}
}

func TestHongoMock_ServerIDHeaderPresent(t *testing.T) {
	m := newHongoMock(t)
	srv := m.server(t)

	resp, err := http.Get(srv.URL + "/api/v1/machine/status")
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	if got := resp.Header.Get("X-Server-Id"); got != hongoServerID {
		t.Fatalf("X-Server-Id = %q, want %q", got, hongoServerID)
	}
}
