package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// Pos-web's mutation hooks send idempotency_key in the JSON body, not
// the HTTP header (see pos-web/CLAUDE.md §Idempotency). Pre-fix the
// LAN payment endpoint hard-required the header → PaymentDialog
// rendered "Idempotency-Key header required" + a Thử lại button that
// would never succeed. Body-level key is the canonical contract pos-web
// has shipped since Sprint A.5.
func TestHandlePosCreatePayment_AcceptsBodyIdempotencyKey(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedOpenShift(t, srv)
	srv.hub = NewHub()

	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    branch_id, brand_id, organization_id)
		VALUES ('o-body', 'O-B', 'spot', 'checkout', datetime('now'),
		        'br-1', 'bd-1', 'or-1')`)

	body := `{
		"amount": 7400,
		"payment_method": "cash",
		"idempotency_key": "key-from-body-1"
	}`
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-body/payments",
		strings.NewReader(body))
	// Intentionally NO Idempotency-Key header — mirrors the live
	// pos-web request that surfaced the bug in the report screenshot.
	req.SetPathValue("id", "o-body")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)

	if w.Code != http.StatusCreated {
		t.Fatalf("body-only idempotency want 201, got %d body=%s",
			w.Code, w.Body.String())
	}

	var stored string
	if err := db.QueryRow(
		`SELECT idempotency_key FROM payments WHERE order_id = 'o-body'`,
	).Scan(&stored); err != nil {
		t.Fatalf("readback: %v", err)
	}
	if stored != "key-from-body-1" {
		t.Errorf("idempotency_key persisted want key-from-body-1, got %q", stored)
	}
}

// Second call with the SAME body key must replay the cached payment
// instead of creating a duplicate. Header-style replay already had
// coverage; pin the body path too so a refactor can't desync them.
func TestHandlePosCreatePayment_BodyIdempotencyReplays(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedOpenShift(t, srv)
	srv.hub = NewHub()

	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    branch_id, brand_id, organization_id)
		VALUES ('o-replay', 'O-R', 'spot', 'checkout', datetime('now'),
		        'br-1', 'bd-1', 'or-1')`)

	body := `{"amount":7400,"payment_method":"cash","idempotency_key":"replay-1"}`

	for i := 0; i < 2; i++ {
		req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-replay/payments",
			strings.NewReader(body))
		req.SetPathValue("id", "o-replay")
		w := httptest.NewRecorder()
		srv.handleLocalPosCreatePayment(w, req)
		if w.Code != http.StatusCreated && w.Code != http.StatusOK {
			t.Fatalf("call #%d: want 201/200, got %d body=%s",
				i, w.Code, w.Body.String())
		}
	}

	var count int
	if err := db.QueryRow(
		`SELECT COUNT(*) FROM payments WHERE order_id = 'o-replay'`,
	).Scan(&count); err != nil {
		t.Fatal(err)
	}
	if count != 1 {
		t.Errorf("body-key replay must dedupe, got %d rows", count)
	}
}

// Both forms missing → 400, but the message must now mention BOTH
// shapes so staff/devs aren't misled into adding a header that
// pos-web doesn't send.
func TestHandlePosCreatePayment_MissingBothFormsErrorMentionsBoth(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedOpenShift(t, srv)
	srv.hub = NewHub()
	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    branch_id, brand_id, organization_id)
		VALUES ('o-x', 'O-X', 'spot', 'checkout', datetime('now'),
		        'br-1', 'bd-1', 'or-1')`)

	body := `{"amount":100,"payment_method":"cash"}` // no key anywhere
	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o-x/payments",
		strings.NewReader(body))
	req.SetPathValue("id", "o-x")
	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)

	if w.Code != http.StatusBadRequest {
		t.Fatalf("missing both: want 400, got %d", w.Code)
	}
	for _, frag := range []string{"header", "body"} {
		if !strings.Contains(w.Body.String(), frag) {
			t.Errorf("error message must mention %q for discoverability: %s",
				frag, w.Body.String())
		}
	}
}
