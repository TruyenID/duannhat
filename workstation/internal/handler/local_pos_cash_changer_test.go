package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	stdsync "sync"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// fakeGloryFinish is a minimal YRT-R08-MN that always drives one transaction to
// finish: deposit reaches `deposit` immediately, and after fix-deposit it
// dispenses `deposit-total` change.
func fakeGloryFinish(t *testing.T, total, deposit int) *httptest.Server {
	t.Helper()
	var mu stdsync.Mutex
	phase := "deposit"
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		switch {
		case r.Method == http.MethodPost && r.URL.Path == "/api/v1/transactions":
			_ = json.NewEncoder(w).Encode(map[string]string{"transactionId": "T1"})
		case r.Method == http.MethodPost && r.URL.Path == "/api/v1/transactions/fix-deposit":
			phase = "dispense"
			w.WriteHeader(http.StatusOK)
		case r.Method == http.MethodGet && strings.HasPrefix(r.URL.Path, "/api/v1/transactions/"):
			if phase == "deposit" {
				_ = json.NewEncoder(w).Encode(glory.Transaction{
					TransactionID: "T1", TransactionStatus: glory.StatusBeginDeposit,
					Total: total, Deposit: deposit,
				})
			} else {
				_ = json.NewEncoder(w).Encode(glory.Transaction{
					TransactionID: "T1", TransactionStatus: glory.StatusFinish,
					Total: total, Deposit: deposit, DispensedCash: deposit - total, FixDeposit: true,
				})
			}
		default:
			http.Error(w, "not found", http.StatusNotFound)
		}
	}))
	t.Cleanup(srv.Close)
	return srv
}

func withFastCashChanger(t *testing.T, s *Server, url string) {
	t.Helper()
	// The gate reads cashChangerURL() (registry/env), so satisfy it via env; the
	// collector itself still targets the fake server at the static url.
	t.Setenv("WS_APP_CASH_CHANGER_URL", url)
	s.cashChanger = service.NewCashChangerService(
		glory.NewCollector(glory.New(url, nil),
			glory.WithPollInterval(time.Millisecond),
			glory.WithDepositTimeout(2*time.Second)),
		s)
}

// withRegistryCashChanger wires the machine the way production does: the adapter
// URL comes from a Cloud-synced peripheral_devices row (type coin_changer), and
// the collector resolves it per request via s.cashChangerURL.
func withRegistryCashChanger(t *testing.T, s *Server, url string) {
	t.Helper()
	t.Setenv("WS_APP_CASH_CHANGER_URL", "") // registry must win on its own
	s.db.Exec(`INSERT INTO peripheral_devices (id, name, type, is_active, metadata)
	           VALUES ('cc1', 'Counter 釣銭機', 'coin_changer', 1, ?)`,
		`{"url":"`+url+`"}`)
	s.cashChanger = service.NewCashChangerService(
		glory.NewCollector(glory.NewResolving(s.cashChangerURL, nil),
			glory.WithPollInterval(time.Millisecond),
			glory.WithDepositTimeout(2*time.Second)),
		s)
}

func TestCashChangerHTTP_CollectFlow(t *testing.T) {
	s := newRecorderServer(t)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 8650)`)
	withFastCashChanger(t, s, fakeGloryFinish(t, 8650, 10000).URL)

	// Start.
	rr := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/pos/cash-changer/collect",
		strings.NewReader(`{"order_id":"o1"}`))
	s.handleCashChangerCollect(cashChangerKiosk)(rr, req)
	if rr.Code != http.StatusAccepted {
		t.Fatalf("collect start = %d, body %s, want 202", rr.Code, rr.Body.String())
	}
	var started struct {
		Data struct {
			SessionID string `json:"session_id"`
			Total     int    `json:"total"`
		} `json:"data"`
	}
	if err := json.Unmarshal(rr.Body.Bytes(), &started); err != nil {
		t.Fatal(err)
	}
	if started.Data.SessionID == "" || started.Data.Total != 8650 {
		t.Fatalf("start payload = %+v, want a session id + total 8650", started.Data)
	}

	// Poll status (via a request carrying the {session} path value) until done.
	final := pollCashStatus(t, s, started.Data.SessionID)
	if final["status"] != "finish" || final["payment_id"] == "" {
		t.Fatalf("final status = %+v, want finish + payment_id", final)
	}
	if int(final["change"].(float64)) != 1350 || int(final["tendered"].(float64)) != 10000 {
		t.Errorf("final money = %+v, want tendered 10000 change 1350", final)
	}

	// The cash payment landed in SQLite and closed the order.
	var oStatus string
	var paid int
	s.db.QueryRow(`SELECT status, paid_amount FROM orders WHERE id='o1'`).Scan(&oStatus, &paid)
	if oStatus != "closed" || paid != 8650 {
		t.Errorf("order = %s paid %d, want closed/8650", oStatus, paid)
	}
}

func pollCashStatus(t *testing.T, s *Server, sessionID string) map[string]any {
	t.Helper()
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		rr := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/cash-changer/collect/"+sessionID, nil)
		req.SetPathValue("session", sessionID)
		s.handleCashChangerStatus(rr, req)
		if rr.Code != http.StatusOK {
			t.Fatalf("status = %d, body %s", rr.Code, rr.Body.String())
		}
		var out struct {
			Data map[string]any `json:"data"`
		}
		json.Unmarshal(rr.Body.Bytes(), &out)
		if running, _ := out.Data["running"].(bool); !running {
			return out.Data
		}
		time.Sleep(2 * time.Millisecond)
	}
	t.Fatal("collection did not finish")
	return nil
}

func TestCashChangerHTTP_ConfigFromRegistry(t *testing.T) {
	// Primary path: the adapter URL comes from the Cloud-synced peripheral
	// registry (type coin_changer), resolved per request — no env.
	s := newRecorderServer(t)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 8650)`)
	withRegistryCashChanger(t, s, fakeGloryFinish(t, 8650, 10000).URL)

	rr := httptest.NewRecorder()
	s.handleCashChangerCollect(cashChangerKiosk)(rr, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/cash-changer/collect", strings.NewReader(`{"order_id":"o1"}`)))
	if rr.Code != http.StatusAccepted {
		t.Fatalf("collect = %d, body %s, want 202 (configured from registry)", rr.Code, rr.Body.String())
	}
	var started struct {
		Data struct {
			SessionID string `json:"session_id"`
		} `json:"data"`
	}
	json.Unmarshal(rr.Body.Bytes(), &started)

	final := pollCashStatus(t, s, started.Data.SessionID)
	if final["status"] != "finish" || final["payment_id"] == "" {
		t.Fatalf("final = %+v, want finish + payment_id via registry-resolved machine", final)
	}
}

func TestCashChangerHTTP_NoMachine503(t *testing.T) {
	t.Setenv("WS_APP_CASH_CHANGER_URL", "") // no registry row, no env
	s := newRecorderServer(t)
	rr := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/pos/cash-changer/collect",
		strings.NewReader(`{"order_id":"o1"}`))
	s.handleCashChangerCollect(cashChangerKiosk)(rr, req)
	if rr.Code != http.StatusServiceUnavailable {
		t.Fatalf("code = %d, want 503 when no machine configured", rr.Code)
	}
}

func TestCashChangerHTTP_Busy409(t *testing.T) {
	s := newRecorderServer(t)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 1000)`)
	// A slow fake (never finishes within the test) keeps the first session running.
	slow := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.Method == http.MethodPost && r.URL.Path == "/api/v1/transactions" {
			_ = json.NewEncoder(w).Encode(map[string]string{"transactionId": "T1"})
			return
		}
		// Always beginDeposit with deposit < total → never fixes, keeps polling.
		_ = json.NewEncoder(w).Encode(glory.Transaction{
			TransactionID: "T1", TransactionStatus: glory.StatusBeginDeposit, Total: 1000, Deposit: 100,
		})
	}))
	t.Cleanup(slow.Close)
	withFastCashChanger(t, s, slow.URL)

	first := httptest.NewRecorder()
	s.handleCashChangerCollect(cashChangerKiosk)(first, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/cash-changer/collect", strings.NewReader(`{"order_id":"o1"}`)))
	if first.Code != http.StatusAccepted {
		t.Fatalf("first = %d, want 202", first.Code)
	}

	second := httptest.NewRecorder()
	s.handleCashChangerCollect(cashChangerKiosk)(second, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/cash-changer/collect", strings.NewReader(`{"order_id":"o1"}`)))
	if second.Code != http.StatusConflict {
		t.Fatalf("second (concurrent) = %d, want 409 busy", second.Code)
	}
}
