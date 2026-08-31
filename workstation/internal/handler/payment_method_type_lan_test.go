package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// `payment_methods.type` reaching the LAN, and the two things that depend on it.
//
// Cloud's payment-methods response carries `type`; the LAN reply for the same
// endpoint did not, so pos-web's debt CTA — which looks for
// `type === "on_account"` — fell through to a `code === "debt"` guess that only
// holds while no shop renames its on-account method.
//
// The print path is worse than a guess: it REFUSES anything that is not
// `on_account`, so with every mirrored method reading as cash, "In phiếu nợ"
// answered `payment_method_not_on_account` every single time.

func seedMethod(t *testing.T, s *Server, id, code, name, methodType string) {
	t.Helper()
	mustExec(t, s.db, `
		INSERT INTO payment_methods (id, code, name, is_active, sort_order,
		    is_auto_confirm, requires_tendered, type)
		VALUES (?, ?, ?, 1, 1, 1, 0, ?)`, id, code, name, methodType)
}

func TestLocalPosPaymentMethods_EmitsType(t *testing.T) {
	s := newFireTestServer(t)
	seedMethod(t, s, "pm-cash", "cash", "Cash", "cash")
	seedMethod(t, s, "pm-debt", "debt", "On account", "on_account")

	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/payment-methods", nil)
	w := httptest.NewRecorder()
	s.handleLocalPosPaymentMethods(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d: %s", w.Code, w.Body.String())
	}
	var resp struct {
		Data []map[string]any `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v", err)
	}

	byCode := map[string]string{}
	for _, m := range resp.Data {
		code, _ := m["code"].(string)
		// A MISSING key and an empty value are different failures and both
		// matter: the first is the LAN reply having a different shape from
		// Cloud's, the second is the mirror never having been told.
		raw, present := m["type"]
		if !present {
			t.Fatalf("method %q has no `type` — the LAN reply must match Cloud's shape", code)
		}
		byCode[code], _ = raw.(string)
	}

	if byCode["debt"] != "on_account" {
		t.Errorf("debt method served as type=%q; pos-web's CTA looks for on_account", byCode["debt"])
	}
	if byCode["cash"] != "cash" {
		t.Errorf("cash method served as type=%q", byCode["cash"])
	}
}

// The debt slip. `handleLANPrintDebtSlip` reads the method type straight out of
// the mirror and refuses anything else, so this is the exact query that used to
// come back 'cash' for the debt method.
func TestDebtSlipTypeLookup_SeesOnAccount(t *testing.T) {
	s := newFireTestServer(t)
	seedMethod(t, s, "pm-debt", "debt", "On account", "on_account")
	mustExec(t, s.db, `INSERT INTO orders (id, order_code, status) VALUES ('o-1','ORD-1','closed')`)
	mustExec(t, s.db, `
		INSERT INTO payments (id, order_id, payment_method, payment_method_id, amount, status)
		VALUES ('pay-1','o-1','debt','pm-debt',2496,'succeeded')`)

	var methodType string
	if err := s.db.QueryRow(
		`SELECT COALESCE(pm.type, '')
		   FROM payments p
		   LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
		  WHERE p.id = ?`, "pay-1",
	).Scan(&methodType); err != nil {
		t.Fatalf("lookup: %v", err)
	}

	if methodType != "on_account" {
		t.Fatalf("the print handler refuses anything but on_account, so %q means "+
			"every 掛売 slip over LAN answers payment_method_not_on_account", methodType)
	}
}
