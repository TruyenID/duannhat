package handler

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// TestE2E817PhaseB drives the REAL LAN HTTP server (real mux + auth
// middleware + handlers) over a real TCP socket to observe the #817 Phase B
// offline shift-close flow: WS-1 (a POS payment stamps captured_at +
// till_session_id) and WS-2/WS-6 (the close gate rejects an out-of-tolerance
// no-reason close 422 VARIANCE_REASON_REQUIRED — where the tolerance is only
// breached because WS-2's cash-tip add-back inflates expected_cash — then
// settles once a reason is supplied). The sync-UP side (WS-3/4/5) is driven
// against a mock Cloud in the service package (sync_till_close_test.go).
func TestE2E817PhaseB(t *testing.T) {
	// Mock Cloud: only /api/v1/me/context is needed to satisfy pos auth.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"user":{"id":"u-1","name":"Alice","email":"a@x.com","locale":"vi","timezone":"Asia/Tokyo"},"brand_count":1,"shop_count":1}`))
	}))
	defer cloud.Close()

	s, db := newServerWithAuth(t, cloud.URL)
	s.hub = NewHub() // payment/close handlers broadcast WS events

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)
	ts := httptest.NewServer(mux) // REAL socket
	defer ts.Close()

	// ── Seed the branch's till + an OPEN shift + fixtures ───────────────────
	openedAt := time.Now().UTC().Add(-time.Hour).Format(time.RFC3339Nano)
	seed := func(q string, args ...any) {
		if _, err := db.Exec(q, args...); err != nil {
			t.Fatalf("seed %q: %v", q, err)
		}
	}
	seed(`INSERT INTO tills (id,branch_id,code,default_currency_code,variance_tolerance_amount,current_session_id)
	      VALUES ('till-1','branch-A','MAIN','JPY',0,'sess-1')`)
	seed(`INSERT INTO denominations (id,currency_code,value,kind,is_active) VALUES ('d100','JPY',100,'coin',1)`)
	seed(`INSERT INTO till_sessions (id,session_code,status,business_date,default_currency_code,opening_float_amount,opened_at,till_id,branch_id)
	      VALUES ('sess-1','WS-CODE-1','open','2026-07-15','JPY',0,?, 'till-1','branch-A')`, openedAt)
	seed(`INSERT INTO orders (id,cloud_id,order_code,status,total_amount,branch_id) VALUES ('ord-1','cloud-ord-1','WS-1','checkout',5000,'branch-A')`)
	seed(`INSERT INTO payment_methods (id,code,name,requires_tendered,is_auto_confirm,is_active) VALUES ('pm-cash','cash','Cash',1,1,1)`)

	client := ts.Client()
	do := func(method, path string, body any) (int, string) {
		var rdr io.Reader
		if body != nil {
			b, _ := json.Marshal(body)
			rdr = bytes.NewReader(b)
		}
		req, _ := http.NewRequest(method, ts.URL+path, rdr)
		req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("Origin", "http://localhost:5440")
		resp, err := client.Do(req)
		if err != nil {
			t.Fatalf("%s %s: %v", method, path, err)
		}
		raw, _ := io.ReadAll(resp.Body)
		resp.Body.Close()
		return resp.StatusCode, string(raw)
	}

	// ════ WS-1: real POS payment stamps captured_at + till_session_id ════════
	code, raw := do("POST", "/api/v1/pos/orders/ord-1/payments", map[string]any{
		"payment_method_id": "pm-cash",
		"payment_method":    "cash",
		"amount":            5000,
		"tip_amount":        200,
		"tendered_amount":   5200,
		"idempotency_key":   "idem-live-1",
	})
	fmt.Printf("\n[WS-1] POST /pos/orders/ord-1/payments → HTTP %d\n  body: %s\n", code, raw)
	if code != http.StatusCreated && code != http.StatusOK {
		t.Fatalf("WS-1 payment create failed: %d %s", code, raw)
	}
	var capturedAt, tillSessID string
	var tipCol int
	if err := db.QueryRow(`SELECT COALESCE(captured_at,''), COALESCE(till_session_id,''), tip_amount
	                       FROM payments WHERE idempotency_key='idem-live-1'`).Scan(&capturedAt, &tillSessID, &tipCol); err != nil {
		t.Fatalf("read payment row: %v", err)
	}
	fmt.Printf("[WS-1] payments row: captured_at=%q till_session_id=%q tip_amount=%d\n", capturedAt, tillSessID, tipCol)
	if capturedAt == "" {
		t.Error("WS-1: captured_at not stamped on the payment row")
	}
	if tillSessID != "sess-1" {
		t.Errorf("WS-1: till_session_id = %q, want sess-1", tillSessID)
	}
	if tipCol != 200 {
		t.Errorf("WS-1: tip_amount persisted = %d, want 200", tipCol)
	}

	// ════ WS-2 + WS-6: close gate over the real socket ═══════════════════════
	// expected_cash = openingFloat 0 + cash 5000 + cash tip 200 = 5200 (WS-2
	// cash-tip add-back). counted 5000 (50 × ¥100) → variance -200; tolerance 0
	// → out of tolerance. Without WS-2's add-back expected would be 5000 and no
	// 422 would fire, so the 422 firing proves BOTH the add-back and WS-6.
	no := map[string]any{
		"closing_counts": []map[string]any{{"denomination_id": "d100", "quantity": 50}},
		"tender_details": []any{},
		"closing_note":   "",
	}
	c1, b1 := do("POST", "/api/v1/pos/till/sessions/sess-1/close", no)
	fmt.Printf("\n[WS-6] close (no reason; variance -¥200 vs tol 0) → HTTP %d\n  body: %s\n", c1, b1)
	if c1 != http.StatusUnprocessableEntity {
		t.Fatalf("WS-6: expected 422, got %d (%s)", c1, b1)
	}
	var errPayload map[string]any
	_ = json.Unmarshal([]byte(b1), &errPayload)
	if errPayload["code"] != "VARIANCE_REASON_REQUIRED" {
		t.Errorf("WS-6: expected code VARIANCE_REASON_REQUIRED, got %v", errPayload["code"])
	}
	var st string
	_ = db.QueryRow(`SELECT status FROM till_sessions WHERE id='sess-1'`).Scan(&st)
	fmt.Printf("[WS-6] session status after 422 = %q (rejected close must mutate nothing)\n", st)
	if st != "open" {
		t.Errorf("WS-6: rejected close must not settle; status=%q", st)
	}

	// Retry WITH a reason → settles.
	yes := map[string]any{
		"closing_counts": []map[string]any{{"denomination_id": "d100", "quantity": 50}},
		"tender_details": []any{},
		"closing_note":   "drawer short ¥200; verified vs receipts",
	}
	c2, b2 := do("POST", "/api/v1/pos/till/sessions/sess-1/close", yes)
	fmt.Printf("[WS-6] close (with reason) → HTTP %d\n  body: %s\n", c2, b2)
	if c2 != http.StatusOK {
		t.Fatalf("WS-6: reasoned close should settle 200, got %d (%s)", c2, b2)
	}
	var cashVar float64
	_ = db.QueryRow(`SELECT status, COALESCE(cash_variance,0) FROM till_sessions WHERE id='sess-1'`).Scan(&st, &cashVar)
	fmt.Printf("[WS-6] session status after reasoned close = %q, cash_variance=%.0f\n", st, cashVar)
	if st != "settled" {
		t.Errorf("WS-6: reasoned close should settle; status=%q", st)
	}
	if cashVar != -200 {
		t.Errorf("WS-2/6: recorded cash_variance = %.0f, want -200 (expected 5200 incl. tip vs counted 5000)", cashVar)
	}
}
