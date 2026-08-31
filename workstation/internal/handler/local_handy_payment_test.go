package handler

// #876 — LAN direct payment on Handy, gated by the mirrored shop setting.

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func handyPayRequest(t *testing.T, srv *Server, orderID, body string) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest("POST", "/api/v1/handy/orders/"+orderID+"/payments", strings.NewReader(body))
	req.SetPathValue("order", orderID)
	w := httptest.NewRecorder()
	srv.handleLocalHandyCreatePayment(w, req)
	return w
}

func TestHandyCreatePayment_DisabledByDefault(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-handy-1", "pm-cash-h", "cash", false, true, 1000, "open")

	w := handyPayRequest(t, srv, "o-handy-1",
		`{"payment_method_id":"pm-cash-h","amount":1000,"idempotency_key":"kh-1"}`)
	if w.Code != http.StatusForbidden {
		t.Fatalf("default OFF must 403, got %d body=%s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), "HANDY_PAYMENT_DISABLED") {
		t.Errorf("machine code missing: %s", w.Body.String())
	}
}

func TestHandyCreatePayment_SettlesWhenEnabled(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	seedOrderAndMethod(t, srv, "o-handy-2", "pm-cash-h2", "cash", false, true, 1000, "paying")
	mustExec(t, srv.db, `INSERT INTO shop_settings (key, value) VALUES ('handy_allow_direct_payment', 'true')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`)

	w := handyPayRequest(t, srv, "o-handy-2",
		`{"payment_method_id":"pm-cash-h2","amount":1000,"idempotency_key":"kh-2"}`)
	if w.Code != http.StatusCreated {
		t.Fatalf("enabled cash payment must 201, got %d body=%s", w.Code, w.Body.String())
	}

	// Delegating to the POS core means the full lifecycle ran: captured-only
	// paid_amount + auto-confirm close (#555 M13 semantics).
	var status string
	var paid int
	if err := srv.db.QueryRow(`SELECT status, paid_amount FROM orders WHERE id = 'o-handy-2'`).Scan(&status, &paid); err != nil {
		t.Fatal(err)
	}
	if status != "closed" || paid != 1000 {
		t.Errorf("order must close fully paid: status=%s paid=%d", status, paid)
	}
}
